<?php
declare(strict_types=1);

/**
 * Layout genealogického stromu.
 *
 * Algoritmus: bottom-up shromažďování nejmenších společných předků.
 *  1. Vybere pár (otec, matka) se společnými dětmi.
 *  2. Posune rodiče nad sebe (má stejné y). Pokud je rodina "dítě" vyšší rodiny,
 *     rodiče jdou pod ni.
 *  3. Opakuje, dokud lze.
 *  4. Výsledek je pole rodin; každá rodina je {x, y, f, m} (souřadnice v mřížkových jednotkách).
 *  5. Pokud zbývají osamělí lidé (bez vazeb), umístí je do mřížky pod rodiny.
 *
 * Souřadnice se v šabloně násobí konstantami CELL_W / CELL_H (pixely).
 */
final class TreeBuilder
{
    public const CELL_W = 230;  // px, šířka buňky (box 210px + mezery)
    public const CELL_H = 150;  // px, výška buňky (box + čára)
    // horizontální střed boxu (210px / 2 = 105) v rámci buňky
    public const BOX_CENTER_X = 105;

    // SVG #tree-edges je posunuté o top:20 → horní hrana dětského boxu = y*CELL_H + SVG_DY
    private const SVG_DY = 20;
    // Fixní výška boxu osoby (CSS .node { height: 84px; box-sizing: border-box }) → spodní hrana boxu
    private const BOX_H = 84;

