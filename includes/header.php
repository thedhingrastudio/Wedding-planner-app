<?php
// includes/header.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/flash.php';

$pageTitle = $pageTitle ?? 'Vidhaan';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($pageTitle) ?></title>

  <!-- Always load CSS from /public/css/main.css using base_url() -->
  <link rel="stylesheet" href="<?= h(base_url('css/main.css')) ?>">
</head>
<body>
<?php if (function_exists('show_flash')) { show_flash(); } ?>