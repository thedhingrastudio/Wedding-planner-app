<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['project_id'] ?? $_GET['id'] ?? 0);
if ($projectId <= 0) {
  redirect('projects/index.php');
}

$companyId = current_company_id();

function esc($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE :table");
    $st->execute([':table' => $table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function safe_count(PDO $pdo, string $sql, array $params = []): int {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
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

if (!$project) {
  redirect('projects/index.php');
}

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
} catch (Throwable $e) {
  $firstEvent = null;
}

$daysToGo = null;
$topDateLabel = 'Date TBD';

if ($firstEvent && !empty($firstEvent['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$firstEvent['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
  $topDateLabel = date('F j, Y', strtotime((string)$firstEvent['starts_at']));
} else {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') {
    $topDateLabel = date('F j, Y', strtotime($createdAt));
  }
}

/* keep project sidebar happy */
$projectDateLabel = $topDateLabel;

/* ---------- Team count ---------- */
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

/* ---------- Guests summary ---------- */
$guestTableExists = table_exists($pdo, 'guests');

$guestCountTotal = 0;
$missingPhoneCount = 0;
$missingEmailCount = 0;

if ($guestTableExists) {
  $guestCountTotal = safe_count(
    $pdo,
    "SELECT COUNT(*) FROM guests WHERE project_id = :pid",
    [':pid' => $projectId]
  );

  if ($guestCountTotal > 0) {
    $missingPhoneCount = safe_count(
      $pdo,
      "SELECT COUNT(*) FROM guests WHERE project_id = :pid AND (phone IS NULL OR TRIM(phone) = '')",
      [':pid' => $projectId]
    );

    $missingEmailCount = safe_count(
      $pdo,
      "SELECT COUNT(*) FROM guests WHERE project_id = :pid AND (email IS NULL OR TRIM(email) = '')",
      [':pid' => $projectId]
    );
  }
}

$hasGuests = $guestCountTotal > 0;

$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));

$projectTitle = trim((string)($project['title'] ?? 'Wedding project'));
if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

$overviewTotalLabel = $hasGuests ? number_format($guestCountTotal) : '—';
$missingPhoneLabel = $hasGuests ? number_format($missingPhoneCount) : '—';
$missingEmailLabel = $hasGuests ? number_format($missingEmailCount) : '—';

$pageTitle = $projectTitle . ' — Guest list setup — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.guest-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  margin-bottom:14px;
}
.guest-head .left h2{
  margin:0;
  font-size:22px;
  font-weight:800;
}
.guest-head .left p{
  margin:6px 0 0 0;
  color:var(--muted);
  font-size:13px;
}
.guest-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:10px;
  font-size:12px;
  padding:6px 10px;
  border:1px solid var(--border);
  border-radius:999px;
  background:#fff;
  color:#222;
  vertical-align:middle;
}
.guest-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.guest-actions .icon-btn{
  width:38px;
  height:38px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
}
.btn[disabled]{
  opacity:.55;
  cursor:not-allowed;
  pointer-events:none;
}
.guest-grid{
  display:grid;
  grid-template-columns:1.15fr 1.05fr 1.15fr;
  gap:14px;
  align-items:start;
}
@media (max-width:1100px){
  .guest-grid{
    grid-template-columns:1fr;
  }
}
.guest-note{
  margin-top:10px;
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.guest-file-list{
  margin:12px 0 0 0;
  padding-left:18px;
  color:#222;
  font-size:13px;
  line-height:1.6;
}
.card-actions-end{
  display:flex;
  justify-content:flex-end;
  margin-top:16px;
}
.soft-stat{
  margin-top:14px;
  padding:12px 14px;
  border:1px solid rgba(0,0,0,0.06);
  border-radius:16px;
  background:rgba(0,0,0,0.02);
  font-size:13px;
  color:#222;
}
.helper-empty{
  margin-top:12px;
  padding:12px 14px;
  border-radius:16px;
  background:rgba(0,0,0,0.03);
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.health-wrap{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:10px;
}
.health-group{
  border-top:1px solid rgba(0,0,0,0.06);
  padding-top:8px;
}
.health-group summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-size:13px;
  font-weight:700;
  color:#222;
  padding:4px 0 8px;
}
.health-group summary::-webkit-details-marker{
  display:none;
}
.health-group summary::after{
  content:'⌄';
  color:var(--muted);
  font-weight:400;
}
.health-group[open] summary::after{
  content:'⌃';
}
.health-list{
  display:flex;
  flex-direction:column;
  gap:8px;
  padding:2px 0 2px 0;
}
.health-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:10px;
  align-items:start;
  font-size:13px;
  color:#222;
}
.health-row .label{
  color:#333;
}
.health-row .value{
  color:var(--muted);
  font-weight:700;
}
.guest-tip{
  margin-top:14px;
  padding:14px 16px;
  border-radius:18px;
  border:1px dashed rgba(0,0,0,0.12);
  background:#fff;
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}
</style>

