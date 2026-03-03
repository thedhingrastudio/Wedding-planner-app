<?php
// public/login.php

$root = __DIR__;
while (!is_dir($root . '/includes') && $root !== dirname($root)) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';

// If already logged in, go to dashboard
if (!empty($_SESSION['user_id']) && !empty($_SESSION['company_id'])) {
  redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  try {
    $stmt = $pdo->prepare(
      "SELECT id, company_id, role, password_hash, full_name FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['company_id'] = (int)$user['company_id'];
      $_SESSION['role'] = (string)($user['role'] ?? 'member');
      $_SESSION['full_name'] = (string)($user['full_name'] ?? '');

      flash_set('success', 'Signed in successfully.');
      redirect('dashboard.php');
    }

    $error = 'Invalid email or password.';
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$pageTitle = 'Sign in — Vidhaan';
require_once $root . '/includes/header.php';
?>

<div class="auth-shell">
  <div class="auth-left">
    <div class="auth-card">
      <div class="auth-title">
        <div style="font-size:22px;">👤</div>
        <h1>Sign in to your workspace</h1>
        <p>Access your team’s wedding projects, tasks, and timelines.</p>
      </div>

      <?php if ($error): ?>
        <p style="color:#b00020; margin-top:14px;"><?php echo h($error); ?></p>
      <?php endif; ?>

      <form method="post" style="margin-top:14px;">
        <div class="field">
          <div class="label">Email</div>
          <input class="input" name="email" type="email" placeholder="sample@example.com" required>
        </div>

        <div class="field">
          <div class="label">Password</div>
          <input class="input" name="password" type="password" placeholder="••••••••••" required>
        </div>

        <button class="btn btn-primary" type="submit" style="width:100%; margin-top:14px;">
          Sign in
        </button>
      </form>

      <div style="text-align:center; margin-top:12px;">
        <a href="#" style="color:#444; font-size:13px;">Forgot password?</a>
      </div>

      <div style="display:flex; align-items:center; gap:10px; margin:16px 0;">
        <div style="height:1px; background:var(--border); flex:1;"></div>
        <div style="font-size:12px; color:var(--muted);">or</div>
        <div style="height:1px; background:var(--border); flex:1;"></div>
      </div>

      <div style="text-align:center; color:var(--muted); font-size:12px; margin-bottom:10px;">
        For admins setting up a new workspace
      </div>

      <a class="btn" href="#" style="width:100%; display:block; text-align:center;">
        Create a team
      </a>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-brand">Vidhaan</div>
  </div>
</div>

<?php require_once $root . '/includes/footer.php'; ?>
