<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_once $root . '/includes/audit.php';
require_login();

$pdo = $pdo ?? get_pdo();

$taskId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($taskId <= 0) redirect('projects/index.php');

$companyId = current_company_id();

/* ---------- Load task safely ---------- */
$task = null;
try {
  $st = $pdo->prepare("
    SELECT id, project_id, title, category, priority, due_on
    FROM tasks
    WHERE id = :tid AND company_id = :cid
    LIMIT 1
  ");
  $st->execute([
    ':tid' => $taskId,
    ':cid' => $companyId,
  ]);
  $task = $st->fetch() ?: null;
} catch (Throwable $e) {
  $task = null;
}

if (!$task) redirect('projects/index.php');

$projectId = (int)($task['project_id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

/* ---------- Only delete on POST ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('tasks/show.php?id=' . $taskId);
}

$taskTitle = trim((string)($task['title'] ?? 'Untitled task'));
$taskCategory = trim((string)($task['category'] ?? 'general'));
$taskPriority = trim((string)($task['priority'] ?? ''));
$taskDueOn = trim((string)($task['due_on'] ?? ''));

try {
  $pdo->beginTransaction();

  $del = $pdo->prepare("
    DELETE FROM tasks
    WHERE id = :tid AND company_id = :cid
  ");
  $del->execute([
    ':tid' => $taskId,
    ':cid' => $companyId,
  ]);

  $pdo->commit();

  audit_log([
    'pdo' => $pdo,
    'company_id' => $companyId,
    'project_id' => $projectId,
    'actor_user_id' => audit_actor_user_id(),
    'actor_name' => audit_actor_name(),
    'entity_type' => 'task',
    'entity_id' => $taskId,
    'action' => 'deleted',
    'summary' => 'Deleted task: ' . $taskTitle,
    'diff_json' => [
      'before' => [
        'title' => $taskTitle,
        'category' => $taskCategory,
        'priority' => $taskPriority,
        'due_on' => $taskDueOn,
      ],
      'after' => null,
    ],
    'search_text' => audit_build_search_text([
      'task',
      'deleted',
      $taskTitle,
      $taskCategory,
      $taskPriority,
      $taskDueOn,
    ]),
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect('tasks/show.php?id=' . $taskId);
}

redirect('projects/open_tasks.php?id=' . $projectId . '&view=open');