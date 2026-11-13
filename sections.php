<?php
require 'lib.php';
require_admin();
$message = '';
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';
    $section_name = mb_strtoupper(trim($_POST['section_name'] ?? ''));
    $program = trim($_POST['program'] ?? '');
    if ($program === '') $program = null;
    $building = trim($_POST['building'] ?? '');

    try {
        if ($action === 'add' || $action === 'update') {
            if ($section_name === '' || $building === '') {
                $message = 'Section name and building are required.';
            } elseif ($action === 'add') {
                $stmt = $pdo->prepare("INSERT INTO sections (section_name, program, building) VALUES (?, ?, ?)");
                $stmt->execute([$section_name, $program, $building]);
                header('Location: sections.php');
                exit;
            } else {
                $stmt = $pdo->prepare("UPDATE sections SET section_name = ?, program = ?, building = ? WHERE section_id = ?");
                $stmt->execute([$section_name, $program, $building, (int)$_POST['section_id']]);
                header('Location: sections.php');
                exit;
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM sections WHERE section_id = ?");
            $stmt->execute([(int)$_POST['section_id']]);
            header('Location: sections.php');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            $message = 'That section already exists.';
        } elseif ($e->getCode() === '23503') {
            $message = 'Cannot delete: this section is used in a schedule or rating.';
        } else {
            throw $e;
        }
    }
}

$edit_section = null;
$edit_id = (int)($_GET['edit'] ?? ($action === 'update' ? $_POST['section_id'] : 0));
if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE section_id = ?");
    $stmt->execute([$edit_id]);
    $edit_section = $stmt->fetch(PDO::FETCH_ASSOC);
}

$sections = $pdo->query("SELECT * FROM sections ORDER BY section_name")->fetchAll(PDO::FETCH_ASSOC);
$programs = array_values(array_unique(array_filter(array_map(fn($s) => $s['program'] ?? '', $sections))));
sort($programs);
$byB = [];
foreach ($sections as $s) { if (!empty($s['building'])) $byB[$s['building']] = ($byB[$s['building']] ?? 0) + 1; }
arsort($byB);
$buildings = array_keys($byB); sort($buildings);
$total = count($sections); $assigned = array_sum($byB);
$top = array_slice($byB, 0, 2, true);
$pct = fn($n) => $total ? round($n / $total * 100) : 0;

$fv = $edit_section ?? ($message && $action === 'add' ? ['section_name' => $section_name, 'program' => $program, 'building' => $building] : []);
$openForm = $edit_section || ($message && $action === 'add');
$title = 'Sections'; include 'header.php'; ?>
<div class="a-head">
    <div><h1 class="s-h1">Sections</h1><p class="s-sub">See how sections are distributed across your buildings.</p></div>
    <span class="s-date"><?= date('D, F j, Y') ?></span>
</div>

<?php if ($message) echo "<p class='s-alert'>" . h($message) . "</p>"; ?>

<div class="s-kpis">
    <div class="s-kpi"><small>Buildings</small><b><?= count($byB) ?></b><em>Campus-wide</em></div>
    <div class="s-kpi"><small>Sections</small><b><?= $total ?></b><em><?= $assigned === $total ? 'All assigned to a building' : $assigned . ' assigned to a building' ?></em></div>
    <?php foreach ($top as $b => $n): ?>
    <div class="s-kpi"><small><?= h($b) ?></small><b><?= $n ?> section<?= $n === 1 ? '' : 's' ?></b><em><?= $pct($n) ?>% of all sections</em></div>
    <?php endforeach; ?>
    <?php for ($k = count($top); $k < 2; $k++): ?><div class="s-kpi"><small>&nbsp;</small><b>-</b><em>No building yet</em></div><?php endfor; ?>
</div>

<div class="a-bar">
    <input type="search" data-search placeholder="Search section...">
    <select data-filter data-attr="f">
        <option value="">All buildings</option>
        <?php foreach ($buildings as $b): ?><option><?= h($b) ?></option><?php endforeach; ?>
    </select>
    <select data-filter data-attr="p">
        <option value="">All programs</option>
        <?php foreach ($programs as $p): ?><option><?= h($p) ?></option><?php endforeach; ?>
    </select>
    <button type="button" class="s-btn" data-open="formModal">+ Add section</button>
</div>

<section class="a-card">
    <h3 id="a-count"><?= $total ?> sections</h3>
    <p class="s-muted">Manage assignments and access from one place.</p>
    <div class="table-wrap"><table class="a-table tight" data-paged="8" data-noun="sections">
        <thead><tr><th>Section</th><th>Program</th><th>Building</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($sections as $s): ?>
        <tr class="r" data-q="<?= h(strtolower($s['section_name'] . ' ' . ($s['program'] ?? '') . ' ' . ($s['building'] ?? ''))) ?>" data-f="<?= h($s['building'] ?? '') ?>" data-p="<?= h($s['program'] ?? '') ?>">
            <td class="nm"><?= h($s['section_name']) ?></td>
            <td><?= h($s['program'] ?? '—') ?></td>
            <td><?= h($s['building'] ?? '—') ?></td>
            <td>
                <a class="a-btn" href="sections.php?edit=<?= (int)$s['section_id'] ?>">Edit</a>
                <form method="post" data-confirm data-title="Delete section" data-heading="Delete <?= h($s['section_name']) ?>?" data-text="This record will be removed. A section that is used in a schedule or rating can't be deleted.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="section_id" value="<?= (int)$s['section_id'] ?>">
                    <button type="submit" class="a-btn del">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="none" hidden><td colspan="4">No sections match.</td></tr>
        </tbody>
    </table></div>
    <div class="a-pager"><span id="a-showing"></span><div><button type="button" id="a-prev">Previous</button><button type="button" id="a-next">Next</button></div></div>
</section>

<section class="s-note"><b>Keep your campus records up to date</b><small>Use Edit to update a record. Delete asks for confirmation before removing it.</small></section>

<dialog id="formModal" class="a-modal fig" <?= $openForm ? 'data-autoopen' : '' ?>>
    <form method="post">
        <?= csrf_field() ?>
        <h3><?= $edit_section ? 'Edit section' : 'Add section' ?></h3>
        <p class="a-sub"><?= $edit_section ? 'Update the selected record below.' : 'Create a new campus record.' ?></p>
        <input type="hidden" name="action" value="<?= $edit_section ? 'update' : 'add' ?>">
        <?php if ($edit_section): ?><input type="hidden" name="section_id" value="<?= (int)$edit_section['section_id'] ?>"><?php endif; ?>
        <label>Section name</label><input name="section_name" placeholder="Enter section name" maxlength="50" required value="<?= h($fv['section_name'] ?? '') ?>">
        <label>Program</label><input name="program" placeholder="e.g. BSIT" maxlength="50" value="<?= h($fv['program'] ?? '') ?>">
        <label>Building</label><input name="building" placeholder="Enter building" maxlength="50" required value="<?= h($fv['building'] ?? '') ?>">
        <p class="a-req">Program is optional.</p>
        <div class="a-acts"><button type="button" class="s-btn soft" data-close>Cancel</button><button type="submit" class="s-btn"><?= $edit_section ? 'Save changes' : 'Add section' ?></button></div>
    </form>
</dialog>
<?php include 'footer.php'; ?>
