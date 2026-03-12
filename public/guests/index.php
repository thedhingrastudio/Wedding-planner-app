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

$companyId       = current_company_id();
$searchQ         = trim((string)($_GET['q'] ?? ''));
$selectedGuestId = (int)($_GET['guest_id'] ?? 0);
$filterSide      = trim((string)($_GET['side'] ?? ''));
$filterContact   = trim((string)($_GET['contact'] ?? ''));
$filterGroup     = trim((string)($_GET['group'] ?? ''));
$filterTag       = trim((string)($_GET['tag'] ?? ''));

$allowedSides = ['bride', 'groom'];
$allowedContacts = ['mobile', 'email', 'both', 'none'];
$allowedTags = ['vip', 'elder', 'none'];

if (!in_array($filterSide, $allowedSides, true)) $filterSide = '';
if (!in_array($filterContact, $allowedContacts, true)) $filterContact = '';
if (!in_array($filterTag, $allowedTags, true)) $filterTag = '';

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

function has_any_value(array $values): bool {
  foreach ($values as $value) {
    if (trim((string)$value) !== '') return true;
  }
  return false;
}

function side_label(string $side): string {
  return match ($side) {
    'bride' => "Bride's side",
    'groom' => "Groom's side",
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

function guest_pick(array $row, array $keys): string {
  foreach ($keys as $key) {
    $value = trim((string)($row[$key] ?? ''));
    if ($value !== '') return $value;
  }
  return '';
}

function guest_group_value(array $row): string {
  return first_non_empty([
    $row['family_group'] ?? '',
    $row['group_name'] ?? '',
  ]);
}

function guest_yes_no(array $row, array $keys): string {
  foreach ($keys as $key) {
    if (!array_key_exists($key, $row)) continue;
    $raw = trim((string)$row[$key]);
    if ($raw === '') continue;

    $normalized = strtolower($raw);
    if (in_array($normalized, ['1', 'yes', 'y', 'true'], true)) return 'Yes';
    if (in_array($normalized, ['0', 'no', 'n', 'false'], true)) return 'No';
    return ucfirst(str_replace('_', ' ', $raw));
  }
  return 'Not added';
}

function guest_section_status(bool $isComplete): array {
  return $isComplete
    ? ['label' => 'Complete', 'class' => 'complete']
    : ['label' => 'Incomplete', 'class' => 'incomplete'];
}

function guest_readonly_value(string $value, string $fallback = 'Not added'): string {
  $value = trim($value);
  return $value !== '' ? $value : $fallback;
}

function guest_has_vip(array $row): bool {
  foreach (['vip', 'is_vip', 'guest_vip', 'vip_guest'] as $key) {
    if (!array_key_exists($key, $row)) continue;
    $raw = strtolower(trim((string)$row[$key]));
    if (in_array($raw, ['1', 'yes', 'true', 'vip'], true)) return true;
  }

  $tagText = strtolower(first_non_empty([
    $row['tag'] ?? '',
    $row['tags'] ?? '',
    $row['guest_tag'] ?? '',
    $row['guest_tags'] ?? '',
    $row['category'] ?? '',
    $row['guest_category'] ?? '',
  ]));

  return $tagText !== '' && str_contains($tagText, 'vip');
}

function guest_tags_from_row(array $row): array {
  $tags = [];

  $accessibility = trim((string)($row['accessibility'] ?? ''));
  $diet = trim((string)($row['diet_preference'] ?? ''));
  $plusOne = (int)($row['plus_one_allowed'] ?? 0);

  $rawTagText = strtolower(first_non_empty([
    $row['tag'] ?? '',
    $row['tags'] ?? '',
    $row['guest_tag'] ?? '',
    $row['guest_tags'] ?? '',
  ]));

  if (guest_has_vip($row)) $tags[] = 'VIP';
  if ($accessibility === 'elder_care' || str_contains($rawTagText, 'elder')) $tags[] = 'Elder';
  if ($accessibility === 'wheelchair') $tags[] = 'Assist';
  if ($accessibility === 'medical') $tags[] = 'Medical';
  if ($accessibility === 'toddler_care') $tags[] = 'Toddler care';
  if ($diet === 'jain') $tags[] = 'Jain';
  if ($diet === 'vegan') $tags[] = 'Vegan';
  if ($plusOne === 1) $tags[] = 'Plus-one';

  return array_values(array_unique($tags));
}

function guest_matches_filters(
  array $row,
  string $searchQ,
  string $filterSide,
  string $filterContact,
  string $filterGroup,
  string $filterTag
): bool {
  $fullName = strtolower(guest_full_name($row));
  $phone    = trim((string)($row['phone'] ?? ''));
  $email    = trim((string)($row['email'] ?? ''));
  $group    = guest_group_value($row);
  $tags     = guest_tags_from_row($row);

  if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $haystack = strtolower(implode(' ', [
      $fullName,
      $phone,
      $email,
      $group,
      implode(' ', $tags),
      (string)($row['relation_label'] ?? ''),
    ]));
    if (!str_contains($haystack, $needle)) {
      return false;
    }
  }

  if ($filterSide !== '' && (string)($row['invited_by'] ?? '') !== $filterSide) {
    return false;
  }

  if ($filterContact !== '') {
    $hasPhone = $phone !== '';
    $hasEmail = $email !== '';

    $contactMatch = match ($filterContact) {
      'mobile' => $hasPhone && !$hasEmail,
      'email'  => !$hasPhone && $hasEmail,
      'both'   => $hasPhone && $hasEmail,
      'none'   => !$hasPhone && !$hasEmail,
      default  => true,
    };

    if (!$contactMatch) {
      return false;
    }
  }

  if ($filterGroup !== '' && strtolower($group) !== strtolower($filterGroup)) {
    return false;
  }

  if ($filterTag !== '') {
    $hasVip = in_array('VIP', $tags, true);
    $hasElder = in_array('Elder', $tags, true);
    $hasNoTags = count($tags) === 0;

    $tagMatch = match ($filterTag) {
      'vip'   => $hasVip,
      'elder' => $hasElder,
      'none'  => $hasNoTags,
      default => true,
    };

    if (!$tagMatch) {
      return false;
    }
  }

  return true;
}

function guest_page_url(
  int $projectId,
  string $searchQ = '',
  int $guestId = 0,
  string $side = '',
  string $contact = '',
  string $group = '',
  string $tag = ''
): string {
  $params = ['project_id' => $projectId];
  if ($searchQ !== '') $params['q'] = $searchQ;
  if ($guestId > 0) $params['guest_id'] = $guestId;
  if ($side !== '') $params['side'] = $side;
  if ($contact !== '') $params['contact'] = $contact;
  if ($group !== '') $params['group'] = $group;
  if ($tag !== '') $params['tag'] = $tag;

  return base_url('guests/index.php?' . http_build_query($params));
}

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

$guestTableExists = table_exists_local($pdo, 'guests');

$allGuestRows = [];
$displayGuestRows = [];
$groupOptions = [];

$guestCountRows = 0;
$guestHeadCountTotal = 0;
$guestAdultCount = 0;
$guestChildrenCount = 0;
$missingPhoneCount = 0;
$missingEmailCount = 0;
$ungroupedCount = 0;
$groupsCreatedCount = 0;
$careTagCount = 0;
$duplicateNames = [];
$selectedGuest = null;

if ($guestTableExists) {
  $sql = "
    SELECT *
    FROM guests
    WHERE project_id = :pid
    ORDER BY created_at DESC, id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':pid' => $projectId]);
  $allGuestRows = $st->fetchAll() ?: [];

  $groupMap = [];
  $nameMap = [];

  foreach ($allGuestRows as $row) {
    $seatCount = max(1, (int)($row['seat_count'] ?? 1));
    $childrenCount = max(0, (int)($row['children_count'] ?? 0));

    $guestHeadCountTotal += $seatCount;
    $guestChildrenCount += $childrenCount;
    $guestAdultCount += max($seatCount - $childrenCount, 0);

    $phone = trim((string)($row['phone'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $group = guest_group_value($row);

    if ($phone === '') $missingPhoneCount++;
    if ($email === '') $missingEmailCount++;
    if ($group === '') $ungroupedCount++;

    if ($group !== '') {
      $groupMap[strtolower($group)] = $group;
    }

    $accessibility = trim((string)($row['accessibility'] ?? ''));
    if (in_array($accessibility, ['elder_care', 'wheelchair', 'medical', 'toddler_care'], true)) {
      $careTagCount++;
    }

    $fullName = strtolower(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')));
    if ($fullName !== '') {
      $nameMap[$fullName] = ($nameMap[$fullName] ?? 0) + 1;
    }
  }

  foreach ($nameMap as $name => $count) {
    if ($count > 1) $duplicateNames[$name] = $count;
  }

  $groupOptions = array_values($groupMap);
  usort($groupOptions, static fn(string $a, string $b): int => strcasecmp($a, $b));

  $groupsCreatedCount = count($groupMap);
  $guestCountRows = count($allGuestRows);

  foreach ($allGuestRows as $row) {
    if (guest_matches_filters($row, $searchQ, $filterSide, $filterContact, $filterGroup, $filterTag)) {
      $displayGuestRows[] = $row;
    }
  }

  foreach ($displayGuestRows as $row) {
    if ((int)($row['id'] ?? 0) === $selectedGuestId) {
      $selectedGuest = $row;
      break;
    }
  }
}

$projectBriefEstimate = (int)($project['guest_count_est'] ?? 0);
$hasGuests = $guestCountRows > 0;
$hasDisplayGuests = count($displayGuestRows) > 0;

$overviewTotalLabel = $hasGuests
  ? number_format($guestHeadCountTotal)
  : ($projectBriefEstimate > 0 ? number_format($projectBriefEstimate) : '—');

$overviewAdultsLabel   = $hasGuests ? number_format($guestAdultCount) : '—';
$overviewChildrenLabel = $hasGuests ? number_format($guestChildrenCount) : '—';
$missingPhoneLabel     = $hasGuests ? number_format($missingPhoneCount) : '—';
$missingEmailLabel     = $hasGuests ? number_format($missingEmailCount) : '—';

$missingContactsNeedsAttention = $hasGuests && ($missingPhoneCount > 0 || $missingEmailCount > 0);
$duplicateNeedsAttention = $hasGuests && count($duplicateNames) > 0;
$groupsNeedsAttention = $hasGuests && $ungroupedCount > 0;

$guestBaseUrl = guest_page_url($projectId);
$hasActiveFilters = $searchQ !== '' || $filterSide !== '' || $filterContact !== '' || $filterGroup !== '' || $filterTag !== '';

$pageTitle = $projectTitle . ' — Guest list setup — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.proj-main{ min-width:0; }

.guest-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:16px;
}
.guest-head .left h2{
  margin:0;
  font-size:22px;
  line-height:1.15;
  font-weight:800;
  color:#1d1d1f;
}
.guest-head .left p{
  margin:8px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.45;
}
.guest-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:12px;
  font-size:11px;
  padding:6px 10px;
  border:1px solid rgba(0,0,0,0.06);
  border-radius:999px;
  background:#fff;
  color:#4b4b4f;
  vertical-align:middle;
}
.guest-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.guest-actions .icon-btn{
  width:42px;
  height:42px;
  min-width:42px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  padding:0;
}
.btn[disabled]{
  opacity:.55;
  cursor:not-allowed;
  pointer-events:none;
}

.guest-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr) 320px;
  gap:14px;
  align-items:start;
  margin-bottom:14px;
}
@media (max-width:1180px){
  .guest-grid{ grid-template-columns:1fr; }
}

