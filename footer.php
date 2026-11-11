<?php include is_admin() ? 'footer_admin.php' : 'footer_student.php'; return; ?>
 </main>
</div>

<nav class="bottomnav">
    <?php foreach (array_merge($main_items, $admin_items) as [$file, $label, $svg]): ?>
        <a href="<?= $file ?>" class="<?= $cur === $file ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><?= $svg ?></svg><span><?= $label ?></span>
        </a>
    <?php endforeach; ?>

                <a href="profile.php" class="<?= $cur === 'profile.php' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><span>Profile</span>
    </a>

    <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit"><svg viewBox="0 0 24 24"><?= $logout_svg ?></svg><span>Log out</span></button>
    </form>
</nav>
</body>
</html>