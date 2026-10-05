<?php
// The end of every page: the message area, the footer (_footer.php) and the scripts ($scripts lists extra ones).
?>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true">
</div>
<?= $after_main ?? '' ?>
<?php require APP_ROOT . '/templates/_footer.php'; ?>
<script src="/static/js/page.js?v=<?= APP_VERSION ?>"></script>
<script src="/static/js/charts.js?v=<?= APP_VERSION ?>"></script>
<?php foreach ($scripts ?? [] as $script): ?><script src="/static/js/<?= h($script) ?>?v=<?= APP_VERSION ?>"></script>
<?php endforeach; ?>
</body></html>
