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

function posted(string $key, string $default = ''): string {
  return trim((string)($_POST[$key] ?? $default));
}

function posted_array(string $key): array {
  $value = $_POST[$key] ?? [];
  return is_array($value) ? $value : [];
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
    SELECT COUNT(*) 
    FROM project_members
    WHERE project_id = :pid
  ");
  $tc->execute([':pid' => $projectId]);
  $teamCount = (int)($tc->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $teamCount = 0;
}

/* ---------- Project title ---------- */
$partner1 = trim((string)($project['partner1_name'] ?? ''));
$partner2 = trim((string)($project['partner2_name'] ?? ''));

$projectTitle = trim((string)($project['title'] ?? ''));
if ($projectTitle === '') {
  $projectTitle = trim(($partner1 !== '' ? $partner1 : 'Partner 1') . ' weds ' . ($partner2 !== '' ? $partner2 : 'Partner 2'));
}

/* ---------- Events for invite mapping ---------- */
$events = [];
$eventsError = '';

try {
  $es = $pdo->prepare("
    SELECT id, name, starts_at, venue, hosting_side
    FROM project_events
    WHERE project_id = :pid
    ORDER BY starts_at ASC, id ASC
  ");
  $es->execute([':pid' => $projectId]);
  $events = $es->fetchAll() ?: [];
} catch (Throwable $e) {
  $events = [];
  $eventsError = $e->getMessage();
}

$pageTitle = 'Add guest — ' . $projectTitle . ' — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.guest-create-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  margin-bottom:16px;
}

.guest-create-actions{
  display:flex;
  gap:10px;
  flex-wrap:nowrap;
  align-items:center;
  justify-content:flex-end;
  white-space:nowrap;
  flex:0 0 auto;
}

.guest-create-actions .btn{
  white-space:nowrap;
}

@media (max-width:980px){
  .guest-create-head{
    flex-direction:column;
    align-items:flex-start;
  }

  .guest-create-actions{
    width:100%;
    flex-wrap:wrap;
    justify-content:flex-start;
  }
} 
}
.guest-layout{
  display:grid;
  grid-template-columns:minmax(0,1.55fr) minmax(320px,.95fr);
  gap:14px;
  align-items:start;
}
@media (max-width:1120px){
  .guest-layout{
    grid-template-columns:1fr;
  }
}
.guest-col{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.form-card{
  padding:18px;
}
.form-card-title{
  margin:0;
  font-size:18px;
  font-weight:800;
  color:#1d1d1d;
}
.form-card-sub{
  margin:6px 0 0 0;
  color:var(--muted);
  font-size:13px;
  line-height:1.5;
}
.form-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:14px;
  margin-top:16px;
}
.form-grid-3{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:14px;
  margin-top:16px;
}
@media (max-width:760px){
  .form-grid,
  .form-grid-3{
    grid-template-columns:1fr;
  }
}
.field{
  display:flex;
  flex-direction:column;
  gap:7px;
}
.field label{
  font-size:12px;
  font-weight:700;
  color:#555;
}
.field input,
.field select,
.field textarea{
  width:100%;
  min-height:44px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,0.12);
  background:#fff;
  padding:11px 14px;
  font:inherit;
  color:#1f1f1f;
  outline:none;
  box-sizing:border-box;
}
.field textarea{
  min-height:108px;
  resize:vertical;
}
.field input:focus,
.field select:focus,
.field textarea:focus{
  border-color:rgba(0,0,0,0.28);
  box-shadow:0 0 0 3px rgba(0,0,0,0.04);
}
.field-help{
  margin-top:4px;
  font-size:12px;
  color:var(--muted);
  line-height:1.45;
}
.invite-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:10px 14px;
  margin-top:16px;
}
@media (max-width:760px){
  .invite-grid{
    grid-template-columns:1fr;
  }
}
.invite-pill{
  display:flex;
  align-items:flex-start;
  gap:10px;
  border:1px solid rgba(0,0,0,0.08);
  border-radius:16px;
  padding:12px 12px;
  background:#fff;
}
.invite-pill input{
  margin-top:2px;
}
.invite-pill-title{
  font-size:14px;
  font-weight:700;
  color:#1f1f1f;
  line-height:1.35;
}
.invite-pill-meta{
  margin-top:4px;
  font-size:12px;
  color:var(--muted);
  line-height:1.45;
}
.empty-events{
  margin-top:16px;
  padding:14px 16px;
  border-radius:16px;
  border:1px dashed rgba(0,0,0,0.12);
  background:rgba(0,0,0,0.02);
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}
.form-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin:18px 0 2px;
}
.mini-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:16px;
}
.info-note{
  margin-top:16px;
  padding:14px 15px;
  border-radius:16px;
  background:rgba(0,0,0,0.03);
  color:var(--muted);
  font-size:13px;
  line-height:1.55;
}
.side-summary{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-top:16px;
}
.side-summary-row{
  display:grid;
  grid-template-columns:1fr auto;
  gap:10px;
  align-items:start;
  font-size:13px;
}
.side-summary-row .k{
  color:#444;
}
.side-summary-row .v{
  color:#1c1c1c;
  font-weight:700;
}
.page-actions-bottom{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:16px;
  flex-wrap:wrap;
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
          <div class="guest-create-head">
            <div>
              <h1 class="guest-create-title">Add guest details</h1>
              <p class="guest-create-sub">
                Add one guest record with invite mapping, contact details, travel needs, accommodation notes, and food preferences.
              </p>
            </div>

            <div class="guest-create-actions">
              <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId)); ?>">Cancel</a>
              <button class="btn" type="button">Save &amp; add another</button>
              <button class="btn btn-primary" type="button">Save guest</button>
            </div>
          </div>

          <form method="post" action="" autocomplete="off">
            <div class="guest-layout">

              <div class="guest-col">
                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Guest details</h2>
                  <p class="form-card-sub">Start with the guest’s identity, side, and group-level details.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="title">Title</label>
                      <select id="title" name="title">
                        <option value="">Select title</option>
                        <option value="Mr">Mr</option>
                        <option value="Ms">Ms</option>
                        <option value="Mrs">Mrs</option>
                        <option value="Dr">Dr</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="invited_by">Invited by</label>
                      <select id="invited_by" name="invited_by">
                        <option value="">Select side</option>
                        <option value="bride">Bride’s side</option>
                        <option value="groom">Groom’s side</option>
                        <option value="both">Both families</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="first_name">First name</label>
                      <input id="first_name" name="first_name" type="text" placeholder="Enter first name" value="<?php echo esc(posted('first_name')); ?>">
                    </div>

                    <div class="field">
                      <label for="last_name">Last name</label>
                      <input id="last_name" name="last_name" type="text" placeholder="Enter last name" value="<?php echo esc(posted('last_name')); ?>">
                    </div>

                    <div class="field">
                      <label for="relation_label">Relation / group</label>
                      <input id="relation_label" name="relation_label" type="text" placeholder="e.g. Cousin, School friends, Sharma family" value="<?php echo esc(posted('relation_label')); ?>">
                    </div>

                    <div class="field">
                      <label for="city">City</label>
                      <input id="city" name="city" type="text" placeholder="e.g. Delhi" value="<?php echo esc(posted('city')); ?>">
                    </div>
                  </div>

                  <div class="form-grid-3">
                    <div class="field">
                      <label for="seat_count">Number of seats</label>
                      <input id="seat_count" name="seat_count" type="number" min="1" placeholder="1" value="<?php echo esc(posted('seat_count')); ?>">
                    </div>

                    <div class="field">
                      <label for="children_count">Number of children</label>
                      <input id="children_count" name="children_count" type="number" min="0" placeholder="0" value="<?php echo esc(posted('children_count')); ?>">
                    </div>

                    <div class="field">
                      <label for="plus_one_allowed">Plus-one</label>
                      <select id="plus_one_allowed" name="plus_one_allowed">
                        <option value="">Select</option>
                        <option value="0">Not included</option>
                        <option value="1">Allowed</option>
                      </select>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Invited events</h2>
                  <p class="form-card-sub">Choose which project events this guest should receive in their invite flow.</p>

                  <?php if ($events): ?>
                    <div class="invite-grid">
                      <?php
                        $selectedEvents = array_map('strval', posted_array('event_ids'));
                        foreach ($events as $event):
                          $eventId = (string)($event['id'] ?? '');
                          $eventName = trim((string)($event['name'] ?? 'Untitled event'));
                          $eventSide = trim((string)($event['hosting_side'] ?? ''));
                          $eventVenue = trim((string)($event['venue'] ?? ''));
                          $eventDate = trim((string)($event['starts_at'] ?? ''));
                          $eventDateLabel = $eventDate !== '' ? date('M j, Y • g:i A', strtotime($eventDate)) : 'Date TBD';
                          $checked = in_array($eventId, $selectedEvents, true);
                      ?>
                        <label class="invite-pill">
                          <input type="checkbox" name="event_ids[]" value="<?php echo esc($eventId); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                          <span>
                            <div class="invite-pill-title"><?php echo esc($eventName); ?></div>
                            <div class="invite-pill-meta">
                              <?php echo esc($eventDateLabel); ?>
                              <?php if ($eventSide !== ''): ?> • <?php echo esc(ucfirst($eventSide)); ?> side<?php endif; ?>
                              <?php if ($eventVenue !== ''): ?> • <?php echo esc($eventVenue); ?><?php endif; ?>
                            </div>
                          </span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="empty-events">
                      No events have been added to this project yet. Add events first, then come back to map this guest to the correct functions.
                    </div>
                  <?php endif; ?>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Travel information</h2>
                  <p class="form-card-sub">Capture arrival and departure needs for pickup and drop coordination.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="pickup_required">Pickup needed</label>
                      <select id="pickup_required" name="pickup_required">
                        <option value="">Select</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="drop_required">Drop needed</label>
                      <select id="drop_required" name="drop_required">
                        <option value="">Select</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                      </select>
                    </div>
                  </div>

                  <div class="form-divider"></div>

                  <div class="form-grid">
                    <div class="field">
                      <label for="arrival_date">Arrival date</label>
                      <input id="arrival_date" name="arrival_date" type="date" value="<?php echo esc(posted('arrival_date')); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_time">Arrival time</label>
                      <input id="arrival_time" name="arrival_time" type="time" value="<?php echo esc(posted('arrival_time')); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_ref">Arrival flight / train no.</label>
                      <input id="arrival_ref" name="arrival_ref" type="text" placeholder="e.g. AI-1234" value="<?php echo esc(posted('arrival_ref')); ?>">
                    </div>

                    <div class="field">
                      <label for="arrival_terminal">Arrival terminal / platform</label>
                      <input id="arrival_terminal" name="arrival_terminal" type="text" placeholder="e.g. T3 / Platform 4" value="<?php echo esc(posted('arrival_terminal')); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_date">Departure date</label>
                      <input id="departure_date" name="departure_date" type="date" value="<?php echo esc(posted('departure_date')); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_time">Departure time</label>
                      <input id="departure_time" name="departure_time" type="time" value="<?php echo esc(posted('departure_time')); ?>">
                    </div>

                    <div class="field">
                      <label for="departure_ref">Departure flight / train no.</label>
                      <input id="departure_ref" name="departure_ref" type="text" placeholder="e.g. UK-211" value="<?php echo esc(posted('departure_ref')); ?>">
                    </div>

                    <div class="field">
                      <label for="transport_notes">Pickup / drop remarks</label>
                      <input id="transport_notes" name="transport_notes" type="text" placeholder="e.g. Extra luggage, senior support needed" value="<?php echo esc(posted('transport_notes')); ?>">
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Accommodation information</h2>
                  <p class="form-card-sub">Store room preferences and stay notes for the hospitality team.</p>

                  <div class="form-grid">
                    <div class="field">
                      <label for="checkin_date">Check-in</label>
                      <input id="checkin_date" name="checkin_date" type="date" value="<?php echo esc(posted('checkin_date')); ?>">
                    </div>

                    <div class="field">
                      <label for="checkout_date">Check-out</label>
                      <input id="checkout_date" name="checkout_date" type="date" value="<?php echo esc(posted('checkout_date')); ?>">
                    </div>

                    <div class="field">
                      <label for="room_type">Room type</label>
                      <select id="room_type" name="room_type">
                        <option value="">Select room type</option>
                        <option value="suite">Suite</option>
                        <option value="deluxe">Deluxe</option>
                        <option value="standard">Standard</option>
                        <option value="family">Family room</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="bed_type">Bed type</label>
                      <select id="bed_type" name="bed_type">
                        <option value="">Select bed type</option>
                        <option value="king">King</option>
                        <option value="queen">Queen</option>
                        <option value="twin">Twin</option>
                        <option value="extra_bed">Extra bed required</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="id_document_note">Identification document</label>
                      <input id="id_document_note" name="id_document_note" type="text" placeholder="e.g. Aadhaar / passport to be collected" value="<?php echo esc(posted('id_document_note')); ?>">
                    </div>

                    <div class="field">
                      <label for="stay_notes">Accommodation remarks</label>
                      <input id="stay_notes" name="stay_notes" type="text" placeholder="e.g. Near lift, connected room, quiet floor" value="<?php echo esc(posted('stay_notes')); ?>">
                    </div>
                  </div>
                </section>
              </div>

              <div class="guest-col">
                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Contact information</h2>
                  <p class="form-card-sub">Use the best details available for invites and follow-ups.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="phone">Phone number</label>
                      <input id="phone" name="phone" type="text" placeholder="Enter guest’s phone number" value="<?php echo esc(posted('phone')); ?>">
                    </div>

                    <div class="field">
                      <label for="email">Email</label>
                      <input id="email" name="email" type="email" placeholder="Enter guest’s email" value="<?php echo esc(posted('email')); ?>">
                    </div>

                    <div class="field">
                      <label for="address">Address</label>
                      <textarea id="address" name="address" placeholder="Address or locality, if relevant for logistics"><?php echo esc(posted('address')); ?></textarea>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Important notes</h2>
                  <p class="form-card-sub">Track accessibility, assistance, and context the team should not miss.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="accessibility">Accessibility / assistance needed</label>
                      <select id="accessibility" name="accessibility">
                        <option value="">Select support need</option>
                        <option value="none">None</option>
                        <option value="wheelchair">Wheelchair support</option>
                        <option value="elder_care">Elder care support</option>
                        <option value="toddler_care">Toddler care support</option>
                        <option value="medical">Medical note</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="special_notes">Special notes</label>
                      <textarea id="special_notes" name="special_notes" placeholder="Add internal notes for RSVP, travel, or hospitality teams"><?php echo esc(posted('special_notes')); ?></textarea>
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Food preferences</h2>
                  <p class="form-card-sub">Keep dietary choices visible before catering numbers are finalized.</p>

                  <div class="form-grid" style="grid-template-columns:1fr;">
                    <div class="field">
                      <label for="diet_preference">Diet preference</label>
                      <select id="diet_preference" name="diet_preference">
                        <option value="">Select diet preference</option>
                        <option value="veg">Veg</option>
                        <option value="non_veg">Non-veg</option>
                        <option value="vegan">Vegan</option>
                        <option value="jain">Jain</option>
                        <option value="eggs">Eggs okay</option>
                        <option value="none">No preference</option>
                      </select>
                    </div>

                    <div class="field">
                      <label for="allergies">Allergies</label>
                      <input id="allergies" name="allergies" type="text" placeholder="e.g. Peanuts, lactose, gluten" value="<?php echo esc(posted('allergies')); ?>">
                    </div>
                  </div>
                </section>

                <section class="card proj-card form-card">
                  <h2 class="form-card-title">Quick summary</h2>
                  <p class="form-card-sub">This page is for one guest record at a time.</p>

                  <div class="side-summary">
                    <div class="side-summary-row">
                      <div class="k">Project</div>
                      <div class="v"><?php echo esc($projectTitle); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Project date</div>
                      <div class="v"><?php echo esc($topDateLabel); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Available events</div>
                      <div class="v"><?php echo esc((string)count($events)); ?></div>
                    </div>
                    <div class="side-summary-row">
                      <div class="k">Mode</div>
                      <div class="v">Manual add</div>
                    </div>
                  </div>

                  <div class="info-note">
                    Keep this page focused on clean internal entry. Bulk uploads, dedupe, and invite sending should stay on the guest setup screen.
                  </div>

                  <div class="page-actions-bottom">
                    <a class="btn" href="<?php echo esc(base_url('guests/index.php?project_id=' . $projectId)); ?>">Back</a>
                    <button class="btn" type="button">Save &amp; add another</button>
                    <button class="btn btn-primary" type="button">Save guest</button>
                  </div>
                </section>
              </div>

            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require_once $root . '/includes/footer.php'; ?>