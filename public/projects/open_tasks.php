<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$companyId = current_company_id();

// Project security
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = $project['title'] . ' — Tasks — Vidhaan';
require_once $root . '/includes/header.php';

// countdown
$first = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
} catch (Throwable $e) {}

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$createdAt = (string)($project['created_at'] ?? '');
$projectDateLabel = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Date TBD';

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
  $cat = strtolower(trim($cat));
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
  $cat = strtolower(trim($cat));
  return $map[$cat] ?? '☑️';
}

/** view tabs */
$view = strtolower(trim((string)($_GET['view'] ?? 'open')));
if (!in_array($view, ['open', 'due_soon', 'overdue'], true)) $view = 'open';

$pageH2 = ($view === 'due_soon') ? 'Due soon' : (($view === 'overdue') ? 'Overdue tasks' : 'Open tasks');
$pageSub = ($view === 'due_soon')
  ? 'Open tasks due in the next 7 days.'
  : (($view === 'overdue') ? 'Open tasks past their due date.' : 'All open tasks assigned in this project (excluding overdue).');

// ---------- module checks ----------
$tasksTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
}

// counts for KPI row
$openTasks = 0;
$dueSoon   = 0;
$overdue   = 0;

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
      WHERE company_id = :cid_t AND project_id = :pid_t
    ");
    $cs->execute([':cid_t' => $companyId, ':pid_t' => $projectId]);
    $row = $cs->fetch() ?: [];
    $openTasks = (int)($row['open_tasks'] ?? 0);
    $overdue   = (int)($row['overdue_tasks'] ?? 0);
    $dueSoon   = (int)($row['due_soon_tasks'] ?? 0);
  } catch (Throwable $e) {}
}

// members count for KPI row
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