.guest-panel{
  display:flex;
  flex-direction:column;
  padding:16px;
  border-radius:22px;
}
.guest-panel--compact{ min-height:182px; }
.guest-panel--health{ padding:16px; }

.guest-panel-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#222;
}
.guest-panel-sub{
  margin:6px 0 0 0;
  color:#75757a;
  font-size:13px;
  line-height:1.45;
}
.guest-file-list{
  margin:14px 0 0 0;
  padding-left:18px;
  color:#222;
  font-size:13px;
  line-height:1.65;
}
.helper-soft{
  margin-top:14px;
  padding:14px 16px;
  border-radius:18px;
  background:#f7f7f8;
  border:1px solid rgba(0,0,0,0.03);
  color:#6d6d72;
  font-size:13px;
  line-height:1.55;
}
.soft-stat{
  margin-top:14px;
  padding:12px 14px;
  border:1px solid rgba(0,0,0,0.04);
  border-radius:18px;
  background:#f7f7f8;
  font-size:13px;
  color:#2c2c2f;
}
.card-actions-end{
  margin-top:auto;
  padding-top:16px;
  display:flex;
  justify-content:flex-end;
  align-items:flex-end;
}

.health-wrap{
  display:flex;
  flex-direction:column;
  gap:2px;
  margin-top:10px;
}
.health-group{
  border-top:1px solid rgba(0,0,0,0.06);
  padding-top:10px;
}
.health-group:first-child{
  border-top:none;
  padding-top:0;
}
.health-group summary{
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
.health-group summary::-webkit-details-marker{ display:none; }
.health-summary-meta{
  display:inline-flex;
  align-items:center;
  gap:10px;
  flex-shrink:0;
}
.health-chevron{
  color:#8a8a90;
  font-weight:400;
  font-size:16px;
  line-height:1;
  transition:transform 160ms ease;
}
.health-group[open] .health-chevron{ transform:rotate(180deg); }
.health-attention-dot{
  width:12px;
  height:12px;
  border-radius:999px;
  background:#ef5a4f;
  display:inline-block;
}
.health-list{
  display:flex;
  flex-direction:column;
  gap:10px;
  padding:2px 0 4px 0;
}
.health-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:12px;
  align-items:start;
  font-size:13px;
  color:#2a2a2d;
}
.health-row .label{ color:#5d5d63; }
.health-row .value{
  color:#3a3a40;
  font-weight:700;
}
.subtle-note{
  margin-top:8px;
  color:#7b7b82;
  font-size:12px;
}

.guest-data-layout{
  display:grid;
  grid-template-columns:minmax(0,1fr) 320px;
  gap:14px;
  align-items:start;
}
@media (max-width:1360px){
  .guest-data-layout{ grid-template-columns:1fr; }
}
.guest-table-shell{ min-width:0; }

.guest-filter-form{ min-width:0; }

.guest-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
  flex-wrap:wrap;
}
.guest-search{ flex:1 1 360px; }
.guest-search-wrap{ position:relative; }
.guest-search-wrap .search-ico{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#9a9aa1;
  font-size:13px;
  pointer-events:none;
}
.guest-search input{
  width:100%;
  min-height:40px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.08);
  background:#fff;
  padding:10px 14px 10px 36px;
  box-sizing:border-box;
  color:#1f1f22;
}
.guest-search input::placeholder{ color:#9b9ba2; }

.guest-toolbar-actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.table-select-btn{ white-space:nowrap; }

.guest-table-card{
  padding:6px 12px 8px;
  border-radius:24px;
  overflow:hidden;
}
.guest-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.guest-table thead th{
  text-align:left;
  padding:12px 12px 12px;
  font-size:12px;
  color:#9a9aa1;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
  vertical-align:top;
}
.guest-table thead th.guest-col-name{
  width:180px;
  min-width:180px;
  padding-top:20px;
}
.guest-th-wrap{
  display:flex;
  flex-direction:column;
  gap:6px;
}
.guest-th-top{
  display:flex;
  align-items:center;
  gap:6px;
  color:#9a9aa1;
  font-size:12px;
  font-weight:700;
}
.guest-th-top .chev{
  font-size:11px;
  color:#b0b0b6;
}
.guest-th-filter{
  width:100%;
  min-height:30px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.06);
  background:#fff;
  color:#5f5f66;
  font-size:11px;
  padding:0 10px;
  outline:none;
}
.guest-th-filter:focus{
  border-color:rgba(0,0,0,0.14);
}
.guest-table tbody td{
  text-align:left;
  padding:14px 16px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:14px;
  color:#1f1f22;
  transition:background 160ms ease;
}
.guest-table tbody tr:last-child td{ border-bottom:none; }
.guest-table-row{ cursor:pointer; }
.guest-table-row:hover td{ background:rgba(0,0,0,0.02); }
.guest-table-row.is-selected td{
  background:rgba(75,0,31,0.045);
}

