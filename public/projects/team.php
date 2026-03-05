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

$pageTitle = $project['title'] . ' — Team — Vidhaan';
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

// --- Members (workstreams card) ---
$members = [];
try {
  $mstmt = $pdo->prepare("
    SELECT
      pm.company_member_id,
      cm.full_name AS full_name,
      cm.email AS email,
      GROUP_CONCAT(DISTINCT pm.role ORDER BY pm.role SEPARATOR ',') AS roles
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
      AND (cm.status = 'active' OR cm.status IS NULL)
    GROUP BY pm.company_member_id, cm.full_name, cm.email
    ORDER BY cm.full_name ASC
  ");
  $mstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
  $members = $mstmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $members = [];
}
$membersCount = count($members);

// --- Recent updates (member assignments) ---
$updates = [];
try {
  $u = $pdo->prepare("
    SELECT
      pm.role,
      pm.created_at,
      COALESCE(cm.full_name, pm.display_name, pm.email, CONCAT('Member #', pm.id)) AS name
    FROM project_members pm
    LEFT JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid
    WHERE pm.project_id = :pid
    ORDER BY pm.created_at DESC
    LIMIT 6
  ");
  $u->execute([':pid' => $projectId, ':cid' => $companyId]);
  $updates = $u->fetchAll() ?: [];
} catch (Throwable $e) {
  $updates = [];
}

// --- Tasks (counts + latest list) ---
$tasksTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
}
$tasksModuleMissing = !$tasksTableExists;

$openTasks = 0;
$dueSoon   = 0;
$overdue   = 0;
$taskRows  = [];

if (!$tasksModuleMissing) {
  try {
    // counts
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

    // latest tasks list (safe ordering)
    $ts = $pdo->prepare("
      SELECT
        t.id,
        t.category,
        t.title,
        t.due_on,
        t.status,
        COALESCE(cm.full_name, 'Unassigned') AS assignee_name
      FROM tasks t
      LEFT JOIN company_members cm
        ON cm.id = t.assigned_to_company_member_id
       AND cm.company_id = :cid_cm
      WHERE t.company_id = :cid_t
        AND t.project_id = :pid_t
      ORDER BY
        CASE WHEN t.assigned_on IS NULL THEN 1 ELSE 0 END,
        t.assigned_on DESC,
        t.id DESC
      LIMIT 12
    ");
    $ts->execute([
      ':cid_cm' => $companyId,
      ':cid_t'  => $companyId,
      ':pid_t'  => $projectId
    ]);
    $taskRows = $ts->fetchAll() ?: [];

  } catch (Throwable $e) {
    $taskRows = [];
    $openTasks = $dueSoon = $overdue = 0;
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
              <div class="proj-h2">Team overview</div>
              <div class="proj-sub">Everyone working on this event, what they own, and what’s due.</div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search team member" data-team-search />
            </div>
          </div>

          <!-- KPI tabs (ALL clickable) -->
          <div class="team-stats">
            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/members.php?id=' . $projectId)); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">👥</div>
                <div class="team-stat-label">Members</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$membersCount); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">☑️</div>
                <div class="team-stat-label">Open tasks</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$openTasks); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=due_soon')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">📅</div>
                <div class="team-stat-label">Due soon</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$dueSoon); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=overdue')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">⚠️</div>
                <div class="team-stat-label">Overdue tasks</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$overdue); ?></div>
            </a>
          </div>

          <div class="team-grid">

            <div class="team-left">
              <!-- Workstreams -->
              <div class="card">
                <div class="proj-card-title">Team member’s workstreams</div>
                <div class="proj-card-sub">Who owns what across the project.</div>

                <?php if (!$members): ?>
                  <div class="proj-empty" style="min-height:240px;">
                    <div class="proj-empty-ico">👥</div>
                    <div class="proj-empty-title">No members assigned yet</div>
                    <div class="proj-empty-sub">Assign members to this project to see workstreams here.</div>
                  </div>
                <?php else: ?>
                  <div class="member-lines">
                    <?php foreach ($members as $m): ?>
                      <?php
                        $name = (string)($m['full_name'] ?? 'Member');
                        $rolesCsv = (string)($m['roles'] ?? '');
                        $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
                      ?>
                      <div class="member-line">
                        <div class="member-left">
                          <div class="member-name"><?php echo h0($name); ?></div>
                        </div>
                        <div class="member-tags">
                          <?php foreach ($roles as $r): ?>
                            <span class="tag"><?php echo h0(role_label($r)); ?></span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Recent updates -->
              <div class="card">
                <div class="proj-card-title">Recent updates</div>
                <div class="proj-card-sub">Latest team assignments for this project.</div>

                <?php if (!$updates): ?>
                  <div class="proj-empty" style="min-height:220px;">
                    <div class="proj-empty-ico">🧾</div>
                    <div class="proj-empty-title">No recent updates</div>
                    <div class="proj-empty-sub">New assignments will show up here.</div>
                  </div>
                <?php else: ?>
                  <div class="updates-list">
                    <?php foreach ($updates as $u): ?>
                      <div class="update-row">
                        <div class="update-ico">👤</div>
                        <div class="update-text">
                          <div class="update-title">
                            <?php echo h0((string)$u['name']); ?> added to <?php echo h0(role_label((string)$u['role'])); ?>
                          </div>
                          <div class="update-sub"><?php echo h0((string)$u['created_at']); ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Task overview -->
            <div class="card task-overview-card">
              <div class="card-head">
                <div>
                  <div class="card-head-title">Task overview</div>
                  <div class="card-head-sub">Latest tasks assigned in this project.</div>
                </div>
                <a class="btn btn-ghost" href="#" onclick="return false;">Filter</a>
              </div>

              <?php if ($tasksModuleMissing): ?>
                <div class="proj-empty" style="min-height:520px;">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">Tasks aren’t set up yet</div>
                  <div class="proj-empty-sub">Create the tasks table and this section will auto-populate.</div>
                </div>

              <?php elseif (!$taskRows): ?>
                <div class="proj-empty" style="min-height:520px;">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">No tasks yet</div>
                  <div class="proj-empty-sub">Add a task to this project to see it here.</div>
                  <div style="margin-top:12px;">
                    <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
                  </div>
                </div>

              <?php else: ?>
                <div class="task-overview-list">
                  <?php foreach ($taskRows as $t): ?>
                    <?php
                      $taskId = (int)($t['id'] ?? 0);
                      $cat = (string)($t['category'] ?? 'general');
                      $icon = task_cat_icon($cat);
                      $label = task_cat_label($cat);
                      $title = trim((string)($t['title'] ?? ''));

                      $due = (string)($t['due_on'] ?? '');
                      $dueLabel = $due ? date('d/m/Y', strtotime($due)) : '—';

                      $assignee = (string)($t['assignee_name'] ?? 'Unassigned');
                    ?>

                    <div class="task-overview-row task-overview-row--click"
                         role="link"
                         tabindex="0"
                         data-task-row
                         data-href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">
                      <div class="task-overview-ico"><?php echo h0($icon); ?></div>

                      <div class="task-overview-main">
                        <div class="task-overview-title"><?php echo h0($label . ': ' . $title); ?></div>
                        <div class="task-overview-sub">Due: <?php echo h0($dueLabel); ?></div>
                      </div>

                      <div class="task-overview-right">
                        <span class="task-overview-assignee"><?php echo h0($assignee); ?></span>
                      </div>

                      <div class="task-overview-arrow">›</div>
                    </div>

                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

          </div><!-- /team-grid -->

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
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
});
</script>

<?php include $root . '/includes/footer.php'; ?>