<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['project_id'] ?? $_GET['id'] ?? $_POST['project_id'] ?? 0);
if ($projectId <= 0) {
  redirect('projects/index.php');
}

$companyId      = current_company_id();
$searchQ        = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$bucket         = trim((string)($_GET['bucket'] ?? $_POST['bucket'] ?? 'arrivals'));
$filterSide     = trim((string)($_GET['side'] ?? $_POST['side'] ?? ''));
$filterMode     = trim((string)($_GET['mode'] ?? $_POST['mode'] ?? ''));
$filterTerminal = trim((string)($_GET['terminal'] ?? $_POST['terminal'] ?? ''));
$filterDriver   = trim((string)($_GET['driver'] ?? $_POST['driver'] ?? ''));

$allowedBuckets = ['arrivals', 'departures', 'unassigned', 'care', 'drivers'];
$allowedSides   = ['bride', 'groom', 'both'];
$allowedModes   = ['flight', 'train', 'not_sure'];

$selectedGuestId = (int)($_GET['guest_id'] ?? $_POST['guest_id'] ?? 0);

if (!in_array($bucket, $allowedBuckets, true)) $bucket = 'arrivals';
if (!in_array($filterSide, $allowedSides, true)) $filterSide = '';
if (!in_array($filterMode, $allowedModes, true)) $filterMode = '';

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
  if ($accessibility === 'wheelchair') $tags[] = 'Wheelchair';
  if ($accessibility === 'medical') $tags[] = 'Medical';
  if ($accessibility === 'toddler_care') $tags[] = 'Toddler';
  if ($diet === 'jain') $tags[] = 'Jain';
  if ($diet === 'vegan') $tags[] = 'Vegan';
  if ($plusOne === 1) $tags[] = 'Plus-one';

  return array_values(array_unique($tags));
}

function guest_has_special_care(array $row): bool {
  $tags = guest_tags_from_row($row);
  $accessibility = trim((string)($row['accessibility'] ?? ''));
  return in_array('VIP', $tags, true)
    || in_array('Elder', $tags, true)
    || in_array('Wheelchair', $tags, true)
    || in_array($accessibility, ['elder_care', 'wheelchair', 'medical', 'toddler_care'], true);
}

function detect_travel_mode(?string $ref): string {
  $value = strtoupper(trim((string)$ref));
  if ($value === '') return 'not_sure';

  $flightPattern = '/^[A-Z0-9]{1,3}\s*[- ]?\s*\d{1,4}[A-Z]?$/';
  $trainPattern  = '/^\d{4,6}$/';

  if (preg_match($trainPattern, $value) === 1) return 'train';
  if (preg_match($flightPattern, $value) === 1 && preg_match('/[A-Z]/', $value) === 1) return 'flight';

  return 'not_sure';
}

function travel_mode_label(string $mode): string {
  return match ($mode) {
    'flight' => 'Flight',
    'train'  => 'Train',
    default  => 'Not sure',
  };
}

function travel_mode_chip_class(string $mode): string {
  return match ($mode) {
    'flight' => 'ok',
    'train'  => 'olive',
    default  => 'neutral',
  };
}

function travel_driver_label(array $row, string $kind): string {
  $kindPrefix = $kind === 'departure' ? 'departure' : 'arrival';

  return first_non_empty([
    $row[$kindPrefix . '_driver_name'] ?? '',
    $row[$kindPrefix . '_driver'] ?? '',
    $row['driver_name'] ?? '',
    $row['assigned_driver'] ?? '',
    $row['transport_driver'] ?? '',
  ]) ?: 'Unassigned';
}

function travel_driver_chip_class(string $driver): string {
  if ($driver === 'Unassigned') return 'neutral';

  $seed = abs(crc32($driver)) % 4;
  return match ($seed) {
    0 => 'driver-a',
    1 => 'driver-b',
    2 => 'driver-c',
    default => 'driver-d',
  };
}

function driver_member_status_label(string $status): string {
  $status = strtolower(trim($status));
  return $status === 'active' ? 'Available' : 'Unavailable';
}

function driver_member_status_class(string $status): string {
  $status = strtolower(trim($status));
  return $status === 'active' ? 'is-available' : 'is-unavailable';
}

function travel_terminal_label(array $row, string $kind): string {
  if ($kind === 'departure') {
    return first_non_empty([
      $row['departure_terminal'] ?? '',
      $row['departure_platform'] ?? '',
    ]);
  }

  return first_non_empty([
    $row['arrival_terminal'] ?? '',
    $row['arrival_platform'] ?? '',
  ]);
}

function travel_date_value(array $row, string $kind): string {
  return trim((string)($kind === 'departure' ? ($row['departure_date'] ?? '') : ($row['arrival_date'] ?? '')));
}

function travel_time_value(array $row, string $kind): string {
  return trim((string)($kind === 'departure' ? ($row['departure_time'] ?? '') : ($row['arrival_time'] ?? '')));
}

function travel_ref_value(array $row, string $kind): string {
  return trim((string)($kind === 'departure' ? ($row['departure_ref'] ?? '') : ($row['arrival_ref'] ?? '')));
}

function travel_date_label(string $date): string {
  if ($date === '') return 'Date TBD';
  $ts = strtotime($date);
  return $ts ? date('d/m/Y', $ts) : $date;
}

function travel_time_slot(string $time): string {
  if ($time === '') return 'unknown';

  $hour = (int)substr($time, 0, 2);

  if ($hour >= 5 && $hour < 9)  return 'early';
  if ($hour >= 9 && $hour < 17) return 'day';
  if ($hour >= 17 && $hour < 22) return 'evening';
  return 'late';
}

function travel_trip_exists(array $row, string $kind): bool {
  if ($kind === 'departure') {
    return (int)($row['drop_required'] ?? 0) === 1
      || first_non_empty([
        $row['departure_date'] ?? '',
        $row['departure_time'] ?? '',
        $row['departure_ref'] ?? '',
        $row['departure_terminal'] ?? '',
      ]) !== '';
  }

  return (int)($row['pickup_required'] ?? 0) === 1
    || first_non_empty([
      $row['arrival_date'] ?? '',
      $row['arrival_time'] ?? '',
      $row['arrival_ref'] ?? '',
      $row['arrival_terminal'] ?? '',
    ]) !== '';
}

