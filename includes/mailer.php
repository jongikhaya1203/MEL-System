<?php
/**
 * Outbound email: a dependency-free SMTP client plus notification routing.
 *
 * Every automated email goes through send_notification(), which applies the
 * super admin's routing (Settings > Email & Notifications):
 *   - per-type on/off switch and extra CC addresses (notification_rules)
 *   - a BCC archive address copied on everything
 *   - Reply-To
 *   - test mode: deliver everything to one address instead of the real
 *     recipients (for UAT), with the intended recipients listed in the body
 * and logs one notification_log row per intended recipient.
 */

/** Every notification the system sends: key => [label, who receives it by default]. */
function notification_events(): array
{
    return [
        'site_status' => ['Site completion verified, approved or rejected', 'The installer who submitted the site'],
        'dt_signoff_required' => ['Digital Transformation: document routed for sign-off', 'Signatories of the governance body at the current step'],
        'dt_rejected' => ['Digital Transformation: document rejected', 'Whoever attached the document, plus the owner body\'s signatories'],
        'dt_signed_off' => ['Digital Transformation: deliverable fully signed off', 'Whoever attached the document, plus the owner body\'s signatories'],
        'ad_access_granted' => ['Directory Access: user granted access to the system', 'The user who was granted access'],
    ];
}

/** event_key => ['enabled' => bool, 'cc' => string]; types without a saved rule are enabled with no CC. */
function notification_rules(PDO $pdo): array
{
    $saved = [];
    try {
        $saved = array_column($pdo->query('SELECT * FROM notification_rules')->fetchAll(), null, 'event_key');
    } catch (Throwable $e) {
        // table not migrated yet — defaults apply
    }
    $rules = [];
    foreach (notification_events() as $key => $_) {
        $rules[$key] = [
            'enabled' => isset($saved[$key]) ? (bool)$saved[$key]['enabled'] : true,
            'cc' => (string)($saved[$key]['cc_emails'] ?? ''),
        ];
    }
    return $rules;
}

/** Splits a comma/semicolon/newline separated list into valid, de-duplicated addresses. */
function parse_email_list(?string $list): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$list) as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($addr)] ??= $addr; // first spelling wins
        }
    }
    return array_values($out);
}

/** Header-safe display name (no CR/LF), RFC 2047 encoded when not plain ASCII. */
function mail_header_name(string $name): string
{
    $name = trim(str_replace(["\r", "\n", '"'], ' ', $name));
    if ($name === '') {
        return '';
    }
    return preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . $name . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=';
}

/**
 * Sends one plain-text message over SMTP (STARTTLS on 587, implicit TLS on
 * 465, or none). $to/$cc are lists of ['email' => .., 'name' => ..]; $bcc is
 * a list of addresses. Returns ['success' => bool, 'error' => ?string].
 */
function smtp_send_message(array $cfg, array $to, array $cc, array $bcc, ?string $replyTo, string $subject, string $body): array
{
    $host = $cfg['smtp_host'] ?? '';
    $port = (int)($cfg['smtp_port'] ?? 587);
    $encryption = $cfg['smtp_encryption'] ?? 'tls';
    $username = $cfg['smtp_username'] ?? '';
    $password = $cfg['smtp_password'] ?? '';
    $fromEmail = $cfg['smtp_from_email'] ?? '';
    $fromName = ($cfg['smtp_from_name'] ?? '') ?: 'USAASA MEL System';

    if ($host === '' || $fromEmail === '') {
        return ['success' => false, 'error' => 'SMTP host or from-address is not configured.'];
    }
    $valid = fn($r) => filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) !== false;
    $to = array_values(array_filter($to, $valid));
    $cc = array_values(array_filter($cc, $valid));
    $bcc = array_values(array_filter($bcc, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
    if (!$to) {
        return ['success' => false, 'error' => 'No valid recipient address.'];
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client("$transport$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['success' => false, 'error' => "Could not connect to SMTP server: $errstr ($errno)"];
    }
    stream_set_timeout($socket, 15);

    $read = function () use ($socket) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = fn(string $cmd) => fwrite($socket, $cmd . "\r\n");
    $expect = function (string $response, string ...$codes) {
        foreach ($codes as $code) {
            if (substr($response, 0, 3) === $code) {
                return true;
            }
        }
        return false;
    };
    $fail = function (string $message) use ($socket) {
        fclose($socket);
        return ['success' => false, 'error' => trim($message)];
    };

    if (!$expect($r = $read(), '220')) {
        return $fail("Unexpected SMTP greeting: $r");
    }
    $write('EHLO localhost');
    if (!$expect($r = $read(), '250')) {
        return $fail("EHLO failed: $r");
    }
    if ($encryption === 'tls') {
        $write('STARTTLS');
        if (!$expect($r = $read(), '220')) {
            return $fail("STARTTLS failed: $r");
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('TLS negotiation failed.');
        }
        $write('EHLO localhost');
        if (!$expect($r = $read(), '250')) {
            return $fail("EHLO after STARTTLS failed: $r");
        }
    }
    if ($username !== '') {
        $write('AUTH LOGIN');
        if (!$expect($r = $read(), '334')) {
            return $fail("AUTH LOGIN not accepted: $r");
        }
        $write(base64_encode($username));
        if (!$expect($r = $read(), '334')) {
            return $fail("SMTP username rejected: $r");
        }
        $write(base64_encode($password));
        if (!$expect($r = $read(), '235')) {
            return $fail("SMTP authentication failed: $r");
        }
    }

    $write("MAIL FROM:<$fromEmail>");
    if (!$expect($r = $read(), '250')) {
        return $fail("MAIL FROM rejected: $r");
    }
    $envelope = array_unique(array_merge(array_column($to, 'email'), array_column($cc, 'email'), $bcc));
    foreach ($envelope as $addr) {
        $write("RCPT TO:<$addr>");
        if (!$expect($r = $read(), '250', '251')) {
            return $fail("RCPT TO <$addr> rejected: $r");
        }
    }
    $write('DATA');
    if (!$expect($r = $read(), '354')) {
        return $fail("DATA command rejected: $r");
    }

    $fmt = fn(array $list) => implode(', ', array_map(fn($x) => trim(mail_header_name($x['name'] ?? '') . ' <' . $x['email'] . '>'), $list));
    $headers = 'From: ' . mail_header_name($fromName) . " <$fromEmail>\r\n"
        . 'To: ' . $fmt($to) . "\r\n"
        . ($cc ? 'Cc: ' . $fmt($cc) . "\r\n" : '')
        . ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? "Reply-To: <$replyTo>\r\n" : '')
        . 'Subject: =?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . "?=\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^a-zA-Z0-9.-]/', '', $host) . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";
    // RFC 5321 dot-stuffing; normalise line endings to CRLF.
    $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
    $write($headers . "\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.");
    if (!$expect($r = $read(), '250')) {
        return $fail("Message not accepted: $r");
    }
    $write('QUIT');
    fclose($socket);
    return ['success' => true, 'error' => null];
}

