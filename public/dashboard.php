<?php
// public/dashboard.php

$root = __DIR__;
while (!is_dir($root . '/includes') && $root !== dirname($root)) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$companyId = current_company_id();

// Projects summary
$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM projects WHERE company_id = :cid");
$countStmt->execute([':cid' => $companyId]);
$totalProjects = (int)($countStmt->fetch()['c'] ?? 0);

$projStmt = $pdo->prepare(
  "SELECT id, title, event_type, guest_count_est, status, created_at
   FROM projects
   WHERE company_id = :cid
   ORDER BY created_at DESC
   LIMIT 6"
);
$projStmt->execute([':cid' => $companyId]);
$projects = $projStmt->fetchAll();

$pageTitle = 'Dashboard — Vidhaan';
require_once $root . '/includes/header.php';

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';
?>

<div class="app-shell">

  <!-- LEFT SIDEBAR -->
  <?php
    $nav_active = 'dashboard';
    require_once $root . '/includes/sidebar.php';
  ?>

  <!-- RIGHT SIDE -->
  <section class="app-main">

    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="page-head">
        <div class="page-title">
          <div class="dash-icon">▦</div>
          <div>
            <h1>Dashboard</h1>
            <p>Your team’s workspace — projects, tasks, and timelines.</p>
          </div>
        </div>

        <div class="actions">
          <a class="btn btn-primary" href="<?php echo h(base_url('projects/create.php')); ?>">＋ Create new project</a>
          <a class="btn" href="<?php echo h(base_url('company/index.php#add-member')); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h(base_url('company/dashboard.php')); ?>" title="Settings">⚙</a>
        </div>
      </div>

      <div class="stats">
        <div class="card stat">
          <div class="num"><?php echo h((string)$totalProjects); ?></div>
          <div><div class="label">Total projects</div></div>
        </div>
        <div class="card stat">
          <div class="num">0</div>
          <div><div class="label">My tasks</div></div>
        </div>
        <div class="card stat">
          <div class="num">0</div>
          <div><div class="label">In progress tasks</div></div>
        </div>
        <div class="card stat">
          <div class="num">0</div>
          <div><div class="label">Completed tasks</div></div>
        </div>
      </div>

      <div class="grid-2">

        <!-- LEFT: Ongoing projects -->
        <div class="card">
          <div class="card-head">
            <div class="card-head-left">
              <div>📁</div>
              <div>
                <div class="card-head-title">On going projects</div>
                <div class="card-head-sub">Jump back into what’s active.</div>
              </div>
            </div>

            <a class="btn" href="<?php echo h(base_url('projects/index.php')); ?>">View all</a>
          </div>

          <?php if (!$projects): ?>
            <div class="empty mt-10">
              <div>
                <div class="icon-26">📁</div>
                <div class="big"><strong>No projects yet</strong></div>
                <div class="small">Create your first project to start organizing events, contracts, and teams.</div>
                <div class="mt-12">
                  <a class="btn btn-primary" href="<?php echo h(base_url('projects/create.php')); ?>">Create a project</a>
                </div>
              </div>
            </div>
          <?php else: ?>

            <div class="project-cards">
              <?php foreach ($projects as $p): ?>
                <?php
                  $status = (string)($p['status'] ?? 'active');

                  $progressClass = 'w-30';
                  if ($status === 'in_progress') $progressClass = 'w-60';
                  if ($status === 'completed') $progressClass = 'w-100';

                  $created = (string)($p['created_at'] ?? '');
                  $createdLabel = $created ? date('d M Y', strtotime($created)) : '';
                ?>

                <!-- ONE card only (no nested cards) -->
                <div class="project-card">
                  <!-- Full-card clickable overlay -->
                  <a
                    class="card-hit"
                    href="<?php echo h(base_url('projects/show.php?id=' . (int)$p['id'])); ?>"
                    aria-label="<?php echo h('Open project: ' . (string)$p['title']); ?>"
                  ></a>

                  <div class="project-card-top">
                    <div><?php echo h($createdLabel); ?></div>
                    <span class="pill"><?php echo h(ucfirst(str_replace('_', ' ', $status))); ?></span>
                  </div>

                  <!-- Title is plain text -->
                  <div class="project-title">
                    <?php echo h((string)$p['title']); ?>
                  </div>

                  <div class="mt-10">
                    <div class="progress">
                      <div class="<?php echo h($progressClass); ?>"></div>
                    </div>
                  </div>

                  <div class="project-meta">
                    <div>
                      <div>Event type</div>
                      <strong><?php echo h((string)($p['event_type'] ?? '—')); ?></strong>
                    </div>

                    <div class="text-right">
                      <div>Guests</div>
                      <strong><?php echo h((string)($p['guest_count_est'] ?? '—')); ?></strong>
                    </div>
                  </div>
                </div>

              <?php endforeach; ?>
            </div>

          <?php endif; ?>
        </div>

        <!-- RIGHT: Task list empty state -->
        <div class="card">
          <div class="card-head card-head--single">
            <div class="card-head-left">
              <div>≡</div>
              <div class="card-head-title">Task list</div>
            </div>
          </div>

          <div class="empty empty-tall">
            <div>
              <div class="icon-26">📄</div>
              <div class="big"><strong>No tasks created!</strong></div>
              <div class="small">Get started by creating a new task.</div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>