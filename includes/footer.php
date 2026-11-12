<?php if ($user): ?>
        </main>
<?php else: ?>
</main>
<?php endif; ?>
<footer class="sitefooter">
    <div class="container">&copy; <?= date('Y') ?> <?= e(get_site_settings($pdo)['footer_text'] ?? ($siteSettings['site_name'] ?? APP_NAME)) ?></div>
</footer>
<?php if ($user): ?>
    </div>
</div>
<?php endif; ?>
</body>
</html>