.guest-table-row.is-selected td:first-child{
  border-top-left-radius:18px;
  border-bottom-left-radius:18px;
}

.guest-table-row.is-selected td:last-child{
  border-top-right-radius:18px;
  border-bottom-right-radius:18px;
}
.guest-table-row:focus{ outline:none; }

.guest-name{
  font-weight:500;
  color:#1d1d1f;
  line-height:1.35;
  min-width:180px;
  width:180px;
  white-space:normal;
  word-break:normal;
  overflow-wrap:break-word;
}
.guest-side{
  color:#4f4f55;
  font-size:13px;
}

.table-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:28px;
  padding:0 12px;
  border-radius:999px;
  font-size:12px;
  white-space:nowrap;
  border:none;
}
.table-chip.ok{
  background:#dff1cf;
  color:#54733e;
}
.table-chip.warn{
  background:#f4cccc;
  color:#875050;
}
.table-chip.neutral{
  background:#efefef;
  color:#7b7b82;
}
.tag-stack{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.empty-table{
  padding:16px;
  border-radius:18px;
  background:rgba(0,0,0,0.02);
  color:#75757a;
  font-size:13px;
  line-height:1.5;
}
.guest-tip{
  margin-top:14px;
  padding:14px 16px;
  border-radius:18px;
  border:1px dashed rgba(0,0,0,0.10);
  background:#fff;
  color:#727279;
  font-size:13px;
  line-height:1.55;
}

.guest-detail-card{
  padding:16px;
  border-radius:24px;
  position:sticky;
  top:14px;
  max-height:calc(100vh - 34px);
  overflow:auto;
}
@media (max-width:1360px){
  .guest-detail-card{
    position:static;
    max-height:none;
    overflow:visible;
  }
}
.guest-detail-topline{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:14px;
}
.guest-detail-label{
  font-size:14px;
  font-weight:800;
  color:#4d4d53;
}
.guest-close{
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
.guest-close:hover{ background:#f7f7f8; }

.guest-detail-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.guest-detail-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#1d1d1f;
}
.guest-detail-sub{
  margin-top:6px;
  color:#6f6f73;
  font-size:12px;
}
.guest-edit-btn{
  white-space:nowrap;
  padding:8px 12px;
  font-size:12px;
}

.guest-detail-divider{
  display:flex;
  align-items:center;
  gap:10px;
  margin:14px 0;
  color:#aaa9b1;
}
.guest-detail-divider::before,
.guest-detail-divider::after{
  content:"";
  flex:1;
  height:1px;
  background:rgba(0,0,0,0.08);
}
.guest-detail-divider span{
  font-size:12px;
  line-height:1;
}

.guest-top-grid{
  display:grid;
  grid-template-columns:1fr 110px;
  gap:10px;
}
.guest-form-block{ margin-top:2px; }
.guest-form-block + .guest-form-block{ margin-top:12px; }
.guest-mini-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.guest-field-label{
  font-size:11px;
  color:#8b8b91;
  margin-bottom:6px;
}
.guest-readonly,
.guest-readonly-pill,
.guest-readonly-select{
  min-height:42px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,0.08);
  background:#f7f7f8;
  color:#232327;
  font-size:13px;
  line-height:1.35;
  display:flex;
  align-items:center;
  padding:10px 12px;
}
.guest-readonly.is-muted,
.guest-readonly-pill.is-muted,
.guest-readonly-select.is-muted{
  color:#97979d;
}
.guest-readonly-select{
  justify-content:space-between;
  gap:12px;
}
.guest-readonly-select::after{
  content:"⌄";
  color:#a2a2a8;
  font-size:14px;
}

.guest-section{
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid rgba(0,0,0,0.06);
}
.guest-section-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:10px;
}
.guest-section-title{
  font-size:16px;
  font-weight:800;
  color:#1f1f22;
}
.guest-section-meta{
  display:flex;
  align-items:center;
  gap:8px;
}
.guest-status-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:22px;
  padding:0 10px;
  border-radius:999px;
  font-size:11px;
  white-space:nowrap;
}
.guest-status-chip.complete{
  background:#dff1cf;
  color:#54733e;
}
.guest-status-chip.incomplete{
  background:#f4cccc;
  color:#875050;
}
.guest-status-chip.neutral{
  background:#efefef;
  color:#6f6f73;
}
.guest-section-arrow{
  color:#a2a2a8;
  font-size:16px;
  line-height:1;
}

