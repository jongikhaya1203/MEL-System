<?php
/**
 * Active Directory integration (super admin: Administration > Directory Access).
 *
 * - Sign-in: a user linked to AD (users.auth_source='ad') is authenticated by
 *   binding to AD as that user; no password is stored locally. Local
 *   accounts keep working, so a super admin can always get in if AD is down.
 * - Access is granted by AD security group: ad_group_mappings maps a group
 *   to a MEL role (and optionally a Digital Transformation governance body).
 *   Nested group membership is honoured (LDAP_MATCHING_RULE_IN_CHAIN).
 * - Users can be granted access explicitly (search + grant), or created
 *   automatically on first sign-in when auto_provision is on and they're in
 *   a mapped group.
 * - Sync refreshes name/email/role from AD and deactivates accounts that
 *   are disabled, deleted or no longer in any mapped group.
 * Every grant, revoke, provision, sign-in failure and sync change is written
 * to access_audit_log.
 */

const AD_MATCHING_RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';
const AD_UAC_ACCOUNTDISABLE = 0x2;

function ad_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM ad_settings WHERE id = 1')->fetch() ?: ['enabled' => 0];
    try {
        $row['bind_password'] = decrypt_secret($row['bind_password'] ?? null);
    } catch (Throwable $e) {
        $row['bind_password'] = '';
    }
    return $row;
}

function ad_is_enabled(PDO $pdo): bool
{
    try {
        return !empty(ad_settings($pdo)['enabled']) && function_exists('ldap_connect');
    } catch (Throwable $e) {
        return false;
    }
}

function ad_mappings(PDO $pdo): array
{
    return $pdo->query('SELECT m.*, b.name AS body_name FROM ad_group_mappings m LEFT JOIN dt_governance_bodies b ON b.id = m.dt_body_id ORDER BY m.priority, m.id')->fetchAll();
}

