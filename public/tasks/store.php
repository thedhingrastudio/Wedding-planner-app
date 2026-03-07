<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';

$auditFile = $root . '/includes/audit.php';
if (is_file($auditFile)) {
  require_once $auditFile;
}

require_login();

$pdo = $pdo ?? get_pdo();
$companyId = current_company_id();

/* ---------- Safe audit fallbacks ---------- */
if (!function_exists('audit_client_ip')) {
  function audit_client_ip(): string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 45) : 'unknown';
  }
}

if (!function_exists('audit_user_agent')) {
  function audit_user_agent(): string {
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
  }
}

if (!function_exists('audit_actor_user_id')) {
  function audit_actor_user_id(): ?int {
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('audit_actor_name')) {
  function audit_actor_name(): string {
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    return $name !== '' ? $name : 'Unknown member';
  }
}

if (!function_exists('audit_json_string')) {
  function audit_json_string($value): ?string {
    if ($value === null) return null;
    if (is_string($value)) return $value;

    try {
      $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      return $json === false ? null : $json;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('audit_build_search_text')) {
  function audit_build_search_text(array $parts): string {
    $out = [];

    foreach ($parts as $part) {
      if (is_array($part)) {
        foreach ($part as $sub) {
          $sub = trim((string)$sub);
          if ($sub !== '') $out[] = $sub;
        }
      } else {
        $part = trim((string)$part);
        if ($part !== '') $out[] = $part;
      }
    }

    return implode(' | ', $out);
  }
}

if (!function_exists('audit_log')) {
  function audit_log(array $payload): void {
    try {
      $pdo = ($payload['pdo'] ?? null);
      if (!$pdo instanceof PDO) return;

      $companyId = (int)($payload['company_id'] ?? 0);
      if ($companyId <= 0) return;

      $projectId = isset($payload['project_id']) && (int)$payload['project_id'] > 0
        ? (int)$payload['project_id']
        : null;

      $actorUserId = isset($payload['actor_user_id']) && (int)$payload['actor_user_id'] > 0
        ? (int)$payload['actor_user_id']
        : null;

      $targetCompanyMemberId = isset($payload['target_company_member_id']) && (int)$payload['target_company_member_id'] > 0
        ? (int)$payload['target_company_member_id']
        : null;

      $entityType = trim((string)($payload['entity_type'] ?? 'system'));
      $entityId = isset($payload['entity_id']) && (int)$payload['entity_id'] > 0
        ? (int)$payload['entity_id']
        : null;

      $action = trim((string)($payload['action'] ?? 'updated'));
      $summary = trim((string)($payload['summary'] ?? 'Updated record'));
      $actorName = trim((string)($payload['actor_name'] ?? audit_actor_name()));
      if ($actorName === '') $actorName = 'Unknown member';

      $diffJson = audit_json_string($payload['diff_json'] ?? null);
      $searchText = trim((string)($payload['search_text'] ?? ''));
      if ($searchText === '') {
        $searchText = audit_build_search_text([$actorName, $entityType, $action, $summary]);
      }

      $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
          company_id,
          project_id,
          actor_user_id,
          actor_name,
          target_company_member_id,
          entity_type,
          entity_id,
          action,
          summary,
          ip_address,
          user_agent,
          diff_json,
          search_text,
          created_at
        ) VALUES (
          :company_id,
          :project_id,
          :actor_user_id,
          :actor_name,
          :target_company_member_id,
          :entity_type,
          :entity_id,
          :action,
          :summary,
          :ip_address,
          :user_agent,
          :diff_json,
          :search_text,
          NOW()
        )
      ");

      $stmt->execute([
        ':company_id' => $companyId,
        ':project_id' => $projectId,
        ':actor_user_id' => $actorUserId,
        ':actor_name' => $actorName,
        ':target_company_member_id' => $targetCompanyMemberId,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':action' => $action,
        ':summary' => $summary,
        ':ip_address' => audit_client_ip(),
        ':user_agent' => audit_user_agent(),
        ':diff_json' => $diffJson,
        ':search_text' => $searchText,
      ]);
    } catch (Throwable $e) {
      // never break the create flow if audit logging fails
    }
  }
}

/* ---------- Only accept POST ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('projects/index.php');
}

/* ---------- Read form values ---------- */
$projectId = (int)($_POST['project_id'] ?? 0);
$assigneeId = (int)(
  $_POST['assigned_to_company_member_id']
  ?? $_POST['company_member_id']
  ?? $_POST['assignee']
  ?? 0
);

$category = strtolower(trim((string)($_POST['category'] ?? 'general')));
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$assignedOn = trim((string)($_POST['assigned_on'] ?? ''));
$dueOn = trim((string)($_POST['due_on'] ?? ''));
$priority = strtolower(trim((string)($_POST['priority'] ?? 'medium')));
$assignedByUserId = (int)($_SESSION['user_id'] ?? 0);

$categoryOptions = ['follow_ups', 'rsvp', 'guest_list', 'transport', 'hospitality', 'vendors', 'general'];
$priorityOptions = ['low', 'medium', 'high'];

if ($projectId <= 0) {
  redirect('tasks/index.php?error=' . urlencode('Please select a project.'));
}
if ($assigneeId <= 0) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Please select a team member.'));
}
if ($title === '') {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Task title is required.'));
}

if (!in_array($category, $categoryOptions, true)) {
  $category = 'general';
}
if (!in_array($priority, $priorityOptions, true)) {
  $priority = 'medium';
}

$assignedOn = ($assignedOn !== '') ? substr($assignedOn, 0, 10) : '';
$dueOn = ($dueOn !== '') ? substr($dueOn, 0, 10) : '';

/* ---------- Validate project ---------- */
$p = $pdo->prepare("SELECT id FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$p->execute([
  ':pid' => $projectId,
  ':cid' => $companyId,
]);

