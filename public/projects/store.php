<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('projects/create.php');
}

$companyId = current_company_id();
$userId    = current_user_id();

$partner1 = trim($_POST['partner1_name'] ?? '');
$partner2 = trim($_POST['partner2_name'] ?? '');
if ($partner1 === '' || $partner2 === '') {
  flash_set('error', 'Partner names are required.');
  redirect('projects/create.php');
}

$title = trim($_POST['title'] ?? '');
if ($title === '') $title = $partner1 . ' weds ' . $partner2;

$payload = [
  'company_id'      => $companyId,
  'title'           => $title,
  'partner1_name'   => $partner1,
  'partner2_name'   => $partner2,
  'phone1'          => trim($_POST['phone1'] ?? ''),
  'phone2'          => trim($_POST['phone2'] ?? ''),
  'email1'          => trim($_POST['email1'] ?? ''),
  'email2'          => trim($_POST['email2'] ?? ''),
  'event_type'      => trim($_POST['event_type'] ?? ''),
  'guest_count_est' => ($_POST['guest_count_est'] ?? '') !== '' ? (int)$_POST['guest_count_est'] : null,
  'budget_from'     => ($_POST['budget_from'] ?? '') !== '' ? (float)$_POST['budget_from'] : null,
  'budget_to'       => ($_POST['budget_to'] ?? '') !== '' ? (float)$_POST['budget_to'] : null,
  'status'          => 'active',
  'priority'        => 'medium',
  'created_by'      => $userId,
];

$teamLeadMemberId = (int)($_POST['team_lead_member_id'] ?? 0);
if ($teamLeadMemberId <= 0) {
  flash_set('error', 'Please select a Team lead.');
  redirect('projects/create.php');
}

$eventNames = $_POST['event_name'] ?? [];
$eventDates = $_POST['event_date'] ?? [];
$eventVenues = $_POST['event_venue'] ?? [];
$eventSides = $_POST['event_hosting_side'] ?? [];

if (!is_array($eventNames) || count($eventNames) < 1) {
  flash_set('error', 'Add at least one event.');
  redirect('projects/create.php');
}

$rsvpIds = array_map('intval', $_POST['rsvp_member_ids'] ?? []);
$hospitalityIds = array_map('intval', $_POST['hospitality_member_ids'] ?? []);
$transportIds = array_map('intval', $_POST['transport_member_ids'] ?? []);

try {
  // 1) Insert project (keep this reliable; ancillary inserts can fail without blocking project creation)
  $sql = "
    INSERT INTO projects
      (company_id, title, partner1_name, partner2_name, phone1, phone2, email1, email2,
       event_type, guest_count_est, budget_from, budget_to, status, priority, created_by, created_at, updated_at)
    VALUES
      (:company_id, :title, :partner1_name, :partner2_name, :phone1, :phone2, :email1, :email2,
       :event_type, :guest_count_est, :budget_from, :budget_to, :status, :priority, :created_by, NOW(), NOW())
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':company_id'      => $payload['company_id'],
    ':title'           => $payload['title'],
    ':partner1_name'   => $payload['partner1_name'],
    ':partner2_name'   => $payload['partner2_name'],
    ':phone1'          => $payload['phone1'],
    ':phone2'          => $payload['phone2'],
    ':email1'          => $payload['email1'],
    ':email2'          => $payload['email2'],
    ':event_type'      => $payload['event_type'],
    ':guest_count_est' => $payload['guest_count_est'],
    ':budget_from'     => $payload['budget_from'],
    ':budget_to'       => $payload['budget_to'],
    ':status'          => $payload['status'],
    ':priority'        => $payload['priority'],
    ':created_by'      => $payload['created_by'],
  ]);

  $projectId = (int)$pdo->lastInsertId();

  // 2) Create contract draft row (optional)
  try {
    $cstmt = $pdo->prepare("
      INSERT INTO contracts (project_id, status, version_label, prepared_by, created_at, updated_at)
      VALUES (:pid, 'draft', 'v0.1', :prepared_by, NOW(), NOW())
    ");
    $cstmt->execute([':pid' => $projectId, ':prepared_by' => $userId]);
  } catch (Throwable $e) {
    // Don't block project creation if contract schema isn't ready
    flash_set('warning', 'Project saved, but contract draft could not be created: ' . $e->getMessage());
  }

  // 3) Insert events (optional)
  try {
    $estmt = $pdo->prepare("
      INSERT INTO project_events
        (project_id, name, starts_at, venue, hosting_side, created_at, updated_at)
      VALUES
        (:pid, :name, :starts_at, :venue, :hosting_side, NOW(), NOW())
    ");

    for ($i=0; $i<count($eventNames); $i++) {
      $name = trim((string)($eventNames[$i] ?? ''));
      $date = trim((string)($eventDates[$i] ?? ''));
      if ($name === '' || $date === '') continue;

      $startsAt = parse_date_ymd($date);
      if (!$startsAt) continue;

      $venue = trim((string)($eventVenues[$i] ?? ''));
      $side  = trim((string)($eventSides[$i] ?? ''));

      $estmt->execute([
        ':pid' => $projectId,
        ':name' => $name,
        ':starts_at' => $startsAt,
        ':venue' => $venue,
        ':hosting_side' => $side,
      ]);
    }
  } catch (Throwable $e) {
    flash_set('warning', 'Project saved, but events could not be saved: ' . $e->getMessage());
  }

  // 4) Insert staffing (optional)
  try {
    // helper to insert project member strictly from company_members
    $memberLookup = $pdo->prepare("
      SELECT id, full_name, email, default_department
      FROM company_members
      WHERE id = :mid AND company_id = :cid AND status = 'active'
      LIMIT 1
    ");

    $pminsert = $pdo->prepare("
      INSERT INTO project_members
        (project_id, role, department, company_member_id, display_name, email, created_at)
      VALUES
        (:pid, :role, :dept, :cmid, :name, :email, NOW())
    ");

    $insertMember = function(int $companyMemberId, string $role) use ($companyId, $projectId, $memberLookup, $pminsert) {
      $memberLookup->execute([':mid' => $companyMemberId, ':cid' => $companyId]);
      $m = $memberLookup->fetch();
      if (!$m) return;

      $pminsert->execute([
        ':pid'  => $projectId,
        ':role' => $role,
        //':dept' => $role,
        ':dept' => (string)($m['default_department'] ?? 'coordination'),
        ':cmid' => (int)$m['id'],
        ':name' => (string)$m['full_name'],
        ':email'=> (string)$m['email'],
      ]);
    };

    $insertMember($teamLeadMemberId, 'team_lead');
    foreach (array_unique($rsvpIds) as $id) $insertMember($id, 'rsvp');
    foreach (array_unique($hospitalityIds) as $id) $insertMember($id, 'hospitality');
    foreach (array_unique($transportIds) as $id) $insertMember($id, 'transport');
  } catch (Throwable $e) {
    flash_set('warning', 'Project saved, but staffing could not be saved: ' . $e->getMessage());
  }

  flash_set('success', 'Project created successfully.');
  redirect('projects/show.php?id=' . $projectId);

} catch (Throwable $e) {
  flash_set('error', 'Save failed: ' . $e->getMessage());
  redirect('projects/create.php');
}