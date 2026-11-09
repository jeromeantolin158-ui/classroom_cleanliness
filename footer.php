    </main>
</div>

<nav class="bottomnav">
    <?php foreach (array_merge($main_items, $admin_items) as [$file, $label, $svg]): ?>
        <a href="<?= $file ?>" class="<?= $cur === $file ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><?= $svg ?></svg><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>
    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit"><svg viewBox="0 0 24 24"><?= $logout_svg ?></svg><span>Log out</span></button>
    </form>
</nav>
</body>
</html>