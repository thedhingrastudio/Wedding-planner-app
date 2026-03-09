<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? $_GET['project_id'] ?? 0);
if ($projectId <= 0) {
  redirect('projects/index.php');
}

$companyId = current_company_id();

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function table_exists_local(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = :table
    ");
    $st->execute([':table' => $table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function responsibility_status_label(string $status): string {
  return match ($status) {
    'received' => 'Received',
    'partially_received' => 'Partially received',
    'not_applicable' => 'Not applicable',
    default => 'Not received',
  };
}

function responsibility_priority_label(string $priority): string {
  return match ($priority) {
    'high' => 'High',
    'low' => 'Low',
    default => 'Medium',
  };
}

function date_label(?string $date): string {
  if (!$date) return '—';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : '—';
}

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("
  SELECT *
  FROM projects
  WHERE id = :pid
    AND company_id = :cid
  LIMIT 1
");
$pstmt->execute([
  ':pid' => $projectId,
  ':cid' => $companyId,
]);
$project = $pstmt->fetch();

if (!$project) {
  redirect('projects/index.php');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));
$projectTitle = trim((string)($project['title'] ?? ''));
if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

$firstEvent = null;
try {
  $evt = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC
    LIMIT 1
  ");
  $evt->execute([':pid' => $projectId]);
  $firstEvent = $evt->fetch();
} catch (Throwable $e) {
  $firstEvent = null;
}

$daysToGo = null;
$topDateLabel = 'Date TBD';

if ($firstEvent && !empty($firstEvent['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$firstEvent['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  $topDateLabel = date('F j, Y', strtotime((string)$firstEvent['starts_at']));
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') {
    $topDateLabel = date('F j, Y', strtotime($createdAt));
  }
}

$projectDateLabel = $topDateLabel;

/* ---------- Responsibilities ---------- */
$tableReady = table_exists_local($pdo, 'project_client_responsibilities');
$searchQ = trim((string)($_GET['q'] ?? ''));
$responsibilities = [];
$errors = [];

if (!$tableReady) {
  $errors[] = 'Missing table: project_client_responsibilities';
} else {
  try {
    $sql = "
      SELECT *
      FROM project_client_responsibilities
      WHERE project_id = :pid
    ";
    $params = [':pid' => $projectId];

    if ($searchQ !== '') {
      $sql .= "
        AND (
          title LIKE :q
          OR description LIKE :q
          OR status LIKE :q
          OR priority LIKE :q
        )
      ";
      $params[':q'] = '%' . $searchQ . '%';
    }

    $sql .= " ORDER BY due_date IS NULL, due_date ASC, id DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $responsibilities = $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    $errors[] = 'Could not load client responsibilities.';
  }
}

$pageTitle = 'Client responsibilities — ' . $projectTitle . ' — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.cr-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:18px;
  margin-bottom:14px;
}
.cr-title{
  margin:0;
  font-size:22px;
  font-weight:800;
  color:#1f1f22;
}
.cr-sub{
  margin:6px 0 0 0;
  color:#6f6f73;
  font-size:13px;
}
.cr-actions{
  display:flex;
  gap:12px;
  align-items:center;
  flex-wrap:wrap;
}
.cr-actions .icon-btn{
  width:42px;
  height:42px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
}
.cr-toolbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:16px;
  margin-bottom:14px;
  flex-wrap:wrap;
}
.cr-search{
  position:relative;
  width:min(360px, 100%);
}
.cr-search span{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#8c8c92;
  font-size:12px;
}
.cr-search input{
  width:100%;
  min-height:40px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.12);
  background:#fff;
  padding:10px 14px 10px 34px;
  box-sizing:border-box;
}
.cr-table-card{
  padding:10px 14px 12px;
  border-radius:24px;
}
.cr-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.cr-table thead th{
  text-align:left;
  padding:14px 14px 12px;
  font-size:12px;
  color:#9a9aa1;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
}
.cr-table tbody td{
  text-align:left;
  padding:14px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  font-size:14px;
  color:#1f1f22;
  vertical-align:middle;
}
.cr-table tbody tr:last-child td{
  border-bottom:none;
}
.cr-row{
  cursor:pointer;
}
.cr-row:hover td{
  background:rgba(0,0,0,0.015);
}
.cr-title-cell{
  font-size:16px;
  color:#1f1f22;
  font-weight:500;
}
.cr-empty-wrap{
  min-height:420px;
  display:flex;
  align-items:center;
  justify-content:center;
  text-align:center;
}
.cr-empty{
  max-width:420px;
  color:#8a8a90;
}
.cr-empty-ico{
  font-size:42px;
  margin-bottom:14px;
  opacity:.7;
}
.cr-empty h3{
  margin:0 0 8px 0;
  font-size:22px;
  line-height:1.2;
  color:#8a8a90;
  font-weight:500;
}
.cr-empty p{
  margin:0;
  font-size:14px;
  line-height:1.5;
}
.cr-alert{
  margin-bottom:14px;
  padding:14px 16px;
  border-radius:18px;
  background:#fff5f5;
  border:1px solid rgba(185,28,28,.14);
}
.cr-alert h4{
  margin:0 0 8px 0;
  font-size:16px;
}
.cr-alert ul{
  margin:0 0 0 18px;
  padding:0;
  line-height:1.7;
}
@media (max-width:980px){
  .cr-head{
    flex-direction:column;
    align-items:flex-start;
  }
}
</style>

<div class="app-shell">
  <?php
    $nav_active = 'projects';
    require_once $root . '/includes/sidebar.php';
  ?>

  <section class="app-main">
    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo esc($adminName); ?>
        <a class="logout" href="<?php echo esc(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo esc($projectTitle); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo esc($topDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo esc((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'contract';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <?php if ($errors): ?>
            <div class="cr-alert">
              <h4>Please fix these fields</h4>
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?php echo esc($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="cr-head">
            <div>
              <h1 class="cr-title">Contract &amp; scope &nbsp;›&nbsp; Client responsibilities</h1>
              <div class="cr-sub">What we need from the client to keep timelines on track.</div>
            </div>

            <div class="cr-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <a class="btn btn-primary" href="<?php echo esc(base_url('projects/contract_client_responsibility_form.php?id=' . $projectId)); ?>">＋ Add new responsibility</a>
              <button class="btn" type="button" disabled>Save changes</button>
            </div>
          </div>

          <div class="cr-toolbar">
            <form class="cr-search" method="get">
              <input type="hidden" name="id" value="<?php echo esc((string)$projectId); ?>">
              <span>⌕</span>
              <input type="text" name="q" value="<?php echo esc($searchQ); ?>" placeholder="Search client responsibility">
            </form>
          </div>

          <div class="card proj-card cr-table-card">
            <?php if ($responsibilities): ?>
              <table class="cr-table">
                <thead>
                  <tr>
                    <th>Client responsibility</th>
                    <th>Due date</th>
                    <th>Status</th>
                    <th>Priority</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($responsibilities as $row): ?>
                    <tr class="cr-row" data-href="<?php echo esc(base_url('projects/contract_client_responsibility_form.php?id=' . $projectId . '&rid=' . (int)$row['id'])); ?>">
                      <td class="cr-title-cell"><?php echo esc((string)$row['title']); ?></td>
                      <td><?php echo esc(date_label((string)($row['due_date'] ?? ''))); ?></td>
                      <td><?php echo esc(responsibility_status_label((string)($row['status'] ?? 'not_received'))); ?></td>
                      <td><?php echo esc(responsibility_priority_label((string)($row['priority'] ?? 'medium'))); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="cr-empty-wrap">
                <div class="cr-empty">
                  <div class="cr-empty-ico">⊚</div>
                  <h3>No client responsibilities added yet</h3>
                  <p>Add responsibilities or inputs that are expected from the client to execute this project in the best way possible.</p>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
document.querySelectorAll('.cr-row[data-href]').forEach((row) => {
  row.addEventListener('click', () => {
    window.location.href = row.dataset.href;
  });
});
</script>

<?php require_once $root . '/includes/footer.php'; ?>