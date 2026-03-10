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

if (!function_exists('h0')) {
  function h0($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('first_nonempty')) {
  function first_nonempty(array $values, string $fallback = ''): string {
    foreach ($values as $v) {
      $v = trim((string)$v);
      if ($v !== '') return $v;
    }
    return $fallback;
  }
}

/* ---------- Project ---------- */
$pstmt = $pdo->prepare("
  SELECT *
  FROM projects
  WHERE id = :pid
    AND company_id = :cid
  LIMIT 1
");
$pstmt->execute([
  ':pid' => $projectId,
  ':cid' => $companyId,
]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

/* ---------- Top meta ---------- */
$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$firstEvent = null;
try {
  $evt = $pdo->prepare("
    SELECT starts_at
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC
    LIMIT 1
  ");
  $evt->execute([':pid' => $projectId]);
  $firstEvent = $evt->fetch();
} catch (Throwable $e) {}

$daysToGo = null;
$topDateLabel = 'Date TBD';
if ($firstEvent && !empty($firstEvent['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$firstEvent['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  $topDateLabel = date('F j, Y', strtotime((string)$firstEvent['starts_at']));
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') $topDateLabel = date('F j, Y', strtotime($createdAt));
}

/* ---------- Team count for sidebar ---------- */
$teamCount = 0;
try {
  $tc = $pdo->prepare("
    SELECT COUNT(DISTINCT
      CASE
        WHEN company_member_id IS NOT NULL THEN CONCAT('cm:', company_member_id)
        WHEN user_id IS NOT NULL THEN CONCAT('u:', user_id)
        WHEN email IS NOT NULL THEN CONCAT('e:', email)
        ELSE CONCAT('row:', id)
      END
    ) AS c
    FROM project_members
    WHERE project_id = :pid
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $teamCount = 0;
}

/* ---------- Optional company row ---------- */
$company = [];
try {
  $cs = $pdo->prepare("SELECT * FROM companies WHERE id = :cid LIMIT 1");
  $cs->execute([':cid' => $companyId]);
  $company = $cs->fetch() ?: [];
} catch (Throwable $e) {
  $company = [];
}

/* ---------- Lightweight meta table for contract parties ---------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS project_contract_meta (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      project_id BIGINT UNSIGNED NOT NULL,
      client1_address TEXT NULL,
      client2_address TEXT NULL,
      vendor_company_name VARCHAR(191) NULL,
      vendor_lead_name VARCHAR(191) NULL,
      vendor_email VARCHAR(191) NULL,
      vendor_phone VARCHAR(64) NULL,
      vendor_address TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_project_contract_meta_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
} catch (Throwable $e) {
  // fail soft
}

$meta = [
  'client1_address' => '',
  'client2_address' => '',
  'vendor_company_name' => '',
  'vendor_lead_name' => '',
  'vendor_email' => '',
  'vendor_phone' => '',
  'vendor_address' => '',
];

try {
  $ms = $pdo->prepare("
    SELECT *
    FROM project_contract_meta
    WHERE project_id = :pid
    LIMIT 1
  ");
  $ms->execute([':pid' => $projectId]);
  $mrow = $ms->fetch();
  if ($mrow) {
    foreach ($meta as $k => $_) {
      $meta[$k] = trim((string)($mrow[$k] ?? ''));
    }
  }
} catch (Throwable $e) {}

/* ---------- Defaults ---------- */
$val_client1_name = trim((string)($project['partner1_name'] ?? ''));
$val_client1_phone = trim((string)($project['phone1'] ?? ''));
$val_client1_email = trim((string)($project['email1'] ?? ''));
$val_client1_address = $meta['client1_address'];

$val_client2_name = trim((string)($project['partner2_name'] ?? ''));
$val_client2_phone = trim((string)($project['phone2'] ?? ''));
$val_client2_email = trim((string)($project['email2'] ?? ''));
$val_client2_address = $meta['client2_address'];

$val_vendor_company_name = first_nonempty([
  $meta['vendor_company_name'],
  $company['legal_name'] ?? '',
  $company['business_name'] ?? '',
  $company['company_name'] ?? '',
  $company['name'] ?? '',
  $_SESSION['company_name'] ?? '',
], 'Your company');

$val_vendor_lead_name = first_nonempty([
  $meta['vendor_lead_name'],
  $company['owner_name'] ?? '',
  $company['contact_person'] ?? '',
  $_SESSION['full_name'] ?? '',
], $adminName);

$val_vendor_email = first_nonempty([
  $meta['vendor_email'],
  $company['email'] ?? '',
  $_SESSION['email'] ?? '',
], '');

$val_vendor_phone = first_nonempty([
  $meta['vendor_phone'],
  $company['phone'] ?? '',
  $company['phone1'] ?? '',
], '');

$val_vendor_address = first_nonempty([
  $meta['vendor_address'],
  $company['address'] ?? '',
  $company['office_address'] ?? '',
], '');

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? 'save'));
  if ($action === 'discard') {
    redirect('projects/contract.php?id=' . $projectId);
  }

  $val_client1_name = trim((string)($_POST['client1_name'] ?? ''));
  $val_client1_phone = trim((string)($_POST['client1_phone'] ?? ''));
  $val_client1_email = trim((string)($_POST['client1_email'] ?? ''));
  $val_client1_address = trim((string)($_POST['client1_address'] ?? ''));

  $val_client2_name = trim((string)($_POST['client2_name'] ?? ''));
  $val_client2_phone = trim((string)($_POST['client2_phone'] ?? ''));
  $val_client2_email = trim((string)($_POST['client2_email'] ?? ''));
  $val_client2_address = trim((string)($_POST['client2_address'] ?? ''));

  $val_vendor_company_name = trim((string)($_POST['vendor_company_name'] ?? ''));
  $val_vendor_lead_name = trim((string)($_POST['vendor_lead_name'] ?? ''));
  $val_vendor_email = trim((string)($_POST['vendor_email'] ?? ''));
  $val_vendor_phone = trim((string)($_POST['vendor_phone'] ?? ''));
  $val_vendor_address = trim((string)($_POST['vendor_address'] ?? ''));

  try {
    $pdo->beginTransaction();

    $up = $pdo->prepare("
      UPDATE projects
      SET
        partner1_name = :p1n,
        phone1 = :p1p,
        email1 = :p1e,
        partner2_name = :p2n,
        phone2 = :p2p,
        email2 = :p2e,
        updated_at = NOW()
      WHERE id = :pid
        AND company_id = :cid
    ");
    $up->execute([
      ':p1n' => $val_client1_name,
      ':p1p' => $val_client1_phone,
      ':p1e' => $val_client1_email,
      ':p2n' => $val_client2_name,
      ':p2p' => $val_client2_phone,
      ':p2e' => $val_client2_email,
      ':pid' => $projectId,
      ':cid' => $companyId,
    ]);

    try {
      $um = $pdo->prepare("
        UPDATE project_contract_meta
        SET
          client1_address = :c1a,
          client2_address = :c2a,
          vendor_company_name = :vcn,
          vendor_lead_name = :vln,
          vendor_email = :ve,
          vendor_phone = :vp,
          vendor_address = :va,
          updated_at = NOW()
        WHERE project_id = :pid
      ");
      $um->execute([
        ':c1a' => $val_client1_address,
        ':c2a' => $val_client2_address,
        ':vcn' => $val_vendor_company_name,
        ':vln' => $val_vendor_lead_name,
        ':ve'  => $val_vendor_email,
        ':vp'  => $val_vendor_phone,
        ':va'  => $val_vendor_address,
        ':pid' => $projectId,
      ]);

      if ($um->rowCount() === 0) {
        $im = $pdo->prepare("
          INSERT INTO project_contract_meta (
            project_id,
            client1_address,
            client2_address,
            vendor_company_name,
            vendor_lead_name,
            vendor_email,
            vendor_phone,
            vendor_address,
            created_at,
            updated_at
          ) VALUES (
            :pid,
            :c1a,
            :c2a,
            :vcn,
            :vln,
            :ve,
            :vp,
            :va,
            NOW(),
            NOW()
          )
        ");
        $im->execute([
          ':pid' => $projectId,
          ':c1a' => $val_client1_address,
          ':c2a' => $val_client2_address,
          ':vcn' => $val_vendor_company_name,
          ':vln' => $val_vendor_lead_name,
          ':ve'  => $val_vendor_email,
          ':vp'  => $val_vendor_phone,
          ':va'  => $val_vendor_address,
        ]);
      }
    } catch (Throwable $e) {
      // fail soft
    }

    $pdo->commit();
    flash_set('success', 'Parties & contacts saved.');

    audit_log([
      'pdo' => $pdo,
      'company_id' => $companyId,
      'project_id' => $projectId,
      'actor_user_id' => (int)($_SESSION['user_id'] ?? 0),
      'actor_name' => trim((string)($_SESSION['full_name'] ?? '')) ?: 'Admin',
      'entity_type' => 'contract',
      'entity_id' => $projectId,
      'action' => 'updated',
      'summary' => 'Updated contract: Parties & contacts',
      'search_text' => audit_build_search_text([
        'contract',
        'parties contacts',
        'updated',
        (string)($project['title'] ?? ''),
      ]),
    ]);

    redirect('projects/contract.php?id=' . $projectId);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', 'Could not save parties & contacts. ' . $e->getMessage());
    redirect('projects/contract_parties_contacts.php?id=' . $projectId);
  }
}

$pageTitle = (string)($project['title'] ?? 'Project') . ' — Parties & contacts';
require_once $root . '/includes/header.php';
?>

<style>
.breadcrumb{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;color:rgba(0,0,0,0.55);}
.breadcrumb a{text-decoration:none;color:rgba(0,0,0,0.55);}
.breadcrumb .sep{opacity:.55;}
.subhead{margin-top:6px;color:var(--muted);font-size:13px;}
.top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.icon-btn{width:38px;height:38px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;}
.form-shell{margin-top:14px;display:flex;flex-direction:column;gap:14px;}
.grid-two{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media (max-width:1100px){.grid-two{grid-template-columns:1fr;}}
.card-headline{color:rgba(0,0,0,0.55);font-weight:900;font-size:14px;}
.card-sub{color:var(--muted);font-size:12px;margin-top:4px;}
.hr{height:1px;background:rgba(0,0,0,0.06);margin:12px 0;}
label.small{display:block;font-size:12px;color:var(--muted);margin-bottom:6px;}
.input-soft,.textarea-soft{
  width:100%;
  padding:12px 14px;
  border-radius:999px;
  border:1px solid transparent;
  background:rgba(0,0,0,0.04);
  font-size:13px;
  outline:none;
}
.textarea-soft{
  border-radius:18px;
  min-height:84px;
  resize:vertical;
}
.input-soft:focus,.textarea-soft:focus{
  background:#fff;
  border-color:rgba(0,0,0,0.14);
}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media (max-width:900px){.field-row{grid-template-columns:1fr;}}
.field-stack{display:flex;flex-direction:column;gap:10px;}
</style>

<div class="app-shell">
  <?php $nav_active = 'projects'; require_once $root . '/includes/sidebar.php'; ?>

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
              <span class="proj-meta-item"><?php echo h0($topDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo h0((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h0(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h0(base_url('projects/show.php?id=' . $projectId)); ?>" title="Project overview">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php $active = 'contract'; $contractSection = 'parties_contacts'; require_once $root . '/includes/project_sidebar.php'; ?>

        <div class="proj-main">
          <div class="proj-overview-head">
            <div>
              <div class="breadcrumb">
                <a href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract &amp; scope</a>
                <span class="sep">›</span>
                <span>Parties &amp; contacts</span>
              </div>
              <div class="subhead">Confirm who the agreement is between and where we should send it for approval.</div>
            </div>

            <div class="top-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn btn-primary" type="submit" form="partiesForm">Save changes</button>
              <a class="btn" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>">Discard changes</a>
            </div>
          </div>

          <form id="partiesForm" method="post" action="">
            <input type="hidden" name="action" value="save">

            <div class="form-shell">
              <div class="grid-two">
                <div class="card proj-card">
                  <div class="card-headline">Client contact 1</div>
                  <div class="card-sub">Track signing progress and key parties.</div>
                  <div class="hr"></div>

                  <div class="field-stack">
                    <div>
                      <label class="small">Partner 1</label>
                      <input class="input-soft" type="text" name="client1_name" value="<?php echo h0($val_client1_name); ?>">
                    </div>

                    <div>
                      <label class="small">Phone number 1</label>
                      <input class="input-soft" type="text" name="client1_phone" value="<?php echo h0($val_client1_phone); ?>">
                    </div>

                    <div>
                      <label class="small">Email address</label>
                      <input class="input-soft" type="text" name="client1_email" value="<?php echo h0($val_client1_email); ?>">
                    </div>

                    <div>
                      <label class="small">Address</label>
                      <input class="input-soft" type="text" name="client1_address" value="<?php echo h0($val_client1_address); ?>" placeholder="Street, City, State, PIN">
                    </div>
                  </div>
                </div>

                <div class="card proj-card">
                  <div class="card-headline">Client contact 2</div>
                  <div class="card-sub">Track signing progress and key parties.</div>
                  <div class="hr"></div>

                  <div class="field-stack">
                    <div>
                      <label class="small">Partner 2</label>
                      <input class="input-soft" type="text" name="client2_name" value="<?php echo h0($val_client2_name); ?>">
                    </div>

                    <div>
                      <label class="small">Phone number 2</label>
                      <input class="input-soft" type="text" name="client2_phone" value="<?php echo h0($val_client2_phone); ?>">
                    </div>

                    <div>
                      <label class="small">Email address</label>
                      <input class="input-soft" type="text" name="client2_email" value="<?php echo h0($val_client2_email); ?>">
                    </div>

                    <div>
                      <label class="small">Address</label>
                      <input class="input-soft" type="text" name="client2_address" value="<?php echo h0($val_client2_address); ?>" placeholder="Street, City, State, PIN">
                    </div>
                  </div>
                </div>
              </div>

              <div class="card proj-card">
                <div class="card-headline">Service provider (Your company)</div>
                <div class="card-sub">Your company details as shown on the contract.</div>
                <div class="hr"></div>

                <div class="field-stack">
                  <div>
                    <label class="small">Company name (Legal/business name)</label>
                    <input class="input-soft" type="text" name="vendor_company_name" value="<?php echo h0($val_vendor_company_name); ?>">
                  </div>

                  <div>
                    <label class="small">Project lead name (Shown as “Prepared by” / point of contact)</label>
                    <input class="input-soft" type="text" name="vendor_lead_name" value="<?php echo h0($val_vendor_lead_name); ?>">
                  </div>

                  <div class="field-row">
                    <div>
                      <label class="small">Email address</label>
                      <input class="input-soft" type="text" name="vendor_email" value="<?php echo h0($val_vendor_email); ?>">
                    </div>

                    <div>
                      <label class="small">Phone number 1</label>
                      <input class="input-soft" type="text" name="vendor_phone" value="<?php echo h0($val_vendor_phone); ?>">
                    </div>
                  </div>

                  <div>
                    <label class="small">Company address (for contract)</label>
                    <input class="input-soft" type="text" name="vendor_address" value="<?php echo h0($val_vendor_address); ?>" placeholder="Office address">
                  </div>
                </div>
              </div>
            </div>
          </form>

        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>