<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();
$companyId = current_company_id();

function h0($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function task_cat_label(string $cat): string {
  $map = [
    'follow_ups' => 'Follow ups',
    'followups'  => 'Follow ups',
    'rsvp'       => 'Follow ups',
    'transport'  => 'Pick ups',
    'hospitality'=> 'Hotel & hospitality',
    'vendors'    => 'Deliveries',
    'guest_list' => 'Guest list',
    'general'    => 'Task',
  ];
  $cat = strtolower(trim((string)$cat));
  return $map[$cat] ?? ucfirst(str_replace('_', ' ', $cat));
}

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

/** Load task + project (secure) */
$task = null;
try {
  $st = $pdo->prepare("
    SELECT
      t.*,
      p.title AS project_title,
      p.id    AS project_id
    FROM tasks t
    JOIN projects p
      ON p.id = t.project_id
     AND p.company_id = t.company_id
    WHERE t.id = :tid
      AND t.company_id = :cid
    LIMIT 1
  ");
  $st->execute([':tid' => $taskId, ':cid' => $companyId]);
  $task = $st->fetch() ?: null;
} catch (Throwable $e) {
  $task = null;
}

if (!$task) redirect('projects/index.php');

$projectId = (int)($task['project_id'] ?? 0);
$projectTitle = (string)($task['project_title'] ?? 'Project');

/** Needed by includes/project_sidebar.php */
$daysToGo = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
  if ($first && !empty($first['starts_at'])) {
    $d1 = new DateTimeImmutable(date('Y-m-d'));
    $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
    $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  }
} catch (Throwable $e) {}

$teamCount = 0;
try {
  $tc = $pdo->prepare("
    SELECT COUNT(DISTINCT company_member_id)
    FROM project_members
    WHERE project_id = :pid AND company_member_id IS NOT NULL
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {}

/** Dropdown options: project members */
$memberOptions = [];
try {
  $m = $pdo->prepare("
    SELECT DISTINCT cm.id, cm.full_name
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
      AND (cm.status = 'active' OR cm.status IS NULL)
    ORDER BY cm.full_name ASC
  ");
  $m->execute([':cid' => $companyId, ':pid' => $projectId]);
  $memberOptions = $m->fetchAll() ?: [];
} catch (Throwable $e) { $memberOptions = []; }

/** Categories + priorities (safe list) */
$categoryOptions = [
  'follow_ups',
  'guest_list',
  'rsvp',
  'transport',
  'hospitality',
  'vendors',
  'general',
];

$priorityOptions = ['low', 'medium', 'high'];

/** Form values (defaults from DB) */
$val_assignee = (string)($task['assigned_to_company_member_id'] ?? '');
$val_category = (string)($task['category'] ?? 'general');
$val_title    = (string)($task['title'] ?? '');
$val_desc     = (string)($task['description'] ?? '');
$val_assigned = (string)($task['assigned_on'] ?? '');
$val_due      = (string)($task['due_on'] ?? '');
$val_priority = (string)($task['priority'] ?? 'medium');

$errors = [];

/** Handle POST update */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $val_assignee = trim((string)($_POST['assignee'] ?? ''));
  $val_category = trim((string)($_POST['category'] ?? 'general'));
  $val_title    = trim((string)($_POST['title'] ?? ''));
  $val_desc     = trim((string)($_POST['description'] ?? ''));
  $val_assigned = trim((string)($_POST['assigned_on'] ?? ''));
  $val_due      = trim((string)($_POST['due_on'] ?? ''));
  $val_priority = strtolower(trim((string)($_POST['priority'] ?? 'medium')));

  if ($val_title === '') $errors[] = "Task title is required.";
  if ($val_assignee === '' || (int)$val_assignee <= 0) $errors[] = "Please select a team member.";
  if (!in_array($val_category, $categoryOptions, true)) $val_category = 'general';
  if (!in_array($val_priority, $priorityOptions, true)) $val_priority = 'medium';

  // Normalize date inputs (accept YYYY-MM-DD or empty)
  $assignedDb = ($val_assigned !== '') ? substr($val_assigned, 0, 10) : null;
  $dueDb      = ($val_due !== '') ? substr($val_due, 0, 10) : null;

  if (!$errors) {
    try {
      $up = $pdo->prepare("
        UPDATE tasks
        SET
          assigned_to_company_member_id = :assignee,
          category    = :category,
          title       = :title,
          description = :description,
          assigned_on = :assigned_on,
          due_on      = :due_on,
          priority    = :priority
        WHERE id = :tid
          AND company_id = :cid
      ");
      $up->execute([
        ':assignee'    => (int)$val_assignee,
        ':category'    => $val_category,
        ':title'       => $val_title,
        ':description' => $val_desc,
        ':assigned_on' => $assignedDb,
        ':due_on'      => $dueDb,
        ':priority'    => $val_priority,
        ':tid'         => $taskId,
        ':cid'         => $companyId,
      ]);

      redirect('tasks/show.php?id=' . $taskId);
    } catch (Throwable $e) {
      $errors[] = "Could not update task. " . $e->getMessage();
    }
  }
}

$pageTitle = $projectTitle . ' — Edit task — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
/* Small layout helpers for edit page */
.edit-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
  margin-bottom:14px;
}
.edit-title{ font-size:22px; font-weight:800; }
.edit-sub{ margin-top:4px; color: var(--muted); }
.edit-card{ padding:16px; }

.form-grid-2{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:12px;
}
@media (max-width: 900px){
  .form-grid-2{ grid-template-columns: 1fr; }
}
.form-field label{
  display:block;
  font-size:12px;
  color: var(--muted);
  margin-bottom:6px;
}
.form-field input, .form-field select, .form-field textarea{
  width:100%;
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px 14px;
  background:#fff;
  font-size:13px;
}
.form-field textarea{
  background:#fff;
  min-height: 110px;
  line-height:1.4;
  resize: vertical;
}
.readonly{
  background:#f6f6f6 !important;
}
.form-actions{
  margin-top:16px;
  display:flex;
  justify-content:flex-end;
  gap:10px;
  flex-wrap:wrap;
}
.btn-danger-outline{
  border:1px solid #ff4b4b !important;
  color:#ff4b4b !important;
  background:transparent !important;
}
.btn-danger-outline:hover{ background: rgba(255,75,75,0.08) !important; }

.error-box{
  border:1px solid #f2c3c3;
  background:#fff5f5;
  color:#8a0014;
  padding:12px 14px;
  border-radius:14px;
  margin-bottom:12px;
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
        Admin: <?php echo h0($adminName); ?>
        <a class="logout" href="<?php echo h0(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo h0($projectTitle); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">Edit task</span>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">Cancel</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="edit-head">
            <div>
              <div class="edit-title">Edit task</div>
              <div class="edit-sub">Edit details for assigned tasks</div>
            </div>
          </div>

          <div class="card edit-card">

            <?php if ($errors): ?>
              <div class="error-box">
                <strong>Fix these:</strong>
                <ul style="margin:8px 0 0 18px;">
                  <?php foreach ($errors as $e): ?>
                    <li><?php echo h0($e); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <form method="post">

              <div class="form-grid-2">
                <div class="form-field">
                  <label>Project name</label>
                  <input class="readonly" value="<?php echo h0($projectTitle); ?>" readonly>
                </div>

                <div class="form-field">
                  <label>Select team member to assign task to</label>
                  <select name="assignee" required>
                    <option value="">Select</option>
                    <?php foreach ($memberOptions as $m): ?>
                      <?php $mid = (int)$m['id']; ?>
                      <option value="<?php echo h0((string)$mid); ?>" <?php echo ((int)$val_assignee === $mid) ? 'selected' : ''; ?>>
                        <?php echo h0((string)$m['full_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-grid-2" style="margin-top:12px;">
                <div class="form-field">
                  <label>Task category</label>
                  <select name="category">
                    <?php foreach ($categoryOptions as $c): ?>
                      <option value="<?php echo h0($c); ?>" <?php echo ($val_category === $c) ? 'selected' : ''; ?>>
                        <?php echo h0(task_cat_label($c)); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="form-field">
                  <label>Task title</label>
                  <input name="title" value="<?php echo h0($val_title); ?>" required>
                </div>
              </div>

              <div class="form-grid-2" style="margin-top:12px;">
                <div class="form-field">
                  <label>Assigned on</label>
                  <input type="date" name="assigned_on" value="<?php echo h0($val_assigned ? substr($val_assigned,0,10) : ''); ?>">
                </div>

                <div class="form-field">
                  <label>Due on</label>
                  <input type="date" name="due_on" value="<?php echo h0($val_due ? substr($val_due,0,10) : ''); ?>">
                </div>
              </div>

              <div class="form-field" style="margin-top:12px;">
                <label>Description</label>
                <textarea name="description"><?php echo h0($val_desc); ?></textarea>
              </div>

              <div class="form-grid-2" style="margin-top:12px;">
                <div class="form-field">
                  <label>Assigned by</label>
                  <input class="readonly" value="<?php echo h0($adminName); ?>" readonly>
                </div>

                <div class="form-field">
                  <label>Project</label>
                  <input class="readonly" value="<?php echo h0($projectTitle); ?>" readonly>
                </div>
              </div>

              <div class="form-field" style="margin-top:12px; max-width: 240px;">
                <label>Priority</label>
                <select name="priority" class="prio-select">
                  <?php foreach ($priorityOptions as $p): ?>
                    <option value="<?php echo h0($p); ?>" <?php echo (strtolower($val_priority) === $p) ? 'selected' : ''; ?>>
                      <?php echo h0(ucfirst($p)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-actions">
                <form method="post"
      action="<?php echo h0(base_url('tasks/delete.php?id=' . $taskId)); ?>"
      data-confirm-delete-form
      style="display:inline;">
  <button class="btn btn-danger-outline" type="submit">🗑 Delete Task</button>
</form>
                <button class="btn btn-primary" type="submit">Update task</button>
              </div>

            </form>
          </div>

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>