<?php
/**
 * Digital Transformation > Governance & Accountability.
 * The governance structure (who owns and approves each component), the
 * signatories who sign on each body's behalf, and an accountability matrix
 * of deliverables x bodies. ?p=<workstream slug> picks the matrix (default erp).
 * Super admin POST actions: add_body, update_body, delete_body, add_member, remove_member.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

$project = dt_get_project($pdo, (string)($_GET['p'] ?? 'erp')) ?? dt_get_project($pdo, 'erp');
$selfUrl = '/digital/governance.php' . ($project['slug'] !== 'erp' ? '?p=' . urlencode($project['slug']) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_super_admin();
    $action = $_POST['action'] ?? '';
    $bodyId = (int)($_POST['body_id'] ?? 0);

    if ($action === 'add_member') {
        $memberId = (int)($_POST['user_id'] ?? 0);
        $designation = trim((string)($_POST['designation'] ?? ''));
        $ok = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1');
        $ok->execute([$memberId]);
        $okBody = $pdo->prepare('SELECT 1 FROM dt_governance_bodies WHERE id = ?');
        $okBody->execute([$bodyId]);
        if (!$ok->fetchColumn() || !$okBody->fetchColumn()) {
            flash('error', 'Choose an active user and governance body.');
        } else {
            $pdo->prepare('INSERT INTO dt_governance_members (body_id, user_id, designation, assigned_by) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE designation = VALUES(designation)')
                ->execute([$bodyId, $memberId, $designation === '' ? null : mb_substr($designation, 0, 150), $user['id']]);
            flash('success', 'Signatory assigned.');
            // Deliverables already waiting on this body: let the new signatory know.
            $stmt = $pdo->prepare("SELECT id FROM dt_deliverables WHERE pending_body_id = ? AND status = 'Awaiting Sign-off'");
            $stmt->execute([$bodyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $did) {
                dt_notify_signatories($pdo, (int)$did, $bodyId);
            }
        }
    } elseif ($action === 'remove_member') {
        $pdo->prepare('DELETE FROM dt_governance_members WHERE id = ?')->execute([(int)($_POST['member_id'] ?? 0)]);
        flash('success', 'Signatory removed. Their past signatures stay on record.');
    } elseif ($action === 'add_body' || $action === 'update_body') {
        $name = trim((string)($_POST['name'] ?? ''));
        $short = trim((string)($_POST['short_name'] ?? ''));
        $resp = trim((string)($_POST['responsibility'] ?? ''));
        if ($name === '' || $resp === '') {
            flash('error', 'Name and responsibility are required.');
            redirect($selfUrl);
        }
        $short = mb_substr($short === '' ? $name : $short, 0, 40);
        if ($action === 'add_body') {
            $code = 'GB' . strtoupper(bin2hex(random_bytes(3)));
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM dt_governance_bodies')->fetchColumn();
            $pdo->prepare('INSERT INTO dt_governance_bodies (code, name, short_name, responsibility, sort_order) VALUES (?, ?, ?, ?, ?)')
                ->execute([$code, mb_substr($name, 0, 150), $short, mb_substr($resp, 0, 255), $sort]);
            flash('success', 'Governance body added. Assign its signatories, then add it to approval workflows.');
        } else {
            $pdo->prepare('UPDATE dt_governance_bodies SET name = ?, short_name = ?, responsibility = ? WHERE id = ?')
                ->execute([mb_substr($name, 0, 150), $short, mb_substr($resp, 0, 255), $bodyId]);
            flash('success', 'Governance body updated.');
        }
    } elseif ($action === 'delete_body') {
        $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM dt_workflow_steps WHERE body_id = ?) + (SELECT COUNT(*) FROM dt_deliverables WHERE owner_body_id = ?)');
        $used->execute([$bodyId, $bodyId]);
        if ((int)$used->fetchColumn() > 0) {
            flash('error', 'This body still owns deliverables or sits on approval routes. Remove it from those first.');
        } else {
            $pdo->prepare('DELETE FROM dt_governance_bodies WHERE id = ?')->execute([$bodyId]);
            flash('success', 'Governance body removed.');
        }
    }
    redirect($selfUrl);
}

$bodies = dt_bodies($pdo);
$members = [];
foreach ($pdo->query('SELECT m.*, u.full_name, u.email, u.is_active FROM dt_governance_members m JOIN users u ON u.id = m.user_id ORDER BY u.full_name')->fetchAll() as $m) {
    $members[(int)$m['body_id']][] = $m;
}
$owns = $pdo->query('SELECT owner_body_id, COUNT(*) FROM dt_deliverables WHERE owner_body_id IS NOT NULL GROUP BY owner_body_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$approves = $pdo->query('SELECT body_id, COUNT(*) FROM dt_workflow_steps GROUP BY body_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$waiting = $pdo->query("SELECT pending_body_id, COUNT(*) FROM dt_deliverables WHERE status = 'Awaiting Sign-off' AND pending_body_id IS NOT NULL GROUP BY pending_body_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare('SELECT id, code, section, title, owner_body_id FROM dt_deliverables WHERE project_id = ? ORDER BY section, sort_order, code');
$stmt->execute([$project['id']]);
$matrixRows = $stmt->fetchAll();
$steps = [];
if ($matrixRows) {
    $stmt = $pdo->prepare('SELECT w.deliverable_id, w.body_id, w.step_order FROM dt_workflow_steps w JOIN dt_deliverables d ON d.id = w.deliverable_id WHERE d.project_id = ?');
    $stmt->execute([$project['id']]);
    foreach ($stmt->fetchAll() as $w) {
        $steps[(int)$w['deliverable_id']][(int)$w['body_id']] = (int)$w['step_order'];
    }
}
$projects = $pdo->query('SELECT slug, name FROM dt_projects ORDER BY sort_order')->fetchAll();
$users = is_super_admin() ? $pdo->query('SELECT id, full_name, username, role FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll() : [];
$labels = dt_section_labels();

$pageTitle = 'Governance & Accountability';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1>Governance &amp; Accountability</h1>
        <p class="subtitle">The blueprint identifies who owns and approves each component. Each body's signatories endorse the documentation routed to them.</p>
    </div>
    <?php if (is_super_admin()): ?><a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/digital/workflows.php">Approval workflows</a><?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0;">Governance structure</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Governance structure</th><th>Responsibility</th><th>Signatories</th><th>Owns</th><th>Approves</th><th>Awaiting signature</th></tr></thead>
            <tbody>
            <?php foreach ($bodies as $b): $bid = (int)$b['id']; ?>
                <tr>
                    <td><strong><?= e($b['name']) ?></strong>
                        <?php if (is_super_admin()): ?>
                            <details style="margin-top:6px;">
                                <summary class="hint" style="cursor:pointer;">Edit</summary>
                                <form method="post" style="margin-top:8px; display:grid; gap:6px; min-width:220px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_body">
                                    <input type="hidden" name="body_id" value="<?= $bid ?>">
                                    <input type="text" name="name" value="<?= e($b['name']) ?>" maxlength="150" aria-label="Name" required>
                                    <input type="text" name="short_name" value="<?= e($b['short_name']) ?>" maxlength="40" aria-label="Short name">
                                    <input type="text" name="responsibility" value="<?= e($b['responsibility']) ?>" maxlength="255" aria-label="Responsibility" required>
                                    <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                                </form>
                                <?php if (empty($owns[$bid]) && empty($approves[$bid])): ?>
                                    <form method="post" style="margin-top:6px;" onsubmit="return confirm('Remove this governance body?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_body">
                                        <input type="hidden" name="body_id" value="<?= $bid ?>">
                                        <button type="submit" class="btn-link">Remove body</button>
                                    </form>
                                <?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td><?= e($b['responsibility']) ?></td>
                    <td>
                        <?php if (!empty($members[$bid])): ?>
                            <ul class="dt-member-list">
                                <?php foreach ($members[$bid] as $m): ?>
                                    <li>
                                        <span><?= e($m['full_name']) ?><?= $m['designation'] ? ' <span class="hint">' . e($m['designation']) . '</span>' : '' ?>
                                            <?= !$m['is_active'] ? ' <span class="badge badge-overdue">inactive</span>' : '' ?>
                                            <?= !$m['email'] ? ' <span class="hint" title="Will not receive routing emails">(no email)</span>' : '' ?></span>
                                        <?php if (is_super_admin()): ?>
                                            <form method="post" class="dt-inline-form" onsubmit="return confirm('Remove <?= e($m['full_name']) ?> as a signatory?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="remove_member">
                                                <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                                <button type="submit" class="btn-link">remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="dt-signatories is-missing">No signatory assigned</span>
                        <?php endif; ?>
                        <?php if (is_super_admin()): ?>
                            <form method="post" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_member">
                                <input type="hidden" name="body_id" value="<?= $bid ?>">
                                <select name="user_id" required aria-label="User" style="max-width:190px;">
                                    <option value="">Assign user…</option>
                                    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?> (<?= e(role_label($u['role'])) ?>)</option><?php endforeach; ?>
                                </select>
                                <input type="text" name="designation" placeholder="Designation" maxlength="150" aria-label="Designation" style="max-width:150px;">
                                <button type="submit" class="btn btn-secondary btn-sm">Add</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)($owns[$bid] ?? 0) ?></td>
                    <td><?= (int)($approves[$bid] ?? 0) ?></td>
                    <td><?= !empty($waiting[$bid]) ? '<span class="badge badge-progress">' . (int)$waiting[$bid] . '</span>' : '0' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (is_super_admin()): ?>
        <details style="margin-top:14px;">
            <summary style="cursor:pointer; font-weight:600;">Add a governance body / sign-off resource</summary>
            <p class="hint">E.g. Executive: Corporate Services, CTO, Chief Risk Officer. Once added, assign its signatories and include it in approval workflows.</p>
            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_body">
                <div class="field"><label for="nb_name">Name</label><input type="text" id="nb_name" name="name" maxlength="150" required></div>
                <div class="field"><label for="nb_short">Short name <span class="hint">(shown on route chips)</span></label><input type="text" id="nb_short" name="short_name" maxlength="40"></div>
                <div class="field full"><label for="nb_resp">Responsibility</label><input type="text" id="nb_resp" name="responsibility" maxlength="255" required></div>
                <div class="field"><button type="submit" class="btn">Add governance body</button></div>
            </form>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <div class="page-header" style="margin-bottom:8px;">
        <h2 style="margin:0;">Accountability matrix &mdash; <?= e($project['name']) ?></h2>
        <form method="get">
            <select name="p" onchange="this.form.submit()" aria-label="Workstream">
                <?php foreach ($projects as $p): ?><option value="<?= e($p['slug']) ?>" <?= $p['slug'] === $project['slug'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="dt-legend"><span><span class="dt-owner">O</span> accountable owner</span><span><span class="dt-approver">2</span> approves at sign-off step 2</span></div>
    <div class="table-wrap">
        <table class="dt-matrix">
            <thead>
            <tr><th>Deliverable</th><?php foreach ($bodies as $b): ?><th title="<?= e($b['name']) ?>"><?= e($b['short_name']) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php $lastSection = null; foreach ($matrixRows as $r): ?>
                <?php if ($project['slug'] === 'erp' && $r['section'] !== $lastSection): $lastSection = $r['section']; ?>
                    <tr><th colspan="<?= count($bodies) + 1 ?>" style="background:#f4f6f8;"><?= e($labels[$r['section']] ?? $r['section']) ?></th></tr>
                <?php endif; ?>
                <tr>
                    <td><a href="<?= APP_BASE_URL ?>/digital/deliverable.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a></td>
                    <?php foreach ($bodies as $b): $bid = (int)$b['id']; ?>
                        <td>
                            <?= (int)$r['owner_body_id'] === $bid ? '<span class="dt-owner" title="Accountable owner">O</span> ' : '' ?>
                            <?= isset($steps[(int)$r['id']][$bid]) ? '<span class="dt-approver" title="Sign-off step ' . $steps[(int)$r['id']][$bid] . '">' . $steps[(int)$r['id']][$bid] . '</span>' : '' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$matrixRows): ?><tr><td colspan="<?= count($bodies) + 1 ?>">No deliverables registered for this workstream yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