.guest-stack{ display:grid; gap:10px; }

.guest-detail-empty{
  display:grid;
  place-items:center;
  min-height:260px;
  text-align:center;
  color:#76767c;
  padding:16px;
}
.guest-detail-empty-title{
  font-size:15px;
  font-weight:700;
  color:#2a2a2d;
}
.guest-detail-empty-sub{
  margin-top:6px;
  font-size:12px;
  line-height:1.5;
  color:#7a7a80;
}

@media (max-width:980px){
  .guest-head{
    flex-direction:column;
    align-items:flex-start;
  }
}
@media (max-width:560px){
  .guest-top-grid,
  .guest-mini-grid{
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
          $active = 'guests';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <div class="guest-head">
            <div class="left">
              <h2>
                Guest list setup
                <span class="guest-badge">In progress</span>
              </h2>
              <p>Import and clean the master list before invites go out.</p>
            </div>

            <div class="guest-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <button class="btn" type="button" <?php echo !$hasGuests ? 'disabled' : ''; ?>>👁 Preview guest list</button>
              <button class="btn btn-primary" type="button" <?php echo !$hasGuests ? 'disabled' : ''; ?>>☆ Send invites</button>
            </div>
          </div>

          <div class="guest-grid">
            <div class="card proj-card guest-panel guest-panel--compact">
              <h3 class="guest-panel-title">Import guest list</h3>
              <div class="guest-panel-sub">Upload the client’s Excel or CSV and organize it before invites go out.</div>

              <ul class="guest-file-list">
                <li>Expected sheet: Groom’s side guest list</li>
                <li>Expected sheet: Bride’s side guest list</li>
              </ul>

              <?php if ($hasGuests): ?>
                <div class="soft-stat">
                  <?php echo esc(number_format($guestCountRows)); ?> guest records currently in this project.
                </div>
              <?php else: ?>
                <div class="helper-soft">
                  No files attached yet. Start with the client’s master sheet or upload separate bride-side and groom-side lists.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
                <button class="btn" type="button">Upload file</button>
              </div>
            </div>

            <div class="card proj-card guest-panel guest-panel--compact">
              <h3 class="guest-panel-title">Manually add guests</h3>
              <div class="guest-panel-sub">Fill guest details manually to add to the guest list.</div>

              <?php if ($hasGuests): ?>
                <div class="helper-soft">
                  Manual guest entry is active. Add individuals, families, and last-minute guests here.
                </div>
              <?php else: ?>
                <div class="helper-soft">
                  No guests added yet. Use this when the client shares a few names first or when you need to add last-minute guests manually.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
                <a class="btn" href="<?php echo esc(base_url('guests/create.php?project_id=' . $projectId)); ?>">Add guest</a>
              </div>
            </div>

            <div class="card proj-card guest-panel guest-panel--health">
              <h3 class="guest-panel-title">Guest list health</h3>
              <div class="guest-panel-sub">What needs cleaning before invites go out.</div>

              <div class="health-wrap">
                <details class="health-group">
                  <summary>
                    <span>Guest overview</span>
                    <span class="health-summary-meta">
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </span>
                  </summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Estimated head count (total)</div>
                      <div class="value"><?php echo esc($overviewTotalLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Estimated head count (adults)</div>
                      <div class="value"><?php echo esc($overviewAdultsLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Estimated head count (children)</div>
                      <div class="value"><?php echo esc($overviewChildrenLabel); ?></div>
                    </div>
                  </div>
                  <?php if ($projectBriefEstimate > 0): ?>
                    <div class="subtle-note">Project brief estimate: <?php echo esc(number_format($projectBriefEstimate)); ?></div>
                  <?php endif; ?>
                </details>

                <details class="health-group">
                  <summary>
                    <span>Missing contacts</span>
                    <span class="health-summary-meta">
                      <?php if ($missingContactsNeedsAttention): ?>
                        <span class="health-attention-dot" aria-hidden="true"></span>
                      <?php endif; ?>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </span>
                  </summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Missing phone number</div>
                      <div class="value"><?php echo esc($missingPhoneLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Missing emails</div>
                      <div class="value"><?php echo esc($missingEmailLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Unassigned groups</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$ungroupedCount) : '—'; ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">VIP / Elder care tags</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$careTagCount) : '—'; ?></div>
                    </div>
                  </div>
                </details>

                <details class="health-group">
                  <summary>
                    <span>Duplicate review</span>
                    <span class="health-summary-meta">
                      <?php if ($duplicateNeedsAttention): ?>
                        <span class="health-attention-dot" aria-hidden="true"></span>
                      <?php endif; ?>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </span>
                  </summary>
                  <div class="health-list">
                    <?php if ($duplicateNames): ?>
                      <?php foreach ($duplicateNames as $name => $count): ?>
                        <div class="health-row">
                          <div class="label"><?php echo esc(ucwords($name)); ?></div>
                          <div class="value"><?php echo esc((string)$count); ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="health-row">
                        <div class="label">No duplicate names flagged yet</div>
                        <div class="value">0</div>
                      </div>
                    <?php endif; ?>
                  </div>
                </details>

                <details class="health-group">
                  <summary>
                    <span>Guest groups</span>
                    <span class="health-summary-meta">
                      <?php if ($groupsNeedsAttention): ?>
                        <span class="health-attention-dot" aria-hidden="true"></span>
                      <?php endif; ?>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </span>
                  </summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Groups created</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$groupsCreatedCount) : '—'; ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Ungrouped</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$ungroupedCount) : '—'; ?></div>
                    </div>
                  </div>
                </details>
              </div>
            </div>
          </div>

          <div class="guest-data-layout">
            <div class="guest-table-shell">
              <form class="guest-filter-form" method="get">
                <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">

                <div class="guest-toolbar">
                  <div class="guest-search">
                    <div class="guest-search-wrap">
                      <span class="search-ico">🔍</span>
                      <input type="text" name="q" value="<?php echo esc($searchQ); ?>" placeholder="Search guest name, contact, or group">
                    </div>
                  </div>

                  <div class="guest-toolbar-actions">
                    <?php if ($hasActiveFilters): ?>
                      <a class="btn" href="<?php echo esc($guestBaseUrl); ?>">Reset filters</a>
                    <?php endif; ?>
                    <button class="btn table-select-btn" type="button">✓ Select all</button>
                  </div>
                </div>

                <div class="card proj-card guest-table-card">
                  <?php if ($hasDisplayGuests): ?>
                    <table class="guest-table">
                      <thead>
                        <tr>
                          <th class="guest-col-name">Guest name</th>

                          <th>
                            <div class="guest-th-wrap">
                              <div class="guest-th-top">Side <span class="chev">⌄</span></div>
                              <select class="guest-th-filter" name="side" onchange="this.form.submit()">
                                <option value="">All sides</option>
                                <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride's side</option>
                                <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom's side</option>
                              </select>
                            </div>
                          </th>

                          <th>
                            <div class="guest-th-wrap">
                              <div class="guest-th-top">Contact <span class="chev">⌄</span></div>
                              <select class="guest-th-filter" name="contact" onchange="this.form.submit()">
                                <option value="">All contacts</option>
                                <option value="mobile" <?php echo $filterContact === 'mobile' ? 'selected' : ''; ?>>Mobile number</option>
                                <option value="email" <?php echo $filterContact === 'email' ? 'selected' : ''; ?>>Email</option>
                                <option value="both" <?php echo $filterContact === 'both' ? 'selected' : ''; ?>>Both</option>
                                <option value="none" <?php echo $filterContact === 'none' ? 'selected' : ''; ?>>None</option>
                              </select>
                            </div>
                          </th>

                          <th>
                            <div class="guest-th-wrap">
                              <div class="guest-th-top">Group <span class="chev">⌄</span></div>
                              <select class="guest-th-filter" name="group" onchange="this.form.submit()">
                                <option value="">All groups</option>
                                <?php foreach ($groupOptions as $groupOption): ?>
                                  <option value="<?php echo esc($groupOption); ?>" <?php echo strtolower($filterGroup) === strtolower($groupOption) ? 'selected' : ''; ?>>
                                    <?php echo esc($groupOption); ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                          </th>

                          <th>
                            <div class="guest-th-wrap">
                              <div class="guest-th-top">Tag <span class="chev">⌄</span></div>
                              <select class="guest-th-filter" name="tag" onchange="this.form.submit()">
                                <option value="">All tags</option>
                                <option value="vip" <?php echo $filterTag === 'vip' ? 'selected' : ''; ?>>VIP</option>
                                <option value="elder" <?php echo $filterTag === 'elder' ? 'selected' : ''; ?>>Elder</option>
                                <option value="none" <?php echo $filterTag === 'none' ? 'selected' : ''; ?>>None</option>
                              </select>
                            </div>
                          </th>
                        </tr>
                      </thead>

                      <tbody>
                        <?php foreach ($displayGuestRows as $row): ?>
                          <?php
                            $rowId = (int)($row['id'] ?? 0);
                            $fullName = guest_full_name($row);
                            $phone = trim((string)($row['phone'] ?? ''));
                            $email = trim((string)($row['email'] ?? ''));
                            $group = guest_group_value($row);
                            $tags  = guest_tags_from_row($row);

                            if ($phone !== '' && $email !== '') {
                              $contactLabel = 'Phone + email';
                              $contactTone = 'ok';
                            } elseif ($phone !== '') {
                              $contactLabel = 'Phone no.';
                              $contactTone = 'ok';
                            } elseif ($email !== '') {
                              $contactLabel = 'Email';
                              $contactTone = 'ok';
                            } else {
                              $contactLabel = 'Missing';
                              $contactTone = 'warn';
                            }

                            $rowUrl = guest_page_url(
                              $projectId,
                              $searchQ,
                              $rowId,
                              $filterSide,
                              $filterContact,
                              $filterGroup,
                              $filterTag
                            );
                            $isSelected = $selectedGuestId > 0 && $rowId === $selectedGuestId;
                          ?>
                          <tr
                            class="guest-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
                            data-guest-row-url="<?php echo esc($rowUrl); ?>"
                            tabindex="0"
                            role="button"
                            aria-label="View details for <?php echo esc($fullName); ?>"
                          >
                            <td class="guest-name"><?php echo esc($fullName); ?></td>
                            <td class="guest-side"><?php echo esc(side_label((string)($row['invited_by'] ?? ''))); ?></td>
                            <td>
                              <span class="table-chip <?php echo esc($contactTone); ?>">
                                <?php echo esc($contactLabel); ?>
                              </span>
                            </td>
                            <td>
                              <?php if ($group !== ''): ?>
                                <span class="table-chip ok"><?php echo esc($group); ?></span>
                              <?php else: ?>
                                <span class="table-chip neutral">-</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if ($tags): ?>
                                <div class="tag-stack">
                                  <?php foreach ($tags as $tagItem): ?>
                                    <span class="table-chip ok"><?php echo esc($tagItem); ?></span>
                                  <?php endforeach; ?>
                                </div>
                              <?php else: ?>
                                <span class="table-chip neutral">-</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php elseif ($hasGuests): ?>
                    <div class="empty-table">
                      No guests match the filters you selected. Try clearing one or more filters.
                    </div>
                  <?php else: ?>
                    <div class="empty-table">
                      No guests saved yet. Once you save the first guest from the manual form, the table will appear here.
                    </div>
                  <?php endif; ?>
                </div>
              </form>

              <?php if (!$hasGuests): ?>
                <div class="guest-tip">
                  This is the empty state for the guest workflow. After the first guest is saved, this page becomes the working guest list with search, counts, and cleanup checks.
                </div>
              <?php endif; ?>
            </div>

            <aside class="card proj-card guest-detail-card">
              <?php if ($selectedGuest && $hasDisplayGuests): ?>
                <?php
                  $selectedName = guest_full_name($selectedGuest);
                  $selectedSide = side_label((string)($selectedGuest['invited_by'] ?? ''));
                  $selectedRelation = guest_pick($selectedGuest, ['relation', 'relationship', 'relation_name', 'relation_label']);
                  $selectedGroup = guest_group_value($selectedGuest);
                  $selectedPhone = guest_pick($selectedGuest, ['phone', 'mobile', 'phone_number']);
                  $selectedEmail = guest_pick($selectedGuest, ['email', 'email_address']);
                  $selectedNotes = guest_pick($selectedGuest, ['notes', 'special_notes', 'special_note', 'internal_notes']);
                  $selectedRsvp = guest_pick($selectedGuest, ['rsvp_status', 'rsvp', 'invite_status', 'status']);
                  $selectedRespondedOn = guest_pick($selectedGuest, ['responded_on', 'rsvp_responded_at', 'responded_at', 'updated_at']);
                  $selectedTags = guest_tags_from_row($selectedGuest);
                  $selectedTagText = $selectedTags ? implode(', ', $selectedTags) : guest_pick($selectedGuest, ['guest_tag', 'tag', 'tags', 'guest_tags']);

                  $contactComplete = has_any_value([$selectedPhone, $selectedEmail]);
                  $contactStatus = guest_section_status($contactComplete);

                  $editUrl = base_url('guests/create.php?' . http_build_query([
                    'project_id' => $projectId,
                    'guest_id'   => (int)($selectedGuest['id'] ?? 0),
                  ]));
                ?>

                <div class="guest-detail-topline">
                  <div class="guest-detail-label">Guest detail</div>
                  <a class="guest-close" href="<?php echo esc(guest_page_url($projectId, $searchQ, 0, $filterSide, $filterContact, $filterGroup, $filterTag)); ?>" aria-label="Close guest details">×</a>
                </div>

                <div class="guest-detail-head">
                  <div>
                    <h3 class="guest-detail-title"><?php echo esc($selectedName); ?></h3>
                    <div class="guest-detail-sub"><?php echo esc($selectedSide); ?></div>
                  </div>
                  <a class="btn guest-edit-btn" href="<?php echo esc($editUrl); ?>">Edit guest</a>
                </div>

                <div class="guest-detail-divider"><span>✧</span></div>

                <div class="guest-top-grid">
                  <div>
                    <div class="guest-field-label">Relation</div>
                    <div class="guest-readonly<?php echo $selectedRelation === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedRelation)); ?></div>
                  </div>
                  <div>
                    <div class="guest-field-label">Family Group</div>
                    <div class="guest-readonly<?php echo $selectedGroup === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedGroup)); ?></div>
                  </div>
                </div>

                <div class="guest-mini-grid">
                  <div>
                    <div class="guest-field-label">RSVP</div>
                    <div class="guest-readonly-pill<?php echo $selectedRsvp === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedRsvp)); ?></div>
                  </div>
                  <div>
                    <div class="guest-field-label">Responded on</div>
                    <div class="guest-readonly-pill<?php echo $selectedRespondedOn === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedRespondedOn)); ?></div>
                  </div>
                </div>

                <div class="guest-form-block">
                  <div class="guest-field-label">Tag</div>
                  <div class="guest-readonly-select<?php echo $selectedTagText === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedTagText)); ?></div>
                </div>

                <div class="guest-section">
                  <div class="guest-section-head">
                    <div class="guest-section-title">Contact information</div>
                    <div class="guest-section-meta">
                      <span class="guest-status-chip <?php echo esc($contactStatus['class']); ?>"><?php echo esc($contactStatus['label']); ?></span>
                      <span class="guest-section-arrow" aria-hidden="true">›</span>
                    </div>
                  </div>

                  <div class="guest-stack">
                    <div>
                      <div class="guest-field-label">Phone number</div>
                      <div class="guest-readonly<?php echo $selectedPhone === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedPhone)); ?></div>
                    </div>
                    <div>
                      <div class="guest-field-label">Email</div>
                      <div class="guest-readonly<?php echo $selectedEmail === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedEmail)); ?></div>
                    </div>
                    <div>
                      <div class="guest-field-label">Notes</div>
                      <div class="guest-readonly<?php echo $selectedNotes === '' ? ' is-muted' : ''; ?>"><?php echo esc(guest_readonly_value($selectedNotes)); ?></div>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <div class="guest-detail-empty">
                  <div>
                    <div class="guest-detail-empty-title">Select a guest</div>
                    <div class="guest-detail-empty-sub">
                      Click any guest row to open the detail bento on the right and review their information.
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </aside>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const rows = document.querySelectorAll("[data-guest-row-url]");

  rows.forEach((row) => {
    const url = row.getAttribute("data-guest-row-url");
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