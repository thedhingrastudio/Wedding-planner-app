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

$pageTitle = $project['title'] . ' — Members — Vidhaan';
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
    'coordination' => 'Coordination',
    'rsvp' => 'RSVP',
    'hospitality' => 'Hospitality',
    'transport' => 'Transport',
    'vendor' => 'Vendor',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Options used for dropdown filter
$roleOptions = [
  'team_lead' => role_label('team_lead'),
  'coordination' => role_label('coordination'),
  'rsvp' => role_label('rsvp'),
  'hospitality' => role_label('hospitality'),
  'transport' => role_label('transport'),
  'vendor' => role_label('vendor'),
];

// ✅ Stable “project members + roles” query
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

$membersCount = count($members);

// tasks not built yet — keep as 0 for now
$openTasks = 0;
$dueSoon = 0;
$overdue = 0;
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
              <div class="proj-h2">Members</div>
              <div class="proj-sub">All active members assigned to this project, plus their departments and task load.</div>
              <div class="proj-sub" style="margin-top:6px;">
                <span style="color:var(--muted); font-size:12px;">Task counts will populate once Tasks are enabled.</span>
              </div>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <!-- Use a page-specific selector to avoid conflicts -->
              <input class="proj-search-input" placeholder="Search team member" data-members-search />
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

          <!-- Main table only -->
          <div class="card members-main">

            <div class="members-table-head">
              <div>Member</div>

              <!-- ✅ Role filter dropdown in header -->
              <div class="members-th-role">
                <span>Roles</span>
                <select class="members-role-filter" data-role-filter>
                  <option value="">All</option>
                  <?php foreach ($roleOptions as $key => $label): ?>
                    <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="members-center">Open tasks</div>
              <div class="members-center">Due tasks</div>
              <div></div>
            </div>

            <?php if (!$members): ?>
              <div class="proj-empty" style="min-height:360px;">
                <div class="proj-empty-ico">👥</div>
                <div class="proj-empty-title">No members assigned yet</div>
                <div class="proj-empty-sub">Add members to see them listed here.</div>
              </div>
            <?php else: ?>
              <div class="members-table-body">
                <?php foreach ($members as $m): ?>
                  <?php
                    $name = (string)($m['full_name'] ?? 'Member');
                    $email = (string)($m['email'] ?? '');
                    $rolesCsv = (string)($m['roles'] ?? '');
                    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));

                    // tasks placeholder
                    $open = 0;
                    $due = 0;

                    // Used for filtering/search
                    $hay = strtolower($name . ' ' . $email . ' ' . $rolesCsv);
                    $rolesForAttr = strtolower(implode(',', $roles));
                  ?>
                  <div class="members-row"
                       data-members-row
                       data-hay="<?php echo h($hay); ?>"
                       data-roles="<?php echo h($rolesForAttr); ?>">

                    <div class="members-cell members-name"><?php echo h($name); ?></div>

                    <!-- ✅ Multiple roles as chips -->
                    <div class="members-cell members-role">
                      <div class="members-role-chips">
                        <?php if (!$roles): ?>
                          <span style="color:var(--muted); font-size:12px;">—</span>
                        <?php else: ?>
                          <?php foreach ($roles as $r): ?>
                            <span class="tag tag--sm"><?php echo h(role_label($r)); ?></span>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="members-cell members-center">
                      <span class="task-pill task-pill--open"><?php echo h((string)$open); ?> open tasks</span>
                    </div>

                    <div class="members-cell members-center">
                      <span class="task-pill task-pill--due"><?php echo h((string)$due); ?> due tasks</span>
                    </div>

                    <div class="members-cell members-arrow">›</div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

          </div>

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const roleFilter = document.querySelector("[data-role-filter]");
  const searchInput = document.querySelector("[data-members-search]");
  const rows = Array.from(document.querySelectorAll("[data-members-row]"));

  const applyFilters = () => {
    const role = (roleFilter?.value || "").trim().toLowerCase();
    const q = (searchInput?.value || "").trim().toLowerCase();

    rows.forEach((row) => {
      const hay = (row.getAttribute("data-hay") || "").toLowerCase();
      const roles = (row.getAttribute("data-roles") || "").toLowerCase();

      const matchSearch = !q || hay.includes(q);
      const matchRole = !role || roles.split(",").includes(role);

      row.style.display = (matchSearch && matchRole) ? "" : "none";
    });
  };

  roleFilter?.addEventListener("change", applyFilters);
  searchInput?.addEventListener("input", applyFilters);

  applyFilters();
});
</script>

<?php include $root . '/includes/footer.php'; ?>