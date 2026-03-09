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

function table_exists_local(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = :table
    ");
    $st->execute([':table' => $table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function side_label(string $side): string {
  return match ($side) {
    'bride' => "Bride's side",
    'groom' => "Groom's side",
    'both'  => "Both families",
    default => '—',
  };
}

function guest_tags_from_row(array $row): array {
  $tags = [];

  $accessibility = trim((string)($row['accessibility'] ?? ''));
  $diet = trim((string)($row['diet_preference'] ?? ''));
  $plusOne = (int)($row['plus_one_allowed'] ?? 0);

  if ($accessibility === 'elder_care') $tags[] = 'Elder';
  if ($accessibility === 'wheelchair') $tags[] = 'Assist';
  if ($accessibility === 'medical') $tags[] = 'Medical';
  if ($accessibility === 'toddler_care') $tags[] = 'Toddler care';
  if ($diet === 'jain') $tags[] = 'Jain';
  if ($diet === 'vegan') $tags[] = 'Vegan';
  if ($plusOne === 1) $tags[] = 'Plus-one';

  return array_values(array_unique($tags));
}

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

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));
$projectTitle = trim((string)($project['title'] ?? ''));

if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

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

$projectDateLabel = $topDateLabel;

$teamCount = 0;
try {
  $tc = $pdo->prepare("
    SELECT COUNT(*)
    FROM project_members
    WHERE project_id = :pid
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $teamCount = 0;
}

$guestTableExists = table_exists_local($pdo, 'guests');
$searchQ = trim((string)($_GET['q'] ?? ''));

$guestRows = [];
$guestCountRows = 0;
$guestHeadCountTotal = 0;
$guestAdultCount = 0;
$guestChildrenCount = 0;
$missingPhoneCount = 0;
$missingEmailCount = 0;
$ungroupedCount = 0;
$groupsCreatedCount = 0;
$careTagCount = 0;
$duplicateNames = [];

if ($guestTableExists) {
  $sql = "
    SELECT *
    FROM guests
    WHERE project_id = :pid
  ";
  $params = [':pid' => $projectId];

  if ($searchQ !== '') {
    $sql .= "
      AND (
        CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE :q
        OR phone LIKE :q
        OR email LIKE :q
        OR relation_label LIKE :q
      )
    ";
    $params[':q'] = '%' . $searchQ . '%';
  }

  $sql .= " ORDER BY created_at DESC, id DESC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $guestRows = $st->fetchAll() ?: [];

  $groupMap = [];
  $nameMap = [];

  foreach ($guestRows as $row) {
    $seatCount = max(1, (int)($row['seat_count'] ?? 1));
    $childrenCount = max(0, (int)($row['children_count'] ?? 0));

    $guestHeadCountTotal += $seatCount;
    $guestChildrenCount += $childrenCount;
    $guestAdultCount += max($seatCount - $childrenCount, 0);

    $phone = trim((string)($row['phone'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $group = trim((string)($row['relation_label'] ?? ''));

    if ($phone === '') $missingPhoneCount++;
    if ($email === '') $missingEmailCount++;
    if ($group === '') $ungroupedCount++;

    if ($group !== '') {
      $groupMap[strtolower($group)] = $group;
    }

    $accessibility = trim((string)($row['accessibility'] ?? ''));
    if (in_array($accessibility, ['elder_care', 'wheelchair', 'medical', 'toddler_care'], true)) {
      $careTagCount++;
    }

    $fullName = strtolower(trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')));
    if ($fullName !== '') {
      $nameMap[$fullName] = ($nameMap[$fullName] ?? 0) + 1;
    }
  }

  foreach ($nameMap as $name => $count) {
    if ($count > 1) {
      $duplicateNames[$name] = $count;
    }
  }

  $groupsCreatedCount = count($groupMap);
  $guestCountRows = count($guestRows);
}

$projectBriefEstimate = (int)($project['guest_count_est'] ?? 0);
$hasGuests = $guestCountRows > 0;

$overviewTotalLabel = $hasGuests
  ? number_format($guestHeadCountTotal)
  : ($projectBriefEstimate > 0 ? number_format($projectBriefEstimate) : '—');

$overviewAdultsLabel   = $hasGuests ? number_format($guestAdultCount) : '—';
$overviewChildrenLabel = $hasGuests ? number_format($guestChildrenCount) : '—';
$missingPhoneLabel     = $hasGuests ? number_format($missingPhoneCount) : '—';
$missingEmailLabel     = $hasGuests ? number_format($missingEmailCount) : '—';

$pageTitle = $projectTitle . ' — Guest list setup — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.proj-main{
  min-width:0;
}

.guest-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:16px;
}
.guest-head .left h2{
  margin:0;
  font-size:22px;
  line-height:1.15;
  font-weight:800;
  color:#1d1d1f;
}
.guest-head .left p{
  margin:8px 0 0 0;
  color:#6f6f73;
  font-size:13px;
  line-height:1.45;
}
.guest-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:12px;
  font-size:11px;
  padding:6px 10px;
  border:1px solid rgba(0,0,0,0.06);
  border-radius:999px;
  background:#fff;
  color:#4b4b4f;
  vertical-align:middle;
}
.guest-actions{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.guest-actions .icon-btn{
  width:42px;
  height:42px;
  min-width:42px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border-radius:999px;
  padding:0;
}
.btn[disabled]{
  opacity:.55;
  cursor:not-allowed;
  pointer-events:none;
}

/* Top 3 cards */
.guest-grid{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,.95fr) minmax(290px,.92fr);
  gap:14px;
  align-items:start;
}
@media (max-width:1180px){
  .guest-grid{
    grid-template-columns:1fr;
  }
}

.guest-panel{
  display:flex;
  flex-direction:column;
  padding:16px;
  border-radius:22px;
}
.guest-panel--compact{
  min-height:182px;
}
.guest-panel--health{
  padding:16px;
}

.guest-panel-title{
  margin:0;
  font-size:18px;
  line-height:1.2;
  font-weight:800;
  color:#222;
}
.guest-panel-sub{
  margin:6px 0 0 0;
  color:#75757a;
  font-size:13px;
  line-height:1.45;
}
.guest-file-list{
  margin:14px 0 0 0;
  padding-left:18px;
  color:#222;
  font-size:13px;
  line-height:1.65;
}
.helper-soft{
  margin-top:14px;
  padding:14px 16px;
  border-radius:18px;
  background:#f7f7f8;
  border:1px solid rgba(0,0,0,0.03);
  color:#6d6d72;
  font-size:13px;
  line-height:1.55;
}
.soft-stat{
  margin-top:14px;
  padding:12px 14px;
  border:1px solid rgba(0,0,0,0.04);
  border-radius:18px;
  background:#f7f7f8;
  font-size:13px;
  color:#2c2c2f;
}
.card-actions-end{
  margin-top:auto;
  padding-top:16px;
  display:flex;
  justify-content:flex-end;
  align-items:flex-end;
}

/* Health card */
.health-wrap{
  display:flex;
  flex-direction:column;
  gap:2px;
  margin-top:10px;
}
.health-group{
  border-top:1px solid rgba(0,0,0,0.06);
  padding-top:10px;
}
.health-group:first-child{
  border-top:none;
  padding-top:0;
}
.health-group summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-size:13px;
  font-weight:800;
  color:#222;
  padding:4px 0 8px;
}
.health-group summary::-webkit-details-marker{
  display:none;
}
.health-group summary::after{
  content:'⌄';
  color:#8a8a90;
  font-weight:400;
}
.health-group[open] summary::after{
  content:'⌃';
}
.health-list{
  display:flex;
  flex-direction:column;
  gap:10px;
  padding:2px 0 4px 0;
}
.health-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:12px;
  align-items:start;
  font-size:13px;
  color:#2a2a2d;
}
.health-row .label{
  color:#5d5d63;
}
.health-row .value{
  color:#3a3a40;
  font-weight:700;
}
.subtle-note{
  margin-top:8px;
  color:#7b7b82;
  font-size:12px;
}

/* Search + table */
.guest-toolbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-top:14px;
  margin-bottom:10px;
  flex-wrap:wrap;
}
.guest-search{
  flex:1 1 360px;
}
.guest-search-wrap{
  position:relative;
}
.guest-search-wrap .search-ico{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#9a9aa1;
  font-size:13px;
  pointer-events:none;
}
.guest-search input{
  width:100%;
  min-height:40px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.08);
  background:#fff;
  padding:10px 14px 10px 36px;
  box-sizing:border-box;
  color:#1f1f22;
}
.guest-search input::placeholder{
  color:#9b9ba2;
}
.table-select-btn{
  white-space:nowrap;
}

