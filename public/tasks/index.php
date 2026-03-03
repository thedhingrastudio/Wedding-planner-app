<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$companyId = current_company_id();
$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$projectId = (int)($_GET['project_id'] ?? 0);

// Load projects (company)
$projects = [];
try {
  $ps = $pdo->prepare("
    SELECT id, title, created_at
    FROM projects
    WHERE company_id = :cid
    ORDER BY created_at DESC, id DESC
  ");
  $ps->execute([':cid' => $companyId]);
  $projects = $ps->fetchAll();
} catch (Throwable $e) {
  $projects = [];
}

// If no project chosen, default to most recent
if ($projectId <= 0 && !empty($projects)) {
  $projectId = (int)$projects[0]['id'];
}

// Load selected project record (for safety + display)
$project = null;
if ($projectId > 0) {
  $pstmt = $pdo->prepare("SELECT id, title FROM projects WHERE id = :id AND company_id = :cid LIMIT 1");
  $pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
  $project = $pstmt->fetch();
  if (!$project) $projectId = 0;
}

// Load project members (unique company members assigned to project)
$assignees = [];
if ($projectId > 0) {
  try {
    $ms = $pdo->prepare("
      SELECT DISTINCT
        cm.id AS company_member_id,
        cm.full_name,
        cm.email
      FROM project_members pm
      JOIN company_members cm
        ON cm.id = pm.company_member_id
       AND cm.company_id = :cid
      WHERE pm.project_id = :pid
        AND pm.company_member_id IS NOT NULL
      ORDER BY cm.full_name ASC
    ");
    $ms->execute([':cid' => $companyId, ':pid' => $projectId]);
    $assignees = $ms->fetchAll();
  } catch (Throwable $e) {
    $assignees = [];
  }
}

// UI values
$success = !empty($_GET['saved']) ? "Task assigned successfully." : "";
$error = trim((string)($_GET['error'] ?? ''));

$categoryOptions = [
  'follow_ups' => 'Follow ups',
  'rsvp' => 'Invite & RSVP',
  'guest_list' => 'Guest list',
  'transport' => 'Travel & transport',
  'hospitality' => 'Hotel & hospitality',
  'vendors' => 'Vendors',
  'general' => 'General',
];

$priorityOptions = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

$today = date('Y-m-d');

require_once $root . '/includes/header.php';
?>

<div class="app-shell">
  <?php
    $nav_active = 'projects';
    require_once $root . '/includes/sidebar.php';
  ?>

  <section class="app-main">
    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="page-head task-head">
        <div class="page-title">
          <div style="font-size:22px;">📝</div>
          <div>
            <h1>Add and assign tasks</h1>
            <p>Assign tasks to team members</p>
          </div>
        </div>

        <div class="actions">
          <a class="btn" href="<?php echo h($_SERVER['HTTP_REFERER'] ?? base_url('dashboard.php')); ?>">Cancel</a>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success">✅ <?php echo h($success); ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo h($error); ?></div>
      <?php endif; ?>

      <div class="card task-card">
        <form method="post" action="<?php echo h(base_url('tasks/store.php')); ?>" class="task-form">

          <input type="hidden" name="company_id" value="<?php echo h((string)$companyId); ?>">
          <input type="hidden" name="assigned_by_user_id" value="<?php echo h((string)current_user_id()); ?>">

          <div class="task-grid">

            <!-- Row 1 -->
            <div class="task-field">
              <div class="label">Project name</div>
              <select class="input" name="project_id" data-project-select data-base="<?php echo h(base_url('tasks/index.php')); ?>">
                <?php if (!$projects): ?>
                  <option value="">No projects found</option>
                <?php else: ?>
                  <?php foreach ($projects as $p): ?>
                    <option value="<?php echo h((string)$p['id']); ?>" <?php echo ((int)$p['id'] === $projectId) ? 'selected' : ''; ?>>
                      <?php echo h((string)$p['title']); ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <div class="task-help">Select active project</div>
            </div>

            <div class="task-field">
              <div class="label">Select team member to assign task to</div>
              <select class="input" name="assigned_to_company_member_id" <?php echo ($projectId <= 0) ? 'disabled' : ''; ?>>
                <?php if ($projectId <= 0): ?>
                  <option value="">Select project first</option>
                <?php elseif (!$assignees): ?>
                  <option value="">No members assigned to this project</option>
                <?php else: ?>
                  <option value="">Select team member</option>
                  <?php foreach ($assignees as $a): ?>
                    <option value="<?php echo h((string)$a['company_member_id']); ?>">
                      <?php echo h((string)$a['full_name']); ?><?php echo $a['email'] ? ' — ' . h((string)$a['email']) : ''; ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            </div>

            <!-- Row 2 -->
            <div class="task-field">
              <div class="label">Task category</div>
              <select class="input" name="category">
                <?php foreach ($categoryOptions as $k => $lbl): ?>
                  <option value="<?php echo h($k); ?>"><?php echo h($lbl); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="task-field">
              <div class="label">Task title</div>
              <input class="input" name="title" placeholder="e.g., 18 pending guests RSVPs" required>
            </div>

            <!-- Row 3 -->
            <div class="task-field">
              <div class="label">Assigned on</div>
              <input class="input" type="date" name="assigned_on" value="<?php echo h($today); ?>">
            </div>

            <div class="task-field">
              <div class="label">Due on</div>
              <input class="input" type="date" name="due_on" value="">
            </div>

            <!-- Row 4 -->
            <div class="task-field task-field--full">
              <div class="label">Description</div>
              <textarea class="input task-textarea" name="description" rows="4" placeholder="Add context, requirements, and any notes…"></textarea>
            </div>

            <!-- Row 5 -->
            <div class="task-field">
              <div class="label">Assigned by</div>
              <input class="input" value="<?php echo h($adminName); ?>" disabled>
            </div>

            <div class="task-field">
              <div class="label">Project</div>
              <input class="input" value="<?php echo h((string)($project['title'] ?? 'Select a project')); ?>" disabled>
            </div>

            <!-- Row 6 -->
            <div class="task-field">
              <div class="label">Priority</div>
              <select class="input task-priority" name="priority">
                <?php foreach ($priorityOptions as $k => $lbl): ?>
                  <option value="<?php echo h($k); ?>" <?php echo $k === 'medium' ? 'selected' : ''; ?>><?php echo h($lbl); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="task-field task-actions">
              <button class="btn btn-primary" type="submit">Assign task</button>
            </div>

          </div><!-- /task-grid -->
        </form>
      </div>
    </div>
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const projectSelect = document.querySelector("[data-project-select]");
  if (!projectSelect) return;

  projectSelect.addEventListener("change", () => {
    const base = projectSelect.getAttribute("data-base");
    const pid = projectSelect.value || "";
    window.location.href = base + "?project_id=" + encodeURIComponent(pid);
  });
});
</script>

<?php include $root . '/includes/footer.php'; ?>