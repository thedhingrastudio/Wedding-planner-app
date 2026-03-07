<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
$auditFile = $root . '/includes/audit.php';
if (is_file($auditFile)) {
  require_once $auditFile;
}
require_login();

$pdo = $pdo ?? get_pdo();
$companyId = current_company_id();

if (!function_exists('h0')) {
  function h0($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('task_cat_label')) {
  function task_cat_label(string $cat): string {
    $map = [
      'follow_ups'  => 'Follow ups',
      'followups'   => 'Follow ups',
      'rsvp'        => 'Follow ups',
      'transport'   => 'Pick ups',
      'hospitality' => 'Hotel & hospitality',
      'vendors'     => 'Deliveries',
      'guest_list'  => 'Guest list',
      'general'     => 'Task',
    ];
    $cat = strtolower(trim((string)$cat));
    return $map[$cat] ?? ucfirst(str_replace('_', ' ', $cat));
  }
}

/* ---------- Safe local audit fallbacks ---------- */
if (!function_exists('audit_client_ip')) {
  function audit_client_ip(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 45) : 'unknown';
  }
}

if (!function_exists('audit_user_agent')) {
  function audit_user_agent(): string {
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
  }
}

if (!function_exists('audit_actor_user_id')) {
  function audit_actor_user_id(): ?int {
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('audit_actor_name')) {
  function audit_actor_name(): string {
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    return $name !== '' ? $name : 'Unknown member';
  }
}

if (!function_exists('audit_json_string')) {
  function audit_json_string($value): ?string {
    if ($value === null) return null;
    if (is_string($value)) return $value;
    try {
      $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      return $json === false ? null : $json;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('audit_build_search_text')) {
  function audit_build_search_text(array $parts): string {
    $out = [];
    foreach ($parts as $part) {
      if (is_array($part)) {
        foreach ($part as $sub) {
          $sub = trim((string)$sub);
          if ($sub !== '') $out[] = $sub;
        }
      } else {
        $part = trim((string)$part);
        if ($part !== '') $out[] = $part;
      }
    }
    return implode(' | ', $out);
  }
}

if (!function_exists('audit_log')) {
  function audit_log(array $payload): void {
    try {
      $pdo = ($payload['pdo'] ?? null);
      if (!$pdo instanceof PDO) return;

      $companyId = (int)($payload['company_id'] ?? 0);
      if ($companyId <= 0) return;

      $projectId = isset($payload['project_id']) && (int)$payload['project_id'] > 0
        ? (int)$payload['project_id']
        : null;

      $actorUserId = isset($payload['actor_user_id']) && (int)$payload['actor_user_id'] > 0
        ? (int)$payload['actor_user_id']
        : null;

      $targetCompanyMemberId = isset($payload['target_company_member_id']) && (int)$payload['target_company_member_id'] > 0
        ? (int)$payload['target_company_member_id']
        : null;

      $entityType = trim((string)($payload['entity_type'] ?? 'system'));
      $entityId = isset($payload['entity_id']) && (int)$payload['entity_id'] > 0
        ? (int)$payload['entity_id']
        : null;

      $action = trim((string)($payload['action'] ?? 'updated'));
      $summary = trim((string)($payload['summary'] ?? 'Updated record'));
      $actorName = trim((string)($payload['actor_name'] ?? audit_actor_name()));
      if ($actorName === '') $actorName = 'Unknown member';

      $diffJson = audit_json_string($payload['diff_json'] ?? null);
      $searchText = trim((string)($payload['search_text'] ?? ''));
      if ($searchText === '') {
        $searchText = audit_build_search_text([$actorName, $entityType, $action, $summary]);
      }

      $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
          company_id,
          project_id,
          actor_user_id,
          actor_name,
          target_company_member_id,
          entity_type,
          entity_id,
          action,
          summary,
          ip_address,
          user_agent,
          diff_json,
          search_text,
          created_at
        ) VALUES (
          :company_id,
          :project_id,
          :actor_user_id,
          :actor_name,
          :target_company_member_id,
          :entity_type,
          :entity_id,
          :action,
          :summary,
          :ip_address,
          :user_agent,
          :diff_json,
          :search_text,
          NOW()
        )
      ");

      $stmt->execute([
        ':company_id' => $companyId,
        ':project_id' => $projectId,
        ':actor_user_id' => $actorUserId,
        ':actor_name' => $actorName,
        ':target_company_member_id' => $targetCompanyMemberId,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':action' => $action,
        ':summary' => $summary,
        ':ip_address' => audit_client_ip(),
        ':user_agent' => audit_user_agent(),
        ':diff_json' => $diffJson,
        ':search_text' => $searchText,
      ]);
    } catch (Throwable $e) {
      // never break the edit flow if audit logging fails
    }
  }
}

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

/* ---------- Load task + project ---------- */
$task = null;
try {
  $st = $pdo->prepare("
    SELECT
      t.*,
      p.title AS project_title,
      p.id AS project_id,
      COALESCE(cm.full_name, '') AS current_assignee_name
    FROM tasks t
    JOIN projects p
      ON p.id = t.project_id
     AND p.company_id = t.company_id
    LEFT JOIN company_members cm
      ON cm.id = t.assigned_to_company_member_id
     AND cm.company_id = t.company_id
    WHERE t.id = :tid
      AND t.company_id = :cid
    LIMIT 1
  ");
  $st->execute([
    ':tid' => $taskId,
    ':cid' => $companyId,
  ]);
  $task = $st->fetch() ?: null;
} catch (Throwable $e) {
  $task = null;
}

if (!$task) redirect('projects/index.php');

$projectId = (int)($task['project_id'] ?? 0);
$projectTitle = (string)($task['project_title'] ?? 'Project');

/* ---------- Needed by project sidebar ---------- */
$daysToGo = null;
try {
  $evt = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC
    LIMIT 1
  ");
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
    WHERE project_id = :pid
      AND company_member_id IS NOT NULL
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {}

/* ---------- Project members for dropdown ---------- */
$memberOptions = [];
$memberNameMap = [];
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
  $m->execute([
    ':cid' => $companyId,
    ':pid' => $projectId,
  ]);
  $memberOptions = $m->fetchAll() ?: [];
  foreach ($memberOptions as $opt) {
    $memberNameMap[(int)$opt['id']] = (string)$opt['full_name'];
  }
} catch (Throwable $e) {
  $memberOptions = [];
}

$categoryOptions = ['follow_ups', 'guest_list', 'rsvp', 'transport', 'hospitality', 'vendors', 'general'];
$priorityOptions = ['low', 'medium', 'high'];

/* ---------- Form defaults ---------- */
$val_assignee = (string)($task['assigned_to_company_member_id'] ?? '');
$val_category = (string)($task['category'] ?? 'general');
$val_title = (string)($task['title'] ?? '');
$val_desc = (string)($task['description'] ?? '');
$val_assigned = !empty($task['assigned_on']) ? substr((string)$task['assigned_on'], 0, 10) : '';
$val_due = !empty($task['due_on']) ? substr((string)$task['due_on'], 0, 10) : '';
$val_priority = (string)($task['priority'] ?? 'medium');
$errors = [];

/* ---------- Before snapshot for audit ---------- */
$beforeAssigneeId = (int)($task['assigned_to_company_member_id'] ?? 0);
$beforeAssigneeName = trim((string)($task['current_assignee_name'] ?? ''));
if ($beforeAssigneeName === '' && $beforeAssigneeId > 0 && isset($memberNameMap[$beforeAssigneeId])) {
  $beforeAssigneeName = $memberNameMap[$beforeAssigneeId];
}

$beforeAudit = [
  'assignee_id' => $beforeAssigneeId > 0 ? $beforeAssigneeId : null,
  'assignee_name' => $beforeAssigneeName,
  'category' => (string)($task['category'] ?? ''),
  'title' => (string)($task['title'] ?? ''),
  'description' => (string)($task['description'] ?? ''),
  'assigned_on' => !empty($task['assigned_on']) ? substr((string)$task['assigned_on'], 0, 10) : '',
  'due_on' => !empty($task['due_on']) ? substr((string)$task['due_on'], 0, 10) : '',
  'priority' => (string)($task['priority'] ?? ''),
];

/* ---------- Handle POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $val_assignee = trim((string)($_POST['assignee'] ?? ''));
  $val_category = trim((string)($_POST['category'] ?? 'general'));
  $val_title = trim((string)($_POST['title'] ?? ''));
  $val_desc = trim((string)($_POST['description'] ?? ''));
  $val_assigned = trim((string)($_POST['assigned_on'] ?? ''));
  $val_due = trim((string)($_POST['due_on'] ?? ''));
  $val_priority = strtolower(trim((string)($_POST['priority'] ?? 'medium')));

  if ($val_title === '') $errors[] = 'Task title is required.';
  if ($val_assignee === '' || (int)$val_assignee <= 0) $errors[] = 'Please select a team member.';
  if (!in_array($val_category, $categoryOptions, true)) $val_category = 'general';
  if (!in_array($val_priority, $priorityOptions, true)) $val_priority = 'medium';

  $assignedDb = ($val_assigned !== '') ? substr($val_assigned, 0, 10) : null;
  $dueDb = ($val_due !== '') ? substr($val_due, 0, 10) : null;

  if (!$errors) {
    try {
      $up = $pdo->prepare("
        UPDATE tasks
        SET
          assigned_to_company_member_id = :assignee,
          category = :category,
          title = :title,
          description = :description,
          assigned_on = :assigned_on,
          due_on = :due_on,
          priority = :priority
        WHERE id = :tid
          AND company_id = :cid
      ");
      $up->execute([
        ':assignee' => (int)$val_assignee,
        ':category' => $val_category,
        ':title' => $val_title,
        ':description' => $val_desc,
        ':assigned_on' => $assignedDb,
        ':due_on' => $dueDb,
        ':priority' => $val_priority,
        ':tid' => $taskId,
        ':cid' => $companyId,
      ]);

      $afterAssigneeId = (int)$val_assignee;
      $afterAssigneeName = $memberNameMap[$afterAssigneeId] ?? '';

      $afterAudit = [
        'assignee_id' => $afterAssigneeId > 0 ? $afterAssigneeId : null,
        'assignee_name' => $afterAssigneeName,
        'category' => $val_category,
        'title' => $val_title,
        'description' => $val_desc,
        'assigned_on' => $assignedDb ?? '',
        'due_on' => $dueDb ?? '',
        'priority' => $val_priority,
      ];

      audit_log([
        'pdo' => $pdo,
        'company_id' => $companyId,
        'project_id' => $projectId,
        'actor_user_id' => audit_actor_user_id(),
        'actor_name' => audit_actor_name(),
        'target_company_member_id' => $afterAssigneeId > 0 ? $afterAssigneeId : null,
        'entity_type' => 'task',
        'entity_id' => $taskId,
        'action' => 'updated',
        'summary' => 'Updated task: ' . $val_title,
        'diff_json' => [
          'before' => $beforeAudit,
          'after' => $afterAudit,
        ],
        'search_text' => audit_build_search_text([
          'task',
          'updated',
          $val_title,
          $val_category,
          $val_priority,
          $afterAssigneeName,
          $assignedDb ?? '',
          $dueDb ?? '',
          $val_desc,
        ]),
      ]);

      redirect('tasks/show.php?id=' . $taskId);
    } catch (Throwable $e) {
      $errors[] = 'Could not update task. ' . $e->getMessage();
    }
  }
}

$pageTitle = $projectTitle . ' — Edit task — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.task-shell{display:grid;grid-template-columns:280px 1fr;gap:16px;}
@media (max-width:1100px){.task-shell{grid-template-columns:1fr;}}
.task-main{min-width:0;}
.task-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;}
.task-breadcrumb{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;color:rgba(0,0,0,.55);}
.task-breadcrumb a{text-decoration:none;color:rgba(0,0,0,.55);}
.task-breadcrumb .sep{opacity:.55;}
.task-sub{margin-top:6px;color:var(--muted);font-size:13px;}
.task-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.task-card{padding:18px;border-radius:24px;background:#fff;border:1px solid rgba(0,0,0,.06);}
.task-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media (max-width:900px){.task-grid{grid-template-columns:1fr;}}
.field{display:flex;flex-direction:column;gap:6px;}
.field label{font-size:12px;color:var(--muted);}
.input-soft,.select-soft,.textarea-soft{
  width:100%;padding:12px 14px;border-radius:18px;border:1px solid transparent;
  background:rgba(0,0,0,.04);font-size:14px;outline:none;
}
.select-soft{border-radius:999px;}
.textarea-soft{min-height:120px;resize:vertical;}
.input-soft:focus,.select-soft:focus,.textarea-soft:focus{background:#fff;border-color:rgba(0,0,0,.14);}
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;}
@media (max-width:900px){.meta-grid{grid-template-columns:1fr;}}
.muted-box{padding:12px 14px;border-radius:18px;background:rgba(0,0,0,.03);border:1px solid rgba(0,0,0,.05);font-size:14px;}
.error-list{margin:0 0 14px 0;padding:12px 14px 12px 32px;border-radius:18px;background:rgba(255,59,48,.08);border:1px solid rgba(255,59,48,.2);color:#7a1d14;}
.footer-actions{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:16px;}
.danger-form{margin:0;}
</style>

<div class="app-shell">
  <?php $nav_active = 'projects'; require_once $root . '/includes/sidebar.php'; ?>

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
          <div class="proj-icon"></div>
          <div>
            <div class="proj-name"><?php echo h0($projectTitle); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">Task editor</span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h0((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">Cancel</a>
        </div>
      </div>

      <div class="task-shell">
        <?php
          $active = 'overview';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="task-main">
          <div class="task-head">
            <div>
              <div class="task-breadcrumb">
                <a href="<?php echo h0(base_url('projects/show.php?id=' . $projectId)); ?>">Project overview</a>
                <span class="sep">›</span>
                <a href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">Task details</a>
                <span class="sep">›</span>
                <span>Edit task</span>
              </div>
              <div class="task-sub">Edit details for assigned tasks.</div>
            </div>

            <div class="task-actions">
              <a class="btn" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">Cancel</a>
            </div>
          </div>

          <?php if ($errors): ?>
            <ul class="error-list">
              <?php foreach ($errors as $err): ?>
                <li><?php echo h0($err); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <form method="post" action="" class="task-card">
            <div class="task-grid">
              <div class="field">
                <label for="assignee">Select team member to assign task to</label>
                <select id="assignee" name="assignee" class="select-soft" required>
                  <option value="">Select</option>
                  <?php foreach ($memberOptions as $member): ?>
                    <?php $mid = (int)$member['id']; ?>
                    <option value="<?php echo h0((string)$mid); ?>" <?php echo ((string)$mid === (string)$val_assignee) ? 'selected' : ''; ?>>
                      <?php echo h0((string)$member['full_name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field">
                <label for="category">Task category</label>
                <select id="category" name="category" class="select-soft">
                  <?php foreach ($categoryOptions as $opt): ?>
                    <option value="<?php echo h0($opt); ?>" <?php echo ($opt === $val_category) ? 'selected' : ''; ?>>
                      <?php echo h0(task_cat_label($opt)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" style="grid-column:1 / -1;">
                <label for="title">Task title</label>
                <input id="title" name="title" class="input-soft" type="text" value="<?php echo h0($val_title); ?>" required>
              </div>

              <div class="field">
                <label for="assigned_on">Assigned on</label>
                <input id="assigned_on" name="assigned_on" class="input-soft" type="date" value="<?php echo h0($val_assigned); ?>">
              </div>

              <div class="field">
                <label for="due_on">Due on</label>
                <input id="due_on" name="due_on" class="input-soft" type="date" value="<?php echo h0($val_due); ?>">
              </div>

              <div class="field" style="grid-column:1 / -1;">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="textarea-soft"><?php echo h0($val_desc); ?></textarea>
              </div>
            </div>

            <div class="meta-grid">
              <div class="field">
                <label>Assigned by</label>
                <div class="muted-box"><?php echo h0($adminName); ?></div>
              </div>
              <div class="field">
                <label>Project</label>
                <div class="muted-box"><?php echo h0($projectTitle); ?></div>
              </div>
              <div class="field">
                <label for="priority">Priority</label>
                <select id="priority" name="priority" class="select-soft">
                  <?php foreach ($priorityOptions as $opt): ?>
                    <option value="<?php echo h0($opt); ?>" <?php echo ($opt === $val_priority) ? 'selected' : ''; ?>>
                      <?php echo h0(ucfirst($opt)); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="footer-actions">
  <button
    type="button"
    class="btn"
    onclick="if(confirm('Are you sure you want to delete this task?')){ window.location.href='<?php echo h0(base_url('tasks/show.php?id=' . $taskId . '&confirm_delete=1')); ?>'; }"
  >
    Delete Task
  </button>

  <div class="task-actions">
    <a class="btn" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">Cancel</a>
    <button type="submit" class="btn btn-primary">Update task</button>
  </div>
</div>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>