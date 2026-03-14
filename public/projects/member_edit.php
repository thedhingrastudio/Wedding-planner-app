<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$memberId  = (int)($_GET['mid'] ?? $_POST['mid'] ?? 0);

if ($projectId <= 0) redirect('projects/index.php');
if ($memberId <= 0) redirect('projects/members.php?id=' . $projectId);

$companyId = current_company_id();

function h0($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function role_label(string $role): string {
  $map = [
    'team_lead'    => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp'         => 'RSVP',
    'hospitality'  => 'Hospitality',
    'transport'    => 'Transport',
    'vendor'       => 'Vendor',
    'driver'       => 'Driver',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
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

function column_exists_local(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = :table
        AND column_name = :column
    ");
    $st->execute([
      ':table' => $table,
      ':column' => $column,
    ]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$project = null;
try {
  $pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid LIMIT 1");
  $pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
  $project = $pstmt->fetch() ?: null;
} catch (Throwable $e) {
  $project = null;
}
if (!$project) redirect('projects/index.php');

$pageTitle = 'Edit member details — ' . (string)($project['title'] ?? 'Project') . ' — Vidhaan';
require_once $root . '/includes/header.php';

$first = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
} catch (Throwable $e) {
  $first = null;
}

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$projectDateLabel = 'Date TBD';
$createdAt = (string)($project['created_at'] ?? '');
if ($createdAt !== '') {
  $projectDateLabel = date('F j, Y', strtotime($createdAt));
}

$roleOptions = [
  'team_lead'    => role_label('team_lead'),
  'coordination' => role_label('coordination'),
  'rsvp'         => role_label('rsvp'),
  'hospitality'  => role_label('hospitality'),
  'transport'    => role_label('transport'),
  'vendor'       => role_label('vendor'),
  'driver'       => role_label('driver'),
];

$driverColumnsReady =
  column_exists_local($pdo, 'company_members', 'driver_car_model') &&
  column_exists_local($pdo, 'company_members', 'driver_car_type') &&
  column_exists_local($pdo, 'company_members', 'driver_plate_number') &&
  column_exists_local($pdo, 'company_members', 'driver_seating_capacity');

$member = null;
$memberRoles = [];

try {
  $ms = $pdo->prepare("
    SELECT cm.*
    FROM company_members cm
    JOIN project_members pm
      ON pm.company_member_id = cm.id
     AND pm.project_id = :pid
    WHERE cm.company_id = :cid
      AND cm.id = :mid
    LIMIT 1
  ");
  $ms->execute([
    ':pid' => $projectId,
    ':cid' => $companyId,
    ':mid' => $memberId,
  ]);
  $member = $ms->fetch() ?: null;

  if ($member) {
    $rs = $pdo->prepare("
      SELECT DISTINCT pm.role
      FROM project_members pm
      WHERE pm.project_id = :pid
        AND pm.company_member_id = :mid
      ORDER BY pm.role ASC
    ");
    $rs->execute([
      ':pid' => $projectId,
      ':mid' => $memberId,
    ]);
    $memberRoles = array_map(
      static fn($r) => (string)$r['role'],
      $rs->fetchAll() ?: []
    );
  }
} catch (Throwable $e) {
  $member = null;
}

if (!$member) {
  redirect('projects/members.php?id=' . $projectId);
}

$errors = [];

$selectedRoles = $_POST['roles'] ?? $memberRoles;
if (!is_array($selectedRoles)) $selectedRoles = [];
$selectedRoles = array_values(array_unique(array_filter(array_map('strval', $selectedRoles))));

$memberName = trim((string)($member['full_name'] ?? 'Member'));
$phoneValue = trim((string)($_POST['phone'] ?? ($member['phone'] ?? '')));
$emailValue = trim((string)($_POST['email'] ?? ($member['email'] ?? '')));

$carModelValue = trim((string)($_POST['driver_car_model'] ?? ($member['driver_car_model'] ?? '')));
$carTypeValue = trim((string)($_POST['driver_car_type'] ?? ($member['driver_car_type'] ?? '')));
$plateValue = trim((string)($_POST['driver_plate_number'] ?? ($member['driver_plate_number'] ?? '')));
$seatingValue = trim((string)($_POST['driver_seating_capacity'] ?? (($member['driver_seating_capacity'] ?? '') !== '' ? (string)$member['driver_seating_capacity'] : '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $cleanRoles = [];
  foreach ($selectedRoles as $role) {
    $role = trim((string)$role);
    if ($role === '') continue;
    if (!array_key_exists($role, $roleOptions)) continue;
    $cleanRoles[] = $role;
  }
  $cleanRoles = array_values(array_unique($cleanRoles));

  if (!$cleanRoles) {
    $errors[] = 'Please assign at least one department.';
  }

  if ($emailValue === '') {
    $errors[] = 'Email is required.';
  } elseif (!filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Enter a valid email address.';
  }

  $hasDriverRole = in_array('driver', $cleanRoles, true);

  if ($hasDriverRole && $driverColumnsReady) {
    if ($seatingValue !== '' && (!ctype_digit($seatingValue) || (int)$seatingValue < 1)) {
      $errors[] = 'Max seating capacity must be a valid number.';
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($driverColumnsReady) {
        $upMember = $pdo->prepare("
          UPDATE company_members
          SET
            phone = :phone,
            email = :email,
            driver_car_model = :driver_car_model,
            driver_car_type = :driver_car_type,
            driver_plate_number = :driver_plate_number,
            driver_seating_capacity = :driver_seating_capacity
          WHERE id = :mid
            AND company_id = :cid
          LIMIT 1
        ");

        $upMember->execute([
          ':phone' => $phoneValue !== '' ? $phoneValue : null,
          ':email' => $emailValue,
          ':driver_car_model' => $hasDriverRole && $carModelValue !== '' ? $carModelValue : null,
          ':driver_car_type' => $hasDriverRole && $carTypeValue !== '' ? $carTypeValue : null,
          ':driver_plate_number' => $hasDriverRole && $plateValue !== '' ? $plateValue : null,
          ':driver_seating_capacity' => $hasDriverRole && $seatingValue !== '' ? (int)$seatingValue : null,
          ':mid' => $memberId,
          ':cid' => $companyId,
        ]);
      } else {
        $upMember = $pdo->prepare("
          UPDATE company_members
          SET
            phone = :phone,
            email = :email
          WHERE id = :mid
            AND company_id = :cid
          LIMIT 1
        ");

        $upMember->execute([
          ':phone' => $phoneValue !== '' ? $phoneValue : null,
          ':email' => $emailValue,
          ':mid' => $memberId,
          ':cid' => $companyId,
        ]);
      }

      $delRoles = $pdo->prepare("
        DELETE FROM project_members
        WHERE project_id = :pid
          AND company_member_id = :mid
      ");
      $delRoles->execute([
        ':pid' => $projectId,
        ':mid' => $memberId,
      ]);

      $insRole = $pdo->prepare("
        INSERT INTO project_members
          (project_id, user_id, email, role, created_at, display_name, responsibility_label, department, company_member_id)
        VALUES
          (:pid, :uid, :email, :role, NOW(), :display_name, NULL, :department, :cmid)
      ");

      foreach ($cleanRoles as $role) {
        $insRole->execute([
          ':pid' => $projectId,
          ':uid' => $member['user_id'] ?? null,
          ':email' => $emailValue !== '' ? $emailValue : null,
          ':role' => $role,
          ':display_name' => $memberName !== '' ? $memberName : null,
          ':department' => $role,
          ':cmid' => $memberId,
        ]);
      }

      $pdo->commit();

      if (function_exists('flash_set')) {
        flash_set('success', 'Member details updated successfully.');
      }

      redirect('projects/member.php?id=' . $projectId . '&mid=' . $memberId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Could not update member details. ' . $e->getMessage();
    }
  }
}
?>

<style>
.member-edit-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
}
.member-edit-title{
  display:flex;
  align-items:flex-start;
  gap:14px;
}
.member-edit-ico{
  font-size:42px;
  line-height:1;
}
.member-edit-h1{
  margin:0;
  font-size:24px;
  line-height:1.15;
  font-weight:800;
  color:#1f1f22;
}
.member-edit-sub{
  margin:6px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.5;
}

.member-edit-grid{
  display:grid;
  grid-template-columns:minmax(0,1.85fr) 360px;
  gap:16px;
  align-items:start;
}
@media (max-width:1100px){
  .member-edit-grid{
    grid-template-columns:1fr;
  }
}

.member-edit-card{
  padding:18px;
  border-radius:24px;
}
.member-edit-card-title{
  margin:0;
  font-size:17px;
  font-weight:800;
  color:#222;
}
.member-edit-card-sub{
  margin:4px 0 0 0;
  color:#75757a;
  font-size:12px;
  line-height:1.45;
}

.member-edit-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin:14px 0 14px;
}

.member-edit-form-row{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
}
@media (max-width:760px){
  .member-edit-form-row{
    grid-template-columns:1fr;
  }
}

.member-edit-field{
  display:flex;
  flex-direction:column;
  gap:6px;
  margin-bottom:12px;
}
.member-edit-field label{
  font-size:12px;
  font-weight:700;
  color:#5b5b61;
}
.member-edit-field input,
.member-edit-field select{
  width:100%;
  min-height:42px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,0.08);
  background:#f7f7f8;
  padding:10px 12px;
  box-sizing:border-box;
  font:inherit;
  color:#1f1f22;
  outline:none;
}
.member-edit-field input:focus,
.member-edit-field select:focus{
  background:#fff;
  border-color:rgba(0,0,0,0.16);
}

.member-edit-readonly{
  background:#f1f1f2;
  color:#6a6a70;
}

.member-role-row{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  gap:10px;
  align-items:end;
}
@media (max-width:760px){
  .member-role-row{
    grid-template-columns:1fr;
  }
}

.member-chip-box{
  min-height:220px;
  border:1px dashed rgba(0,0,0,0.10);
  border-radius:22px;
  padding:14px;
  background:#fff;
}
.member-chips{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.member-chip{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  background:#f3eef3;
  color:#4a3a4a;
  font-size:13px;
  font-weight:600;
}
.member-chip button{
  border:none;
  background:transparent;
  font-size:16px;
  line-height:1;
  cursor:pointer;
  color:#6a5c6a;
  padding:0;
}
.member-chip-empty{
  color:#9a9aa1;
  font-size:13px;
  line-height:1.5;
  min-height:120px;
  display:grid;
  place-items:center;
  text-align:center;
}

.member-driver-section{
  margin-top:16px;
  padding-top:16px;
  border-top:1px solid rgba(0,0,0,0.08);
}
.member-driver-title{
  font-size:16px;
  font-weight:800;
  color:#1f1f22;
  margin-bottom:10px;
}
.member-driver-sub{
  margin:-4px 0 12px 0;
  color:#75757a;
  font-size:12px;
}

.member-edit-actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:14px;
  flex-wrap:wrap;
}

.member-edit-alert{
  margin-bottom:14px;
  border:1px solid rgba(185,28,28,.16);
  background:#fff7f7;
  color:#991b1b;
  border-radius:18px;
  padding:14px 16px;
}
.member-edit-alert ul{
  margin:8px 0 0 18px;
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
        Admin: <?php echo h0($adminName); ?>
        <a class="logout" href="<?php echo h0(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo h0((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo h0($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h0((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h0(base_url('projects/member.php?id=' . $projectId . '&mid=' . $memberId)); ?>">Cancel</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <?php if ($errors): ?>
            <div class="member-edit-alert">
              <strong>Fix these:</strong>
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?php echo h0($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="member-edit-head">
            <div class="member-edit-title">
              <div class="member-edit-ico">👥</div>
              <div>
                <h1 class="member-edit-h1">Edit member details</h1>
                <p class="member-edit-sub">Edit and update details of your team members.</p>
              </div>
            </div>

            <a class="btn" href="<?php echo h0(base_url('projects/member.php?id=' . $projectId . '&mid=' . $memberId)); ?>">Cancel</a>
          </div>

          <div class="member-edit-grid">
            <div class="card member-edit-card">
              <div class="member-edit-card-title">Team member information</div>
              <div class="member-edit-card-sub">Update contact details and project departments for this member.</div>

              <div class="member-edit-divider"></div>

              <form method="post" id="memberEditForm">
                <input type="hidden" name="id" value="<?php echo h0((string)$projectId); ?>">
                <input type="hidden" name="mid" value="<?php echo h0((string)$memberId); ?>">

                <div class="member-edit-field">
                  <label for="member_name">Selected team member</label>
                  <input
                    id="member_name"
                    class="member-edit-readonly"
                    type="text"
                    value="<?php echo h0($memberName); ?>"
                    readonly
                  >
                </div>

                <div class="member-edit-field">
                  <label for="pmDeptSelect">Add department</label>
                  <div class="member-role-row">
                    <select id="pmDeptSelect">
                      <option value="">Select department…</option>
                      <?php foreach ($roleOptions as $roleKey => $roleText): ?>
                        <option value="<?php echo h0($roleKey); ?>"><?php echo h0($roleText); ?></option>
                      <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn" id="pmAddDeptBtn">Add department</button>
                  </div>

                  <div id="pmHiddenRoles">
                    <?php foreach ($selectedRoles as $role): ?>
                      <input type="hidden" name="roles[]" value="<?php echo h0($role); ?>">
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="member-edit-form-row">
                  <div class="member-edit-field">
                    <label for="phone">Contact number</label>
                    <input id="phone" name="phone" type="text" value="<?php echo h0($phoneValue); ?>" placeholder="+91-1234567890">
                  </div>

                  <div class="member-edit-field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?php echo h0($emailValue); ?>" placeholder="name@company.com">
                  </div>
                </div>

                <div
                  class="member-driver-section"
                  id="driverDetailsSection"
                  style="<?php echo in_array('driver', $selectedRoles, true) ? '' : 'display:none;'; ?>"
                >
                  <div class="member-driver-title">Driver details</div>
                  <div class="member-driver-sub">Add vehicle details for members who will handle pickups and drops.</div>

                  <?php if (!$driverColumnsReady): ?>
                    <div class="member-edit-alert" style="margin-bottom:0;">
                      Driver detail columns are missing from <strong>company_members</strong>. Run the SQL first, then this section will save properly.
                    </div>
                  <?php else: ?>
                    <div class="member-edit-form-row">
                      <div class="member-edit-field">
                        <label for="driver_car_model">Car model</label>
                        <input id="driver_car_model" name="driver_car_model" type="text" value="<?php echo h0($carModelValue); ?>" placeholder="e.g. Audi S class">
                      </div>

                      <div class="member-edit-field">
                        <label for="driver_car_type">Car type</label>
                        <input id="driver_car_type" name="driver_car_type" type="text" value="<?php echo h0($carTypeValue); ?>" placeholder="e.g. Sedan">
                      </div>
                    </div>

                    <div class="member-edit-form-row">
                      <div class="member-edit-field">
                        <label for="driver_plate_number">Licensed plate number</label>
                        <input id="driver_plate_number" name="driver_plate_number" type="text" value="<?php echo h0($plateValue); ?>" placeholder="e.g. DL-04-1234">
                      </div>

                      <div class="member-edit-field">
                        <label for="driver_seating_capacity">Max seating capacity</label>
                        <input id="driver_seating_capacity" name="driver_seating_capacity" type="number" min="1" value="<?php echo h0($seatingValue); ?>" placeholder="e.g. 5">
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="member-edit-actions">
                  <button class="btn btn-primary" type="submit">Update member details</button>
                </div>
              </form>
            </div>

            <div class="card member-edit-card">
              <div class="member-edit-card-title">Assigned departments</div>
              <div class="member-edit-card-sub">Departments assigned to the member.</div>

              <div class="member-chip-box" style="margin-top:16px;">
                <div class="member-chips" id="pmChips"></div>
                <div class="member-chip-empty" id="pmChipsEmpty">Department assigned to the member will show up here</div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </section>
</div>

<script>
(function () {
  const roleLabels = <?php echo json_encode($roleOptions, JSON_UNESCAPED_UNICODE); ?>;

  const deptSelect = document.getElementById("pmDeptSelect");
  const addBtn = document.getElementById("pmAddDeptBtn");
  const chipsWrap = document.getElementById("pmChips");
  const empty = document.getElementById("pmChipsEmpty");
  const hiddenWrap = document.getElementById("pmHiddenRoles");
  const driverSection = document.getElementById("driverDetailsSection");

  function getRoles() {
    return Array.from(hiddenWrap.querySelectorAll('input[name="roles[]"]')).map(i => i.value);
  }

  function syncDriverSection() {
    const hasDriver = getRoles().includes('driver');
    if (!driverSection) return;

    driverSection.style.display = hasDriver ? '' : 'none';

    const inputs = driverSection.querySelectorAll('input');
    inputs.forEach((input) => {
      input.disabled = !hasDriver;
    });
  }

  function syncEmptyState() {
    const has = chipsWrap.children.length > 0;
    empty.style.display = has ? "none" : "";
  }

  function renderChips() {
    chipsWrap.innerHTML = "";
    const roles = getRoles();

    roles.forEach((role) => {
      const chip = document.createElement("span");
      chip.className = "member-chip";
      chip.innerHTML = `
        <span>${roleLabels[role] || role}</span>
        <button type="button" aria-label="Remove">×</button>
      `;

      chip.querySelector("button").addEventListener("click", () => {
        hiddenWrap.querySelectorAll('input[name="roles[]"]').forEach((input) => {
          if (input.value === role) input.remove();
        });
        renderChips();
      });

      chipsWrap.appendChild(chip);
    });

    syncEmptyState();
    syncDriverSection();
  }

  function addRole(role) {
    if (!role) return;

    const roles = getRoles();
    if (roles.includes(role)) {
      deptSelect.value = "";
      return;
    }

    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "roles[]";
    input.value = role;
    hiddenWrap.appendChild(input);

    deptSelect.value = "";
    renderChips();
  }

  addBtn.addEventListener("click", () => addRole(deptSelect.value));

  renderChips();
})();
</script>

<?php require_once $root . '/includes/footer.php'; ?>