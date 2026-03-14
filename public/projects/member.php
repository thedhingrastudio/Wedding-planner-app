<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
$memberId  = (int)($_GET['mid'] ?? 0);

if ($projectId <= 0) redirect('projects/index.php');
if ($memberId <= 0) redirect('projects/members.php?id=' . $projectId);

$companyId = current_company_id();

// Project security
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = $project['title'] . ' — Member — Vidhaan';
require_once $root . '/includes/header.php';

// Countdown
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

function role_label(string $role): string {
  $map = [
    'team_lead'    => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp'         => 'RSVP',
    'hospitality'  => 'Hospitality',
    'transport'    => 'Transport',
    'vendor'       => 'Vendor',
  ];
  $role = trim($role);
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

// Member must belong to company AND be on this project
$member = null;
$memberRoles = [];
try {
  $ms = $pdo->prepare("
    SELECT cm.*
    FROM company_members cm
    JOIN project_members pm
      ON pm.company_member_id = cm.id
     AND pm.project_id = :pid
    WHERE cm.company_id = :cid
      AND cm.id = :mid
    LIMIT 1
  ");
  $ms->execute([':pid' => $projectId, ':cid' => $companyId, ':mid' => $memberId]);
  $member = $ms->fetch() ?: null;

  if ($member) {
    $rs = $pdo->prepare("
      SELECT DISTINCT pm.role
      FROM project_members pm
      WHERE pm.project_id = :pid
        AND pm.company_member_id = :mid
      ORDER BY pm.role ASC
    ");
    $rs->execute([':pid' => $projectId, ':mid' => $memberId]);
    $memberRoles = array_map(fn($r) => (string)$r['role'], $rs->fetchAll() ?: []);
  }
} catch (Throwable $e) {
  $member = null;
}

if (!$member) redirect('projects/members.php?id=' . $projectId);

// Tasks module + KPI counts
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
} catch (Throwable $e) {}

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
      WHERE company_id = :cid AND project_id = :pid
    ");
    $cs->execute([':cid' => $companyId, ':pid' => $projectId]);
    $row = $cs->fetch() ?: [];
    $openTasks = (int)($row['open_tasks'] ?? 0);
    $overdue   = (int)($row['overdue_tasks'] ?? 0);
    $dueSoon   = (int)($row['due_soon_tasks'] ?? 0);
  } catch (Throwable $e) {}
}

// Member tasks (+ filter)
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all','pending','due','completed'], true)) $statusFilter = 'all';

$taskRows = [];
try {
  if ($tasksTableExists) {
    $where = [];
    $params = [
      ':cid_where' => $companyId,
      ':pid'       => $projectId,
      ':mid'       => $memberId,
      ':cid_join'  => $companyId,
    ];

    $where[] = "t.company_id = :cid_where";
    $where[] = "t.project_id = :pid";
    $where[] = "t.assigned_to_company_member_id = :mid";

    // status filter mapped to “chips” like reference
    if ($statusFilter === 'completed') {
      $where[] = "LOWER(COALESCE(t.status,'')) IN ('completed','done')";
    } elseif ($statusFilter === 'due') {
      $where[] = "LOWER(COALESCE(t.status,'')) NOT IN ('completed','done')";
      $where[] = "t.due_on IS NOT NULL AND t.due_on < CURDATE()";
    } elseif ($statusFilter === 'pending') {
      $where[] = "LOWER(COALESCE(t.status,'')) NOT IN ('completed','done')";
      $where[] = "(t.due_on IS NULL OR t.due_on >= CURDATE())";
    }

    $sql = "
      SELECT
        t.id,
        t.title,
        t.category,
        t.status,
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
        t.id DESC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $taskRows = $st->fetchAll() ?: [];
  }
} catch (Throwable $e) {
  $taskRows = [];
}

