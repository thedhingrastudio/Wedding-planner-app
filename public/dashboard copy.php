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

<!-- (Optional safety) If CSS isn't loading for some reason, this forces it for THIS page only -->
<link rel="stylesheet" href="<?php echo h(base_url('css/main.css')); ?>">

<style>
  /* Page-only styling so we don't touch main.css */
  .project-cards{
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-top: 14px;
  }
  .project-card{
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px;
  }
  .project-card-top{
    display:flex;
    justify-content: space-between;
    align-items:center;
    gap: 10px;
    color: var(--muted);
    font-size: 12px;
  }
  .project-title{
    margin-top: 10px;
    font-size: 20px;
    font-weight: 750;
    line-height: 1.1;
  }
  .project-title a{ color: inherit; text-decoration: none; }
  .project-title a:hover{ text-decoration: underline; }
  .project-meta{
    display:flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 10px;
    color: var(--muted);
    font-size: 12px;
  }
  .project-meta strong{ color: var(--text); font-weight: 650; }

  @media (max-width: 1100px){
    .grid-2{ grid-template-columns: 1fr; }
    .project-cards{ grid-template-columns: 1fr; }
  }
  @media (max-width: 900px){
    .sidebar{ display:none; }
    .surface{ margin: 0 22px 22px 22px; }
  }
</style>

<div class="app-shell">

  <!-- LEFT SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="burger">≡</div>
      <div class="brand-name">Vidhaan</div>
    </div>

    <div class="nav-section">
      <div class="nav-label">Workspace</div>

      <a class="nav-item active" href="<?php echo h(base_url('dashboard.php')); ?>">
        <div>
          <div class="nav-title">Dashboard</div>
          <div class="nav-sub">Overview of your assigned weddings and priorities</div>
        </div>
        <div class="nav-arrow">›</div>
      </a>

      <a class="nav-item" href="<?php echo h(base_url('projects/index.php')); ?>">
        <div>
          <div class="nav-title">Your projects</div>
          <div class="nav-sub">All weddings you’re working on</div>
        </div>
        <div class="nav-arrow">›</div>
      </a>

      <a class="nav-item" href="<?php echo h(base_url('tasks/index.php')); ?>">
        <div>
          <div class="nav-title">My tasks</div>
          <div class="nav-sub">Tasks assigned to you across projects</div>
        </div>
        <div class="nav-arrow">›</div>
      </a>
    </div>

    <div class="nav-section">
      <div class="nav-label">Organization</div>

      <a class="nav-link" href="<?php echo h(base_url('company/dashboard.php')); ?>">Your team</a>
      <a class="nav-link" href="<?php echo h(base_url('company/dashboard.php')); ?>">Your organization</a>
    </div>

    <div class="nav-section">
      <div class="nav-label">Modules</div>
      <a class="nav-link" href="<?php echo h(base_url('contracts/index.php')); ?>">Contracts</a>
      <a class="nav-link" href="<?php echo h(base_url('guests/index.php')); ?>">Guests</a>
      <a class="nav-link" href="<?php echo h(base_url('invites/index.php')); ?>">Invites</a>
      <a class="nav-link" href="<?php echo h(base_url('transport/index.php')); ?>">Transport</a>
      <a class="nav-link" href="<?php echo h(base_url('hospitality/index.php')); ?>">Hospitality</a>
      <a class="nav-link" href="<?php echo h(base_url('updates/index.php')); ?>">Updates</a>
    </div>

    <div class="nav-section">
      <div class="nav-label">Support</div>
      <a class="nav-link" href="#">Help and support</a>
      <a class="nav-link" href="#">Privacy policy</a>
    </div>
  </aside>

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
          <div style="font-size:22px;">▦</div>
          <div>
            <h1>Dashboard</h1>
            <p>Your team’s workspace — projects, tasks, and timelines.</p>
          </div>
        </div>

        <div class="actions">
          <a class="btn btn-primary" href="<?php echo h(base_url('projects/create.php')); ?>">＋ Create new project</a>
          <a class="btn" href="<?php echo h(base_url('company/dashboard.php')); ?>">＋ Add member</a>
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
          <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
              <div>📁</div>
              <div>
                <div style="font-weight:750;">On going projects</div>
                <div style="color:var(--muted); font-size:13px; margin-top:4px;">Jump back into what’s active.</div>
              </div>
            </div>
            <a class="btn" href="<?php echo h(base_url('projects/index.php')); ?>">View all</a>
          </div>

          <?php if (!$projects): ?>
            <div class="empty" style="margin-top: 10px;">
              <div>
                <div style="font-size:26px;">📁</div>
                <div class="big"><strong>No projects yet</strong></div>
                <div class="small">Create your first project to start organizing events, contracts, and teams.</div>
                <div style="margin-top:12px;">
                  <a class="btn btn-primary" href="<?php echo h(base_url('projects/create.php')); ?>">Create a project</a>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="project-cards">
              <?php foreach (array_slice($projects, 0, 4) as $p): ?>
                <?php
                  $status = (string)($p['status'] ?? 'active');
                  $progress = 30;
                  if ($status === 'completed') $progress = 100;
                  if ($status === 'in_progress') $progress = 60;

                  $created = (string)($p['created_at'] ?? '');
                  $createdLabel = $created ? date('d M Y', strtotime($created)) : '';
                ?>
                <div class="project-card">
                  <div class="project-card-top">
                    <div><?php echo h($createdLabel); ?></div>
                    <span class="pill"><?php echo h(ucfirst(str_replace('_', ' ', $status))); ?></span>
                  </div>

                  <div class="project-title">
                    <a href="<?php echo h(base_url('projects/show.php?id=' . (int)$p['id'])); ?>">
                      <?php echo h((string)$p['title']); ?>
                    </a>
                  </div>

                  <div style="margin-top:10px;">
                    <div class="progress"><div style="width: <?php echo (int)$progress; ?>%;"></div></div>
                  </div>

                  <div class="project-meta">
                    <div>
                      <div>Event type</div>
                      <strong><?php echo h((string)($p['event_type'] ?? '')); ?></strong>
                    </div>
                    <div style="text-align:right;">
                      <div>Guests</div>
                      <strong><?php echo h((string)($p['guest_count_est'] ?? '—')); ?></strong>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- RIGHT: Task list empty state (matches reference) -->
        <div class="card">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
              <div>≡</div>
              <div style="font-weight:750;">Task list</div>
            </div>
          </div>

          <div class="empty" style="min-height: 360px;">
            <div>
              <div style="font-size:26px;">📄</div>
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