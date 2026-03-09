<?php
declare(strict_types=1);

$root = __DIR__;
while ($root !== dirname($root) && !is_dir($root . '/includes')) {
  $root = dirname($root);
}

require_once $root . '/includes/app_start.php';
require_login();

$pdo = $pdo ?? get_pdo();

$projectId = (int)($_GET['id'] ?? $_GET['project_id'] ?? 0);
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

function parse_money_value($value): float {
  if ($value === null) return 0.0;
  $raw = trim((string)$value);
  if ($raw === '') return 0.0;

  $raw = str_replace([',', '₹', 'Rs.', 'Rs', 'INR', 'inr'], '', $raw);
  $raw = trim($raw);

  return is_numeric($raw) ? (float)$raw : 0.0;
}

function extract_highest_money_from_text(string $text): float {
  preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);
  if (empty($matches[0])) return 0.0;

  $nums = [];
  foreach ($matches[0] as $m) {
    $nums[] = (float)str_replace(',', '', $m);
  }
  return $nums ? max($nums) : 0.0;
}

function infer_project_total_amount(array $project): float {
  $numericKeys = [
    'budget_total',
    'quoted_total',
    'quoted_amount',
    'contract_total',
    'total_budget',
    'budget_amount',
    'budget_max',
    'budget_to',
    'price_total',
    'total_fee',
  ];

  foreach ($numericKeys as $key) {
    if (isset($project[$key]) && trim((string)$project[$key]) !== '') {
      $v = parse_money_value($project[$key]);
      if ($v > 0) return $v;
    }
  }

  $textKeys = [
    'budget_range',
    'budget',
    'budget_label',
    'budget_band',
    'budget_bracket',
  ];

  foreach ($textKeys as $key) {
    if (!empty($project[$key])) {
      $v = extract_highest_money_from_text((string)$project[$key]);
      if ($v > 0) return $v;
    }
  }

  return 0.0;
}

function detect_budget_label(array $project): string {
  $labelKeys = ['budget_range', 'budget', 'budget_label', 'budget_band', 'budget_bracket'];
  foreach ($labelKeys as $key) {
    if (!empty($project[$key])) {
      return trim((string)$project[$key]);
    }
  }

  $amount = infer_project_total_amount($project);
  return $amount > 0 ? format_inr($amount) : '—';
}

function format_inr(float $amount): string {
  $negative = $amount < 0;
  $amount = abs($amount);

  $parts = explode('.', number_format($amount, 2, '.', ''));
  $integer = $parts[0];
  $decimal = $parts[1] ?? '00';

  if (strlen($integer) > 3) {
    $last3 = substr($integer, -3);
    $rest = substr($integer, 0, -3);
    $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest);
    $integer = $rest . ',' . $last3;
  }

  return ($negative ? '-₹ ' : '₹ ') . $integer . '.' . $decimal;
}

/* ---------- Tables ---------- */
$termsTableReady = table_exists_local($pdo, 'project_payment_terms');
$milestonesTableReady = table_exists_local($pdo, 'project_payment_milestones');

$errors = [];
$warnings = [];

