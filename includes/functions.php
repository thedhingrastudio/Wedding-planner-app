<?php
// includes/functions.php
declare(strict_types=1);

/**
 * base_url()
 *
 * Usage:
 *   base_url() -> /shaadi_management/public
 *   base_url('dashboard.php') -> /shaadi_management/public/dashboard.php
 *   base_url('projects/show.php?id=1') -> .../public/projects/show.php?id=1
 */
function base_url(string $path = ''): string {
    // Script example: /shaadi_management/public/projects/create.php
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = strpos($script, '/public/');

    $base = ($pos === false)
        ? '/shaadi_management/public'
        : (substr($script, 0, $pos) . '/public');

    if ($path === '') return $base;

    // Allow absolute URLs
    if (preg_match('#^https?://#i', $path)) return $path;

    return $base . '/' . ltrim($path, '/');
}

function asset_url(string $relativePath): string {
    return base_url(ltrim($relativePath, '/'));
}

function asset_url_existing(array $candidates): string {
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $base = base_url();

    foreach ($candidates as $rel) {
        $rel = '/' . ltrim($rel, '/');
        $fsPath = $docRoot . $base . $rel;
        if ($docRoot !== '' && file_exists($fsPath)) {
            return $base . $rel;
        }
    }

    // fallback: first candidate
    return asset_url($candidates[0] ?? '');
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Canonical DB accessor.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $root = dirname(__DIR__);
    $cfg = require $root . '/config/database.php';

    try {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], $cfg['options']);
        return $pdo;
    } catch (PDOException $e) {
        // If charset causes issues, retry with utf8
        if (stripos($e->getMessage(), 'Unknown character set') !== false) {
            $dsn2 = preg_replace('/charset=([^;]+)/i', 'charset=utf8', (string)$cfg['dsn']);
            $pdo = new PDO($dsn2, $cfg['user'], $cfg['pass'], $cfg['options']);
            return $pdo;
        }
        throw $e;
    }
}

// Backwards-compatible alias (your old pages use get_pdo())
function get_pdo(): PDO {
    return db();
}

/**
 * Redirect helper.
 */
function redirect(string $path, int $statusCode = 302): void {
    header('Location: ' . base_url($path), true, $statusCode);
    exit;
}

/**
 * Parse a YYYY-MM-DD string into a MySQL-friendly datetime.
 */
function parse_date_ymd(string $ymd): ?string {
    $ymd = trim($ymd);
    if ($ymd === '') return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    if (!$dt) return null;
    return $dt->format('Y-m-d 00:00:00');
}

/**
 * Flash helpers (so pages don't die looking for includes/flash.php)
 */
function flash(string $type, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

// Backwards-compatible alias (store.php uses flash_set)
function flash_set(string $type, string $message): void {
    flash($type, $message);
}

function flash_pop_all(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}