// ---------- filters ----------
$assignee = trim((string)($_GET['assignee'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$priority = trim((string)($_GET['priority'] ?? ''));

// dropdown options
$memberOptions = [];
$categoryOptions = [];
$priorityOptions = [];

if ($tasksTableExists) {
  try {
    $m = $pdo->prepare("
      SELECT DISTINCT cm.id, cm.full_name
      FROM project_members pm
      JOIN company_members cm ON cm.id = pm.company_member_id AND cm.company_id = :cid_cm
      WHERE pm.project_id = :pid
        AND pm.company_member_id IS NOT NULL
        AND (cm.status = 'active' OR cm.status IS NULL)
      ORDER BY cm.full_name ASC
    ");
    $m->execute([':cid_cm' => $companyId, ':pid' => $projectId]);
    $memberOptions = $m->fetchAll() ?: [];
  } catch (Throwable $e) {}

  try {
    $c = $pdo->prepare("
      SELECT DISTINCT category
      FROM tasks
      WHERE company_id = :cid_c
        AND project_id = :pid_c
        AND category IS NOT NULL
        AND category <> ''
      ORDER BY category ASC
    ");
    $c->execute([':cid_c' => $companyId, ':pid_c' => $projectId]);
    $categoryOptions = array_map(fn($r) => (string)$r['category'], $c->fetchAll() ?: []);
  } catch (Throwable $e) {}

  try {
    $p = $pdo->prepare("
      SELECT DISTINCT priority
      FROM tasks
      WHERE company_id = :cid_p
        AND project_id = :pid_p
        AND priority IS NOT NULL
        AND priority <> ''
      ORDER BY priority ASC
    ");
    $p->execute([':cid_p' => $companyId, ':pid_p' => $projectId]);
    $priorityOptions = array_map(fn($r) => (string)$r['priority'], $p->fetchAll() ?: []);
  } catch (Throwable $e) {}
}

// ---------- fetch tasks ----------
$rows = [];
$err = '';

if ($tasksTableExists) {
  try {
    $where = [];
    $params = [
      ':pid' => $projectId,
      ':cid_where' => $companyId,
      ':cid_join' => $companyId,
    ];

    $where[] = "t.project_id = :pid";
    $where[] = "t.company_id = :cid_where";
    $where[] = "LOWER(COALESCE(t.status,'')) NOT IN ('completed','done')";
    $where[] = "t.assigned_to_company_member_id IS NOT NULL";

    // view-specific due logic
    if ($view === 'open') {
      $where[] = "(t.due_on IS NULL OR t.due_on >= CURDATE())";
    } elseif ($view === 'due_soon') {
      $where[] = "t.due_on IS NOT NULL AND t.due_on >= CURDATE() AND t.due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    } else { // overdue
      $where[] = "t.due_on IS NOT NULL AND t.due_on < CURDATE()";
    }

    if ($assignee !== '') {
      $where[] = "t.assigned_to_company_member_id = :assignee_id";
      $params[':assignee_id'] = (int)$assignee;
    }
    if ($category !== '') {
      $where[] = "t.category = :cat";
      $params[':cat'] = $category;
    }
    if ($priority !== '') {
      $where[] = "LOWER(COALESCE(t.priority,'')) = LOWER(:prio)";
      $params[':prio'] = $priority;
    }

    $sql = "
      SELECT
        t.id,
        t.title,
        t.category,
        t.priority,
        t.assigned_on,
        t.due_on,
        COALESCE(cm.full_name, 'Unassigned') AS assignee_name
      FROM tasks t
      LEFT JOIN company_members cm
        ON cm.id = t.assigned_to_company_member_id
       AND cm.company_id = :cid_join
      WHERE " . implode(" AND ", $where) . "
      ORDER BY
        CASE WHEN t.due_on IS NULL THEN 1 ELSE 0 END,
        t.due_on ASC,
        CASE LOWER(COALESCE(t.priority,'')) WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END,
        t.id DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    $rows = [];
    $err = $e->getMessage();
  }
}
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
            <div class="proj-name"><?php echo h0((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo h0($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h0((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h0(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="proj-overview-head">
            <div>
              <div class="proj-h2"><?php echo h0($pageH2); ?></div>
              <div class="proj-sub"><?php echo h0($pageSub); ?></div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search tasks" />
            </div>
          </div>

          <!-- KPI tabs -->
          <div class="team-stats">
            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/members.php?id=' . $projectId)); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">👥</div><div class="team-stat-label">Members</div></div>
              <div class="team-stat-num"><?php echo h0((string)$membersCount); ?></div>
            </a>

            <a class="team-stat team-stat--link <?php echo $view==='open'?'team-stat--active':''; ?>"
               href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">☑️</div><div class="team-stat-label">Open tasks</div></div>
              <div class="team-stat-num"><?php echo h0((string)$openTasks); ?></div>
            </a>

            <a class="team-stat team-stat--link <?php echo $view==='due_soon'?'team-stat--active':''; ?>"
               href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=due_soon')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">📅</div><div class="team-stat-label">Due soon</div></div>
              <div class="team-stat-num"><?php echo h0((string)$dueSoon); ?></div>
            </a>

            <a class="team-stat team-stat--link <?php echo $view==='overdue'?'team-stat--active':''; ?>"
               href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=overdue')); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">⚠️</div><div class="team-stat-label">Overdue tasks</div></div>
              <div class="team-stat-num"><?php echo h0((string)$overdue); ?></div>
            </a>
          </div>

          <!-- Assigned tasks card -->
          <div class="card proj-card proj-card--span2 task-list-card">
            <div class="tasks-header">
              <div>
                <div class="proj-card-title">Assigned tasks</div>
                <div class="proj-card-sub">Use filters to narrow by owner, department, or priority.</div>
              </div>

              <details class="filter-dd">
                <summary class="btn">Filter</summary>

                <form class="filter-form" method="get">
                  <input type="hidden" name="id" value="<?php echo h0((string)$projectId); ?>"/>
                  <input type="hidden" name="view" value="<?php echo h0($view); ?>"/>

                  <div class="filter-grid">
                    <label>
                      <div class="filter-label">Member</div>
                      <select name="assignee">
                        <option value="">All</option>
                        <?php foreach ($memberOptions as $m): ?>
                          <?php $mid = (int)$m['id']; ?>
                          <option value="<?php echo h0((string)$mid); ?>" <?php echo ($assignee !== '' && (int)$assignee === $mid) ? 'selected' : ''; ?>>
                            <?php echo h0((string)$m['full_name']); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label>
                      <div class="filter-label">Department / category</div>
                      <select name="category">
                        <option value="">All</option>
                        <?php foreach ($categoryOptions as $c): ?>
                          <option value="<?php echo h0($c); ?>" <?php echo ($category === $c) ? 'selected' : ''; ?>>
                            <?php echo h0(task_cat_label($c)); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label>
                      <div class="filter-label">Priority</div>
                      <select name="priority">
                        <option value="">All</option>
                        <?php foreach ($priorityOptions as $p): ?>
                          <option value="<?php echo h0($p); ?>" <?php echo (strcasecmp($priority, $p) === 0) ? 'selected' : ''; ?>>
                            <?php echo h0(ucfirst(strtolower($p))); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>

                  <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">Apply</button>
                    <a class="btn" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=' . $view)); ?>">Clear</a>
                  </div>
                </form>
              </details>
            </div>

            <?php if (!$tasksTableExists): ?>
              <div class="proj-empty">
                <div class="proj-empty-ico">☑️</div>
                <div class="proj-empty-title">Tasks aren’t set up yet</div>
                <div class="proj-empty-sub">Create the tasks table to see tasks here.</div>
              </div>

            <?php elseif (empty($rows)): ?>
              <div class="proj-empty">
                <div class="proj-empty-ico">📄</div>
                <div class="proj-empty-title">No tasks found</div>
                <div class="proj-empty-sub">Try changing filters.</div>
                <?php if (!empty($_GET['debug']) && $err !== ''): ?>
                  <div class="proj-empty-sub" style="margin-top:10px;color:#b00020;">
                    SQL error: <?php echo h0($err); ?>
                  </div>
                <?php endif; ?>
              </div>

            <?php else: ?>
              <div class="open-tasks-scroll">
                <?php foreach ($rows as $t): ?>
                  <?php
                    $taskId = (int)($t['id'] ?? 0);
                    $cat = (string)($t['category'] ?? 'general');
                    $icon = task_cat_icon($cat);
                    $label = task_cat_label($cat);
                    $title = trim((string)($t['title'] ?? 'Untitled task'));

                    $assignedOn = (string)($t['assigned_on'] ?? '');
                    $assignedLabel = $assignedOn ? date('d/m/Y', strtotime($assignedOn)) : '—';

                    $due = (string)($t['due_on'] ?? '');
                    $dueLabel = $due ? date('d/m/Y', strtotime($due)) : '—';

                    $assigneeName = (string)($t['assignee_name'] ?? 'Unassigned');
                    $prio = trim((string)($t['priority'] ?? ''));
                    $prioLabel = $prio ? ucfirst(strtolower($prio)) : '';
                  ?>

                  <a class="open-task-row open-task-row--link" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">
  <div class="open-task-ico"><?php echo h0($icon); ?></div>

  <div class="open-task-main">
    <div class="open-task-title"><?php echo h0($label . ': ' . $title); ?></div>
    <div class="open-task-meta">
      <span>Assigned: <?php echo h0($assignedLabel); ?></span>
      <span>•</span>
      <span>Due: <?php echo h0($dueLabel); ?></span>
      <?php if ($prioLabel): ?>
        <span class="alert-pill" style="margin-left:8px;"><?php echo h0($prioLabel); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <div class="open-task-right">
    <span class="assignee-pill">Assigned to: <?php echo h0($assigneeName); ?></span>
    <span class="task-overview-arrow">›</span>
  </div>
</a>

                <?php endforeach; ?>
              </div>
            <?php endif; ?>

          </div><!-- /card -->

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  // make rows clickable
  const rows = Array.from(document.querySelectorAll("[data-task-row]"));
  const go = (row) => {
    const href = row.getAttribute("data-href");
    if (href) window.location.href = href;
  };
  rows.forEach((row) => {
    row.addEventListener("click", () => go(row));
    row.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        go(row);
      }
    });
  });

  // close filter when clicking outside
  document.addEventListener("click", (e) => {
    const dd = document.querySelector(".filter-dd");
    if (!dd || !dd.open) return;
    if (!dd.contains(e.target)) dd.open = false;
  });
});
</script>

<?php include $root . '/includes/footer.php'; ?>