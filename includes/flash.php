<?php
// includes/flash.php
require_once __DIR__ . '/functions.php';

function show_flash(): void {
  $items = flash_pop_all();
  if (!$items) return;

  // Toast container (fixed, not in document flow)
  echo '<div class="toast-wrap" data-toast-wrap aria-live="polite" aria-atomic="true">';

  foreach ($items as $it) {
    $type = (string)($it['type'] ?? 'info');
    $msg  = (string)($it['message'] ?? '');

    // normalize type for css
    $type = in_array($type, ['success','error','warning','info'], true) ? $type : 'info';

    echo '<div class="toast toast-' . h($type) . '" data-toast>';
    echo '  <div class="toast-msg">' . h($msg) . '</div>';
    echo '  <button class="toast-x" type="button" aria-label="Dismiss" data-toast-close>×</button>';
    echo '</div>';
  }

  echo '</div>';
}