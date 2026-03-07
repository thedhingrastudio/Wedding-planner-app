<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_once $root . '/includes/audit.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$companyId = current_company_id();
$search = trim((string)($_GET['q'] ?? ''));

$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$pstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$projectDateLabel = 'Date TBD';
$daysToGo = null;

try {
  $first = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid AND starts_at IS NOT NULL
    ORDER BY starts_at ASC
    LIMIT 1
  ");
  $first->execute([':pid' => $projectId]);
  $row = $first->fetch();
  if ($row && !empty($row['starts_at'])) {
    $projectDateLabel = date('F j, Y', strtotime((string)$row['starts_at']));
    $d1 = new DateTimeImmutable(date('Y-m-d'));
    $d2 = new DateTimeImmutable(substr((string)$row['starts_at'], 0, 10));
    $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  } else {
    $createdAt = (string)($project['created_at'] ?? '');
    if ($createdAt !== '') $projectDateLabel = date('F j, Y', strtotime($createdAt));
  }
} catch (Throwable $e) {}

$auditTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
  $auditTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $auditTableExists = false;
}

$logs = [];
if ($auditTableExists) {
  try {
    $sql = "
      SELECT id, actor_name, summary, action, entity_type, ip_address, created_at
      FROM audit_logs
      WHERE company_id = :cid
        AND project_id = :pid
    ";
    $params = [
      ':cid' => $companyId,
      ':pid' => $projectId,
    ];

    if ($search !== '') {
      $sql .= " AND (
        summary LIKE :q
        OR actor_name LIKE :q
        OR ip_address LIKE :q
        OR search_text LIKE :q
      )";
      $params[':q'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 100";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $logs = $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    $logs = [];
  }
}

$pageTitle = (string)($project['title'] ?? 'Project') . ' — Activity log';
require_once $root . '/includes/header.php';
?>

<style>
.breadcrumb{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;color:rgba(0,0,0,0.55);}
.breadcrumb a{text-decoration:none;color:rgba(0,0,0,0.55);}
.breadcrumb .sep{opacity:.55;}
.subhead{margin-top:6px;color:var(--muted);font-size:13px;}
.tools-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;}
.search-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.search-input{width:min(360px, 72vw);height:44px;border-radius:999px;border:1px solid rgba(0,0,0,0.08);background:rgba(0,0,0,0.03);padding:0 16px;outline:none;font-size:14px;}
.search-input:focus{background:#fff;border-color:rgba(0,0,0,0.16);}
.log-card{margin-top:16px;border:1px solid rgba(0,0,0,0.06);border-radius:24px;background:#fff;overflow:hidden;}
.log-table{width:100%;border-collapse:separate;border-spacing:0;}
.log-table thead th{text-align:left;font-size:13px;font-weight:800;color:rgba(0,0,0,0.55);padding:18px 20px;border-bottom:1px solid rgba(0,0,0,0.06);}
.log-table tbody td{padding:16px 20px;vertical-align:top;border-bottom:1px solid rgba(0,0,0,0.05);}
.log-table tbody tr:last-child td{border-bottom:none;}
.log-summary{font-weight:700;}
.log-meta{margin-top:6px;font-size:12px;color:var(--muted);}
.empty-state{margin-top:16px;padding:18px 20px;border:1px solid rgba(0,0,0,0.06);border-radius:24px;background:#fff;color:var(--muted);}
@media (max-width: 920px){
  .log-table{display:block;overflow-x:auto;}
  .search-input{width:100%;min-width:240px;}
}
</style>

<div class="app-shell">
  <?php $nav_active = 'projects'; require_once $root . '/includes/sidebar.php'; ?>

  <section class="app-main">
    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo h($adminName); ?>
        <a class="logout" href="<?php echo h(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon"></div>
          <div>
            <div class="proj-name"><?php echo h((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item"><?php echo h($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn" href="<?php echo h(base_url('projects/show.php?id=' . $projectId)); ?>">Back to dashboard</a>
        </div>
      </div>

      <div class="project-shell">
        <?php $active = 'contract'; require_once $root . '/includes/project_sidebar.php'; ?>

        <div class="proj-main">
          <div class="proj-overview-head">
            <div>
              <div class="breadcrumb">
                <a href="<?php echo h(base_url('projects/show.php?id=' . $projectId)); ?>">Project overview</a>
                <span class="sep">›</span>
                <span>Activity log</span>
              </div>
              <div class="subhead">Recent changes on this project. Search by member, action, summary, or IP.</div>
            </div>
          </div>

          <div class="tools-row">
            <form class="search-form" method="get" action="">
              <input type="hidden" name="id" value="<?php echo h((string)$projectId); ?>">
              <input class="search-input" type="text" name="q" value="<?php echo h($search); ?>" placeholder="Search activity">
              <button class="btn" type="submit">Search</button>
            </form>
          </div>

          <?php if (!$auditTableExists): ?>
            <div class="empty-state">Audit log table is not set up yet.</div>
          <?php elseif (empty($logs)): ?>
            <div class="empty-state">No activity found for this project yet.</div>
          <?php else: ?>
            <div class="log-card">
              <table class="log-table">
                <thead>
                  <tr>
                    <th>Change</th>
                    <th>Member</th>
                    <th>When</th>
                    <th>IP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $log): ?>
                    <tr>
                      <td>
                        <div class="log-summary"><?php echo h((string)$log['summary']); ?></div>
                        <div class="log-meta">
                          <?php echo h((string)$log['entity_type']); ?> · <?php echo h((string)$log['action']); ?>
                        </div>
                      </td>
                      <td><?php echo h((string)$log['actor_name']); ?></td>
                      <td><?php echo h(date('d M Y, g:i a', strtotime((string)$log['created_at']))); ?></td>
                      <td><?php echo h((string)($log['ip_address'] ?? '—')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>