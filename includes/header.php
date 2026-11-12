<?php
$user = current_user();
$siteSettings = get_site_settings($pdo);

$relPath = substr($_SERVER['SCRIPT_NAME'], strlen(APP_BASE_URL)); // e.g. /cms/list.php

function nav_is_active(array $item, string $relPath): bool
{
    if (isset($item['exact'])) {
        return in_array($relPath, $item['exact'], true);
    }
    if (isset($item['dir'])) {
        return strpos($relPath, '/' . $item['dir'] . '/') === 0;
    }
    return false;
}

$navItems = [
    ['label' => 'Dashboard', 'href' => '/index.php', 'exact' => ['/index.php']],
    ['label' => 'Projects', 'href' => '/projects/list.php', 'exact' => ['/projects/list.php']],
    ['label' => 'Findings &amp; Lessons Register', 'href' => '/findings/list.php', 'dir' => 'findings'],
    ['label' => 'Risk Register', 'href' => '/risks/list.php', 'dir' => 'risks'],
    ['label' => 'Results Framework', 'href' => '/indicators/framework.php', 'dir' => 'indicators'],
    ['label' => 'Planning &amp; Dispatch', 'href' => '/planning/list.php', 'dir' => 'planning'],
    ['label' => 'Site Completions', 'href' => '/sites/list.php', 'dir' => 'sites'],
    ['label' => 'Indigent Applications', 'href' => '/indigent/list.php', 'dir' => 'indigent'],
    ['label' => 'AI Reports', 'href' => '/ai_reports/list.php', 'dir' => 'ai_reports', 'roles' => ['admin', 'manager']],
    ['label' => 'Pages', 'href' => '/cms/directory.php', 'exact' => ['/cms/directory.php', '/cms/view.php']],
];
$adminNavItems = [
    ['label' => 'Users', 'href' => '/users/list.php', 'exact' => ['/users/list.php']],
    ['label' => 'Data Captured', 'href' => '/reports/data_captured.php', 'exact' => ['/reports/data_captured.php']],
    ['label' => 'Manage Pages', 'href' => '/cms/list.php', 'exact' => ['/cms/list.php', '/cms/form.php', '/cms/save.php', '/cms/delete.php']],
    ['label' => 'Documentation', 'href' => '/docs/index.php', 'dir' => 'docs'],
];
$superAdminNavItems = [
    ['label' => 'Access Review', 'href' => '/users/access_review.php', 'exact' => ['/users/access_review.php']],
    ['label' => 'Settings', 'href' => '/settings/edit.php', 'exact' => ['/settings/edit.php']],
];
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
        <nav class="sidenav">
            <?php foreach ($navItems as $item): ?>
                <?php if (isset($item['roles']) && !in_array($user['role'], $item['roles'], true)) continue; ?>
                <a href="<?= APP_BASE_URL . $item['href'] ?>" class="<?= nav_is_active($item, $relPath) ? 'active' : '' ?>"><?= $item['label'] ?></a>
            <?php endforeach; ?>
            <?php if ($user['role'] === 'admin'): ?>
                <div class="sidenav-divider">Administration</div>
                <?php foreach ($adminNavItems as $item): ?>
                    <a href="<?= APP_BASE_URL . $item['href'] ?>" class="<?= nav_is_active($item, $relPath) ? 'active' : '' ?>"><?= $item['label'] ?></a>
                <?php endforeach; ?>
                <?php if (is_super_admin()): ?>
                    <?php foreach ($superAdminNavItems as $item): ?>
                        <a href="<?= APP_BASE_URL . $item['href'] ?>" class="<?= nav_is_active($item, $relPath) ? 'active' : '' ?>"><?= $item['label'] ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
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
