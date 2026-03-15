<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['project_id'] ?? $_GET['id'] ?? 0);
if ($projectId <= 0) {
  redirect('projects/index.php');
}

$companyId = current_company_id();
$searchQ = trim((string)($_GET['q'] ?? ''));
$bucket = trim((string)($_GET['bucket'] ?? 'rooms'));
$filterSide = trim((string)($_GET['side'] ?? ''));
$filterRoomType = trim((string)($_GET['room_type'] ?? ''));
$filterBedType = trim((string)($_GET['bed_type'] ?? ''));
$selectedGuestId = (int)($_GET['guest_id'] ?? 0);

$allowedBuckets = ['rooms', 'checkins', 'checkouts', 'requests'];
$allowedSides   = ['bride', 'groom', 'both'];

if (!in_array($bucket, $allowedBuckets, true)) $bucket = 'rooms';
if (!in_array($filterSide, $allowedSides, true)) $filterSide = '';

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function table_exists_local(PDO $pdo, string $table): bool {
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

function first_non_empty(array $values): string {
  foreach ($values as $value) {
    $value = trim((string)$value);
    if ($value !== '') return $value;
  }
  return '';
}

function side_label(string $side): string {
  return match ($side) {
    'bride' => "Bride’s side",
    'groom' => "Groom’s side",
    'both'  => 'Both families',
    default => '—',
  };
}

function guest_full_name(array $row): string {
  $fullName = trim(
    trim((string)($row['title'] ?? '')) . ' ' .
    trim((string)($row['first_name'] ?? '')) . ' ' .
    trim((string)($row['last_name'] ?? ''))
  );
  return $fullName !== '' ? $fullName : 'Unnamed guest';
}

function room_type_label(string $value): string {
  $value = trim($value);
  if ($value === '') return 'Missing';

  return match ($value) {
    'suite' => 'Suite',
    'deluxe' => 'Deluxe',
    'standard' => 'Standard',
    'family' => 'Family room',
    'basic' => 'Basic',
    'executive_suite' => 'Executive suite',
    default => ucfirst(str_replace('_', ' ', $value)),
  };
}

function bed_type_label(string $value): string {
  $value = trim($value);
  if ($value === '') return 'Missing';

  return match ($value) {
    'king' => 'King',
    'queen' => 'Queen',
    'twin' => 'Twin',
    'double' => 'Double',
    'extra_bed' => 'Extra bed',
    default => ucfirst(str_replace('_', ' ', $value)),
  };
}

function hospitality_date_label(string $date): string {
  $date = trim($date);
  if ($date === '') return 'Missing';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : $date;
}

function hospitality_note_label(array $row): string {
  $parts = [];

  $stayNotes = trim((string)($row['stay_notes'] ?? ''));
  $specialNotes = trim((string)($row['special_notes'] ?? ''));
  $accessibility = trim((string)($row['accessibility'] ?? ''));

  if ($stayNotes !== '') $parts[] = $stayNotes;
  if ($specialNotes !== '' && !in_array($specialNotes, $parts, true)) $parts[] = $specialNotes;

  if ($accessibility !== '' && $accessibility !== 'none') {
    $parts[] = ucfirst(str_replace('_', ' ', $accessibility));
  }

  return $parts ? implode(', ', array_unique($parts)) : '';
}

function hospitality_page_url(
  int $projectId,
  string $bucket = 'rooms',
  string $searchQ = '',
  string $side = '',
  string $roomType = '',
  string $bedType = '',
  int $guestId = 0
): string {
  $params = ['project_id' => $projectId, 'bucket' => $bucket];
  if ($searchQ !== '') $params['q'] = $searchQ;
  if ($side !== '') $params['side'] = $side;
  if ($roomType !== '') $params['room_type'] = $roomType;
  if ($bedType !== '') $params['bed_type'] = $bedType;
  if ($guestId > 0) $params['guest_id'] = $guestId;

  return base_url('hospitality/index.php?' . http_build_query($params));
}

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("
  SELECT *
  FROM projects
  WHERE id = :pid
    AND company_id = :cid
  LIMIT 1
");
$pstmt->execute([
  ':pid' => $projectId,
  ':cid' => $companyId,
]);
$project = $pstmt->fetch();

if (!$project) {
  redirect('projects/index.php');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));
$projectTitle = trim((string)($project['title'] ?? ''));

if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

$firstEvent = null;
try {
  $evt = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC
    LIMIT 1
  ");
  $evt->execute([':pid' => $projectId]);
  $firstEvent = $evt->fetch();
} catch (Throwable $e) {
  $firstEvent = null;
}

$daysToGo = null;
$topDateLabel = 'Date TBD';

if ($firstEvent && !empty($firstEvent['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$firstEvent['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  $topDateLabel = date('F j, Y', strtotime((string)$firstEvent['starts_at']));
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') {
    $topDateLabel = date('F j, Y', strtotime($createdAt));
  }
}

$teamCount = 0;
try {
  $tc = $pdo->prepare("
    SELECT COUNT(*)
    FROM project_members
    WHERE project_id = :pid
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $teamCount = 0;
}

/* ---------- Guests / hospitality rows ---------- */
$guestRows = [];
if (table_exists_local($pdo, 'guests')) {
  $st = $pdo->prepare("
    SELECT *
    FROM guests
    WHERE project_id = :pid
    ORDER BY created_at DESC, id DESC
  ");
  $st->execute([':pid' => $projectId]);
  $guestRows = $st->fetchAll() ?: [];
}

$selectedGuest = null;
foreach ($guestRows as $row) {
  if ((int)($row['id'] ?? 0) === $selectedGuestId) {
    $selectedGuest = $row;
    break;
  }
}

$hospitalityRows = [];
$roomTypeOptions = [];
$bedTypeOptions = [];

foreach ($guestRows as $row) {
  $roomTypeRaw = trim((string)($row['room_type'] ?? ''));
  $bedTypeRaw = trim((string)($row['bed_type'] ?? ''));
  $headcount = (int)($row['seat_count'] ?? 0);
  $checkin = trim((string)($row['checkin_date'] ?? ''));
  $checkout = trim((string)($row['checkout_date'] ?? ''));
  $idDocumentNote = trim((string)($row['id_document_note'] ?? ''));
  $extraRequestNotes = hospitality_note_label($row);

  $luggageCountRaw = trim((string)($row['luggage_count'] ?? ''));
  $luggageCount = $luggageCountRaw === '' ? null : (int)$luggageCountRaw;

  $roomTypeLabel = room_type_label($roomTypeRaw);
  $bedTypeLabel = bed_type_label($bedTypeRaw);

  if ($roomTypeRaw !== '') $roomTypeOptions[strtolower($roomTypeLabel)] = $roomTypeLabel;
  if ($bedTypeRaw !== '') $bedTypeOptions[strtolower($bedTypeLabel)] = $bedTypeLabel;

  $hospitalityRows[] = [
    'guest_id' => (int)($row['id'] ?? 0),
    'guest_name' => guest_full_name($row),
    'side' => trim((string)($row['invited_by'] ?? '')),
    'side_label' => side_label((string)($row['invited_by'] ?? '')),
    'room_type_raw' => $roomTypeRaw,
    'room_type_label' => $roomTypeLabel,
    'bed_type_raw' => $bedTypeRaw,
    'bed_type_label' => $bedTypeLabel,
    'headcount' => $headcount,
    'checkin' => $checkin,
    'checkout' => $checkout,
    'checkin_label' => hospitality_date_label($checkin),
    'checkout_label' => hospitality_date_label($checkout),
    'id_document_note' => $idDocumentNote,
    'request_notes' => $extraRequestNotes,
    'luggage_count' => $luggageCount,
  ];
}

natcasesort($roomTypeOptions);
natcasesort($bedTypeOptions);

/* ---------- Counts ---------- */
$roomsCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['room_type_raw'] === ''));
$checkinsCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['checkin'] !== ''));
$checkoutsCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['checkout'] !== ''));
$requestsCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['request_notes'] !== ''));

