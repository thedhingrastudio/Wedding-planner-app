<?php
// includes/sidebar.php
$nav_active = $nav_active ?? '';

function nav_active($key, $nav_active) {
  return $nav_active === $key ? 'active' : '';
}
?>

<aside class="sidebar" data-sidebar>
  <div class="sidebar-brand">
    <button class="burger" type="button" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="true">≡</button>
    <div class="brand-name">Vidhaan</div>
  </div>

  <!-- Workspace -->
  <div class="nav-section">
    <div class="nav-label">Workspace</div>

    <a class="nav-item <?= nav_active('dashboard', $nav_active) ?>" href="<?= h(base_url('dashboard.php')) ?>">
      <span class="nav-ico">▦</span>
      <div class="nav-text">
        <div class="nav-title">Dashboard</div>
        <div class="nav-sub">Overview of your assigned weddings and priorities</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>

    <a class="nav-item <?= nav_active('projects', $nav_active) ?>" href="<?= h(base_url('projects/index.php')) ?>">
      <span class="nav-ico">📁</span>
      <div class="nav-text">
        <div class="nav-title">Your projects</div>
        <div class="nav-sub">All weddings you’re working on</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>

    <a class="nav-item <?= nav_active('tasks', $nav_active) ?>" href="<?= h(base_url('tasks/index.php')) ?>">
      <span class="nav-ico">✅</span>
      <div class="nav-text">
        <div class="nav-title">My tasks</div>
        <div class="nav-sub">Tasks assigned to you across projects</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>
  </div>

  <!-- Organization -->
  <div class="nav-section">
    <div class="nav-label">Organization</div>

    <a class="nav-item <?= nav_active('team', $nav_active) ?>" href="<?= h(base_url('company/dashboard.php')) ?>">
      <span class="nav-ico">👥</span>
      <div class="nav-text">
        <div class="nav-title">Your team</div>
        <div class="nav-sub">People collaborating on your projects</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>

    <a class="nav-item <?= nav_active('org', $nav_active) ?>" href="<?= h(base_url('company/dashboard.php')) ?>">
      <span class="nav-ico">🏢</span>
      <div class="nav-text">
        <div class="nav-title">Your organization</div>
        <div class="nav-sub">Manage your organization, roles, and access</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>
  </div>

  <!-- Support -->
  <div class="nav-section">
    <div class="nav-label">Support</div>

    <a class="nav-item <?= nav_active('support', $nav_active) ?>" href="#">
      <span class="nav-ico">?</span>
      <div class="nav-text">
        <div class="nav-title">Help and support</div>
        <div class="nav-sub">Guides, FAQs, and contact support</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>

    <a class="nav-item <?= nav_active('privacy', $nav_active) ?>" href="#">
      <span class="nav-ico">🔒</span>
      <div class="nav-text">
        <div class="nav-title">Privacy policy</div>
        <div class="nav-sub">How we protect your data</div>
      </div>
      <div class="nav-arrow">›</div>
    </a>
  </div>
</aside>