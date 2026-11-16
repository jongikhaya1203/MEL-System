<?php
$user = current_user();
$siteSettings = get_site_settings($pdo);

$relPath = substr($_SERVER['SCRIPT_NAME'], strlen(APP_BASE_URL)); // e.g. /cms/list.php

function nav_is_active(array $item, string $relPath): bool
{
    if (isset($item['children'])) {
        foreach ($item['children'] as $child) {
            if (nav_is_active($child, $relPath)) {
                return true;
            }
        }
        return false;
    }
    if (isset($item['exact'])) {
        return in_array($relPath, $item['exact'], true);
    }
    if (isset($item['exclude']) && in_array($relPath, $item['exclude'], true)) {
        return false;
    }
    if (isset($item['dir'])) {
        return strpos($relPath, '/' . $item['dir'] . '/') === 0;
    }
    return false;
}

/** Inline stroke icon for the sidebar (18px, inherits the link colour). */
function nav_icon(string $name): string
{
    $paths = [
        'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'projects'   => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6H9l2 2h8.5A1.5 1.5 0 0 1 21 9.5v8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>',
        'findings'   => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2V16h5v-.1c0-.8.4-1.5 1-2A6 6 0 0 0 12 3z"/>',
        'risks'      => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.3"/>',
        'results'    => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
        'planning'   => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'broadband'  => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.7 16a5 5 0 0 1 6.6 0"/><circle cx="12" cy="19.3" r="1.1"/>',
        'bdm'        => '<rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M8.5 3l3.5 4.5L15.5 3M8 22h8"/>',
        'ai'         => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'pages'      => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
        'users'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'data'       => '<ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v13c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-13M4.5 12c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8"/>',
        'edit'       => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
        'docs'       => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H11v17H5.5A1.5 1.5 0 0 0 4 21.5zM20 4.5A1.5 1.5 0 0 0 18.5 3H13v17h5.5a1.5 1.5 0 0 1 1.5 1.5z"/>',
        'shield'     => '<path d="M12 3l7.5 3v5.5c0 4.5-3.2 8.3-7.5 9.5-4.3-1.2-7.5-5-7.5-9.5V6z"/><path d="M9 12l2.2 2.2L15.5 10"/>',
        'settings'   => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
    ];
    return '<svg class="nav-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '<circle cx="12" cy="12" r="3"/>') . '</svg>';
}