$missingRoomTypeCount = $roomsCount;
$missingDocumentCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['id_document_note'] === ''));
$unassignedHeadcountCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['headcount'] <= 0));

$today = date('Y-m-d');
$unassignedStayDurationCount = count(array_filter($hospitalityRows, static fn(array $r): bool => $r['checkin'] === '' || $r['checkout'] === ''));
$currentCheckedInCount = count(array_filter($hospitalityRows, static function (array $r) use ($today): bool {
  return $r['checkin'] !== '' && $r['checkout'] !== '' && $r['checkin'] <= $today && $r['checkout'] >= $today;
}));
$checkedOutCount = count(array_filter($hospitalityRows, static function (array $r) use ($today): bool {
  return $r['checkout'] !== '' && $r['checkout'] < $today;
}));
$pastDurationCount = count(array_filter($hospitalityRows, static function (array $r) use ($today): bool {
  return $r['checkin'] !== '' && $r['checkout'] === '' && $r['checkin'] < $today;
}));

$roomTypeCounts = [];
$bedTypeCounts = [];

foreach ($hospitalityRows as $row) {
  if ($row['room_type_raw'] !== '') {
    $roomTypeCounts[$row['room_type_label']] = ($roomTypeCounts[$row['room_type_label']] ?? 0) + 1;
  }
  if ($row['bed_type_raw'] !== '') {
    $bedTypeCounts[$row['bed_type_label']] = ($bedTypeCounts[$row['bed_type_label']] ?? 0) + 1;
  }
}

arsort($roomTypeCounts);
arsort($bedTypeCounts);

/* ---------- Filters ---------- */
$displayRows = array_values(array_filter($hospitalityRows, function (array $row) use ($searchQ, $filterSide, $filterRoomType, $filterBedType): bool {
  if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $haystack = strtolower(implode(' ', [
      $row['guest_name'],
      $row['side_label'],
      $row['room_type_label'],
      $row['bed_type_label'],
      $row['request_notes'],
      (string)($row['luggage_count'] ?? ''),
    ]));
    if (!str_contains($haystack, $needle)) return false;
  }

  if ($filterSide !== '' && $row['side'] !== $filterSide) return false;
  if ($filterRoomType !== '' && strtolower($row['room_type_label']) !== strtolower($filterRoomType)) return false;
  if ($filterBedType !== '' && strtolower($row['bed_type_label']) !== strtolower($filterBedType)) return false;

  return true;
}));

