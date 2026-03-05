<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$pageErr = '';

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$companyId = current_company_id();

// Project security
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$pageTitle = $project['title'] . ' — Members — Vidhaan';
require_once $root . '/includes/header.php';

// countdown
$first = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
} catch (Throwable $e) { if ($debug) $pageErr .= "Event query: ".$e->getMessage()."\n"; }

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$createdAt = (string)($project['created_at'] ?? '');
$projectDateLabel = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Date TBD';

function h0($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function role_label(string $role): string {
  $map = [
    'team_lead'    => 'Team lead',
    'coordination' => 'Coordination',
    'rsvp'         => 'RSVP',
    'hospitality'  => 'Hospitality',
    'transport'    => 'Transport',
    'vendor'       => 'Vendor',
  ];
  $role = trim($role);
  return $map[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ---- members list ----
$members = [];
try {
  $mstmt = $pdo->prepare("
    SELECT
      pm.company_member_id,
      cm.full_name AS full_name,
      cm.email AS email,
      GROUP_CONCAT(DISTINCT pm.role ORDER BY pm.role SEPARATOR ',') AS roles
    FROM project_members pm
    JOIN company_members cm
      ON cm.id = pm.company_member_id
     AND cm.company_id = :cid_cm
    WHERE pm.project_id = :pid
      AND pm.company_member_id IS NOT NULL
      AND (cm.status = 'active' OR cm.status IS NULL)
    GROUP BY pm.company_member_id, cm.full_name, cm.email
    ORDER BY cm.full_name ASC
  ");
  $mstmt->execute([':pid' => $projectId, ':cid_cm' => $companyId]);
  $members = $mstmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $members = [];
  if ($debug) $pageErr .= "Members query: ".$e->getMessage()."\n";
}
$membersCount = count($members);

// ---- tasks module + KPI counts ----
$tasksTableExists = false;
try {
  $q = $pdo->query("SHOW TABLES LIKE 'tasks'");
  $tasksTableExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
  $tasksTableExists = false;
  if ($debug) $pageErr .= "SHOW TABLES tasks: ".$e->getMessage()."\n";
}

$openTasks = 0;
$dueSoon   = 0;
$overdue   = 0;

if ($tasksTableExists) {
  try {
    $cs = $pdo->prepare("
      SELECT
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND assigned_to_company_member_id IS NOT NULL
              AND (due_on IS NULL OR due_on >= CURDATE())
            THEN 1 ELSE 0
          END
        ) AS open_tasks,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND assigned_to_company_member_id IS NOT NULL
              AND due_on IS NOT NULL
              AND due_on >= CURDATE()
              AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            THEN 1 ELSE 0
          END
        ) AS due_soon_tasks,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND assigned_to_company_member_id IS NOT NULL
              AND due_on IS NOT NULL
              AND due_on < CURDATE()
            THEN 1 ELSE 0
          END
        ) AS overdue_tasks
      FROM tasks
      WHERE company_id = :cid
        AND project_id = :pid
    ");
    $cs->execute([':cid' => $companyId, ':pid' => $projectId]);
    $row = $cs->fetch() ?: [];
    $openTasks = (int)($row['open_tasks'] ?? 0);
    $dueSoon   = (int)($row['due_soon_tasks'] ?? 0);
    $overdue   = (int)($row['overdue_tasks'] ?? 0);
  } catch (Throwable $e) {}
}

// ---- per-member counts ----
$countsByMember = []; // [cmid => ['open'=>x,'due'=>y]]
if ($tasksTableExists) {
  try {
    $ct = $pdo->prepare("
      SELECT
        assigned_to_company_member_id AS cmid,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND (due_on IS NULL OR due_on >= CURDATE())
            THEN 1 ELSE 0
          END
        ) AS open_cnt,
        SUM(
          CASE
            WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','done')
              AND due_on IS NOT NULL
              AND due_on >= CURDATE()
              AND due_on <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            THEN 1 ELSE 0
          END
        ) AS due_cnt
      FROM tasks
      WHERE company_id = :cid_t2
        AND project_id = :pid_t2
        AND assigned_to_company_member_id IS NOT NULL
      GROUP BY assigned_to_company_member_id
    ");
    $ct->execute([':cid_t2' => $companyId, ':pid_t2' => $projectId]);
    foreach (($ct->fetchAll() ?: []) as $r) {
      $cmid = (int)($r['cmid'] ?? 0);
      $countsByMember[$cmid] = [
        'open' => (int)($r['open_cnt'] ?? 0),
        'due'  => (int)($r['due_cnt'] ?? 0),
      ];
    }
  } catch (Throwable $e) {
    if ($debug) $pageErr .= "Per-member counts: ".$e->getMessage()."\n";
  }
}

// ---- role filter ----
$roleFilter = trim((string)($_GET['role'] ?? 'all'));
$roleOptions = [];
foreach ($members as $m) {
  $rolesCsv = (string)($m['roles'] ?? '');
  $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
  foreach ($roles as $r) $roleOptions[$r] = true;
}
$roleOptions = array_keys($roleOptions);
sort($roleOptions);

if ($roleFilter !== '' && $roleFilter !== 'all') {
  $members = array_values(array_filter($members, function ($m) use ($roleFilter) {
    $rolesCsv = (string)($m['roles'] ?? '');
    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));
    return in_array($roleFilter, $roles, true);
  }));
}
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
        Admin: <?php echo h0($adminName); ?>
        <a class="logout" href="<?php echo h0(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">

      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo h0((string)$project['title']); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo h0($projectDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h0((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h0(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'team';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">

          <div class="proj-overview-head">
            <div>
              <div class="proj-h2">Members</div>
              <div class="proj-sub">All active members assigned to this project, plus their departments and task load.</div>
              <?php if (!$tasksTableExists): ?>
                <div class="proj-sub" style="margin-top:6px;">Task counts will populate once Tasks are enabled.</div>
              <?php endif; ?>

              <?php if ($debug && $pageErr !== ''): ?>
                <pre style="margin-top:10px; padding:10px; border:1px solid #f2c3c3; background:#fff5f5; color:#8a0014; border-radius:12px; white-space:pre-wrap;"><?php echo h0($pageErr); ?></pre>
              <?php endif; ?>
            </div>

            <div class="proj-search">
              <span class="proj-search-ico">⌕</span>
              <input class="proj-search-input" placeholder="Search team member" />
            </div>
          </div>

          <!-- KPI tabs -->
          <div class="team-stats">
            <a class="team-stat team-stat--link team-stat--active" href="<?php echo h0(base_url('projects/members.php?id=' . $projectId)); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">👥</div>
                <div class="team-stat-label">Members</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$membersCount); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=open')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">☑️</div>
                <div class="team-stat-label">Open tasks</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$openTasks); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=due_soon')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">📅</div>
                <div class="team-stat-label">Due soon</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$dueSoon); ?></div>
            </a>

            <a class="team-stat team-stat--link" href="<?php echo h0(base_url('projects/open_tasks.php?id=' . $projectId . '&view=overdue')); ?>">
              <div class="team-stat-left">
                <div class="team-stat-ico" aria-hidden="true">⚠️</div>
                <div class="team-stat-label">Overdue tasks</div>
              </div>
              <div class="team-stat-num"><?php echo h0((string)$overdue); ?></div>
            </a>
          </div>

          <!-- Members table -->
          <div class="card">
            <div class="members-head">
              <div class="members-col members-col--name">Member</div>

              <div class="members-col members-col--roles">
                <div style="display:flex; align-items:center; gap:10px; justify-content:flex-end;">
                  <span>Roles</span>
                  <form method="get" style="margin:0;">
                    <input type="hidden" name="id" value="<?php echo h0((string)$projectId); ?>">
                    <select name="role" onchange="this.form.submit()">
                      <option value="all" <?php echo ($roleFilter==='all')?'selected':''; ?>>All</option>
                      <?php foreach ($roleOptions as $r): ?>
                        <option value="<?php echo h0($r); ?>" <?php echo ($roleFilter===$r)?'selected':''; ?>>
                          <?php echo h0(role_label($r)); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </div>
              </div>

              <div class="members-col members-col--open">Open tasks</div>
              <div class="members-col members-col--due">Due tasks</div>
              <div class="members-col members-col--arrow"></div>
            </div>

            <?php if (!$members): ?>
              <div class="proj-empty" style="min-height:260px;">
                <div class="proj-empty-ico">👥</div>
                <div class="proj-empty-title">No members found</div>
                <div class="proj-empty-sub">Try changing the role filter or add members to this project.</div>
              </div>
            <?php else: ?>
              <div class="members-body">
                <?php foreach ($members as $m): ?>
                  <?php
                    $cmid = (int)($m['company_member_id'] ?? 0);
                    $name = (string)($m['full_name'] ?? 'Member');
                    $rolesCsv = (string)($m['roles'] ?? '');
                    $roles = array_values(array_filter(array_map('trim', explode(',', $rolesCsv))));

                    $openCnt = (int)(($countsByMember[$cmid]['open'] ?? 0));
                    $dueCnt  = (int)(($countsByMember[$cmid]['due'] ?? 0));

                    $href = base_url('projects/member.php?id=' . $projectId . '&mid=' . $cmid);
                  ?>

                  <div class="members-row members-row--click"
                       role="link"
                       tabindex="0"
                       data-member-row
                       data-href="<?php echo h0($href); ?>">
                    <div class="members-col members-col--name">
                      <div class="members-name"><?php echo h0($name); ?></div>
                    </div>

                    <div class="members-col members-col--roles">
                      <div class="member-tags">
                        <?php foreach ($roles as $r): ?>
                          <span class="tag"><?php echo h0(role_label($r)); ?></span>
                        <?php endforeach; ?>
                      </div>
                    </div>

                    <div class="members-col members-col--open">
                      <span class="pill pill--open"><?php echo h0((string)$openCnt); ?> open tasks</span>
                    </div>

                    <div class="members-col members-col--due">
                      <span class="pill pill--due"><?php echo h0((string)$dueCnt); ?> due tasks</span>
                    </div>

                    <div class="members-col members-col--arrow">›</div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        </div><!-- /proj-main -->
      </div><!-- /project-shell -->

    </div><!-- /surface -->
  </section>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const rows = Array.from(document.querySelectorAll("[data-member-row]"));
  const go = (row) => {
    const href = row.getAttribute("data-href");
    if (href) window.location.href = href;
  };
  rows.forEach((row) => {
    row.addEventListener("click", () => go(row));
    row.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        go(row);
      }
    });
  });
});
</script>

<?php include $root . '/includes/footer.php'; ?>