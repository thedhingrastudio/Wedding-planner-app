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
$filterRsvp      = trim((string)($_GET['rsvp'] ?? ''));
$filterTag       = trim((string)($_GET['tag'] ?? ''));
$filterRound     = trim((string)($_GET['round'] ?? ''));

$allowedSides  = ['bride', 'groom', 'both'];
$allowedRsvp   = ['attending', 'not_attending', 'pending'];
$allowedTags   = ['vip', 'elder', 'none'];
$allowedRounds = ['1', '2', '3', 'none'];

if (!in_array($filterSide, $allowedSides, true)) $filterSide = '';
if (!in_array($filterRsvp, $allowedRsvp, true)) $filterRsvp = '';
if (!in_array($filterTag, $allowedTags, true)) $filterTag = '';
if (!in_array($filterRound, $allowedRounds, true)) $filterRound = '';

$filterTag = '';

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function selected_attr(string $current, string $value): string {
  return $current === $value ? 'selected' : '';
}

function input_date_value(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') return '';

  $ts = strtotime($value);
  if ($ts === false) return '';

  return date('Y-m-d', $ts);
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

function normalize_key(?string $value): string {
  $value = strtolower(trim((string)$value));
  $value = str_replace(['-', ' '], '_', $value);
  return $value;
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

function guest_group_value(array $row): string {
  return first_non_empty([
    $row['group_name'] ?? '',
    $row['family_group'] ?? '',
    $row['relation_label'] ?? '',
  ]);
}

function guest_tags_from_row(array $row): array {
  $tags = [];

  $accessibility = trim((string)($row['accessibility'] ?? ''));
  $diet = trim((string)($row['diet_preference'] ?? ''));
  $plusOne = (int)($row['plus_one_allowed'] ?? 0);

  $rawTagText = strtolower(first_non_empty([
    $row['guest_tag'] ?? '',
    $row['tag'] ?? '',
    $row['tags'] ?? '',
  ]));

  if ($rawTagText !== '') {
    if (str_contains($rawTagText, 'vip')) $tags[] = 'VIP';
    if (str_contains($rawTagText, 'elder')) $tags[] = 'Elder';
    if (str_contains($rawTagText, 'toddler')) $tags[] = 'Toddler';
  }

  if ($accessibility === 'elder_care') $tags[] = 'Elder';
  if ($accessibility === 'wheelchair') $tags[] = 'Assist';
  if ($accessibility === 'medical') $tags[] = 'Medical';
  if ($accessibility === 'toddler_care') $tags[] = 'Toddler';
  if ($diet === 'jain') $tags[] = 'Jain';
  if ($diet === 'vegan') $tags[] = 'Vegan';
  if ($plusOne === 1) $tags[] = 'Plus-one';

  return array_values(array_unique($tags));
}

function guest_has_contact(array $row): bool {
  $phone = trim((string)($row['phone'] ?? ''));
  $email = trim((string)($row['email'] ?? ''));
  return $phone !== '' || $email !== '';
}

function guest_invite_status(array $row): string {
  $raw = normalize_key($row['invite_status'] ?? '');
  return match ($raw) {
    'draft', 'not_sent' => 'draft',
    'sent', 'opened', 'invited' => 'invited',
    default => 'invited',
  };
}

function guest_rsvp_status(array $row): string {
  $raw = normalize_key($row['rsvp_status'] ?? '');

  if (in_array($raw, ['attending', 'yes', 'accepted', 'confirmed'], true)) {
    return 'attending';
  }
  if (in_array($raw, ['not_attending', 'declined', 'decline', 'no'], true)) {
    return 'not_attending';
  }

  return 'pending';
}

function guest_rsvp_label(string $status): string {
  return match ($status) {
    'attending' => 'Attending',
    'not_attending' => 'Not attending',
    default => 'Pending',
  };
}

function guest_rsvp_chip_class(string $status): string {
  return match ($status) {
    'attending' => 'ok',
    'not_attending' => 'warn',
    default => 'pending',
  };
}

function guest_followup_round(array $row): string {
  $raw = normalize_key($row['followup_round'] ?? '');

  if (in_array($raw, ['1', 'round1', 'round_1'], true)) return '1';
  if (in_array($raw, ['2', 'round2', 'round_2'], true)) return '2';
  if (in_array($raw, ['3', 'round3', 'round_3'], true)) return '3';
  if (in_array($raw, ['none', 'na', 'n_a', '0'], true)) return 'none';

  return guest_has_contact($row) ? '1' : 'none';
}


function guest_followup_status(array $row): string {
  $round = guest_followup_round($row);

  if ($round === 'none') {
    $intro = normalize_key($row['intro_message_status'] ?? 'pending');
    return match ($intro) {
      'completed'   => 'completed',
      'in_progress' => 'in_progress',
      default       => 'pending',
    };
  }

  $callKey = 'round' . $round . '_call_status';
  $msgKey  = 'round' . $round . '_message_status';

  $call = normalize_key($row[$callKey] ?? 'pending');
  $msg  = normalize_key($row[$msgKey] ?? 'pending');

  if ($call === 'completed' && $msg === 'completed') return 'completed';
  if ($call === 'in_progress' || $msg === 'in_progress') return 'in_progress';
  if ($call === 'completed' || $msg === 'completed') return 'in_progress';

  return 'pending';
}

function guest_followup_status_label(string $status): string {
  return match ($status) {
    'completed'   => 'Done',
    'in_progress' => 'In progress',
    default       => 'Pending',
  };
}

function guest_followup_chip_class(string $status): string {
  return match ($status) {
    'completed'   => 'ok',
    'in_progress' => 'neutral',
    default       => 'pending',
  };
}

function guest_followup_compact_label(array $row): string {
  $round = guest_followup_round($row);
  if ($round === 'none') return 'No follow-up';

  return 'R' . $round . ' · ' . guest_followup_status_label(guest_followup_status($row));
}


function guest_followup_round_label(string $round): string {
  return match ($round) {
    '1' => 'Round 1',
    '2' => 'Round 2',
    '3' => 'Round 3',
    default => '—',
  };
}

function guest_headcount_display(array $row): string {
  $confirmed = (int)($row['confirmed_headcount'] ?? 0);
  $seatCount = max(1, (int)($row['seat_count'] ?? 1));

  return (string)($confirmed > 0 ? $confirmed : $seatCount);
}

function guest_responded_on_label(array $row): string {
  $raw = first_non_empty([
    $row['responded_on'] ?? '',
    $row['rsvp_responded_at'] ?? '',
    $row['responded_at'] ?? '',
  ]);

  if ($raw === '') return 'Not added';

  $ts = strtotime($raw);
  if ($ts === false) return $raw;

  return date('d/m/Y', $ts);
}

function guest_party_type(array $row): string {
  $seatCount = max(1, (int)($row['seat_count'] ?? 1));
  $childrenCount = max(0, (int)($row['children_count'] ?? 0));

  if ($childrenCount > 0 && $seatCount > $childrenCount) return 'Family';
  if ($childrenCount > 0 && $seatCount === $childrenCount) return 'Kids';
  if ($seatCount > 1) return 'Family';
  return 'Adult';
}

function guest_plus_ones_label(array $row): string {
  return (int)($row['plus_one_allowed'] ?? 0) === 1 ? 'Allowed' : '—';
}

function guest_matches_filters(
  array $row,
  string $searchQ,
  string $filterSide,
  string $filterRsvp,
  string $filterTag,
  string $filterRound
): bool {
  $fullName = strtolower(guest_full_name($row));
  $group    = guest_group_value($row);
  $tags     = guest_tags_from_row($row);
  $rsvp     = guest_rsvp_status($row);
  $round    = guest_followup_round($row);

  if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $haystack = strtolower(implode(' ', [
      $fullName,
      (string)($row['phone'] ?? ''),
      (string)($row['email'] ?? ''),
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

  if ($filterRsvp !== '' && $rsvp !== $filterRsvp) {
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

    if (!$tagMatch) return false;
  }

  if ($filterRound !== '' && $round !== $filterRound) {
    return false;
  }

  return true;
}

function invite_page_url(
  int $projectId,
  string $searchQ = '',
  int $guestId = 0,
  string $side = '',
  string $rsvp = '',
  string $tag = '',
  string $round = ''
): string {
  $params = ['project_id' => $projectId];
  if ($searchQ !== '') $params['q'] = $searchQ;
  if ($guestId > 0) $params['guest_id'] = $guestId;
  if ($side !== '') $params['side'] = $side;
  if ($rsvp !== '') $params['rsvp'] = $rsvp;
  if ($tag !== '') $params['tag'] = $tag;
  if ($round !== '') $params['round'] = $round;

  return base_url('invites/index.php?' . http_build_query($params));
}

$rsvpOptions = [
  'pending' => 'Pending',
  'attending' => 'Attending',
  'not_attending' => 'Not attending',
];

$progressOptions = [
  'pending' => 'Pending',
  'in_progress' => 'In progress',
  'completed' => 'Completed',
];

$guestTableExists = table_exists_local($pdo, 'guests');
$rsvpTableExists  = table_exists_local($pdo, 'guest_rsvp_tracking');

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

/* ---------- Top meta ---------- */
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

/* ---------- Team count ---------- */
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

/* ---------- Save inline RSVP updates ---------- */
$saveErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rsvp_details'])) {
  if (!$rsvpTableExists) {
    $saveErrors[] = 'RSVP tracking table is missing. Please create guest_rsvp_tracking first.';
  }

  $postedGuestId = (int)($_POST['guest_id'] ?? 0);

  $checkGuest = $pdo->prepare("
    SELECT id
    FROM guests
    WHERE id = :gid
      AND project_id = :pid
    LIMIT 1
  ");
  $checkGuest->execute([
    ':gid' => $postedGuestId,
    ':pid' => $projectId,
  ]);
  $guestExists = (bool)$checkGuest->fetch();

  if (!$guestExists) {
    $saveErrors[] = 'This guest could not be found.';
  }

  $rsvpStatus = normalize_key($_POST['rsvp_status'] ?? '');
  $respondedOn = trim((string)($_POST['responded_on'] ?? ''));

  $introMessageStatus = normalize_key($_POST['intro_message_status'] ?? '');
  $round1CallStatus = normalize_key($_POST['round1_call_status'] ?? '');
  $round1MessageStatus = normalize_key($_POST['round1_message_status'] ?? '');
  $round2CallStatus = normalize_key($_POST['round2_call_status'] ?? '');
  $round2MessageStatus = normalize_key($_POST['round2_message_status'] ?? '');
  $round3CallStatus = normalize_key($_POST['round3_call_status'] ?? '');
  $round3MessageStatus = normalize_key($_POST['round3_message_status'] ?? '');

  if (!array_key_exists($rsvpStatus, $rsvpOptions)) {
    $saveErrors[] = 'Select a valid RSVP status.';
  }

  foreach ([
    'Introduction message' => $introMessageStatus,
    'Round 1 call' => $round1CallStatus,
    'Round 1 message' => $round1MessageStatus,
    'Round 2 call' => $round2CallStatus,
    'Round 2 message' => $round2MessageStatus,
    'Round 3 call' => $round3CallStatus,
    'Round 3 message' => $round3MessageStatus,
  ] as $label => $value) {
    if (!array_key_exists($value, $progressOptions)) {
      $saveErrors[] = $label . ' has an invalid value.';
    }
  }

  if ($respondedOn !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $respondedOn);
    if (!$dt || $dt->format('Y-m-d') !== $respondedOn) {
      $saveErrors[] = 'Enter a valid RSVP update date.';
    }
  }

  $followupRound = '1';
  if ($round3CallStatus !== 'pending' || $round3MessageStatus !== 'pending') {
    $followupRound = '3';
  } elseif ($round2CallStatus !== 'pending' || $round2MessageStatus !== 'pending') {
    $followupRound = '2';
  } elseif (!guest_has_contact(['phone' => '', 'email' => '']) && false) {
    $followupRound = 'none';
  }

  if (!$saveErrors) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO guest_rsvp_tracking (
          project_id,
          guest_id,
          rsvp_status,
          responded_on,
          followup_round,
          intro_message_status,
          round1_call_status,
          round1_message_status,
          round2_call_status,
          round2_message_status,
          round3_call_status,
          round3_message_status,
          created_at,
          updated_at
        ) VALUES (
          :project_id,
          :guest_id,
          :rsvp_status,
          :responded_on,
          :followup_round,
          :intro_message_status,
          :round1_call_status,
          :round1_message_status,
          :round2_call_status,
          :round2_message_status,
          :round3_call_status,
          :round3_message_status,
          NOW(),
          NOW()
        )
        ON DUPLICATE KEY UPDATE
          rsvp_status = VALUES(rsvp_status),
          responded_on = VALUES(responded_on),
          followup_round = VALUES(followup_round),
          intro_message_status = VALUES(intro_message_status),
          round1_call_status = VALUES(round1_call_status),
          round1_message_status = VALUES(round1_message_status),
          round2_call_status = VALUES(round2_call_status),
          round2_message_status = VALUES(round2_message_status),
          round3_call_status = VALUES(round3_call_status),
          round3_message_status = VALUES(round3_message_status),
          updated_at = NOW()
      ");

      $stmt->execute([
        ':project_id' => $projectId,
        ':guest_id' => $postedGuestId,
        ':rsvp_status' => $rsvpStatus,
        ':responded_on' => $respondedOn !== '' ? $respondedOn : null,
        ':followup_round' => $followupRound,
        ':intro_message_status' => $introMessageStatus,
        ':round1_call_status' => $round1CallStatus,
        ':round1_message_status' => $round1MessageStatus,
        ':round2_call_status' => $round2CallStatus,
        ':round2_message_status' => $round2MessageStatus,
        ':round3_call_status' => $round3CallStatus,
        ':round3_message_status' => $round3MessageStatus,
      ]);

      if (function_exists('flash_set')) {
        flash_set('success', 'RSVP details updated.');
      }

      redirect('invites/index.php?project_id=' . $projectId . '&guest_id=' . $postedGuestId);
    } catch (Throwable $e) {
      $saveErrors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

/* ---------- Guests + optional RSVP tracking ---------- */
$allGuestRows = [];
$displayGuestRows = [];
$selectedGuest = null;

if ($guestTableExists) {
  if ($rsvpTableExists) {
    $sql = "
      SELECT
        g.*,
        rt.invite_status,
        rt.rsvp_status,
        rt.responded_on,
        rt.confirmed_headcount,
        rt.followup_round,
        rt.intro_message_status,
        rt.round1_call_status,
        rt.round1_message_status,
        rt.round2_call_status,
        rt.round2_message_status,
        rt.round3_call_status,
        rt.round3_message_status,
        rt.notes AS rsvp_notes
      FROM guests g
      LEFT JOIN guest_rsvp_tracking rt
        ON rt.guest_id = g.id
       AND rt.project_id = g.project_id
      WHERE g.project_id = :pid
      ORDER BY g.created_at DESC, g.id DESC
    ";
  } else {
    $sql = "
      SELECT
        g.*,
        NULL AS invite_status,
        NULL AS rsvp_status,
        NULL AS responded_on,
        NULL AS confirmed_headcount,
        NULL AS followup_round,
        NULL AS intro_message_status,
        NULL AS round1_call_status,
        NULL AS round1_message_status,
        NULL AS round2_call_status,
        NULL AS round2_message_status,
        NULL AS round3_call_status,
        NULL AS round3_message_status,
        NULL AS rsvp_notes
      FROM guests g
      WHERE g.project_id = :pid
      ORDER BY g.created_at DESC, g.id DESC
    ";
  }

  $st = $pdo->prepare($sql);
  $st->execute([':pid' => $projectId]);
  $allGuestRows = $st->fetchAll() ?: [];

  foreach ($allGuestRows as $row) {
    if (guest_matches_filters($row, $searchQ, $filterSide, $filterRsvp, $filterTag, $filterRound)) {
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

$hasGuests = count($allGuestRows) > 0;
$hasDisplayGuests = count($displayGuestRows) > 0;

/* ---------- Overview stats ---------- */
$invitedCount = 0;
$respondedCount = 0;
$pendingCount = 0;
$attendingCount = 0;
$notAttendingCount = 0;

$totalHeadcount = 0;
$totalAdults = 0;
$totalKids = 0;
$totalPlusOnes = 0;

$contactMissingCount = 0;
$dietaryMissingCount = 0;
$assistanceMissingCount = 0;
$plusOneIncompleteCount = 0;

$noFollowupsCount = 0;
$round1Count = 0;
$round2Count = 0;
$round3Count = 0;

foreach ($allGuestRows as $row) {
  $inviteStatus = guest_invite_status($row);
  $rsvpStatus   = guest_rsvp_status($row);
  $round        = guest_followup_round($row);

  if ($inviteStatus !== 'draft') $invitedCount++;

  if (in_array($rsvpStatus, ['attending', 'not_attending'], true)) {
    $respondedCount++;
  }
  if ($rsvpStatus === 'pending') $pendingCount++;
  if ($rsvpStatus === 'attending') $attendingCount++;
  if ($rsvpStatus === 'not_attending') $notAttendingCount++;

  $headcount = (int)guest_headcount_display($row);
  $childrenCount = max(0, (int)($row['children_count'] ?? 0));
  $adultCount = max($headcount - $childrenCount, 0);

  $totalHeadcount += $headcount;
  $totalAdults += $adultCount;
  $totalKids += $childrenCount;

  if ((int)($row['plus_one_allowed'] ?? 0) === 1) {
    $totalPlusOnes++;
  }

  $phone = trim((string)($row['phone'] ?? ''));
  $email = trim((string)($row['email'] ?? ''));
  if ($phone === '' && $email === '') $contactMissingCount++;

  $diet = trim((string)($row['diet_preference'] ?? ''));
  if ($diet === '') $dietaryMissingCount++;

  $access = trim((string)($row['accessibility'] ?? ''));
  if ($access === '') $assistanceMissingCount++;

  if ((int)($row['plus_one_allowed'] ?? 0) === 1 && max(1, (int)($row['seat_count'] ?? 1)) < 2) {
    $plusOneIncompleteCount++;
  }

  switch ($round) {
    case '1':
      $round1Count++;
      break;
    case '2':
      $round2Count++;
      break;
    case '3':
      $round3Count++;
      break;
    default:
      $noFollowupsCount++;
      break;
  }
}

/* ---------- Event headcount ---------- */
$eventBreakdown = [];
try {
  $es = $pdo->prepare("
    SELECT
      e.id,
      e.name,
      e.hosting_side,
      COALESCE(SUM(g.seat_count), 0) AS seat_total
    FROM project_events e
    LEFT JOIN guest_event_invites gei
      ON gei.event_id = e.id
    LEFT JOIN guests g
      ON g.id = gei.guest_id
    WHERE e.project_id = :pid
    GROUP BY e.id, e.name, e.hosting_side, e.starts_at
    ORDER BY e.starts_at ASC, e.id ASC
  ");
  $es->execute([':pid' => $projectId]);
  $eventBreakdown = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $eventBreakdown = [];
}

$basePageUrl = invite_page_url($projectId);
$hasActiveFilters = $searchQ !== '' || $filterSide !== '' || $filterRsvp !== '' || $filterRound !== '';

$pageTitle = $projectTitle . ' — Invite & RSVP — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.proj-main{ min-width:0; }

.invite-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:18px;
  margin-bottom:18px;
}
.invite-head .left h2{
  margin:0;
  font-size:26px;
  line-height:1.08;
  font-weight:800;
  color:#1d1d1f;
}
.invite-head .left p{
  margin:8px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.5;
  max-width:660px;
}
.invite-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:12px;
  font-size:11px;
  font-weight:700;
  padding:6px 10px;
  border:1px solid rgba(0,0,0,0.05);
  border-radius:999px;
  background:#f7f7f8;
  color:#56565b;
  vertical-align:middle;
}
.invite-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.invite-actions .icon-btn{
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

.invite-shell{
  display:grid;
  grid-template-columns:minmax(0,1fr) 340px;
  gap:16px;
  align-items:start;
}
@media (max-width:1360px){
  .invite-shell{ grid-template-columns:1fr; }
}

.invite-main{ min-width:0; }
.invite-side{
  display:flex;
  flex-direction:column;
  gap:16px;
  min-width:0;
}

.stats-row{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:14px;
  margin-bottom:16px;
}
@media (max-width:980px){
  .stats-row{ grid-template-columns:1fr; }
}

.stat-card{
  padding:18px 20px;
  border-radius:24px;
  min-height:88px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:18px;
  background:#fff;
  border:1px solid rgba(0,0,0,0.04);
}
.stat-card-left{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
}
.stat-ico{
  width:38px;
  height:38px;
  min-width:38px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:#f5f1fb;
  color:#6f4aa1;
  font-size:16px;
  line-height:1;
}
.stat-label{
  font-size:15px;
  font-weight:700;
  color:#252528;
  line-height:1.2;
  white-space:nowrap;
}
.stat-value{
  font-size:24px;
  line-height:1;
  font-weight:800;
  color:#202024;
  letter-spacing:-0.02em;
  flex:0 0 auto;
}

.invite-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
  flex-wrap:wrap;
}
.invite-search{ flex:1 1 360px; }
.invite-search-wrap{ position:relative; }
.invite-search-wrap .search-ico{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#9a9aa1;
  font-size:13px;
  pointer-events:none;
}
.invite-search input{
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
.invite-search input::placeholder{ color:#9b9ba2; }

.invite-toolbar-actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.table-select-btn{
  min-height:42px;
  white-space:nowrap;
}

.invite-table-card{
  padding:8px 14px 10px;
  border-radius:26px;
  overflow:hidden;
}

.invite-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}

.invite-table thead th{
  text-align:left;
  padding:14px 14px 14px;
  font-size:12px;
  color:#9a9aa1;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
  vertical-align:top;
}

.invite-table thead th.invite-col-name{
  width:170px;
  min-width:170px;
  padding-top:24px;
}

.invite-col-headcount{
  width:68px;
  min-width:68px;
  padding-top:24px;
}

.invite-th-wrap{
  display:flex;
  flex-direction:column;
  gap:7px;
}

.invite-th-top{
  display:flex;
  align-items:center;
  gap:6px;
  color:#9a9aa1;
  font-size:12px;
  font-weight:700;
}

.invite-th-top .chev{
  font-size:11px;
  color:#b0b0b6;
}

.invite-th-filter{
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
}

.invite-th-filter:focus{
  border-color:rgba(0,0,0,0.14);
}

.invite-table tbody td{
  text-align:left;
  padding:14px 12px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:14px;
  color:#1f1f22;
  transition:background 160ms ease;
}

.invite-table tbody tr:last-child td{
  border-bottom:none;
}

.invite-table-row{
  cursor:pointer;
}

.invite-table-row:hover td{
  background:rgba(0,0,0,0.02);
}

.invite-table-row:focus{
  outline:none;
}

.invite-table-row.is-selected td{
  background:rgba(75,0,31,0.045);
}

.invite-table-row.is-selected td:first-child{
  border-top-left-radius:18px;
  border-bottom-left-radius:18px;
}

.invite-table-row.is-selected td:last-child{
  border-top-right-radius:18px;
  border-bottom-right-radius:18px;
}

.invite-name{
  font-weight:500;
  color:#1d1d1f;
  line-height:1.3;
  font-size:13px;
  width:170px;
  min-width:170px;
  max-width:170px;
  white-space:normal;
  overflow-wrap:anywhere;
  word-break:break-word;
}

.invite-side-text{
  color:#4f4f55;
  font-size:13px;
  line-height:1.35;
}

/* Side */
.invite-table thead th:nth-child(2),
.invite-table tbody td:nth-child(2){
  width:110px;
  min-width:110px;
}

/* RSVP */
.invite-table thead th:nth-child(3),
.invite-table tbody td:nth-child(3){
  width:115px;
  min-width:115px;
}

/* Follow-up */
.invite-table thead th:nth-child(4),
.invite-table tbody td:nth-child(4){
  width:125px;
  min-width:125px;
}

/* Headcount */
.invite-table thead th:nth-child(5),
.invite-table tbody td:nth-child(5){
  width:68px;
  min-width:68px;
  padding-left:8px;
  padding-right:8px;
}
.table-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  min-height:28px;
  padding:0 10px;
  border-radius:999px;
  font-size:11px;
  font-weight:500;
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

.table-chip.pending{
  background:#efcccc;
  color:#8a5b5b;
}

.table-chip.neutral{
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

.health-card,
.guest-detail-card{
  padding:18px;
  border-radius:24px;
  position:static;
  top:auto;
}
.health-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#222;
}
.health-sub{
  margin:4px 0 0 0;
  color:#75757a;
  font-size:12px;
  line-height:1.45;
}
.health-wrap{
  display:flex;
  flex-direction:column;
  gap:2px;
  margin-top:12px;
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
.health-chevron{
  color:#8a8a90;
  font-weight:400;
  font-size:16px;
  line-height:1;
  transition:transform 160ms ease;
}
.health-group[open] .health-chevron{ transform:rotate(180deg); }
.health-list{
  display:flex;
  flex-direction:column;
  gap:8px;
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

.guest-detail-card{
  min-height:290px;
}
.guest-detail-empty{
  display:grid;
  place-items:center;
  min-height:250px;
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
  margin-top:8px;
  font-size:12px;
  line-height:1.55;
  color:#7a7a80;
  max-width:240px;
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
  grid-template-columns:1fr 112px;
  gap:10px;
}
.guest-mini-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
  margin-top:10px;
}
.guest-field-label{
  font-size:11px;
  color:#8b8b91;
  margin-bottom:6px;
}
.guest-readonly{
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
.guest-readonly.is-muted{
  color:#97979d;
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
.guest-section-arrow{
  color:#a2a2a8;
  font-size:16px;
  line-height:1;
}
.guest-stack{ display:grid; gap:10px; }

.guest-edit-input,
.guest-edit-select{
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
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
}

.guest-edit-select{
  background-image:
    linear-gradient(45deg, transparent 50%, #a2a2a8 50%),
    linear-gradient(135deg, #a2a2a8 50%, transparent 50%);
  background-position:
    calc(100% - 18px) calc(50% - 2px),
    calc(100% - 13px) calc(50% - 2px);
  background-size:5px 5px, 5px 5px;
  background-repeat:no-repeat;
  padding-right:34px;
}

.guest-edit-input:focus,
.guest-edit-select:focus{
  border-color:rgba(0,0,0,0.16);
  background:#fff;
}

.guest-detail-form-actions{
  display:flex;
  justify-content:flex-end;
  margin-top:14px;
}

.error-card{
  margin-bottom:14px;
  border:1px solid rgba(185,28,28,.15);
  background:#fff7f7;
  padding:16px;
  border-radius:18px;
}
.error-card-title{
  font-size:16px;
  color:#991b1b;
  font-weight:800;
}
.error-card ul{
  margin:10px 0 0 18px;
  color:#991b1b;
  font-size:13px;
  line-height:1.6;
}

@media (max-width:980px){
  .invite-head{
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
          $active = 'rsvp';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <?php if ($saveErrors): ?>
            <div class="error-card">
              <div class="error-card-title">Please fix these fields</div>
              <ul>
                <?php foreach ($saveErrors as $error): ?>
                  <li><?php echo esc($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="invite-head">
            <div class="left">
              <h2>
                Invite &amp; RSVP
                <span class="invite-badge"><?php echo $hasGuests ? 'RSVP open' : 'Not started'; ?></span>
              </h2>
              <p>Know who’s coming, who isn’t, who hasn’t replied, and what details are still missing.</p>
            </div>

            <div class="invite-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId)); ?>">👁 Preview guest list</a>
              <button class="btn btn-primary" type="button" <?php echo !$hasGuests ? 'disabled' : ''; ?>>☆ Send invites</button>
            </div>
          </div>

          <div class="invite-shell">
            <div class="invite-main">
              <div class="stats-row">
                <div class="card proj-card stat-card">
                  <div class="stat-card-left">
                    <div class="stat-ico">👥</div>
                    <div class="stat-label">Invited</div>
                  </div>
                  <div class="stat-value"><?php echo esc((string)$invitedCount); ?></div>
                </div>

                <div class="card proj-card stat-card">
                  <div class="stat-card-left">
                    <div class="stat-ico">👥</div>
                    <div class="stat-label">Responded</div>
                  </div>
                  <div class="stat-value"><?php echo esc((string)$respondedCount); ?></div>
                </div>

                <div class="card proj-card stat-card">
                  <div class="stat-card-left">
                    <div class="stat-ico">👥</div>
                    <div class="stat-label">Pending</div>
                  </div>
                  <div class="stat-value"><?php echo esc((string)$pendingCount); ?></div>
                </div>
              </div>

              <form method="get">
                <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">

                <div class="invite-toolbar">
                  <div class="invite-search">
                    <div class="invite-search-wrap">
                      <span class="search-ico">🔍</span>
                      <input type="text" name="q" value="<?php echo esc($searchQ); ?>" placeholder="Search guest">
                    </div>
                  </div>

                  <div class="invite-toolbar-actions">
                    <?php if ($hasActiveFilters): ?>
                      <a class="btn" href="<?php echo esc($basePageUrl); ?>">Reset filters</a>
                    <?php endif; ?>
                    <button class="btn table-select-btn" type="button">✓ Select all</button>
                  </div>
                </div>

                <div class="card proj-card invite-table-card">
                  <?php if ($hasDisplayGuests): ?>
                    <table class="invite-table">
                      <thead>
  <tr>
    <th class="invite-col-name">Guest name</th>

    <th>
      <div class="invite-th-wrap">
        <div class="invite-th-top">Side <span class="chev">⌄</span></div>
        <select class="invite-th-filter" name="side" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride’s side</option>
          <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom’s side</option>
          <option value="both" <?php echo $filterSide === 'both' ? 'selected' : ''; ?>>Both families</option>
        </select>
      </div>
    </th>

    <th>
      <div class="invite-th-wrap">
        <div class="invite-th-top">RSVP <span class="chev">⌄</span></div>
        <select class="invite-th-filter" name="rsvp" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="attending" <?php echo $filterRsvp === 'attending' ? 'selected' : ''; ?>>Attending</option>
          <option value="not_attending" <?php echo $filterRsvp === 'not_attending' ? 'selected' : ''; ?>>Not attending</option>
          <option value="pending" <?php echo $filterRsvp === 'pending' ? 'selected' : ''; ?>>Pending</option>
        </select>
      </div>
    </th>

    <th>
      <div class="invite-th-wrap">
        <div class="invite-th-top">Follow-up <span class="chev">⌄</span></div>
        <select class="invite-th-filter" name="round" onchange="this.form.submit()">
          <option value="">All</option>
          <option value="1" <?php echo $filterRound === '1' ? 'selected' : ''; ?>>Round 1</option>
          <option value="2" <?php echo $filterRound === '2' ? 'selected' : ''; ?>>Round 2</option>
          <option value="3" <?php echo $filterRound === '3' ? 'selected' : ''; ?>>Round 3</option>
          <option value="none" <?php echo $filterRound === 'none' ? 'selected' : ''; ?>>No follow-up</option>
        </select>
      </div>
    </th>

    <th class="invite-col-headcount">
      <div class="invite-th-top">Headcount</div>
    </th>
  </tr>
</thead>

                      <tbody>
                        <?php foreach ($displayGuestRows as $row): ?>
                          <?php
  $rowId = (int)($row['id'] ?? 0);
  $fullName = guest_full_name($row);
  $rsvpStatus = guest_rsvp_status($row);
  $rsvpLabel = guest_rsvp_label($rsvpStatus);
  $rsvpClass = guest_rsvp_chip_class($rsvpStatus);

  $followupStatus = guest_followup_status($row);
  $followupClass = guest_followup_chip_class($followupStatus);
  $followupLabel = guest_followup_compact_label($row);

  $rowUrl = invite_page_url(
    $projectId,
    $searchQ,
    $rowId,
    $filterSide,
    $filterRsvp,
    '',
    $filterRound
  );
  $isSelected = $selectedGuestId > 0 && $rowId === $selectedGuestId;
?>
<tr
  class="invite-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
  data-guest-row-url="<?php echo esc($rowUrl); ?>"
  tabindex="0"
  role="button"
  aria-label="View details for <?php echo esc($fullName); ?>"
>
  <td class="invite-name"><?php echo esc($fullName); ?></td>
  <td class="invite-side-text"><?php echo esc(side_label((string)($row['invited_by'] ?? ''))); ?></td>
  <td>
    <span class="table-chip <?php echo esc($rsvpClass); ?>">
      <?php echo esc($rsvpLabel); ?>
    </span>
  </td>
  <td>
    <span class="table-chip <?php echo esc($followupClass); ?>">
      <?php echo esc($followupLabel); ?>
    </span>
  </td>
  <td>
    <span class="table-chip neutral"><?php echo esc(guest_headcount_display($row)); ?></span>
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
                      No guests added yet. First add guests in Guest list setup, then use this page to track responses and follow-ups.
                    </div>
                  <?php endif; ?>
                </div>
              </form>
            </div>

            <aside class="invite-side">
              <section class="card proj-card health-card">
                <h3 class="health-title">RSVP health</h3>
                <p class="health-sub">What needs cleaning before invites go out.</p>

                <div class="health-wrap">
                  <details class="health-group">
                    <summary>
                      <span>RSVP overview</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <div class="health-row"><div class="label">Invited</div><div class="value"><?php echo esc((string)$invitedCount); ?></div></div>
                      <div class="health-row"><div class="label">Responded</div><div class="value"><?php echo esc((string)$respondedCount); ?></div></div>
                      <div class="health-row"><div class="label">Pending</div><div class="value"><?php echo esc((string)$pendingCount); ?></div></div>
                    </div>
                  </details>

                  <details class="health-group">
                    <summary>
                      <span>Response breakdown</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <div class="health-row"><div class="label">Attending</div><div class="value"><?php echo esc((string)$attendingCount); ?></div></div>
                      <div class="health-row"><div class="label">Not attending</div><div class="value"><?php echo esc((string)$notAttendingCount); ?></div></div>
                    </div>
                  </details>

                  <details class="health-group">
                    <summary>
                      <span>Headcount</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <div class="health-row"><div class="label">Total headcount</div><div class="value"><?php echo esc((string)$totalHeadcount); ?></div></div>
                      <div class="health-row"><div class="label">Adult</div><div class="value"><?php echo esc((string)$totalAdults); ?></div></div>
                      <div class="health-row"><div class="label">Kids</div><div class="value"><?php echo esc((string)$totalKids); ?></div></div>
                      <div class="health-row"><div class="label">Plus-ones added</div><div class="value"><?php echo esc((string)$totalPlusOnes); ?></div></div>
                    </div>
                  </details>

                  <details class="health-group">
                    <summary>
                      <span>Missing details</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <div class="health-row"><div class="label">Contact missing</div><div class="value"><?php echo esc((string)$contactMissingCount); ?></div></div>
                      <div class="health-row"><div class="label">Dietary preferences missing</div><div class="value"><?php echo esc((string)$dietaryMissingCount); ?></div></div>
                      <div class="health-row"><div class="label">Assistance info missing</div><div class="value"><?php echo esc((string)$assistanceMissingCount); ?></div></div>
                      <div class="health-row"><div class="label">Plus-one details incomplete</div><div class="value"><?php echo esc((string)$plusOneIncompleteCount); ?></div></div>
                    </div>
                  </details>

                  <details class="health-group">
                    <summary>
                      <span>Follow-up queue</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <div class="health-row"><div class="label">No follow ups</div><div class="value"><?php echo esc((string)$noFollowupsCount); ?></div></div>
                      <div class="health-row"><div class="label">Round 1 complete</div><div class="value"><?php echo esc((string)$round1Count); ?></div></div>
                      <div class="health-row"><div class="label">Round 2 complete</div><div class="value"><?php echo esc((string)$round2Count); ?></div></div>
                      <div class="health-row"><div class="label">Round 3 complete</div><div class="value"><?php echo esc((string)$round3Count); ?></div></div>
                    </div>
                  </details>

                  <details class="health-group">
                    <summary>
                      <span>Events’ headcount</span>
                      <span class="health-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="health-list">
                      <?php if ($eventBreakdown): ?>
                        <?php foreach ($eventBreakdown as $eventRow): ?>
                          <?php
                            $eventName = trim((string)($eventRow['name'] ?? 'Untitled event'));
                            $eventSide = trim((string)($eventRow['hosting_side'] ?? ''));
                            $eventLabel = $eventName;
                            if ($eventSide !== '') $eventLabel .= ' (' . ucfirst($eventSide) . ' side)';
                          ?>
                          <div class="health-row">
                            <div class="label"><?php echo esc($eventLabel); ?></div>
                            <div class="value"><?php echo esc((string)((int)($eventRow['seat_total'] ?? 0))); ?></div>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="health-row">
                          <div class="label">No events mapped yet</div>
                          <div class="value">0</div>
                        </div>
                      <?php endif; ?>
                    </div>
                  </details>
                </div>
              </section>

              <aside class="card proj-card guest-detail-card">
                <?php if ($selectedGuest && $hasDisplayGuests): ?>
                  <?php
                    $selectedName = guest_full_name($selectedGuest);
                    $selectedSide = side_label((string)($selectedGuest['invited_by'] ?? ''));
                    $selectedRelation = first_non_empty([
                      $selectedGuest['relation_label'] ?? '',
                      $selectedGuest['relation'] ?? '',
                    ]);
                    $selectedGroup = guest_group_value($selectedGuest);
                    $selectedRsvpStatus = guest_rsvp_status($selectedGuest);
                    $selectedPartyType = guest_party_type($selectedGuest);
                    $selectedPlusOnes = guest_plus_ones_label($selectedGuest);
                    $selectedPartyComplete = max(1, (int)($selectedGuest['seat_count'] ?? 1)) > 0;

                    $editUrl = base_url('guests/create.php?' . http_build_query([
                      'project_id' => $projectId,
                      'guest_id'   => (int)($selectedGuest['id'] ?? 0),
                      'return_to'  => 'invites',
                    ]));
                  ?>

                  <div class="guest-detail-topline">
                    <div class="guest-detail-label">Guest detail</div>
                    <a class="guest-close" href="<?php echo esc(invite_page_url($projectId, $searchQ, 0, $filterSide, $filterRsvp, $filterTag, $filterRound)); ?>" aria-label="Close guest details">×</a>
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
                      <div class="guest-readonly<?php echo $selectedRelation === '' ? ' is-muted' : ''; ?>">
                        <?php echo esc($selectedRelation !== '' ? $selectedRelation : 'Not added'); ?>
                      </div>
                    </div>
                    <div>
                      <div class="guest-field-label">Family Group</div>
                      <div class="guest-readonly<?php echo $selectedGroup === '' ? ' is-muted' : ''; ?>">
                        <?php echo esc($selectedGroup !== '' ? $selectedGroup : 'Not added'); ?>
                      </div>
                    </div>
                  </div>

                  <form method="post">
                    <input type="hidden" name="guest_id" value="<?php echo esc((string)((int)($selectedGuest['id'] ?? 0))); ?>">

                    <div class="guest-mini-grid">
                      <div>
                        <div class="guest-field-label">RSVP</div>
                        <select class="guest-edit-select" name="rsvp_status">
                          <?php foreach ($rsvpOptions as $value => $label): ?>
                            <option value="<?php echo esc($value); ?>" <?php echo selected_attr($selectedRsvpStatus, $value); ?>>
                              <?php echo esc($label); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div>
                        <div class="guest-field-label">Responded on</div>
                        <input
                          class="guest-edit-input"
                          type="date"
                          name="responded_on"
                          value="<?php echo esc(input_date_value($selectedGuest['responded_on'] ?? '')); ?>"
                        >
                      </div>
                    </div>

                    <div class="guest-section">
                      <div class="guest-section-head">
                        <div class="guest-section-title">Party details</div>
                        <div class="guest-section-meta">
                          <span class="guest-status-chip <?php echo $selectedPartyComplete ? 'complete' : 'incomplete'; ?>">
                            <?php echo $selectedPartyComplete ? 'Complete' : 'Incomplete'; ?>
                          </span>
                          <span class="guest-section-arrow" aria-hidden="true">⌄</span>
                        </div>
                      </div>

                      <div class="guest-mini-grid">
                        <div>
                          <div class="guest-field-label">Type</div>
                          <div class="guest-readonly"><?php echo esc($selectedPartyType); ?></div>
                        </div>
                        <div>
                          <div class="guest-field-label">Plus ones</div>
                          <div class="guest-readonly"><?php echo esc($selectedPlusOnes); ?></div>
                        </div>
                      </div>
                    </div>

                    <div class="guest-section">
                      <div class="guest-section-head">
                        <div class="guest-section-title">Follow ups</div>
                      </div>

                      <div class="guest-stack">
                        <div>
                          <div class="guest-field-label">Introduction message</div>
                          <select class="guest-edit-select" name="intro_message_status">
                            <?php foreach ($progressOptions as $value => $label): ?>
                              <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['intro_message_status'] ?? 'pending'), $value); ?>>
                                <?php echo esc($label); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>

                        <div class="guest-mini-grid">
                          <div>
                            <div class="guest-field-label">Round 1 of calling</div>
                            <select class="guest-edit-select" name="round1_call_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round1_call_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <div class="guest-field-label">Round 1 of messages</div>
                            <select class="guest-edit-select" name="round1_message_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round1_message_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>

                        <div class="guest-mini-grid">
                          <div>
                            <div class="guest-field-label">Round 2 of calling</div>
                            <select class="guest-edit-select" name="round2_call_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round2_call_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <div class="guest-field-label">Round 2 of messages</div>
                            <select class="guest-edit-select" name="round2_message_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round2_message_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>

                        <div class="guest-mini-grid">
                          <div>
                            <div class="guest-field-label">Round 3 of calling</div>
                            <select class="guest-edit-select" name="round3_call_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round3_call_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <div class="guest-field-label">Round 3 of messages</div>
                            <select class="guest-edit-select" name="round3_message_status">
                              <?php foreach ($progressOptions as $value => $label): ?>
                                <option value="<?php echo esc($value); ?>" <?php echo selected_attr(normalize_key($selectedGuest['round3_message_status'] ?? 'pending'), $value); ?>>
                                  <?php echo esc($label); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                        </div>
                      </div>

                      <div class="guest-detail-form-actions">
                        <button class="btn btn-primary" type="submit" name="save_rsvp_details" value="1">Save updates</button>
                      </div>
                    </div>
                  </form>
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