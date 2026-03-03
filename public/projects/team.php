<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$companyId = current_company_id();
$pdo = $pdo ?? get_pdo();

$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = $project['title'] . ' — Team — Vidhaan';
require_once $root . '/includes/header.php';

// countdown (same as show.php)
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

function role_label(string $role): string {
  $map = [
    'team_lead' => 'Team lead',
    'rsvp' => 'RSVP',
    'hospitality' => 'Hospitality',
    'transport' => 'Transport',
    'vendor' => 'Vendor',
    'coordination' => 'Coordination',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// team count
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

// members list (project members + departments) — stable query
$members = [];
try {
  $mstmt = $pdo->prepare("
    SELECT
      pm.company_member_id,
      cm.full_name AS full_name,
      cm.email AS email,
      cm.status AS status,
      GROUP_CONCAT(DISTINCT pm.role ORDER BY pm.role SEPARATOR ',') AS roles,
      MIN(pm.created_at) AS assigned_at
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
      AND (cm.status = 'active' OR cm.status IS NULL)
    GROUP BY pm.company_member_id, cm.full_name, cm.email, cm.status
    ORDER BY cm.full_name ASC
  ");
  $mstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
  $members = $mstmt->fetchAll();
} catch (Throwable $e) {
  $members = [];
}

$membersCount = max(count($members), $teamCount);

// tasks not built yet
$openTasks = 0;
$dueSoon = 0;
$overdue = 0;

// recent updates fallback (latest assignments)
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
  $updates = $u->fetchAll();
} catch (Throwable $e) {
  $updates = [];
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
          <a class="btn btn-primary" href="<?php echo h(base_url('tasks/index.php')); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
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

          <!-- ✅ STATS ROW (single, clean) -->
          <div class="team-stats">
           <a class="team-stat team-stat--link" href="<?php echo h(base_url('projects/members.php?id=' . $projectId)); ?>">
  <div class="team-stat-left">
    <div class="team-stat-ico" aria-hidden="true">👥</div>
    <div class="team-stat-label">Members</div>
  </div>
  <div class="team-stat-num"><?php echo h((string)$membersCount); ?></div>
</a>
            

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

          <!-- 2-col grid -->
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
                        $name = (string)($m['full_name'] ?? '');
                        if ($name === '') $name = (string)($m['display_name'] ?? '');
                        if ($name === '') $name = (string)($m['email'] ?? '');
                        if ($name === '') $name = 'Member';

                        $email = (string)($m['email'] ?? '');
                        $rolesCsv = (string)($m['roles'] ?? '');
                        $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
                      ?>
                      <div class="member-line" data-team-member="<?php echo h(strtolower($name . ' ' . $email . ' ' . $rolesCsv)); ?>">
                        <div class="member-left">
                          <div class="member-name"><?php echo h($name); ?></div>
                          <?php if ($email): ?>
                            <div class="member-sub"><?php echo h($email); ?></div>
                          <?php endif; ?>
                        </div>

                        <div class="member-tags">
                          <?php foreach ($roles as $r): ?>
                            <span class="tag"><?php echo h(role_label($r)); ?></span>
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
                            <?php echo h((string)$u['name']); ?> added to <?php echo h(role_label((string)$u['role'])); ?>
                          </div>
                          <div class="update-sub"><?php echo h((string)$u['created_at']); ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Task overview -->
            <div class="card">
              <div class="card-head">
                <div>
                  <div class="card-head-title">Task overview</div>
                  <div class="card-head-sub">This will populate once the Tasks module is created.</div>
                </div>
                <a class="btn btn-ghost" href="#" onclick="return false;">Filter</a>
              </div>

              <div class="proj-empty" style="min-height:520px;">
                <div class="proj-empty-ico">☑️</div>
                <div class="proj-empty-title">Tasks aren’t set up yet</div>
                <div class="proj-empty-sub">Once you add a tasks table, this list + counts will auto-populate.</div>
              </div>
            </div>

          </div><!-- /team-grid -->

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>