<?php
/** @var array{id:string,labels:array,wd:?string} $family */
/** @var array<int,array> $members */
/** @var array{families:array,people:array,order:array} $tree */
/** @var array<int,array{cls:string,d:string}> $edges */
/** @var int $cellW */
/** @var int $cellH */
/** @var bool $hasMembers */

$byId = [];
foreach ($members as $m) {
    $byId[$m['id']] = $m;
}
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($base === '\\' || $base === '.') {
    $base = '';
}

// rozměry plátna
$maxX = 0;
$maxY = 0;
foreach ($tree['people'] as $p) {
    $maxX = max($maxX, $p['x']);
    $maxY = max($maxY, $p['y']);
}
$width = ($maxX + 1) * $cellW + 100;
$height = ($maxY + 1) * $cellH + 60;
$familyName = I18n::pick($family['labels']) ?: $family['id'];
?>
<section class="family">
    <p class="breadcrumb"><a href="<?= e($base) ?>/">&larr; <?= e(t('family.back')) ?></a></p>

    <h2><?= e($familyName) ?>
        <?php if ($family['wd']): ?>
            <a class="wd-link" href="https://www.wikidata.org/wiki/<?= e($family['wd']) ?>" target="_blank" rel="noopener">
                <img src="<?= e($base) ?>/icons/wikidata.svg" alt="Wikidata" width="16" height="16">
            </a>
        <?php endif; ?>
    </h2>

    <?php if (!$hasMembers): ?>
        <p class="flash"><?= e(t('family.no_members')) ?></p>

    <?php else: ?>
        <!-- Hledání ve stromě -->
        <div class="tree-search-wrap">
            <input type="search" id="tree-search" placeholder="<?= e(t('family.search')) ?>"
                   aria-label="<?= e(t('family.search')) ?>">
            <ul id="tree-search-results" class="tree-search-results"></ul>
        </div>

        <div class="tree-canvas" id="tree-canvas">
            <svg id="tree-edges" width="<?= $width ?>" height="<?= $height ?>" aria-hidden="true">
                <?php foreach ($edges as $edge): ?>
                    <path class="<?= e($edge['cls']) ?>" d="<?= e($edge['d']) ?>" fill="none"/>
                <?php endforeach; ?>
            </svg>

            <?php foreach ($tree['people'] as $pid => $pos):
                $m = $byId[$pid] ?? null;
                if ($m === null) {
                    continue;
                }
                $label = I18n::pick($m['labels']) ?: $pid;
                $birth = $m['birth'] ?? null;
                $death = $m['death'] ?? null;
                $years = '';
                if ($birth || $death) {
                    $years = ($birth ? substr($birth, 0, 4) : '?') . '–' . ($death ? substr($death, 0, 4) : '');
                }
                // Rozhodnutí o pohlaví: dle labelu entity (muž/man/Mann/male → male, žena/woman/Frau/female → female)
                $gLabel = mb_strtolower(trim((string)($m['gender_label'] ?? '')));
                $gLabelN = strtr($gLabel, ['ž'=>'z','ů'=>'u','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ý'=>'y','ě'=>'e','š'=>'s','č'=>'c','ř'=>'r','ď'=>'d','ť'=>'t','ň'=>'n']);
                $genderCls = '';
                if (in_array($gLabelN, ['muz','male','man','mann'], true)) {
                    $genderCls = 'node--male';
                } elseif (in_array($gLabelN, ['zena','female','woman','frau'], true) ||
                          substr($gLabelN, 0, 3) === 'zen') {
                    $genderCls = 'node--female';
                }
                // +30px X a +20px Y = shoda s padding .tree-canvas a offsetem SVG
                $left = $pos['x'] * $cellW + 30;
                $top = $pos['y'] * $cellH + 20;
            ?>
                <div class="node <?= e($genderCls) ?>"
                     data-label="<?= e(mb_strtolower($label)) ?>"
                     data-id="<?= e($pid) ?>"
                     style="left:<?= $left ?>px;top:<?= $top ?>px">
                    <div class="name"><?= e($label) ?></div>
                    <?php if ($years): ?>
                        <div class="years"><?= e($years) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Postranní panel -->
<aside class="person-panel" id="person-panel" aria-hidden="true">
    <button class="close" id="panel-close" aria-label="<?= e(t('person.close')) ?>">&times;</button>
    <div id="panel-content"><p class="muted"><?= e(t('person.loading')) ?></p></div>
</aside>

<script>
window.gvFamily = {
    searchInput: 'tree-search',
    resultsList: 'tree-search-results',
    canvas: 'tree-canvas',
    api: '<?= e($base) ?>/api/person/',
    panel: 'person-panel',
    panelContent: 'panel-content',
    panelClose: 'panel-close',
    loadingText: <?= json_encode(t('person.loading')) ?>,
    wikidataText: <?= json_encode(t('person.wikidata')) ?>
};
</script>
