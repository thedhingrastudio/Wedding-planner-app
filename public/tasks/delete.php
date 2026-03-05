<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();
$companyId = current_company_id();

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) redirect('projects/index.php');

// Load task securely + get project_id for redirect
$task = null;
try {
  $st = $pdo->prepare("
    SELECT id, project_id
    FROM tasks
    WHERE id = :tid AND company_id = :cid
    LIMIT 1
  ");
  $st->execute([':tid' => $taskId, ':cid' => $companyId]);
  $task = $st->fetch() ?: null;
} catch (Throwable $e) {
  $task = null;
}

if (!$task) redirect('projects/index.php');

$projectId = (int)($task['project_id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

// Only delete on POST (so direct link doesn't delete)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  // If someone hits delete.php directly, send them back to task page
  redirect('tasks/show.php?id=' . $taskId);
}

try {
  $del = $pdo->prepare("DELETE FROM tasks WHERE id = :tid AND company_id = :cid");
  $del->execute([':tid' => $taskId, ':cid' => $companyId]);
} catch (Throwable $e) {
  // If delete fails, go back to task
  redirect('tasks/show.php?id=' . $taskId);
}

// After delete → Open tasks page
redirect('projects/open_tasks.php?id=' . $projectId . '&view=open');