if (!$p->fetch()) {
  redirect('tasks/index.php?error=' . urlencode('Invalid project.'));
}

/* ---------- Validate assignee belongs to company + is assigned to this project ---------- */
$chk = $pdo->prepare("
  SELECT COUNT(*)
  FROM project_members pm
  JOIN company_members cm
    ON cm.id = pm.company_member_id
   AND cm.company_id = :cid
  WHERE pm.project_id = :pid
    AND pm.company_member_id = :cmid
");
$chk->execute([
  ':cid' => $companyId,
  ':pid' => $projectId,
  ':cmid' => $assigneeId,
]);

if ((int)$chk->fetchColumn() === 0) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('That member is not assigned to this project.'));
}

/* ---------- Assignee display name for audit ---------- */
$assigneeName = '';
try {
  $ms = $pdo->prepare("
    SELECT full_name
    FROM company_members
    WHERE id = :cmid
      AND company_id = :cid
    LIMIT 1
  ");
  $ms->execute([
    ':cmid' => $assigneeId,
    ':cid' => $companyId,
  ]);
  $assigneeName = trim((string)($ms->fetchColumn() ?: ''));
} catch (Throwable $e) {
  $assigneeName = '';
}

/* ---------- Insert task ---------- */
try {
  $ins = $pdo->prepare("
    INSERT INTO tasks (
      company_id,
      project_id,
      assigned_to_company_member_id,
      assigned_by_user_id,
      category,
      title,
      description,
      assigned_on,
      due_on,
      priority,
      status
    ) VALUES (
      :cid,
      :pid,
      :assignee,
      :by,
      :cat,
      :title,
      :descr,
      :assigned_on,
      :due_on,
      :priority,
      'pending'
    )
  ");

  $ins->execute([
    ':cid' => $companyId,
    ':pid' => $projectId,
    ':assignee' => $assigneeId,
    ':by' => ($assignedByUserId > 0 ? $assignedByUserId : null),
    ':cat' => $category,
    ':title' => $title,
    ':descr' => ($description !== '' ? $description : null),
    ':assigned_on' => ($assignedOn !== '' ? $assignedOn : null),
    ':due_on' => ($dueOn !== '' ? $dueOn : null),
    ':priority' => $priority,
  ]);

  $taskId = (int)$pdo->lastInsertId();

  audit_log([
    'pdo' => $pdo,
    'company_id' => $companyId,
    'project_id' => $projectId,
    'actor_user_id' => audit_actor_user_id(),
    'actor_name' => audit_actor_name(),
    'target_company_member_id' => $assigneeId,
    'entity_type' => 'task',
    'entity_id' => $taskId > 0 ? $taskId : null,
    'action' => 'created',
    'summary' => 'Created task: ' . $title,
    'diff_json' => [
      'before' => null,
      'after' => [
        'assignee_id' => $assigneeId,
        'assignee_name' => $assigneeName,
        'category' => $category,
        'title' => $title,
        'description' => $description,
        'assigned_on' => $assignedOn,
        'due_on' => $dueOn,
        'priority' => $priority,
        'status' => 'pending',
      ],
    ],
    'search_text' => audit_build_search_text([
      'task',
      'created',
      $title,
      $category,
      $priority,
      $assigneeName,
      $assignedOn,
      $dueOn,
      $description,
    ]),
  ]);

} catch (Throwable $e) {
  redirect('tasks/index.php?project_id=' . $projectId . '&error=' . urlencode('Could not save task. Check DB/table: tasks.'));
}

/* ---------- Redirect to project dashboard ---------- */
redirect('projects/show.php?id=' . $projectId);