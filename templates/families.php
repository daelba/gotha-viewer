<?php
/** @var array<int,array{id:string,labels:array{cs:?string,de:?string,en:?string},members:int,wd:?string}> $families */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if ($base === '\\' || $base === '.') {
    $base = '';
}
?>
<section class="families">
    <div class="toolbar">
        <input type="search" id="family-filter" placeholder="<?= e(t('filter.placeholder')) ?>"
               aria-label="<?= e(t('filter.placeholder')) ?>" autofocus>
        <span id="filter-count" class="muted"></span>
    </div>

    <?php if (empty($families)): ?>
        <p class="flash"><?= e(t('families.empty')) ?></p>
    <?php else: ?>
    <table class="families-table" id="families-table">
        <thead>
            <tr>
                <th data-sort="label"><?= e(t('table.name')) ?></th>
                <th data-sort="de"><?= e(t('table.name_de')) ?></th>
                <th data-sort="members" class="num"><?= e(t('table.members')) ?></th>
                <th><?= e(t('table.wikidata')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($families as $f):
            $label = I18n::pick($f['labels']) ?: $f['id'];
            $de = $f['labels']['de'] ?? '';
            $wd = $f['wd'];
        ?>
            <tr data-search="<?= e(mb_strtolower($label . ' ' . $de . ' ' . $f['id'])) ?>">
                <td>
                    <a href="<?= e($base) ?>/family/<?= e($f['id']) ?>"><?= e($label) ?></a>
                </td>
                <td><?= e($de) ?></td>
                <td class="num"><?= $f['members'] > 0 ? (int) $f['members'] : '–' ?></td>
                <td class="wd">
                    <?php if ($wd): ?>
                        <a href="https://www.wikidata.org/wiki/<?= e($wd) ?>" target="_blank" rel="noopener"
                           title="<?= e($wd) ?>">
                            <img src="<?= e($base) ?>/icons/wikidata.svg" alt="Wikidata" width="18" height="18">
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<script>
// data-attributes pro app.js
window.gv = {
    filterTable: 'families-table',
    filterInput: 'family-filter',
    filterCount: 'filter-count'
};
</script>
