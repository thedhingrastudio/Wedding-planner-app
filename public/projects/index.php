<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pageTitle = 'Your projects — Vidhaan';
require_once $root . '/includes/header.php';

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$companyId = current_company_id();

$stmt = $pdo->prepare("SELECT * FROM projects WHERE company_id = :cid ORDER BY created_at DESC");
$stmt->execute([':cid' => $companyId]);
$projects = $stmt->fetchAll();

// UI-only: progress bar width based on status (same as dashboard pattern)
function progress_class(string $status): string {
  return match ($status) {
    'completed' => 'w-100',
    'in_progress' => 'w-60',
    default => 'w-30',
  };
}
?>

<div class="app-shell">

  <!-- LEFT SIDEBAR -->
  <?php
    $nav_active = 'projects';
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
          <div class="dash-icon">📁</div>
          <div>
            <h1>Your projects</h1>
            <p>All weddings you’re working on.</p>
          </div>
        </div>

        <div class="actions">
          <a class="btn btn-primary" href="<?= h(base_url('projects/create.php')) ?>">＋ Create new project</a>
        </div>
      </div>

      <?php if (!$projects): ?>
        <div class="card">
          <div class="empty" style="min-height:220px;">
            <div>
              <div class="icon-26">📁</div>
              <div class="big"><strong>No projects yet</strong></div>
              <div class="small">Create your first project to get started.</div>
              <div class="mt-12">
                <a class="btn btn-primary" href="<?= h(base_url('projects/create.php')) ?>">Create new project</a>
              </div>
            </div>
          </div>
        </div>
      <?php else: ?>

        <div class="projects-scroll">
          <div class="projects-grid">

            <?php foreach ($projects as $p): ?>
              <?php
                $status = (string)($p['status'] ?? 'active');
                $created = (string)($p['created_at'] ?? '');
                $createdLabel = $created ? date('d M Y', strtotime($created)) : '';
                $progressClass = progress_class($status);
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

                <!-- Title is plain text (avoid nested <a>) -->
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
        </div>

      <?php endif; ?>

    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>