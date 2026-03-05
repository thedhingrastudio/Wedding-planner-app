<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

function h0($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function role_label(string $role): string {
  $map = [
    'team_lead'    => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp'         => 'RSVP',
    'hospitality'  => 'Hospitality',
    'transport'    => 'Transport',
    'vendor'       => 'Vendor',
    'vendors'      => 'Vendor',
  ];
  $role = trim((string)$role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

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

function task_cat_icon(string $cat): string {
  $map = [
    'follow_ups' => '✉️',
    'followups'  => '✉️',
    'rsvp'       => '✉️',
    'transport'  => '🚗',
    'hospitality'=> '🏨',
    'vendors'    => '📦',
    'guest_list' => '🧾',
    'general'    => '☑️',
  ];
  $cat = strtolower(trim((string)$cat));
  return $map[$cat] ?? '☑️';
}

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) redirect('projects/index.php');

$companyId = current_company_id();

// Load task + project (secure)
$task = null;
try {
  $st = $pdo->prepare("
    SELECT
      t.*,
      p.title AS project_title,
      p.id    AS project_id,
      COALESCE(cm.full_name, 'Unassigned') AS assignee_name
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
  $st->execute([':tid' => $taskId, ':cid' => $companyId]);
  $task = $st->fetch() ?: null;
} catch (Throwable $e) {
  $task = null;
}

if (!$task) redirect('projects/index.php');

$projectId = (int)($task['project_id'] ?? 0);
$projectTitle = (string)($task['project_title'] ?? 'Project');

$pageTitle = $projectTitle . ' — Task — Vidhaan';
require_once $root . '/includes/header.php';

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

// ------------------------------------------------------------
// Fix the warning: project_sidebar.php expects $daysToGo
// ------------------------------------------------------------
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

// teamCount (some sidebars use this)
$teamCount = 0;
try {
  $tc = $pdo->prepare("
    SELECT COUNT(DISTINCT
      CASE
        WHEN company_member_id IS NOT NULL THEN CONCAT('cm:', company_member_id)
        WHEN user_id IS NOT NULL THEN CONCAT('u:', user_id)
        WHEN email IS NOT NULL THEN CONCAT('e:', email)
        ELSE CONCAT('row:', id)
      END
    ) AS c
    FROM project_members
    WHERE project_id = :pid
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) { $teamCount = 0; }

// ------------------------------------------------------------
// KPI counts for tabs (Members / Open / Due soon / Overdue)
// ------------------------------------------------------------
$tasksTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
}

$membersCount = 0;
try {
  $mc = $pdo->prepare("
    SELECT COUNT(DISTINCT company_member_id)
    FROM project_members
    WHERE project_id = :pid
      AND company_member_id IS NOT NULL
  ");
  $mc->execute([':pid' => $projectId]);
  $membersCount = (int)($mc->fetchColumn() ?: 0);
} catch (Throwable $e) { $membersCount = 0; }

$openTasks = $dueSoon = $overdue = 0;
if ($tasksTableExists) {
  try {
    $cs = $pdo->prepare("
      SELECT
        SUM(CASE WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done') THEN 1 ELSE 0 END) AS open_tasks,
        SUM(CASE WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
                   AND due_on IS NOT NULL
                   AND due_on < CURDATE() THEN 1 ELSE 0 END) AS overdue_tasks,
        SUM(CASE WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
                   AND due_on IS NOT NULL
                   AND due_on >= CURDATE()
                   AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_soon_tasks
      FROM tasks
      WHERE company_id = :cid
        AND project_id = :pid
    ");
    $cs->execute([':cid' => $companyId, ':pid' => $projectId]);
    $row = $cs->fetch() ?: [];
    $openTasks = (int)($row['open_tasks'] ?? 0);
    $overdue   = (int)($row['overdue_tasks'] ?? 0);
    $dueSoon   = (int)($row['due_soon_tasks'] ?? 0);
  } catch (Throwable $e) {}
}

// ------------------------------------------------------------
// Team list (left card): members + roles + task counts
// ------------------------------------------------------------
$members = [];
try {
  $mstmt = $pdo->prepare("
    SELECT
      pm.company_member_id,
      cm.full_name,
      GROUP_CONCAT(DISTINCT pm.role ORDER BY pm.role SEPARATOR ',') AS roles
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid_cm
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
      AND (cm.status = 'active' OR cm.status IS NULL)
    GROUP BY pm.company_member_id, cm.full_name
    ORDER BY cm.full_name ASC
  ");
  $mstmt->execute([':pid' => $projectId, ':cid_cm' => $companyId]);
  $members = $mstmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $members = [];
}

$countsByMember = []; // open + due(soon)
if ($tasksTableExists) {
  try {
    $ct = $pdo->prepare("
      SELECT
        assigned_to_company_member_id AS cmid,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND (due_on IS NULL OR due_on >= CURDATE())
            THEN 1 ELSE 0
          END
        ) AS open_cnt,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND due_on IS NOT NULL
              AND due_on >= CURDATE()
              AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            THEN 1 ELSE 0
          END
        ) AS due_cnt
      FROM tasks
      WHERE company_id = :cid
        AND project_id = :pid
        AND assigned_to_company_member_id IS NOT NULL
      GROUP BY assigned_to_company_member_id
    ");
    $ct->execute([':cid' => $companyId, ':pid' => $projectId]);
    foreach (($ct->fetchAll() ?: []) as $r) {
      $cmid = (int)($r['cmid'] ?? 0);
      $countsByMember[$cmid] = [
        'open' => (int)($r['open_cnt'] ?? 0),
        'due'  => (int)($r['due_cnt'] ?? 0),
      ];
    }
  } catch (Throwable $e) {}
}

// Assignee highlight + role pill
$assigneeId = (int)($task['assigned_to_company_member_id'] ?? 0);
$assigneeName = (string)($task['assignee_name'] ?? 'Unassigned');

$assigneeRolePill = '';
try {
  if ($assigneeId > 0) {
    $rs = $pdo->prepare("
      SELECT DISTINCT role
      FROM project_members
      WHERE project_id = :pid
        AND company_member_id = :mid
      ORDER BY role ASC
      LIMIT 1
    ");
    $rs->execute([':pid' => $projectId, ':mid' => $assigneeId]);
    $assigneeRole = (string)($rs->fetchColumn() ?: '');
    if ($assigneeRole !== '') $assigneeRolePill = role_label($assigneeRole);
  }
} catch (Throwable $e) {}

// ------------------------------------------------------------
// Task fields for the form-like view
// ------------------------------------------------------------
$title = trim((string)($task['title'] ?? 'Untitled task'));
$category = strtolower(trim((string)($task['category'] ?? 'general')));
$priority = strtolower(trim((string)($task['priority'] ?? '')));
$status   = strtolower(trim((string)($task['status'] ?? 'pending')));
$desc     = trim((string)($task['description'] ?? ''));

$assignedOn = (string)($task['assigned_on'] ?? '');
$assignedLabel = $assignedOn ? date('d/m/Y', strtotime($assignedOn)) : '—';

$dueOn = (string)($task['due_on'] ?? '');
$dueLabel = $dueOn ? date('d/m/Y', strtotime($dueOn)) : '—';

$progress = $status;
if (in_array($progress, ['done'], true)) $progress = 'completed';
if (!in_array($progress, ['pending','in_progress','completed','on_hold'], true)) $progress = 'pending';

$prioLabel = $priority ? ucfirst($priority) : '—';
$catLabel  = task_cat_label($category);
$catIcon   = task_cat_icon($category);
?>

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
              <span class="proj-meta-item">Task #<?php echo h0((string)$taskId); ?></span>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">← Back to tasks</a>
          <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <!-- KPI tabs -->
          <div class="team-stats" style="margin-top:6px;">
            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/members.php?id=' . $projectId)); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">👥</div><div class="team-stat-label">Members</div></div>
              <div class="team-stat-num"><?php echo h0((string)$membersCount); ?></div>
            </a>

            <a class="team-stat team-stat--link team-stat--active" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">☑️</div><div class="team-stat-label">Open tasks</div></div>
              <div class="team-stat-num"><?php echo h0((string)$openTasks); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=due_soon')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">📅</div><div class="team-stat-label">Due soon</div></div>
              <div class="team-stat-num"><?php echo h0((string)$dueSoon); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=overdue')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">⚠️</div><div class="team-stat-label">Overdue tasks</div></div>
              <div class="team-stat-num"><?php echo h0((string)$overdue); ?></div>
            </a>
          </div>

          <div class="task-page-grid">

            <!-- LEFT: team list -->
            <div class="card task-team-card">
              <div class="task-team-head">
                <div class="proj-card-title">The team</div>
                <div class="proj-card-sub"><?php echo h0((string)$teamCount); ?> members</div>
              </div>

              <div class="task-team-scroll">
                <?php foreach ($members as $m): ?>
                  <?php
                    $cmid = (int)($m['company_member_id'] ?? 0);
                    $name = (string)($m['full_name'] ?? 'Member');
                    $rolesCsv = (string)($m['roles'] ?? '');
                    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
                    $openCnt = (int)(($countsByMember[$cmid]['open'] ?? 0));
                    $dueCnt  = (int)(($countsByMember[$cmid]['due'] ?? 0));

                    $isActive = ($assigneeId > 0 && $cmid === $assigneeId);
                    $href = base_url('projects/member.php?id=' . $projectId . '&mid=' . $cmid);
                  ?>
                  <a class="task-team-row <?php echo $isActive ? 'task-team-row--active' : ''; ?>" href="<?php echo h0($href); ?>">
                    <div class="task-team-name"><?php echo h0($name); ?></div>
                    <div class="task-team-role"><?php echo h0($roles ? role_label($roles[0]) : ''); ?></div>

                    <div class="task-team-tags">
                      <?php foreach (array_slice($roles, 0, 2) as $r): ?>
                        <span class="tag"><?php echo h0(role_label($r)); ?></span>
                      <?php endforeach; ?>
                      <?php if (count($roles) > 2): ?>
                        <span class="tag">+<?php echo h0((string)(count($roles) - 2)); ?> more</span>
                      <?php endif; ?>
                    </div>

                    <div class="task-team-mini">
                      <span><?php echo h0((string)$openCnt); ?> open</span>
                      <span class="dot">•</span>
                      <span><?php echo h0((string)$dueCnt); ?> due</span>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- RIGHT: task details (form-like) -->
            <div class="card task-detail-card">
              <div class="task-detail-top">
                <div>
                  <div class="task-title">
                    <?php echo h0($catLabel . ': ' . $title); ?>
                  </div>
                  <div class="task-sub">
                    Assigned to: <strong><?php echo h0($assigneeName); ?></strong>
                  </div>
                </div>

                <div class="task-badges">
                  <?php if ($assigneeRolePill): ?>
                    <span class="pill"><?php echo h0($assigneeRolePill); ?></span>
                  <?php endif; ?>
                  <span class="pill"><?php echo h0($catLabel); ?></span>
                  <span class="pill"><?php echo h0($prioLabel); ?></span>
                </div>
              </div>

              <div class="task-form">
                <div class="task-form-row">
                  <div class="task-field">
                    <label>Assigned on</label>
                    <div class="task-input"><?php echo h0($assignedLabel); ?></div>
                  </div>
                  <div class="task-field">
                    <label>Due on</label>
                    <div class="task-input"><?php echo h0($dueLabel); ?></div>
                  </div>
                </div>

                <div class="task-field">
                  <label>Description</label>
                  <div class="task-textarea"><?php echo nl2br(h0($desc ?: '—')); ?></div>
                </div>

                <div class="task-form-row">
                  <div class="task-field">
                    <label>Assigned by</label>
                    <div class="task-input"><?php echo h0($adminName); ?></div>
                  </div>
                  <div class="task-field">
                    <label>Project</label>
                    <div class="task-input"><?php echo h0($projectTitle); ?></div>
                  </div>
                </div>

                <div class="task-bottom">
                  <div class="task-progress">
                    <div class="task-progress-label">Task progress</div>
                    <div class="task-progress-pills">
                      <span class="seg <?php echo $progress==='pending'?'seg--active':''; ?>">Pending</span>
                      <span class="seg <?php echo $progress==='in_progress'?'seg--active':''; ?>">In Progress</span>
                      <span class="seg <?php echo $progress==='completed'?'seg--active':''; ?>">Completed</span>
                      <span class="seg <?php echo $progress==='on_hold'?'seg--active':''; ?>">On Hold</span>
                    </div>
                  </div>

                  <div class="task-priority">
                    <div class="task-progress-label">Priority</div>
                    <span class="prio-pill"><?php echo h0($prioLabel); ?></span>
                  </div>
                </div>

                <div class="task-actions">
                  <a class="btn btn-danger-outline" href="<?php echo h0(base_url('tasks/delete.php?id=' . $taskId)); ?>">🗑 Delete Task</a>
                  <a class="btn" href="<?php echo h0(base_url('tasks/edit.php?id=' . $taskId)); ?>">✎ Edit task</a>
                </div>
              </div>
            </div>

          </div><!-- /task-page-grid -->

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>