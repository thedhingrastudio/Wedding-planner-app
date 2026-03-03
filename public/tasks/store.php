<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('tasks/index.php');
}

$companyId = current_company_id();

$projectId = (int)($_POST['project_id'] ?? 0);
$assigneeId = (int)($_POST['assigned_to_company_member_id'] ?? 0);
$assignedByUserId = (int)($_POST['assigned_by_user_id'] ?? current_user_id());

$category = trim((string)($_POST['category'] ?? 'general'));
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

$assignedOn = trim((string)($_POST['assigned_on'] ?? ''));
$dueOn = trim((string)($_POST['due_on'] ?? ''));

$priority = trim((string)($_POST['priority'] ?? 'medium'));

if ($projectId <= 0) {
  redirect('tasks/index.php?error=' . urlencode('Please select a project.'));
}
if ($assigneeId <= 0) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Please select a team member.'));
}
if ($title === '') {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Task title is required.'));
}

$priorityAllowed = ['low','medium','high'];
if (!in_array($priority, $priorityAllowed, true)) $priority = 'medium';

// Validate project belongs to company
$p = $pdo->prepare("SELECT id FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$p->execute([':pid' => $projectId, ':cid' => $companyId]);
if (!$p->fetch()) {
  redirect('tasks/index.php?error=' . urlencode('Invalid project.'));
}

// Validate assignee belongs to company + is assigned to this project
$chk = $pdo->prepare("
  SELECT COUNT(*)
  FROM project_members pm
  JOIN company_members cm
    ON cm.id = pm.company_member_id
   AND cm.company_id = :cid
  WHERE pm.project_id = :pid
    AND pm.company_member_id = :cmid
");
$chk->execute([':cid' => $companyId, ':pid' => $projectId, ':cmid' => $assigneeId]);
if ((int)$chk->fetchColumn() === 0) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('That member is not assigned to this project.'));
}

// Insert task
try {
  $ins = $pdo->prepare("
    INSERT INTO tasks (
      company_id, project_id,
      assigned_to_company_member_id, assigned_by_user_id,
      category, title, description,
      assigned_on, due_on,
      priority, status
    )
    VALUES (
      :cid, :pid,
      :assignee, :by,
      :cat, :title, :descr,
      :assigned_on, :due_on,
      :priority, 'pending'
    )
  ");
  $ins->execute([
    ':cid' => $companyId,
    ':pid' => $projectId,
    ':assignee' => $assigneeId,
    ':by' => $assignedByUserId,
    ':cat' => $category,
    ':title' => $title,
    ':descr' => ($description !== '' ? $description : null),
    ':assigned_on' => ($assignedOn !== '' ? $assignedOn : null),
    ':due_on' => ($dueOn !== '' ? $dueOn : null),
    ':priority' => $priority,
  ]);
} catch (Throwable $e) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Could not save task. Check DB/table: tasks.'));
}

redirect('tasks/index.php?project_id=' . $projectId . '&saved=1');