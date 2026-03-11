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
if (!function_exists('normalize_hosting_side')) {
  function normalize_hosting_side(string $raw): string {
    $v = strtolower(trim($raw));
    $v = str_replace(['’', '`'], ["'", "'"], $v);

    if ($v === '') return '';
    if (in_array($v, ['bride', 'groom', 'collaborative'], true)) return $v;
    if (strpos($v, 'bride') !== false) return 'bride';
    if (strpos($v, 'groom') !== false) return 'groom';
    if (strpos($v, 'collab') !== false) return 'collaborative';

    return '';
  }
}

$SIDE_LABEL = [
  'bride' => "Bride’s side",
  'groom' => "Groom’s side",
  'collaborative' => "Collaborative event",
];

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("
  SELECT *
  FROM projects
  WHERE id = :pid AND company_id = :cid
  LIMIT 1
");
$pstmt->execute([
  ':pid' => $projectId,
  ':cid' => $companyId,
]);
$project = $pstmt->fetch();

if (!$project) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

/* ---------- Search ---------- */
$search = trim((string)($_GET['q'] ?? ''));

/* ---------- Events ---------- */
$sql = "
  SELECT id, name, starts_at, venue, hosting_side
  FROM project_events
  WHERE project_id = :pid
";
$params = [':pid' => $projectId];

if ($search !== '') {
  $sql .= " AND (
    name LIKE :q
    OR venue LIKE :q
    OR hosting_side LIKE :q
  )";
  $params[':q'] = '%' . $search . '%';
}

$sql .= " ORDER BY starts_at ASC, id ASC";

$events = [];
try {
  $es = $pdo->prepare($sql);
  $es->execute($params);
  $events = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $events = [];
}

/* ---------- Needed for project sidebar ---------- */
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
.breadcrumb{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;color:rgba(0,0,0,0.55);}
.breadcrumb a{text-decoration:none;color:rgba(0,0,0,0.55);}
.breadcrumb .sep{opacity:.55;}
.subhead{margin-top:6px;color:var(--muted);font-size:13px;}

.tools-row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-top:14px;
}

.search-form{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}

.search-input{
  width:min(340px, 70vw);
  height:44px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.08);
  background:rgba(0,0,0,0.03);
  padding:0 16px;
  outline:none;
  font-size:14px;
}
.search-input:focus{
  background:#fff;
  border-color:rgba(0,0,0,0.16);
}

.icon-round{
  width:44px;
  height:44px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}

.events-card{
  margin-top:16px;
  border:1px solid rgba(0,0,0,0.06);
  border-radius:24px;
  background:#fff;
  overflow:hidden;
}

.events-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}

.events-table thead th{
  text-align:left;
  font-size:13px;
  font-weight:800;
  color:rgba(0,0,0,0.55);
  padding:18px 20px;
  border-bottom:1px solid rgba(0,0,0,0.06);
}

.events-table tbody td{
  padding:16px 20px;
  vertical-align:middle;
  border-bottom:1px solid rgba(0,0,0,0.05);
}

.events-table tbody tr:last-child td{
  border-bottom:none;
}

.events-table tbody tr.event-row{
  cursor:pointer;
  transition:background 140ms ease;
}

.events-table tbody tr.event-row:hover{
  background:rgba(0,0,0,0.015);
}

.events-table tbody tr.event-row:focus-within,
.events-table tbody tr.event-row.is-focus{
  background:rgba(0,0,0,0.02);
}

.event-name-link{
  text-decoration:none;
  color:inherit;
  font-weight:800;
  font-size:17px;
}

.event-name-link:hover{
  text-decoration:none;
}

.muted-dash{
  color:rgba(0,0,0,0.35);
}

.side-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:38px;
  padding:0 16px;
  border-radius:999px;
  font-size:13px;
  font-weight:700;
  white-space:nowrap;
  border:1px solid transparent;
}

.side-pill.is-empty{
  background:rgba(0,0,0,0.05);
  color:rgba(0,0,0,0.40);
  border-color:rgba(0,0,0,0.06);
}

.side-pill.is-bride{
  background:rgba(75,0,31,0.10);
  color:#4b001f;
  border-color:rgba(75,0,31,0.14);
}

.side-pill.is-groom{
  background:rgba(0,0,0,0.05);
  color:rgba(0,0,0,0.72);
  border-color:rgba(0,0,0,0.08);
}

.side-pill.is-collaborative{
  background:rgba(0,0,0,0.05);
  color:rgba(0,0,0,0.72);
  border-color:rgba(0,0,0,0.08);
}

.action-cell{
  width:52px;
  text-align:right;
}