/**
 * Sends an automated notification according to the super admin's routing
 * and logs it. $recipients: list of ['email' => ?string, 'name' => string].
 * Never throws — mail problems must not block the action that triggered it.
 */
function send_notification(PDO $pdo, string $event, array $recipients, string $subject, string $body, ?int $siteId = null): void
{
    try {
        $settings = get_site_settings($pdo);
        if (empty($settings['smtp_enabled'])) {
            return;
        }
        $rule = notification_rules($pdo)[$event] ?? ['enabled' => true, 'cc' => ''];
        if (!$rule['enabled']) {
            return;
        }
        $log = $pdo->prepare('INSERT INTO notification_log (site_id, event_key, recipient_email, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?)');

        $to = [];
        foreach ($recipients as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $log->execute([$siteId, $event, '(no email on file)', $subject, 'failed', ($r['name'] ?? 'A recipient') . ' has no valid email address on their account.']);
                continue;
            }
            $to[strtolower($email)] = ['email' => $email, 'name' => (string)($r['name'] ?? '')];
        }
        $cc = array_map(fn($a) => ['email' => $a, 'name' => ''], array_values(array_diff_key(
            array_combine(array_map('strtolower', parse_email_list($rule['cc'])), parse_email_list($rule['cc'])) ?: [], $to)));
        if (!$to && !$cc) {
            return;
        }
        $to = array_values($to);
        $bcc = parse_email_list($settings['mail_bcc_archive'] ?? '');
        $signature = "\n\nThis is an automated notification from " . ($settings['site_name'] ?? APP_NAME) . " — please do not reply unless a reply-to address is shown.\n";

        $testTo = trim((string)($settings['mail_test_mode_to'] ?? ''));
        if ($testTo !== '' && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $intended = "[TEST MODE — this message was routed to you instead of:\n  To: " . implode(', ', array_column($to, 'email'))
                . ($cc ? "\n  Cc: " . implode(', ', array_column($cc, 'email')) : '') . "]\n\n";
            $result = smtp_send_message($settings, [['email' => $testTo, 'name' => 'Test mode']], [], [], $settings['smtp_reply_to'] ?? null,
                '[TEST] ' . $subject, $intended . $body . $signature);
            $note = 'Test mode: delivered to ' . $testTo . ' instead';
            foreach (array_merge($to, $cc) as $r) {
                $log->execute([$siteId, $event, $r['email'], $subject, $result['success'] ? 'sent' : 'failed', $result['success'] ? $note : $result['error']]);
            }
            return;
        }

        if (!$to) { // only CC recipients configured
            $to = $cc;
            $cc = [];
        }
        $result = smtp_send_message($settings, $to, $cc, $bcc, $settings['smtp_reply_to'] ?? null, $subject, $body . $signature);
        foreach (array_merge($to, $cc) as $r) {
            $log->execute([$siteId, $event, $r['email'], $subject, $result['success'] ? 'sent' : 'failed', $result['error']]);
        }
    } catch (Throwable $e) {
        // best-effort only
    }
}
