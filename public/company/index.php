<?php
$root = __DIR__;
while (!is_dir($root . '/includes') && $root !== dirname($root)) $root = dirname($root);

require_once $root . '/includes/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';
require_login();

// (UI only) Make sure header title works consistently across pages
$page_title = "Your team";
$pageTitle  = "Your team — Vidhaan";

$pdo = get_pdo();

$companyId = (int)($_SESSION['company_id'] ?? 0);
$errors = [];
$success = '';

$deptOptions = ['coordination','rsvp','hospitality','transport','vendor'];
$statusOptions = ['active','invited','inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullName = trim($_POST['full_name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $phone = trim($_POST['phone'] ?? '');
  $dept = $_POST['default_department'] ?? 'coordination';
  $status = $_POST['status'] ?? 'active';

  if ($fullName === '') $errors[] = "Full name is required.";
  if ($email === '') $errors[] = "Email is required.";
  if (!in_array($dept, $deptOptions, true)) $errors[] = "Invalid department.";
  if (!in_array($status, $statusOptions, true)) $errors[] = "Invalid status.";

  // If this email already has a login user in this company, link it
  $userId = null;
  if (!$errors) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE company_id = ? AND email = ? LIMIT 1");
    $stmt->execute([$companyId, $email]);
    $u = $stmt->fetch();
    if ($u) $userId = (int)$u['id'];
  }

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO company_members (company_id, user_id, full_name, email, phone, default_department, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$companyId, $userId, $fullName, $email, $phone ?: null, $dept, $status]);

      header("Location: " . base_url("company/index.php?added=1"));
      exit;
    } catch (PDOException $e) {
      // Duplicate email in same company
      if (str_contains($e->getMessage(), 'uniq_company_email')) {
        $errors[] = "A member with this email already exists in your company.";
      } else {
        $errors[] = $e->getMessage();
      }
    }
  }
}

if (!empty($_GET['added'])) $success = "Member added successfully.";

$stmt = $pdo->prepare("
  SELECT id, full_name, email, phone, default_department, status, created_at
  FROM company_members
  WHERE company_id = ?
  ORDER BY created_at DESC
");
$stmt->execute([$companyId]);
$members = $stmt->fetchAll();

require_once $root . '/includes/header.php';
require_once $root . '/includes/app_start.php';

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';
?>

<div class="app-shell">

  <!-- LEFT SIDEBAR -->
  <?php
    $nav_active = 'team'; // highlight "Your team"
    require_once $root . '/includes/sidebar.php';
  ?>

  <!-- RIGHT SIDE -->
  <section class="app-main">

    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <!-- Your existing content starts here (UNCHANGED) -->
      <div class="page-head">
        <div class="page-title">
          <div style="font-size:22px;">👥</div>
          <div>
            <h1>Your team</h1>
            <p>People in your organization. Add them here before assigning them to projects.</p>
          </div>
        </div>

        <div class="actions">
  <a class="btn" href="<?php echo h($_SERVER['HTTP_REFERER'] ?? base_url('dashboard.php')); ?>">Cancel</a>
</div>
      </div>

      <?php if ($success): ?>
        <div class="card" style="border-color: rgba(0,0,0,0.12); margin-bottom:14px;">
          ✅ <?php echo h($success); ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="card" style="border-color:#b00020; color:#b00020; margin-bottom:14px;">
          <strong>Fix these:</strong>
          <ul>
            <?php foreach ($errors as $er): ?>
              <li><?php echo h($er); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="card" id="add-member">
        <div style="font-weight:700; margin-bottom:8px;">Add a member</div>

        <form method="post" style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
          <div>
            <div class="label">Full name</div>
            <input class="input" name="full_name" placeholder="e.g., Vijay Sharma" required>
          </div>
          <div>
            <div class="label">Email</div>
            <input class="input" type="email" name="email" placeholder="name@company.com" required>
          </div>
          <div>
            <div class="label">Phone (optional)</div>
            <input class="input" name="phone" placeholder="+91...">
          </div>
          <div>
            <div class="label">Default department</div>
            <select class="input" name="default_department">
              <?php foreach ($deptOptions as $d): ?>
                <option value="<?php echo h($d); ?>"><?php echo h(ucfirst($d)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <div class="label">Status</div>
            <select class="input" name="status">
              <?php foreach ($statusOptions as $s): ?>
                <option value="<?php echo h($s); ?>"><?php echo h(ucfirst($s)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display:flex; align-items:end;">
            <button class="btn btn-primary" type="submit" style="width:100%;">Save member</button>
          </div>
        </form>
      </div>

      <div class="card" style="margin-top:14px;">
        <div style="font-weight:700; margin-bottom:10px;">Active team members</div>

        <?php if (!$members): ?>
          <div class="empty">
            <div>
              <div style="font-size:26px;">👥</div>
              <div class="big"><strong>No members yet</strong></div>
              <div class="small">Add members here to assign them to projects.</div>
            </div>
          </div>
        <?php else: ?>
          <table border="0" cellpadding="10" cellspacing="0" style="width:100%;">
            <thead style="color:var(--muted); font-size:12px; text-align:left;">
              <tr>
                <th>Name</th>
                <th>Role/Dept</th>
                <th>Email</th>
                <th>Status</th>
                <th>Added</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($members as $m): ?>
                <tr style="border-top:1px solid var(--border);">
                  <td><strong><?php echo h($m['full_name']); ?></strong></td>
                  <td><?php echo h(ucfirst($m['default_department'])); ?></td>
                  <td><?php echo h($m['email']); ?></td>
                  <td><?php echo h(ucfirst($m['status'])); ?></td>
                  <td style="color:var(--muted); font-size:12px;"><?php echo h($m['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <!-- Your existing content ends here -->

    </div>
  </section>
</div>

<?php
require_once $root . '/includes/app_end.php';
require_once $root . '/includes/footer.php';