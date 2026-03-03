<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) $root = dirname($root);

require_once $root . '/includes/app_start.php';
require_login();

$page_title = "Create project";
include $root . '/includes/header.php';

$companyId = current_company_id();

$members = [];
try {
  $mstmt = $pdo->prepare("
    SELECT id, full_name, email, default_department
    FROM company_members
    WHERE company_id = :cid AND status = 'active'
    ORDER BY full_name ASC
  ");
  $mstmt->execute([':cid' => $companyId]);
  $members = $mstmt->fetchAll();
} catch (Throwable $e) {
  // Keep the page usable even if the schema isn't ready
  flash_set('warning', 'Could not load company members: ' . $e->getMessage());
}

function member_options(array $members): string {
  $out = '<option value="">Select a member…</option>';
  foreach ($members as $m) {
    $label = $m['full_name'] . ' — ' . $m['email'];
    $out .= '<option value="'.(int)$m['id'].'">'.h($label).'</option>';
  }
  return $out;
}
?>

<h1>Create project</h1>
<p>Start a new wedding project with the couple + core details.</p>

<form method="post" action="<?= h(base_url('projects/store.php')) ?>">
  <div class="card">
    <h2>General information</h2>

    <div class="grid-2">
      <div>
        <label>Partner 1</label>
        <input name="partner1_name" placeholder="Enter full name" required>
      </div>
      <div>
        <label>Partner 2</label>
        <input name="partner2_name" placeholder="Enter full name" required>
      </div>

      <div>
        <label>Phone number 1</label>
        <input name="phone1" placeholder="+91 - XXXXXXXXXX">
      </div>
      <div>
        <label>Phone number 2</label>
        <input name="phone2" placeholder="+91 - XXXXXXXXXX">
      </div>

      <div>
        <label>Email 1</label>
        <input name="email1" placeholder="sample@test.com">
      </div>
      <div>
        <label>Email 2</label>
        <input name="email2" placeholder="sample@test.com">
      </div>

      <div>
        <label>Event type</label>
        <input name="event_type" placeholder="eg. Punjabi Hindu wedding">
      </div>
      <div>
        <label>Estimate guest count</label>
        <input name="guest_count_est" type="number" min="0" placeholder="Enter estimate">
      </div>

      <div>
        <label>Estimate budget range (from)</label>
        <input name="budget_from" type="number" min="0" step="0.01" placeholder="₹ xxxxxx">
      </div>
      <div>
        <label>Estimate budget range (to)</label>
        <input name="budget_to" type="number" min="0" step="0.01" placeholder="₹ xxxxxx">
      </div>

      <div>
        <label>Optional project title (editable later)</label>
        <input name="title" placeholder="Leave empty to auto-name: Partner1 weds Partner2">
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Event information</h2>
    <p>Add at least one key event now — you can add more later.</p>

    <div id="eventsWrap"></div>

    <button type="button" class="btn" id="addEventBtn">+ Add event</button>
  </div>

  <div class="card">
    <h2>Staffing plan</h2>
    <p>Select members from your organization.</p>

    <?php if (empty($members)): ?>
      <div class="card" style="border:1px solid var(--border); padding:12px; margin:12px 0;">
        <strong>No active company members found.</strong>
        <div style="color:var(--muted); font-size:13px; margin-top:6px;">
          Add at least one member to your company first, then come back to create a project.
        </div>
      </div>
    <?php endif; ?>

    <div class="grid-2">
      <div>
        <label>Team lead</label>
        <select name="team_lead_member_id" required>
          <?= member_options($members) ?>
        </select>
      </div>
    </div>

    <div class="grid-3">
      <div>
        <label>RSVP team</label>
        <div class="row">
          <select id="rsvpSelect"><?= member_options($members) ?></select>
          <button type="button" class="btn" data-add="rsvp">Add</button>
        </div>
        <div id="rsvpList" class="stack"></div>
      </div>

      <div>
        <label>Hospitality team</label>
        <div class="row">
          <select id="hospitalitySelect"><?= member_options($members) ?></select>
          <button type="button" class="btn" data-add="hospitality">Add</button>
        </div>
        <div id="hospitalityList" class="stack"></div>
      </div>

      <div>
        <label>Transport team</label>
        <div class="row">
          <select id="transportSelect"><?= member_options($members) ?></select>
          <button type="button" class="btn" data-add="transport">Add</button>
        </div>
        <div id="transportList" class="stack"></div>
      </div>
    </div>

    <!-- hidden containers (JS adds inputs here) -->
    <div id="hiddenMembers"></div>
  </div>

  <div class="row">
    <a class="btn" href="<?= h(base_url('projects/index.php')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit">Save project</button>
  </div>
</form>

<script>
(function(){
  // ----- EVENTS -----
  const eventsWrap = document.getElementById('eventsWrap');
  const addEventBtn = document.getElementById('addEventBtn');

  function addEventRow(){
    const idx = eventsWrap.children.length;
    const row = document.createElement('div');
    row.className = 'eventRow';
    row.innerHTML = `
      <div class="grid-4">
        <div>
          <label>Event name</label>
          <input name="event_name[]" placeholder="Eg. Reception dinner" required>
        </div>
        <div>
          <label>Event date</label>
          <input name="event_date[]" type="date" required>
        </div>
        <div>
          <label>Event venue</label>
          <input name="event_venue[]" placeholder="Optional">
        </div>
        <div>
          <label>Hosting side</label>
          <select name="event_hosting_side[]">
            <option value="">—</option>
            <option value="bride">Bride’s side</option>
            <option value="groom">Groom’s side</option>
            <option value="collaborative">Collaborative</option>
          </select>
        </div>
      </div>
      <div style="margin-top:8px;">
        <button type="button" class="btn btn-danger removeEvent">Remove event</button>
      </div>
    `;
    row.querySelector('.removeEvent').addEventListener('click', ()=> row.remove());
    eventsWrap.appendChild(row);
  }

  addEventBtn.addEventListener('click', addEventRow);
  addEventRow(); // start with 1 required event

  // ----- MEMBERS (stacking) -----
  const hidden = document.getElementById('hiddenMembers');

  function addMember(dept){
    const select = document.getElementById(dept + 'Select');
    const memberId = select.value;
    if(!memberId) return;

    const label = select.options[select.selectedIndex].text;

    // prevent duplicates per dept
    if(hidden.querySelector(`input[name="${dept}_member_ids[]"][value="${memberId}"]`)) return;

    const pill = document.createElement('div');
    pill.className = 'pill';
    pill.innerHTML = `<span>${label}</span> <button type="button" class="x">×</button>`;

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = `${dept}_member_ids[]`;
    input.value = memberId;

    pill.querySelector('.x').addEventListener('click', ()=>{
      input.remove();
      pill.remove();
    });

    document.getElementById(dept + 'List').appendChild(pill);
    hidden.appendChild(input);

    select.value = '';
  }

  document.querySelectorAll('button[data-add]').forEach(btn=>{
    btn.addEventListener('click', ()=> addMember(btn.getAttribute('data-add')));
  });
})();
</script>

<?php include $root . '/includes/footer.php'; ?>