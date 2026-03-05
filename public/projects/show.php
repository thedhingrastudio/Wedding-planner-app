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

// tasks for Alerts card (latest 5 assigned tasks)
$tasksTableExists = false;
$alertTasks = [];
$alertTasksError = '';

try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
}

if ($tasksTableExists) {
  try {
    // Strict: company + project
    $ts = $pdo->prepare("
      SELECT
        t.id,
        t.category,
        t.title,
        t.status,
        t.priority,
        t.assigned_on,
        t.due_on,
        COALESCE(cm.full_name, 'Unassigned') AS assignee_name
      FROM tasks t
      LEFT JOIN company_members cm
        ON cm.id = t.assigned_to_company_member_id
       AND cm.company_id = :cid_join
      WHERE t.company_id = :cid_where
        AND t.project_id = :pid
        AND LOWER(COALESCE(t.status,'')) NOT IN ('completed','done')
      ORDER BY
        CASE WHEN t.assigned_on IS NULL THEN 1 ELSE 0 END,
        t.assigned_on DESC,
        t.id DESC
      LIMIT 5
    ");
    $ts->execute([
      ':cid_join'  => $companyId,
      ':cid_where' => $companyId,
      ':pid'       => $projectId
    ]);
    $alertTasks = $ts->fetchAll() ?: [];

    // Fallback: project only (if some old tasks have wrong/missing company_id)
    if (!$alertTasks) {
      $ts2 = $pdo->prepare("
        SELECT
          t.id,
          t.category,
          t.title,
          t.status,
          t.priority,
          t.assigned_on,
          t.due_on,
          COALESCE(cm.full_name, 'Unassigned') AS assignee_name
        FROM tasks t
        LEFT JOIN company_members cm
          ON cm.id = t.assigned_to_company_member_id
         AND cm.company_id = :cid_join2
        WHERE t.project_id = :pid2
          AND LOWER(COALESCE(t.status,'')) NOT IN ('completed','done')
        ORDER BY
          CASE WHEN t.assigned_on IS NULL THEN 1 ELSE 0 END,
          t.assigned_on DESC,
          t.id DESC
        LIMIT 5
      ");
      $ts2->execute([
        ':cid_join2' => $companyId,
        ':pid2'      => $projectId
      ]);
      $alertTasks = $ts2->fetchAll() ?: [];
    }

  } catch (Throwable $e) {
    $alertTasks = [];
    $alertTasksError = $e->getMessage();
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

            <!-- Project phases -->
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

            <!-- Alerts -->
            <div class="card proj-card">
              <div class="proj-card-title">Alerts</div>
              <div class="proj-card-sub">Items that need a quick check or follow-up.</div>

              <?php if (!$tasksTableExists): ?>
                <div class="proj-empty">
                  <div class="proj-empty-ico">☑️</div>
                  <div class="proj-empty-title">Tasks aren’t set up yet</div>
                  <div class="proj-empty-sub">Create the tasks table to see alerts here.</div>
                </div>

              <?php elseif (empty($alertTasks)): ?>
                <div class="proj-empty">
                  <div class="proj-empty-ico">📄</div>
                  <div class="proj-empty-title">No tasks created!</div>
                  <div class="proj-empty-sub">Pending tasks will show up here</div>

                  <?php if (!empty($_GET['debug']) && $alertTasksError !== ''): ?>
                    <div class="proj-empty-sub" style="margin-top:10px;color:#b00020;">
                      Alerts SQL error: <?php echo h0($alertTasksError); ?>
                    </div>
                  <?php endif; ?>

                  <div style="margin-top:12px;">
                    <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
                  </div>
                </div>

              <?php else: ?>
                <div class="alerts-scroll">
                <div class="alerts-list">
                  <?php foreach ($alertTasks as $t): ?>
                    <?php
                      $taskId   = (int)($t['id'] ?? 0);
                      $cat      = (string)($t['category'] ?? 'general');
                      $icon     = task_cat_icon($cat);

                      $title    = trim((string)($t['title'] ?? 'Untitled task'));
                      $assignee = (string)($t['assignee_name'] ?? 'Unassigned');

                      $due = (string)($t['due_on'] ?? '');
                      $dueLabel = $due ? date('d M', strtotime($due)) : 'No due date';

                      $isOverdue = false;
                      if ($due) {
                        $isOverdue = strtotime(substr($due, 0, 10)) < strtotime(date('Y-m-d'));
                      }

                      $priority = strtolower((string)($t['priority'] ?? ''));
                      $priorityLabel = $priority ? ucfirst($priority) : '';
                    ?>

                    <div class="alert-item <?php echo $isOverdue ? 'alert-item--overdue' : ''; ?>"
                         role="link"
                         tabindex="0"
                         data-task-row
                         data-href="<?php echo h0(base_url('tasks/show.php?id=' . $taskId)); ?>">
                      <div class="alert-ico"><?php echo h0($icon); ?></div>

                      <div class="alert-body">
                        <div class="alert-title"><?php echo h0($title); ?></div>
                        <div class="alert-meta">
                          <span><?php echo h0($assignee); ?></span>
                          <span>•</span>
                          <span><?php echo h0($dueLabel); ?></span>
                          <?php if ($priorityLabel): ?>
                            <span class="alert-pill"><?php echo h0($priorityLabel); ?></span>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="task-overview-arrow">›</div>
                    </div>
                  <?php endforeach; ?>
                </div>
                          </div>
                        

                <div class="alerts-actions">
  <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
  <a class="btn" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">Show all</a>
</div>
              <?php endif; ?>
            </div>

            <!-- Recent updates placeholder -->
            <div class="card proj-card proj-card--tall">
              <div class="proj-card-title">Recent updates</div>
              <div class="proj-card-sub">Latest changes across your team and guests.</div>

              <div class="proj-empty">
                <div class="proj-empty-ico">📄</div>
                <div class="proj-empty-title">No recent updates</div>
                <div class="proj-empty-sub">All updates on the project will be displayed here</div>
              </div>
            </div>

            <!-- Quick numbers -->
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