<div class="app-shell">
  <?php
    $nav_active = 'projects';
    require_once $root . '/includes/sidebar.php';
  ?>

  <section class="app-main">
    <div class="topbar">
      <div></div>
      <div class="user-pill">
        Admin: <?php echo esc($adminName); ?>
        <a class="logout" href="<?php echo esc(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <div class="surface">
      <div class="proj-top">
        <div class="proj-top-left">
          <div class="proj-icon">💍</div>
          <div>
            <div class="proj-name"><?php echo esc($projectTitle); ?></div>
            <div class="proj-meta">
              <span class="proj-meta-item">📅 <?php echo esc($topDateLabel); ?></span>
              <?php if ($daysToGo !== null): ?>
                <span class="proj-meta-item">• <?php echo esc((string)$daysToGo); ?> days to go</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="proj-top-actions">
          <a class="btn btn-primary" href="<?php echo esc(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo esc(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo esc(base_url('projects/contract.php?id=' . $projectId)); ?>" title="Contract & scope">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php
          $active = 'guests';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <div class="guest-head">
            <div class="left">
              <h2>
                Guest list setup
                <span class="guest-badge">In progress</span>
              </h2>
              <p>Import and clean the master list before invites go out.</p>
            </div>

            <div class="guest-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn icon-btn" type="button" title="Save">💾</button>
              <button class="btn" type="button" <?php echo !$hasGuests ? 'disabled' : ''; ?>>👁 Preview guest list</button>
              <button class="btn btn-primary" type="button" <?php echo !$hasGuests ? 'disabled' : ''; ?>>☆ Send invites</button>
            </div>
          </div>

          <div class="guest-grid">

            <div class="card proj-card">
              <div class="proj-card-title">Import guest list</div>
              <div class="proj-card-sub">Upload the client’s Excel or CSV and organize it before invites go out.</div>

              <ul class="guest-file-list">
                <li>Expected sheet: Groom’s side guest list</li>
                <li>Expected sheet: Bride’s side guest list</li>
              </ul>

              <?php if ($hasGuests): ?>
                <div class="soft-stat">
                  <?php echo esc(number_format($guestCountTotal)); ?> guests currently in this project.
                </div>
              <?php else: ?>
                <div class="helper-empty">
                  No files attached yet. Start with the client’s master sheet or upload separate bride-side and groom-side lists.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
                <button class="btn" type="button">Upload file</button>
              </div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Manually add guests</div>
              <div class="proj-card-sub">Fill guest details manually to add to the guest list.</div>

              <?php if ($hasGuests): ?>
                <div class="soft-stat">
                  Guest list started. You can keep adding individuals, families, and plus-ones here.
                </div>
              <?php else: ?>
                <div class="helper-empty">
                  No guests added yet. Use this when the client shares a few names first or when you need to add last-minute guests manually.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
  <a class="btn" href="<?php echo esc(base_url('guests/create.php?project_id=' . $projectId)); ?>">Add guest</a>
</div>
            </div>

            <div class="card proj-card">
              <div class="proj-card-title">Guest list health</div>
              <div class="proj-card-sub">What needs cleaning before invites go out.</div>

              <div class="health-wrap">
                <details class="health-group" open>
                  <summary>Guest overview</summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Estimated head count (total)</div>
                      <div class="value"><?php echo esc($overviewTotalLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Estimated head count (adults)</div>
                      <div class="value">—</div>
                    </div>
                    <div class="health-row">
                      <div class="label">Estimated head count (children)</div>
                      <div class="value">—</div>
                    </div>
                  </div>
                </details>

                <details class="health-group" open>
                  <summary>Missing contacts</summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Missing phone number</div>
                      <div class="value"><?php echo esc($missingPhoneLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Missing emails</div>
                      <div class="value"><?php echo esc($missingEmailLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Unassigned groups</div>
                      <div class="value">—</div>
                    </div>
                    <div class="health-row">
                      <div class="label">VIP / Elder care tags</div>
                      <div class="value">—</div>
                    </div>
                  </div>
                </details>

                <details class="health-group">
                  <summary>Duplicate review</summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Potential duplicates</div>
                      <div class="value">—</div>
                    </div>
                  </div>
                </details>

                <details class="health-group">
                  <summary>Guest groups</summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Family or household groups</div>
                      <div class="value">—</div>
                    </div>
                  </div>
                </details>
              </div>
            </div>

          </div>

          <?php if (!$hasGuests): ?>
            <div class="guest-tip">
              This is the empty state for the guest workflow. Once the first guest import is wired up, this page can become the starting point for duplicate review, tags, group assignment, and export.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>