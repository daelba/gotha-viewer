<?php
// Smoke test: osoba, která je rodičem i dítětem v rodině (dvojrole),
// se v šabloně family.php vykreslí dvakrát:
//   - primární box na pozici z TreeBuilder::layout
//   - duplikát s HTML id "node-{pid}-aschild" v rodičovské rodině
// Každá instance má odkazovou ikonu (class node-dup-link) na tu druhou.

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

// Minimální data: Joachim (otec Q100) nemá rodiče, jeho dcera Karoline (Q200)
// se provdala za svého strýce Karla (Q101). Karoline je v rodičovské rodině
// jako dítě (v c) a zároveň v nové rodině jako matka (m).
$defs = [
    // [id, gender, birth, father, mother, partners]
    ['Q100', 'm', '1700', null,   null,   ['Q201']], // Joachim (otec Karoline)
    ['Q201', 'f', '1700', null,   null,   ['Q100']], // matka Karoline (cizí)
    ['Q200', 'f', '1725', 'Q100', 'Q201', ['Q101']], // Karoline
    ['Q101', 'm', '1695', null,   null,   ['Q200']], // Karel (strýc + manžel)
];
$members = [];
foreach ($defs as [$id,$g,$b,$f,$m,$p]) {
    $members[] = [
        'id' => $id, 'gender' => $g, 'gender_label' => $g === 'm' ? 'muž' : 'žena',
        'birth' => $b, 'death' => null,
        'father' => $f, 'mother' => $m, 'partners' => $p,
        'labels' => ['cs' => "Person $id"], 'wd' => null,
        'titles' => [], 'other_families' => [],
    ];
}

$tree = TreeBuilder::layout($members);
$edges = TreeBuilder::edges($tree['families'], $tree['people']);

// Render šablony do stringu
ob_start();
$family = ['id' => 'QX', 'labels' => ['cs' => 'Test'], 'wd' => null];
$cellW = TreeBuilder::CELL_W;
$cellH = TreeBuilder::CELL_H;
$hasMembers = true;
require __DIR__ . '/../../templates/family.php';
$html = ob_get_clean();

$fails = [];
if (substr_count($html, 'data-id="Q200"') !== 2) {
    $fails[] = 'Očekávány 2 instance Q200 (rodič + dítě), nalezeno ' . substr_count($html, 'data-id="Q200"');
}
if (strpos($html, 'id="node-Q200"') === false) {
    $fails[] = 'Chybí id="node-Q200" (primární instance).';
}
if (strpos($html, 'id="node-Q200-aschild"') === false) {
    $fails[] = 'Chybí id="node-Q200-aschild" (duplikát instance).';
}
if (strpos($html, 'data-goto="node-Q200-aschild"') === false) {
    $fails[] = 'Q200 nemá odkaz na Q200-aschild (chybí ikona navigace v primárním boxu).';
}
if (strpos($html, 'data-goto="node-Q200"') === false) {
    $fails[] = 'Duplikát Q200 nemá odkaz zpět na primární (chybí ikona navigace).';
}

if ($fails) {
    foreach ($fails as $f) { fwrite(STDERR, "FAIL: $f\n"); }
    exit(1);
}
echo "OK\n";
exit(0);