function travel_missing_parts(array $row): array {
  $missing = [];

  $pickupRequired = (int)($row['pickup_required'] ?? 0) === 1;
  $dropRequired   = (int)($row['drop_required'] ?? 0) === 1;

  $hasArrivalPartial = first_non_empty([
    $row['arrival_date'] ?? '',
    $row['arrival_time'] ?? '',
    $row['arrival_ref'] ?? '',
    $row['arrival_terminal'] ?? '',
  ]) !== '';

  $hasDeparturePartial = first_non_empty([
    $row['departure_date'] ?? '',
    $row['departure_time'] ?? '',
    $row['departure_ref'] ?? '',
    $row['departure_terminal'] ?? '',
  ]) !== '';

  if ($pickupRequired || $hasArrivalPartial) {
    if (trim((string)($row['arrival_date'] ?? '')) === '') {
      $missing[] = 'Arrival date';
    }
    if (trim((string)($row['arrival_time'] ?? '')) === '') {
      $missing[] = 'Arrival time';
    }
    if (trim((string)($row['arrival_ref'] ?? '')) === '') {
      $missing[] = 'Arrival flight / train no.';
    }
    if (trim((string)($row['arrival_terminal'] ?? '')) === '') {
      $missing[] = 'Arrival terminal / platform';
    }
  }

  if ($dropRequired || $hasDeparturePartial) {
    if (trim((string)($row['departure_date'] ?? '')) === '') {
      $missing[] = 'Departure date';
    }
    if (trim((string)($row['departure_time'] ?? '')) === '') {
      $missing[] = 'Departure time';
    }
    if (trim((string)($row['departure_ref'] ?? '')) === '') {
      $missing[] = 'Departure flight / train no.';
    }
    if (trim((string)($row['departure_terminal'] ?? '')) === '') {
      $missing[] = 'Departure terminal / platform';
    }
  }

  return $missing;
}

function build_trip_rows(array $guestRows): array {
  $rows = [];

  foreach ($guestRows as $row) {
    foreach (['arrival', 'departure'] as $kind) {
      if (!travel_trip_exists($row, $kind)) continue;

      $ref = travel_ref_value($row, $kind);
      $date = travel_date_value($row, $kind);
      $time = travel_time_value($row, $kind);
      $terminal = travel_terminal_label($row, $kind);
      $driver = travel_driver_label($row, $kind);
      $mode = detect_travel_mode($ref);

      $sortStamp = 9999999999;
      if ($date !== '') {
        $combined = trim($date . ' ' . ($time !== '' ? $time : '00:00:00'));
        $ts = strtotime($combined);
        if ($ts !== false) $sortStamp = $ts;
      }

      $rows[] = [
        'guest_id'    => (int)($row['id'] ?? 0),
        'guest_name'  => guest_full_name($row),
        'side'        => (string)($row['invited_by'] ?? ''),
        'kind'        => $kind,
        'kind_label'  => $kind === 'departure' ? 'Departure' : 'Arrival',
        'mode'        => $mode,
        'mode_label'  => travel_mode_label($mode),
        'date'        => $date,
        'date_label'  => travel_date_label($date),
        'time'        => $time,
        'terminal'    => $terminal !== '' ? $terminal : 'Missing',
        'driver'      => $driver,
        'driver_class'=> travel_driver_chip_class($driver),
        'mode_class'  => travel_mode_chip_class($mode),
        'has_care'    => guest_has_special_care($row),
        'tags'        => guest_tags_from_row($row),
        'notes'       => trim((string)($row['transport_notes'] ?? '')),
        'sort_stamp'  => $sortStamp,
      ];
    }
  }

  usort($rows, static function (array $a, array $b): int {
    if ($a['sort_stamp'] === $b['sort_stamp']) {
      return strcasecmp($a['guest_name'], $b['guest_name']);
    }
    return $a['sort_stamp'] <=> $b['sort_stamp'];
  });

  return $rows;
}

function travel_page_url(
  int $projectId,
  string $bucket = 'arrivals',
  string $searchQ = '',
  string $side = '',
  string $mode = '',
  string $terminal = '',
  string $driver = '',
  int $guestId = 0
): string {
  $params = ['project_id' => $projectId, 'bucket' => $bucket];
  if ($searchQ !== '') $params['q'] = $searchQ;
  if ($side !== '') $params['side'] = $side;
  if ($mode !== '') $params['mode'] = $mode;
  if ($terminal !== '') $params['terminal'] = $terminal;
  if ($driver !== '') $params['driver'] = $driver;
  if ($guestId > 0) $params['guest_id'] = $guestId;

  return base_url('travel/index.php?' . http_build_query($params));
}

