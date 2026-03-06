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

/* ---------- Helpers ---------- */
function has_col(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
    $st->execute([':c' => $col]);
    return (bool)$st->fetch();
  } catch (Throwable $e) {
    return false;
  }
}

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$pstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

/* ---------- Columns available ---------- */
$hasVenue = has_col($pdo, 'project_events', 'venue');
$hasSide  = has_col($pdo, 'project_events', 'hosting_side');

/* ---------- Events ---------- */
$select = "id, name, starts_at";
if ($hasVenue) $select .= ", venue";
if ($hasSide)  $select .= ", hosting_side";

$events = [];
try {
  $es = $pdo->prepare("SELECT $select FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC, id ASC");
  $es->execute([':pid' => $projectId]);
  $events = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $events = [];
}

/* ---------- Needed for project sidebar (avoids warnings) ---------- */
$projectDateLabel = 'Date TBD';
$daysToGo = null;

if (!empty($events) && !empty($events[0]['starts_at'])) {
  $projectDateLabel = date('F j, Y', strtotime((string)$events[0]['starts_at']));
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$events[0]['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') $projectDateLabel = date('F j, Y', strtotime($createdAt));
}

$pageTitle = (string)($project['title'] ?? 'Project') . ' — Event details';
require_once $root . '/includes/header.php';
?>

<style>
/* Breadcrumb */
.breadcrumb{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:800;
}
.breadcrumb a{ text-decoration:none; color:inherit; }
.breadcrumb .sep{ opacity:.5; }

.subhead{
  margin-top: 6px;
  color: var(--muted);
  font-size: 13px;
}

.events-toolbar{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:center;
  margin-top: 10px;
}

.search-wrap{
  flex: 1;
  max-width: 360px;
}
.search-wrap input{
  width: 100%;
  padding: 10px 12px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: #fff;
  font-size: 13px;
}

.toolbar-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}

.icon-btn{
  width: 38px;
  height: 38px;
  border-radius: 999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}

/* Table */
.events-card{ margin-top: 14px; }

.events-table{
  width: 100%;
  border-collapse: collapse;
}
.events-table th{
  text-align:left;
  font-size: 12px;
  color: var(--muted);
  font-weight: 700;
  padding: 12px 10px;
  border-bottom: 1px solid rgba(0,0,0,0.06);
}
.events-table td{
  padding: 14px 10px;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  vertical-align: middle;
  font-size: 14px;
}

/* Clickable rows */
.eventRow.is-clickable{ cursor: pointer; }
.eventRow.is-clickable:hover{
  background: rgba(0,0,0,0.02);
}

/* Hosting side pill */
.pill{
  display:inline-flex;
  align-items:center;
  padding: 8px 12px;
  border-radius: 999px;
  font-size: 12px;
  border: 1px solid rgba(0,0,0,0.06);
  background: rgba(0,0,0,0.05);
}
.pill.bride{ background: rgba(210, 160, 255, 0.35); }
.pill.groom{ background: rgba(120, 200, 255, 0.35); }
.pill.collab{ background: rgba(0,0,0,0.06); }

.row-title{
  font-weight: 800;
  color: #111;
}

.row-chevron{
  opacity: .45;
  text-align:right;
}

.empty{
  padding: 18px;
  color: var(--muted);
  font-size: 13px;
}

@media (max-width: 1000px){
  .events-toolbar{ flex-direction: column; align-items: stretch; }
  .search-wrap{ max-width: none; }
}
</style>

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
      <!-- Project header -->
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon"></div>
          <div>
            <div class="proj-name"><?php echo h((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item"><?php echo h($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo h(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h(base_url('projects/show.php?id=' . $projectId)); ?>" title="Project overview">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'contract';
          $contractSection = 'event_details';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="proj-overview-head">
            <div>
              <div class="breadcrumb">
                <a href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract &amp; scope</a>
                <span class="sep">›</span>
                <span>Event details</span>
              </div>
              <div class="subhead">Create the agreement, define deliverables, and send it for approval.</div>

              <div class="events-toolbar">
                <div class="search-wrap">
                  <input id="eventSearch" type="text" placeholder="Search event" />
                </div>

                <div class="toolbar-actions">
                  <button class="btn icon-btn" type="button" title="Download">⬇</button>
                  <!-- ✅ Add event opens the SAME page used for editing (no eid) -->
                  <a class="btn btn-primary" href="<?php echo h(base_url('projects/contract_event.php?id=' . $projectId)); ?>">＋ Add event</a>
                </div>
              </div>
            </div>
          </div>

          <div class="card proj-card events-card">
            <?php if (!$events): ?>
              <div class="empty">
                No events yet. Click <strong>Add event</strong> to create your first event.
              </div>
            <?php else: ?>
              <table class="events-table" id="eventsTable">
                <thead>
                  <tr>
                    <th style="width: 32%;">Event name</th>
                    <th style="width: 18%;">Event date</th>
                    <th style="width: 28%;">Venue</th>
                    <th style="width: 16%;">Hosting side</th>
                    <th style="width: 6%;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($events as $e): ?>
                    <?php
                      $eid = (int)($e['id'] ?? 0);
                      $name = trim((string)($e['name'] ?? 'Event'));
                      $startsAt = (string)($e['starts_at'] ?? '');
                      $dateLabel = $startsAt ? date('d/m/Y', strtotime($startsAt)) : '—';

                      $venue = $hasVenue ? trim((string)($e['venue'] ?? '')) : '';
                      $venueLabel = $venue !== '' ? $venue : '—';

                      $side = $hasSide ? trim((string)($e['hosting_side'] ?? '')) : '';
                      $sideLabel = $side !== '' ? $side : '—';

                      $sideKey = 'collab';
                      $s = strtolower($sideLabel);
                      if (strpos($s, 'bride') !== false) $sideKey = 'bride';
                      else if (strpos($s, 'groom') !== false) $sideKey = 'groom';

                      $href = base_url('projects/contract_event.php?id=' . $projectId . '&eid=' . $eid);
                    ?>
                    <!-- ✅ Entire row clickable -->
                    <tr class="eventRow is-clickable" data-href="<?php echo h($href); ?>">
                      <td class="row-title"><?php echo h($name); ?></td>
                      <td><?php echo h($dateLabel); ?></td>
                      <td><?php echo h($venueLabel); ?></td>
                      <td><span class="pill <?php echo h($sideKey); ?>"><?php echo h($sideLabel); ?></span></td>
                      <td class="row-chevron">›</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </div>
  </section>
</div>

<script>
(function(){
  // Row click → edit event
  document.querySelectorAll('.eventRow.is-clickable').forEach((row) => {
    row.addEventListener('click', (e) => {
      const href = row.getAttribute('data-href');
      if (!href) return;
      window.location.href = href;
    });
  });

  // Search filter
  const search = document.getElementById('eventSearch');
  search?.addEventListener('input', () => {
    const q = (search.value || '').toLowerCase().trim();
    document.querySelectorAll('.eventRow').forEach((row) => {
      const txt = (row.querySelector('.row-title')?.textContent || '').toLowerCase();
      row.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<?php require_once $root . '/includes/footer.php'; ?>