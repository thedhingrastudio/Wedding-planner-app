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

$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = $project['title'] . ' — Vidhaan';
require_once $root . '/includes/header.php';

// first event for countdown
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

// team count for sidebar
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
} catch (Throwable $e) {
  $teamCount = 0;
}

// tasks for Alerts card
$tasksTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
}

$alertTasks = [];
if ($tasksTableExists) {
  try {
    $ts = $pdo->prepare("
      SELECT
        t.id, t.category, t.title, t.due_on,
        COALESCE(cm.full_name, 'Unassigned') AS assignee_name
      FROM tasks t
      LEFT JOIN company_members cm
        ON cm.id = t.assigned_to_company_member_id
       AND cm.company_id = :cid
      WHERE t.company_id = :cid
        AND t.project_id = :pid
        AND LOWER(COALESCE(t.status,'')) <> 'completed'
      ORDER BY
        CASE WHEN t.due_on IS NULL THEN 1 ELSE 0 END,
        t.due_on ASC,
        t.created_at DESC
      LIMIT 6
    ");
    $ts->execute([':cid' => $companyId, ':pid' => $projectId]);
    $alertTasks = $ts->fetchAll() ?: [];
  } catch (Throwable $e) {
    $alertTasks = [];
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
          <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ New task</a>
          <a class="btn" href="<?php echo h0(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">

        <?php
          $active = 'overview';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="proj-overview-head">
            <div>
              <div class="proj-h2">Project overview</div>
              <div class="proj-sub">A quick look at the project and the progress.</div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search keywords" />
            </div>
          </div>

          <div class="proj-grid">

            <!-- Project phases (unchanged) -->
            <div class="card proj-card">
              <div class="proj-card-title">Project phases</div>
              <div class="proj-card-sub">Track progress across the full workflow.</div>

              <div class="phase-list">
                <div class="phase-row">
                  <div class="phase-ico">📄</div>
                  <div class="phase-body">
                    <div class="phase-name">Contract & scope</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta"><span>Status: Started</span><span>0% Completed</span></div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">👥</div>
                  <div class="phase-body">
                    <div class="phase-name">Guest list set up</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta"><span>Status: Started</span><span>0% Completed</span></div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">✉️</div>
                  <div class="phase-body">
                    <div class="phase-name">Invite & RSVP</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta"><span>Status: Not started</span><span>0% Completed</span></div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">✈️</div>
                  <div class="phase-body">
                    <div class="phase-name">Travel and transport</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta"><span>Status: Not started</span><span>0% Completed</span></div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">🏨</div>
                  <div class="phase-body">
                    <div class="phase-name">Hotel and hospitality</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta"><span>Status: Not started</span><span>0% Completed</span></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Alerts (NOW REAL TASKS) -->
            <div class="card proj-card">
              <div class="proj-card-title">Alerts</div>
              <div class="proj-card-sub">Items that need a quick check or follow-up.</div>

              <?php if (!$tasksTableExists): ?>
                <div class="proj-empty">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">Tasks aren’t set up yet</div>
                  <div class="proj-empty-sub">Create the tasks table to see alerts here.</div>
                </div>

              <?php elseif (!$alertTasks): ?>
                <div class="proj-empty">
                  <div class="proj-empty-ico">📄</div>
                  <div class="proj-empty-title">No tasks created!</div>
                  <div class="proj-empty-sub">Pending tasks will show up here</div>
                  <div style="margin-top:12px;">
                    <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
                  </div>
                </div>

              <?php else: ?>
                <div class="task-overview-list">
                  <?php foreach ($alertTasks as $t): ?>
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

            <!-- Recent updates placeholder (unchanged) -->
            <div class="card proj-card proj-card--tall">
              <div class="proj-card-title">Recent updates</div>
              <div class="proj-card-sub">Latest changes across your team and guests.</div>

              <div class="proj-empty">
                <div class="proj-empty-ico">📄</div>
                <div class="proj-empty-title">No recent updates</div>
                <div class="proj-empty-sub">All updates on the project will be displayed here</div>
              </div>
            </div>

            <!-- Quick numbers (unchanged) -->
            <div class="card proj-card proj-card--span2">
              <div class="proj-card-title">Quick numbers</div>
              <div class="proj-card-sub">Latest totals based on current responses.</div>

              <div class="quick-grid">
                <div class="quick-item">
                  <div class="quick-ico">✉️</div>
                  <div><div class="quick-title">RSVPs received</div><div class="quick-sub">None</div></div>
                  <div class="quick-arrow">›</div>
                </div>
                <div class="quick-item">
                  <div class="quick-ico">🏨</div>
                  <div><div class="quick-title">Accommodation</div><div class="quick-sub">None</div></div>
                  <div class="quick-arrow">›</div>
                </div>
                <div class="quick-item">
                  <div class="quick-ico">👥</div>
                  <div><div class="quick-title">Expected headcount</div><div class="quick-sub">None</div></div>
                  <div class="quick-arrow">›</div>
                </div>
                <div class="quick-item">
                  <div class="quick-ico">🚗</div>
                  <div><div class="quick-title">This week’s movements</div><div class="quick-sub">None</div></div>
                  <div class="quick-arrow">›</div>
                </div>
              </div>
            </div>

          </div><!-- /proj-grid -->
        </div><!-- /proj-main -->

      </div><!-- /project-shell -->

      <div class="proj-footer-actions">
        <a class="btn" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract & scope</a>
        <a class="btn" href="<?php echo h0(base_url('projects/index.php')); ?>">Back to projects</a>
      </div>

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