$memberName = (string)($member['full_name'] ?? 'Member');
$memberEmail = (string)($member['email'] ?? '—');
$memberPhone = (string)($member['phone'] ?? ($member['phone_number'] ?? '—')); // safe if your column differs
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
              <div class="proj-h2"><?php echo h0($memberName); ?></div>
              <div class="proj-sub"><?php echo h0($memberRoles ? role_label($memberRoles[0]) : ''); ?></div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search team member" />
            </div>
          </div>

          <!-- KPI tabs (clickable) -->
          <div class="team-stats">
            <a class="team-stat team-stat--link team-stat--active" href="<?php echo h0(base_url('projects/members.php?id=' . $projectId)); ?>">
              <div class="team-stat-left"><div class="team-stat-ico">👥</div><div class="team-stat-label">Members</div></div>
              <div class="team-stat-num"><?php echo h0((string)$membersCount); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">
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

          <div class="member-detail-grid">

           <div class="card member-info-card">
  <div class="proj-card-title">General information</div>
  <div class="proj-card-sub">Basic information about the member</div>

  <div class="kv">
    <div class="kv-row">
      <div class="kv-key">Phone</div>
      <div class="kv-val"><?php echo h0($memberPhone ?: '—'); ?></div>
    </div>
    <div class="kv-row">
      <div class="kv-key">Email</div>
      <div class="kv-val"><?php echo h0($memberEmail ?: '—'); ?></div>
    </div>
  </div>

  <div style="margin-top:16px; display:flex; justify-content:flex-end;">
    <a class="btn" href="<?php echo h0(base_url('projects/member_edit.php?id=' . $projectId . '&mid=' . $memberId)); ?>">Edit details</a>
  </div>
</div>

            <div class="card member-tasks-card">
              <div class="member-tasks-head">
                <div>
                  <div class="proj-card-title">Assigned tasks</div>
                  <div class="proj-card-sub">All the assigned tasks to the member</div>
                </div>

                <details class="filter-dd">
                  <summary class="btn btn-ghost">Filter</summary>
                  <form class="filter-form" method="get">
                    <input type="hidden" name="id" value="<?php echo h0((string)$projectId); ?>">
                    <input type="hidden" name="mid" value="<?php echo h0((string)$memberId); ?>">

                    <div class="filter-grid">
                      <label>
                        <div class="filter-label">Status</div>
                        <select name="status">
                          <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>All</option>
                          <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                          <option value="due" <?php echo $statusFilter==='due'?'selected':''; ?>>Due</option>
                          <option value="completed" <?php echo $statusFilter==='completed'?'selected':''; ?>>Completed</option>
                        </select>
                      </label>
                    </div>

                    <div class="filter-actions">
                      <button class="btn btn-primary" type="submit">Apply</button>
                      <a class="btn" href="<?php echo h0(base_url('projects/member.php?id=' . $projectId . '&mid=' . $memberId)); ?>">Clear</a>
                    </div>
                  </form>
                </details>
              </div>

              <?php if (!$tasksTableExists): ?>
                <div class="proj-empty" style="min-height:340px;">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">Tasks aren’t set up yet</div>
                  <div class="proj-empty-sub">Create the tasks table to see tasks here.</div>
                </div>
              <?php elseif (!$taskRows): ?>
                <div class="proj-empty" style="min-height:340px;">
                  <div class="proj-empty-ico">📄</div>
                  <div class="proj-empty-title">No tasks found</div>
                  <div class="proj-empty-sub">Try changing the filter.</div>
                </div>
              <?php else: ?>
                <div class="member-tasks-scroll">
                  <?php foreach ($taskRows as $t): ?>
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

                      $status = strtolower(trim((string)($t['status'] ?? '')));
                      $isCompleted = in_array($status, ['completed','done'], true);
                      $isDue = (!$isCompleted && $due && strtotime(substr($due,0,10)) < strtotime(date('Y-m-d')));

                      $statusLabel = $isCompleted ? 'Completed' : ($isDue ? 'Due' : 'Pending');
                      $statusClass = $isCompleted ? 'status-pill--completed' : ($isDue ? 'status-pill--due' : 'status-pill--pending');
                    ?>

                    <a class="member-task-row" href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">
                      <div class="member-task-ico"><?php echo h0($icon); ?></div>

                      <div class="member-task-main">
                        <div class="member-task-title"><?php echo h0($label . ': ' . $title); ?></div>
                        <div class="member-task-sub">
                          <span>Assigned: <?php echo h0($assignedLabel); ?></span>
                          <span class="dot">•</span>
                          <span>Due: <?php echo h0($dueLabel); ?></span>
                        </div>
                      </div>

                      <span class="status-pill <?php echo h0($statusClass); ?>"><?php echo h0($statusLabel); ?></span>
                      <span class="task-overview-arrow">›</span>
                    </a>

                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

          </div><!-- /member-detail-grid -->

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<script>
document.addEventListener("click", (e) => {
  const dd = document.querySelector(".filter-dd");
  if (!dd || !dd.open) return;
  if (!dd.contains(e.target)) dd.open = false;
});
</script>

<?php include $root . '/includes/footer.php'; ?>