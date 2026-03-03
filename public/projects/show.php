<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

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
} catch (Throwable $e) {
  // ignore if project_events isn't ready
}

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

// UI-only date label (uses created_at if you don't have event date stored)
$createdAt = (string)($project['created_at'] ?? '');
$projectDateLabel = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Date TBD';
?>

<div class="app-shell">

  <!-- COMPANY SIDEBAR (left, collapsible via burger) -->
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

      <!-- Project header bar (top of white surface) -->
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
          <!-- These are UI buttons; links go to existing pages -->
          <a class="btn btn-primary" href="<?php echo h(base_url('tasks/index.php')); ?>">＋ New task</a>
          <a class="btn" href="<?php echo h(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <!-- Two-sidebar layout inside the surface -->
      <div class="project-shell">

        <!-- PROJECT SIDEBAR (inside the project) -->
        <?php
          // provides $projectId and $daysToGo to the include
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
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <!-- MAIN PROJECT DASHBOARD CONTENT -->
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
                    <div class="phase-meta">
                      <span>Status: Started</span>
                      <span>0% Completed</span>
                    </div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">👥</div>
                  <div class="phase-body">
                    <div class="phase-name">Guest list set up</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta">
                      <span>Status: Started</span>
                      <span>0% Completed</span>
                    </div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">✉️</div>
                  <div class="phase-body">
                    <div class="phase-name">Invite & RSVP</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta">
                      <span>Status: Not started</span>
                      <span>0% Completed</span>
                    </div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">✈️</div>
                  <div class="phase-body">
                    <div class="phase-name">Travel and transport</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta">
                      <span>Status: Not started</span>
                      <span>0% Completed</span>
                    </div>
                  </div>
                </div>

                <div class="phase-row">
                  <div class="phase-ico">🏨</div>
                  <div class="phase-body">
                    <div class="phase-name">Hotel and hospitality</div>
                    <div class="phase-bar"><div class="w-30"></div></div>
                    <div class="phase-meta">
                      <span>Status: Not started</span>
                      <span>0% Completed</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Alerts -->
            <div class="card proj-card">
              <div class="proj-card-title">Alerts</div>
              <div class="proj-card-sub">Items that need a quick check or follow-up.</div>

              <div class="proj-empty">
                <div class="proj-empty-ico">📄</div>
                <div class="proj-empty-title">No tasks created!</div>
                <div class="proj-empty-sub">Pending tasks will show up here</div>
              </div>
            </div>

            <!-- Recent updates (right column, taller) -->
            <div class="card proj-card proj-card--tall">
              <div class="proj-card-title">Recent updates</div>
              <div class="proj-card-sub">Latest changes across your team and guests.</div>

              <div class="proj-empty">
                <div class="proj-empty-ico">📄</div>
                <div class="proj-empty-title">No recent updates</div>
                <div class="proj-empty-sub">All updates on the project will be displayed here</div>
              </div>
            </div>

            <!-- Quick numbers (spans 2 cols) -->
            <div class="card proj-card proj-card--span2">
              <div class="proj-card-title">Quick numbers</div>
              <div class="proj-card-sub">Latest totals based on current responses.</div>

              <div class="quick-grid">
                <div class="quick-item">
                  <div class="quick-ico">✉️</div>
                  <div>
                    <div class="quick-title">RSVPs received</div>
                    <div class="quick-sub">None</div>
                  </div>
                  <div class="quick-arrow">›</div>
                </div>

                <div class="quick-item">
                  <div class="quick-ico">🏨</div>
                  <div>
                    <div class="quick-title">Accommodation</div>
                    <div class="quick-sub">None</div>
                  </div>
                  <div class="quick-arrow">›</div>
                </div>

                <div class="quick-item">
                  <div class="quick-ico">👥</div>
                  <div>
                    <div class="quick-title">Expected headcount</div>
                    <div class="quick-sub">None</div>
                  </div>
                  <div class="quick-arrow">›</div>
                </div>

                <div class="quick-item">
                  <div class="quick-ico">🚗</div>
                  <div>
                    <div class="quick-title">This week’s movements</div>
                    <div class="quick-sub">None</div>
                  </div>
                  <div class="quick-arrow">›</div>
                </div>
              </div>
            </div>

          </div><!-- /proj-grid -->
        </div><!-- /proj-main -->

      </div><!-- /project-shell -->

      <!-- keep your existing links available (UI-only placement) -->
      <div class="proj-footer-actions">
        <a class="btn" href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract & scope</a>
        <a class="btn" href="<?php echo h(base_url('projects/index.php')); ?>">Back to projects</a>
      </div>

    </div><!-- /surface -->
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>