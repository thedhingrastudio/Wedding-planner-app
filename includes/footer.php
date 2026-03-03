<?php
// includes/footer.php
require_once __DIR__ . '/functions.php';

$jsSrc = asset_url_existing([
  'js/main.js',
  'projects/js/main.js',
]);
?>
<script src="<?php echo h(base_url('js/main.js')); ?>" defer></script>
<script defer src="<?= h(base_url('js/toast.js')) ?>"></script>
</body>
</html>