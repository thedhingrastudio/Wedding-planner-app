<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$companyId = current_company_id();

// Safe escape helper (your includes/functions.php already has h(), but many pages also use a local helper)
function h0($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Load project (company-safe)
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id AND company_id = :cid");
$pstmt->execute([':id' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

// Admin name
$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

// Countdown (same pattern as your other project pages)
$first = null;
try {
  $evt = $pdo->prepare("SELECT starts_at FROM project_events WHERE project_id = :pid ORDER BY starts_at ASC LIMIT 1");
  $evt->execute([':pid' => $projectId]);
  $first = $evt->fetch();
} catch (Throwable $e) {}

$daysToGo = null;
if ($first && !empty($first['starts_at'])) {
  $d1 = new DateTimeImmutable(date('Y-m-d'));
  $d2 = new DateTimeImmutable(substr((string)$first['starts_at'], 0, 10));
  $daysToGo = (int)$d1->diff($d2)->format('%r%a');
}

// UI date label (your other pages use created_at as a fallback label)
$createdAt = (string)($project['created_at'] ?? '');
$projectDateLabel = $createdAt ? date('F j, Y', strtotime($createdAt)) : 'Date TBD';

// Optional: team count (if sidebar uses it later)
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

// Contract sub-section (used for sidebar highlight)
$section = strtolower(trim((string)($_GET['section'] ?? 'status')));
$validSections = [
  'status','parties','client_responsibilities','event_details','services',
  'staffing','notes','payment','cancellation','liability','changes'
];
if (!in_array($section, $validSections, true)) $section = 'status';

// Demo content (so it visually matches your reference right away)
$demo = [
  'draft_label'    => 'Draft • v0.3',
  'client'         => 'Priya Mehra and Rahul Sharma',
  'vendor'         => 'Trikaya',
  'prepared_by'    => 'Vijay Sharma (Team lead)',
  'last_updated'   => '12/02/2026',
  'target_signoff' => '22/04/2026',
];

$pageTitle = (string)$project['title'] . ' — Contract & scope — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
/* Scoped styles for this page only */
.contract-page{
  padding: 18px 18px 28px;
}

.contract-surface{
  background: rgba(255,255,255,0.92);
  border-radius: 28px;
  padding: 20px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.08);
}

.contract-topbar{
  display:flex;
  justify-content: space-between;
  align-items:center;
  margin-bottom: 14px;
}

.contract-user{
  display:flex;
  gap: 10px;
  align-items:center;
  padding: 8px 12px;
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.08);
  background: rgba(255,255,255,0.6);
  font-size: 13px;
}

.contract-user a{
  text-decoration:none;
}

.contract-project-head{
  display:flex;
  justify-content: space-between;
  gap: 12px;
  align-items:flex-start;
  padding: 8px 6px 16px;
  border-bottom: 1px solid rgba(0,0,0,0.06);
  margin-bottom: 16px;
}

.contract-project-left{
  display:flex;
  gap: 12px;
  align-items:center;
}

.contract-project-icon{
  width: 40px;
  height: 40px;
  border-radius: 14px;
  background: rgba(0,0,0,0.06);
}

.contract-project-title{
  font-size: 26px;
  font-weight: 800;
  line-height: 1.1;
}

.contract-project-meta{
  margin-top: 6px;
  font-size: 13px;
  opacity: 0.75;
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
}

.contract-project-actions{
  display:flex;
  gap: 10px;
  align-items:center;
  flex-wrap: wrap;
  justify-content:flex-end;
}

.contract-btn{
  padding: 10px 14px;
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.1);
  background: rgba(255,255,255,0.75);
  cursor:pointer;
  font-size: 13px;
  text-decoration:none;
  color: inherit;
  display:inline-flex;
  align-items:center;
  gap: 8px;
}

.contract-btn.primary{
  background: rgba(0,0,0,0.86);
  color: #fff;
  border-color: rgba(0,0,0,0.86);
}

.contract-btn.icon{
  width: 40px;
  height: 40px;
  justify-content:center;
  padding: 0;
}

.contract-shell{
  display:grid;
  grid-template-columns: 320px 1fr;
  gap: 18px;
  align-items:start;
}

.contract-left{
  display:flex;
  flex-direction: column;
  gap: 12px;
}

.contract-nav-card{
  background: rgba(255,255,255,0.85);
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 20px;
  padding: 12px;
}

