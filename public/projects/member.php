<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
$cmid = (int)($_GET['cmid'] ?? 0);
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

$pageTitle = $project['title'] . ' — ' . $member['full_name'] . ' — Vidhaan';
require_once $root . '/includes/header.php';

// Task placeholders (until tasks module exists)
$membersCount = 0;
try {
  $tc = $pdo->prepare("SELECT COUNT(DISTINCT company_member_id) FROM project_members WHERE project_id = :pid AND company_member_id IS NOT NULL");
  $tc->execute([':pid' => $projectId]);
  $membersCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) { $membersCount = 0; }

$openTasks = 0;
$dueSoon = 0;
$overdue = 0;

// For now: fake sample tasks in UI (remove later when tasks table exists)
$sampleTasks = [
  ['icon'=>'✉️','title'=>'Follow ups: 18 pending guests RSVPs','assigned'=>'01/02/2026','due'=>'01/02/2026','status'=>'Pending'],
  ['icon'=>'✉️','title'=>'Follow ups: 30 guests dietary restrictions pending','assigned'=>'05/02/2026','due'=>'05/02/2026','status'=>'Pending'],
  ['icon'=>'🚗','title'=>'Pick ups: Assign pick ups for March 18 arrivals','assigned'=>'27/12/2025','due'=>'27/12/2025','status'=>'Due'],
  ['icon'=>'🏨','title'=>'Accommodation: 3 guests’ accommodation preference pending','assigned'=>'15/02/2026','due'=>'15/02/2026','status'=>'Due'],
  ['icon'=>'✉️','title'=>'Follow ups: 12 guests’ details missing','assigned'=>'01/02/2026','due'=>'01/02/2026','status'=>'Completed'],
];
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

      <!-- Project header bar -->
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

          <!-- Reference-style header -->
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

          <!-- Stats row -->
          <div class="team-stats">
            <div class="team-stat">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">👥</div>
                <div class="team-stat-label">Members</div>
              </div>
              <div class="team-stat-num"><?php echo h((string)$membersCount); ?></div>
            </div>

            <div class="team-stat">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">☑️</div>
                <div class="team-stat-label">Open tasks</div>
              </div>
              <div class="team-stat-num"><?php echo h((string)$openTasks); ?></div>
            </div>

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

          <!-- Member header -->
          <div class="member-head">
            <div class="member-h1"><?php echo h((string)$member['full_name']); ?></div>
            <div class="member-sub"><?php echo h($roles ? role_label($roles[0]) : 'Member'); ?></div>
          </div>

          <!-- Info + Tasks layout -->
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

              <!-- Until Tasks module exists: show sample list like reference -->
              <div class="task-list">
                <?php foreach ($sampleTasks as $t): ?>
                  <?php
                    $status = $t['status'];
                    $pillClass = 'task-status-pill';
                    if ($status === 'Completed') $pillClass .= ' is-complete';
                    elseif ($status === 'Due') $pillClass .= ' is-due';
                    else $pillClass .= ' is-pending';
                  ?>
                  <div class="task-row">
                    <div class="task-ico"><?php echo h($t['icon']); ?></div>
                    <div class="task-body">
                      <div class="task-title"><?php echo h($t['title']); ?></div>
                      <div class="task-meta">
                        <span>Assigned: <?php echo h($t['assigned']); ?></span>
                        <span class="dot">•</span>
                        <span>Due: <?php echo h($t['due']); ?></span>
                      </div>
                    </div>
                    <div class="task-status">
                      <span class="<?php echo h($pillClass); ?>"><?php echo h($status); ?></span>
                    </div>
                    <div class="task-arrow">›</div>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="member-note">
                Tasks will become real once the Tasks table/module is created.
              </div>
            </div>

          </div><!-- /member-grid -->

        </div>
      </div>

    </div>
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>