/* ---------- Selected guest detail ---------- */
$selectedGuestName = '';
$selectedGuestSide = '';
$selectedGuestRelation = '';
$selectedGuestFamilyGroup = '';
$selectedCheckinLabel = 'Missing';
$selectedCheckoutLabel = 'Missing';
$selectedRoomTypeLabel = 'Missing';
$selectedBedTypeLabel = 'Missing';
$selectedIdDocument = 'Missing';
$selectedStayNotes = 'Missing';
$selectedLuggageCountLabel = 'Missing';

if ($selectedGuest) {
  $selectedGuestName = guest_full_name($selectedGuest);
  $selectedGuestSide = side_label((string)($selectedGuest['invited_by'] ?? ''));
  $selectedGuestRelation = trim((string)($selectedGuest['relation_label'] ?? ''));
  $selectedGuestFamilyGroup = trim((string)($selectedGuest['family_group'] ?? ''));

  $selectedCheckinLabel = hospitality_date_label((string)($selectedGuest['checkin_date'] ?? ''));
  $selectedCheckoutLabel = hospitality_date_label((string)($selectedGuest['checkout_date'] ?? ''));
  $selectedRoomTypeLabel = room_type_label((string)($selectedGuest['room_type'] ?? ''));
  $selectedBedTypeLabel = bed_type_label((string)($selectedGuest['bed_type'] ?? ''));

  $selectedIdDocument = trim((string)($selectedGuest['id_document_note'] ?? ''));
  if ($selectedIdDocument === '') $selectedIdDocument = 'Missing';

  $selectedStayNotes = trim((string)($selectedGuest['stay_notes'] ?? ''));
  if ($selectedStayNotes === '') $selectedStayNotes = 'Missing';

  $selectedLuggageRaw = trim((string)($selectedGuest['luggage_count'] ?? ''));
  $selectedLuggageCountLabel = $selectedLuggageRaw !== '' ? $selectedLuggageRaw : 'Missing';
}

$hospitalityBucket = $bucket;

$pageTitle = $projectTitle . ' — Hotel and hospitality — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.proj-main{ min-width:0; }

.hosp-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:18px;
  margin-bottom:18px;
}
.hosp-head .left h2{
  margin:0;
  font-size:26px;
  line-height:1.08;
  font-weight:800;
  color:#1d1d1f;
}
.hosp-head .left p{
  margin:8px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.5;
  max-width:660px;
}
.hosp-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.hosp-actions .icon-btn{
  width:42px;
  height:42px;
  min-width:42px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  padding:0;
}

.hosp-stat-row{
  display:grid;
  grid-template-columns:repeat(4, minmax(0,1fr));
  gap:12px;
  margin:0 0 14px 0;
}
@media (max-width:1180px){
  .hosp-stat-row{ grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width:680px){
  .hosp-stat-row{ grid-template-columns:1fr; }
}

.hosp-stat-card{
  display:flex;
  flex-direction:column;
  justify-content:flex-start;
  min-height:88px;
  text-decoration:none;
  padding:16px 18px;
  border-radius:22px;
  border:1px solid rgba(0,0,0,0.04);
  background:#fff;
  color:#1f1f22;
}
.hosp-stat-card.is-active{
  background:rgba(75,0,31,0.06);
  border-color:rgba(75,0,31,0.08);
}
.hosp-stat-title{
  font-size:16px;
  font-weight:800;
  line-height:1.2;
}
.hosp-stat-sub{
  margin-top:4px;
  color:#7a7a80;
  font-size:12px;
  line-height:1.35;
}

.hosp-top-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) 340px;
  gap:16px;
  align-items:start;
}
@media (max-width:1380px){
  .hosp-top-grid{ grid-template-columns:1fr; }
}

.hosp-main{ min-width:0; }

.hosp-side{
  min-width:0;
  width:100%;
  max-width:340px;
  justify-self:end;
  display:flex;
  flex-direction:column;
  gap:16px;
}