if (!$termsTableReady || !$milestonesTableReady) {
  if (!$termsTableReady) $errors[] = 'Missing table: project_payment_terms';
  if (!$milestonesTableReady) $errors[] = 'Missing table: project_payment_milestones';
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

/* ---------- Budget ---------- */
$projectBudgetTotal = infer_project_total_amount($project);
$projectBudgetLabel = detect_budget_label($project);

if ($projectBudgetTotal <= 0) {
  $warnings[] = 'Could not detect the project budget automatically. Update the candidate budget column names in contract_payment_terms.php if needed.';
}

/* ---------- Existing data ---------- */
$terms = null;
$milestones = [];

if ($termsTableReady) {
  try {
    $st = $pdo->prepare("
      SELECT *
      FROM project_payment_terms
      WHERE project_id = :pid
      LIMIT 1
    ");
    $st->execute([':pid' => $projectId]);
    $terms = $st->fetch() ?: null;
  } catch (Throwable $e) {
    $terms = null;
  }
}

if ($terms && $milestonesTableReady) {
  try {
    $ms = $pdo->prepare("
      SELECT *
      FROM project_payment_milestones
      WHERE payment_terms_id = :tid
      ORDER BY sort_order ASC, id ASC
    ");
    $ms->execute([':tid' => (int)$terms['id']]);
    $milestones = $ms->fetchAll() ?: [];
  } catch (Throwable $e) {
    $milestones = [];
  }
}

/* ---------- Current values ---------- */
$totalAmount = $projectBudgetTotal > 0
  ? $projectBudgetTotal
  : (float)($terms['total_amount'] ?? 0);

$overallDueDate = (string)($terms['overall_due_date'] ?? '');
$gstNote = trim((string)($terms['gst_note'] ?? ''));

if ($gstNote === '' && stripos($projectBudgetLabel, 'gst') !== false) {
  $gstNote = '+ GST';
}

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
  $overallDueDate = posted('overall_due_date');
  $gstNote = posted('gst_note');

  $phaseLabels = $_POST['phase_label'] ?? [];
  $amounts = $_POST['milestone_amount'] ?? [];
  $dueDates = $_POST['milestone_due_date'] ?? [];

  $phaseLabels = is_array($phaseLabels) ? $phaseLabels : [];
  $amounts = is_array($amounts) ? $amounts : [];
  $dueDates = is_array($dueDates) ? $dueDates : [];

  $rowCount = max(count($phaseLabels), count($amounts), count($dueDates));
  $cleanRows = [];
  $sum = 0.0;

  for ($i = 0; $i < $rowCount; $i++) {
    $phase = trim((string)($phaseLabels[$i] ?? ''));
    $amount = parse_money_value($amounts[$i] ?? '');
    $dueDate = trim((string)($dueDates[$i] ?? ''));

    $isBlank = ($phase === '' && $amount <= 0 && $dueDate === '');
    if ($isBlank) continue;

    if ($phase === '') $errors[] = 'Each milestone needs a phase name.';
    if ($amount <= 0) $errors[] = 'Each milestone needs an amount greater than 0.';
    if ($dueDate === '') $errors[] = 'Each milestone needs a due date.';

    $cleanRows[] = [
      'phase_label' => $phase,
      'amount' => round($amount, 2),
      'due_date' => $dueDate !== '' ? $dueDate : null,
    ];

    $sum += round($amount, 2);
  }

  if ($cleanRows && abs($sum - $totalAmount) > 0.01) {
    $errors[] = 'Milestone total must exactly match the total payment quotation.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $userId = (int)($_SESSION['user_id'] ?? 0);

      if ($terms) {
        $up = $pdo->prepare("
          UPDATE project_payment_terms
          SET total_amount = :total_amount,
              overall_due_date = :overall_due_date,
              gst_note = :gst_note,
              updated_by = :updated_by,
              updated_at = NOW()
          WHERE id = :id
        ");
        $up->execute([
          ':total_amount' => $totalAmount,
          ':overall_due_date' => $overallDueDate !== '' ? $overallDueDate : null,
          ':gst_note' => $gstNote !== '' ? $gstNote : null,
          ':updated_by' => $userId > 0 ? $userId : null,
          ':id' => (int)$terms['id'],
        ]);
        $termsId = (int)$terms['id'];
      } else {
        $ins = $pdo->prepare("
          INSERT INTO project_payment_terms (
            project_id, total_amount, overall_due_date, gst_note, created_by, updated_by, created_at, updated_at
          ) VALUES (
            :project_id, :total_amount, :overall_due_date, :gst_note, :created_by, :updated_by, NOW(), NOW()
          )
        ");
        $ins->execute([
          ':project_id' => $projectId,
          ':total_amount' => $totalAmount,
          ':overall_due_date' => $overallDueDate !== '' ? $overallDueDate : null,
          ':gst_note' => $gstNote !== '' ? $gstNote : null,
          ':created_by' => $userId > 0 ? $userId : null,
          ':updated_by' => $userId > 0 ? $userId : null,
        ]);
        $termsId = (int)$pdo->lastInsertId();
      }

      $pdo->prepare("DELETE FROM project_payment_milestones WHERE payment_terms_id = :tid")
          ->execute([':tid' => $termsId]);

      if ($cleanRows) {
        $mi = $pdo->prepare("
          INSERT INTO project_payment_milestones (
            payment_terms_id, sort_order, phase_label, amount, due_date, created_at, updated_at
          ) VALUES (
            :payment_terms_id, :sort_order, :phase_label, :amount, :due_date, NOW(), NOW()
          )
        ");

        foreach ($cleanRows as $index => $row) {
          $mi->execute([
            ':payment_terms_id' => $termsId,
            ':sort_order' => $index + 1,
            ':phase_label' => $row['phase_label'],
            ':amount' => $row['amount'],
            ':due_date' => $row['due_date'],
          ]);
        }
      }

      $pdo->commit();

      if (function_exists('flash_set')) {
        flash_set('success', 'Payment terms saved successfully.');
      }

      redirect('projects/contract.php?id=' . $projectId);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }

  if ($errors) {
    $milestones = [];
    foreach ($cleanRows as $row) {
      $milestones[] = [
        'phase_label' => $row['phase_label'],
        'amount' => $row['amount'],
        'due_date' => $row['due_date'],
      ];
    }
  }
}

$pageTitle = 'Payment terms — ' . $projectTitle . ' — Vidhaan';
require_once $root . '/includes/header.php';
?>

<style>
.pt-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:18px;
  margin-bottom:14px;
}
.pt-title{
  margin:0;
  font-size:22px;
  font-weight:800;
  color:#1f1f22;
}
.pt-sub{
  margin:6px 0 0 0;
  color:#6f6f73;
  font-size:13px;
}
.pt-actions{
  display:flex;
  gap:12px;
  align-items:center;
  flex-wrap:wrap;
}
.pt-actions .icon-btn{
  width:42px;
  height:42px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
}
.pt-toolbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:16px;
  margin-bottom:14px;
  flex-wrap:wrap;
}
.pt-search{
  position:relative;
  width:min(360px, 100%);
}
.pt-search span{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:#8c8c92;
  font-size:12px;
}
.pt-search input{
  width:100%;
  min-height:40px;
  border-radius:999px;
  border:1px solid rgba(0,0,0,0.12);
  background:#fff;
  padding:10px 14px 10px 34px;
  box-sizing:border-box;
}
.pt-card{
  padding:16px;
  border-radius:24px;
}
.pt-card h3{
  margin:0;
  font-size:18px;
  font-weight:800;
  color:#222;
}
.pt-card p{
  margin:4px 0 12px 0;
  color:#75757a;
  font-size:13px;
}
.pt-divider{
  height:1px;
  background:rgba(0,0,0,0.08);
  margin:12px 0 10px;
}
.pt-grid-2{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
}
.pt-grid-3{
  display:grid;
  grid-template-columns:1fr 1fr 1fr auto;
  gap:14px;
  align-items:end;
}
@media (max-width:1080px){
  .pt-grid-2,
  .pt-grid-3{
    grid-template-columns:1fr;
  }
}
.pt-field{
  display:flex;
  flex-direction:column;
  gap:7px;
}
.pt-field label{
  font-size:12px;
  font-weight:700;
  color:#68686d;
}
.pt-field input{
  width:100%;
  min-height:44px;
  border-radius:16px;
  border:1px solid rgba(0,0,0,0.08);
  background:#f7f7f8;
  padding:11px 14px;
  box-sizing:border-box;
  font:inherit;
}
.pt-field input[readonly]{
  color:#303036;
}
.pt-milestone-row{
  padding:10px 0;
}
.pt-remove{
  min-width:44px;
  height:44px;
  border-radius:16px;
}
.pt-add-wrap{
  margin-top:10px;
}
.pt-summary{
  margin-top:14px;
  color:#7a7a80;
  font-size:14px;
}
.pt-summary strong{
  color:#3c3c42;
}
.pt-balance{
  margin-top:8px;
  font-size:13px;
  color:#6e6e74;
}
.pt-balance.ok{
  color:#3e6b35;
}
.pt-balance.bad{
  color:#9b2c2c;
}
.pt-note{
  margin-top:10px;
  padding:12px 14px;
  border-radius:16px;
  background:#faf7e8;
  color:#6d5d2d;
  font-size:12px;
  line-height:1.5;
}
.pt-alert{
  margin-bottom:14px;
  padding:14px 16px;
  border-radius:18px;
}
.pt-alert.error{
  background:#fff5f5;
  border:1px solid rgba(185,28,28,.14);
}
.pt-alert.warn{
  background:#fffaf0;
  border:1px solid rgba(180,120,20,.15);
}
.pt-alert h4{
  margin:0 0 8px 0;
  font-size:16px;
}
.pt-alert ul{
  margin:0 0 0 18px;
  padding:0;
  line-height:1.7;
}
@media (max-width:980px){
  .pt-head{
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
      </div>

      <div class="project-shell">
        <?php
          $active = 'contract';
          require_once $root . '/includes/project_sidebar.php';
        ?>

        <div class="proj-main">
          <?php if ($errors): ?>
            <div class="pt-alert error">
              <h4>Please fix these fields</h4>
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?php echo esc($error); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($warnings): ?>
            <div class="pt-alert warn">
              <h4>Heads up</h4>
              <ul>
                <?php foreach ($warnings as $warning): ?>
                  <li><?php echo esc($warning); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="post" id="payment-terms-form" autocomplete="off">
            <div class="pt-head">
              <div>
                <h1 class="pt-title">Contract &amp; scope &nbsp;›&nbsp; Payment terms</h1>
                <div class="pt-sub">What we need from the client to keep timelines on track.</div>
              </div>

              <div class="pt-actions">
                <button class="btn icon-btn" type="button" title="Download">⬇</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
                <a class="btn" href="<?php echo esc(base_url('projects/contract_payment_terms.php?id=' . $projectId)); ?>">Discard changes</a>
              </div>
            </div>

            <div class="pt-toolbar">
              <div class="pt-search">
                <span>⌕</span>
                <input type="text" id="milestone-search" placeholder="Search milestone">
              </div>
            </div>

            <div class="card proj-card pt-card">
              <h3>Service information</h3>
              <p>Track payment phases and key due dates.</p>

              <div class="pt-divider"></div>

              <div class="pt-grid-2">
                <div class="pt-field">
                  <label>Total payment quotation</label>
                  <input type="text" readonly value="<?php echo esc($projectBudgetLabel !== '—' ? $projectBudgetLabel : format_inr($totalAmount)); ?>">
                  <input type="hidden" id="total-amount" value="<?php echo esc(number_format($totalAmount, 2, '.', '')); ?>">
                </div>

                <div class="pt-field">
                  <label>Due date</label>
                  <input type="date" name="overall_due_date" value="<?php echo esc($overallDueDate); ?>">
                </div>
              </div>

              <input type="hidden" name="gst_note" value="<?php echo esc($gstNote); ?>">

              <div id="milestone-list">
                <?php foreach ($milestones as $idx => $row): ?>
                  <div class="pt-milestone-row" data-row>
                    <div class="pt-grid-3">
                      <div class="pt-field">
                        <label>Phase</label>
                        <input type="text" name="phase_label[]" value="<?php echo esc((string)($row['phase_label'] ?? '')); ?>" placeholder="e.g. Deposit, Milestone 1, Final payment">
                      </div>

                      <div class="pt-field">
                        <label>Amount</label>
                        <input type="number" step="0.01" min="0" name="milestone_amount[]" value="<?php echo esc(number_format((float)($row['amount'] ?? 0), 2, '.', '')); ?>" data-amount-input placeholder="0.00">
                      </div>

                      <div class="pt-field">
                        <label>Due date</label>
                        <input type="date" name="milestone_due_date[]" value="<?php echo esc((string)($row['due_date'] ?? '')); ?>">
                      </div>

                      <button class="btn pt-remove" type="button" data-remove-row title="Delete milestone">✕</button>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <div class="pt-add-wrap">
                <button class="btn" type="button" id="add-milestone-btn">＋ Add milestone</button>
              </div>

              <div class="pt-summary">
                Total fee: <strong id="total-fee-label"><?php echo esc(format_inr($totalAmount)); ?><?php echo $gstNote !== '' ? ' ' . esc($gstNote) : ''; ?></strong>
              </div>

              <div class="pt-balance" id="milestone-balance-text"></div>

              <div class="pt-note">
                The milestone total must always match the total quotation. When you edit one milestone, the last milestone auto-adjusts to keep the schedule balanced.
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </section>
</div>

<template id="milestone-row-template">
  <div class="pt-milestone-row" data-row>
    <div class="pt-grid-3">
      <div class="pt-field">
        <label>Phase</label>
        <input type="text" name="phase_label[]" placeholder="e.g. Deposit, Milestone 1, Final payment">
      </div>

      <div class="pt-field">
        <label>Amount</label>
        <input type="number" step="0.01" min="0" name="milestone_amount[]" value="0.00" data-amount-input placeholder="0.00">
      </div>

      <div class="pt-field">
        <label>Due date</label>
        <input type="date" name="milestone_due_date[]">
      </div>

      <button class="btn pt-remove" type="button" data-remove-row title="Delete milestone">✕</button>
    </div>
  </div>
</template>

<script>
(function () {
  const totalAmount = parseFloat(document.getElementById('total-amount')?.value || '0') || 0;
  const list = document.getElementById('milestone-list');
  const addBtn = document.getElementById('add-milestone-btn');
  const tpl = document.getElementById('milestone-row-template');
  const balanceText = document.getElementById('milestone-balance-text');
  const searchInput = document.getElementById('milestone-search');

  function rows() {
    return Array.from(list.querySelectorAll('[data-row]'));
  }

  function amountInputs() {
    return Array.from(list.querySelectorAll('[data-amount-input]'));
  }

  function round2(num) {
    return Math.round((num + Number.EPSILON) * 100) / 100;
  }

  function getAmount(input) {
    return round2(parseFloat(input.value || '0') || 0);
  }

  function setAmount(input, value) {
    input.value = round2(Math.max(0, value)).toFixed(2);
  }

  function updateBalanceText() {
    const sum = round2(amountInputs().reduce((acc, el) => acc + getAmount(el), 0));
    const diff = round2(totalAmount - sum);

    if (!balanceText) return;

    if (rows().length === 0) {
      balanceText.className = 'pt-balance';
      balanceText.textContent = 'No milestones added yet.';
      return;
    }

    if (Math.abs(diff) <= 0.01) {
      balanceText.className = 'pt-balance ok';
      balanceText.textContent = 'Milestones balanced. Total matches the quotation.';
    } else {
      balanceText.className = 'pt-balance bad';
      balanceText.textContent = 'Milestone total is off by ₹ ' + diff.toFixed(2) + '.';
    }
  }

  function autoBalance(changedInput = null) {
    const inputs = amountInputs();
    if (!inputs.length) {
      updateBalanceText();
      return;
    }

    if (inputs.length === 1) {
      setAmount(inputs[0], totalAmount);
      updateBalanceText();
      return;
    }

    let absorber = inputs[inputs.length - 1];
    if (changedInput && absorber === changedInput) {
      absorber = inputs[inputs.length - 2];
    }

    let sumWithoutAbsorber = 0;
    inputs.forEach((input) => {
      if (input !== absorber) {
        sumWithoutAbsorber += getAmount(input);
      }
    });

    setAmount(absorber, round2(totalAmount - sumWithoutAbsorber));
    updateBalanceText();
  }

  function nextPhaseLabel() {
    const count = rows().length + 1;
    return count === 1 ? 'Deposit' : 'Milestone ' + count;
  }

  function addRow() {
    const fragment = tpl.content.cloneNode(true);

    list.appendChild(fragment);

    const currentRows = rows();
    const currentInputs = amountInputs();
    const currentPhase = currentRows[currentRows.length - 1]?.querySelector('input[name="phase_label[]"]');

    if (currentPhase) {
      currentPhase.value = nextPhaseLabel();
    }

    if (currentRows.length === 1) {
      setAmount(currentInputs[0], totalAmount);
    } else {
      const previous = currentInputs[currentInputs.length - 2];
      const current = currentInputs[currentInputs.length - 1];
      const previousAmount = getAmount(previous);
      const split = round2(previousAmount / 2);
      setAmount(current, split);
      setAmount(previous, round2(previousAmount - split));
      autoBalance(current);
    }

    attachRowEvents();
    updateBalanceText();
  }

  function removeRow(btn) {
    const row = btn.closest('[data-row]');
    if (!row) return;
    row.remove();
    autoBalance();
  }

  function attachRowEvents() {
    rows().forEach((row) => {
      const removeBtn = row.querySelector('[data-remove-row]');
      const amountInput = row.querySelector('[data-amount-input]');

      if (removeBtn && removeBtn.dataset.bound !== '1') {
        removeBtn.dataset.bound = '1';
        removeBtn.addEventListener('click', function () {
          removeRow(removeBtn);
        });
      }

      if (amountInput && amountInput.dataset.bound !== '1') {
        amountInput.dataset.bound = '1';
        amountInput.addEventListener('input', function () {
          autoBalance(amountInput);
        });
      }
    });
  }

  if (addBtn) {
    addBtn.addEventListener('click', addRow);
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      const q = searchInput.value.trim().toLowerCase();
      rows().forEach((row) => {
        const phaseInput = row.querySelector('input[name="phase_label[]"]');
        const text = (phaseInput?.value || '').toLowerCase();
        row.style.display = q === '' || text.includes(q) ? '' : 'none';
      });
    });
  }

  attachRowEvents();
  updateBalanceText();
})();
</script>

<?php require_once $root . '/includes/footer.php'; ?>