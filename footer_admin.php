    </main>
</div>
<nav class="s-tabs">
    <?php foreach ($nav_all as [$f, $l, $p]): ?>
        <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><?= s_icon($p) ?><span><?= $l ?></span></a>
    <?php endforeach; ?>
    <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><?= s_icon($ic_out) ?><span>Sign out</span></button></form>
</nav>
<script src="admin.js"></script>
</body>
</html>