function trip_matches_bucket(array $trip, string $bucket): bool {
  return match ($bucket) {
    'arrivals'   => $trip['kind'] === 'arrival',
    'departures' => $trip['kind'] === 'departure',
    'unassigned' => $trip['driver'] === 'Unassigned',
    'care'       => $trip['has_care'] === true,
    'drivers'    => true,
    default      => true,
  };
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


$projectDriverRows = [];
$companyDriverNames = [];

if (table_exists_local($pdo, 'project_members')) {
  try {
    $ds = $pdo->prepare("
      SELECT DISTINCT
        pm.company_member_id,
        COALESCE(NULLIF(TRIM(cm.full_name), ''), NULLIF(TRIM(pm.display_name), '')) AS full_name,
        COALESCE(NULLIF(TRIM(cm.phone), ''), '') AS phone,
        COALESCE(NULLIF(TRIM(cm.status), ''), 'inactive') AS member_status,
        COALESCE(NULLIF(TRIM(cm.driver_car_model), ''), '') AS driver_car_model,
        COALESCE(NULLIF(TRIM(cm.driver_car_type), ''), '') AS driver_car_type,
        COALESCE(NULLIF(TRIM(cm.driver_plate_number), ''), '') AS driver_plate_number,
        COALESCE(cm.driver_seating_capacity, 0) AS driver_seating_capacity
      FROM project_members pm
      LEFT JOIN company_members cm
        ON cm.id = pm.company_member_id
       AND cm.company_id = :cid
      WHERE pm.project_id = :pid
        AND pm.role = 'driver'
      ORDER BY full_name ASC
    ");
    $ds->execute([
      ':pid' => $projectId,
      ':cid' => $companyId,
    ]);

    foreach ($ds->fetchAll() ?: [] as $driverRow) {
      $name = trim((string)($driverRow['full_name'] ?? ''));
      if ($name === '') continue;

      $carModel = trim((string)($driverRow['driver_car_model'] ?? ''));
      $carType = trim((string)($driverRow['driver_car_type'] ?? ''));
      $plate = trim((string)($driverRow['driver_plate_number'] ?? ''));
      $seats = (int)($driverRow['driver_seating_capacity'] ?? 0);
      $memberStatus = trim((string)($driverRow['member_status'] ?? 'inactive'));

      $vehicleParts = [];
      if ($carModel !== '') $vehicleParts[] = $carModel;
      if ($carType !== '') $vehicleParts[] = $carType;
      if ($seats > 0) $vehicleParts[] = $seats . ' seats';

      $vehicleLabel = $vehicleParts ? implode(' · ', $vehicleParts) : 'Not added';

      $projectDriverRows[] = [
        'name' => $name,
        'phone' => trim((string)($driverRow['phone'] ?? '')),
        'vehicle' => $vehicleLabel,
        'plate' => $plate !== '' ? $plate : 'Not added',
        'member_status' => $memberStatus,
        'status_label' => driver_member_status_label($memberStatus),
        'status_class' => driver_member_status_class($memberStatus),
      ];

      if (strtolower($memberStatus) === 'active') {
        $companyDriverNames[strtolower($name)] = $name;
      }
    }
  } catch (Throwable $e) {
    $projectDriverRows = [];
    $companyDriverNames = [];
  }
}

$driverRosterCount = count($companyDriverNames);

$driverRosterCount = count($companyDriverNames);

$companyDriverNames = [];
if (table_exists_local($pdo, 'company_members')) {
  try {
    $ds = $pdo->prepare("
      SELECT full_name
      FROM company_members
      WHERE company_id = :cid
        AND default_department = 'driver'
        AND status = 'active'
      ORDER BY full_name ASC, id ASC
    ");
    $ds->execute([':cid' => $companyId]);

    foreach ($ds->fetchAll() ?: [] as $driverRow) {
      $name = trim((string)($driverRow['full_name'] ?? ''));
      if ($name !== '') {
        $companyDriverNames[strtolower($name)] = $name;
      }
    }
  } catch (Throwable $e) {
    $companyDriverNames = [];
  }
}

$driverRosterCount = count($companyDriverNames);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_driver_assignment'])) {
  $postedGuestId = (int)($_POST['guest_id'] ?? 0);
  $arrivalDriverInput = trim((string)($_POST['arrival_driver'] ?? ''));
  $departureDriverInput = trim((string)($_POST['departure_driver'] ?? ''));

  $allowedDriverNames = array_values($companyDriverNames);
  $saveErrors = [];

  if ($postedGuestId <= 0) {
    $saveErrors[] = 'Select a guest before assigning drivers.';
  }

  if ($arrivalDriverInput !== '' && !in_array($arrivalDriverInput, $allowedDriverNames, true)) {
    $saveErrors[] = 'Selected arrival driver is not in your driver team.';
  }

  if ($departureDriverInput !== '' && !in_array($departureDriverInput, $allowedDriverNames, true)) {
    $saveErrors[] = 'Selected departure driver is not in your driver team.';
  }

  if (!$saveErrors) {
    try {
      $up = $pdo->prepare("
        UPDATE guests
        SET
          arrival_driver = :arrival_driver,
          departure_driver = :departure_driver,
          updated_at = NOW()
        WHERE id = :guest_id
          AND project_id = :project_id
        LIMIT 1
      ");
      $up->execute([
        ':arrival_driver'   => $arrivalDriverInput !== '' ? $arrivalDriverInput : null,
        ':departure_driver' => $departureDriverInput !== '' ? $departureDriverInput : null,
        ':guest_id'         => $postedGuestId,
        ':project_id'       => $projectId,
      ]);

      if (function_exists('flash_set')) {
        flash_set('success', 'Driver assignment updated.');
      }
    } catch (Throwable $e) {
      if (function_exists('flash_set')) {
        flash_set('error', 'Could not save driver assignment.');
      }
    }
  } else {
    if (function_exists('flash_set')) {
      flash_set('error', $saveErrors[0]);
    }
  }

  redirect(travel_page_url(
    $projectId,
    $bucket,
    $searchQ,
    $filterSide,
    $filterMode,
    $filterTerminal,
    $filterDriver,
    $postedGuestId
  ));
}


/* ---------- Guests / trips ---------- */
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


$allTripRows = build_trip_rows($guestRows);

$arrivalTrips = array_values(array_filter($allTripRows, static fn(array $trip): bool => $trip['kind'] === 'arrival'));
$departureTrips = array_values(array_filter($allTripRows, static fn(array $trip): bool => $trip['kind'] === 'departure'));
$unassignedTrips = array_values(array_filter($allTripRows, static fn(array $trip): bool => $trip['driver'] === 'Unassigned'));
$careTrips = array_values(array_filter($allTripRows, static fn(array $trip): bool => $trip['has_care'] === true));

$displayRows = array_values(array_filter($allTripRows, static fn(array $trip): bool => trip_matches_bucket($trip, $bucket)));

$terminalOptions = [];
$driverOptions = $companyDriverNames;

foreach ($displayRows as $trip) {
  $terminal = trim((string)$trip['terminal']);
  if ($terminal !== '' && $terminal !== 'Missing') {
    $terminalOptions[strtolower($terminal)] = $terminal;
  }
}

foreach ($allTripRows as $trip) {
  $driver = trim((string)$trip['driver']);
  if ($driver !== '' && $driver !== 'Unassigned') {
    $driverOptions[strtolower($driver)] = $driver;
  }
}

natcasesort($terminalOptions);
natcasesort($driverOptions);


$displayRows = array_values(array_filter($displayRows, function (array $trip) use ($searchQ, $filterSide, $filterMode, $filterTerminal, $filterDriver): bool {
  if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $haystack = strtolower(implode(' ', [
      $trip['guest_name'],
      side_label($trip['side']),
      $trip['mode_label'],
      $trip['terminal'],
      $trip['driver'],
      $trip['kind_label'],
    ]));
    if (!str_contains($haystack, $needle)) return false;
  }

  if ($filterSide !== '' && $trip['side'] !== $filterSide) return false;
  if ($filterMode !== '' && $trip['mode'] !== $filterMode) return false;
  if ($filterTerminal !== '' && strtolower($trip['terminal']) !== strtolower($filterTerminal)) return false;
  if ($filterDriver !== '' && strtolower($trip['driver']) !== strtolower($filterDriver)) return false;

  return true;
}));

$arrivalsCount    = count($arrivalTrips);
$departuresCount  = count($departureTrips);
$unassignedCount  = count($unassignedTrips);

$careGuestMap = [];
foreach ($careTrips as $trip) {
  $careGuestMap[(int)$trip['guest_id']] = true;
}
$careCount = count($careGuestMap);

$assignedDriverMap = [];
foreach ($allTripRows as $trip) {
  if ($trip['driver'] !== 'Unassigned') {
    $assignedDriverMap[strtolower($trip['driver'])] = true;
  }
}


$missingTravelGuests = [];

foreach ($guestRows as $row) {
  $missingParts = travel_missing_parts($row);

  if (!$missingParts) {
    continue;
  }

  $missingTravelGuests[] = [
    'guest_id' => (int)($row['id'] ?? 0),
    'name'     => guest_full_name($row),
    'side'     => side_label((string)($row['invited_by'] ?? '')),
    'missing'  => $missingParts,
  ];
}

$missingTravelCount = count($missingTravelGuests);


$driverAssignedCount = count($assignedDriverMap);

$driverTripCounts = [];
foreach ($allTripRows as $trip) {
  $driverName = trim((string)($trip['driver'] ?? ''));
  if ($driverName !== '' && $driverName !== 'Unassigned') {
    $key = strtolower($driverName);
    $driverTripCounts[$key] = ($driverTripCounts[$key] ?? 0) + 1;
  }
}

$driverDisplayRows = [];
foreach ($projectDriverRows as $driverRow) {
  $nameKey = strtolower((string)$driverRow['name']);
  $tripCount = (int)($driverTripCounts[$nameKey] ?? 0);

  if ($searchQ !== '') {
    $needle = strtolower($searchQ);
    $haystack = strtolower(implode(' ', [
      $driverRow['name'],
      $driverRow['phone'],
      $driverRow['vehicle'],
      $driverRow['plate'],
      $driverRow['status_label'],
    ]));
    if (!str_contains($haystack, $needle)) {
      continue;
    }
  }

  $driverDisplayRows[] = $driverRow + [
    'trip_count' => $tripCount,
  ];
}

/* ---------- Overview numbers ---------- */
$missingTerminalCount = 0;
$lateNightArrivalsCount = 0;
$assignedTripCount = 0;

$pickupLocationCounts = [];
$timeSlotCounts = [
  'early' => 0,
  'day' => 0,
  'evening' => 0,
  'late' => 0,
];

$specialCounts = [
  'VIP' => 0,
  'Elder care' => 0,
  'Wheelchair' => 0,
  'Extra luggage' => 0,
  'Child seat' => 0,
];

foreach ($allTripRows as $trip) {
  if ($trip['terminal'] === 'Missing') $missingTerminalCount++;
  if ($trip['driver'] !== 'Unassigned') $assignedTripCount++;

  if ($trip['kind'] === 'arrival' && travel_time_slot($trip['time']) === 'late') {
    $lateNightArrivalsCount++;
  }

  if ($trip['terminal'] !== 'Missing') {
    $pickupLocationCounts[$trip['terminal']] = ($pickupLocationCounts[$trip['terminal']] ?? 0) + 1;
  }

  $slot = travel_time_slot($trip['time']);
  if (isset($timeSlotCounts[$slot])) {
    $timeSlotCounts[$slot]++;
  }

  if (in_array('VIP', $trip['tags'], true)) $specialCounts['VIP']++;
  if (in_array('Elder', $trip['tags'], true)) $specialCounts['Elder care']++;
  if (in_array('Wheelchair', $trip['tags'], true)) $specialCounts['Wheelchair']++;
  if (str_contains(strtolower($trip['notes']), 'luggage')) $specialCounts['Extra luggage']++;
  if (in_array('Toddler', $trip['tags'], true)) $specialCounts['Child seat']++;
}


$selectedGuestName = '';
$selectedGuestSide = '';
$selectedGuestRelation = '';
$selectedGuestFamilyGroup = '';
$selectedPickupRequired = 0;
$selectedDropRequired = 0;
$selectedArrivalDate = '';
$selectedArrivalTime = '';
$selectedArrivalRef = '';
$selectedArrivalTerminal = '';
$selectedDepartureDate = '';
$selectedDepartureTime = '';
$selectedDepartureRef = '';
$selectedDepartureTerminal = '';
$selectedTransportNotes = '';
$selectedArrivalDriver = 'Unassigned';
$selectedDepartureDriver = 'Unassigned';
$selectedArrivalDriverValue = '';
$selectedDepartureDriverValue = '';

if ($selectedGuest) {
  $selectedGuestName = guest_full_name($selectedGuest);
  $selectedGuestSide = side_label((string)($selectedGuest['invited_by'] ?? ''));
  $selectedGuestRelation = trim((string)($selectedGuest['relation_label'] ?? ''));
  $selectedGuestFamilyGroup = trim((string)($selectedGuest['family_group'] ?? ''));
  $selectedPickupRequired = (int)($selectedGuest['pickup_required'] ?? 0);
  $selectedDropRequired = (int)($selectedGuest['drop_required'] ?? 0);
  $selectedArrivalDate = trim((string)($selectedGuest['arrival_date'] ?? ''));
  $selectedArrivalTime = trim((string)($selectedGuest['arrival_time'] ?? ''));
  $selectedArrivalRef = trim((string)($selectedGuest['arrival_ref'] ?? ''));
  $selectedArrivalTerminal = trim((string)($selectedGuest['arrival_terminal'] ?? ''));
  $selectedDepartureDate = trim((string)($selectedGuest['departure_date'] ?? ''));
  $selectedDepartureTime = trim((string)($selectedGuest['departure_time'] ?? ''));
  $selectedDepartureRef = trim((string)($selectedGuest['departure_ref'] ?? ''));
  $selectedDepartureTerminal = trim((string)($selectedGuest['departure_terminal'] ?? ''));
  $selectedTransportNotes = trim((string)($selectedGuest['transport_notes'] ?? ''));
  $selectedArrivalDriverValue = trim((string)($selectedGuest['arrival_driver'] ?? ''));
  $selectedDepartureDriverValue = trim((string)($selectedGuest['departure_driver'] ?? ''));
  $selectedArrivalDriver = travel_driver_label($selectedGuest, 'arrival');
  $selectedDepartureDriver = travel_driver_label($selectedGuest, 'departure');
}


arsort($pickupLocationCounts);

$pageTitle = $projectTitle . ' — Travel and transport — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.proj-main{ min-width:0; }

.travel-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:18px;
  margin-bottom:18px;
}
.travel-head .left h2{
  margin:0;
  font-size:26px;
  line-height:1.08;
  font-weight:800;
  color:#1d1d1f;
}
.travel-head .left p{
  margin:8px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.5;
  max-width:660px;
}
.travel-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.travel-actions .icon-btn{
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

.travel-top-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) 340px;
  gap:16px;
  align-items:start;
}
@media (max-width:1380px){
  .travel-top-grid{ grid-template-columns:1fr; }
}