.row-arrow{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:28px;
  height:28px;
  border-radius:999px;
  text-decoration:none;
  color:rgba(0,0,0,0.45);
  font-size:20px;
}

.row-arrow:hover{
  background:rgba(0,0,0,0.05);
  color:rgba(0,0,0,0.75);
}

.empty-state{
  margin-top:16px;
  padding:18px 20px;
  border:1px solid rgba(0,0,0,0.06);
  border-radius:24px;
  background:#fff;
  color:var(--muted);
}

@media (max-width: 920px){
  .events-table{
    display:block;
    overflow-x:auto;
  }
  .search-input{
    width:100%;
    min-width:240px;
  }
}
</style>

<div class="app-shell">
  <?php $nav_active = 'projects'; require_once $root . '/includes/sidebar.php'; ?>

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
        <?php $active = 'contract'; $contractSection = 'event_details'; require_once $root . '/includes/project_sidebar.php'; ?>

        <div class="proj-main">
          <div class="proj-overview-head">
            <div>
              <div class="breadcrumb">
                <a href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract &amp; scope</a>
                <span class="sep">›</span>
                <span>Event details</span>
              </div>
              <div class="subhead">Create the agreement, define deliverables, and send it for approval.</div>
            </div>
          </div>

          <div class="tools-row">
            <form class="search-form" method="get" action="">
              <input type="hidden" name="id" value="<?php echo h((string)$projectId); ?>">
              <input
                class="search-input"
                type="text"
                name="q"
                value="<?php echo h($search); ?>"
                placeholder="Search event"
              >
              <button class="btn icon-round" type="submit" aria-label="Search">↓</button>
            </form>

            <div>
              <a class="btn btn-primary" href="<?php echo h(base_url('projects/contract_event.php?id=' . $projectId)); ?>">＋ Add event</a>
            </div>
          </div>

          <?php if (empty($events)): ?>
            <div class="empty-state">
              No events yet. Click Add event to create your first event.
            </div>
          <?php else: ?>
            <div class="events-card">
              <table class="events-table">
                <thead>
                  <tr>
                    <th>Event name</th>
                    <th>Event date</th>
                    <th>Venue</th>
                    <th>Hosting side</th>
                    <th class="action-cell"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($events as $ev): ?>
                    <?php
                      $eid = (int)($ev['id'] ?? 0);
                      $editHref = base_url('projects/contract_event.php?id=' . $projectId . '&eid=' . $eid);

                      $eventName = trim((string)($ev['name'] ?? 'Untitled event'));
                      if ($eventName === '') $eventName = 'Untitled event';

                      $eventDate = !empty($ev['starts_at']) ? date('d/m/Y', strtotime((string)$ev['starts_at'])) : '—';

                      $venueText = trim((string)($ev['venue'] ?? ''));
                      if ($venueText === '') $venueText = '—';

                      $sideKey = normalize_hosting_side((string)($ev['hosting_side'] ?? ''));
                      $sideLabel = $sideKey !== '' ? ($SIDE_LABEL[$sideKey] ?? '—') : '—';
                      $sideClass = $sideKey !== '' ? (' is-' . $sideKey) : ' is-empty';
                    ?>
                    <tr class="event-row" data-href="<?php echo h($editHref); ?>" tabindex="0">
                      <td>
                        <a class="event-name-link" href="<?php echo h($editHref); ?>">
                          <?php echo h($eventName); ?>
                        </a>
                      </td>
                      <td><?php echo h($eventDate); ?></td>
                      <td>
                        <?php if ($venueText === '—'): ?>
                          <span class="muted-dash">—</span>
                        <?php else: ?>
                          <?php echo h($venueText); ?>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="side-pill<?php echo h($sideClass); ?>">
                          <?php echo h($sideLabel); ?>
                        </span>
                      </td>
                      <td class="action-cell">
                        <a class="row-arrow" href="<?php echo h($editHref); ?>" aria-label="Edit event">›</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  const rows = Array.from(document.querySelectorAll('.event-row[data-href]'));
  if (!rows.length) return;

  function go(row) {
    const href = row.getAttribute('data-href');
    if (href) window.location.href = href;
  }

  rows.forEach((row) => {
    row.addEventListener('click', (e) => {
      const target = e.target;
      if (target.closest('a, button, input, select, textarea, label')) return;
      go(row);
    });

    row.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        go(row);
      }
    });

    row.addEventListener('focus', () => row.classList.add('is-focus'));
    row.addEventListener('blur', () => row.classList.remove('is-focus'));
  });
})();
</script>

<?php require_once $root . '/includes/footer.php'; ?>