.contract-nav-item{
  display:flex;
  gap: 12px;
  padding: 12px;
  border-radius: 16px;
  border: 1px solid rgba(0,0,0,0.06);
  background: rgba(255,255,255,0.65);
  text-decoration:none;
  color: inherit;
  margin-bottom: 10px;
}

.contract-nav-item:last-child{ margin-bottom: 0; }

.contract-nav-item .ico{
  width: 34px;
  height: 34px;
  border-radius: 12px;
  background: rgba(0,0,0,0.06);
  flex: 0 0 auto;
}

.contract-nav-item .txt{
  min-width: 0;
}

.contract-nav-item .title{
  font-weight: 800;
  font-size: 14px;
}

.contract-nav-item .sub{
  font-size: 12px;
  opacity: 0.75;
  margin-top: 2px;
}

.contract-nav-item.active{
  border-color: rgba(0,0,0,0.10);
  background: rgba(0,0,0,0.03);
}

.contract-substeps{
  margin: 10px 0 0 46px;
  display:grid;
  gap: 10px;
}

.contract-step{
  display:flex;
  gap: 10px;
  align-items:center;
  padding: 8px 10px;
  border-radius: 14px;
  text-decoration:none;
  color: inherit;
  font-size: 13px;
}

.contract-step:hover{ background: rgba(0,0,0,0.03); }

.contract-step .dot{
  width: 18px;
  height: 18px;
  border-radius: 999px;
  background: #1d6ff2;
  color: #fff;
  display:grid;
  place-items:center;
  font-size: 12px;
}

.contract-step.active{
  background: rgba(0,0,0,0.04);
}

.contract-main{
  min-width: 0;
}

.contract-head{
  display:flex;
  justify-content: space-between;
  gap: 14px;
  align-items:flex-start;
  margin-bottom: 14px;
}

.contract-head-left .h1{
  display:flex;
  gap: 10px;
  align-items:center;
  flex-wrap: wrap;
  font-size: 26px;
  font-weight: 900;
}

.contract-pill{
  font-size: 12px;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.08);
  background: rgba(255,255,255,0.6);
  opacity: 0.9;
}

.contract-head-sub{
  margin-top: 6px;
  opacity: 0.75;
  font-size: 13px;
}

.contract-head-actions{
  display:flex;
  gap: 10px;
  align-items:center;
  flex-wrap: wrap;
  justify-content:flex-end;
}

