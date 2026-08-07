<?php
// Regresní test: rodina Q10 (Khevenhüller-Metsch) — osoba Q852 (Johann Franz Khevenhüller)
// má svazek s manželkou Q851 (8 dětí) a zároveň dítě Q973 z nemanželského vztahu (matka neznámá).
// Q852 se nesmí přetáhnout níž (na úroveň dětí) ani se svazek s NULL nesmí
// objevit nahoře (vedlejší union nesmí stáhnout rodiče na jeho úroveň).

declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

$members = [
    // [id, gender, birth, father, mother, partners]
    ['Q680',  'm', '1628-01-06', null,   null,   []],
    ['Q852',  'm', '1667-10-13', 'Q680', null,   []],
    ['Q851',  'f', '1672',       null,   null,   []],
    ['Q861',  'f', '1690',       'Q852', 'Q851', []],
    ['Q862',  'f', '1691',       'Q852', 'Q851', []],
    ['Q863',  'm', '1692-04-19', 'Q852', 'Q851', []],
    ['Q975',  'f', '1700-11-22', null,   null,   []],
    ['Q976',  'm', '1724-11-26', 'Q863', 'Q975', []],
    ['Q977',  'm', '1741-01-16', 'Q863', 'Q975', []],
    ['Q864',  'f', '1693',       'Q852', 'Q851', []],
    ['Q865',  'f', '1697',       'Q852', 'Q851', []],
    ['Q866',  'f', '1699-04-25', 'Q852', 'Q851', []],
    ['Q867',  'f', '1702',       'Q852', 'Q851', []],
    ['Q868',  'm', '1698',       'Q852', 'Q851', []],
    ['Q973',  'm', '1701-12-14', 'Q852', null,   []], // účastník testu — dítě bez matky
    ['Q853',  'm', '1673',       'Q680', null,   []],
    ['Q856',  'f', '1676-04-30', null,   null,   []],
    ['Q858',  'm', '1706-11-15', 'Q853', 'Q856', []],
    ['Q859',  'm', '1708-05-28', 'Q853', 'Q856', []],
    ['Q857',  'm', '1678-08-01', 'Q680', null,   []],
    ['Q759',  'm', '1691',       'Q680', null,   []],
    ['Q681',  'm', '1694-06-18', 'Q680', null,   []],
    ['Q860',  'm', '1698-01-02', 'Q680', null,   []],
];

$keys = ['id','gender','birth','father','mother','partners','death','labels'];
$members = array_map(static function ($m) use ($keys) {
    $row = array_combine(['id','gender','birth','father','mother','partners'], $m);
    $row['death'] = null;
    $row['labels'] = ['cs' => $m[0]];
    return $row;
}, $members);

$r = TreeBuilder::layout($members);
$fails = [];
if (($r['people']['Q852']['y'] ?? null) !== 1) {
    $fails[] = 'Q852.y musí být 1, je ' . var_export($r['people']['Q852']['y'] ?? null, true);
}
// Q973 (nemanželské dítě Q852) musí být o 1 pod rodičem (y=2), ne pod fiktivní rodinou Q852|NULL (y=2+1).
if (($r['people']['Q973']['y'] ?? null) !== 2) {
    $fails[] = 'Q973.y musí být 2 (o 1 pod rodičem Q852 na y=1), je ' . var_export($r['people']['Q973']['y'] ?? null, true);
}
// svazek Q852|NULL (index 3 ve families) má být na y=2 (pod rodičem)
$foundNullFam = null;
foreach ($r['families'] as $fi => $fam) {
    if ($fam['f'] === 'Q852' && $fam['m'] === null) {
        $foundNullFam = $fam;
        break;
    }
}
if ($foundNullFam === null) {
    $fails[] = 'Svazek Q852|NULL nebyl nalezen ve families.';
} elseif ($foundNullFam['y'] !== 2) {
    $fails[] = 'Svazek Q852|NULL musí být na y=2, má y=' . $foundNullFam['y'];
}

if ($fails) {
    foreach ($fails as $f) { fwrite(STDERR, "FAIL: $f\n"); }
    exit(1);
}
echo "OK\n";
exit(0);