function access_audit(PDO $pdo, string $action, ?int $userId, ?string $details = null, ?int $actorId = null): void
{
    try {
        $actorId = $actorId ?? (current_user()['id'] ?? null);
        $pdo->prepare('INSERT INTO access_audit_log (user_id, actor_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $actorId, $action, $details === null ? null : mb_substr($details, 0, 1000), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // auditing must never break sign-in
    }
}

/** Escapes a value for use inside an LDAP filter (RFC 4515). */
function ad_filter_escape(string $value): string
{
    return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
}

/** objectGUID (16 raw bytes) -> canonical 8-4-4-4-12 string as AD displays it. */
function ad_guid_to_string(string $bin): string
{
    if (strlen($bin) !== 16) {
        return '';
    }
    $h = bin2hex($bin);
    $le = fn($s) => implode('', array_reverse(str_split($s, 2)));
    return strtolower($le(substr($h, 0, 8)) . '-' . $le(substr($h, 8, 4)) . '-' . $le(substr($h, 12, 4)) . '-' . substr($h, 16, 4) . '-' . substr($h, 20));
}

/** Canonical GUID string -> escaped binary filter value for (objectGUID=...). */
function ad_guid_filter_value(string $guid): string
{
    $h = str_replace('-', '', strtolower($guid));
    if (!preg_match('/^[0-9a-f]{32}$/', $h)) {
        return '\00';
    }
    $le = fn($s) => implode('', array_reverse(str_split($s, 2)));
    $bytes = $le(substr($h, 0, 8)) . $le(substr($h, 8, 4)) . $le(substr($h, 12, 4)) . substr($h, 16);
    return '\\' . implode('\\', str_split($bytes, 2));
}

/** "DOMAIN\jdoe" -> "jdoe"; trims; lowercases nothing (AD is case-insensitive anyway). */
function ad_normalise_login(string $login): string
{
    $login = trim($login);
    if (strpos($login, '\\') !== false) {
        $login = substr($login, strrpos($login, '\\') + 1);
    }
    return $login;
}

/**
 * Opens a connection to the first reachable domain controller and binds
 * with the service account. Returns the link or null with $error set.
 */
function ad_connect(array $cfg, ?string &$error)
{
    if (!function_exists('ldap_connect')) {
        $error = 'The PHP LDAP extension is not enabled on this server.';
        return null;
    }
    $hosts = preg_split('/[\s,;]+/', trim((string)($cfg['hosts'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if (!$hosts || empty($cfg['base_dn'])) {
        $error = 'Directory server and base DN must be configured.';
        return null;
    }
    $timeout = max(1, (int)($cfg['timeout_seconds'] ?? 5));
    // TLS certificate policy must be set globally before connecting.
    @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, !empty($cfg['verify_cert']) ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);

    $errors = [];
    foreach ($hosts as $host) {
        $scheme = ($cfg['encryption'] ?? 'ldaps') === 'ldaps' ? 'ldaps' : 'ldap';
        $link = @ldap_connect($scheme . '://' . $host . ':' . (int)($cfg['port'] ?? 636));
        if (!$link) {
            $errors[] = "$host: invalid address";
            continue;
        }
        ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($link, LDAP_OPT_TIMELIMIT, $timeout * 2);
        if (($cfg['encryption'] ?? '') === 'starttls' && !@ldap_start_tls($link)) {
            $errors[] = "$host: STARTTLS failed (" . ldap_error($link) . ')';
            continue;
        }
        if (empty($cfg['bind_dn']) || ($cfg['bind_password'] ?? '') === '') {
            $error = 'A service account (bind DN and password) is required to search the directory.';
            return null;
        }
        if (!@ldap_bind($link, $cfg['bind_dn'], $cfg['bind_password'])) {
            $diag = '';
            @ldap_get_option($link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
            $errors[] = "$host: " . ldap_error($link) . ($diag ? " ($diag)" : '');
            if (ldap_errno($link) === 49) { // invalid credentials — no point trying other DCs
                break;
            }
            continue;
        }
        return $link;
    }
    $error = 'Could not connect/bind to the directory — ' . implode('; ', $errors);
    return null;
}

/** Attributes read for every user lookup. */
function ad_user_attributes(): array
{
    return ['objectguid', 'distinguishedname', 'samaccountname', 'userprincipalname', 'displayname', 'givenname', 'sn',
            'mail', 'memberof', 'useraccountcontrol', 'title', 'department'];
}

/** Turns one ldap_get_entries() row into a plain array. */
function ad_normalise_entry(array $e): array
{
    $one = fn($k) => isset($e[$k][0]) ? (string)$e[$k][0] : '';
    $groups = [];
    for ($i = 0; $i < (int)($e['memberof']['count'] ?? 0); $i++) {
        $groups[] = $e['memberof'][$i];
    }
    $name = $one('displayname') ?: trim($one('givenname') . ' ' . $one('sn')) ?: $one('samaccountname');
    return [
        'guid' => ad_guid_to_string($one('objectguid')),
        'dn' => $one('distinguishedname') ?: (string)($e['dn'] ?? ''),
        'username' => strtolower($one('samaccountname')),
        'upn' => $one('userprincipalname'),
        'name' => $name,
        'email' => $one('mail'),
        'title' => $one('title'),
        'department' => $one('department'),
        'groups' => $groups,
        'disabled' => ((int)$one('useraccountcontrol') & AD_UAC_ACCOUNTDISABLE) !== 0,
    ];
}

function ad_search($link, array $cfg, string $filter, int $limit = 0): array
{
    $res = @ldap_search($link, $cfg['base_dn'], $filter, ad_user_attributes(), 0, $limit);
    if (!$res) {
        return [];
    }
    $entries = ldap_get_entries($link, $res);
    $out = [];
    for ($i = 0; $i < (int)($entries['count'] ?? 0); $i++) {
        $out[] = ad_normalise_entry($entries[$i]);
    }
    return $out;
}

/** Finds one user by sAMAccountName, "DOMAIN\user", UPN or email. */
function ad_find_user($link, array $cfg, string $login): ?array
{
    $login = ad_normalise_login($login);
    if ($login === '') {
        return null;
    }
    $v = ad_filter_escape($login);
    $alts = "(sAMAccountName=$v)(userPrincipalName=$v)(mail=$v)";
    if (strpos($login, '@') === false && !empty($cfg['upn_suffix'])) {
        $alts .= '(userPrincipalName=' . ad_filter_escape($login . '@' . ltrim($cfg['upn_suffix'], '@')) . ')';
    }
    $found = ad_search($link, $cfg, '(&' . $cfg['user_filter'] . "(|$alts))", 2);
    return count($found) === 1 ? $found[0] : null; // ambiguous matches are refused
}

function ad_find_by_guid($link, array $cfg, string $guid): ?array
{
    $found = ad_search($link, $cfg, '(objectGUID=' . ad_guid_filter_value($guid) . ')', 1);
    return $found[0] ?? null;
}

/** Free-text directory search for the grant screen. */
function ad_search_people($link, array $cfg, string $term, int $limit = 30): array
{
    $v = ad_filter_escape(trim($term));
    if ($v === '') {
        return [];
    }
    return ad_search($link, $cfg, '(&' . $cfg['user_filter'] . "(|(sAMAccountName=$v*)(displayName=*$v*)(mail=$v*)(sn=$v*)(givenName=$v*)(userPrincipalName=$v*)))", $limit);
}

/** Group search, to help a super admin pick the DN for a mapping. */
function ad_search_groups($link, array $cfg, string $term, int $limit = 30): array
{
    $v = ad_filter_escape(trim($term));
    $res = @ldap_search($link, $cfg['base_dn'], "(&(objectClass=group)(|(cn=*$v*)(sAMAccountName=*$v*)))", ['cn', 'distinguishedname', 'description'], 0, $limit);
    if (!$res) {
        return [];
    }
    $e = ldap_get_entries($link, $res);
    $out = [];
    for ($i = 0; $i < (int)$e['count']; $i++) {
        $out[] = ['name' => $e[$i]['cn'][0] ?? '', 'dn' => $e[$i]['distinguishedname'][0] ?? $e[$i]['dn'], 'description' => $e[$i]['description'][0] ?? ''];
    }
    return $out;
}

/**
 * Which mappings apply to this user. Direct membership comes from memberOf;
 * with nested_groups on, each mapped group is also checked transitively.
 */
function ad_matching_mappings($link, array $cfg, array $entry, array $mappings): array
{
    $direct = array_map('strtolower', $entry['groups']);
    $matched = [];
    foreach ($mappings as $m) {
        $dn = strtolower($m['group_dn']);
        $isMember = in_array($dn, $direct, true);
        if (!$isMember && !empty($cfg['nested_groups']) && $link && $entry['dn'] !== '') {
            $res = @ldap_read($link, $entry['dn'], '(memberOf:' . AD_MATCHING_RULE_IN_CHAIN . ':=' . ad_filter_escape($m['group_dn']) . ')', ['dn']);
            $isMember = $res && ldap_count_entries($link, $res) > 0;
        }
        if ($isMember) {
            $matched[] = $m;
        }
    }
    return $matched; // already ordered by priority
}

/** Role from the highest-priority matching mapping (null = no access), plus governance bodies from all matches. */
function ad_resolve_access(array $matched): array
{
    return [
        'role' => $matched[0]['role'] ?? null,
        'group' => $matched[0]['group_name'] ?? ($matched[0]['group_dn'] ?? null),
        'bodies' => array_values(array_unique(array_filter(array_map(fn($m) => (int)$m['dt_body_id'], $matched)))),
    ];
}

/** Adds the user as a signatory of each mapped governance body (never removes manual assignments). */
function ad_apply_governance(PDO $pdo, int $userId, array $bodyIds, int $actorId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO dt_governance_members (body_id, user_id, designation, assigned_by) VALUES (?, ?, NULL, ?)');
    foreach ($bodyIds as $b) {
        $stmt->execute([$b, $userId, $actorId]);
    }
}

/** Creates or updates the local account linked to an AD entry. Returns the user id. */
function ad_upsert_user(PDO $pdo, array $entry, string $role, string $roleSource, int $actorId): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE ad_guid = ?');
    $stmt->execute([$entry['guid']]);
    $id = (int)$stmt->fetchColumn();
    $email = $entry['email'] !== '' ? $entry['email'] : null;
    if ($id) {
        $pdo->prepare("UPDATE users SET full_name = ?, email = COALESCE(?, email), role = ?, role_source = ?, is_super_admin = IF(? = 'admin', is_super_admin, 0),
                       ad_dn = ?, ad_synced_at = NOW(), is_active = 1, auth_source = 'ad' WHERE id = ?")
            ->execute([$entry['name'], $email, $role, $roleSource, $role, $entry['dn'], $id]);
        return $id;
    }
    // Username: sAMAccountName, made unique if a local account already uses it.
    $base = $entry['username'] !== '' ? $entry['username'] : strtolower(strtok($entry['upn'], '@'));
    $username = $base;
    $check = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    for ($n = 2; $check->execute([$username]) && $check->fetchColumn(); $n++) {
        $username = mb_substr($base, 0, 45) . '.ad' . ($n > 2 ? $n : '');
    }
    // Random unusable local hash: AD users never sign in with a local password.
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, role, is_super_admin, auth_source, role_source, ad_guid, ad_dn, ad_synced_at, is_active, must_change_password)
                   VALUES (?, ?, ?, ?, ?, 0, 'ad', ?, ?, ?, NOW(), 1, 0)")
        ->execute([$username, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $entry['name'], $email, $role, $roleSource, $entry['guid'], $entry['dn']]);
    return (int)$pdo->lastInsertId();
}

/**
 * Sign-in against AD. Returns the local users row on success, or null with
 * $error set to a user-facing message. $localUser is the existing account
 * (looked up by username) when there is one.
 */
function ad_login(PDO $pdo, string $login, string $password, ?array $localUser, ?string &$error): ?array
{
    $error = 'Invalid username or password.';
    if ($password === '') {
        return null; // an empty password would be an anonymous bind and "succeed"
    }
    $cfg = ad_settings($pdo);
    $connErr = null;
    $link = ad_connect($cfg, $connErr);
    if (!$link) {
        access_audit($pdo, 'ad_login_error', $localUser['id'] ?? null, "Login for '$login': $connErr", $localUser['id'] ?? null);
        $error = 'The directory service is unavailable right now. Please try again shortly, or contact the system administrator.';
        return null;
    }
    $entry = $localUser && $localUser['ad_guid'] ? ad_find_by_guid($link, $cfg, $localUser['ad_guid']) : ad_find_user($link, $cfg, $login);
    if (!$entry) {
        access_audit($pdo, 'ad_login_denied', $localUser['id'] ?? null, "Login '$login': not found in the directory", $localUser['id'] ?? null);
        return null;
    }
    // Verify the password by binding as the user, on a fresh connection.
    $userLink = ad_connect(['bind_dn' => $entry['dn'], 'bind_password' => $password] + $cfg, $connErr);
    if (!$userLink) {
        access_audit($pdo, 'ad_login_denied', $localUser['id'] ?? null, "Login '$login': directory rejected the credentials", $localUser['id'] ?? null);
        return null;
    }
    @ldap_unbind($userLink);
    if ($entry['disabled']) {
        $error = 'Your directory account is disabled.';
        access_audit($pdo, 'ad_login_denied', $localUser['id'] ?? null, "Login '$login': account disabled in AD", $localUser['id'] ?? null);
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE ad_guid = ?');
    $stmt->execute([$entry['guid']]);
    $user = $stmt->fetch() ?: null;
    if ($user && !$user['is_active']) {
        $error = 'Your access to this system has been revoked. Contact the system administrator.';
        access_audit($pdo, 'ad_login_denied', (int)$user['id'], 'Account is deactivated in the MEL system', (int)$user['id']);
        return null;
    }
    $access = ad_resolve_access(ad_matching_mappings($link, $cfg, $entry, ad_mappings($pdo)));

    if (!$user) {
        if (empty($cfg['auto_provision']) || !$access['role']) {
            $error = 'You have not been granted access to this system. Ask the system administrator to grant access to your directory account.';
            access_audit($pdo, 'ad_login_denied', null, "Login '$login' ({$entry['name']}): no MEL account" . ($access['role'] ? '' : ' and no mapped AD group'), null);
            return null;
        }
        $id = ad_upsert_user($pdo, $entry, $access['role'], 'directory', 0);
        ad_apply_governance($pdo, $id, $access['bodies'], $id);
        access_audit($pdo, 'ad_provisioned', $id, "Created on first sign-in as {$access['role']} via group {$access['group']}", $id);
    } else {
        $role = $user['role'];
        if ($user['role_source'] === 'directory') {
            if (!$access['role']) {
                $error = 'You are no longer in a directory group that grants access to this system.';
                access_audit($pdo, 'ad_login_denied', (int)$user['id'], 'No longer in any mapped AD group', (int)$user['id']);
                return null;
            }
            if ($access['role'] !== $role) {
                access_audit($pdo, 'ad_role_changed', (int)$user['id'], "Role $role → {$access['role']} (group {$access['group']})", (int)$user['id']);
            }
            $role = $access['role'];
        }
        $id = ad_upsert_user($pdo, $entry, $role, $user['role_source'], (int)$user['id']);
        ad_apply_governance($pdo, $id, $access['bodies'], $id);
    }
    @ldap_unbind($link);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Re-reads every AD-linked account from the directory. Returns a summary
 * array. $link/$cfg may be passed in (tests, single-user sync).
 */
function ad_sync_users(PDO $pdo, ?int $onlyUserId = null, ?int $actorId = null): array
{
    $cfg = ad_settings($pdo);
    $err = null;
    $link = ad_connect($cfg, $err);
    if (!$link) {
        return ['ok' => false, 'error' => $err];
    }
    $mappings = ad_mappings($pdo);
    $sql = "SELECT * FROM users WHERE auth_source = 'ad' AND ad_guid IS NOT NULL" . ($onlyUserId ? ' AND id = ' . (int)$onlyUserId : '');
    $summary = ['ok' => true, 'checked' => 0, 'updated' => 0, 'deactivated' => 0, 'role_changes' => 0, 'error' => null];
    foreach ($pdo->query($sql)->fetchAll() as $u) {
        $summary['checked']++;
        $entry = ad_find_by_guid($link, $cfg, $u['ad_guid']);
        $reason = null;
        if (!$entry) {
            $reason = 'no longer found in the directory';
        } elseif ($entry['disabled']) {
            $reason = 'disabled in Active Directory';
        }
        $access = $entry ? ad_resolve_access(ad_matching_mappings($link, $cfg, $entry, $mappings)) : ['role' => null, 'bodies' => [], 'group' => null];
        if (!$reason && $u['role_source'] === 'directory' && !$access['role']) {
            $reason = 'no longer in any mapped AD group';
        }
        if ($reason) {
            if ($u['is_active'] && !empty($cfg['sync_deactivate'])) {
                $pdo->prepare('UPDATE users SET is_active = 0, ad_synced_at = NOW() WHERE id = ?')->execute([$u['id']]);
                access_audit($pdo, 'ad_sync_deactivated', (int)$u['id'], "Deactivated by sync: $reason", $actorId);
                $summary['deactivated']++;
            }
            continue;
        }
        $role = $u['role'];
        if ($u['role_source'] === 'directory' && $access['role'] !== $role) {
            access_audit($pdo, 'ad_role_changed', (int)$u['id'], "Sync: role $role → {$access['role']} (group {$access['group']})", $actorId);
            $role = $access['role'];
            $summary['role_changes']++;
        }
        if (!$u['is_active']) {
            // A revoked account stays revoked; sync only refreshes its details.
            $pdo->prepare('UPDATE users SET full_name = ?, email = COALESCE(?, email), ad_dn = ?, ad_synced_at = NOW() WHERE id = ?')
                ->execute([$entry['name'], $entry['email'] ?: null, $entry['dn'], $u['id']]);
            continue;
        }
        ad_upsert_user($pdo, $entry, $role, $u['role_source'], (int)($actorId ?? $u['id']));
        ad_apply_governance($pdo, (int)$u['id'], $access['bodies'], (int)($actorId ?? $u['id']));
        $summary['updated']++;
    }
    @ldap_unbind($link);
    if (!$onlyUserId) {
        $text = "Checked {$summary['checked']}, updated {$summary['updated']}, role changes {$summary['role_changes']}, deactivated {$summary['deactivated']}";
        $pdo->prepare('UPDATE ad_settings SET last_sync_at = NOW(), last_sync_summary = ? WHERE id = 1')->execute([$text]);
        access_audit($pdo, 'ad_sync', null, $text, $actorId);
    }
    return $summary;
}

function access_action_label(string $action): string
{
    return [
        'ad_login_denied' => 'Sign-in denied', 'ad_login_error' => 'Directory unavailable', 'ad_provisioned' => 'Auto-provisioned',
        'ad_granted' => 'Access granted', 'ad_revoked' => 'Access revoked', 'ad_restored' => 'Access restored', 'ad_role_changed' => 'Role changed',
        'ad_sync' => 'Directory sync', 'ad_sync_deactivated' => 'Deactivated by sync', 'ad_config' => 'Settings changed',
        'ad_mapping' => 'Group mapping changed', 'ad_role_source' => 'Role source changed',
    ][$action] ?? $action;
}