.contract-grid{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.contract-card{
  background: rgba(255,255,255,0.85);
  border: 1px solid rgba(0,0,0,0.07);
  border-radius: 20px;
  padding: 14px;
}

.contract-card h3{
  margin: 0;
  font-size: 14px;
  font-weight: 900;
}

.contract-card p.meta{
  margin: 6px 0 0;
  font-size: 12px;
  opacity: 0.72;
}

.kv{
  margin-top: 12px;
  display:grid;
  gap: 8px;
  font-size: 13px;
}

.kv-row{
  display:grid;
  grid-template-columns: 140px 1fr;
  gap: 10px;
  align-items:start;
}

.kv-k{ opacity: 0.7; }
.kv-v{ text-align:right; }

.contract-actions-row{
  display:flex;
  justify-content:flex-end;
  margin-top: 12px;
}

.contract-actions-row .contract-btn{
  padding: 8px 12px;
  font-size: 12px;
}

.link-list{
  margin-top: 10px;
  border-top: 1px solid rgba(0,0,0,0.07);
}

.link-row{
  display:flex;
  justify-content: space-between;
  align-items:center;
  padding: 10px 0;
  text-decoration:none;
  color: inherit;
  border-bottom: 1px solid rgba(0,0,0,0.07);
  font-size: 13px;
}

.link-row:last-child{ border-bottom:none; }
.link-row .arr{ opacity: 0.6; }

.bullets{
  margin: 10px 0 0;
  padding-left: 18px;
  font-size: 13px;
}

.muted{
  opacity: 0.72;
}

@media (max-width: 1100px){
  .contract-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 860px){
  .contract-shell{ grid-template-columns: 1fr; }
  .contract-grid{ grid-template-columns: 1fr; }
  .kv-row{ grid-template-columns: 1fr; }
  .kv-v{ text-align:left; }
}
</style>

<div class="contract-page">
  <div class="contract-surface">

    <div class="contract-topbar">
      <div></div>
      <div class="contract-user">
        <span>Admin: <?php echo h0($adminName); ?></span>
        <a href="<?php echo h0(base_url('logout.php')); ?>">Logout</a>
      </div>
    </div>

    <!-- Project header -->
    <div class="contract-project-head">
      <div class="contract-project-left">
        <div class="contract-project-icon" aria-hidden="true"></div>
        <div>
          <div class="contract-project-title"><?php echo h0((string)$project['title']); ?></div>
          <div class="contract-project-meta">
            <span><?php echo h0($projectDateLabel); ?></span>
            <?php if ($daysToGo !== null): ?>
              <span>• <?php echo h0((string)$daysToGo); ?> days to go</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="contract-project-actions">
        <a class="contract-btn primary" href="<?php echo h0(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
        <a class="contract-btn" href="<?php echo h0(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
        <a class="contract-btn icon" href="<?php echo h0(base_url('projects/show.php?id=' . $projectId)); ?>" title="Project overview">⚙</a>
      </div>
    </div>

    <div class="contract-shell">

      <!-- Left project sidebar (self-contained for this page to match your reference) -->
      <aside class="contract-left">

        <div class="contract-nav-card">
          <a class="contract-nav-item" href="<?php echo h0(base_url('projects/show.php?id=' . $projectId)); ?>">
            <div class="ico" aria-hidden="true"></div>
            <div class="txt">
              <div class="title"><?php echo h0(($daysToGo ?? 0) . ' days to go'); ?></div>
              <div class="sub">Countdown to the big day!</div>
            </div>
          </a>

          <a class="contract-nav-item" href="<?php echo h0(base_url('projects/team.php?id=' . $projectId)); ?>">
            <div class="ico" aria-hidden="true"></div>
            <div class="txt">
              <div class="title">The team</div>
              <div class="sub"><?php echo h0((string)$teamCount); ?> active members</div>
            </div>
          </a>

          <a class="contract-nav-item active" href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId)); ?>">
            <div class="ico" aria-hidden="true"></div>
            <div class="txt">
              <div class="title">Contract &amp; scope</div>
              <div class="sub">Set the agreement, deliverables and key terms before planning begins</div>
            </div>
          </a>

          <div class="contract-substeps">
            <a class="contract-step <?php echo $section === 'status' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=status')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Contract status</span>
            </a>

            <a class="contract-step <?php echo $section === 'parties' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=parties')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Parties &amp; contacts</span>
            </a>

            <a class="contract-step <?php echo $section === 'client_responsibilities' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=client_responsibilities')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Client responsibilities</span>
            </a>

            <a class="contract-step <?php echo $section === 'event_details' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=event_details')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Event details</span>
            </a>

            <a class="contract-step <?php echo $section === 'services' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=services')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Services provided</span>
            </a>

            <a class="contract-step <?php echo $section === 'staffing' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=staffing')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Staffing plans</span>
            </a>

            <a class="contract-step <?php echo $section === 'notes' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=notes')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Notes &amp; files</span>
            </a>

            <a class="contract-step <?php echo $section === 'payment' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=payment')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Payment terms</span>
            </a>

            <a class="contract-step <?php echo $section === 'cancellation' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=cancellation')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Cancellation policy</span>
            </a>

            <a class="contract-step <?php echo $section === 'liability' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=liability')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Liability &amp; force majeure</span>
            </a>

            <a class="contract-step <?php echo $section === 'changes' ? 'active' : ''; ?>"
               href="<?php echo h0(base_url('projects/contract.php?id=' . $projectId . '&section=changes')); ?>">
              <span class="dot" aria-hidden="true">✓</span>
              <span>Change requests</span>
            </a>
          </div>
        </div>

        <div class="contract-nav-card">
          <a class="contract-nav-item" href="#">
            <div class="ico" aria-hidden="true"></div>
            <div class="txt">
              <div class="title">Guest list setup</div>
              <div class="sub">Build the master guest list and organize it for invites and logistics</div>
            </div>
          </a>
        </div>

      </aside>

      <!-- Main content -->
      <main class="contract-main">

        <div class="contract-head">
          <div class="contract-head-left">
            <div class="h1">
              <span>Contract &amp; scope</span>
              <span class="contract-pill"><?php echo h0($demo['draft_label']); ?></span>
            </div>
            <div class="contract-head-sub">Create the agreement, define deliverables, and send it for approval.</div>
          </div>

          <div class="contract-head-actions">
            <button class="contract-btn icon" type="button" title="Download">⤓</button>
            <button class="contract-btn icon" type="button" title="Save">💾</button>
            <button class="contract-btn" type="button" title="Preview PDF">👁 Preview PDF</button>
            <button class="contract-btn primary" type="button" title="Send for approval">☆ Send for approval</button>
          </div>
        </div>

        <div class="contract-grid">

          <!-- Column 1 -->
          <section class="contract-card" id="status">
            <h3>Contract status</h3>
            <p class="meta">Track signing progress and key parties.</p>

            <div class="kv">
              <div class="kv-row"><div class="kv-k">Client:</div><div class="kv-v"><?php echo h0($demo['client']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Vendor</div><div class="kv-v"><?php echo h0($demo['vendor']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Prepared by</div><div class="kv-v"><?php echo h0($demo['prepared_by']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Last updated on:</div><div class="kv-v"><?php echo h0($demo['last_updated']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Target sign-off</div><div class="kv-v"><?php echo h0($demo['target_signoff']); ?></div></div>
            </div>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">View version history</a>
            </div>
          </section>

          <section class="contract-card" id="event_details">
            <h3>Event details</h3>
            <p class="meta">Dates, venue, and estimated guest count.</p>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit details</a>
            </div>
          </section>

          <section class="contract-card" id="payment">
            <h3>Payment terms</h3>
            <p class="meta">Total fee, milestones, and cancellation terms.</p>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit payment schedule</a>
            </div>
          </section>

          <!-- Column 2 -->
          <section class="contract-card" id="parties">
            <h3>Parties &amp; contacts</h3>
            <p class="meta">Who is the agreement between</p>

            <div class="kv">
              <div class="kv-row"><div class="kv-k">Company</div><div class="kv-v">Trikaya</div></div>
              <div class="kv-row"><div class="kv-k">Address</div><div class="kv-v">H-51, 1st Floor, Shivaji Park, New Delhi 110026</div></div>
              <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v">hello@trikaya.events</div></div>
              <div class="kv-row"><div class="kv-k">Phone</div><div class="kv-v">+91 98XXXXXX12</div></div>
            </div>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit details</a>
            </div>
          </section>

          <section class="contract-card" id="services">
            <h3>Services provided</h3>
            <p class="meta">What the planning team will deliver for this event.</p>

            <div class="link-list">
              <a class="link-row" href="#"><span>Consultation &amp; planning</span><span class="arr">›</span></a>
              <a class="link-row" href="#"><span>Vendor coordination</span><span class="arr">›</span></a>
              <a class="link-row" href="#"><span>Logistics &amp; on-ground coordination</span><span class="arr">›</span></a>
            </div>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">View &amp; edit services</a>
            </div>
          </section>

          <section class="contract-card" id="cancellation">
            <h3>Cancellation policy</h3>
            <p class="meta">Refund and cancellation terms</p>

            <ul class="bullets">
              <li>Cancellation <strong>30–90 days</strong> before the event: <strong>50%</strong> of paid amount refundable (excluding deposit)</li>
              <li>Cancellation <strong>less than 30 days</strong> before the wedding: No refund</li>
              <li>Date changes: treated as rescheduling—charges may apply</li>
            </ul>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit policy</a>
            </div>
          </section>

          <!-- Column 3 -->
          <section class="contract-card" id="client_responsibilities">
            <h3>Client responsibilities</h3>
            <p class="meta">Confirm dates, venue, and requirements on time.</p>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit details</a>
            </div>
          </section>

          <section class="contract-card" id="staffing">
            <h3>Staffing plan</h3>
            <p class="meta">Set the team size needed for execution.</p>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit details</a>
            </div>
          </section>

          <section class="contract-card" id="notes">
            <h3>Notes &amp; files</h3>
            <p class="meta">Attach documents and keep internal notes.</p>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Upload files</a>
            </div>
          </section>

          <section class="contract-card" id="liability">
            <h3>Liability &amp; force majeure</h3>
            <p class="meta">Responsibility limits and uncontrollable events.</p>

            <ul class="bullets">
              <li>The service provider is not liable for issues caused by third-party vendors beyond agreed coordination.</li>
              <li>Force majeure includes natural disasters, government restrictions, and unforeseen emergencies.</li>
            </ul>

            <div class="contract-actions-row">
              <a class="contract-btn" href="#">Edit clauses</a>
            </div>
          </section>

          <section class="contract-card" id="changes">
            <h3>Change requests</h3>
            <p class="meta">Track requested updates after the contract is shared.</p>

            <ul class="bullets muted">
              <li>No change requests yet.</li>
              <li>Change requests become available after the contract is sent.</li>
            </ul>
          </section>

        </div>
      </main>

    </div>
  </div>
</div>

<?php require_once $root . '/includes/footer.php'; ?>