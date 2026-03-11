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

$companyId = current_company_id();

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function selected_attr(string $current, string $value): string {
  return $current === $value ? 'selected' : '';
}

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

function normalize_date_input(?string $value): ?string {
  $value = trim((string)$value);
  return $value !== '' ? $value : null;
}

function normalize_text_input(?string $value): ?string {
  $value = trim((string)$value);
  return $value !== '' ? $value : null;
}

function request_value(string $key, array $defaults = [], string $fallback = ''): string {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    return trim((string)($_POST[$key] ?? $fallback));
  }
  return trim((string)($defaults[$key] ?? $fallback));
}

function request_array_value(string $key, array $defaults = []): array {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $value = $_POST[$key] ?? [];
    return is_array($value) ? array_values(array_map('strval', $value)) : [];
  }
  $value = $defaults[$key] ?? [];
  return is_array($value) ? array_values(array_map('strval', $value)) : [];
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

/* ---------- Top meta ---------- */
$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') {
  $adminName = 'Admin';
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

$projectDateLabel = $topDateLabel;

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

/* ---------- Project title ---------- */
$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));

$projectTitle = trim((string)($project['title'] ?? ''));
if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

/* ---------- Events ---------- */
$events = [];
try {
  $es = $pdo->prepare("
    SELECT id, name, starts_at, venue, hosting_side
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC, id ASC
  ");
  $es->execute([':pid' => $projectId]);
  $events = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $events = [];
}

/* ---------- Table checks ---------- */
$guestsTableReady = local_table_exists($pdo, 'guests');
$guestInvitesTableReady = local_table_exists($pdo, 'guest_event_invites');
$currentDb = (string)($pdo->query("SELECT DATABASE()")->fetchColumn() ?: '');

/* ---------- Edit mode ---------- */
$guestId = (int)($_GET['guest_id'] ?? $_POST['guest_id'] ?? 0);
$isEditMode = false;
$existingGuest = null;
$existingEventIds = [];

if ($guestId > 0 && $guestsTableReady) {
  $gst = $pdo->prepare("
    SELECT *
    FROM guests
    WHERE id = :gid
      AND project_id = :pid
    LIMIT 1
  ");
  $gst->execute([
    ':gid' => $guestId,
    ':pid' => $projectId,
  ]);
  $existingGuest = $gst->fetch() ?: null;

  if (!$existingGuest) {
    redirect('guests/index.php?project_id=' . $projectId);
  }

  $isEditMode = true;

  if ($guestInvitesTableReady) {
    try {
      $ist = $pdo->prepare("
        SELECT event_id
        FROM guest_event_invites
        WHERE guest_id = :guest_id
      ");
      $ist->execute([':guest_id' => $guestId]);
      $existingEventIds = array_map('strval', array_column($ist->fetchAll() ?: [], 'event_id'));
    } catch (Throwable $e) {
      $existingEventIds = [];
    }
  }
}

/* ---------- Defaults ---------- */
$defaults = [];
if ($existingGuest) {
  $defaults = [
    'title'            => (string)($existingGuest['title'] ?? ''),
    'invited_by'       => (string)($existingGuest['invited_by'] ?? ''),
    'first_name'       => (string)($existingGuest['first_name'] ?? ''),
    'last_name'        => (string)($existingGuest['last_name'] ?? ''),
    'relation_label'   => (string)($existingGuest['relation_label'] ?? ''),
    'city'             => (string)($existingGuest['city'] ?? ''),
    'seat_count'       => (string)($existingGuest['seat_count'] ?? '1'),
    'children_count'   => (string)($existingGuest['children_count'] ?? '0'),
    'plus_one_allowed' => array_key_exists('plus_one_allowed', $existingGuest) ? (string)$existingGuest['plus_one_allowed'] : '',
    'phone'            => (string)($existingGuest['phone'] ?? ''),
    'email'            => (string)($existingGuest['email'] ?? ''),
    'address'          => (string)($existingGuest['address'] ?? ''),
    'accessibility'    => (string)($existingGuest['accessibility'] ?? ''),
    'special_notes'    => (string)($existingGuest['special_notes'] ?? ''),
    'diet_preference'  => (string)($existingGuest['diet_preference'] ?? ''),
    'allergies'        => (string)($existingGuest['allergies'] ?? ''),
    'pickup_required'  => array_key_exists('pickup_required', $existingGuest) ? (string)$existingGuest['pickup_required'] : '',
    'drop_required'    => array_key_exists('drop_required', $existingGuest) ? (string)$existingGuest['drop_required'] : '',
    'arrival_date'     => (string)($existingGuest['arrival_date'] ?? ''),
    'arrival_time'     => (string)($existingGuest['arrival_time'] ?? ''),
    'arrival_ref'      => (string)($existingGuest['arrival_ref'] ?? ''),
    'arrival_terminal' => (string)($existingGuest['arrival_terminal'] ?? ''),
    'departure_date'   => (string)($existingGuest['departure_date'] ?? ''),
    'departure_time'   => (string)($existingGuest['departure_time'] ?? ''),
    'departure_ref'    => (string)($existingGuest['departure_ref'] ?? ''),
    'transport_notes'  => (string)($existingGuest['transport_notes'] ?? ''),
    'checkin_date'     => (string)($existingGuest['checkin_date'] ?? ''),
    'checkout_date'    => (string)($existingGuest['checkout_date'] ?? ''),
    'room_type'        => (string)($existingGuest['room_type'] ?? ''),
    'bed_type'         => (string)($existingGuest['bed_type'] ?? ''),
    'id_document_note' => (string)($existingGuest['id_document_note'] ?? ''),
    'stay_notes'       => (string)($existingGuest['stay_notes'] ?? ''),
    'event_ids'        => $existingEventIds,
  ];
}

/* ---------- Save ---------- */
$errors = [];
$allowedEventIds = array_map('intval', array_column($events, 'id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$guestsTableReady) {
    $errors[] = 'Guests table is missing. Please create the guests table first.';
  }

  $postedGuestId = (int)($_POST['guest_id'] ?? 0);
  $editingThisGuest = $postedGuestId > 0;

  $userId = (int)($_SESSION['user_id'] ?? 0);

  $title             = trim((string)($_POST['title'] ?? ''));
  $invitedBy         = trim((string)($_POST['invited_by'] ?? ''));
  $firstName         = trim((string)($_POST['first_name'] ?? ''));
  $lastName          = trim((string)($_POST['last_name'] ?? ''));
  $relationLabel     = trim((string)($_POST['relation_label'] ?? ''));
  $city              = trim((string)($_POST['city'] ?? ''));
  $seatCount         = max(1, (int)($_POST['seat_count'] ?? 1));
  $childrenCount     = max(0, (int)($_POST['children_count'] ?? 0));
  $plusOneAllowed    = (int)($_POST['plus_one_allowed'] ?? 0) === 1 ? 1 : 0;

  $phone             = trim((string)($_POST['phone'] ?? ''));
  $email             = trim((string)($_POST['email'] ?? ''));
  $address           = trim((string)($_POST['address'] ?? ''));

  $accessibility     = trim((string)($_POST['accessibility'] ?? ''));
  $specialNotes      = trim((string)($_POST['special_notes'] ?? ''));
  $dietPreference    = trim((string)($_POST['diet_preference'] ?? ''));
  $allergies         = trim((string)($_POST['allergies'] ?? ''));

  $pickupRequired    = (int)($_POST['pickup_required'] ?? 0) === 1 ? 1 : 0;
  $dropRequired      = (int)($_POST['drop_required'] ?? 0) === 1 ? 1 : 0;
  $arrivalDate       = normalize_date_input($_POST['arrival_date'] ?? null);
  $arrivalTime       = normalize_text_input($_POST['arrival_time'] ?? null);
  $arrivalRef        = trim((string)($_POST['arrival_ref'] ?? ''));
  $arrivalTerminal   = trim((string)($_POST['arrival_terminal'] ?? ''));
  $departureDate     = normalize_date_input($_POST['departure_date'] ?? null);
  $departureTime     = normalize_text_input($_POST['departure_time'] ?? null);
  $departureRef      = trim((string)($_POST['departure_ref'] ?? ''));
  $transportNotes    = trim((string)($_POST['transport_notes'] ?? ''));

  $checkinDate       = normalize_date_input($_POST['checkin_date'] ?? null);
  $checkoutDate      = normalize_date_input($_POST['checkout_date'] ?? null);
  $roomType          = trim((string)($_POST['room_type'] ?? ''));
  $bedType           = trim((string)($_POST['bed_type'] ?? ''));
  $idDocumentNote    = trim((string)($_POST['id_document_note'] ?? ''));
  $stayNotes         = trim((string)($_POST['stay_notes'] ?? ''));

  $eventIds = array_map('intval', $_POST['event_ids'] ?? []);
  $eventIds = array_values(array_unique(array_intersect($eventIds, $allowedEventIds)));

  if ($editingThisGuest) {
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
    if (!$checkGuest->fetch()) {
      $errors[] = 'This guest record could not be found for editing.';
    }
  }

  if ($firstName === '') {
    $errors[] = 'Enter the guest first name.';
  }

  if (!in_array($invitedBy, ['bride', 'groom', 'both'], true)) {
    $errors[] = 'Select who invited this guest.';
  }

  if ($childrenCount > $seatCount) {
    $errors[] = 'Children count cannot be greater than total seats.';
  }

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($editingThisGuest) {
        $stmt = $pdo->prepare("
          UPDATE guests
          SET
            title = :title,
            invited_by = :invited_by,
            first_name = :first_name,
            last_name = :last_name,
            relation_label = :relation_label,
            city = :city,
            seat_count = :seat_count,
            children_count = :children_count,
            plus_one_allowed = :plus_one_allowed,
            phone = :phone,
            email = :email,
            address = :address,
            accessibility = :accessibility,
            special_notes = :special_notes,
            diet_preference = :diet_preference,
            allergies = :allergies,
            pickup_required = :pickup_required,
            drop_required = :drop_required,
            arrival_date = :arrival_date,
            arrival_time = :arrival_time,
            arrival_ref = :arrival_ref,
            arrival_terminal = :arrival_terminal,
            departure_date = :departure_date,
            departure_time = :departure_time,
            departure_ref = :departure_ref,
            transport_notes = :transport_notes,
            checkin_date = :checkin_date,
            checkout_date = :checkout_date,
            room_type = :room_type,
            bed_type = :bed_type,
            id_document_note = :id_document_note,
            stay_notes = :stay_notes,
            updated_at = NOW()
          WHERE id = :guest_id
            AND project_id = :project_id
          LIMIT 1
        ");

        $stmt->execute([
          ':title'             => $title !== '' ? $title : null,
          ':invited_by'        => $invitedBy,
          ':first_name'        => $firstName,
          ':last_name'         => $lastName !== '' ? $lastName : null,
          ':relation_label'    => $relationLabel !== '' ? $relationLabel : null,
          ':city'              => $city !== '' ? $city : null,
          ':seat_count'        => $seatCount,
          ':children_count'    => $childrenCount,
          ':plus_one_allowed'  => $plusOneAllowed,
          ':phone'             => $phone !== '' ? $phone : null,
          ':email'             => $email !== '' ? $email : null,
          ':address'           => $address !== '' ? $address : null,
          ':accessibility'     => $accessibility !== '' ? $accessibility : null,
          ':special_notes'     => $specialNotes !== '' ? $specialNotes : null,
          ':diet_preference'   => $dietPreference !== '' ? $dietPreference : null,
          ':allergies'         => $allergies !== '' ? $allergies : null,
          ':pickup_required'   => $pickupRequired,
          ':drop_required'     => $dropRequired,
          ':arrival_date'      => $arrivalDate,
          ':arrival_time'      => $arrivalTime,
          ':arrival_ref'       => $arrivalRef !== '' ? $arrivalRef : null,
          ':arrival_terminal'  => $arrivalTerminal !== '' ? $arrivalTerminal : null,
          ':departure_date'    => $departureDate,
          ':departure_time'    => $departureTime,
          ':departure_ref'     => $departureRef !== '' ? $departureRef : null,
          ':transport_notes'   => $transportNotes !== '' ? $transportNotes : null,
          ':checkin_date'      => $checkinDate,
          ':checkout_date'     => $checkoutDate,
          ':room_type'         => $roomType !== '' ? $roomType : null,
          ':bed_type'          => $bedType !== '' ? $bedType : null,
          ':id_document_note'  => $idDocumentNote !== '' ? $idDocumentNote : null,
          ':stay_notes'        => $stayNotes !== '' ? $stayNotes : null,
          ':guest_id'          => $postedGuestId,
          ':project_id'        => $projectId,
        ]);

        $savedGuestId = $postedGuestId;
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO guests (
            project_id, title, invited_by, first_name, last_name, relation_label, city,
            seat_count, children_count, plus_one_allowed,
            phone, email, address,
            accessibility, special_notes, diet_preference, allergies,
            pickup_required, drop_required,
            arrival_date, arrival_time, arrival_ref, arrival_terminal,
            departure_date, departure_time, departure_ref,
            transport_notes,
            checkin_date, checkout_date, room_type, bed_type,
            id_document_note, stay_notes,
            created_by, created_at, updated_at
          ) VALUES (
            :project_id, :title, :invited_by, :first_name, :last_name, :relation_label, :city,
            :seat_count, :children_count, :plus_one_allowed,
            :phone, :email, :address,
            :accessibility, :special_notes, :diet_preference, :allergies,
            :pickup_required, :drop_required,
            :arrival_date, :arrival_time, :arrival_ref, :arrival_terminal,
            :departure_date, :departure_time, :departure_ref,
            :transport_notes,
            :checkin_date, :checkout_date, :room_type, :bed_type,
            :id_document_note, :stay_notes,
            :created_by, NOW(), NOW()
          )
        ");

        $stmt->execute([
          ':project_id'        => $projectId,
          ':title'             => $title !== '' ? $title : null,
          ':invited_by'        => $invitedBy,
          ':first_name'        => $firstName,
          ':last_name'         => $lastName !== '' ? $lastName : null,
          ':relation_label'    => $relationLabel !== '' ? $relationLabel : null,
          ':city'              => $city !== '' ? $city : null,
          ':seat_count'        => $seatCount,
          ':children_count'    => $childrenCount,
          ':plus_one_allowed'  => $plusOneAllowed,
          ':phone'             => $phone !== '' ? $phone : null,
          ':email'             => $email !== '' ? $email : null,
          ':address'           => $address !== '' ? $address : null,
          ':accessibility'     => $accessibility !== '' ? $accessibility : null,
          ':special_notes'     => $specialNotes !== '' ? $specialNotes : null,
          ':diet_preference'   => $dietPreference !== '' ? $dietPreference : null,
          ':allergies'         => $allergies !== '' ? $allergies : null,
          ':pickup_required'   => $pickupRequired,
          ':drop_required'     => $dropRequired,
          ':arrival_date'      => $arrivalDate,
          ':arrival_time'      => $arrivalTime,
          ':arrival_ref'       => $arrivalRef !== '' ? $arrivalRef : null,
          ':arrival_terminal'  => $arrivalTerminal !== '' ? $arrivalTerminal : null,
          ':departure_date'    => $departureDate,
          ':departure_time'    => $departureTime,
          ':departure_ref'     => $departureRef !== '' ? $departureRef : null,
          ':transport_notes'   => $transportNotes !== '' ? $transportNotes : null,
          ':checkin_date'      => $checkinDate,
          ':checkout_date'     => $checkoutDate,
          ':room_type'         => $roomType !== '' ? $roomType : null,
          ':bed_type'          => $bedType !== '' ? $bedType : null,
          ':id_document_note'  => $idDocumentNote !== '' ? $idDocumentNote : null,
          ':stay_notes'        => $stayNotes !== '' ? $stayNotes : null,
          ':created_by'        => $userId > 0 ? $userId : null,
        ]);

        $savedGuestId = (int)$pdo->lastInsertId();
      }

      if ($guestInvitesTableReady) {
        $del = $pdo->prepare("DELETE FROM guest_event_invites WHERE guest_id = :guest_id");
        $del->execute([':guest_id' => $savedGuestId]);

        if ($eventIds) {
          $link = $pdo->prepare("
            INSERT INTO guest_event_invites (guest_id, event_id, created_at)
            VALUES (:guest_id, :event_id, NOW())
          ");

          foreach ($eventIds as $eventId) {
            $link->execute([
              ':guest_id' => $savedGuestId,
              ':event_id' => $eventId,
            ]);
          }
        }
      }

      $pdo->commit();

      if (function_exists('flash_set')) {
        flash_set('success', $editingThisGuest ? 'Guest updated successfully.' : 'Guest saved successfully.');
      }

      if (isset($_POST['save_add_another']) && !$editingThisGuest) {
        redirect('guests/create.php?project_id=' . $projectId);
      }

      if (isset($_POST['save_add_another']) && $editingThisGuest) {
        redirect('guests/create.php?project_id=' . $projectId . '&guest_id=' . $savedGuestId);
      }

      redirect('guests/index.php?project_id=' . $projectId . '&guest_id=' . $savedGuestId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

$pageModeTitle = $isEditMode ? 'Edit guest details' : 'Add guest details';
$pageModeSub = $isEditMode
  ? 'Update the existing guest record with the latest invite mapping, contact details, travel needs, accommodation notes, and food preferences.'
  : 'Add one guest record with invite mapping, contact details, travel needs, accommodation notes, and food preferences.';

$pagePrimaryAction = $isEditMode ? 'Save changes' : 'Save guest';
$pageModeLabel = $isEditMode ? 'Edit existing guest' : 'Manual add';

$pageTitle = $pageModeTitle . ' — ' . $projectTitle . ' — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.guest-create-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:16px;
}
.guest-create-title{
  margin:0;
  font-size:24px;
  line-height:1.2;
  font-weight:800;
  color:#151515;
}
.guest-create-sub{
  margin:8px 0 0 0;
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
  max-width:700px;
}
.guest-create-actions{
  display:flex;
  gap:10px;
  flex-wrap:nowrap;
  align-items:center;
  justify-content:flex-end;
  white-space:nowrap;
  flex:0 0 auto;
}
.guest-create-actions .btn{
  white-space:nowrap;
}
@media (max-width:980px){
  .guest-create-head{
    flex-direction:column;
    align-items:flex-start;
  }
  .guest-create-actions{
    width:100%;
    flex-wrap:wrap;
    justify-content:flex-start;
  }
}
.guest-layout{
  display:grid;
  grid-template-columns:minmax(0,1.55fr) minmax(320px,.95fr);
  gap:14px;
  align-items:start;
}
@media (max-width:1120px){
  .guest-layout{
    grid-template-columns:1fr;
  }
}
.guest-col{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.form-card{
  padding:18px;
}
.form-card-title{
  margin:0;
  font-size:18px;
  font-weight:800;
  color:#1d1d1d;
}
.form-card-sub{
  margin:6px 0 0 0;
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.form-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:14px;
  margin-top:16px;
}
.form-grid-3{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:14px;
  margin-top:16px;
}
@media (max-width:760px){
  .form-grid,
  .form-grid-3{
    grid-template-columns:1fr;
  }
}
.field{
  display:flex;
  flex-direction:column;
  gap:7px;
}
.field label{
  font-size:12px;
  font-weight:700;
  color:#555;
}
.field input,
.field select,
.field textarea{
  width:100%;
  min-height:44px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,0.12);
  background:#fff;
  padding:11px 14px;
  font:inherit;
  color:#1f1f1f;
  outline:none;
  box-sizing:border-box;
}
.field textarea{
  min-height:108px;
  resize:vertical;
}
.field input:focus,
.field select:focus,
.field textarea:focus{
  border-color:rgba(0,0,0,0.28);
  box-shadow:0 0 0 3px rgba(0,0,0,0.04);
}
.invite-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:10px 14px;
  margin-top:16px;
}
@media (max-width:760px){
  .invite-grid{
    grid-template-columns:1fr;
  }
}
.invite-pill{
  display:flex;
  align-items:flex-start;
  gap:10px;
  border:1px solid rgba(0,0,0,0.08);
  border-radius:16px;
  padding:12px 12px;
  background:#fff;
}
.invite-pill input{
  margin-top:2px;
}
.invite-pill-title{
  font-size:14px;
  font-weight:700;
  color:#1f1f1f;
  line-height:1.35;
}
.invite-pill-meta{
  margin-top:4px;
  font-size:12px;
  color:var(--muted);
  line-height:1.45;
}
.empty-events{
  margin-top:16px;
  padding:14px 16px;
  border-radius:16px;
  border:1px dashed rgba(0,0,0,0.12);
  background:rgba(0,0,0,0.02);
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}
.form-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin:18px 0 2px;
}
.info-note{
  margin-top:16px;
  padding:14px 15px;
  border-radius:16px;
  background:rgba(0,0,0,0.03);
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}
.side-summary{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:16px;
}
.side-summary-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:10px;
  align-items:start;
  font-size:13px;
}
.side-summary-row .k{
  color:#444;
}
.side-summary-row .v{
  color:#1c1c1c;
  font-weight:700;
}
.page-actions-bottom{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:16px;
  flex-wrap:wrap;
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

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo esc(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo esc(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo esc(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'guests';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <?php if ($errors): ?>
            <div class="card proj-card" style="margin-bottom:14px; border:1px solid rgba(185,28,28,.15); background:#fff7f7;">
              <div class="proj-card-title" style="font-size:16px; color:#991b1b;">Please fix these fields</div>
              <ul style="margin:10px 0 0 18px; color:#991b1b; font-size:13px; line-height:1.6;">
                <?php foreach ($errors as $error): ?>
                  <li><?php echo esc($error); ?></li>
                <?php endforeach; ?>
              </ul>
              <div style="margin-top:10px; color:#7f1d1d; font-size:12px;">
                Connected DB: <?php echo esc($currentDb); ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="guest-create-head">
            <div>
              <h1 class="guest-create-title"><?php echo esc($pageModeTitle); ?></h1>
              <p class="guest-create-sub"><?php echo esc($pageModeSub); ?></p>
            </div>

            <div class="guest-create-actions">
              <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId . ($isEditMode ? '&guest_id=' . $guestId : ''))); ?>">Cancel</a>
              <button class="btn" type="submit" form="guest-create-form" name="save_add_another" value="1">Save &amp; add another</button>
              <button class="btn btn-primary" type="submit" form="guest-create-form" name="save_guest" value="1"><?php echo esc($pagePrimaryAction); ?></button>
            </div>
          </div>

          <form id="guest-create-form" method="post" action="" autocomplete="off">
            <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">
            <?php if ($isEditMode): ?>
              <input type="hidden" name="guest_id" value="<?php echo esc((string)$guestId); ?>">
            <?php endif; ?>

            <div class="guest-layout">
              <div class="guest-col">
                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Guest details</h2>
                  <p class="form-card-sub">Start with the guest’s identity, side, and group-level details.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="title">Title</label>
                      <select id="title" name="title">
                        <option value="">Select title</option>
                        <option value="Mr" <?php echo selected_attr(request_value('title', $defaults), 'Mr'); ?>>Mr</option>
                        <option value="Ms" <?php echo selected_attr(request_value('title', $defaults), 'Ms'); ?>>Ms</option>
                        <option value="Mrs" <?php echo selected_attr(request_value('title', $defaults), 'Mrs'); ?>>Mrs</option>
                        <option value="Dr" <?php echo selected_attr(request_value('title', $defaults), 'Dr'); ?>>Dr</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="invited_by">Invited by</label>
                      <select id="invited_by" name="invited_by">
                        <option value="">Select side</option>
                        <option value="bride" <?php echo selected_attr(request_value('invited_by', $defaults), 'bride'); ?>>Bride’s side</option>
                        <option value="groom" <?php echo selected_attr(request_value('invited_by', $defaults), 'groom'); ?>>Groom’s side</option>
                        <option value="both" <?php echo selected_attr(request_value('invited_by', $defaults), 'both'); ?>>Both families</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="first_name">First name</label>
                      <input id="first_name" name="first_name" type="text" placeholder="Enter first name" value="<?php echo esc(request_value('first_name', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="last_name">Last name</label>
                      <input id="last_name" name="last_name" type="text" placeholder="Enter last name" value="<?php echo esc(request_value('last_name', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="relation_label">Relation / group</label>
                      <input id="relation_label" name="relation_label" type="text" placeholder="e.g. Cousin, School friends, Sharma family" value="<?php echo esc(request_value('relation_label', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="city">City</label>
                      <input id="city" name="city" type="text" placeholder="e.g. Delhi" value="<?php echo esc(request_value('city', $defaults)); ?>">
                    </div>
                  </div>

                  <div class="form-grid-3">
                    <div class="field">
                      <label for="seat_count">Number of seats</label>
                      <input id="seat_count" name="seat_count" type="number" min="1" placeholder="1" value="<?php echo esc(request_value('seat_count', $defaults, '1')); ?>">
                    </div>

                    <div class="field">
                      <label for="children_count">Number of children</label>
                      <input id="children_count" name="children_count" type="number" min="0" placeholder="0" value="<?php echo esc(request_value('children_count', $defaults, '0')); ?>">
                    </div>

                    <div class="field">
                      <label for="plus_one_allowed">Plus-one</label>
                      <select id="plus_one_allowed" name="plus_one_allowed">
                        <option value="">Select</option>
                        <option value="0" <?php echo selected_attr(request_value('plus_one_allowed', $defaults), '0'); ?>>Not included</option>
                        <option value="1" <?php echo selected_attr(request_value('plus_one_allowed', $defaults), '1'); ?>>Allowed</option>
                      </select>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Invited events</h2>
                  <p class="form-card-sub">Choose which project events this guest should receive in their invite flow.</p>

                  <?php if ($events): ?>
                    <div class="invite-grid">
                      <?php
                        $selectedEvents = request_array_value('event_ids', $defaults);

                        foreach ($events as $event):
                          $eventId = (string)($event['id'] ?? '');
                          $eventName = trim((string)($event['name'] ?? 'Untitled event'));
                          $eventSide = trim((string)($event['hosting_side'] ?? ''));
                          $eventVenue = trim((string)($event['venue'] ?? ''));
                          $eventDate = trim((string)($event['starts_at'] ?? ''));
                          $eventDateLabel = $eventDate !== '' ? date('M j, Y • g:i A', strtotime($eventDate)) : 'Date TBD';
                          $checked = in_array($eventId, $selectedEvents, true);
                      ?>
                        <label class="invite-pill">
                          <input type="checkbox" name="event_ids[]" value="<?php echo esc($eventId); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                          <span>
                            <div class="invite-pill-title"><?php echo esc($eventName); ?></div>
                            <div class="invite-pill-meta">
                              <?php echo esc($eventDateLabel); ?>
                              <?php if ($eventSide !== ''): ?> • <?php echo esc(ucfirst($eventSide)); ?> side<?php endif; ?>
                              <?php if ($eventVenue !== ''): ?> • <?php echo esc($eventVenue); ?><?php endif; ?>
                            </div>
                          </span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="empty-events">
                      No events have been added to this project yet. Add events first, then come back to map this guest to the correct functions.
                    </div>
                  <?php endif; ?>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Travel information</h2>
                  <p class="form-card-sub">Capture arrival and departure needs for pickup and drop coordination.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="pickup_required">Pickup needed</label>
                      <select id="pickup_required" name="pickup_required">
                        <option value="">Select</option>
                        <option value="1" <?php echo selected_attr(request_value('pickup_required', $defaults), '1'); ?>>Yes</option>
                        <option value="0" <?php echo selected_attr(request_value('pickup_required', $defaults), '0'); ?>>No</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="drop_required">Drop needed</label>
                      <select id="drop_required" name="drop_required">
                        <option value="">Select</option>
                        <option value="1" <?php echo selected_attr(request_value('drop_required', $defaults), '1'); ?>>Yes</option>
                        <option value="0" <?php echo selected_attr(request_value('drop_required', $defaults), '0'); ?>>No</option>
                      </select>
                    </div>
                  </div>

                  <div class="form-divider"></div>

                  <div class="form-grid">
                    <div class="field">
                      <label for="arrival_date">Arrival date</label>
                      <input id="arrival_date" name="arrival_date" type="date" value="<?php echo esc(request_value('arrival_date', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_time">Arrival time</label>
                      <input id="arrival_time" name="arrival_time" type="time" value="<?php echo esc(request_value('arrival_time', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_ref">Arrival flight / train no.</label>
                      <input id="arrival_ref" name="arrival_ref" type="text" placeholder="e.g. AI-1234" value="<?php echo esc(request_value('arrival_ref', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_terminal">Arrival terminal / platform</label>
                      <input id="arrival_terminal" name="arrival_terminal" type="text" placeholder="e.g. T3 / Platform 4" value="<?php echo esc(request_value('arrival_terminal', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_date">Departure date</label>
                      <input id="departure_date" name="departure_date" type="date" value="<?php echo esc(request_value('departure_date', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_time">Departure time</label>
                      <input id="departure_time" name="departure_time" type="time" value="<?php echo esc(request_value('departure_time', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_ref">Departure flight / train no.</label>
                      <input id="departure_ref" name="departure_ref" type="text" placeholder="e.g. UK-211" value="<?php echo esc(request_value('departure_ref', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="transport_notes">Pickup / drop remarks</label>
                      <input id="transport_notes" name="transport_notes" type="text" placeholder="e.g. Extra luggage, senior support needed" value="<?php echo esc(request_value('transport_notes', $defaults)); ?>">
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Accommodation information</h2>
                  <p class="form-card-sub">Store room preferences and stay notes for the hospitality team.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="checkin_date">Check-in</label>
                      <input id="checkin_date" name="checkin_date" type="date" value="<?php echo esc(request_value('checkin_date', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="checkout_date">Check-out</label>
                      <input id="checkout_date" name="checkout_date" type="date" value="<?php echo esc(request_value('checkout_date', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="room_type">Room type</label>
                      <select id="room_type" name="room_type">
                        <option value="">Select room type</option>
                        <option value="suite" <?php echo selected_attr(request_value('room_type', $defaults), 'suite'); ?>>Suite</option>
                        <option value="deluxe" <?php echo selected_attr(request_value('room_type', $defaults), 'deluxe'); ?>>Deluxe</option>
                        <option value="standard" <?php echo selected_attr(request_value('room_type', $defaults), 'standard'); ?>>Standard</option>
                        <option value="family" <?php echo selected_attr(request_value('room_type', $defaults), 'family'); ?>>Family room</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="bed_type">Bed type</label>
                      <select id="bed_type" name="bed_type">
                        <option value="">Select bed type</option>
                        <option value="king" <?php echo selected_attr(request_value('bed_type', $defaults), 'king'); ?>>King</option>
                        <option value="queen" <?php echo selected_attr(request_value('bed_type', $defaults), 'queen'); ?>>Queen</option>
                        <option value="twin" <?php echo selected_attr(request_value('bed_type', $defaults), 'twin'); ?>>Twin</option>
                        <option value="extra_bed" <?php echo selected_attr(request_value('bed_type', $defaults), 'extra_bed'); ?>>Extra bed required</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="id_document_note">Identification document</label>
                      <input id="id_document_note" name="id_document_note" type="text" placeholder="e.g. Aadhaar / passport to be collected" value="<?php echo esc(request_value('id_document_note', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="stay_notes">Accommodation remarks</label>
                      <input id="stay_notes" name="stay_notes" type="text" placeholder="e.g. Near lift, connected room, quiet floor" value="<?php echo esc(request_value('stay_notes', $defaults)); ?>">
                    </div>
                  </div>
                </section>
              </div>

              <div class="guest-col">
                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Contact information</h2>
                  <p class="form-card-sub">Use the best details available for invites and follow-ups.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="phone">Phone number</label>
                      <input id="phone" name="phone" type="text" placeholder="Enter guest’s phone number" value="<?php echo esc(request_value('phone', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="email">Email</label>
                      <input id="email" name="email" type="email" placeholder="Enter guest’s email" value="<?php echo esc(request_value('email', $defaults)); ?>">
                    </div>

                    <div class="field">
                      <label for="address">Address</label>
                      <textarea id="address" name="address" placeholder="Address or locality, if relevant for logistics"><?php echo esc(request_value('address', $defaults)); ?></textarea>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Important notes</h2>
                  <p class="form-card-sub">Track accessibility, assistance, and context the team should not miss.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="accessibility">Accessibility / assistance needed</label>
                      <select id="accessibility" name="accessibility">
                        <option value="">Select support need</option>
                        <option value="none" <?php echo selected_attr(request_value('accessibility', $defaults), 'none'); ?>>None</option>
                        <option value="wheelchair" <?php echo selected_attr(request_value('accessibility', $defaults), 'wheelchair'); ?>>Wheelchair support</option>
                        <option value="elder_care" <?php echo selected_attr(request_value('accessibility', $defaults), 'elder_care'); ?>>Elder care support</option>
                        <option value="toddler_care" <?php echo selected_attr(request_value('accessibility', $defaults), 'toddler_care'); ?>>Toddler care support</option>
                        <option value="medical" <?php echo selected_attr(request_value('accessibility', $defaults), 'medical'); ?>>Medical note</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="special_notes">Special notes</label>
                      <textarea id="special_notes" name="special_notes" placeholder="Add internal notes for RSVP, travel, or hospitality teams"><?php echo esc(request_value('special_notes', $defaults)); ?></textarea>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Food preferences</h2>
                  <p class="form-card-sub">Keep dietary choices visible before catering numbers are finalized.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="diet_preference">Diet preference</label>
                      <select id="diet_preference" name="diet_preference">
                        <option value="">Select diet preference</option>
                        <option value="veg" <?php echo selected_attr(request_value('diet_preference', $defaults), 'veg'); ?>>Veg</option>
                        <option value="non_veg" <?php echo selected_attr(request_value('diet_preference', $defaults), 'non_veg'); ?>>Non-veg</option>
                        <option value="vegan" <?php echo selected_attr(request_value('diet_preference', $defaults), 'vegan'); ?>>Vegan</option>
                        <option value="jain" <?php echo selected_attr(request_value('diet_preference', $defaults), 'jain'); ?>>Jain</option>
                        <option value="eggs" <?php echo selected_attr(request_value('diet_preference', $defaults), 'eggs'); ?>>Eggs okay</option>
                        <option value="none" <?php echo selected_attr(request_value('diet_preference', $defaults), 'none'); ?>>No preference</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="allergies">Allergies</label>
                      <input id="allergies" name="allergies" type="text" placeholder="e.g. Peanuts, lactose, gluten" value="<?php echo esc(request_value('allergies', $defaults)); ?>">
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Quick summary</h2>
                  <p class="form-card-sub">This page is for one guest record at a time.</p>

                  <div class="side-summary">
                    <div class="side-summary-row">
                      <div class="k">Project</div>
                      <div class="v"><?php echo esc($projectTitle); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Project date</div>
                      <div class="v"><?php echo esc($topDateLabel); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Available events</div>
                      <div class="v"><?php echo esc((string)count($events)); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Mode</div>
                      <div class="v"><?php echo esc($pageModeLabel); ?></div>
                    </div>
                  </div>

                  <div class="info-note">
                    Keep this page focused on clean internal entry. Bulk uploads, dedupe, and invite sending should stay on the guest setup screen.
                  </div>

                  <div class="page-actions-bottom">
                    <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId . ($isEditMode ? '&guest_id=' . $guestId : ''))); ?>">Back</a>
                    <button class="btn" type="submit" name="save_add_another" value="1">Save &amp; add another</button>
                    <button class="btn btn-primary" type="submit" name="save_guest" value="1"><?php echo esc($pagePrimaryAction); ?></button>
                  </div>
                </section>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>