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

/* ---------- helpers (DON'T use h0 here; it exists in project_sidebar.php) ---------- */
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmt_dt(?string $ts, string $format): string {
  $ts = trim((string)$ts);
  if ($ts === '') return '—';
  $t = strtotime($ts);
  if (!$t) return '—';
  return date($format, $t);
}

/**
 * Safely run scalar queries even if table/column doesn't exist.
 * Returns null if query fails.
 */
function safe_fetch_scalar(PDO $pdo, string $sql, array $params): ?string {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $val = $st->fetchColumn();
    $val = is_string($val) ? trim($val) : (string)$val;
    return $val !== '' ? $val : null;
  } catch (Throwable $e) {
    return null;
  }
}

function max_timestamp(array $candidates): ?string {
  $best = null;
  $bestT = null;
  foreach ($candidates as $c) {
    $c = trim((string)$c);
    if ($c === '') continue;
    $t = strtotime($c);
    if (!$t) continue;
    if ($bestT === null || $t > $bestT) {
      $bestT = $t;
      $best = $c;
    }
  }
  return $best;
}

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$pstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

/* ---------- Top meta ---------- */
$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$firstEvent = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $firstEvent = $evt->fetch();
} catch (Throwable $e) {}

$daysToGo = null;
$topDateLabel = 'Date TBD';