    /**
     * @param array<int,array{id:string,gender:?string,birth:?string,death:?string,father:?string,mother:?string,partners:array<int,string>,labels:array}> $members
     * @return array{families:array<int,array{x:int,y:int,f:?string,m:?string}>,people:array<string,array{x:int,y:int,union:?int}>,order:array<int,string>}
     */
    public static function layout(array $members): array
    {
        $byId = [];
        foreach ($members as $m) {
            $byId[$m['id']] = $m;
        }

        // shromáždi děti pro každý rodičovský pár
        $unions = []; // (fatherId, motherId) => ['f'=>?, 'm'=>?, 'c'=>[ids]]
        $singles = [];
        foreach ($members as $m) {
            $f = $m['father'] ?? null;
            $mm = $m['mother'] ?? null;
            if (($f || $mm) && (isset($byId[$f]) || isset($byId[$mm]))) {
                $key = ($f ?? 'x') . '|' . ($mm ?? 'x');
                if (!isset($unions[$key])) {
                    $unions[$key] = ['f' => $f, 'm' => $mm, 'c' => []];
                }
                $unions[$key]['c'][] = $m['id'];
            } else {
                $singles[] = $m['id'];
            }
        }

        // seřaď děti v každé rodině podle data narození
        foreach ($unions as &$u) {
            usort($u['c'], static function (string $a, string $b) use ($byId): int {
                return strcmp($byId[$a]['birth'] ?? '9999', $byId[$b]['birth'] ?? '9999');
            });
        }
        unset($u);

        // Partnerství (P32) bez společných dětí: vytvoř fiktivní union (manželé bez dětí)
        // aby se položili vedle sebe a spojili čarou.
        foreach ($members as $m) {
            $pid = $m['id'];
            foreach ($m['partners'] ?? [] as $partnerId) {
                if (!isset($byId[$partnerId])) {
                    continue;
                }
                // zamez duplicitě: každý pár jen jednou (vybereme lexikograficky menší jako klíč)
                $pair = [$pid, $partnerId];
                sort($pair);
                $key = $pair[0] . '|' . $pair[1];
                if (isset($unions[$key])) {
                    continue;
                }
                // Přidej jen pokud tato dvojice už nemá společné děti v roli rodičů
                // (tj. v unions neexistuje klíč "pid|partner" ani "partner|pid" s f/m rolí)
                $exists = false;
                foreach ($unions as $u) {
                    if (($u['f'] === $pid && $u['m'] === $partnerId) ||
                        ($u['f'] === $partnerId && $u['m'] === $pid)) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    continue;
                }
                $unions[$key] = ['f' => $pid, 'm' => $partnerId, 'c' => []];
            }
        }

        // unions, které jsou zároveň dítětem jiné rodiny → mapování id => union index
        $personUnion = [];
        foreach ($unions as $idx => $u) {
            foreach (array_filter([$u['f'], $u['m']]) as $pid) {
                $personUnion[$pid][$idx] = $idx;
            }
        }

        // bottom-up: sloučení rodičovských párů do rodin, dokud lze
        $families = []; // konečný formát: ['x'=>int,'y'=>int,'f'=>?,'m'=>?,'c'=>[ids]]
        $unionIdx = array_keys($unions);
        $consumed = [];
        $unionFamilyMap = []; // unionIdx => index do $families

        // iterativně sbíráme "nejmenší" svazky: ty, jejichž manželé nejsou dětmi žádného jiného nerozpracovaného svazku
        $remaining = array_keys($unions);
        $depth = 0;
        while ($remaining && $depth < 30) {
            $depth++;
            $progress = false;
            foreach ($remaining as $rk => $ui) {
                $u = $unions[$ui];
                $f = $u['f'];
                $m = $u['m'];

                // je některý z rodičů dítětem ještě nerozpracované rodiny?
                $blocked = false;
                foreach ([$f, $m] as $pid) {
                    if ($pid === null) {
                        continue;
                    }
                    foreach ($remaining as $otherUi) {
                        if ($otherUi === $ui) {
                            continue;
                        }
                        if (in_array($pid, $unions[$otherUi]['c'], true)) {
                            $blocked = true;
                            break 2;
                        }
                    }
                }
                if ($blocked) {
                    continue;
                }

                // slučuj
                $families[] = [
                    'x' => 0, // dopočítá se později
                    'y' => 0,
                    'f' => $f,
                    'm' => $m,
                    'c' => $u['c'],
                ];
                $unionFamilyMap[$ui] = count($families) - 1;
                foreach ($u['c'] as $cid) {
                    $personUnion[$cid][$ui] = $ui;
                }
                unset($remaining[$rk]);
                $consumed[] = $cid;
                $progress = true;
            }
            if (!$progress) {
                break; // cyklus, necháme být
            }
        }

        // zbylé unions (cykly) přidej na konec
        foreach ($remaining as $ui) {
            $u = $unions[$ui];
            $families[] = ['x' => 0, 'y' => 0, 'f' => $u['f'], 'm' => $u['m'], 'c' => $u['c']];
            $unionFamilyMap[$ui] = count($families) - 1;
        }

        // y-souřadnice: bottom-up (rodiče vyšší než děti)
        // spočítej hloubku každé rodiny = 1 + max(hloubka dětí)
        $depthOf = [];
        $calcDepth = static function (int $fi) use (&$calcDepth, &$depthOf, $families, $unionFamilyMap, $unions): int {
            if (isset($depthOf[$fi])) {
                return $depthOf[$fi];
            }
            $fam = $families[$fi];
            $max = 0;
            foreach ($fam['c'] as $cid) {
                // najdi union, kde tato osoba vystupuje jako rodič
                foreach ($unions as $ui => $u) {
                    if ($u['f'] === $cid || $u['m'] === $cid) {
                        if (isset($unionFamilyMap[$ui])) {
                            $max = max($max, $calcDepth($unionFamilyMap[$ui]));
                            break;
                        }
                    }
                }
            }
            return $depthOf[$fi] = $max + 1;
        };

        // spočítej počet "listových" pozic (šířka) každé rodiny
        $widthOf = [];
        $calcWidth = static function (int $fi) use (&$calcWidth, &$widthOf, $families, $unions, $unionFamilyMap): int {
            if (isset($widthOf[$fi])) {
                return $widthOf[$fi];
            }
            $fam = $families[$fi];
            $w = 0;
            foreach ($fam['c'] as $cid) {
                $cw = 0;
                foreach ($unions as $ui => $u) {
                    if ($u['f'] === $cid || $u['m'] === $cid) {
                        if (isset($unionFamilyMap[$ui])) {
                            $cw = $calcWidth($unionFamilyMap[$ui]);
                            break;
                        }
                    }
                }
                $w += max(1, $cw);
            }
            // rodina potřebuje alespoň 2 buňky (otec+matka)
            return $widthOf[$fi] = max(2, $w);
        };

        // Rodiny seřazené od kořenů (bez rodičů v datech) dolů.
        // Pro přehlednost: urči "top-level" rodiny = ty, jejichž f/m nejsou dítětem žádné rodiny.
        $isChild = [];
        foreach ($families as $fam) {
            foreach ($fam['c'] as $cid) {
                $isChild[$cid] = true;
            }
        }
        $topLevel = [];
        $other = [];
        foreach ($families as $fi => $fam) {
            $isTop = true;
            foreach (array_filter([$fam['f'], $fam['m']]) as $pid) {
                if (isset($isChild[$pid])) {
                    $isTop = false;
                    break;
                }
            }
            if ($isTop) {
                $topLevel[] = $fi;
            } else {
                $other[] = $fi;
            }
        }

        // x-souřadnice: top-level zleva doprava, pak rekurzivně potomky
        $pos = []; // fi => ['x'=>int,'y'=>int]
        $cursor = 0;
        $assign = static function (int $fi, int $xStart, int $yBase) use (&$assign, &$pos, &$cursor, $families, $unions, $unionFamilyMap, $calcWidth, $calcDepth): void {
            $w = $calcWidth($fi);
            $famX = $xStart + intdiv($w, 2);
            // y: kořeny nahoře → hluboké rodiny větší y. Pro jednoduchost použijeme inverzní hloubku.
            $pos[$fi] = ['x' => $famX, 'y' => $yBase];

            // rozdej děti
            $cx = $xStart;
            foreach ($families[$fi]['c'] as $cid) {
                // najdi rodinu, kde cid vystupuje jako rodič (tj. dítě má vlastní rodinu)
                $childFi = null;
                foreach ($unions as $ui => $u) {
                    if ($u['f'] === $cid || $u['m'] === $cid) {
                        if (isset($unionFamilyMap[$ui]) && $unionFamilyMap[$ui] !== $fi) {
                            $childFi = $unionFamilyMap[$ui];
                            break;
                        }
                    }
                }
                $cw = $childFi !== null ? $calcWidth($childFi) : 1;
                if ($childFi !== null) {
                    $assign($childFi, $cx, $yBase + 1);
                }
                // osoba, která nemá vlastní rodinu, dostane pozici v mřížce (viz people)
                $cx += $cw;
            }
        };

        // y: kořeny mají y=0, děti y=1 atd. → přepočítáme dle hloubky (invertovaně, protože předkové mají větší depth)
        $maxDepth = 0;
        foreach ($families as $fi => $fam) {
            $d = $calcDepth($fi);
            if ($d > $maxDepth) {
                $maxDepth = $d;
            }
        }
        // nejdřív top-level rodiny umísťujeme zleva doprava na y = maxDepth - depth
        foreach ($topLevel as $fi) {
            $y = $maxDepth - $calcDepth($fi);
            $w = $calcWidth($fi);
            $assign($fi, $cursor, $y);
            $cursor += $w + 1;
        }
        // zbylé (cykly): vyskládej vedle
        foreach ($other as $fi) {
            if (isset($pos[$fi])) {
                continue;
            }
            $y = $maxDepth - $calcDepth($fi);
            $w = $calcWidth($fi);
            $assign($fi, $cursor, $y);
            $cursor += $w + 1;
        }

        // pozice osob
        $people = [];
        $familiesOut = [];

        // 1. průchod: otce+matky (partnery), aby se dětské pozice nepletejí s partnery pozdějších rodin
        foreach ($families as $fi => $fam) {
            $x = $pos[$fi]['x'];
            $y = $pos[$fi]['y'];
            $familiesOut[] = ['x' => $x, 'y' => $y, 'f' => $fam['f'], 'm' => $fam['m'], 'c' => $fam['c'] ?? []];
            if ($fam['f'] !== null) {
                $people[$fam['f']] = ['x' => $x, 'y' => $y, 'union' => $fi];
            }
            if ($fam['m'] !== null) {
                $mx = $fam['f'] !== null ? $x + 1 : $x;
                $people[$fam['m']] = ['x' => $mx, 'y' => $y, 'union' => $fi];
            }
        }

        // 2. průchod: děti bez vlastní rodiny — stojí pod rodiči (na y+1).
        // Pozice musí být volná (nesmí přepsat partnera z jiné rodiny).
        foreach ($families as $fi => $fam) {
            $x = $pos[$fi]['x'];
            $y = $pos[$fi]['y'];
            $childX = $x - intdiv(max(0, count($fam['c']) - 1), 2);
            foreach ($fam['c'] as $cid) {
                if (isset($people[$cid])) {
                    continue; // už má pozici (je f/m ve vlastní rodině)
                }
                $tx = max(0, $childX);
                // najdi nejbližší volnou buňku vpravo na tomto řádku
                $occupied = true;
                while ($occupied) {
                    $occupied = false;
                    foreach ($people as $p) {
                        if ($p['y'] === $y + 1 && $p['x'] === $tx) {
                            $tx++;
                            $occupied = true;
                            break;
                        }
                    }
                }
                $people[$cid] = ['x' => $tx, 'y' => $y + 1, 'union' => null];
                $childX = $tx + 1;
            }
        }

        // singles bez rodiny: mřížka doprava
        $singleX = $cursor + 2;
        foreach ($singles as $sid) {
            if (!isset($people[$sid])) {
                $people[$sid] = ['x' => $singleX, 'y' => 0, 'union' => null];
                $singleX++;
            }
        }

        // pořadí osob pro fallback seznam (chlopí nejdřív, pak abecedně)
        $order = array_keys($byId);
        usort($order, static function (string $a, string $b) use ($byId): int {
            return strcasecmp(
                I18n::pick($byId[$a]['labels']) ?: $a,
                I18n::pick($byId[$b]['labels']) ?: $b
            );
        });

        return [
            'families' => $familiesOut,
            'people'   => $people,
            'order'    => $order,
        ];
    }

