<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
$cmid      = (int)($_GET['cmid'] ?? 0);

if ($projectId <= 0 || $cmid <= 0) redirect('projects/index.php');

$companyId = current_company_id();

// Project security
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

// Verify member is part of this project
$chk = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = :pid AND company_member_id = :cmid");
$chk->execute([':pid' => $projectId, ':cmid' => $cmid]);
if ((int)$chk->fetchColumn() === 0) redirect('projects/members.php?id=' . $projectId);

// Load member
$m = $pdo->prepare("
  SELECT id, full_name, email, phone, default_department, status
  FROM company_members
  WHERE id = :cmid AND company_id = :cid
  LIMIT 1
");
$m->execute([':cmid' => $cmid, ':cid' => $companyId]);
$member = $m->fetch();
if (!$member) redirect('projects/members.php?id=' . $projectId);

function role_label(string $role): string {
  $map = [
    'team_lead' => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp' => 'RSVP',
    'hospitality' => 'Hospitality',
    'transport' => 'Transport',
    'vendor' => 'Vendor',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

function cat_label(string $cat): string {
  $map = [
    'follow_ups' => 'Follow ups',
    'rsvp' => 'Invite & RSVP',
    'guest_list' => 'Guest list',
    'transport' => 'Travel & transport',
    'hospitality' => 'Hotel & hospitality',
    'vendors' => 'Vendors',
    'general' => 'General',
  ];
  $cat = trim($cat);
  return $map[$cat] ?? ucfirst(str_replace('_', ' ', $cat));
}

function cat_icon(string $cat): string {
  $map = [
    'follow_ups' => '✉️',
    'rsvp' => '📩',
    'guest_list' => '👥',
    'transport' => '🚗',
    'hospitality' => '🏨',
    'vendors' => '🧾',
    'general' => '☑️',
  ];
  $cat = trim($cat);
  return $map[$cat] ?? '☑️';
}

// Roles for this member in this project
$roles = [];
try {
  $r = $pdo->prepare("
    SELECT DISTINCT role
    FROM project_members
    WHERE project_id = :pid AND company_member_id = :cmid
    ORDER BY role
  ");
  $r->execute([':pid' => $projectId, ':cmid' => $cmid]);
  $roles = array_values(array_filter(array_map('strval', $r->fetchAll(PDO::FETCH_COLUMN))));
} catch (Throwable $e) {
  $roles = [];
}

// ✅ Countdown for project_sidebar.php ($daysToGo)
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

// Project stats (optional, matches your reference top row)
$membersCount = 0;
$openTasks = 0;
$dueSoon = 0;
$overdue = 0;

try {
  $tc = $pdo->prepare("SELECT COUNT(DISTINCT company_member_id) FROM project_members WHERE project_id = :pid AND company_member_id IS NOT NULL");
  $tc->execute([':pid' => $projectId]);
  $membersCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {}

try {
  $cs = $pdo->prepare("
    SELECT
      SUM(CASE WHEN status <> 'completed' THEN 1 ELSE 0 END) AS open_tasks,
      SUM(CASE WHEN status <> 'completed' AND due_on IS NOT NULL AND due_on < CURDATE() THEN 1 ELSE 0 END) AS overdue_tasks,
      SUM(CASE WHEN status <> 'completed' AND due_on IS NOT NULL AND due_on >= CURDATE() AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_soon_tasks
    FROM tasks
    WHERE company_id = :cid AND project_id = :pid
  ");
  $cs->execute([':cid' => $companyId, ':pid' => $projectId]);
  $row = $cs->fetch() ?: [];
  $openTasks = (int)($row['open_tasks'] ?? 0);
  $overdue   = (int)($row['overdue_tasks'] ?? 0);
  $dueSoon   = (int)($row['due_soon_tasks'] ?? 0);
} catch (Throwable $e) {
  // tasks table missing or query error — keep counts at 0
}

// ✅ REAL tasks for THIS member
$tasks = [];
try {
  $ts = $pdo->prepare("
    SELECT id, category, title, description, assigned_on, due_on, priority, status, created_at
    FROM tasks
    WHERE company_id = :cid
      AND project_id = :pid
      AND assigned_to_company_member_id = :cmid
    ORDER BY
      CASE
        WHEN status <> 'completed' AND due_on IS NOT NULL AND due_on < CURDATE() THEN 0
        WHEN status <> 'completed' AND due_on IS NOT NULL AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1
        WHEN status <> 'completed' THEN 2
        ELSE 3
      END,
      (due_on IS NULL),
      due_on ASC,
      created_at DESC
  ");
  $ts->execute([':cid' => $companyId, ':pid' => $projectId, ':cmid' => $cmid]);
  $tasks = $ts->fetchAll() ?: [];
} catch (Throwable $e) {
  $tasks = [];
}

$pageTitle = $project['title'] . ' — ' . $member['full_name'] . ' — Vidhaan';
require_once $root . '/includes/header.php';

$primaryRole = $roles ? role_label($roles[0]) : 'Member';
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
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo h((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo h($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h(base_url('projects/members.php?id=' . $projectId)); ?>">Back</a>
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
              <div class="proj-h2">Team overview</div>
              <div class="proj-sub">Everyone working on this event, what they own, and what’s due.</div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search team member" disabled />
            </div>
          </div>

          <div class="team-stats">

  <!-- ✅ Members is a link back to members list -->
  <a class="team-stat team-stat--link"
     href="<?php echo h(base_url('projects/members.php?id=' . $projectId)); ?>">
    <div class="team-stat-left">
      <div class="team-stat-ico" aria-hidden="true">👥</div>
      <div class="team-stat-label">Members</div>
    </div>
    <div class="team-stat-num"><?php echo h((string)$membersCount); ?></div>
  </a>

  <!-- ✅ Open tasks can link to tasks filtered to this project -->
  <a class="team-stat team-stat--link"
     href="<?php echo h(base_url('tasks/index.php?project_id=' . $projectId)); ?>">
    <div class="team-stat-left">
      <div class="team-stat-ico" aria-hidden="true">☑️</div>
      <div class="team-stat-label">Open tasks</div>
    </div>
    <div class="team-stat-num"><?php echo h((string)$openTasks); ?></div>
  </a>

  <!-- Keep these as non-links for now (until we build a tasks list page with filters) -->
  <div class="team-stat">
    <div class="team-stat-left">
      <div class="team-stat-ico" aria-hidden="true">📅</div>
      <div class="team-stat-label">Due soon</div>
    </div>
    <div class="team-stat-num"><?php echo h((string)$dueSoon); ?></div>
  </div>

  <div class="team-stat">
    <div class="team-stat-left">
      <div class="team-stat-ico" aria-hidden="true">⚠️</div>
      <div class="team-stat-label">Overdue tasks</div>
    </div>
    <div class="team-stat-num"><?php echo h((string)$overdue); ?></div>
  </div>

</div>

          <div class="member-head">
            <div class="member-h1"><?php echo h((string)$member['full_name']); ?></div>
            <div class="member-sub"><?php echo h($primaryRole); ?></div>
          </div>

          <div class="member-grid">

            <div class="card member-info">
              <div class="proj-card-title">General information</div>
              <div class="proj-card-sub">Basic information about the member</div>

              <div class="member-info-row">
                <div class="member-info-label">Phone</div>
                <div class="member-info-val"><?php echo h((string)($member['phone'] ?? '—')); ?></div>
              </div>

              <div class="member-info-row">
                <div class="member-info-label">Email</div>
                <div class="member-info-val"><?php echo h((string)($member['email'] ?? '—')); ?></div>
              </div>

              <?php if ($roles): ?>
                <div class="member-roles">
                  <?php foreach ($roles as $r): ?>
                    <span class="tag tag--sm"><?php echo h(role_label($r)); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="card member-tasks">
              <div class="card-head">
                <div>
                  <div class="card-head-title">Assigned tasks</div>
                  <div class="card-head-sub">All the assigned tasks to the member</div>
                </div>
                <a class="btn btn-ghost" href="#" onclick="return false;">Filter</a>
              </div>

              <?php if (!$tasks): ?>
                <div class="proj-empty" style="min-height:320px;">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">No tasks assigned yet</div>
                  <div class="proj-empty-sub">Assign a task to <?php echo h((string)$member['full_name']); ?> to see it show up here.</div>
                  <div style="margin-top:12px;">
                    <a class="btn btn-primary" href="<?php echo h(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
                  </div>
                </div>
              <?php else: ?>
                <div class="task-list">
                  <?php foreach ($tasks as $t): ?>
                    <?php
                      $cat = (string)($t['category'] ?? 'general');
                      $icon = cat_icon($cat);
                      $title = (string)($t['title'] ?? '');

                      $assigned = (string)($t['assigned_on'] ?? '');
                      $due = (string)($t['due_on'] ?? '');

                      $assignedLabel = $assigned ? date('d/m/Y', strtotime($assigned)) : '—';
                      $dueLabel = $due ? date('d/m/Y', strtotime($due)) : '—';

                      $statusRaw = (string)($t['status'] ?? 'pending');
                      $statusLabel = 'Pending';
                      $pillClass = 'task-status-pill is-pending';

                      if ($statusRaw === 'completed') {
                        $statusLabel = 'Completed';
                        $pillClass = 'task-status-pill is-complete';
                      } else {
                        // If not completed and overdue => "Due"
                        if ($due && strtotime($due) < strtotime(date('Y-m-d'))) {
                          $statusLabel = 'Due';
                          $pillClass = 'task-status-pill is-due';
                        } else {
                          $statusLabel = 'Pending';
                          $pillClass = 'task-status-pill is-pending';
                        }
                      }
                    ?>

                    <div class="task-row">
                      <div class="task-ico"><?php echo h($icon); ?></div>

                      <div class="task-body">
                        <div class="task-title"><?php echo h(cat_label($cat) . ': ' . $title); ?></div>
                        <div class="task-meta">
                          <span>Assigned: <?php echo h($assignedLabel); ?></span>
                          <span class="dot">•</span>
                          <span>Due: <?php echo h($dueLabel); ?></span>
                        </div>
                      </div>

                      <div class="task-status">
                        <span class="<?php echo h($pillClass); ?>"><?php echo h($statusLabel); ?></span>
                      </div>

                      <div class="task-arrow">›</div>
                    </div>

                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

            </div>

          </div><!-- /member-grid -->

        </div>
      </div>

    </div>
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>