.guest-table-card{
  padding:6px 12px 8px;
  border-radius:24px;
}
.guest-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.guest-table thead th{
  text-align:left;
  padding:14px 16px 12px;
  font-size:12px;
  color:#9a9aa1;
  font-weight:700;
  border-bottom:1px solid rgba(0,0,0,0.06);
}
.guest-table tbody td{
  text-align:left;
  padding:14px 16px;
  border-bottom:1px solid rgba(0,0,0,0.05);
  vertical-align:middle;
  font-size:14px;
  color:#1f1f22;
}
.guest-table tbody tr:last-child td{
  border-bottom:none;
}
.guest-name{
  font-weight:500;
  color:#1d1d1f;
}
.guest-side{
  color:#4f4f55;
  font-size:13px;
}

.table-chip{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:28px;
  padding:0 12px;
  border-radius:999px;
  font-size:12px;
  white-space:nowrap;
  border:none;
}
.table-chip.ok{
  background:#dff1cf;
  color:#54733e;
}
.table-chip.warn{
  background:#f4cccc;
  color:#875050;
}
.table-chip.neutral{
  background:#efefef;
  color:#7b7b82;
}
.tag-stack{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.empty-table{
  padding:16px;
  border-radius:18px;
  background:rgba(0,0,0,0.02);
  color:#75757a;
  font-size:13px;
  line-height:1.5;
}
.guest-tip{
  margin-top:14px;
  padding:14px 16px;
  border-radius:18px;
  border:1px dashed rgba(0,0,0,0.10);
  background:#fff;
  color:#727279;
  font-size:13px;
  line-height:1.55;
}

@media (max-width:980px){
  .guest-head{
    flex-direction:column;
    align-items:flex-start;
  }
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
            <div class="card proj-card guest-panel guest-panel--compact">
              <h3 class="guest-panel-title">Import guest list</h3>
              <div class="guest-panel-sub">Upload the client’s Excel or CSV and organize it before invites go out.</div>

              <ul class="guest-file-list">
                <li>Expected sheet: Groom’s side guest list</li>
                <li>Expected sheet: Bride’s side guest list</li>
              </ul>

              <?php if ($hasGuests): ?>
                <div class="soft-stat">
                  <?php echo esc(number_format($guestCountRows)); ?> guest records currently in this project.
                </div>
              <?php else: ?>
                <div class="helper-soft">
                  No files attached yet. Start with the client’s master sheet or upload separate bride-side and groom-side lists.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
                <button class="btn" type="button">Upload file</button>
              </div>
            </div>

            <div class="card proj-card guest-panel guest-panel--compact">
              <h3 class="guest-panel-title">Manually add guests</h3>
              <div class="guest-panel-sub">Fill guest details manually to add to the guest list.</div>

              <?php if ($hasGuests): ?>
                <div class="helper-soft">
                  Manual guest entry is active. Add individuals, families, and last-minute guests here.
                </div>
              <?php else: ?>
                <div class="helper-soft">
                  No guests added yet. Use this when the client shares a few names first or when you need to add last-minute guests manually.
                </div>
              <?php endif; ?>

              <div class="card-actions-end">
                <a class="btn" href="<?php echo esc(base_url('guests/create.php?project_id=' . $projectId)); ?>">Add guest</a>
              </div>
            </div>

            <div class="card proj-card guest-panel guest-panel--health">
              <h3 class="guest-panel-title">Guest list health</h3>
              <div class="guest-panel-sub">What needs cleaning before invites go out.</div>

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
                      <div class="value"><?php echo esc($overviewAdultsLabel); ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Estimated head count (children)</div>
                      <div class="value"><?php echo esc($overviewChildrenLabel); ?></div>
                    </div>
                  </div>
                  <?php if ($projectBriefEstimate > 0): ?>
                    <div class="subtle-note">Project brief estimate: <?php echo esc(number_format($projectBriefEstimate)); ?></div>
                  <?php endif; ?>
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
                      <div class="value"><?php echo $hasGuests ? esc((string)$ungroupedCount) : '—'; ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">VIP / Elder care tags</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$careTagCount) : '—'; ?></div>
                    </div>
                  </div>
                </details>

                <details class="health-group" open>
                  <summary>Duplicate review</summary>
                  <div class="health-list">
                    <?php if ($duplicateNames): ?>
                      <?php foreach ($duplicateNames as $name => $count): ?>
                        <div class="health-row">
                          <div class="label"><?php echo esc(ucwords($name)); ?></div>
                          <div class="value"><?php echo esc((string)$count); ?></div>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="health-row">
                        <div class="label">No duplicate names flagged yet</div>
                        <div class="value">0</div>
                      </div>
                    <?php endif; ?>
                  </div>
                </details>

                <details class="health-group" open>
                  <summary>Guest groups</summary>
                  <div class="health-list">
                    <div class="health-row">
                      <div class="label">Groups created</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$groupsCreatedCount) : '—'; ?></div>
                    </div>
                    <div class="health-row">
                      <div class="label">Ungrouped</div>
                      <div class="value"><?php echo $hasGuests ? esc((string)$ungroupedCount) : '—'; ?></div>
                    </div>
                  </div>
                </details>
              </div>
            </div>
          </div>

          <div class="guest-toolbar">
            <form class="guest-search" method="get">
              <input type="hidden" name="project_id" value="<?php echo esc((string)$projectId); ?>">
              <div class="guest-search-wrap">
                <span class="search-ico">🔍</span>
                <input type="text" name="q" value="<?php echo esc($searchQ); ?>" placeholder="Search guest name, contact, or group">
              </div>
            </form>

            <button class="btn table-select-btn" type="button">✓ Select all</button>
          </div>

          <div class="card proj-card guest-table-card">
            <?php if ($hasGuests): ?>
              <table class="guest-table">
                <thead>
                  <tr>
                    <th>Guest name</th>
                    <th>Side</th>
                    <th>Contact</th>
                    <th>Group</th>
                    <th>Tag</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($guestRows as $row): ?>
                    <?php
                      $fullName = trim(
                        trim((string)($row['title'] ?? '')) . ' ' .
                        trim((string)($row['first_name'] ?? '')) . ' ' .
                        trim((string)($row['last_name'] ?? ''))
                      );

                      $phone = trim((string)($row['phone'] ?? ''));
                      $email = trim((string)($row['email'] ?? ''));
                      $group = trim((string)($row['relation_label'] ?? ''));
                      $tags  = guest_tags_from_row($row);

                      if ($phone !== '' && $email !== '') {
                        $contactLabel = 'Phone + email';
                        $contactTone = 'ok';
                      } elseif ($phone !== '') {
                        $contactLabel = 'Phone no.';
                        $contactTone = 'ok';
                      } elseif ($email !== '') {
                        $contactLabel = 'Email';
                        $contactTone = 'ok';
                      } else {
                        $contactLabel = 'Missing';
                        $contactTone = 'warn';
                      }
                    ?>
                    <tr>
                      <td class="guest-name"><?php echo esc($fullName !== '' ? $fullName : 'Unnamed guest'); ?></td>
                      <td class="guest-side"><?php echo esc(side_label((string)($row['invited_by'] ?? ''))); ?></td>
                      <td>
                        <span class="table-chip <?php echo esc($contactTone); ?>">
                          <?php echo esc($contactLabel); ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($group !== ''): ?>
                          <span class="table-chip ok"><?php echo esc($group); ?></span>
                        <?php else: ?>
                          <span class="table-chip neutral">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($tags): ?>
                          <div class="tag-stack">
                            <?php foreach ($tags as $tag): ?>
                              <span class="table-chip ok"><?php echo esc($tag); ?></span>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <span class="table-chip neutral">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <div class="empty-table">
                No guests saved yet. Once you save the first guest from the manual form, the table will appear here.
              </div>
            <?php endif; ?>
          </div>

          <?php if (!$hasGuests): ?>
            <div class="guest-tip">
              This is the empty state for the guest workflow. After the first guest is saved, this page becomes the working guest list with search, counts, and cleanup checks.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>