    /**
     * Vypočítá SVG čáry mezi boxy.
     * @param array $families viz layout()
     * @param array $people viz layout()
     * @return array<int,array{cls:string,d:string}>
     */
    public static function edges(array $families, array $people): array
    {
        $paths = [];
        foreach ($families as $fi => $fam) {
            $f = $fam['f'];
            $m = $fam['m'];
            $x = $fam['x'];
            $y = $fam['y'];

            // manželská čára
            if ($f !== null && $m !== null && isset($people[$f], $people[$m])) {
                $fx = $people[$f]['x'] * self::CELL_W + self::BOX_CENTER_X + 55;
                $mx = $people[$m]['x'] * self::CELL_W + self::BOX_CENTER_X - 55;
                $yy = $people[$f]['y'] * self::CELL_H + 60;
                $paths[] = [
                    'cls' => 'marriage',
                    'd'   => "M $fx,$yy L $mx,$yy",
                ];
            }

            // čáry k dětem
            // Box node v .tree-canvas: left = x*CELL_W+30, top = y*CELL_H+SVG_DY (family.php).
            // Skutečný spodek boxu = top + BOX_H = y*CELL_H + SVG_DY + BOX_H.
            // SVG #tree-edges je posunuté o (30,20) → canvas coords jsou shodné se SVG coords po odečtení 30/20,
            // ale tady používáme absolutní souřadnice SHODNÉ s node top/left (− padding) → SVG je správně zarovnané.
            foreach ($fam['c'] ?? [] as $cid) {
                if (!isset($people[$cid])) {
                    continue;
                }
                // rodičovský střed (spodní hrana boxu)
                // SVG #tree-edges má top:20px v .tree-canvas → přičte +20 ke všem y souřadnicím.
                // Node divy mají top = y*CELL_H + 20 (family.php). Aby se souhlasily,
                // spočítáme souřadnice vztažené k node top (bez SVG_DY), a SVG posune o 20px samo.
                if ($f !== null && $m !== null && isset($people[$f], $people[$m])) {
                    $px = intdiv($people[$f]['x'] + $people[$m]['x'], 2) * self::CELL_W + self::BOX_CENTER_X;
                    $py = $people[$f]['y'] * self::CELL_H + self::BOX_H;
                } else {
                    $p = $f !== null ? $people[$f] : $people[$m];
                    $px = $p['x'] * self::CELL_W + self::BOX_CENTER_X;
                    $py = $p['y'] * self::CELL_H + self::BOX_H;
                }
                // střed dítka, horní hrana jeho boxu
                $cx = $people[$cid]['x'] * self::CELL_W + self::BOX_CENTER_X;
                $cy = $people[$cid]['y'] * self::CELL_H;

                // L-tvar: dolů k polovině mezery, pak do strany, pak dolů na horní hranu dětského boxu
                $midY = intdiv($py + $cy, 2);
                $paths[] = [
                    'cls' => 'child',
                    'd'   => "M $px,$py L $px,$midY L $cx,$midY L $cx,$cy",
                ];
            }
        }
        return $paths;
    }
}
