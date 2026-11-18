<?php
/**
 * Super admin: edit one deliverable's details and its ordered sign-off route,
 * optionally applying the same route to other deliverables in the workstream.
 * ?id=<deliverable id>
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_super_admin();
$user = current_user();

const DT_MAX_STEPS = 8;

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT d.*, p.name AS project_name, p.slug FROM dt_deliverables d JOIN dt_projects p ON p.id = d.project_id WHERE d.id = ?');
$stmt->execute([$id]);
$d = $stmt->fetch();
if (!$d) {
    flash('error', 'Deliverable not found.');
    redirect('/digital/workflows.php');
}
$bodies = dt_bodies($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $owner = (int)($_POST['owner_body_id'] ?? 0);

    $route = [];
    foreach ((array)($_POST['steps'] ?? []) as $bodyId) {
        $bodyId = (int)$bodyId;
        if ($bodyId && isset($bodies[$bodyId])) {
            $route[] = $bodyId;
        }
    }
    $errors = [];
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if (count($route) !== count(array_unique($route))) {
        $errors[] = 'A governance body can appear only once on a route.';
    }
    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect('/digital/workflow_edit.php?id=' . $id);
    }

    // Targets for the route: this deliverable plus any ticked siblings in the same workstream.
    $targets = [$id];
    $stmt = $pdo->prepare('SELECT id FROM dt_deliverables WHERE project_id = ? AND id <> ?');
    $stmt->execute([$d['project_id'], $id]);
    $siblings = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ((array)($_POST['apply_to'] ?? []) as $other) {
        if (in_array((int)$other, $siblings, true)) {
            $targets[] = (int)$other;
        }
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE dt_deliverables SET title = ?, description = ?, owner_body_id = ? WHERE id = ?')
        ->execute([mb_substr($title, 0, 255), $description === '' ? null : $description, isset($bodies[$owner]) ? $owner : null, $id]);
    $del = $pdo->prepare('DELETE FROM dt_workflow_steps WHERE deliverable_id = ?');
    $ins = $pdo->prepare('INSERT INTO dt_workflow_steps (deliverable_id, step_order, body_id) VALUES (?, ?, ?)');
    $mark = $pdo->prepare('UPDATE dt_deliverables SET workflow_customised = 1 WHERE id = ?');
    foreach ($targets as $t) {
        $del->execute([$t]);
        foreach ($route as $i => $bodyId) {
            $ins->execute([$t, $i + 1, $bodyId]);
        }
        $mark->execute([$t]);
    }
    $pdo->commit();
    foreach ($targets as $t) {
        dt_refresh_status($pdo, $t);
    }
    flash('success', 'Sign-off route saved' . (count($targets) > 1 ? ' for ' . count($targets) . ' deliverables' : '') . '.');
    redirect('/digital/workflow_edit.php?id=' . $id);
}

$stmt = $pdo->prepare('SELECT body_id FROM dt_workflow_steps WHERE deliverable_id = ? ORDER BY step_order');
$stmt->execute([$id]);
$current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->prepare('SELECT * FROM dt_deliverables WHERE project_id = ? AND id <> ? ORDER BY section, sort_order, code');
$stmt->execute([$d['project_id'], $id]);
$siblings = $stmt->fetchAll();
$siblingRoutes = dt_routes($pdo, $siblings);
$signatoryCount = $pdo->query('SELECT body_id, COUNT(*) FROM dt_governance_members GROUP BY body_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$labels = dt_section_labels();

$pageTitle = 'Edit route — ' . $d['code'];
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Sign-off route &mdash; <?= e($d['code']) ?></h1>
        <p class="subtitle"><?= e($d['project_name']) ?><?= $d['slug'] === 'erp' ? ' &rsaquo; ' . e($labels[$d['section']] ?? '') : '' ?></p>
    </div>
    <div class="actions-row" style="margin:0;">
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= $id ?>">Open deliverable</a>
        <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/workflows.php?p=<?= urlencode($d['slug']) ?>">All workflows</a>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="dt-two-col">
        <div class="card">
            <h2 style="margin-top:0;">Deliverable</h2>
            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="<?= e($d['title']) ?>" maxlength="255" required>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"><?= e($d['description'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label for="owner_body_id">Accountable owner</label>
                <select id="owner_body_id" name="owner_body_id">
                    <option value="">— Not set —</option>
                    <?php foreach ($bodies as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$d['owner_body_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Route the POE for sign-off</h2>
            <p class="hint" style="margin-top:0;">Step 1 signs first; each later step is only asked once the previous one has endorsed. Leave unused steps blank.</p>
            <div class="dt-route-editor">
                <?php for ($i = 0; $i < DT_MAX_STEPS; $i++): $sel = $current[$i] ?? 0; ?>
                    <label class="dt-route-row"><span>Step <?= $i + 1 ?></span>
                        <select name="steps[]">
                            <option value="">—</option>
                            <?php foreach ($bodies as $b): $n = (int)($signatoryCount[$b['id']] ?? 0); ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $sel === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?><?= $n ? '' : ' — no signatory yet' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endfor; ?>
            </div>
            <?php if ($d['status'] !== 'Not Started'): ?>
                <p class="hint">Endorsements already given on the current version are kept for bodies that stay on the route.
                    <?= $d['status'] === 'Signed Off' ? 'Adding a body re-opens this signed-off deliverable for that body\'s signature.' : '' ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($siblings): ?>
        <details class="card">
            <summary style="cursor:pointer; font-weight:600;">Also apply this route to other <?= e($d['project_name']) ?> deliverables</summary>
            <div class="table-wrap" style="margin-top:12px;">
                <table class="dt-checklist">
                    <thead><tr><th><input type="checkbox" aria-label="Select all" onclick="document.querySelectorAll('.dt-apply').forEach(c => c.checked = this.checked)"></th><th>Deliverable</th><th>Current route</th></tr></thead>
                    <tbody>
                    <?php foreach ($siblings as $s): ?>
                        <tr>
                            <td><input type="checkbox" class="dt-apply" name="apply_to[]" value="<?= (int)$s['id'] ?>" id="ap<?= (int)$s['id'] ?>"></td>
                            <td><label for="ap<?= (int)$s['id'] ?>" style="font-weight:400; margin:0;"><?= e($s['code']) ?> &mdash; <?= e($s['title']) ?></label></td>
                            <td><?= dt_route_chips($siblingRoutes[(int)$s['id']] ?? []) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <div class="actions-row"><button type="submit" class="btn">Save route</button></div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
