<?php
// Find project root automatically (so this works from any subfolder)
$root = __DIR__;
while (!is_dir($root . '/includes') && $root !== dirname($root)) {
  $root = dirname($root);
}

require_once $root . '/includes/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

require_login();

require_once $root . '/includes/header.php';
require_once $root . '/includes/sidebar.php';
?>

<main style="padding:24px;">
  <h1>Guests</h1>
  <p>Placeholder page ✅</p>
</main>

<?php require_once $root . '/includes/footer.php'; ?>