<?php
declare(strict_types=1);

$ROOT = dirname(__DIR__);

// Dev-friendly error reporting (remove/disable in production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once $ROOT . '/config/app.php';
require_once $ROOT . '/config/session.php';

require_once $ROOT . '/includes/functions.php';
require_once $ROOT . '/includes/flash.php';
require_once $ROOT . '/includes/auth.php';

$pdo = db();