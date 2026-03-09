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

/* ---------- helpers ---------- */
function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fmt_dt(?string $ts, string $format): string {
  $ts = trim((string)$ts);
  if ($ts === '') return '—';
  $t = strtotime($ts);
  if (!$t) return '—';
  return date($format, $t);
}

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

/* keep project sidebar happy */
$projectDateLabel = $topDateLabel;

/* ---------- Team count ---------- */
$teamCount = 0;
try {
  $tc = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE project_id = :pid");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $teamCount = 0;
}

/* ---------- Contract row ---------- */
$contract = null;
try {
  $cs = $pdo->prepare("SELECT status, version_label, updated_at FROM contracts WHERE project_id = :pid ORDER BY id DESC LIMIT 1");
  $cs->execute([':pid' => $projectId]);
  $contract = $cs->fetch() ?: null;
} catch (Throwable $e) {
  $contract = null;
}

$contractStatusRaw = strtolower(trim((string)($contract['status'] ?? 'draft')));
$contractStatusLabel = $contractStatusRaw !== '' ? ucfirst(str_replace('_', ' ', $contractStatusRaw)) : 'Draft';
$versionLabel = trim((string)($contract['version_label'] ?? 'v0.1'));
$versionBadge = $contractStatusLabel . ' • ' . ($versionLabel !== '' ? $versionLabel : 'v0.1');

/* ---------- Contract status card values ---------- */
$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));

$clientName = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ($partner2 !== '' ? ' and ' . $partner2 : ''));

/* Vendor = company name (best effort) */
$vendorName = 'Your company';
try {
  $vn = $pdo->prepare("SELECT name FROM companies WHERE id = :cid LIMIT 1");
  $vn->execute([':cid' => $companyId]);
  $tmp = trim((string)($vn->fetchColumn() ?? ''));
  if ($tmp !== '') $vendorName = $tmp;
} catch (Throwable $e) {}

/* Prepared by */
$preparedBy = trim((string)($_SESSION['full_name'] ?? ''));
if ($preparedBy === '') $preparedBy = 'You';

/* Target sign-off */
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

/* Last updated on */
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
$minT = null;
$maxT = null;

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

/* ---------- PARTIES & CONTACTS ---------- */
if (!function_exists('first_non_empty')) {
  function first_non_empty(array $values, string $fallback = '—'): string {
    foreach ($values as $value) {
      $value = trim((string)$value);
      if ($value !== '') return $value;
    }
    return $fallback;
  }
}

if (!function_exists('join_non_empty')) {
  function join_non_empty(array $values, string $sep = ', ', string $fallback = '—'): string {
    $parts = [];

    foreach ($values as $value) {
      $value = trim((string)$value);
      if ($value !== '') $parts[] = $value;
    }

    $parts = array_values(array_unique($parts));
    return $parts ? implode($sep, $parts) : $fallback;
  }
}

$company = [];
try {
  $cstmt = $pdo->prepare("SELECT * FROM companies WHERE id = :cid LIMIT 1");
  $cstmt->execute([':cid' => $companyId]);
  $company = $cstmt->fetch() ?: [];
} catch (Throwable $e) {
  $company = [];
}

/* Service provider */
$serviceProviderCompany = first_non_empty([
  $company['name'] ?? '',
  $vendorName ?? '',
]);

$serviceProviderAddress = join_non_empty([
  $company['address'] ?? '',
  $company['address_line1'] ?? '',
  $company['address_line2'] ?? '',
  $company['city'] ?? '',
  $company['state'] ?? '',
  $company['postal_code'] ?? '',
]);

$serviceProviderEmail = first_non_empty([
  $company['email'] ?? '',
  $company['contact_email'] ?? '',
  $_SESSION['email'] ?? '',
]);

$serviceProviderPhone = first_non_empty([
  $company['phone'] ?? '',
  $company['contact_phone'] ?? '',
  $_SESSION['phone'] ?? '',
]);

/* Client / host */
$clientHostName = join_non_empty([
  $partner1,
  $partner2,
], ' & ');

