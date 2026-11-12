<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

$pageTitle = 'Documentation';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Documentation</h1>
        <p class="subtitle">Training manual and architecture reference for the USAASA MEL System</p>
    </div>
</div>

<div class="card">
    <h2>Training Manual</h2>
    <p>Step-by-step "how to" instructions for every module, written for the people who use the system day to day — installers, reviewers, approvers, data capturers, planners, managers and admins. Includes placeholders marking exactly where a real screenshot belongs (this session has no browser automation available, so these are descriptions of what to capture, not actual images).</p>
    <a class="btn" href="<?= APP_BASE_URL ?>/docs/training_manual.php">Open Training Manual</a>
</div>

<div class="card">
    <h2>Enterprise Architecture</h2>
    <p>System context, component architecture, data architecture, technology stack and deployment model — written for whoever next has to maintain, extend, host, or hand this system over.</p>
    <a class="btn" href="<?= APP_BASE_URL ?>/docs/enterprise_architecture.php">Open Enterprise Architecture</a>
</div>

<div class="card">
    <h2>Security Architecture</h2>
    <p>Authentication, authorization, data protection, audit trails, and the concrete defences built into every module — plus known gaps and recommendations, for whoever next has to assess or extend this system's security posture.</p>
    <a class="btn" href="<?= APP_BASE_URL ?>/docs/security_architecture.php">Open Security Architecture</a>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