.travel-main{
  min-width:0;
}

.travel-side{
  min-width:0;
  width:100%;
  max-width:340px;
  justify-self:end;
  display:flex;
  flex-direction:column;
  gap:16px;
}

.travel-stat-row{
  display:grid;
  grid-template-columns:repeat(5, minmax(0,1fr));
  gap:12px;
  margin:0 0 14px 0;
}
@media (max-width:1180px){
  .travel-stat-row{ grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width:680px){
  .travel-stat-row{ grid-template-columns:1fr; }
}

.travel-stat-card{
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
.travel-stat-card.is-active{
  background:rgba(75,0,31,0.06);
  border-color:rgba(75,0,31,0.08);
}
.travel-stat-title{
  font-size:16px;
  font-weight:800;
  line-height:1.2;
}
.travel-stat-sub{
  margin-top:4px;
  color:#7a7a80;
  font-size:12px;
  line-height:1.35;
}

.travel-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:12px;
  flex-wrap:wrap;
}
.travel-search{ flex:1 1 280px; }
.travel-search-wrap{ position:relative; }
.travel-search-wrap .search-ico{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#9a9aa1;
  font-size:13px;
  pointer-events:none;
}
.travel-search input{
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
.travel-search input::placeholder{ color:#9b9ba2; }

.travel-toolbar-right{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.travel-date-chip{
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

.travel-table-card{
  padding:8px 14px 10px;
  border-radius:26px;
  overflow-x:auto;
  overflow-y:hidden;
}
.travel-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.travel-table thead th{
  text-align:left;
  padding:14px 14px 14px;
  font-size:12px;
  color:#b0b0b6;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
  vertical-align:top;
}
.travel-table tbody td{
  text-align:left;
  padding:12px 10px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:13px;
  color:#1f1f22;
}
.travel-table tbody tr:last-child td{ border-bottom:none; }

.travel-th-wrap{
  display:flex;
  flex-direction:column;
  gap:7px;
}
.travel-th-top{
  display:flex;
  align-items:center;
  gap:6px;
  color:#b0b0b6;
  font-size:12px;
  font-weight:700;
}
.travel-th-top .chev{
  font-size:11px;
  color:#c0c0c6;
}
.travel-th-filter{
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

.travel-table thead th.travel-col-name{
  width:150px;
  min-width:150px;
}
.travel-table thead th:nth-child(2),
.travel-table tbody td:nth-child(2){
  width:95px;
  min-width:95px;
}
.travel-table thead th:nth-child(3),
.travel-table tbody td:nth-child(3){
  width:95px;
  min-width:95px;
}
.travel-table thead th:nth-child(4),
.travel-table tbody td:nth-child(4){
  width:86px;
  min-width:86px;
}
.travel-table thead th:nth-child(5),
.travel-table tbody td:nth-child(5){
  width:76px;
  min-width:76px;
}
.travel-table thead th:nth-child(6),
.travel-table tbody td:nth-child(6){
  width:110px;
  min-width:110px;
}

.travel-name{
  font-weight:500;
  color:#1d1d1f;
  line-height:1.3;
  width:150px;
  min-width:150px;
  max-width:150px;
  white-space:normal;
  overflow-wrap:anywhere;
}
.travel-side-text{
  color:#4f4f55;
  font-size:13px;
  line-height:1.35;
}

.travel-table-row{
  cursor:pointer;
}
.travel-table-row:hover td{
  background:rgba(0,0,0,0.02);
}
.travel-table-row.is-selected td{
  background:rgba(75,0,31,0.045);
}
.travel-table-row.is-selected td:first-child{
  border-top-left-radius:18px;
  border-bottom-left-radius:18px;
}
.travel-table-row.is-selected td:last-child{
  border-top-right-radius:18px;
  border-bottom-right-radius:18px;
}

.table-chip{
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
.table-chip.ok{
  background:#dff1cf;
  color:#54733e;
}
.table-chip.olive{
  background:#d7e4cf;
  color:#587156;
}
.table-chip.warn{
  background:#f4cccc;
  color:#875050;
}
.table-chip.neutral{
  background:#efefef;
  color:#7b7b82;
}
.table-chip.driver-a{
  background:#f6e9c8;
  color:#7e6840;
}
.table-chip.driver-b{
  background:#d6eef8;
  color:#4a7080;
}
.table-chip.driver-c{
  background:#e8daf6;
  color:#78658d;
}
.table-chip.driver-d{
  background:#f2d8eb;
  color:#8a5f7b;
}

.empty-table{
  padding:18px;
  border-radius:18px;
  background:rgba(0,0,0,0.02);
  color:#75757a;
  font-size:13px;
  line-height:1.55;
}


.travel-driver-table-card{
  padding:8px 14px 10px;
  border-radius:26px;
  overflow-x:auto;
  overflow-y:hidden;
}

.travel-driver-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}

.travel-driver-table thead th{
  text-align:left;
  padding:14px 14px 14px;
  font-size:12px;
  color:#b0b0b6;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
  vertical-align:top;
}

.travel-driver-table tbody td{
  text-align:left;
  padding:14px 14px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:13px;
  color:#1f1f22;
}

.travel-driver-table tbody tr:last-child td{
  border-bottom:none;
}

.travel-driver-name{
  font-size:14px;
  font-weight:700;
  color:#1d1d1f;
  line-height:1.35;
}

.travel-driver-meta{
  color:#66666d;
  font-size:13px;
  line-height:1.4;
}

.driver-status-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:30px;
  padding:0 14px;
  border-radius:999px;
  font-size:12px;
  font-weight:600;
  white-space:nowrap;
}

.driver-status-pill.is-available{
  background:#efefef;
  color:#6a6a70;
}

.driver-status-pill.is-unavailable{
  background:#f4e6e6;
  color:#8a5b5b;
}


.overview-card,
.travel-detail-card{
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

.overview-guest-item{
  padding:8px 0;
  border-top:1px solid rgba(0,0,0,0.05);
}
.overview-guest-item:first-child{
  border-top:none;
  padding-top:2px;
}
.overview-guest-name{
  font-size:13px;
  font-weight:700;
  color:#2a2a2d;
  line-height:1.35;
}
.overview-guest-meta{
  margin-top:2px;
  font-size:12px;
  color:#7b7b82;
}
.overview-guest-missing{
  margin-top:4px;
  font-size:12px;
  color:#5d5d63;
  line-height:1.5;
}
.overview-guest-more{
  margin-top:6px;
  font-size:12px;
  color:#7b7b82;
}

.travel-detail-empty{
  display:grid;
  place-items:center;
  min-height:240px;
  text-align:center;
  color:#76767c;
  padding:16px;
}
.travel-detail-empty-title{
  font-size:15px;
  font-weight:700;
  color:#2a2a2d;
}
.travel-detail-empty-sub{
  margin-top:8px;
  font-size:12px;
  line-height:1.55;
  color:#7a7a80;
  max-width:240px;
}

.travel-detail-topline{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:14px;
}
.travel-detail-label{
  font-size:14px;
  font-weight:800;
  color:#4d4d53;
}
.travel-close{
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
.travel-close:hover{ background:#f7f7f8; }

.travel-detail-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.travel-detail-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#1d1d1f;
}
.travel-detail-sub{
  margin-top:6px;
  color:#6f6f73;
  font-size:12px;
}

.travel-detail-divider{
  display:flex;
  align-items:center;
  gap:10px;
  margin:14px 0;
  color:#aaa9b1;
}
.travel-detail-divider::before,
.travel-detail-divider::after{
  content:"";
  flex:1;
  height:1px;
  background:rgba(0,0,0,0.08);
}
.travel-detail-divider span{
  font-size:12px;
  line-height:1;
}

.travel-detail-top-grid{
  display:grid;
  grid-template-columns:1fr 110px;
  gap:10px;
}
.travel-mini-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.travel-field-label{
  font-size:11px;
  color:#8b8b91;
  margin-bottom:6px;
}
.travel-input,
.travel-select{
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
.travel-select{
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
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
.travel-input:focus,
.travel-select:focus{
  border-color:rgba(0,0,0,0.16);
  background:#fff;
}
.travel-section{
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid rgba(0,0,0,0.06);
}
.travel-section-title{
  font-size:16px;
  font-weight:800;
  color:#1f1f22;
  margin-bottom:10px;
}
.travel-detail-actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:16px;
  flex-wrap:wrap;
}

.travel-accordion-wrap{
  display:flex;
  flex-direction:column;
  gap:0;
  margin-top:14px;
}

.travel-accordion{
  border-top:1px solid rgba(0,0,0,0.06);
  padding-top:12px;
}

.travel-accordion summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:2px 0 6px;
}

.travel-accordion summary::-webkit-details-marker{
  display:none;
}

.travel-accordion-title{
  font-size:16px;
  font-weight:800;
  color:#1f1f22;
  line-height:1.2;
}

.travel-accordion-chevron{
  color:#8a8a90;
  font-weight:400;
  font-size:16px;
  line-height:1;
  transition:transform 160ms ease;
}

.travel-accordion[open] .travel-accordion-chevron{
  transform:rotate(180deg);
}

.travel-accordion-body{
  padding-top:10px;
}



@media (max-width:980px){
  .travel-head{
    flex-direction:column;
    align-items:flex-start;
  }
}
@media (max-width:560px){
  .travel-detail-top-grid,
  .travel-mini-grid{
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
          $active = 'travel';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <div class="travel-head">
            <div class="left">
              <h2>Travel and transport</h2>
              <p>Plan arrivals and departures, assign pickups, and track trip status in one place.</p>
            </div>

            <div class="travel-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId)); ?>">👁 Preview guest list</a>
              <button class="btn btn-primary" type="button" <?php echo empty($allTripRows) ? 'disabled' : ''; ?>>☆ Send invites</button>
            </div>
          </div>

          <div class="travel-stat-row">
            <a class="travel-stat-card <?php echo $bucket === 'arrivals' ? 'is-active' : ''; ?>" href="<?php echo esc(travel_page_url($projectId, 'arrivals')); ?>">
              <div class="travel-stat-title">Arrivals</div>
              <div class="travel-stat-sub"><?php echo esc((string)$arrivalsCount); ?> scheduled</div>
            </a>

            <a class="travel-stat-card <?php echo $bucket === 'departures' ? 'is-active' : ''; ?>" href="<?php echo esc(travel_page_url($projectId, 'departures')); ?>">
              <div class="travel-stat-title">Departures</div>
              <div class="travel-stat-sub"><?php echo esc((string)$departuresCount); ?> scheduled</div>
            </a>

            <a class="travel-stat-card <?php echo $bucket === 'unassigned' ? 'is-active' : ''; ?>" href="<?php echo esc(travel_page_url($projectId, 'unassigned')); ?>">
              <div class="travel-stat-title">Unassigned</div>
              <div class="travel-stat-sub"><?php echo esc((string)$unassignedCount); ?> trips unassigned</div>
            </a>

            <a class="travel-stat-card <?php echo $bucket === 'care' ? 'is-active' : ''; ?>" href="<?php echo esc(travel_page_url($projectId, 'care')); ?>">
              <div class="travel-stat-title">VIP / Elder care</div>
              <div class="travel-stat-sub"><?php echo esc((string)$careCount); ?> guests require special care</div>
            </a>

            <a class="travel-stat-card <?php echo $bucket === 'drivers' ? 'is-active' : ''; ?>" href="<?php echo esc(travel_page_url($projectId, 'drivers')); ?>">
  <div class="travel-stat-title">Drivers</div>
  <div class="travel-stat-sub"><?php echo esc((string)$driverRosterCount); ?> drivers available</div>
</a>
          </div>

          <div class="travel-top-grid">
            <div class="travel-main">
              <form method="get">
                <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">
                <input type="hidden" name="bucket" value="<?php echo esc($bucket); ?>">

                <div class="travel-toolbar">
  <div class="travel-search">
    <div class="travel-search-wrap">
      <span class="search-ico">🔍</span>
      <input
        type="text"
        name="q"
        value="<?php echo esc($searchQ); ?>"
        placeholder="<?php echo $bucket === 'drivers' ? 'Search driver' : 'Search guest'; ?>"
      >
    </div>
  </div>

  <?php if ($bucket !== 'drivers'): ?>
    <div class="travel-toolbar-right">
      <button class="travel-date-chip" type="button">Custom dates — dd/mm/yyyy to dd/mm/yyyy</button>
    </div>
  <?php endif; ?>
</div>

                <?php if ($bucket === 'drivers'): ?>
  <div class="card proj-card travel-driver-table-card">
    <?php if ($driverDisplayRows): ?>
      <table class="travel-driver-table">
        <thead>
          <tr>
            <th>Driver</th>
            <th>Phone</th>
            <th>Vehicle</th>
            <th>Number plate</th>
            <th>Trips</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($driverDisplayRows as $driverRow): ?>
            <tr>
              <td><div class="travel-driver-name"><?php echo esc($driverRow['name']); ?></div></td>
              <td><div class="travel-driver-meta"><?php echo esc($driverRow['phone'] !== '' ? $driverRow['phone'] : 'Not added'); ?></div></td>
              <td><div class="travel-driver-meta"><?php echo esc($driverRow['vehicle']); ?></div></td>
              <td><div class="travel-driver-meta"><?php echo esc($driverRow['plate']); ?></div></td>
              <td><div class="travel-driver-meta"><?php echo esc((string)$driverRow['trip_count']); ?></div></td>
              <td>
                <span class="driver-status-pill <?php echo esc($driverRow['status_class']); ?>">
                  <?php echo esc($driverRow['status_label']); ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty-table">
        No drivers match this view yet. Add project members with the driver responsibility to see them here.
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card proj-card travel-table-card">
    <?php if ($displayRows): ?>
      <table class="travel-table">
        <thead>
          <tr>
            <th class="travel-col-name">Guest name</th>

            <th>
              <div class="travel-th-wrap">
                <div class="travel-th-top">Side <span class="chev">⌄</span></div>
                <select class="travel-th-filter" name="side" onchange="this.form.submit()">
                  <option value="">All</option>
                  <option value="bride" <?php echo $filterSide === 'bride' ? 'selected' : ''; ?>>Bride’s side</option>
                  <option value="groom" <?php echo $filterSide === 'groom' ? 'selected' : ''; ?>>Groom’s side</option>
                  <option value="both" <?php echo $filterSide === 'both' ? 'selected' : ''; ?>>Both families</option>
                </select>
              </div>
            </th>

            <th>
              <div class="travel-th-wrap">
                <div class="travel-th-top">Train / flight <span class="chev">⌄</span></div>
                <select class="travel-th-filter" name="mode" onchange="this.form.submit()">
                  <option value="">All</option>
                  <option value="flight" <?php echo $filterMode === 'flight' ? 'selected' : ''; ?>>Flight</option>
                  <option value="train" <?php echo $filterMode === 'train' ? 'selected' : ''; ?>>Train</option>
                  <option value="not_sure" <?php echo $filterMode === 'not_sure' ? 'selected' : ''; ?>>Not sure</option>
                </select>
              </div>
            </th>

            <th>
              <div class="travel-th-wrap">
                <div class="travel-th-top">Date</div>
                <div class="travel-th-filter" style="display:flex;align-items:center;">dd/mm/yyyy</div>
              </div>
            </th>

            <th>
              <div class="travel-th-wrap">
                <div class="travel-th-top">Terminal <span class="chev">⌄</span></div>
                <select class="travel-th-filter" name="terminal" onchange="this.form.submit()">
                  <option value="">All</option>
                  <?php foreach ($terminalOptions as $terminalOption): ?>
                    <option value="<?php echo esc($terminalOption); ?>" <?php echo strtolower($filterTerminal) === strtolower($terminalOption) ? 'selected' : ''; ?>>
                      <?php echo esc($terminalOption); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </th>

            <th>
              <div class="travel-th-wrap">
                <div class="travel-th-top">Driver <span class="chev">⌄</span></div>
                <select class="travel-th-filter" name="driver" onchange="this.form.submit()">
                  <option value="">All</option>
                  <?php foreach ($driverOptions as $driverOption): ?>
                    <option value="<?php echo esc($driverOption); ?>" <?php echo strtolower($filterDriver) === strtolower($driverOption) ? 'selected' : ''; ?>>
                      <?php echo esc($driverOption); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($displayRows as $trip): ?>
            <?php
              $rowUrl = travel_page_url(
                $projectId,
                $bucket,
                $searchQ,
                $filterSide,
                $filterMode,
                $filterTerminal,
                $filterDriver,
                (int)$trip['guest_id']
              );

              $isSelected = $selectedGuestId > 0 && (int)$trip['guest_id'] === $selectedGuestId;
            ?>
            <tr
              class="travel-table-row <?php echo $isSelected ? 'is-selected' : ''; ?>"
              data-travel-row-url="<?php echo esc($rowUrl); ?>"
              tabindex="0"
              role="button"
              aria-label="View travel details for <?php echo esc($trip['guest_name']); ?>"
            >
              <td class="travel-name"><?php echo esc($trip['guest_name']); ?></td>
              <td class="travel-side-text"><?php echo esc(side_label($trip['side'])); ?></td>
              <td><span class="table-chip <?php echo esc($trip['mode_class']); ?>"><?php echo esc($trip['mode_label']); ?></span></td>
              <td><span class="table-chip neutral"><?php echo esc($trip['date_label']); ?></span></td>
              <td><span class="table-chip neutral"><?php echo esc($trip['terminal']); ?></span></td>
              <td><span class="table-chip <?php echo esc($trip['driver_class']); ?>"><?php echo esc($trip['driver']); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty-table">
        No travel records match this view yet. Save arrival or departure details on the guest form first, then they will show up here.
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
              </form>
            </div>

           <aside class="travel-side">
  <section class="card proj-card overview-card">
    <h3 class="overview-title">Travel and transport overview</h3>
    <p class="overview-sub">What needs cleaning before pickups and drops go out.</p>

    <div class="overview-wrap">
      <details class="overview-group">
        <summary>
          <span>At risk</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <div class="overview-row"><div class="label">Unassigned trips</div><div class="value"><?php echo esc((string)$unassignedCount); ?></div></div>
          <div class="overview-row"><div class="label">Missing terminal / platform</div><div class="value"><?php echo esc((string)$missingTerminalCount); ?></div></div>
          <div class="overview-row"><div class="label">Late night arrivals</div><div class="value"><?php echo esc((string)$lateNightArrivalsCount); ?></div></div>
        </div>
      </details>

      <details class="overview-group">
        <summary>
          <span>Trip status</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <div class="overview-row"><div class="label">Unassigned driver</div><div class="value"><?php echo esc((string)$unassignedCount); ?></div></div>
          <div class="overview-row"><div class="label">Assigned</div><div class="value"><?php echo esc((string)$assignedTripCount); ?></div></div>
          <div class="overview-row"><div class="label">In progress</div><div class="value">0</div></div>
          <div class="overview-row"><div class="label">Completed</div><div class="value">0</div></div>
        </div>
      </details>

      <details class="overview-group">
        <summary>
          <span>Pick up locations</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <?php if ($pickupLocationCounts): ?>
            <?php foreach (array_slice($pickupLocationCounts, 0, 5, true) as $location => $count): ?>
              <div class="overview-row"><div class="label"><?php echo esc($location); ?></div><div class="value"><?php echo esc((string)$count); ?></div></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="overview-row"><div class="label">No terminals added yet</div><div class="value">0</div></div>
          <?php endif; ?>
        </div>
      </details>

      <details class="overview-group">
        <summary>
          <span>Time slots</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <div class="overview-row"><div class="label">Early (5–9am)</div><div class="value"><?php echo esc((string)$timeSlotCounts['early']); ?></div></div>
          <div class="overview-row"><div class="label">Day (9am–5pm)</div><div class="value"><?php echo esc((string)$timeSlotCounts['day']); ?></div></div>
          <div class="overview-row"><div class="label">Evening (5–10pm)</div><div class="value"><?php echo esc((string)$timeSlotCounts['evening']); ?></div></div>
          <div class="overview-row"><div class="label">Late (10pm+)</div><div class="value"><?php echo esc((string)$timeSlotCounts['late']); ?></div></div>
        </div>
      </details>

      <details class="overview-group">
        <summary>
          <span>Missing travel details</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <?php if ($missingTravelGuests): ?>
            <?php foreach (array_slice($missingTravelGuests, 0, 6) as $guest): ?>
              <div class="overview-guest-item">
                <div class="overview-guest-name"><?php echo esc($guest['name']); ?></div>
                <div class="overview-guest-meta"><?php echo esc($guest['side']); ?></div>
                <div class="overview-guest-missing">
                  Missing: <?php echo esc(implode(', ', $guest['missing'])); ?>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if (count($missingTravelGuests) > 6): ?>
              <div class="overview-guest-more">
                +<?php echo esc((string)(count($missingTravelGuests) - 6)); ?> more guests with incomplete travel details
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="overview-row">
              <div class="label">No incomplete travel details</div>
              <div class="value">0</div>
            </div>
          <?php endif; ?>
        </div>
      </details>

      <details class="overview-group">
        <summary>
          <span>Special handling</span>
          <span class="overview-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="overview-list">
          <?php foreach ($specialCounts as $label => $count): ?>
            <div class="overview-row"><div class="label"><?php echo esc($label); ?></div><div class="value"><?php echo esc((string)$count); ?></div></div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
  </section>

  <?php if ($bucket !== 'drivers'): ?>
  <section class="card proj-card travel-detail-card">
    <?php if ($selectedGuest): ?>
      <?php
        $closeUrl = travel_page_url(
          $projectId,
          $bucket,
          $searchQ,
          $filterSide,
          $filterMode,
          $filterTerminal,
          $filterDriver,
          0
        );
      ?>

      <form method="post" action="">
        <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">
        <input type="hidden" name="bucket" value="<?php echo esc($bucket); ?>">
        <input type="hidden" name="q" value="<?php echo esc($searchQ); ?>">
        <input type="hidden" name="side" value="<?php echo esc($filterSide); ?>">
        <input type="hidden" name="mode" value="<?php echo esc($filterMode); ?>">
        <input type="hidden" name="terminal" value="<?php echo esc($filterTerminal); ?>">
        <input type="hidden" name="driver" value="<?php echo esc($filterDriver); ?>">
        <input type="hidden" name="guest_id" value="<?php echo esc((string)$selectedGuestId); ?>">
        <input type="hidden" name="save_driver_assignment" value="1">

      <div class="travel-detail-topline">

        <div class="travel-detail-label">Guest detail</div>
        <a class="travel-close" href="<?php echo esc($closeUrl); ?>" aria-label="Close travel details">×</a>
      </div>

      <div class="travel-detail-head">
        <div>
          <h3 class="travel-detail-title"><?php echo esc($selectedGuestName); ?></h3>
          <div class="travel-detail-sub"><?php echo esc($selectedGuestSide); ?></div>
        </div>
      </div>

      <div class="travel-accordion-wrap">
        <details class="travel-accordion">
          <summary>
            <span class="travel-accordion-title">Contact information</span>
            <span class="travel-accordion-chevron" aria-hidden="true">⌄</span>
          </summary>

          <div class="travel-accordion-body">
            <div class="travel-detail-top-grid">
              <div>
                <div class="travel-field-label">Relation</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedGuestRelation !== '' ? $selectedGuestRelation : 'Not added'); ?>" readonly>
              </div>
              <div>
                <div class="travel-field-label">Family Group</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedGuestFamilyGroup !== '' ? $selectedGuestFamilyGroup : 'Not added'); ?>" readonly>
              </div>
            </div>

            <div class="travel-section" style="margin-top:14px;">
              <div class="travel-section-title">Travel information</div>

              <div class="travel-mini-grid">
                <div>
                  <div class="travel-field-label">Pick up</div>
                  <select class="travel-select" disabled>
                    <option <?php echo $selectedPickupRequired === 1 ? 'selected' : ''; ?>>Yes</option>
                    <option <?php echo $selectedPickupRequired !== 1 ? 'selected' : ''; ?>>No</option>
                  </select>
                </div>
                <div>
                  <div class="travel-field-label">Drop off</div>
                  <select class="travel-select" disabled>
                    <option <?php echo $selectedDropRequired === 1 ? 'selected' : ''; ?>>Yes</option>
                    <option <?php echo $selectedDropRequired !== 1 ? 'selected' : ''; ?>>No</option>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </details>

        <details class="travel-accordion">
          <summary>
            <span class="travel-accordion-title">Arrival</span>
            <span class="travel-accordion-chevron" aria-hidden="true">⌄</span>
          </summary>

          <div class="travel-accordion-body">
            <div>
              <div class="travel-field-label">Assigned driver</div>
              <select class="travel-select" name="arrival_driver">
                <option value="">Unassigned</option>
                <?php foreach ($companyDriverNames as $driverName): ?>
                  <option value="<?php echo esc($driverName); ?>" <?php echo $selectedArrivalDriverValue === $driverName ? 'selected' : ''; ?>>
                    <?php echo esc($driverName); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="travel-mini-grid" style="margin-top:10px;">
              <div>
                <div class="travel-field-label">Arrival date</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedArrivalDate !== '' ? $selectedArrivalDate : 'dd/mm/yyyy'); ?>" readonly>
              </div>
              <div>
                <div class="travel-field-label">Flight / train number</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedArrivalRef !== '' ? $selectedArrivalRef : 'eg. AI-1234'); ?>" readonly>
              </div>
            </div>

            <div class="travel-mini-grid" style="margin-top:10px;">
              <div>
                <div class="travel-field-label">Arrival time</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedArrivalTime !== '' ? $selectedArrivalTime : 'Not added'); ?>" readonly>
              </div>
              <div>
                <div class="travel-field-label">Terminal</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedArrivalTerminal !== '' ? $selectedArrivalTerminal : 'Not added'); ?>" readonly>
              </div>
            </div>

            <div style="margin-top:10px;">
              <div class="travel-field-label">Pick up notes</div>
              <input class="travel-input" type="text" value="<?php echo esc($selectedTransportNotes !== '' ? $selectedTransportNotes : 'Not added'); ?>" readonly>
            </div>
          </div>
        </details>

        <details class="travel-accordion">
          <summary>
            <span class="travel-accordion-title">Departure</span>
            <span class="travel-accordion-chevron" aria-hidden="true">⌄</span>
          </summary>

          <div class="travel-accordion-body">
            <div>
              <div class="travel-field-label">Assigned driver</div>
              <select class="travel-select" name="departure_driver">
                <option value="">Unassigned</option>
                <?php foreach ($companyDriverNames as $driverName): ?>
                  <option value="<?php echo esc($driverName); ?>" <?php echo $selectedDepartureDriverValue === $driverName ? 'selected' : ''; ?>>
                    <?php echo esc($driverName); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="travel-mini-grid" style="margin-top:10px;">
              <div>
                <div class="travel-field-label">Departure date</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedDepartureDate !== '' ? $selectedDepartureDate : 'dd/mm/yyyy'); ?>" readonly>
              </div>
              <div>
                <div class="travel-field-label">Flight / train number</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedDepartureRef !== '' ? $selectedDepartureRef : 'eg. AI-1234'); ?>" readonly>
              </div>
            </div>

            <div class="travel-mini-grid" style="margin-top:10px;">
              <div>
                <div class="travel-field-label">Departure time</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedDepartureTime !== '' ? $selectedDepartureTime : 'Not added'); ?>" readonly>
              </div>
              <div>
                <div class="travel-field-label">Terminal</div>
                <input class="travel-input" type="text" value="<?php echo esc($selectedDepartureTerminal !== '' ? $selectedDepartureTerminal : 'Not added'); ?>" readonly>
              </div>
            </div>

            <div style="margin-top:10px;">
              <div class="travel-field-label">Drop notes</div>
              <input class="travel-input" type="text" value="<?php echo esc($selectedTransportNotes !== '' ? $selectedTransportNotes : 'Not added'); ?>" readonly>
            </div>
          </div>
        </details>
      </div>

      <div class="travel-detail-actions">
        <a class="btn" href="<?php echo esc(base_url('guests/create.php?project_id=' . $projectId . '&guest_id=' . (int)($selectedGuest['id'] ?? 0))); ?>">Edit guest</a>
        <button class="btn btn-primary" type="submit">Save drivers</button>
      </div>
      </form>
    <?php else: ?>
      <div class="travel-detail-empty">
        <div>
          <div class="travel-detail-empty-title">Select a guest</div>
          <div class="travel-detail-empty-sub">
            Click any guest row to open the travel detail bento on the right and review their transport information.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>
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
  const rows = document.querySelectorAll("[data-travel-row-url]");

  rows.forEach((row) => {
    const url = row.getAttribute("data-travel-row-url");
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