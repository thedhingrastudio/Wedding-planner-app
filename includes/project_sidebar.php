<?php
// includes/project_sidebar.php
// Requires: $projectId (int), optional: $daysToGo (int|null)

$active = $active ?? 'overview';
?>

<aside class="project-nav project-nav--v2 project-nav--ref" aria-label="Project navigation">

  <!-- Top: 2 standalone cards -->
  <div class="project-nav-stack">

    <a class="project-nav-item project-nav-item--card <?php echo $active === 'overview' ? 'is-active' : ''; ?>"
       href="<?= h(base_url('projects/show.php?id=' . (int)$projectId)) ?>"
       <?php echo $active === 'overview' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">📅</div>
      <div class="project-nav-text">
        <div class="project-nav-title">
          <?= $daysToGo !== null ? h($daysToGo . ' days to go') : 'Project overview' ?>
        </div>
        <div class="project-nav-sub">Countdown to the big day!</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

    <a class="project-nav-item project-nav-item--card <?php echo $active === 'team' ? 'is-active' : ''; ?>"
   href="<?= h(base_url('projects/team.php?id=' . (int)$projectId)) ?>"
   <?php echo $active === 'team' ? 'aria-current="page"' : ''; ?>>
  <div class="project-nav-ico" aria-hidden="true">👥</div>
  <div class="project-nav-text">
    <div class="project-nav-title">The team</div>
    <div class="project-nav-sub">
      <?php if (isset($teamCount)): ?>
        <?= h((string)(int)$teamCount) ?> active members
      <?php else: ?>
        View assigned members
      <?php endif; ?>
    </div>
  </div>
  <div class="project-nav-arrow" aria-hidden="true">›</div>
</a>

  </div>

  <!-- Bottom: one grouped card with divider lines -->
  <div class="project-nav-group" role="list">

    <a class="project-nav-item project-nav-item--row <?php echo $active === 'contract' ? 'is-active' : ''; ?>"
       href="<?= h(base_url('projects/contract.php?id=' . (int)$projectId)) ?>"
       role="listitem"
       <?php echo $active === 'contract' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">📄</div>
      <div class="project-nav-text">
        <div class="project-nav-title">Contract &amp; scope</div>
        <div class="project-nav-sub">Set the agreement, deliverables and key terms before planning begins</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

    <a class="project-nav-item project-nav-item--row <?php echo $active === 'guests' ? 'is-active' : ''; ?>"
       href="#"
       role="listitem"
       <?php echo $active === 'guests' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">🧑‍🤝‍🧑</div>
      <div class="project-nav-text">
        <div class="project-nav-title">Guest list setup</div>
        <div class="project-nav-sub">Build the master guest list and organize it for invites and logistics</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

    <a class="project-nav-item project-nav-item--row <?php echo $active === 'rsvp' ? 'is-active' : ''; ?>"
       href="#"
       role="listitem"
       <?php echo $active === 'rsvp' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">✉️</div>
      <div class="project-nav-text">
        <div class="project-nav-title">Invite &amp; RSVP</div>
        <div class="project-nav-sub">Send invitations, track responses, and follow up on pending guests</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

    <a class="project-nav-item project-nav-item--row <?php echo $active === 'travel' ? 'is-active' : ''; ?>"
       href="#"
       role="listitem"
       <?php echo $active === 'travel' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">✈️</div>
      <div class="project-nav-text">
        <div class="project-nav-title">Travel and transport</div>
        <div class="project-nav-sub">Plan airport pickups, drops and schedules based on guest travel details</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

    <a class="project-nav-item project-nav-item--row <?php echo $active === 'hospitality' ? 'is-active' : ''; ?>"
       href="#"
       role="listitem"
       <?php echo $active === 'hospitality' ? 'aria-current="page"' : ''; ?>>
      <div class="project-nav-ico" aria-hidden="true">🏨</div>
      <div class="project-nav-text">
        <div class="project-nav-title">Hotel and hospitality</div>
        <div class="project-nav-sub">Coordinate rooms, check-ins and guest support during their stay</div>
      </div>
      <div class="project-nav-arrow" aria-hidden="true">›</div>
    </a>

  </div>

</aside>