.hosp-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
  flex-wrap:wrap;
}
.hosp-search{ flex:1 1 280px; }
.hosp-search-wrap{ position:relative; }
.hosp-search-wrap .search-ico{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#9a9aa1;
  font-size:13px;
  pointer-events:none;
}
.hosp-search input{
  width:100%;
  min-height:42px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.07);
  background:#fff;
  padding:10px 14px 10px 36px;
  box-sizing:border-box;
  color:#1f1f22;
  font-size:14px;
}
.hosp-search input::placeholder{ color:#9b9ba2; }

.hosp-toolbar-right{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.hosp-date-chip{
  min-height:42px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.10);
  background:#fff;
  color:#5f5f66;
  padding:0 16px;
  font-size:13px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  white-space:nowrap;
}

.hosp-table-card{
  padding:8px 14px 10px;
  border-radius:26px;
  overflow-x:auto;
  overflow-y:hidden;
}
.hosp-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.hosp-table thead th{
  text-align:left;
  padding:14px 14px 14px;
  font-size:12px;
  color:#b0b0b6;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
  vertical-align:top;
}
.hosp-table tbody td{
  text-align:left;
  padding:12px 10px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:13px;
  color:#1f1f22;
}
.hosp-table tbody tr:last-child td{ border-bottom:none; }

.hosp-th-wrap{
  display:flex;
  flex-direction:column;
  gap:7px;
}
.hosp-th-top{
  display:flex;
  align-items:center;
  gap:6px;
  color:#b0b0b6;
  font-size:12px;
  font-weight:700;
}
.hosp-th-top .chev{
  font-size:11px;
  color:#c0c0c6;
}
.hosp-th-filter{
  width:100%;
  min-width:72px;
  min-height:34px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.06);
  background:#fff;
  color:#5f5f66;
  font-size:12px;
  padding:0 12px;
  outline:none;
  box-sizing:border-box;
}

.hosp-name{
  font-weight:500;
  color:#1d1d1f;
  line-height:1.3;
  min-width:150px;
  max-width:180px;
  white-space:normal;
  overflow-wrap:anywhere;
}
.hosp-side-text{
  color:#4f4f55;
  font-size:13px;
  line-height:1.35;
}
.hosp-notes{
  color:#4f4f55;
  font-size:13px;
  line-height:1.5;
}

.hosp-table-row{
  cursor:pointer;
}
.hosp-table-row:hover td{
  background:rgba(0,0,0,0.02);
}
.hosp-table-row.is-selected td{
  background:rgba(75,0,31,0.045);
}
.hosp-table-row.is-selected td:first-child{
  border-top-left-radius:18px;
  border-bottom-left-radius:18px;
}
.hosp-table-row.is-selected td:last-child{
  border-top-right-radius:18px;
  border-bottom-right-radius:18px;
}

.h-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  min-height:28px;
  padding:0 12px;
  border-radius:999px;
  font-size:11px;
  font-weight:500;
  white-space:nowrap;
  border:none;
}
.h-chip.ok{
  background:#dff1cf;
  color:#54733e;
}
.h-chip.neutral{
  background:#efefef;
  color:#7b7b82;
}
.h-chip.missing{
  background:#efefef;
  color:#7b7b82;
}

.empty-table{
  padding:18px;
  border-radius:18px;
  background:rgba(0,0,0,0.02);
  color:#75757a;
  font-size:13px;
  line-height:1.55;
}

.overview-card,
.hosp-detail-card{
  padding:18px;
  border-radius:24px;
}
.overview-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#222;
}
.overview-sub{
  margin:4px 0 0 0;
  color:#75757a;
  font-size:12px;
  line-height:1.45;
}
.overview-wrap{
  display:flex;
  flex-direction:column;
  gap:2px;
  margin-top:12px;
}
.overview-group{
  border-top:1px solid rgba(0,0,0,0.06);
  padding-top:10px;
}
.overview-group:first-child{
  border-top:none;
  padding-top:0;
}
.overview-group summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  font-size:13px;
  font-weight:800;
  color:#222;
  padding:4px 0 8px;
}
.overview-group summary::-webkit-details-marker{ display:none; }
.overview-chevron{
  color:#8a8a90;
  font-weight:400;
  font-size:16px;
  line-height:1;
  transition:transform 160ms ease;
}
.overview-group[open] .overview-chevron{ transform:rotate(180deg); }
.overview-list{
  display:flex;
  flex-direction:column;
  gap:8px;
  padding:2px 0 4px 0;
}
.overview-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:12px;
  align-items:start;
  font-size:13px;
  color:#2a2a2d;
}
.overview-row .label{ color:#5d5d63; }
.overview-row .value{
  color:#3a3a40;
  font-weight:700;
}

.hosp-detail-empty{
  display:grid;
  place-items:center;
  min-height:220px;
  text-align:center;
  color:#76767c;
  padding:16px;
}
.hosp-detail-empty-title{
  font-size:15px;
  font-weight:700;
  color:#2a2a2d;
}
.hosp-detail-empty-sub{
  margin-top:8px;
  font-size:12px;
  line-height:1.55;
  color:#7a7a80;
  max-width:240px;
}

.hosp-detail-topline{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:14px;
}
.hosp-detail-label{
  font-size:14px;
  font-weight:800;
  color:#4d4d53;
}
.hosp-close{
  width:32px;
  height:32px;
  min-width:32px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.08);
  background:#fff;
  color:#2d2d2d;
  text-decoration:none;
  font-size:16px;
}
.hosp-close:hover{ background:#f7f7f8; }

.hosp-detail-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.hosp-detail-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#1d1d1f;
}
.hosp-detail-sub{
  margin-top:6px;
  color:#6f6f73;
  font-size:12px;
}