$clientHostAddress = join_non_empty([
  $project['address'] ?? '',
  $project['address_line1'] ?? '',
  $project['address_line2'] ?? '',
  $project['city'] ?? '',
  $project['state'] ?? '',
  $project['postal_code'] ?? '',
]);

$clientHostEmail = join_non_empty([
  $project['email1'] ?? '',
  $project['email2'] ?? '',
], ' / ');

$clientHostPhone = join_non_empty([
  $project['phone1'] ?? '',
  $project['phone2'] ?? '',
], ' / ');


$pageTitle = (string)($project['title'] ?? 'Project') . ' — Contract & scope — Vidhaan';

if (!function_exists('local_table_exists')) {
  function local_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table
      ");
      $st->execute([':table' => $table]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('format_inr')) {
  function format_inr(float $amount): string {
    $negative = $amount < 0;
    $amount = abs($amount);

    $parts = explode('.', number_format($amount, 2, '.', ''));
    $integer = $parts[0];
    $decimal = $parts[1] ?? '00';

    if (strlen($integer) > 3) {
      $last3 = substr($integer, -3);
      $rest = substr($integer, 0, -3);
      $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest);
      $integer = $rest . ',' . $last3;
    }

    return ($negative ? '-₹ ' : '₹ ') . $integer . '.' . $decimal;
  }
}

if (!function_exists('milestone_percent_label')) {
  function milestone_percent_label(float $amount, float $total): string {
    if ($total <= 0) return '';
    $pct = ($amount / $total) * 100;
    $rounded = round($pct, 1);
    $display = ((float)(int)$rounded === (float)$rounded)
      ? (string)(int)$rounded
      : number_format($rounded, 1);
    return $display . '% of total';
  }
}

$paymentTerms = null;
$paymentMilestones = [];
$paymentTotal = 0.0;
$paymentGstNote = '';

