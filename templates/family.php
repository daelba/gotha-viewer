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

// rozměry plátna (zohledni i dup boxy, mohou ležet mimo mříž people)
$maxX = 0;
$maxY = 0;
foreach ($tree['people'] as $p) {
    $maxX = max($maxX, $p['x']);
    $maxY = max($maxY, $p['y']);
}
foreach ($tree['dups'] ?? [] as $d) {
    $maxX = max($maxX, $d['x']);
    $maxY = max($maxY, $d['y']);
}
$width = ($maxX + 1) * $cellW + 100;
$height = ($maxY + 1) * $cellH + 60;
$familyName = I18n::pick($family['labels']) ?: $family['id'];

/* -----------------------------------------------------------------------
 * Osoby, které jsou zároveň DÍTĚTEM ve své rodičovské rodině a RODIČEM
 * (f/m) ve své vlastní rodině (typicky sňatek v rámci stejné rodiny).
 * TreeBuilder::layout() jim vypočítá "duplikát" box (jako dítě) vedle
 * sourozenců a TreeBuilder::edges() ke dvojrolém kreslí čáry z rodičů
 * do duplikátu, ne do primární. Zde jen vykreslíme druhou instanci
 * spolu s ikonou pro navigaci mezi oběma boxy.
 * -------------------------------------------------------------------- */
$dups = $tree['dups'] ?? [];
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
        <!-- Hledání ve stromě + ovládání zoomu -->
        <div class="tree-toolbar">
            <div class="tree-search-wrap">
                <input type="search" id="tree-search" placeholder="<?= e(t('family.search')) ?>"
                       aria-label="<?= e(t('family.search')) ?>">
                <ul id="tree-search-results" class="tree-search-results"></ul>
            </div>
            <button type="button" class="zoom-btn" id="zoom-out" title="<?= e(t('family.zoom_out')) ?>" aria-label="<?= e(t('family.zoom_out')) ?>">−</button>
            <span class="zoom-level" id="zoom-level">100&nbsp;%</span>
            <button type="button" class="zoom-btn" id="zoom-in" title="<?= e(t('family.zoom_in')) ?>" aria-label="<?= e(t('family.zoom_in')) ?>">+</button>
            <button type="button" class="zoom-btn" id="zoom-reset" title="<?= e(t('family.zoom_reset')) ?>" aria-label="<?= e(t('family.zoom_reset')) ?>">1:1</button>
        </div>

        <div class="tree-viewport" id="tree-viewport">
            <div class="tree-canvas" id="tree-canvas">
                <svg id="tree-edges" width="<?= $width ?>" height="<?= $height ?>" aria-hidden="true">
                    <?php foreach ($edges as $edge): ?>
                        <path class="<?= e($edge['cls']) ?>" d="<?= e($edge['d']) ?>" fill="none"/>
                    <?php endforeach; ?>
                </svg>

                <?php
                /* Renderer jednoho boxu osoby. $opts:
                 *   nodeHtmlId – unikátní HTML id boxu (používá se pro navigaci mezi instancemi)
                 *   isDup      – true pro "duplikát" (druhá instance osoby v roli dítěte)
                 *   gotoId     – HTML id druhé instance (kam navigovat klikem na ikonu)
                 */
                $renderNode = static function (array $m, string $pid, int $x, int $y, array $opts) use ($base, $cellW, $cellH): void {
                    $label = I18n::pick($m['labels']) ?: $pid;
                    $otherFamilies = $m['other_families'] ?? [];
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
                    $left = $x * $cellW + 30;
                    $top = $y * $cellH + 20;
                    $nodeCls = 'node ' . $genderCls . ($opts['isDup'] ? ' node--dup' : '');
                    ?>
                    <div class="<?= e(trim($nodeCls)) ?>"
                         <?php if ($opts['nodeHtmlId'] !== null): ?>id="<?= e($opts['nodeHtmlId']) ?>"<?php endif; ?>
                         data-label="<?= e(mb_strtolower($label)) ?>"
                         data-id="<?= e($pid) ?>"
                         style="left:<?= $left ?>px;top:<?= $top ?>px">
                        <div class="name"><?= e($label) ?></div>
                        <?php if ($years): ?>
                            <div class="years"><?= e($years) ?></div>
                        <?php endif; ?>
                        <span class="node-other-families">
                            <?php if ($opts['gotoId'] !== null): ?>
                                <a class="node-family-link node-dup-link"
                                   href="#<?= e($opts['gotoId']) ?>"
                                   data-goto="<?= e($opts['gotoId']) ?>"
                                   title="<?= e(t('family.dup_link')) ?>"
                                   onclick="event.stopPropagation()">
                                    <img src="<?= e($base) ?>/icons/tree.svg"
                                         alt="<?= e(t('family.dup_link_icon')) ?>"
                                         width="16" height="16">
                                </a>
                            <?php endif; ?>
                            <?php foreach ($otherFamilies as $of):
                                $ofName = I18n::pick($of['labels']) ?: $of['id'];
                            ?>
                                <a class="node-family-link"
                                   href="<?= e($base) ?>/family/<?= e($of['id']) ?>#p=<?= e($pid) ?>"
                                   title="<?= e(sprintf(t('family.other_family'), $ofName)) ?>"
                                   onclick="event.stopPropagation()">
                                    <img src="<?= e($base) ?>/icons/tree.svg"
                                         alt="<?= e(t('family.other_family_icon')) ?>"
                                         width="16" height="16">
                                </a>
                            <?php endforeach; ?>
                        </span>
                    </div>
                    <?php
                };

                // 1. vykresli primární instance (všechny boxy z TreeBuilderu)
                foreach ($tree['people'] as $pid => $pos) {
                    $m = $byId[$pid] ?? null;
                    if ($m === null) {
                        continue;
                    }
                    // má-li osoba duplikát (je dvojrolá), přiřaď oběma instancím HTML id + propojení
                    $hasDup = isset($dups[$pid]);
                    $nodeHtmlId = $hasDup ? 'node-' . $pid : null;
                    $gotoId = $hasDup ? 'node-' . $pid . '-aschild' : null;
                    $renderNode($m, $pid, $pos['x'], $pos['y'], [
                        'nodeHtmlId' => $nodeHtmlId,
                        'isDup' => false,
                        'gotoId' => $gotoId,
                    ]);
                }

                // 2. vykresli duplikáty (druhá instance osoby v roli dítěte)
                foreach ($dups as $pid => $dpos) {
                    $m = $byId[$pid] ?? null;
                    if ($m === null) {
                        continue;
                    }
                    $renderNode($m, $pid, $dpos['x'], $dpos['y'], [
                        'nodeHtmlId' => 'node-' . $pid . '-aschild',
                        'isDup' => true,
                        'gotoId' => 'node-' . $pid,
                    ]);
                }
                ?>
            </div>
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
    base: '<?= e($base) ?>',
    panel: 'person-panel',
    panelContent: 'panel-content',
    panelClose: 'panel-close',
    loadingText: <?= json_encode(t('person.loading')) ?>,
    wikidataText: <?= json_encode(t('person.wikidata')) ?>
};
</script>
