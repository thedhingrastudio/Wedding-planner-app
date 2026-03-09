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
$responsibilityId = (int)($_GET['rid'] ?? 0);

if ($projectId <= 0) {
  redirect('projects/index.php');
}

$companyId = current_company_id();

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function posted(string $key, string $default = ''): string {
  return trim((string)($_POST[$key] ?? $default));
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

function selected_attr(string $a, string $b): string {
  return $a === $b ? 'selected' : '';
}

/* ---------- Table ---------- */
$errors = [];
if (!table_exists_local($pdo, 'project_client_responsibilities')) {
  $errors[] = 'Missing table: project_client_responsibilities';
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

/* ---------- Existing responsibility ---------- */
$isEdit = $responsibilityId > 0;
$responsibility = null;

if ($isEdit && !$errors) {
  $st = $pdo->prepare("
    SELECT *
    FROM project_client_responsibilities
    WHERE id = :id
      AND project_id = :pid
    LIMIT 1
  ");
  $st->execute([
    ':id' => $responsibilityId,
    ':pid' => $projectId,
  ]);
  $responsibility = $st->fetch() ?: null;

  if (!$responsibility) {
    redirect('projects/contract_client_responsibilities.php?id=' . $projectId);
  }
}

/* ---------- Form values ---------- */
$title = $isEdit ? trim((string)($responsibility['title'] ?? '')) : '';
$description = $isEdit ? trim((string)($responsibility['description'] ?? '')) : '';
$dueDate = $isEdit ? trim((string)($responsibility['due_date'] ?? '')) : '';
$status = $isEdit ? trim((string)($responsibility['status'] ?? 'not_received')) : 'not_received';
$priority = $isEdit ? trim((string)($responsibility['priority'] ?? 'medium')) : 'medium';

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
  $title = posted('title');
  $description = posted('description');
  $dueDate = posted('due_date');
  $status = posted('status', 'not_received');
  $priority = posted('priority', 'medium');

  if ($title === '') {
    $errors[] = 'Enter the client responsibility.';
  }

  if (!in_array($status, ['not_received', 'received', 'partially_received', 'not_applicable'], true)) {
    $errors[] = 'Select a valid status.';
  }

  if (!in_array($priority, ['low', 'medium', 'high'], true)) {
    $errors[] = 'Select a valid priority.';
  }

  if (!$errors) {
    try {
      $userId = (int)($_SESSION['user_id'] ?? 0);

      if ($isEdit) {
        $up = $pdo->prepare("
          UPDATE project_client_responsibilities
          SET title = :title,
              description = :description,
              due_date = :due_date,
              status = :status,
              priority = :priority,
              updated_by = :updated_by,
              updated_at = NOW()
          WHERE id = :id
            AND project_id = :pid
        ");
        $up->execute([
          ':title' => $title,
          ':description' => $description !== '' ? $description : null,
          ':due_date' => $dueDate !== '' ? $dueDate : null,
          ':status' => $status,
          ':priority' => $priority,
          ':updated_by' => $userId > 0 ? $userId : null,
          ':id' => $responsibilityId,
          ':pid' => $projectId,
        ]);
      } else {
        $ins = $pdo->prepare("
          INSERT INTO project_client_responsibilities (
            project_id, title, description, due_date, status, priority, created_by, updated_by, created_at, updated_at
          ) VALUES (
            :project_id, :title, :description, :due_date, :status, :priority, :created_by, :updated_by, NOW(), NOW()
          )
        ");
        $ins->execute([
          ':project_id' => $projectId,
          ':title' => $title,
          ':description' => $description !== '' ? $description : null,
          ':due_date' => $dueDate !== '' ? $dueDate : null,
          ':status' => $status,
          ':priority' => $priority,
          ':created_by' => $userId > 0 ? $userId : null,
          ':updated_by' => $userId > 0 ? $userId : null,
        ]);
      }

      if (function_exists('flash_set')) {
        flash_set('success', $isEdit ? 'Client responsibility updated.' : 'Client responsibility added.');
      }

      redirect('projects/contract_client_responsibilities.php?id=' . $projectId);
    } catch (Throwable $e) {
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

/* ---------- Contact reference ---------- */
$contact1Name = trim((string)($project['partner1_name'] ?? ''));
$contact1Phone = trim((string)($project['phone1'] ?? ''));
$contact1Email = trim((string)($project['email1'] ?? ''));

$contact2Name = trim((string)($project['partner2_name'] ?? ''));
$contact2Phone = trim((string)($project['phone2'] ?? ''));
$contact2Email = trim((string)($project['email2'] ?? ''));

$addressLabel = '—';
$addressParts = [];
foreach (['address', 'address_line1', 'address_line2', 'city', 'state', 'postal_code'] as $k) {
  if (!empty($project[$k])) {
    $addressParts[] = trim((string)$project[$k]);
  }
}
if ($addressParts) {
  $addressLabel = implode(', ', array_unique($addressParts));
}

$pageTitle = ($isEdit ? 'Edit' : 'Add') . ' client responsibility — ' . $projectTitle . ' — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.crf-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:18px;
  margin-bottom:14px;
}
.crf-title{
  margin:0;
  font-size:22px;
  font-weight:800;
  color:#1f1f22;
}
.crf-sub{
  margin:6px 0 0 0;
  color:#6f6f73;
  font-size:13px;
}
.crf-actions{
  display:flex;
  gap:12px;
  align-items:center;
  flex-wrap:wrap;
}
.crf-actions .icon-btn{
  width:42px;
  height:42px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
}
.crf-layout{
  display:grid;
  grid-template-columns:minmax(0,1.25fr) minmax(320px,.6fr);
  gap:16px;
  align-items:start;
}
@media (max-width:1080px){
  .crf-layout{
    grid-template-columns:1fr;
  }
}
.crf-card{
  padding:16px;
  border-radius:24px;
}
.crf-card h3{
  margin:0;
  font-size:18px;
  font-weight:800;
  color:#222;
}
.crf-card p{
  margin:4px 0 12px 0;
  color:#75757a;
  font-size:13px;
}
.crf-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin:12px 0 10px;
}
.crf-grid-2{
  display:grid;
  grid-template-columns:1fr 300px;
  gap:16px;
}
@media (max-width:880px){
  .crf-grid-2{
    grid-template-columns:1fr;
  }
}
.crf-field{
  display:flex;
  flex-direction:column;
  gap:7px;
  margin-bottom:14px;
}
.crf-field label{
  font-size:12px;
  font-weight:700;
  color:#68686d;
}
.crf-field input,
.crf-field textarea,
.crf-field select{
  width:100%;
  min-height:44px;
  border-radius:16px;
  border:1px solid rgba(0,0,0,0.08);
  background:#f7f7f8;
  padding:11px 14px;
  box-sizing:border-box;
  font:inherit;
}
.crf-field textarea{
  min-height:180px;
  resize:vertical;
}
.crf-priority-wrap{
  margin-top:2px;
}
.crf-chip-row{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.crf-chip{
  border:none;
  border-radius:999px;
  min-height:34px;
  padding:0 16px;
  background:#f2f2f3;
  color:#7a7a81;
  cursor:pointer;
}
.crf-chip.is-active{
  background:#202124;
  color:#fff;
}
.crf-contact-card{
  padding:16px;
  border-radius:24px;
}
.crf-contact-card + .crf-contact-card{
  margin-top:14px;
}
.crf-contact-row{
  margin-top:10px;
}
.crf-contact-row label{
  display:block;
  margin-bottom:6px;
  font-size:12px;
  font-weight:700;
  color:#68686d;
}
.crf-contact-box{
  min-height:44px;
  border-radius:16px;
  background:#f7f7f8;
  border:1px solid rgba(0,0,0,0.04);
  padding:12px 14px;
  box-sizing:border-box;
  color:#5c5c62;
  font-size:14px;
}
.crf-alert{
  margin-bottom:14px;
  padding:14px 16px;
  border-radius:18px;
  background:#fff5f5;
  border:1px solid rgba(185,28,28,.14);
}
.crf-alert h4{
  margin:0 0 8px 0;
  font-size:16px;
}
.crf-alert ul{
  margin:0 0 0 18px;
  padding:0;
  line-height:1.7;
}
@media (max-width:980px){
  .crf-head{
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
            <div class="crf-alert">
              <h4>Please fix these fields</h4>
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?php echo esc($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="post" id="client-responsibility-form" autocomplete="off">
            <div class="crf-head">
              <div>
                <h1 class="crf-title">Contract &amp; scope &nbsp;›&nbsp; Client responsibilities &nbsp;›&nbsp; <?php echo $isEdit ? 'Edit' : 'Add'; ?></h1>
                <div class="crf-sub">What we need from the client to keep timelines on track.</div>
              </div>

              <div class="crf-actions">
                <button class="btn icon-btn" type="button" title="Download">⬇</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
                <a class="btn" href="<?php echo esc(base_url('projects/contract_client_responsibilities.php?id=' . $projectId)); ?>">Discard changes</a>
              </div>
            </div>

            <div class="crf-layout">
              <div class="card proj-card crf-card">
                <h3>Task information</h3>
                <p>Track signing progress and key parties.</p>

                <div class="crf-divider"></div>

                <div class="crf-grid-2">
                  <div class="crf-field">
                    <label for="title">Client responsibility</label>
                    <input id="title" name="title" type="text" value="<?php echo esc($title); ?>" placeholder="e.g. Share guest list (names + numbers)">
                  </div>

                  <div class="crf-field">
                    <label for="due_date">Due date</label>
                    <input id="due_date" name="due_date" type="date" value="<?php echo esc($dueDate); ?>">
                  </div>
                </div>

                <div class="crf-field">
                  <label for="description">Task description</label>
                  <textarea id="description" name="description" placeholder="Add a clear explanation of what is expected from the client and why it matters."><?php echo esc($description); ?></textarea>
                </div>

                <div class="crf-grid-2">
                  <div class="crf-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                      <option value="not_received" <?php echo selected_attr($status, 'not_received'); ?>>Not received</option>
                      <option value="received" <?php echo selected_attr($status, 'received'); ?>>Received</option>
                      <option value="partially_received" <?php echo selected_attr($status, 'partially_received'); ?>>Partially received</option>
                      <option value="not_applicable" <?php echo selected_attr($status, 'not_applicable'); ?>>Not applicable</option>
                    </select>
                  </div>

                  <div class="crf-field">
                    <label>Priority level</label>
                    <input type="hidden" name="priority" id="priority-input" value="<?php echo esc($priority); ?>">
                    <div class="crf-priority-wrap">
                      <div class="crf-chip-row">
                        <button class="crf-chip <?php echo $priority === 'low' ? 'is-active' : ''; ?>" data-priority="low" type="button">Low</button>
                        <button class="crf-chip <?php echo $priority === 'medium' ? 'is-active' : ''; ?>" data-priority="medium" type="button">Medium</button>
                        <button class="crf-chip <?php echo $priority === 'high' ? 'is-active' : ''; ?>" data-priority="high" type="button">High</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <div class="card proj-card crf-contact-card">
                  <h3>Client contact 1</h3>
                  <p>Track signing progress and key parties.</p>
                  <div class="crf-divider"></div>

                  <div class="crf-contact-row">
                    <label>Partner 1</label>
                    <div class="crf-contact-box"><?php echo esc($contact1Name !== '' ? $contact1Name : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Phone number 1</label>
                    <div class="crf-contact-box"><?php echo esc($contact1Phone !== '' ? $contact1Phone : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Email address</label>
                    <div class="crf-contact-box"><?php echo esc($contact1Email !== '' ? $contact1Email : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Address</label>
                    <div class="crf-contact-box"><?php echo esc($addressLabel); ?></div>
                  </div>
                </div>

                <div class="card proj-card crf-contact-card">
                  <h3>Client contact 2</h3>
                  <p>Track signing progress and key parties.</p>
                  <div class="crf-divider"></div>

                  <div class="crf-contact-row">
                    <label>Partner 2</label>
                    <div class="crf-contact-box"><?php echo esc($contact2Name !== '' ? $contact2Name : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Phone number 2</label>
                    <div class="crf-contact-box"><?php echo esc($contact2Phone !== '' ? $contact2Phone : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Email address</label>
                    <div class="crf-contact-box"><?php echo esc($contact2Email !== '' ? $contact2Email : '—'); ?></div>
                  </div>

                  <div class="crf-contact-row">
                    <label>Address</label>
                    <div class="crf-contact-box"><?php echo esc($addressLabel); ?></div>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
const priorityInput = document.getElementById('priority-input');
document.querySelectorAll('.crf-chip[data-priority]').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.crf-chip[data-priority]').forEach((b) => b.classList.remove('is-active'));
    btn.classList.add('is-active');
    if (priorityInput) priorityInput.value = btn.dataset.priority || 'medium';
  });
});
</script>

<?php require_once $root . '/includes/footer.php'; ?>