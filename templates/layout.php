<?php
/** @var string $content */
/** @var ?string $title */
$lang = I18n::lang();
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($base === '\\' || $base === '.') {
    $base = '';
}
// Cíl tlačítka „Aktualizovat data" – aktuální stránka (nelze spoléhat na
// Referer, proxy nasazuje Referrer-Policy: no-referrer).
$refreshBack = '/';
$famId = $_GET['family'] ?? '';
if (is_string($famId) && preg_match('/^Q[1-9][0-9]*$/', $famId) === 1) {
    $refreshBack = '/family/' . $famId;
}
?>
<!doctype html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title !== null && $title !== '' ? e($title) . ' · ' : '' ?><?= e(t('app.title')) ?></title>
    <link rel="stylesheet" href="<?= e($base) ?>/css/style.css">
</head>
<body>
    <header class="site-header">
        <h1><a href="<?= e($base) ?>/"><?= e(t('app.title')) ?></a></h1>
        <p class="subtitle"><?= e(t('app.subtitle')) ?></p>
        <nav class="lang-switch">
            <?php foreach (I18n::LANGS as $l):
                $flag = $base . '/icons/flag-' . $l . '.svg';
                $active = $lang === $l ? ' class="active"' : '';
                $href = $base . '/?lang=' . $l;
            ?>
                <a<?= $active ?> href="<?= e($href) ?>" title="<?= e(t('lang.' . $l)) ?>">
                    <img src="<?= e($flag) ?>" alt="<?= e($l) ?>" width="20" height="15">
                </a>
            <?php endforeach; ?>
        </nav>
        <a class="action-refresh" href="<?= e($base) ?>/refresh?back=<?= rawurlencode($refreshBack) ?>" title="<?= e(t('action.refresh')) ?>">
            &#x21bb; <?= e(t('action.refresh')) ?>
        </a>
    </header>

    <main>
        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="flash flash-notice"><?= e($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="footer-left">
            <a href="https://www.hiu.cas.cz/" target="_blank" rel="noopener" title="Historický ústav AV ČR, v. v. i.">
                <img src="<?= e($base) ?>/icons/logo-hiu.svg" alt="Historický ústav AV ČR, v. v. i.">
                <span class="hiu-name">Historický ústav<br>AV ČR, v. v. i.</span>
            </a>
        </div>
        <div class="footer-right">
            <a href="https://gotha-wb.daelba.eu" target="_blank" rel="noopener" title="Powered by Wikibase">
                <img src="<?= e($base) ?>/icons/powered-by-wikibase.svg" alt="Powered by Wikibase">
            </a>
            <a href="https://creativecommons.org/licenses/by-sa/4.0/deed.cs" target="_blank" rel="noopener" title="CC BY-SA 4.0">
                <img src="<?= e($base) ?>/icons/cc-by-sa.svg" alt="CC BY-SA">
            </a>
        </div>
    </footer>

    <script src="<?= e($base) ?>/js/app.js" defer></script>
</body>
</html>
