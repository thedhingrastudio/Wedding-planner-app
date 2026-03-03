<?php
// public/company/dashboard.php
// This file is now just an alias/redirect to the real team page:
// public/company/index.php

$root = __DIR__;
while (!is_dir($root . '/includes') && $root !== dirname($root)) {
  $root = dirname($root);
}

require_once $root . '/includes/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/functions.php';

require_login();

// Redirect to the real page that has the Add Member form + DB insert
header("Location: " . base_url("company/index.php"));
exit;