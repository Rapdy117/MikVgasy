<!-- Loading Spinner Overlay -->
<div id="spinner-overlay">
    <div class="spinner"></div>
</div>

<!-- Core JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Shared Notification System (avant tous les scripts page) -->
<script src="../js/toast.js?v=20260430a"></script>

<!-- Global Application JS -->
<script src="../js/sidebar.js?v=20260402a"></script>

<!-- Visual Animation Background -->
<script src="../js/background_animation.js"></script>

<!-- PWA : installable, sans mise en cache (donnees live, voir sw.js) -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('../sw.js').catch(() => {});
    });
}
</script>

<!-- Page Specific JS -->
<?php if (isset($extraJs) && is_array($extraJs)): ?>
    <?php foreach ($extraJs as $jsFile): ?>
        <script src="<?= htmlspecialchars($jsFile) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Inline Page Script Injection -->
<?php if (isset($extraScript)): ?>
    <script>
        <?= $extraScript ?>
    </script>
<?php endif; ?>

</body>
</html>