if ($firstEvent && !empty($firstEvent['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$firstEvent['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  $topDateLabel = date('F j, Y', strtotime((string)$firstEvent['starts_at']));
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') $topDateLabel = date('F j, Y', strtotime($createdAt));
}

/* ---------- Team count (optional, for sidebar copy) ---------- */
$teamCount = 0;
try {
  $tc = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = :pid");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) { $teamCount = 0; }

/* ---------- Contract row (optional) ---------- */
$contract = null;
try {
  $cs = $pdo->prepare("SELECT status, version_label, updated_at FROM contracts WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
  $cs->execute([':pid' => $projectId]);
  $contract = $cs->fetch() ?: null;
} catch (Throwable $e) {
  $contract = null;
}

$contractStatusRaw = strtolower(trim((string)($contract['status'] ?? 'draft')));
$contractStatusLabel = $contractStatusRaw !== '' ? ucfirst(str_replace('_',' ', $contractStatusRaw)) : 'Draft';
$versionLabel = trim((string)($contract['version_label'] ?? 'v0.1'));
$versionBadge = $contractStatusLabel . ' • ' . ($versionLabel !== '' ? $versionLabel : 'v0.1');

/* ---------- Contract status card values ---------- */
$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));

$clientName = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ($partner2 !== '' ? ' and ' . $partner2 : ''));

// Vendor = company name (best effort)
$vendorName = 'Your company';
try {
  $vn = $pdo->prepare("SELECT name FROM companies WHERE id = :cid LIMIT 1");
  $vn->execute([':cid' => $companyId]);
  $tmp = trim((string)($vn->fetchColumn() ?? ''));
  if ($tmp !== '') $vendorName = $tmp;
} catch (Throwable $e) {}

// Prepared by
$preparedBy = trim((string)($_SESSION['full_name'] ?? ''));
if ($preparedBy === '') $preparedBy = 'You';

// Target sign-off = event date closest to today (fallback = first event)
$signOffTs = null;
try {
  $closest = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid AND starts_at IS NOT NULL
    ORDER BY ABS(DATEDIFF(DATE(starts_at), CURDATE())) ASC, starts_at ASC
    LIMIT 1
  ");
  $closest->execute([':pid' => $projectId]);
  $row = $closest->fetch();
  if ($row && !empty($row['starts_at'])) $signOffTs = (string)$row['starts_at'];
} catch (Throwable $e) {}

if ($signOffTs === null && $firstEvent && !empty($firstEvent['starts_at'])) {
  $signOffTs = (string)$firstEvent['starts_at'];
}
$signOffLabel = fmt_dt($signOffTs, 'd/m/Y');

// Last updated on (best effort across project + contract + events + tasks + members)
$tsCandidates = [];
$tsCandidates[] = (string)($project['updated_at'] ?? '');
$tsCandidates[] = (string)($project['created_at'] ?? '');
if ($contract && !empty($contract['updated_at'])) $tsCandidates[] = (string)$contract['updated_at'];

$tsCandidates[] = safe_fetch_scalar($pdo, "SELECT MAX(updated_at) FROM project_events WHERE project_id = :pid", [':pid' => $projectId]);
$tsCandidates[] = safe_fetch_scalar($pdo, "SELECT MAX(created_at) FROM project_events WHERE project_id = :pid", [':pid' => $projectId]);

$tsCandidates[] = safe_fetch_scalar($pdo, "SELECT MAX(updated_at) FROM tasks WHERE company_id = :cid AND project_id = :pid", [':cid' => $companyId, ':pid' => $projectId]);
$tsCandidates[] = safe_fetch_scalar($pdo, "SELECT MAX(created_at) FROM tasks WHERE company_id = :cid AND project_id = :pid", [':cid' => $companyId, ':pid' => $projectId]);

$tsCandidates[] = safe_fetch_scalar($pdo, "SELECT MAX(created_at) FROM project_members WHERE project_id = :pid", [':pid' => $projectId]);

$lastUpdatedTs = max_timestamp($tsCandidates);
$lastUpdatedLabel = fmt_dt($lastUpdatedTs, 'd/m/Y, H:i');

/* ---------- EVENT DETAILS ---------- */
$eventTypeLabel = trim((string)($project['event_type'] ?? ''));
if ($eventTypeLabel === '') $eventTypeLabel = '—';

$eventNameLabel = trim((string)($project['title'] ?? ''));
if ($eventNameLabel === '') {
  $eventNameLabel = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

$guestCount = $project['guest_count_est'] ?? null;
$guestCountLabel = ($guestCount === null || $guestCount === '') ? '—' : number_format((int)$guestCount);

$events = [];
try {
  $es = $pdo->prepare("SELECT id, name, starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC, id ASC");
  $es->execute([':pid' => $projectId]);
  $events = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $events = [];
}

$eventNames = [];
$minT = null; $maxT = null;

foreach ($events as $e) {
  $n = trim((string)($e['name'] ?? ''));
  if ($n !== '') $eventNames[] = $n;

  $st = trim((string)($e['starts_at'] ?? ''));
  if ($st !== '') {
    $t = strtotime($st);
    if ($t) {
      if ($minT === null || $t < $minT) $minT = $t;
      if ($maxT === null || $t > $maxT) $maxT = $t;
    }
  }
}

$eventNames = array_values(array_unique($eventNames));
$eventsLabel = $eventNames ? implode(', ', $eventNames) : '—';

if ($minT === null) {
  $eventDatesLabel = '—';
} else {
  $minLabel = date('F j, Y', $minT);
  $maxLabel = ($maxT !== null) ? date('F j, Y', $maxT) : $minLabel;
  $eventDatesLabel = ($minLabel === $maxLabel) ? $minLabel : ($minLabel . ' - ' . $maxLabel);
}

$pageTitle = (string)($project['title'] ?? 'Project') . ' — Contract & scope — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.contract-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  margin-bottom: 14px;
}
.contract-head .left h2{
  margin:0;
  font-size:22px;
  font-weight:800;
}
.contract-head .left p{
  margin:6px 0 0 0;
  color: var(--muted);
  font-size: 13px;
}
.contract-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:10px;
  font-size:12px;
  padding:6px 10px;
  border:1px solid var(--border);
  border-radius: 999px;
  background:#fff;
  color:#222;
  vertical-align: middle;
}
.contract-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.contract-actions .icon-btn{
  width:38px;
  height:38px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
}
.contract-grid{
  display:grid;
  grid-template-columns: 1.2fr 1fr 1fr;
  gap: 14px;
  align-items:start;
}
@media (max-width: 1100px){
  .contract-grid{ grid-template-columns: 1fr; }
}
.kv{
  margin-top: 10px;
  border-top: 1px solid rgba(0,0,0,0.06);
}
.kv-row{
  display:grid;
  grid-template-columns: 140px 1fr;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  font-size: 13px;
}
.kv-k{ color: var(--muted); }
.kv-v{ text-align:right; font-weight:600; color:#222; }
.kv-actions{
  display:flex;
  justify-content:flex-end;
  margin-top: 12px;
}
.card-minh{ min-height: 130px; }
.card-list a{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding: 10px 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  color: inherit;
  text-decoration:none;
  font-size: 13px;
}
.card-list a:last-child{ border-bottom:none; }
.card-list .chev{ color: var(--muted); }

/* Event details table */
.ed-section{
  margin-top: 12px;
  font-weight: 800;
  font-size: 13px;
  color: #222;
}
.info-table{
  margin-top: 8px;
  border-top: 1px solid rgba(0,0,0,0.06);
}
.info-row{
  display:grid;
  grid-template-columns: 140px 1fr;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  font-size: 13px;
}
.info-k{ color: var(--muted); }
.info-v{ color:#222; font-weight:600; }
@media (max-width: 700px){
  .info-row{ grid-template-columns: 1fr; }
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
        Admin: <?php echo esc($adminName); ?>
        <a class="logout" href="<?php echo esc(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon"></div>
          <div>
            <div class="proj-name"><?php echo esc((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item"><?php echo esc($topDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo esc((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo esc(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo esc(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo esc(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'contract';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <div class="contract-head">
            <div class="left">
              <h2>
                Contract &amp; scope
                <span class="contract-badge"><?php echo esc($versionBadge); ?></span>
              </h2>
              <p>Create the agreement, define deliverables, and send it for approval.</p>
            </div>

            <div class="contract-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <button class="btn" type="button">👁 Preview PDF</button>
              <button class="btn btn-primary" type="button">☆ Send for approval</button>
            </div>
          </div>

          <div class="contract-grid">

            <!-- Contract status -->
            <div class="card proj-card">
              <div class="proj-card-title">Contract status</div>
              <div class="proj-card-sub">Track signing progress and key parties.</div>

              <div class="kv">
                <div class="kv-row">
                  <div class="kv-k">Client:</div>
                  <div class="kv-v"><?php echo esc($clientName); ?></div>
                </div>
                <div class="kv-row">
                  <div class="kv-k">Vendor</div>
                  <div class="kv-v"><?php echo esc($vendorName); ?></div>
                </div>
                <div class="kv-row">
                  <div class="kv-k">Prepared by</div>
                  <div class="kv-v"><?php echo esc($preparedBy); ?> (you)</div>
                </div>
                <div class="kv-row">
                  <div class="kv-k">Last updated on:</div>
                  <div class="kv-v"><?php echo esc($lastUpdatedLabel); ?></div>
                </div>
                <div class="kv-row" style="border-bottom:none;">
                  <div class="kv-k">Target sign-off</div>
                  <div class="kv-v"><?php echo esc($signOffLabel); ?></div>
                </div>
              </div>

              <div class="kv-actions">
                <button class="btn" type="button">View version history</button>
              </div>
            </div>

            <!-- Event details (reference style) -->
            <div class="card proj-card">
              <div class="proj-card-title">Event details</div>
              <div class="proj-card-sub">Core event information for planning.</div>

              <div class="ed-section">General information</div>
              <div class="info-table">
                <div class="info-row">
                  <div class="info-k">Event type</div>
                  <div class="info-v"><?php echo esc($eventTypeLabel); ?></div>
                </div>
                <div class="info-row">
                  <div class="info-k">Event name</div>
                  <div class="info-v"><?php echo esc($eventNameLabel); ?></div>
                </div>
                <div class="info-row">
                  <div class="info-k">Event dates</div>
                  <div class="info-v"><?php echo esc($eventDatesLabel); ?></div>
                </div>
                <div class="info-row" style="border-bottom:none;">
                  <div class="info-k">Estimated guests</div>
                  <div class="info-v"><?php echo esc($guestCountLabel); ?></div>
                </div>
              </div>

              <div class="ed-section" style="margin-top:14px;">Events</div>
              <div class="info-table">
                <div class="info-row" style="border-bottom:none;">
                  <div class="info-k">Event</div>
                  <div class="info-v"><?php echo esc($eventsLabel); ?></div>
                </div>
              </div>

              <div class="kv-actions">
                <a class="btn" href="<?php echo h(base_url('projects/contract_event_details.php?id=' . $projectId)); ?>">Edit details</a>
              </div>
            </div>

            <!-- Payment terms -->
            <div class="card proj-card card-minh">
              <div class="proj-card-title">Payment terms</div>
              <div class="proj-card-sub">Total fee, milestones, and cancellation terms.</div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit payment schedule</button>
              </div>
            </div>

            <!-- The rest of your cards can stay as-is (next we’ll wire them one by one) -->
            <div class="card proj-card">
              <div class="proj-card-title">Parties &amp; contacts</div>
              <div class="proj-card-sub">Who is the agreement between</div>
              <div style="margin-top: 10px; color: var(--muted); font-size:13px;">(We’ll wire this up next.)</div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit details</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Services provided</div>
              <div class="proj-card-sub">What the planning team will deliver for this event.</div>
              <div class="card-list" style="margin-top: 10px;">
                <a href="#" onclick="return false;"><span>Consultation &amp; planning</span><span class="chev">›</span></a>
                <a href="#" onclick="return false;"><span>Vendor coordination</span><span class="chev">›</span></a>
                <a href="#" onclick="return false;"><span>Logistics &amp; on-ground coordination</span><span class="chev">›</span></a>
              </div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">View &amp; edit services</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Cancellation policy</div>
              <div class="proj-card-sub">Refund and cancellation terms</div>
              <ul style="margin: 10px 0 0 18px; color:#222; font-size:13px; line-height:1.45;">
                <li>Cancellation <strong>30–90 days</strong> before the event: <strong>50%</strong> refundable (excluding deposit)</li>
                <li>Cancellation <strong>less than 30 days</strong> before the wedding: No refund</li>
                <li>Date changes: treated as rescheduling—charges may apply</li>
              </ul>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit policy</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Client responsibilities</div>
              <div class="proj-card-sub">Confirm dates, venue, and requirements on time.</div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit details</button>
              </div>
            </div>

            <div class="card proj-card card-minh">
              <div class="proj-card-title">Staffing plan</div>
              <div class="proj-card-sub">Set the team size needed for execution.</div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit details</button>
              </div>
            </div>

            <div class="card proj-card card-minh">
              <div class="proj-card-title">Notes &amp; files</div>
              <div class="proj-card-sub">Attach documents and keep internal notes.</div>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Upload files</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Liability &amp; force majeure</div>
              <div class="proj-card-sub">Responsibility limits and uncontrollable events.</div>
              <ul style="margin: 10px 0 0 18px; color:#222; font-size:13px; line-height:1.45;">
                <li>The service provider is not liable for issues caused by third-party vendors beyond agreed coordination.</li>
                <li>Force majeure includes natural disasters, government restrictions, and unforeseen emergencies.</li>
              </ul>
              <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                <button class="btn" type="button">Edit clauses</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Change requests</div>
              <div class="proj-card-sub">Track requested updates after the contract is shared.</div>
              <div style="margin-top: 10px; color: var(--muted); font-size:13px;">
                <ul style="margin:0 0 0 18px;">
                  <li>No change requests yet.</li>
                  <li>Change requests become available after the contract is sent.</li>
                </ul>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>