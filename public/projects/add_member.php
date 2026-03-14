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

// Load project (security: must belong to current company)
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = "Add member — " . (string)$project['title'] . " — Vidhaan";
require_once $root . '/includes/header.php';

// Countdown (same as show.php)
$first = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
} catch (Throwable $e) {}

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$createdAt = (string)($project['created_at'] ?? '');
$projectDateLabel = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Date TBD';

function role_label(string $role): string {
  $map = [
    'team_lead'    => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp'         => 'RSVP team',
    'hospitality'  => 'Hospitality',
    'transport'    => 'Transport',
    'vendor'       => 'Vendor',
    'driver'       => 'Driver',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
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

// --- Project members list (for bottom table + to disable already-added in dropdown)
$projectMembers = [];
$assignedIds = [];
try {
  $pm = $pdo->prepare("
    SELECT
      pm.company_member_id,
      cm.full_name,
      cm.email,
      cm.status,
      GROUP_CONCAT(DISTINCT pm.role ORDER BY pm.role SEPARATOR ',') AS roles,
      MIN(pm.created_at) AS added_at
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
    GROUP BY pm.company_member_id, cm.full_name, cm.email, cm.status
    ORDER BY cm.full_name ASC
  ");
  $pm->execute([':pid' => $projectId, ':cid' => $companyId]);
  $projectMembers = $pm->fetchAll();

  $assignedIds = array_map(
    fn($r) => (int)$r['company_member_id'],
    $projectMembers ?: []
  );
} catch (Throwable $e) {
  $projectMembers = [];
  $assignedIds = [];
}

// --- Company members for dropdown
$companyMembers = [];
try {
  $cm = $pdo->prepare("
    SELECT id, full_name, email, default_department, status
    FROM company_members
    WHERE company_id = :cid
    ORDER BY created_at DESC
  ");
  $cm->execute([':cid' => $companyId]);
  $companyMembers = $cm->fetchAll();
} catch (Throwable $e) {
  $companyMembers = [];
}

// --- Form state
$errors = [];
$selectedCompanyMemberId = (int)($_POST['company_member_id'] ?? 0);
$selectedRoles = $_POST['roles'] ?? [];
if (!is_array($selectedRoles)) $selectedRoles = [];

// Handle POST: assign existing company member to project with selected departments
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($selectedCompanyMemberId <= 0) $errors[] = "Please select a team member.";
  if (!$selectedRoles) $errors[] = "Please add at least one department.";

  // Ensure they are from this company
  $member = null;
  if (!$errors) {
    $s = $pdo->prepare("SELECT * FROM company_members WHERE id = ? AND company_id = ? LIMIT 1");
    $s->execute([$selectedCompanyMemberId, $companyId]);
    $member = $s->fetch();
    if (!$member) $errors[] = "That member doesn’t exist in your company.";
  }

  // Prevent adding if already in project (since your UI says “already exists in project”)
  if (!$errors && in_array($selectedCompanyMemberId, $assignedIds, true)) {
    $errors[] = "This member is already in the project. (You can manage roles from the Team page later.)";
  }

  // Validate roles
  $cleanRoles = [];
  foreach ($selectedRoles as $r) {
    $r = trim((string)$r);
    if ($r === '') continue;
    if (!array_key_exists($r, $roleOptions)) continue;
    $cleanRoles[] = $r;
  }
  $cleanRoles = array_values(array_unique($cleanRoles));
  if (!$errors && !$cleanRoles) $errors[] = "Please add a valid department.";

  if (!$errors && $member) {
    try {
      $pdo->beginTransaction();

      $ins = $pdo->prepare("
        INSERT INTO project_members
          (project_id, user_id, email, role, created_at, display_name, responsibility_label, department, company_member_id)
        VALUES
          (:pid, :uid, :email, :role, NOW(), :display_name, NULL, :dept, :cmid)
      ");

      $added = 0;
      foreach ($cleanRoles as $role) {
        $ins->execute([
          ':pid' => $projectId,
          ':uid' => $member['user_id'] ?? null,
          ':email' => ($member['email'] ?? null),
          ':role' => $role,
          ':display_name' => ($member['full_name'] ?? null),
          ':dept' => $role,
          ':cmid' => $selectedCompanyMemberId,
        ]);
        $added++;
      }

      $pdo->commit();

      flash_set('success', $added > 0 ? "Member added to project." : "No changes made.");
      redirect('projects/team.php?id=' . $projectId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = "Could not add member. " . $e->getMessage();
    }
  }
}
?>

<div class="app-shell">
  <?php
    $nav_active = 'projects';
    require_once $root . '/includes/sidebar.php';
  ?>

  <section class="app-main">
    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <!-- Project header -->
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo h((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo h($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h(base_url('projects/team.php?id=' . $projectId)); ?>">Cancel</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="pm-head">
            <div class="pm-title">
              <div class="pm-ico">👥</div>
              <div>
                <div class="pm-h1">Add team member to your project</div>
                <div class="pm-sub">Choose someone from your company, then assign their departments for this project.</div>
              </div>
            </div>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-error">
              <strong>Fix these:</strong>
              <ul class="alert-list">
                <?php foreach ($errors as $er): ?>
                  <li><?php echo h($er); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="pm-grid">

            <!-- Left card -->
            <div class="card pm-card">
              <div class="pm-card-head">
                <div>
                  <div class="pm-card-title">Team member information</div>
                  <div class="pm-card-sub">Pick an existing company member and assign their departments.</div>
                </div>
              </div>

              <form method="post" class="pm-form" id="pmForm">
                <div class="field">
                  <div class="label">Selected team member</div>

                  <!-- ✅ Dropdown instead of bottom selection list -->
                  <select class="input" name="company_member_id" id="pmMemberSelect" required>
                    <option value="">Select a person…</option>
                    <?php foreach ($companyMembers as $m): ?>
                      <?php
                        $cmid = (int)$m['id'];
                        $disabled = in_array($cmid, $assignedIds, true);
                        $selected = ($cmid === $selectedCompanyMemberId);
                        $label = (string)$m['full_name'] . ' — ' . (string)$m['email'];
                      ?>
                      <option
                        value="<?php echo h((string)$cmid); ?>"
                        <?php echo $selected ? 'selected' : ''; ?>
                        <?php echo $disabled ? 'disabled' : ''; ?>
                      >
                        <?php echo h($label . ($disabled ? ' (Already in project)' : '')); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="field">
                  <div class="label">Add department</div>
                  <div class="pm-role-row">
                    <select class="input" id="pmDeptSelect">
                      <option value="">Select department…</option>
                      <?php foreach ($roleOptions as $k => $lab): ?>
                        <option value="<?php echo h($k); ?>"><?php echo h($lab); ?></option>
                      <?php endforeach; ?>
                    </select>

                    <button type="button" class="btn" id="pmAddDeptBtn">Add department</button>
                  </div>

                  <!-- hidden inputs to submit departments -->
                  <div id="pmHiddenRoles">
                    <?php foreach ($selectedRoles as $r): ?>
                      <input type="hidden" name="roles[]" value="<?php echo h((string)$r); ?>">
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="pm-actions">
                  <button class="btn btn-primary" type="submit" id="pmInviteBtn">Invite to team</button>
                </div>
              </form>
            </div>

            <!-- Right card: live chips -->
            <div class="card pm-card">
              <div class="pm-card-head">
                <div>
                  <div class="pm-card-title">Assigned departments</div>
                  <div class="pm-card-sub">Departments you add will show up here before you invite.</div>
                </div>
              </div>

              <div class="pm-chip-box">
                <div class="pm-chips" id="pmChips"></div>
                <div class="pm-empty" id="pmChipsEmpty">Department assigned to the member will show up here</div>
              </div>
            </div>

          </div><!-- /pm-grid -->

          <!-- ✅ Bottom list: PROJECT MEMBERS ONLY -->
          <div class="pm-list-head">
            <div class="pm-list-title">Project members</div>
            <div class="pm-list-sub">People currently assigned to this project.</div>
          </div>

          <div class="card pm-table">
            <div class="pm-table-head">
              <div>Team member</div>
              <div>Email</div>
              <div>Departments</div>
              <div>Status</div>
              <div class="pm-right">Added</div>
            </div>

            <?php if (!$projectMembers): ?>
              <div class="empty" style="min-height:240px;">
                <div>
                  <div style="font-size:26px;">👥</div>
                  <div class="big"><strong>No project members yet</strong></div>
                  <div class="small">Use the dropdown above to add your first member.</div>
                </div>
              </div>
            <?php else: ?>
              <div class="pm-table-body">
                <?php foreach ($projectMembers as $m): ?>
                  <?php
                    $rolesCsv = (string)($m['roles'] ?? '');
                    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
                  ?>
                  <div class="pm-row">
                    <div class="pm-cell"><strong><?php echo h((string)$m['full_name']); ?></strong></div>
                    <div class="pm-cell"><?php echo h((string)$m['email']); ?></div>
                    <div class="pm-cell">
                      <div class="pm-role-tags">
                        <?php foreach ($roles as $r): ?>
                          <span class="tag"><?php echo h(role_label($r)); ?></span>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div class="pm-cell"><span class="tag tag-status"><?php echo h(ucfirst((string)$m['status'])); ?></span></div>
                    <div class="pm-cell pm-right" style="color:var(--muted); font-size:12px;">
                      <?php echo h((string)$m['added_at']); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->
    </div><!-- /surface -->
  </section>
</div>

<script>
(function(){
  const roleLabels = <?php echo json_encode($roleOptions, JSON_UNESCAPED_UNICODE); ?>;

  const memberSelect = document.getElementById("pmMemberSelect");
  const deptSelect = document.getElementById("pmDeptSelect");
  const addBtn = document.getElementById("pmAddDeptBtn");
  const chipsWrap = document.getElementById("pmChips");
  const empty = document.getElementById("pmChipsEmpty");
  const hiddenWrap = document.getElementById("pmHiddenRoles");

  const getRoles = () => Array.from(hiddenWrap.querySelectorAll('input[name="roles[]"]')).map(i => i.value);

  const setEmptyState = () => {
    const has = chipsWrap.children.length > 0;
    empty.style.display = has ? "none" : "";
  };

  const renderChips = () => {
    chipsWrap.innerHTML = "";
    const roles = getRoles();

    roles.forEach((role) => {
      const chip = document.createElement("span");
      chip.className = "chip";
      chip.innerHTML = `
        <span>${roleLabels[role] || role}</span>
        <button type="button" class="chip-x" aria-label="Remove">×</button>
      `;
      chip.querySelector(".chip-x").addEventListener("click", () => {
        hiddenWrap.querySelectorAll('input[name="roles[]"]').forEach((inp) => {
          if (inp.value === role) inp.remove();
        });
        renderChips();
      });
      chipsWrap.appendChild(chip);
    });

    setEmptyState();
  };

  const addRole = (role) => {
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
  };

  addBtn.addEventListener("click", () => addRole(deptSelect.value));

  // When member changes, clear roles (prevents accidental carry-over)
  memberSelect.addEventListener("change", () => {
    hiddenWrap.innerHTML = "";
    renderChips();
  });

  // initial render (for validation error reload)
  renderChips();
})();
</script>

<?php include $root . '/includes/footer.php'; ?>