/** Live counts shown as badges on the Broadband sub-links. Never lets a counting problem break the page. */
function nav_badge_counts(PDO $pdo): array
{
    try {
        return [
            'sites_submitted'  => (int)$pdo->query("SELECT COUNT(*) FROM sites WHERE status = 'Submitted'")->fetchColumn(),
            'rollout_new'      => (int)$pdo->query("SELECT COUNT(*) FROM broadband_rollout_sites WHERE connectivity_status = 'New' AND status <> 'Cancelled'")->fetchColumn(),
            'preassess_todo'   => (int)$pdo->query("SELECT COUNT(*) FROM broadband_rollout_sites r LEFT JOIN site_pre_assessments pa ON pa.rollout_site_id = r.id
                                                    WHERE pa.id IS NULL AND r.status NOT IN ('Cancelled', 'Rolled Out')")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        return [];
    }
}

$navItems = [
    ['label' => 'Dashboard', 'icon' => 'dashboard', 'href' => '/index.php', 'exact' => ['/index.php']],
    ['label' => 'Projects', 'icon' => 'projects', 'href' => '/projects/list.php', 'exact' => ['/projects/list.php']],
    ['label' => 'Findings &amp; Lessons Register', 'icon' => 'findings', 'href' => '/findings/list.php', 'dir' => 'findings'],
    ['label' => 'Risk Register', 'icon' => 'risks', 'href' => '/risks/list.php', 'dir' => 'risks'],
    ['label' => 'Results Framework', 'icon' => 'results', 'href' => '/indicators/framework.php', 'dir' => 'indicators'],
    ['label' => 'Planning &amp; Dispatch', 'icon' => 'planning', 'href' => '/planning/list.php', 'dir' => 'planning'],
    ['section' => 'Programmes'],
    ['label' => 'Broadband', 'icon' => 'broadband', 'key' => 'broadband', 'children' => [
        ['label' => 'Site Completions', 'href' => '/sites/list.php', 'dir' => 'sites', 'badge' => 'sites_submitted', 'badge_title' => 'submitted, awaiting verification',
            'exclude' => ['/sites/acceptance_list.php', '/sites/acceptance_form.php']],
        ['label' => 'Acceptance Forms', 'href' => '/sites/acceptance_list.php',
            'exact' => ['/sites/acceptance_list.php', '/sites/acceptance_form.php']],
        ['label' => 'New Sites Awaiting Rollout', 'href' => '/broadband/rollout_list.php', 'badge' => 'rollout_new', 'badge_title' => 'sites not yet deployed',
            'exact' => ['/broadband/rollout_list.php', '/broadband/rollout_form.php', '/broadband/rollout_import.php', '/broadband/rollout_map.php']],
        ['label' => 'Pre-Assessment Forms', 'href' => '/broadband/pre_assessment_list.php', 'badge' => 'preassess_todo', 'badge_title' => 'sites still to be pre-assessed',
            'exact' => ['/broadband/pre_assessment_list.php', '/broadband/pre_assessment.php']],
    ]],
    ['label' => 'BDM', 'sublabel' => 'Broadcasting Digital Migration', 'icon' => 'bdm', 'key' => 'bdm', 'children' => [
        ['label' => 'Indigent Application Form', 'href' => '/indigent/form.php', 'exact' => ['/indigent/form.php'],
            'roles' => ['admin', 'manager', 'field_worker', 'indigent_capturer']],
        ['label' => 'Indigent Applications', 'href' => '/indigent/list.php', 'dir' => 'indigent',
            'exclude' => ['/indigent/form.php']],
    ]],
    ['section' => 'Tools'],
    ['label' => 'AI Reports', 'icon' => 'ai', 'href' => '/ai_reports/list.php', 'dir' => 'ai_reports', 'roles' => ['admin', 'manager']],
    ['label' => 'Pages', 'icon' => 'pages', 'href' => '/cms/directory.php', 'exact' => ['/cms/directory.php', '/cms/view.php']],
];
$adminNavItems = [
    ['label' => 'Users', 'icon' => 'users', 'href' => '/users/list.php', 'exact' => ['/users/list.php']],
    ['label' => 'Data Captured', 'icon' => 'data', 'href' => '/reports/data_captured.php', 'exact' => ['/reports/data_captured.php']],
    ['label' => 'Manage Pages', 'icon' => 'edit', 'href' => '/cms/list.php', 'exact' => ['/cms/list.php', '/cms/form.php', '/cms/save.php', '/cms/delete.php']],
    ['label' => 'Documentation', 'icon' => 'docs', 'href' => '/docs/index.php', 'dir' => 'docs'],
];
$superAdminNavItems = [
    ['label' => 'Access Review', 'icon' => 'shield', 'href' => '/users/access_review.php', 'exact' => ['/users/access_review.php']],
    ['label' => 'Settings', 'icon' => 'settings', 'href' => '/settings/edit.php', 'exact' => ['/settings/edit.php']],
];

/** One top-level sidebar link. */
function nav_link(array $item, string $relPath): string
{
    $active = nav_is_active($item, $relPath);
    return '<a href="' . APP_BASE_URL . $item['href'] . '" class="nav-item' . ($active ? ' active' : '') . '"'
        . ($active ? ' aria-current="page"' : '') . '>' . nav_icon($item['icon'] ?? '')
        . '<span class="nav-label">' . $item['label'] . '</span></a>';
}
$navBadges = $user ? nav_badge_counts($pdo) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?><?= e($siteSettings['site_name']) ?></title>
<link rel="stylesheet" href="<?= APP_BASE_URL ?>/assets/css/style.css">
</head>
<body>
<?php if ($user): ?>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= APP_BASE_URL ?>/index.php">
            <?php if ($siteSettings['logo_url']): ?>
                <img src="<?= e($siteSettings['logo_url']) ?>" alt="" class="brand-logo">
            <?php endif; ?>
            <span><?= e($siteSettings['site_name']) ?></span>
        </a>
        <nav class="sidenav" aria-label="Main">
            <?php foreach ($navItems as $item): ?>
                <?php if (isset($item['section'])): ?>
                    <div class="sidenav-divider"><?= e($item['section']) ?></div>
                    <?php continue; ?>
                <?php endif; ?>
                <?php if (isset($item['roles']) && !in_array($user['role'], $item['roles'], true)) continue; ?>
                <?php if (isset($item['children'])):
                    $children = array_filter($item['children'], fn($c) => !isset($c['roles']) || in_array($user['role'], $c['roles'], true));
                    if (!$children) continue;
                    $groupActive = nav_is_active($item, $relPath);
                    $groupId = 'navgrp-' . $item['key'];
                    $groupHasBadge = (bool)array_filter($children, fn($c) => !empty($navBadges[$c['badge'] ?? ''])); ?>
                    <div class="nav-group<?= $groupActive ? ' is-open has-active' : '' ?>" data-group="<?= e($item['key']) ?>"<?= $groupActive ? ' data-active="1"' : '' ?>>
                        <button type="button" class="nav-item nav-group-toggle" aria-expanded="<?= $groupActive ? 'true' : 'false' ?>" aria-controls="<?= $groupId ?>"
                                <?= isset($item['sublabel']) ? 'title="' . e($item['label'] . ' — ' . $item['sublabel']) . '"' : '' ?>>
                            <?= nav_icon($item['icon']) ?>
                            <span class="nav-label"><?= $item['label'] ?>
                                <?php if (isset($item['sublabel'])): ?><small class="nav-sublabel"><?= e($item['sublabel']) ?></small><?php endif; ?>
                            </span>
                            <?php if ($groupHasBadge): ?><span class="nav-pip" aria-hidden="true"></span><?php endif; ?>
                            <svg class="nav-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                        </button>
                        <div class="nav-sub-wrap" id="<?= $groupId ?>">
                            <div class="nav-sub-inner">
                                <?php foreach ($children as $child):
                                    $childActive = nav_is_active($child, $relPath);
                                    $count = $navBadges[$child['badge'] ?? ''] ?? 0; ?>
                                    <a href="<?= APP_BASE_URL . $child['href'] ?>" class="nav-sub<?= $childActive ? ' active' : '' ?>"<?= $childActive ? ' aria-current="page"' : '' ?>>
                                        <span class="nav-sub-label"><?= $child['label'] ?></span>
                                        <?php if ($count > 0): ?>
                                            <span class="nav-badge" title="<?= number_format($count) . ' ' . e($child['badge_title'] ?? '') ?>"><?= $count > 999 ? '999+' : $count ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php continue; ?>
                <?php endif; ?>
                <?= nav_link($item, $relPath) ?>
            <?php endforeach; ?>
            <?php if ($user['role'] === 'admin'): ?>
                <div class="sidenav-divider">Administration</div>
                <?php foreach ($adminNavItems as $item): ?>
                    <?= nav_link($item, $relPath) ?>
                <?php endforeach; ?>
                <?php if (is_super_admin()): ?>
                    <?php foreach ($superAdminNavItems as $item): ?>
                        <?= nav_link($item, $relPath) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <script>
        // Collapsible menu groups with a smooth open/close. The group holding the current page is always open;
        // the others remember the viewer's last choice (closed by default).
        (function () {
            document.querySelectorAll('.nav-group').forEach(function (g) {
                var key = 'navGroup:' + g.getAttribute('data-group');
                var btn = g.querySelector('.nav-group-toggle');
                function set(open, remember) {
                    g.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (remember) { try { localStorage.setItem(key, open ? 'open' : 'closed'); } catch (e) {} }
                }
                if (!g.hasAttribute('data-active')) {
                    var stored = null;
                    try { stored = localStorage.getItem(key); } catch (e) {}
                    if (stored === 'open') {
                        g.classList.add('no-anim');
                        set(true, false);
                        requestAnimationFrame(function () { requestAnimationFrame(function () { g.classList.remove('no-anim'); }); });
                    }
                }
                btn.addEventListener('click', function () { set(!g.classList.contains('is-open'), true); });
            });
        })();
        </script>
        <div class="sidenav-user">
            <span><?= e($user['full_name']) ?></span>
            <?= user_role_badge($user) ?>
            <a href="<?= APP_BASE_URL ?>/change_password.php">Change password</a>
            <a class="logout" href="<?= APP_BASE_URL ?>/logout.php">Log out</a>
        </div>
    </aside>
    <div class="main-col">
        <main class="container">
<?php endif; ?>
<?php if (!$user): ?>
<main class="container">
<?php endif; ?>
<?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
