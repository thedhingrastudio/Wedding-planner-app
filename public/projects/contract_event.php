<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0) redirect('projects/index.php');

$eventId = (int)($_GET['eid'] ?? 0);
$companyId = current_company_id();

/* ---------- Helpers ---------- */
if (!function_exists('contract_table_exists__evt')) {
  function contract_table_exists__evt(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("SHOW TABLES LIKE :t");
      $st->execute([':t' => $table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('parse_date_ymd')) {
  function parse_date_ymd(string $ymd): ?string {
    $ymd = trim($ymd);
    if ($ymd === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return null;
    return $ymd . ' 00:00:00';
  }
}

function col_evt(array $row, string $key): string {
  return array_key_exists($key, $row) ? trim((string)$row[$key]) : '';
}

/** Map any legacy/label value → canonical DB value */
function normalize_hosting_side_evt(string $raw): string {
  $v = strtolower(trim($raw));
  $v = str_replace(['’','`'], ["'","'"], $v);

  if (in_array($v, ['bride','groom','collaborative'], true)) return $v;
  if (strpos($v, 'bride') !== false) return 'bride';
  if (strpos($v, 'groom') !== false) return 'groom';
  if (strpos($v, 'collab') !== false) return 'collaborative';

  return 'collaborative';
}

$SIDE_LABEL = [
  'bride' => "Bride’s side",
  'groom' => "Groom’s side",
  'collaborative' => "Collaborative event",
];

/* ---------- Project (company-safe) ---------- */
$pstmt = $pdo->prepare("SELECT * FROM projects WHERE id = :pid AND company_id = :cid LIMIT 1");
$pstmt->execute([':pid' => $projectId, ':cid' => $companyId]);
$project = $pstmt->fetch();
if (!$project) redirect('projects/index.php');

$adminName = trim((string)($_SESSION['full_name'] ?? ''));
if ($adminName === '') $adminName = 'Admin';

/* ---------- Needed for includes/project_sidebar.php ---------- */
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
} catch (Throwable $e) {
  $createdAt = (string)($project['created_at'] ?? '');
  if ($createdAt !== '') $projectDateLabel = date('F j, Y', strtotime($createdAt));
}

/* ---------- Prefill contacts from Project create form ---------- */
$projPartner1 = col_evt($project, 'partner1_name');
$projPartner2 = col_evt($project, 'partner2_name');
$projPhone1   = col_evt($project, 'phone1');
$projPhone2   = col_evt($project, 'phone2');
$projEmail1   = col_evt($project, 'email1');
$projEmail2   = col_evt($project, 'email2');

/* ---------- Load event (edit mode) ---------- */
$event = [
  'id' => 0,
  'name' => '',
  'starts_at' => null,
  'venue' => '',
  'hosting_side' => 'collaborative',
];

if ($eventId > 0) {
  $es = $pdo->prepare("
    SELECT id, name, starts_at, venue, hosting_side
    FROM project_events
    WHERE id = :eid AND project_id = :pid
    LIMIT 1
  ");
  $es->execute([':eid' => $eventId, ':pid' => $projectId]);
  $row = $es->fetch();
  if ($row) {
    $event['id'] = (int)$row['id'];
    $event['name'] = (string)($row['name'] ?? '');
    $event['starts_at'] = $row['starts_at'] ?? null;
    $event['venue'] = (string)($row['venue'] ?? '');
    $event['hosting_side'] = normalize_hosting_side_evt((string)($row['hosting_side'] ?? 'collaborative'));
  } else {
    redirect('projects/contract_event_details.php?id=' . $projectId);
  }
}

/* ---------- Optional meta table ---------- */
$metaEnabled = contract_table_exists__evt($pdo, 'project_event_meta');

$meta = [
  'description' => '',
  'client1_name' => '',
  'client1_phone' => '',
  'client1_email' => '',
  'client1_address' => '',
  'client2_name' => '',
  'client2_phone' => '',
  'client2_email' => '',
  'client2_address' => '',
];

if ($metaEnabled && $eventId > 0) {
  try {
    $ms = $pdo->prepare("SELECT * FROM project_event_meta WHERE project_event_id = :eid LIMIT 1");
    $ms->execute([':eid' => $eventId]);
    $mrow = $ms->fetch();
    if ($mrow) {
      foreach ($meta as $k => $_) $meta[$k] = (string)($mrow[$k] ?? '');
    }
  } catch (Throwable $e) {}
}

/* Prefill from project if meta is empty */
if ($meta['client1_name'] === '' && $projPartner1 !== '') $meta['client1_name'] = $projPartner1;
if ($meta['client1_phone'] === '' && $projPhone1 !== '')   $meta['client1_phone'] = $projPhone1;
if ($meta['client1_email'] === '' && $projEmail1 !== '')   $meta['client1_email'] = $projEmail1;

if ($meta['client2_name'] === '' && $projPartner2 !== '') $meta['client2_name'] = $projPartner2;
if ($meta['client2_phone'] === '' && $projPhone2 !== '')   $meta['client2_phone'] = $projPhone2;
if ($meta['client2_email'] === '' && $projEmail2 !== '')   $meta['client2_email'] = $projEmail2;

/* ---------- POST: Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'save');
  if ($action === 'discard') redirect('projects/contract_event_details.php?id=' . $projectId);

  $name = trim((string)($_POST['name'] ?? ''));
  $venue = trim((string)($_POST['venue'] ?? ''));
  $hostingSide = normalize_hosting_side_evt((string)($_POST['hosting_side'] ?? 'collaborative'));

  $dateYmd = trim((string)($_POST['date'] ?? ''));
  $startsAt = $dateYmd !== '' ? parse_date_ymd($dateYmd) : null;

  // Server-side required rules
  if ($name === '') {
    flash_set('error', 'Event title is required.');
    redirect('projects/contract_event.php?id=' . $projectId . ($eventId ? '&eid=' . $eventId : ''));
  }
  if ($dateYmd === '' || $startsAt === null) {
    flash_set('error', 'Event date is required.');
    redirect('projects/contract_event.php?id=' . $projectId . ($eventId ? '&eid=' . $eventId : ''));
  }

  $metaPost = [
    'description' => trim((string)($_POST['description'] ?? '')),
    'client1_name' => trim((string)($_POST['client1_name'] ?? '')),
    'client1_phone' => trim((string)($_POST['client1_phone'] ?? '')),
    'client1_email' => trim((string)($_POST['client1_email'] ?? '')),
    'client1_address' => trim((string)($_POST['client1_address'] ?? '')),
    'client2_name' => trim((string)($_POST['client2_name'] ?? '')),
    'client2_phone' => trim((string)($_POST['client2_phone'] ?? '')),
    'client2_email' => trim((string)($_POST['client2_email'] ?? '')),
    'client2_address' => trim((string)($_POST['client2_address'] ?? '')),
  ];

  try {
    $pdo->beginTransaction();

    if ($eventId > 0) {
      $u = $pdo->prepare("
        UPDATE project_events
           SET name = :name,
               starts_at = :starts_at,
               venue = :venue,
               hosting_side = :side,
               updated_at = NOW()
         WHERE id = :eid AND project_id = :pid
      ");
      $u->execute([
        ':name' => $name,
        ':starts_at' => $startsAt,
        ':venue' => $venue,
        ':side' => $hostingSide,
        ':eid' => $eventId,
        ':pid' => $projectId,
      ]);
    } else {
      $i = $pdo->prepare("
        INSERT INTO project_events (project_id, name, starts_at, venue, hosting_side, created_at, updated_at)
        VALUES (:pid, :name, :starts_at, :venue, :side, NOW(), NOW())
      ");
      $i->execute([
        ':pid' => $projectId,
        ':name' => $name,
        ':starts_at' => $startsAt,
        ':venue' => $venue,
        ':side' => $hostingSide,
      ]);
      $eventId = (int)$pdo->lastInsertId();
    }

    if ($metaEnabled) {
      $up = $pdo->prepare("
        INSERT INTO project_event_meta
          (project_event_id, description,
           client1_name, client1_phone, client1_email, client1_address,
           client2_name, client2_phone, client2_email, client2_address,
           created_at, updated_at)
        VALUES
          (:eid, :description,
           :c1n, :c1p, :c1e, :c1a,
           :c2n, :c2p, :c2e, :c2a,
           NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          description = VALUES(description),
          client1_name = VALUES(client1_name),
          client1_phone = VALUES(client1_phone),
          client1_email = VALUES(client1_email),
          client1_address = VALUES(client1_address),
          client2_name = VALUES(client2_name),
          client2_phone = VALUES(client2_phone),
          client2_email = VALUES(client2_email),
          client2_address = VALUES(client2_address),
          updated_at = NOW()
      ");
      $up->execute([
        ':eid' => $eventId,
        ':description' => $metaPost['description'],
        ':c1n' => $metaPost['client1_name'],
        ':c1p' => $metaPost['client1_phone'],
        ':c1e' => $metaPost['client1_email'],
        ':c1a' => $metaPost['client1_address'],
        ':c2n' => $metaPost['client2_name'],
        ':c2p' => $metaPost['client2_phone'],
        ':c2e' => $metaPost['client2_email'],
        ':c2a' => $metaPost['client2_address'],
      ]);
    }

    // bump project updated_at
    try {
      $pdo->prepare("UPDATE projects SET updated_at = NOW() WHERE id = :pid AND company_id = :cid")
          ->execute([':pid' => $projectId, ':cid' => $companyId]);
    } catch (Throwable $e) {}

    $pdo->commit();

    flash_set('success', 'Event saved.');
    redirect('projects/contract_event.php?id=' . $projectId . '&eid=' . $eventId);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('error', 'Save failed: ' . $e->getMessage());
    redirect('projects/contract_event.php?id=' . $projectId . ($eventId ? '&eid=' . $eventId : ''));
  }
}

/* ---------- UI values ---------- */
$ymd = '';
if (!empty($event['starts_at'])) $ymd = substr((string)$event['starts_at'], 0, 10);

$pageTitle = (string)($project['title'] ?? 'Project') . ' — Event';
require_once $root . '/includes/header.php';
?>

<style>
/* Breadcrumb */
.breadcrumb{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:800;
  font-size: 20px;
  color: rgba(0,0,0,0.55);
}
.breadcrumb a{ text-decoration:none; color: rgba(0,0,0,0.55); }
.breadcrumb .sep{ opacity:.55; }
.subhead{ margin-top: 6px; color: var(--muted); font-size: 13px; }

/* Actions */
.top-actions{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.icon-btn{
  width: 38px;
  height: 38px;
  border-radius: 999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}

/* Layout */
.form-grid{
  display:grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 14px;
  align-items:start;
  margin-top: 14px;
}
@media (max-width: 1100px){ .form-grid{ grid-template-columns: 1fr; } }

.card-headline{ color: rgba(0,0,0,0.45); font-weight: 900; font-size: 14px; }
.card-sub{ color: var(--muted); font-size: 12px; margin-top: 4px; }
.hr{ height: 1px; background: rgba(0,0,0,0.06); margin: 12px 0; }

/* Soft inputs */
label.small{ display:block; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
.input-soft, .textarea-soft{
  width: 100%;
  padding: 12px 14px;
  border-radius: 999px;
  border: 1px solid transparent;
  background: rgba(0,0,0,0.04);
  font-size: 13px;
  outline: none;
}
.textarea-soft{
  border-radius: 18px;
  min-height: 140px;
  resize: vertical;
  padding-top: 12px;
}
.input-soft:focus, .textarea-soft:focus{
  background: #fff;
  border-color: rgba(0,0,0,0.14);
}
.input-center{ text-align:center; font-weight: 650; }

.field-row{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-top: 12px;
}
@media (max-width: 900px){ .field-row{ grid-template-columns: 1fr; } }

/* Hosted by chips */
.chips{ display:flex; gap:10px; flex-wrap:wrap; margin-top: 10px; }
.chip input{ display:none; }
.chip label{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding: 10px 14px;
  border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.12);
  background: rgba(0,0,0,0.05);
  cursor:pointer;
  font-size: 13px;
  transition: box-shadow 140ms ease, border-color 140ms ease, background 140ms ease, transform 140ms ease;
}
.chip input:checked + label{
  background: #fff;
  border: 2px solid rgba(0,0,0,0.40);
  box-shadow: 0 6px 18px rgba(0,0,0,0.08);
  font-weight: 800;
  transform: translateY(-1px);
}

.stack{ display:flex; flex-direction:column; gap:14px; }

/* Required field icon UI */
.field-wrap{ position: relative; }
.field-wrap .input-soft, .field-wrap .textarea-soft{ padding-right: 56px; }
.field-alert{
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 34px;
  height: 34px;
  border-radius: 999px;
  display: none;
  place-items: center;
  background: rgba(0,0,0,0.78);
  color: #fff;
  font-size: 16px;
}
.field-error{
  display:none;
  margin-top: 6px;
  font-size: 12px;
  color: rgba(0,0,0,0.55);
}
.field-wrap.is-invalid .field-alert{ display:grid; }
.field-wrap.is-invalid .input-soft, .field-wrap.is-invalid .textarea-soft{
  background: rgba(255, 59, 48, 0.06);
  border: 1px solid rgba(255, 59, 48, 0.35);
}
.field-wrap.is-invalid + .field-error{ display:block; }
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
          <a class="btn btn-primary" href="<?php echo h(base_url('tasks/index.php?project_id=' . $projectId)); ?>">＋ Add task</a>
          <a class="btn" href="<?php echo h(base_url('projects/add_member.php?id=' . $projectId)); ?>">＋ Add member</a>
          <a class="btn icon-btn" href="<?php echo h(base_url('projects/show.php?id=' . $projectId)); ?>" title="Project overview">⚙</a>
        </div>
      </div>

      <div class="project-shell">
        <?php $active = 'contract'; $contractSection = 'event_details'; require_once $root . '/includes/project_sidebar.php'; ?>

        <div class="proj-main">
          <div class="proj-overview-head">
            <div>
              <div class="breadcrumb">
                <a href="<?php echo h(base_url('projects/contract.php?id=' . $projectId)); ?>">Contract &amp; scope</a>
                <span class="sep">›</span>
                <a href="<?php echo h(base_url('projects/contract_event_details.php?id=' . $projectId)); ?>">Event details</a>
                <span class="sep">›</span>
                <span>Event</span>
              </div>
              <div class="subhead">Create the agreement, define deliverables, and send it for approval.</div>
            </div>

            <div class="top-actions">
              <button class="btn icon-btn" type="button" title="Download">⬇</button>
              <button class="btn btn-primary" type="submit" form="eventForm">Save changes</button>
              <a class="btn" href="<?php echo h(base_url('projects/contract_event_details.php?id=' . $projectId)); ?>">Discard changes</a>
            </div>
          </div>

          <form id="eventForm" method="post" action="">
            <input type="hidden" name="action" value="save">

            <div class="form-grid">

              <!-- Left card -->
              <div class="card proj-card">
                <div class="card-headline">Event information</div>
                <div class="card-sub">Track signing progress and key parties.</div>
                <div class="hr"></div>

                <div class="field-row">
                  <div>
                    <label class="small">Event title</label>
                    <div class="field-wrap" data-required="1" data-required-message="Event title is required.">
                      <input class="input-soft input-center" type="text" name="name"
                             value="<?php echo h($event['name']); ?>" placeholder="Wedding">
                      <span class="field-alert" aria-hidden="true">⚠</span>
                    </div>
                    <div class="field-error" role="alert">Event title is required.</div>
                  </div>

                  <div>
                    <label class="small">Event date</label>
                    <div class="field-wrap" data-required="1" data-required-message="Event date is required.">
                      <input class="input-soft input-center" type="date" name="date"
                             value="<?php echo h($ymd); ?>">
                      <span class="field-alert" aria-hidden="true">⚠</span>
                    </div>
                    <div class="field-error" role="alert">Event date is required.</div>
                  </div>
                </div>

                <div style="margin-top: 12px;">
                  <label class="small">Event description (optional)</label>
                  <div class="field-wrap">
                    <textarea class="textarea-soft" name="description" placeholder="Event description ..."><?php echo h($meta['description']); ?></textarea>
                  </div>
                </div>

                <div style="margin-top: 12px;">
                  <label class="small">Venue (optional)</label>
                  <div class="field-wrap">
                    <input class="input-soft" type="text" name="venue"
                           value="<?php echo h($event['venue']); ?>" placeholder="e.g., ABC Banquet, New Delhi">
                  </div>
                </div>

                <div style="margin-top: 14px;">
                  <label class="small">Hosted by</label>
                  <?php $sideKey = normalize_hosting_side_evt((string)$event['hosting_side']); ?>
                  <div class="chips">
                    <div class="chip">
                      <input id="side_bride" type="radio" name="hosting_side" value="bride" <?php echo ($sideKey === 'bride' ? 'checked' : ''); ?>>
                      <label for="side_bride"><?php echo h($SIDE_LABEL['bride']); ?></label>
                    </div>
                    <div class="chip">
                      <input id="side_groom" type="radio" name="hosting_side" value="groom" <?php echo ($sideKey === 'groom' ? 'checked' : ''); ?>>
                      <label for="side_groom"><?php echo h($SIDE_LABEL['groom']); ?></label>
                    </div>
                    <div class="chip">
                      <input id="side_collab" type="radio" name="hosting_side" value="collaborative" <?php echo ($sideKey === 'collaborative' ? 'checked' : ''); ?>>
                      <label for="side_collab"><?php echo h($SIDE_LABEL['collaborative']); ?></label>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Right stack -->
              <div class="stack">
                <div class="card proj-card">
                  <div class="card-headline">Client contact 1</div>
                  <div class="card-sub">Pulled from the project details — edit if needed.</div>
                  <div class="hr"></div>

                  <label class="small">Partner 1</label>
                  <input class="input-soft" type="text" name="client1_name" value="<?php echo h($meta['client1_name']); ?>">

                  <div style="margin-top:10px;">
                    <label class="small">Phone number 1</label>
                    <input class="input-soft" type="text" name="client1_phone" value="<?php echo h($meta['client1_phone']); ?>">
                  </div>

                  <div style="margin-top:10px;">
                    <label class="small">Email address</label>
                    <input class="input-soft" type="text" name="client1_email" value="<?php echo h($meta['client1_email']); ?>">
                  </div>

                  <div style="margin-top:10px;">
                    <label class="small">Address (optional)</label>
                    <input class="input-soft" type="text" name="client1_address" value="<?php echo h($meta['client1_address']); ?>">
                  </div>
                </div>

                <div class="card proj-card">
                  <div class="card-headline">Client contact 2</div>
                  <div class="card-sub">Pulled from the project details — edit if needed.</div>
                  <div class="hr"></div>

                  <label class="small">Partner 2</label>
                  <input class="input-soft" type="text" name="client2_name" value="<?php echo h($meta['client2_name']); ?>">

                  <div style="margin-top:10px;">
                    <label class="small">Phone number 2</label>
                    <input class="input-soft" type="text" name="client2_phone" value="<?php echo h($meta['client2_phone']); ?>">
                  </div>

                  <div style="margin-top:10px;">
                    <label class="small">Email address</label>
                    <input class="input-soft" type="text" name="client2_email" value="<?php echo h($meta['client2_email']); ?>">
                  </div>

                  <div style="margin-top:10px;">
                    <label class="small">Address (optional)</label>
                    <input class="input-soft" type="text" name="client2_address" value="<?php echo h($meta['client2_address']); ?>">
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

<script>
(function () {
  const form = document.getElementById('eventForm');
  if (!form) return;

  const requiredWraps = Array.from(form.querySelectorAll('[data-required="1"]'));

  function getFieldValue(wrap) {
    const el = wrap.querySelector('input, textarea, select');
    if (!el) return '';
    return (el.value || '').trim();
  }

  function setInvalid(wrap, message) {
    wrap.classList.add('is-invalid');
    const err = wrap.parentElement?.querySelector('.field-error');
    if (err && message) err.textContent = message;
    const input = wrap.querySelector('input, textarea, select');
    if (input) input.setAttribute('aria-invalid', 'true');
  }

  function clearInvalid(wrap) {
    wrap.classList.remove('is-invalid');
    const input = wrap.querySelector('input, textarea, select');
    if (input) input.removeAttribute('aria-invalid');
  }

  requiredWraps.forEach((wrap) => {
    const input = wrap.querySelector('input, textarea, select');
    if (!input) return;
    const evt = (input.tagName === 'SELECT' || input.type === 'date') ? 'change' : 'input';
    input.addEventListener(evt, () => {
      if (getFieldValue(wrap) !== '') clearInvalid(wrap);
    });
  });

  form.addEventListener('submit', (e) => {
    let firstBad = null;

    requiredWraps.forEach((wrap) => {
      const val = getFieldValue(wrap);
      if (val === '') {
        const msg = wrap.getAttribute('data-required-message') || 'This field is required.';
        setInvalid(wrap, msg);
        if (!firstBad) firstBad = wrap;
      } else {
        clearInvalid(wrap);
      }
    });

    if (firstBad) {
      e.preventDefault();
      const focusEl = firstBad.querySelector('input, textarea, select');
      focusEl?.focus();
      focusEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();
</script>

<?php require_once $root . '/includes/footer.php'; ?>