if (local_table_exists($pdo, 'project_payment_terms')) {
  try {
    $pt = $pdo->prepare("
      SELECT *
      FROM project_payment_terms
      WHERE project_id = :pid
      LIMIT 1
    ");
    $pt->execute([':pid' => $projectId]);
    $paymentTerms = $pt->fetch() ?: null;
  } catch (Throwable $e) {
    $paymentTerms = null;
  }
}

if ($paymentTerms) {
  $paymentTotal = (float)($paymentTerms['total_amount'] ?? 0);
  $paymentGstNote = trim((string)($paymentTerms['gst_note'] ?? ''));

  if (local_table_exists($pdo, 'project_payment_milestones')) {
    try {
      $pm = $pdo->prepare("
        SELECT *
        FROM project_payment_milestones
        WHERE payment_terms_id = :tid
        ORDER BY sort_order ASC, id ASC
      ");
      $pm->execute([':tid' => (int)$paymentTerms['id']]);
      $paymentMilestones = $pm->fetchAll() ?: [];
    } catch (Throwable $e) {
      $paymentMilestones = [];
    }
  }
}

/* ---------- CLIENT RESPONSIBILITIES SUMMARY ---------- */
if (!function_exists('local_table_exists')) {
  function local_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table
      ");
      $st->execute([':table' => $table]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('responsibility_due_label')) {
  function responsibility_due_label(?string $date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/y', $ts) : '';
  }
}

$clientResponsibilitiesPreview = [];
$clientResponsibilitiesCount = 0;

if (local_table_exists($pdo, 'project_client_responsibilities')) {
  try {
    $cr = $pdo->prepare("
      SELECT id, title, due_date, priority, status
      FROM project_client_responsibilities
      WHERE project_id = :pid
      ORDER BY due_date IS NULL, due_date ASC, id DESC
      LIMIT 5
    ");
    $cr->execute([':pid' => $projectId]);
    $clientResponsibilitiesPreview = $cr->fetchAll() ?: [];

    $crc = $pdo->prepare("
      SELECT COUNT(*)
      FROM project_client_responsibilities
      WHERE project_id = :pid
    ");
    $crc->execute([':pid' => $projectId]);
    $clientResponsibilitiesCount = (int)$crc->fetchColumn();
  } catch (Throwable $e) {
    $clientResponsibilitiesPreview = [];
    $clientResponsibilitiesCount = 0;
  }
}

/* ---------- STAFFING PLAN SUMMARY ---------- */
if (!function_exists('staffing_role_label')) {
  function staffing_role_label(string $role): string {
    $map = [
      'team_lead'    => 'Team lead',
      'coordination' => 'Coordination',
      'rsvp'         => 'RSVP team',
      'hospitality'  => 'Hospitality',
      'transport'    => 'Transport',
      'vendor'       => 'Vendor',
    ];

    $role = trim($role);
    if ($role === '') return '—';

    return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
  }
}

$staffingPreview = [];
$staffingPreviewCount = 0;

try {
  $sp = $pdo->prepare("
    SELECT
      COALESCE(NULLIF(pm.display_name, ''), NULLIF(cm.full_name, ''), NULLIF(pm.email, ''), 'Team member') AS member_name,
      GROUP_CONCAT(
        DISTINCT COALESCE(
          NULLIF(pm.responsibility_label, ''),
          NULLIF(pm.department, ''),
          NULLIF(pm.role, '')
        )
        ORDER BY COALESCE(
          NULLIF(pm.responsibility_label, ''),
          NULLIF(pm.department, ''),
          NULLIF(pm.role, '')
        )
        SEPARATOR '||'
      ) AS raw_responsibilities
    FROM project_members pm
    LEFT JOIN company_members cm
      ON cm.id = pm.company_member_id
    WHERE pm.project_id = :pid
    GROUP BY COALESCE(NULLIF(pm.display_name, ''), NULLIF(cm.full_name, ''), NULLIF(pm.email, ''), 'Team member')
    ORDER BY member_name ASC
    LIMIT 5
  ");
  $sp->execute([':pid' => $projectId]);
  $staffingPreview = $sp->fetchAll() ?: [];

  $spc = $pdo->prepare("
    SELECT COUNT(DISTINCT
      COALESCE(NULLIF(pm.display_name, ''), NULLIF(cm.full_name, ''), NULLIF(pm.email, ''), CONCAT('member-', pm.id))
    )
    FROM project_members pm
    LEFT JOIN company_members cm
      ON cm.id = pm.company_member_id
    WHERE pm.project_id = :pid
  ");
  $spc->execute([':pid' => $projectId]);
  $staffingPreviewCount = (int)($spc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $staffingPreview = [];
  $staffingPreviewCount = 0;
}

require_once $root . '/includes/header.php';
?>

<style>
.contract-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  margin-bottom:14px;
}
.contract-head .left h2{
  margin:0;
  font-size:22px;
  font-weight:800;
}
.contract-head .left p{
  margin:6px 0 0 0;
  color:var(--muted);
  font-size:13px;
}
.contract-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:10px;
  font-size:12px;
  padding:6px 10px;
  border:1px solid var(--border);
  border-radius:999px;
  background:#fff;
  color:#222;
  vertical-align:middle;
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
  grid-template-columns:1.2fr 1fr 1fr;
  gap:14px;
  align-items:start;
}
@media (max-width:1100px){
  .contract-grid{ grid-template-columns:1fr; }
}
.kv{
  margin-top:10px;
  border-top:1px solid rgba(0,0,0,0.06);
}
.kv-row{
  display:grid;
  grid-template-columns:140px 1fr;
  gap:12px;
  padding:12px 0;
  border-bottom:1px solid rgba(0,0,0,0.06);
  font-size:13px;
}
.kv-k{ color:var(--muted); }
.kv-v{ text-align:right; font-weight:600; color:#222; }
.kv-actions{
  display:flex;
  justify-content:flex-end;
  margin-top:12px;
}
.card-minh{ min-height:130px; }
.card-list a{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:10px 0;
  border-bottom:1px solid rgba(0,0,0,0.06);
  color:inherit;
  text-decoration:none;
  font-size:13px;
}
.card-list a:last-child{ border-bottom:none; }
.card-list .chev{ color:var(--muted); }
.ed-section{
  margin-top:12px;
  font-weight:800;
  font-size:13px;
  color:#222;
}
.info-table{
  margin-top:8px;
  border-top:1px solid rgba(0,0,0,0.06);
}
.info-row{
  display:grid;
  grid-template-columns:140px 1fr;
  gap:12px;
  padding:10px 0;
  border-bottom:1px solid rgba(0,0,0,0.06);
  font-size:13px;
}
.info-k{ color:var(--muted); }
.info-v{ color:#222; font-weight:600; }
@media (max-width:700px){
  .info-row{ grid-template-columns:1fr; }
}

.payment-summary{
  margin-top:12px;
}
.payment-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:14px;
  align-items:start;
  padding:6px 0;
}
.payment-row .k{
  font-size:14px;
  color:#3f3f45;
}
.payment-row .v{
  font-size:14px;
  color:#232326;
  text-align:right;
  font-weight:600;
}
.payment-row .meta{
  display:block;
  margin-top:2px;
  font-size:12px;
  color:var(--muted);
  font-weight:400;
}
.payment-empty{
  margin-top:12px;
  padding:12px 14px;
  border-radius:16px;
  background:rgba(0,0,0,0.03);
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}

.staffing-mini-section{
  margin-top:14px;
}
.staffing-mini-heading{
  font-size:16px;
  font-weight:500;
  color:#2f2f34;
  margin-bottom:8px;
}
.staffing-mini-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin-bottom:6px;
}
.staffing-mini-list{
  display:flex;
  flex-direction:column;
  gap:0;
}
.staffing-mini-row{
  display:grid;
  grid-template-columns:minmax(120px, 42%) minmax(140px, 58%);
  gap:16px;
  align-items:start;
  padding:4px 0;
}
.staffing-mini-name{
  min-width:0;
  font-size:14px;
  color:#4a4a50;
  line-height:1.45;
  word-break:normal;
}
.staffing-mini-role{
  min-width:0;
  text-align:left;
  font-size:14px;
  color:#5e5e64;
  line-height:1.45;
  word-break:normal;
}
.staffing-mini-empty{
  margin-top:12px;
  padding:12px 14px;
  border-radius:16px;
  background:rgba(0,0,0,0.03);
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.staffing-mini-note{
  margin-top:10px;
  color:var(--muted);
  font-size:12px;
}

@media (max-width:520px){
  .staffing-mini-row{
    grid-template-columns:1fr;
    gap:2px;
  }

  .staffing-mini-role{
    text-align:left;
    font-size:13px;
    color:var(--muted);
  }
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
                <a class="btn" href="<?php echo esc(base_url('projects/contract_event_details.php?id=' . $projectId)); ?>">Edit details</a>
              </div>
            </div>

            <div class="card proj-card">
  <div class="proj-card-title">Payment terms</div>
  <div class="proj-card-sub">Total fee and payment schedule.</div>

  <?php if ($paymentTerms): ?>
    <div class="payment-summary">
      <div class="payment-row">
        <div class="k">Total fee:</div>
        <div class="v">
          <?php echo esc(format_inr($paymentTotal)); ?>
          <?php if ($paymentGstNote !== ''): ?>
            <?php echo ' ' . esc($paymentGstNote); ?>
          <?php endif; ?>
        </div>
      </div>

      <?php foreach ($paymentMilestones as $milestone): ?>
        <?php
          $mAmount = (float)($milestone['amount'] ?? 0);
          $mLabel = trim((string)($milestone['phase_label'] ?? 'Milestone'));
          $mPercent = milestone_percent_label($mAmount, $paymentTotal);
        ?>
        <div class="payment-row">
          <div class="k"><?php echo esc($mLabel); ?>:</div>
          <div class="v">
            <?php echo esc(format_inr($mAmount)); ?>
            <?php if ($mPercent !== ''): ?>
              <span class="meta"><?php echo esc($mPercent); ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="payment-empty">
      No payment schedule added yet. Add total fee, due date, and milestones.
    </div>
  <?php endif; ?>

  <div style="display:flex; justify-content:flex-end; margin-top:14px;">
    <a class="btn" href="<?php echo esc(base_url('projects/contract_payment_terms.php?id=' . $projectId)); ?>">
      Edit payment schedule
    </a>
  </div>
</div>

            <div class="card proj-card">
  <div class="proj-card-title">Parties &amp; contacts</div>
  <div class="proj-card-sub">Who is the agreement between</div>

  <div style="margin-top:12px;">
    <div class="ed-section" style="margin-top:0;">Service provider</div>
    <div class="info-table">
      <div class="info-row">
        <div class="info-k">Company</div>
        <div class="info-v"><?php echo esc($serviceProviderCompany); ?></div>
      </div>

      <div class="info-row">
        <div class="info-k">Address</div>
        <div class="info-v"><?php echo esc($serviceProviderAddress); ?></div>
      </div>

      <div class="info-row">
        <div class="info-k">Email</div>
        <div class="info-v"><?php echo esc($serviceProviderEmail); ?></div>
      </div>

      <div class="info-row" style="border-bottom:none;">
        <div class="info-k">Phone</div>
        <div class="info-v"><?php echo esc($serviceProviderPhone); ?></div>
      </div>
    </div>
  </div>

  <div style="margin-top:14px;">
    <div class="ed-section" style="margin-top:0;">Client (Host)</div>
    <div class="info-table">
      <div class="info-row">
        <div class="info-k">Name</div>
        <div class="info-v"><?php echo esc($clientHostName); ?></div>
      </div>

      <div class="info-row">
        <div class="info-k">Address</div>
        <div class="info-v"><?php echo esc($clientHostAddress); ?></div>
      </div>

      <div class="info-row">
        <div class="info-k">Email</div>
        <div class="info-v"><?php echo esc($clientHostEmail); ?></div>
      </div>

      <div class="info-row" style="border-bottom:none;">
        <div class="info-k">Phone</div>
        <div class="info-v"><?php echo esc($clientHostPhone); ?></div>
      </div>
    </div>
  </div>

  <div style="display:flex; justify-content:flex-end; margin-top:14px;">
    <a class="btn" href="<?php echo esc(base_url('projects/contract_parties_contacts.php?id=' . $projectId)); ?>">Edit details</a>
  </div>
</div>

            <div class="card proj-card">
              <div class="proj-card-title">Services provided</div>
              <div class="proj-card-sub">What the planning team will deliver for this event.</div>
              <div class="card-list" style="margin-top:10px;">
                <a href="#" onclick="return false;"><span>Consultation &amp; planning</span><span class="chev">›</span></a>
                <a href="#" onclick="return false;"><span>Vendor coordination</span><span class="chev">›</span></a>
                <a href="#" onclick="return false;"><span>Logistics &amp; on-ground coordination</span><span class="chev">›</span></a>
              </div>
              <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button class="btn" type="button">View &amp; edit services</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Cancellation policy</div>
              <div class="proj-card-sub">Refund and cancellation terms</div>
              <ul style="margin:10px 0 0 18px; color:#222; font-size:13px; line-height:1.45;">
                <li>Cancellation <strong>30–90 days</strong> before the event: <strong>50%</strong> refundable (excluding deposit)</li>
                <li>Cancellation <strong>less than 30 days</strong> before the wedding: No refund</li>
                <li>Date changes: treated as rescheduling—charges may apply</li>
              </ul>
              <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button class="btn" type="button">Edit policy</button>
              </div>
            </div>

            <div class="card proj-card">
  <div class="proj-card-title">Client responsibilities</div>
  <div class="proj-card-sub">Use the client must provide to keep things moving.</div>

  <?php if ($clientResponsibilitiesPreview): ?>
    <div class="contract-mini-list">
      <?php foreach ($clientResponsibilitiesPreview as $item): ?>
        <?php
          $itemTitle = trim((string)($item['title'] ?? ''));
          $itemDue = responsibility_due_label((string)($item['due_date'] ?? ''));
        ?>
        <div class="contract-mini-row">
          <div class="contract-mini-left">
            <span class="contract-mini-bullet">•</span>
            <span class="contract-mini-title"><?php echo esc($itemTitle !== '' ? $itemTitle : 'Untitled responsibility'); ?></span>
          </div>
          <div class="contract-mini-date">
            <?php echo esc($itemDue !== '' ? $itemDue : ''); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($clientResponsibilitiesCount > count($clientResponsibilitiesPreview)): ?>
      <div class="contract-mini-note">
        +<?php echo esc((string)($clientResponsibilitiesCount - count($clientResponsibilitiesPreview))); ?> more responsibilities
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="contract-mini-empty">
      No client responsibilities added yet.
    </div>
  <?php endif; ?>

  <div style="display:flex; justify-content:flex-end; margin-top:14px;">
    <a class="btn" href="<?php echo esc(base_url('projects/contract_client_responsibilities.php?id=' . $projectId)); ?>">
      Edit details
    </a>
  </div>
</div>

            <div class="card proj-card">
  <div class="proj-card-title">Staffing plan</div>
  <div class="proj-card-sub">Team members planned for on-ground execution.</div>

  <?php if ($staffingPreview): ?>
    <div class="staffing-mini-section">
      <div class="staffing-mini-heading">Team requirements</div>
      <div class="staffing-mini-divider"></div>

      <div class="staffing-mini-list">
        <?php foreach ($staffingPreview as $member): ?>
          <?php
            $memberName = trim((string)($member['member_name'] ?? 'Team member'));
            $rawResponsibilities = trim((string)($member['raw_responsibilities'] ?? ''));
            $responsibilityParts = $rawResponsibilities !== '' ? array_filter(array_map('trim', explode('||', $rawResponsibilities))) : [];
            $responsibilityParts = array_values(array_unique($responsibilityParts));

            $responsibilityLabels = [];
            foreach ($responsibilityParts as $part) {
              $responsibilityLabels[] = staffing_role_label($part);
            }

            $responsibilityText = $responsibilityLabels ? implode(', ', $responsibilityLabels) : '—';
          ?>
          <div class="staffing-mini-row">
            <div class="staffing-mini-name"><?php echo esc($memberName); ?></div>
            <div class="staffing-mini-role"><?php echo esc($responsibilityText); ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($staffingPreviewCount > count($staffingPreview)): ?>
        <div class="staffing-mini-note">
          +<?php echo esc((string)($staffingPreviewCount - count($staffingPreview))); ?> more team members
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="staffing-mini-empty">
      No team members assigned yet.
    </div>
  <?php endif; ?>

  <div style="display:flex; justify-content:flex-end; margin-top:14px;">
    <a class="btn" href="<?php echo esc(base_url('projects/team.php?id=' . $projectId)); ?>">
      Edit details
    </a>
  </div>
</div>

            <div class="card proj-card card-minh">
              <div class="proj-card-title">Notes &amp; files</div>
              <div class="proj-card-sub">Attach documents and keep internal notes.</div>
              <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button class="btn" type="button">Upload files</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Liability &amp; force majeure</div>
              <div class="proj-card-sub">Responsibility limits and uncontrollable events.</div>
              <ul style="margin:10px 0 0 18px; color:#222; font-size:13px; line-height:1.45;">
                <li>The service provider is not liable for issues caused by third-party vendors beyond agreed coordination.</li>
                <li>Force majeure includes natural disasters, government restrictions, and unforeseen emergencies.</li>
              </ul>
              <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button class="btn" type="button">Edit clauses</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Change requests</div>
              <div class="proj-card-sub">Track requested updates after the contract is shared.</div>
              <div style="margin-top:10px; color:var(--muted); font-size:13px;">
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