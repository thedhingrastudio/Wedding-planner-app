<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

echo "<main style='padding:24px;'>";
try {
  $pdo = get_pdo();

  // heartbeat check
  $row = $pdo->query("SELECT 1 AS ok")->fetch();

  echo "<h1>DB Connected ✅</h1>";
  echo "<pre>"; print_r($row); echo "</pre>";

  // fetch real data
  echo "<h2>Companies table</h2>";
  $companies = $pdo->query("SELECT id, name, theme_color, created_at FROM companies ORDER BY id DESC")->fetchAll();

  if (!$companies) {
    echo "<p>No companies found yet.</p>";
  } else {
    echo "<table border='1' cellpadding='8' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Name</th><th>Theme</th><th>Created</th></tr>";
    foreach ($companies as $c) {
      echo "<tr>";
      echo "<td>" . htmlspecialchars($c['id']) . "</td>";
      echo "<td>" . htmlspecialchars($c['name']) . "</td>";
      echo "<td>" . htmlspecialchars($c['theme_color']) . "</td>";
      echo "<td>" . htmlspecialchars($c['created_at']) . "</td>";
      echo "</tr>";
    }
    echo "</table>";
  }

} catch (Throwable $e) {
  echo "<h1>DB Connection Failed ❌</h1>";
  echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
echo "</main>";

require_once __DIR__ . '/../includes/footer.php';