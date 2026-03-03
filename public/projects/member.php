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

// project security
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

// verify member is part of project
$v = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = :pid AND company_member_id = :cmid");
$v->execute([':pid' => $projectId, ':cmid' => $cmid]);
if ((int)$v->fetchColumn() === 0) redirect('projects/members.php?id=' . $projectId);

// member info
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

// roles for this member in this project
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

$pageTitle = $project['title'] . ' — ' . $member['full_name'] . ' — Vidhaan';
require_once $root . '/includes/header.php';

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';
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
              <span class="proj-meta-item">👤 <?php echo h((string)$member['full_name']); ?></span>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h(base_url('projects/members.php?id=' . $projectId)); ?>">Back to members</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="member-head">
            <div class="member-h1"><?php echo h((string)$member['full_name']); ?></div>
            <div class="member-sub">
              <?php echo h($roles ? role_label($roles[0]) : 'Member'); ?>
            </div>
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

              <div class="proj-empty" style="min-height:360px;">
                <div class="proj-empty-ico">☑️</div>
                <div class="proj-empty-title">Tasks aren’t set up yet</div>
                <div class="proj-empty-sub">Once you add a tasks table, this section will show the member’s assigned tasks.</div>
              </div>
            </div>

          </div>

        </div>
      </div>

    </div>
  </section>
</div>

<?php include $root . '/includes/footer.php'; ?>