.hosp-detail-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
  margin-top:14px;
}
.hosp-field-label{
  font-size:11px;
  color:#8b8b91;
  margin-bottom:6px;
}
.hosp-input{
  width:100%;
  min-height:42px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,0.08);
  background:#f7f7f8;
  color:#232327;
  font-size:13px;
  line-height:1.35;
  padding:10px 12px;
  box-sizing:border-box;
  outline:none;
}
.hosp-section{
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid rgba(0,0,0,0.06);
}
.hosp-section-title{
  font-size:16px;
  font-weight:800;
  color:#1f1f22;
  margin-bottom:10px;
}
.hosp-detail-actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:16px;
  flex-wrap:wrap;
}

@media (max-width:980px){
  .hosp-head{
    flex-direction:column;
    align-items:flex-start;
  }
}
@media (max-width:560px){
  .hosp-detail-grid{
    grid-template-columns:1fr;
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
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo esc($projectTitle); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo esc($topDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo esc((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'hospitality';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <div class="hosp-head">
            <div class="left">
              <h2>Hotel and hospitality</h2>
              <p>Manage room allocations, check-ins, check-outs, and guest stay requests in one place.</p>
            </div>

            <div class="hosp-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId)); ?>">👁 Preview guest list</a>
              <button class="btn btn-primary" type="button">☆ Send invites</button>
            </div>
          </div>

          <div class="hosp-stat-row">
            <a class="hosp-stat-card <?php echo $bucket === 'rooms' ? 'is-active' : ''; ?>" href="<?php echo esc(hospitality_page_url($projectId, 'rooms')); ?>">
              <div class="hosp-stat-title">Rooms</div>
              <div class="hosp-stat-sub"><?php echo esc((string)$roomsCount); ?> guests unassigned</div>
            </a>

            <a class="hosp-stat-card <?php echo $bucket === 'checkins' ? 'is-active' : ''; ?>" href="<?php echo esc(hospitality_page_url($projectId, 'checkins')); ?>">
              <div class="hosp-stat-title">Check-ins</div>
              <div class="hosp-stat-sub"><?php echo esc((string)$checkinsCount); ?> scheduled</div>
            </a>

            <a class="hosp-stat-card <?php echo $bucket === 'checkouts' ? 'is-active' : ''; ?>" href="<?php echo esc(hospitality_page_url($projectId, 'checkouts')); ?>">
              <div class="hosp-stat-title">Check-outs</div>
              <div class="hosp-stat-sub"><?php echo esc((string)$checkoutsCount); ?> scheduled</div>
            </a>

            <a class="hosp-stat-card <?php echo $bucket === 'requests' ? 'is-active' : ''; ?>" href="<?php echo esc(hospitality_page_url($projectId, 'requests')); ?>">
              <div class="hosp-stat-title">Extra requests</div>
              <div class="hosp-stat-sub"><?php echo esc((string)$requestsCount); ?> guests</div>
            </a>
          </div>

          <div class="hosp-top-grid">
            <div class="hosp-main">
              <form method="get">
                <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">
                <input type="hidden" name="bucket" value="<?php echo esc($bucket); ?>">

                <div class="hosp-toolbar">
                  <div class="hosp-search">
                    <div class="hosp-search-wrap">
                      <span class="search-ico">🔍</span>
                      <input type="text" name="q" value="<?php echo esc($searchQ); ?>" placeholder="Search guest">
                    </div>
                  </div>

                  <div class="hosp-toolbar-right">
                    <button class="hosp-date-chip" type="button">Custom dates — dd/mm/yyyy to dd/mm/yyyy</button>
                  </div>
                </div>

                <div class="card proj-card hosp-table-card">
                  <?php if ($bucket === 'requests'): ?>
                    <?php if ($displayRows): ?>
                      <table class="hosp-table">
                        <thead>
                          <tr>
                            <th>Guest name</th>
                            <th>Notes</th>
                            <th>Luggage count</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($displayRows as $row): ?>
                            <?php
                              $rowUrl = hospitality_page_url(
                                $projectId,
                                $bucket,
                                $searchQ,
                                $filterSide,
                                $filterRoomType,
                                $filterBedType,
                                (int)$row['guest_id']
                              );
                              $isSelected = $selectedGuestId > 0 && (int)$row['guest_id'] === $selectedGuestId;
                            ?>
                            <tr
                              class="hosp-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
                              data-hosp-row-url="<?php echo esc($rowUrl); ?>"
                              tabindex="0"
                              role="button"
                              aria-label="View hospitality details for <?php echo esc($row['guest_name']); ?>"
                            >
                              <td class="hosp-name"><?php echo esc($row['guest_name']); ?></td>
                              <td class="hosp-notes">
                                <?php if ($row['request_notes'] !== ''): ?>
                                  <?php echo esc($row['request_notes']); ?>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php if ($row['luggage_count'] !== null): ?>
                                  <span class="h-chip neutral"><?php echo esc((string)$row['luggage_count']); ?></span>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <div class="empty-table">No guests match this view yet.</div>
                    <?php endif; ?>

                  <?php elseif ($bucket === 'checkins'): ?>
                    <?php if ($displayRows): ?>
                      <table class="hosp-table">
                        <thead>
                          <tr>
                            <th>Guest name</th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Side <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="side" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride’s side</option>
                                  <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom’s side</option>
                                  <option value="both" <?php echo $filterSide === 'both' ? 'selected' : ''; ?>>Both families</option>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Room type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="room_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($roomTypeOptions as $roomTypeOption): ?>
                                    <option value="<?php echo esc($roomTypeOption); ?>" <?php echo strtolower($filterRoomType) === strtolower($roomTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($roomTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Bed type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="bed_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($bedTypeOptions as $bedTypeOption): ?>
                                    <option value="<?php echo esc($bedTypeOption); ?>" <?php echo strtolower($filterBedType) === strtolower($bedTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($bedTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>Check in</th>
                            <th>Luggage count</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($displayRows as $row): ?>
                            <?php
                              $rowUrl = hospitality_page_url(
                                $projectId,
                                $bucket,
                                $searchQ,
                                $filterSide,
                                $filterRoomType,
                                $filterBedType,
                                (int)$row['guest_id']
                              );
                              $isSelected = $selectedGuestId > 0 && (int)$row['guest_id'] === $selectedGuestId;
                            ?>
                            <tr
                              class="hosp-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
                              data-hosp-row-url="<?php echo esc($rowUrl); ?>"
                              tabindex="0"
                              role="button"
                              aria-label="View hospitality details for <?php echo esc($row['guest_name']); ?>"
                            >
                              <td class="hosp-name"><?php echo esc($row['guest_name']); ?></td>
                              <td class="hosp-side-text"><?php echo esc($row['side_label']); ?></td>
                              <td><span class="h-chip <?php echo $row['room_type_raw'] !== '' ? 'ok' : 'missing'; ?>"><?php echo esc($row['room_type_label']); ?></span></td>
                              <td><span class="h-chip <?php echo $row['bed_type_raw'] !== '' ? 'neutral' : 'missing'; ?>"><?php echo esc($row['bed_type_label']); ?></span></td>
                              <td><span class="h-chip <?php echo $row['checkin'] !== '' ? 'neutral' : 'missing'; ?>"><?php echo esc($row['checkin_label']); ?></span></td>
                              <td>
                                <?php if ($row['luggage_count'] !== null): ?>
                                  <span class="h-chip neutral"><?php echo esc((string)$row['luggage_count']); ?></span>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <div class="empty-table">No guests match this view yet.</div>
                    <?php endif; ?>

                  <?php elseif ($bucket === 'checkouts'): ?>
                    <?php if ($displayRows): ?>
                      <table class="hosp-table">
                        <thead>
                          <tr>
                            <th>Guest name</th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Side <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="side" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride’s side</option>
                                  <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom’s side</option>
                                  <option value="both" <?php echo $filterSide === 'both' ? 'selected' : ''; ?>>Both families</option>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Room type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="room_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($roomTypeOptions as $roomTypeOption): ?>
                                    <option value="<?php echo esc($roomTypeOption); ?>" <?php echo strtolower($filterRoomType) === strtolower($roomTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($roomTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Bed type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="bed_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($bedTypeOptions as $bedTypeOption): ?>
                                    <option value="<?php echo esc($bedTypeOption); ?>" <?php echo strtolower($filterBedType) === strtolower($bedTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($bedTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>Check out</th>
                            <th>Luggage count</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($displayRows as $row): ?>
                            <?php
                              $rowUrl = hospitality_page_url(
                                $projectId,
                                $bucket,
                                $searchQ,
                                $filterSide,
                                $filterRoomType,
                                $filterBedType,
                                (int)$row['guest_id']
                              );
                              $isSelected = $selectedGuestId > 0 && (int)$row['guest_id'] === $selectedGuestId;
                            ?>
                            <tr
                              class="hosp-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
                              data-hosp-row-url="<?php echo esc($rowUrl); ?>"
                              tabindex="0"
                              role="button"
                              aria-label="View hospitality details for <?php echo esc($row['guest_name']); ?>"
                            >
                              <td class="hosp-name"><?php echo esc($row['guest_name']); ?></td>
                              <td class="hosp-side-text"><?php echo esc($row['side_label']); ?></td>
                              <td><span class="h-chip <?php echo $row['room_type_raw'] !== '' ? 'ok' : 'missing'; ?>"><?php echo esc($row['room_type_label']); ?></span></td>
                              <td><span class="h-chip <?php echo $row['bed_type_raw'] !== '' ? 'neutral' : 'missing'; ?>"><?php echo esc($row['bed_type_label']); ?></span></td>
                              <td><span class="h-chip <?php echo $row['checkout'] !== '' ? 'neutral' : 'missing'; ?>"><?php echo esc($row['checkout_label']); ?></span></td>
                              <td>
                                <?php if ($row['luggage_count'] !== null): ?>
                                  <span class="h-chip neutral"><?php echo esc((string)$row['luggage_count']); ?></span>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <div class="empty-table">No guests match this view yet.</div>
                    <?php endif; ?>

                  <?php else: ?>
                    <?php if ($displayRows): ?>
                      <table class="hosp-table">
                        <thead>
                          <tr>
                            <th>Guest name</th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Side <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="side" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride’s side</option>
                                  <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom’s side</option>
                                  <option value="both" <?php echo $filterSide === 'both' ? 'selected' : ''; ?>>Both families</option>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Room type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="room_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($roomTypeOptions as $roomTypeOption): ?>
                                    <option value="<?php echo esc($roomTypeOption); ?>" <?php echo strtolower($filterRoomType) === strtolower($roomTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($roomTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>
                              <div class="hosp-th-wrap">
                                <div class="hosp-th-top">Bed type <span class="chev">⌄</span></div>
                                <select class="hosp-th-filter" name="bed_type" onchange="this.form.submit()">
                                  <option value="">All</option>
                                  <?php foreach ($bedTypeOptions as $bedTypeOption): ?>
                                    <option value="<?php echo esc($bedTypeOption); ?>" <?php echo strtolower($filterBedType) === strtolower($bedTypeOption) ? 'selected' : ''; ?>>
                                      <?php echo esc($bedTypeOption); ?>
                                    </option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                            </th>
                            <th>Headcount</th>
                            <th>Luggage count</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($displayRows as $row): ?>
                            <?php
                              $rowUrl = hospitality_page_url(
                                $projectId,
                                $bucket,
                                $searchQ,
                                $filterSide,
                                $filterRoomType,
                                $filterBedType,
                                (int)$row['guest_id']
                              );
                              $isSelected = $selectedGuestId > 0 && (int)$row['guest_id'] === $selectedGuestId;
                            ?>
                            <tr
                              class="hosp-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
                              data-hosp-row-url="<?php echo esc($rowUrl); ?>"
                              tabindex="0"
                              role="button"
                              aria-label="View hospitality details for <?php echo esc($row['guest_name']); ?>"
                            >
                              <td class="hosp-name"><?php echo esc($row['guest_name']); ?></td>
                              <td class="hosp-side-text"><?php echo esc($row['side_label']); ?></td>
                              <td><span class="h-chip <?php echo $row['room_type_raw'] !== '' ? 'ok' : 'missing'; ?>"><?php echo esc($row['room_type_label']); ?></span></td>
                              <td><span class="h-chip <?php echo $row['bed_type_raw'] !== '' ? 'neutral' : 'missing'; ?>"><?php echo esc($row['bed_type_label']); ?></span></td>
                              <td>
                                <?php if ($row['headcount'] > 0): ?>
                                  <span class="h-chip neutral"><?php echo esc((string)$row['headcount']); ?></span>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php if ($row['luggage_count'] !== null): ?>
                                  <span class="h-chip neutral"><?php echo esc((string)$row['luggage_count']); ?></span>
                                <?php else: ?>
                                  <span class="h-chip missing">Missing</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    <?php else: ?>
                      <div class="empty-table">No guests match this view yet.</div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </form>
            </div>

            <aside class="hosp-side">
              <section class="card proj-card overview-card">
                <h3 class="overview-title">Accommodation details overview</h3>
                <p class="overview-sub">What needs cleaning before rooming and check-ins go out.</p>

                <div class="overview-wrap">
                  <details class="overview-group" open>
                    <summary>
                      <span>At risk</span>
                      <span class="overview-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="overview-list">
                      <div class="overview-row"><div class="label">Unassigned room type</div><div class="value"><?php echo esc((string)$missingRoomTypeCount); ?></div></div>
                      <div class="overview-row"><div class="label">Missing documents (for checkin)</div><div class="value"><?php echo esc((string)$missingDocumentCount); ?></div></div>
                      <div class="overview-row"><div class="label">Unassigned headcount</div><div class="value"><?php echo esc((string)$unassignedHeadcountCount); ?></div></div>
                    </div>
                  </details>

                  <details class="overview-group">
                    <summary>
                      <span>Stay Status</span>
                      <span class="overview-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="overview-list">
                      <div class="overview-row"><div class="label">Unassigned stay durations</div><div class="value"><?php echo esc((string)$unassignedStayDurationCount); ?></div></div>
                      <div class="overview-row"><div class="label">Currently checked in</div><div class="value"><?php echo esc((string)$currentCheckedInCount); ?></div></div>
                      <div class="overview-row"><div class="label">Checked out</div><div class="value"><?php echo esc((string)$checkedOutCount); ?></div></div>
                      <div class="overview-row"><div class="label">Currently checked in past duration</div><div class="value"><?php echo esc((string)$pastDurationCount); ?></div></div>
                    </div>
                  </details>

                  <details class="overview-group">
                    <summary>
                      <span>Room type</span>
                      <span class="overview-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="overview-list">
                      <?php if ($roomTypeCounts): ?>
                        <?php foreach ($roomTypeCounts as $label => $count): ?>
                          <div class="overview-row"><div class="label"><?php echo esc($label); ?></div><div class="value"><?php echo esc((string)$count); ?></div></div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="overview-row"><div class="label">No room types added yet</div><div class="value">0</div></div>
                      <?php endif; ?>
                    </div>
                  </details>

                  <details class="overview-group">
                    <summary>
                      <span>Bed type</span>
                      <span class="overview-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="overview-list">
                      <?php if ($bedTypeCounts): ?>
                        <?php foreach ($bedTypeCounts as $label => $count): ?>
                          <div class="overview-row"><div class="label"><?php echo esc($label); ?></div><div class="value"><?php echo esc((string)$count); ?></div></div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="overview-row"><div class="label">No bed types added yet</div><div class="value">0</div></div>
                      <?php endif; ?>
                    </div>
                  </details>
                </div>
              </section>

              <section class="card proj-card hosp-detail-card">
                <?php if ($selectedGuest): ?>
                  <?php
                    $closeUrl = hospitality_page_url(
                      $projectId,
                      $bucket,
                      $searchQ,
                      $filterSide,
                      $filterRoomType,
                      $filterBedType,
                      0
                    );
                  ?>

                  <div class="hosp-detail-topline">
                    <div class="hosp-detail-label">Guest detail</div>
                    <a class="hosp-close" href="<?php echo esc($closeUrl); ?>" aria-label="Close hospitality details">×</a>
                  </div>

                  <div class="hosp-detail-head">
                    <div>
                      <h3 class="hosp-detail-title"><?php echo esc($selectedGuestName); ?></h3>
                      <div class="hosp-detail-sub"><?php echo esc($selectedGuestSide); ?></div>
                    </div>
                  </div>

                  <div class="hosp-detail-grid">
                    <div>
                      <div class="hosp-field-label">Relation</div>
                      <input class="hosp-input" type="text" value="<?php echo esc($selectedGuestRelation !== '' ? $selectedGuestRelation : 'Missing'); ?>" readonly>
                    </div>
                    <div>
                      <div class="hosp-field-label">Family group</div>
                      <input class="hosp-input" type="text" value="<?php echo esc($selectedGuestFamilyGroup !== '' ? $selectedGuestFamilyGroup : 'Missing'); ?>" readonly>
                    </div>
                  </div>

                  <div class="hosp-section">
                    <div class="hosp-section-title">Accommodation information</div>

                    <div class="hosp-detail-grid">
                      <div>
                        <div class="hosp-field-label">Check in</div>
                        <input class="hosp-input" type="text" value="<?php echo esc($selectedCheckinLabel); ?>" readonly>
                      </div>
                      <div>
                        <div class="hosp-field-label">Check out</div>
                        <input class="hosp-input" type="text" value="<?php echo esc($selectedCheckoutLabel); ?>" readonly>
                      </div>
                    </div>

                    <div class="hosp-detail-grid" style="margin-top:10px;">
                      <div>
                        <div class="hosp-field-label">Room type</div>
                        <input class="hosp-input" type="text" value="<?php echo esc($selectedRoomTypeLabel); ?>" readonly>
                      </div>
                      <div>
                        <div class="hosp-field-label">Bed type</div>
                        <input class="hosp-input" type="text" value="<?php echo esc($selectedBedTypeLabel); ?>" readonly>
                      </div>
                    </div>

                    <div style="margin-top:10px;">
                      <div class="hosp-field-label">Identification document</div>
                      <input class="hosp-input" type="text" value="<?php echo esc($selectedIdDocument); ?>" readonly>
                    </div>

                    <div style="margin-top:10px;">
                      <div class="hosp-field-label">Accommodation remarks</div>
                      <input class="hosp-input" type="text" value="<?php echo esc($selectedStayNotes); ?>" readonly>
                    </div>
                  </div>

                  <div class="hosp-section">
                    <div class="hosp-section-title">Luggage</div>

                    <div>
                      <div class="hosp-field-label">Luggage count</div>
                      <input class="hosp-input" type="text" value="<?php echo esc($selectedLuggageCountLabel); ?>" readonly>
                    </div>
                  </div>

                  <div class="hosp-detail-actions">
                    <a class="btn btn-primary" href="<?php echo esc(base_url('guests/create.php?project_id=' . $projectId . '&guest_id=' . (int)($selectedGuest['id'] ?? 0))); ?>">Edit guest</a>
                  </div>
                <?php else: ?>
                  <div class="hosp-detail-empty">
                    <div>
                      <div class="hosp-detail-empty-title">Select a guest</div>
                      <div class="hosp-detail-empty-sub">
                        Click any guest row to open the hospitality detail panel on the right.
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              </section>
            </aside>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const rows = document.querySelectorAll("[data-hosp-row-url]");

  rows.forEach((row) => {
    const url = row.getAttribute("data-hosp-row-url");
    if (!url) return;

    row.addEventListener("click", function (e) {
      if (e.target.closest("a, button, input, textarea, select, label")) return;
      window.location.href = url;
    });

    row.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        window.location.href = url;
      }
    });
  });
});
</script>

<?php require_once $root . '/includes/footer.php'; ?>