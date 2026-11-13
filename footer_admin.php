    </main>
</div>
<nav class="s-tabs">
    <?php foreach (array_merge($nav, [['profile.php', 'Account', 'acc']]) as [$f, $l, $i]): ?>
        <a href="<?= $f ?>" class="<?= $cur === $f ? 'on' : '' ?>"><?= icon($i) ?><span><?= $l ?></span></a>
    <?php endforeach; ?>
    <form method="post" action="logout.php"><?= csrf_field() ?><button type="submit"><?= icon('out') ?><span>Sign out</span></button></form>
</nav>
<script src="app.js"></script>
</body>
</html>
