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
     * @return array{families:array<int,array{x:int,y:int,f:?string,m:?string}>,people:array<string,array{x:int,y:int,union:?int}>,order:array<int,string>,dups:array<string,array{x:int,y:int,parent_family:array{x:int,y:int}}>}
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
        $spouseMap = []; // partnerId => pid, pro partnery přeskočené níže (bez společných dětí)
        // Dvourolé osoby (dítě jedné fam + partner cizí — incestní sňatek). Pro ně později
        // vytvoříme dup: primárně v roli rodiče ve fiktivní fam, duplikát v roli dítěte.
        $crossRolePids = []; // pid => true
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
                // Dvourolé osoby (sňatek uvnitř stejné rodiny): osoba je dítětem v jedné fam
                // A ZÁROVEŇ její partner nemá v canvas žádné rodiče (tj. je to "cizinec",
                // který nemá kam jinam patřit). Jinak by fiktivní fam vedla k zbytečné dup vizuální
                // duplicitě — partner má vlastní rodiče/pozici jinde (např. Q682: manželka z jiného
                // rodu — tam stačí přeškrtnutá marriage čára přes $spouseMap).
                $pidIsChild = false;
                foreach ($unions as $u) {
                    foreach ($u['c'] as $cid) {
                        if ($cid === $pid) {
                            $pidIsChild = true;
                            break 2;
                        }
                    }
                }
                // Partner nesmí mít v canvas vlastní rodinu — tj. nesmí být rodičem ani
                // dítětem v žádné jiné fam, ANI nemít rodiče v canvasu (jinak má jiné místo
                // v canvas a dup nedává smysl).
                $partnerHasFamilyHere = false;
                $partnerData = $byId[$partnerId] ?? null;
                if ($partnerData !== null
                    && (!empty($partnerData['father']) || !empty($partnerData['mother']))
                ) {
                    $partnerHasFamilyHere = true;
                }

                if (!$partnerHasFamilyHere) {
                    foreach ($unions as $u) {
                        if ($u['f'] === $partnerId || $u['m'] === $partnerId) {
                            $partnerHasFamilyHere = true;
                            break;
                        }
                        foreach ($u['c'] as $cid) {
                            if ($cid === $partnerId) {
                                $partnerHasFamilyHere = true;
                                break 2;
                            }
                        }
                    }
                }

                $partnerOtherFamilies = $partnerData['other_families'] ?? null;
                if ($pidIsChild && !$partnerHasFamilyHere
                    && $partnerOtherFamilies !== null && $partnerOtherFamilies === []
                ) {

                    // Dup jen pro sňatek uvnitř stejného rodu: pid je dítě, partner je
                    // "cizinec bez rodiny" a zároveň nemá other_families (tj. patří jen
                    // do aktuálního rodu). U běžného sňatku (např. Fürstenberg × Schlabrendorf,
                    // Anna Karolina Q1098) stačí jeden box — bez duplikátů.
                    $crossRolePids[$pid] = true;
                } else {



                // přepočítej partnerIsChild pro spouseMap
                $partnerIsChild = false;
                foreach ($unions as $u) {
                    foreach ($u['c'] as $cid) {
                        if ($cid === $partnerId) { $partnerIsChild = true; break 2; }
                    }
                }

                    // Ani jeden není dítě v jiné fam (klasické partnerství bez dětí),
                    // NEBO oba jsou dětmi (exotické). Využijeme spouseMap: partner stojí
                    // vedle. Kdo je dítě, pokud takový je (oba), zůstane pod svými rodiči.
                    if ($pidIsChild && !$partnerIsChild) {
                        $spouseMap[$partnerId] = $pid;
                    } elseif ($partnerIsChild && !$pidIsChild) {
                        $spouseMap[$pid] = $partnerId;
                    }
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
                // Dvourolé osoby (incest, $crossRolePids) se za "dítě" NEPOVAŽUJÍ —
                // jejich primární fam (s partnerem) má být kořenovým svazkem.
                // Duplikát v roli dítěte je jen vizuální.
                $blocked = false;
                foreach ([$f, $m] as $pid) {
                    if ($pid === null || isset($crossRolePids[$pid])) {
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
                unset($remaining[$rk]);
                // Nenecháváme děti v personUnion, abychom je přiště nerozpoznali jako rodiče.
                // Rodiče (f/m) necháváme — umožní hranu i z rodičovské rodiny výše.
                foreach ($u['c'] as $cid) {
                    unset($personUnion[$cid]);
                }
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
        // Cross-role osoby (incest, $crossRolePids) se přeskočí: jejich primární svazek
        // je kořenem své podvětve, ne potomkem rodičovské fam (dup je jen vizuální).
        $depthOf = [];
        $calcDepth = static function (int $fi) use (&$calcDepth, &$depthOf, $families, $unionFamilyMap, $unions, $crossRolePids): int {
            if (isset($depthOf[$fi])) {
                return $depthOf[$fi];
            }
            $fam = $families[$fi];
            $max = 0;
            foreach ($fam['c'] as $cid) {
                if (isset($crossRolePids[$cid])) {
                    continue;
                }
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
            // ochrana před cyklem (teoreticky nemůže nastat — preorder visited)
            $widthOf[$fi] = PHP_INT_MAX; // provizorně
            $fam = $families[$fi];
            $w = 0;
            foreach ($fam['c'] as $cid) {
                $cw = 0;
                foreach ($unions as $ui => $u) {
                    if ($u['f'] === $cid || $u['m'] === $cid) {
                        if (isset($unionFamilyMap[$ui]) && $unionFamilyMap[$ui] !== $fi) {
                            $cw += $calcWidth($unionFamilyMap[$ui]);
                            // NEBREAK — osoba může mít více svazků (např. Q852)
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
        // Výjimka: dvojrolé osoby (incest, $crossRolePids) — jejich primární fam
        // (s partnerem) je kořenová, dup jako dítě je jen vizuální.
        $isChild = [];
        foreach ($families as $fam) {
            foreach ($fam['c'] as $cid) {
                if (!isset($crossRolePids[$cid])) {
                    $isChild[$cid] = true;
                }
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
                // najdi všechny rodiny, kde cid vystupuje jako rodič
                // (osoba může mít více svazků — např. Q852: hlavní s Q851 + vedlejší
                // s nezaznamenanou matkou Q973; všechny zavěsíme pod rodičovskou rodinu)
                $childFis = [];
                foreach ($unions as $ui => $u) {
                    if (($u['f'] === $cid || $u['m'] === $cid)
                        && isset($unionFamilyMap[$ui])
                        && $unionFamilyMap[$ui] !== $fi
                        && !in_array($unionFamilyMap[$ui], $childFis, true)
                        && !isset($pos[$unionFamilyMap[$ui]])) {
                        $childFis[] = $unionFamilyMap[$ui];
                    }
                }
                foreach ($childFis as $childFi) {
                    $assign($childFi, $cx, $yBase + 1);
                    $cx += $calcWidth($childFi);
                }
                if (!$childFis) {
                    $cx++;
                }
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

        // Primární rodina dané osoby: preferuj svazek se známým partnerem (m != null).
        // Např. Q852 je rodičem ve svazku Q852|Q851 (8 dětí) i ve "vedlejším" Q852|NULL (dítě Q973
        // z nemanželského vztahu). Bez preference by se Q852 přiřadil k fiktivnímu svazku s nižší y.
        $primaryUnion = [];
        foreach ($families as $fi => $fam) {
            foreach (['f', 'm'] as $role) {
                $pid = $fam[$role];
                if ($pid === null) {
                    continue;
                }
                if (!isset($primaryUnion[$pid])) {
                    $primaryUnion[$pid] = $fi;
                    continue;
                }
                // preferuj svazek s partnerem (oba f/m non-null) před osamělým
                $cur = $families[$primaryUnion[$pid]];
                $curHasPartner = $cur['f'] !== null && $cur['m'] !== null;
                $newHasPartner = $fam['f'] !== null && $fam['m'] !== null;
                if (!$curHasPartner && $newHasPartner) {
                    $primaryUnion[$pid] = $fi;
                }
            }
        }

        // Sestavení $familiesOut s předběžnými pozicemi rodin. Pozice LIDÍ se přiřazují
        // až po korekci generace/sloupce multi-marriage svazků níže — jinak by se partner
        // vedlejšího svazku (např. Q1095 z Zierotin v Q12) vykreslil na původní pozici fam.
        foreach ($families as $fi => $fam) {
            $familiesOut[] = ['x' => $pos[$fi]['x'], 'y' => $pos[$fi]['y'], 'f' => $fam['f'], 'm' => $fam['m'], 'c' => $fam['c'] ?? []];
        }

        // Korekce generace pro "vedlejší" svazky: pokud je rodič (f nebo m) současně primárně
        // v jiném svazku s partnerem (např. Q852: hlavní Q852|Q851, vedlejší Q852|NULL
        // s dítětem Q973), je správná generace vedlejšího svazku = generace rodiče.
        // Jednorodičový svazek (tj. otec/matka + neznámý partner, např. fam "Q676|NULL"
        // v Q12 s dětmi Q1091/Q1090) položíme na STEJNOU úroveň jako primární fam a jeho
        // uzel čáry těsně vedle primární pozice — jinak by čára k dětem visela ve vzduchu
        // a děti by vypadaly jako generační přeskok.
        foreach ($familiesOut as $fi => &$famOut) {
            $singleParent = ($famOut['f'] === null) !== ($famOut['m'] === null);
            foreach (array_filter([$famOut['f'], $famOut['m']]) as $pid) {
                $prim = $primaryUnion[$pid] ?? null;
                if ($prim === null || $prim === $fi) {
                    continue;
                }
                $primY = $familiesOut[$prim]['y'];
                if ($singleParent) {
                    // Jednorodičový svazek (f=známý, m=NULL nebo obráceně): fam je virtuální
                    // "rodičovský pár" — rodič + neznámý partner. Děti z tohoto vztahu jsou
                    // stejné generace jako děti primární fam, proto fam stojí na STEJNÉ y
                    // jako primární (nikoli na primY+1). Virtuální uzel čáry dáme o 2 buňky
                    // napravo od primární fam (mimo box rodiče, ale na stejné úrovni).
                    // Q12: fi=0 (Q676|Q679) na (6,1), fi=2 (Q676|NULL) na (8,1) — obě fam
                    // mají děti na y=2.
                    $famOut['y'] = $primY;
                    $famOut['x'] = $familiesOut[$prim]['x'] + 2;
                } else {

                    // multi-marriage (obě f/m non-null): fam stojí na STEJNÉ generaci jako primární.
                    // Partnera vedlejšího svazku (typicky druhá žena) umístíme BEZPROSTŘEDNĚ VEDLE
                    // společného rodiče — na stranu, kde už primární partner nestojí (u muže: pokud je
                    // primární žena vpravo, druhá žena na x=primX-1, čára pak vede z levé hrany jeho boxu).
                    // Nejedná se o dup: rodič (Q1092) zůstává jediný box; obě jeho manželky ho obklopejí
                    // zleva a zprava jako sourozenci v mezigeneraci. => nepřekrývající se čáry.
                    $famOut['y'] = $primY;
                    $primX = $familiesOut[$prim]['x'];
                    $primFam = $familiesOut[$prim];
                    // Směr: pokud má primFam f prim (je to otec mimo nás), m primární je na primX+1,
                    // takže vedlejší partnerku dáme na x=primX-1; fam.x = primX-2.
                    // Pokud prim je m (matka v nás), symetricky na x=primX+1 za prim; fam.x = primX (její x).
                    $offset = ($primFam['f'] === $pid) ? -2 : 0;
                    $tx = $primX + $offset;
                    // ochrana proti kolizi: pokud je na cílovém místě jiná fam na této y, posuň doprava
                    $occupied = true;
                    while ($occupied) {
                        $occupied = false;
                        foreach ($familiesOut as $of => $other) {
                            if ($of === $fi || $other['y'] !== $primY) {
                                continue;
                            }
                            if (abs($other['x'] - $tx) < 2) {
                                $tx += 2;
                                $occupied = true;
                                break;
                            }
                        }
                    }
                    // Potvrdíme, že partnerka vedlejšího svazku (druhý prvek fam) skončí
                    // bezprostředně vedle společného rodiče (na straně opačné, než primární partnerka).
                    $famOut['x'] = $tx;
                }
            }
        }
        unset($famOut);

        // 1. průchod: otce+matky (partnery) — z OPRAVENÝCH pozic rodin.
        // Každá osoba dostane pozici jen ze své primární rodiny.
        foreach ($familiesOut as $fi => $fam) {
            $x = $fam['x'];
            $y = $fam['y'];
            if ($fam['f'] !== null && $primaryUnion[$fam['f']] === $fi) {
                $people[$fam['f']] = ['x' => $x, 'y' => $y, 'union' => $fi];
            }
            if ($fam['m'] !== null && $primaryUnion[$fam['m']] === $fi) {
                $mx = $fam['f'] !== null ? $x + 1 : $x;
                $people[$fam['m']] = ['x' => $mx, 'y' => $y, 'union' => $fi];
            }
        }


        // 1b. průchod: partneři bez společných dětí ($spouseMap) — stojí vedle svého partnera
        // na stejné y. Musí běžet PŘED 2. průchodem, jinak by např. snacha (Q1097) šla
        // za svou švagrovou (Q1100=Friederike) — viz Q12.
        // Později se ještě v sekci "singles" znovu kontroluje zbývající partnerOf
        // (ty, co nebyly přiřazeny zde — např. rootPid neexistuje).
        $partnerOf = []; // childId => partner (rodič)
        foreach ($singles as $sid) {
            if (isset($spouseMap[$sid])) {
                $partnerOf[$sid] = $spouseMap[$sid];
            }
        }
        foreach ($partnerOf as $sid => $rootPid) {
            if (!isset($people[$rootPid])) {
                continue;
            }
            $p = $people[$rootPid];
            $tx = $p['x'] + 1;
            $occupied = true;
            while ($occupied) {
                $occupied = false;
                foreach ($people as $po) {
                    if ($po['y'] === $p['y'] && $po['x'] === $tx) {
                        $tx++;
                        $occupied = true;
                        break;
                    }
                }
            }
            $people[$sid] = ['x' => $tx, 'y' => $p['y'], 'union' => null];
        }

        // 2. průchod: děti bez vlastní rodiny — stojí pod rodiči (na y+1).
        // Pozice musí být volná (nesmí přepsat partnera z jiné rodiny).
        foreach ($familiesOut as $fi => $fam) {
            $x = $fam['x'];
            $y = $fam['y'];
            // Děti "vedlejšího" samostatného svazku (jen jeden z f/m, druhý NULL)
            // se umisťují pod pozici primárního svazku rodiče NEBO pod pozici aktuální
            // fam – podle toho, co je výš. Jinak by totiž fam (např. "Q676|NULL" v Q12)
            // mohla ležet HLUBŠÍ než primární (fam[0] s Q679) a děti by se vykreslily
            // ve STEJNÉM řádku jako jejich samotná fam — pak by žádná čára z fam
            // k dětem nešla nakreslit.
            $baseY = $y;
            if (($fam['f'] === null) !== ($fam['m'] === null)) {
                $singlePid = $fam['f'] ?? $fam['m'];
                if (isset($people[$singlePid])) {
                    // Jednorodičový svazek: fam je virtuální uzel vedle rodiče, děti patří
                    // na úroveň rodič.y+1, tj. pod skutečného rodiče — ne pod fiktivní fam.
                    // Test Q10: Q852 na y=1, svazek Q852|NULL na y=2, dítě Q973 na y=2.
                    $baseY = $people[$singlePid]['y'];
                }
            }
            $childY = $baseY + 1;

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
                        if ($p['y'] === $childY && $p['x'] === $tx) {
                            $tx++;
                            $occupied = true;
                            break;
                        }
                    }
                }
                $people[$cid] = ['x' => $tx, 'y' => $childY, 'union' => null];
                $childX = $tx + 1;
                // Partner dítěte (bez společných dětí, $spouseMap) — vlož ho hned vedle,
                // aby nestál na konci řady sourozenců (např. Charlotte Q1097 vedle syna
                // Q1098 v Q12; 1b. průchod ho ještě nemohl obsadit).
                // spouseMap ukládá "partner → dítě" (např. Q1097=>Q1098): pro právě
                // umístěné dítě najdeme partnera zpětným vyhledáním.
                $partnerPid = array_search($cid, $spouseMap, true);
                if ($partnerPid === false) {
                    $partnerPid = null;
                }
                if ($partnerPid !== null && !isset($people[$partnerPid])) {
                    $ptx = $tx + 1;
                    $occupied = true;
                    while ($occupied) {
                        $occupied = false;
                        foreach ($people as $p) {
                            if ($p['y'] === $childY && $p['x'] === $ptx) {
                                $ptx++;
                                $occupied = true;
                                break;
                            }
                        }
                    }
                    $people[$partnerPid] = ['x' => $ptx, 'y' => $childY, 'union' => null];
                    $childX = $ptx + 1;
                }
            }
        }

        // singles bez rodiny: partneři bez společných dětí ti sedí vedle svého partnera.
        // Pozn.: hlavní část proběhla už v "1b. průchodu" — zde řešíme jen ty, co už jsou
        // v people, ale přesto nemají pozici (např. jejich partner$people zaplnil až v 2. průchodu).
        $partnerOf = [];
        foreach ($singles as $sid) {
            if (isset($spouseMap[$sid])) {
                $partnerOf[$sid] = $spouseMap[$sid];
            }
        }
        foreach ($partnerOf as $sid => $rootPid) {
            if (isset($people[$sid])) {
                continue; // už v 1b. průchodu
            }
            if (!isset($people[$rootPid])) {
                continue;
            }
            $p = $people[$rootPid];
            $tx = $p['x'] + 1;
            // najdi volné místo na stejném řádku
            $occupied = true;
            while ($occupied) {
                $occupied = false;
                foreach ($people as $po) {
                    if ($po['y'] === $p['y'] && $po['x'] === $tx) {
                        $tx++;
                        $occupied = true;
                        break;
                    }
                }
            }
            $people[$sid] = ['x' => $tx, 'y' => $p['y'], 'union' => null];
        }
        // zbylé singles: mřížka doprava
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

        // -----------------------------------------------------------------
        // Dvojrolé osoby: jsou současně rodičem (v nějaké fam jako f/m) i dítětem
        // (v jiné fam jako 'c'). Typicky sňatek uvnitř rodu (Karoline Q677).
        // Vykreslují se dvakrát:
        //   - primárně na pozici z $people (role rodiče),
        //   - jako duplikát na pozici vedle sourozeńców v roli dítěte.
        // Přídavná souřadnice se vrací v 'dups' a použije se v šabloně i v edges().
        // -----------------------------------------------------------------
        $dups = [];
        // Mapa rodičovské rodiny pro každé dítě (hledáme fam, kde se vyskytuje v 'c').
        $parentFamOf = []; // pid => index v $familiesOut
        foreach ($familiesOut as $fi => $fam) {
            foreach ($fam['c'] ?? [] as $cid) {
                if (!isset($parentFamOf[$cid])) {
                    $parentFamOf[$cid] = $fi;
                }
            }
        }
        // Dup chceme jen pro osoby, které jsou primárně rodičem ve fiktivní fam manželství
        // (bez dětí) A vznikly při "cizí-partner" logice ($crossRolePids). Tím se vyhneme
        // duplikátům u osob, které mají partnera z vlastní rodiny (ta má pak jiné místo
        // v canvas a dup by byl zbytečný).


        // Obsazené buňky (včetně již přidaných dupů)
        $occupied = [];
        foreach ($people as $p) {
            $occupied[$p['y']][$p['x']] = true;
        }
        foreach ($people as $pid => $pos) {
            if (!isset($crossRolePids[$pid], $parentFamOf[$pid])) {
                continue; // není dvojrolá (podle cizí-partner logiky)
            }

            // Sourozenci ve stejné fam (seřazení dle birth): poslední samostatný (non-dup) je hned
            // vedle nás → dup položíme na jeho y (tj. sourozenec řádek) a na x napravo od něj.
            // Genialnější než pf.y+1 — u jednorodičových svazků (fam s null partnerem) je
            // dostupná úroveň sourozenců = people[single].y+1, ne pf.y+1.
            $parentFi = $parentFamOf[$pid];
            $siblings = $familiesOut[$parentFi]['c'] ?? [];
            $sibY = null;
            $baseX = null;
            foreach ($siblings as $sib) {
                if ($sib === $pid || !isset($people[$sib])) {
                    continue;
                }
                $sy = $people[$sib]['y'];
                if ($sibY === null || $sy > $sibY) {
                    $sibY = $sy;
                    $baseX = $people[$sib]['x'] + 1;
                }
            }
            if ($sibY === null) {
                // Žádný sourozenec v people (všichni jsou primárně jinde nebo bez pozice):
                // fallback na pozici pod rodičovskou fam.
                $pf = $familiesOut[$parentFi];
                $sibY = $pf['y'] + 1;
                $baseX = $pf['x'];
            }
            $ty = $sibY;
            $px = $baseX;
            $pf = $familiesOut[$parentFi]; // potřebujeme pro parent_family později
            $tx = null;
            // Hledáme volnou buňku od baseX doprava (priorita: napravo od sourozence).
            // Pokud je plno, zkusíme střídavě vpravo a vlevo.
            $cand = $px;
            while ($cand >= 0 && isset($occupied[$ty][$cand])) {
                $cand++;
            }
            if ($cand >= 0) {
                $tx = $cand;
            }
            if ($tx === null) {
                for ($off = 1; $off <= 12; $off++) {
                    foreach ([-$off, $off] as $dx) {
                        $cand = $px + $dx;
                        if ($cand < 0) {
                            continue;
                        }
                        if (!isset($occupied[$ty][$cand])) {
                            $tx = $cand;
                            break 2;
                        }
                    }
                }
            }

            if ($tx === null) {
                continue; // volné místo nenalezeno – dup nevytvářet
            }
            $occupied[$ty][$tx] = true;
            $dups[$pid] = [
                'x' => $tx,
                'y' => $ty,
                'parent_family' => ['x' => $pf['x'], 'y' => $pf['y']],
            ];
        }

        // Páry bez vlastní rodiny (spouseMap): zde jsou partneři bez společných dětí,
        // kteří se neumístili do fiktivní fam (protože jeden z nich je dítě v jiné fam).
        // Potřebujeme pro vykreslení manželské čáry v edges().
        $couples = [];
        foreach ($spouseMap as $otherPid => $childPid) {
            if (isset($people[$otherPid], $people[$childPid])) {
                $couples[] = [$childPid, $otherPid];
            }
        }

        // Bez multi-marriage dup: žádné druhé zobrazení stejné osoby. Multi-marriage
        // svazek necháme kreslit čáru z primární pozice; u malých poměrů (právě dvojí
        // sňatek) je to čitelnější než vizuální duplicita.

        // Označ primární pozice lidí s dupem — šablona vykreslí ikonu s navigací na dup.
        foreach ($dups as $pid => $d) {
            if (isset($people[$pid])) {
                $people[$pid]['isDupOf'] = true;
            }
        }

        return [

            'families' => $familiesOut,
            'people'   => $people,
            'order'    => $order,
            'dups'     => $dups,
            'couples'  => $couples,
        ];
    }


    /**
     * Vypočítá SVG čáry mezi boxy.
     * @param array $families viz layout()
     * @param array $people viz layout()
     * @param array $dups viz layout(); pro dvojrolé osoby se 'child' čára kreslí do dup pozice
     *              a 'marriage' čára zůstává na primární pozici.
     * @return array<int,array{cls:string,d:string}>
     */
    public static function edges(array $families, array $people, array $dups = [], array $couples = []): array
    {
        $paths = [];
        // Deduplikace: stejná 'd' (typicky manželská čára z $couples + zároveň z fam)
        // se vykreslí jen jednou.
        $seen = [];
        $push = static function (array $p) use (&$paths, &$seen): void {
            $key = $p['cls'] . '|' . $p['d'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $paths[] = $p;
            }
        };
        // Souřadnice pro kreslení 'child' čar: pro dvojrolou osobu (dup v roli dítěte)
        // použijeme dup pozici. Pozor — dup v roli partnera ('marriage_family') se pro
        // child čáry NEPOUŽÍVÁ (tam chceme cílit primární box, dup je jen vizuální spona).
        $childTarget = []; // pid => ['x'=>int,'y'=>int]
        foreach ($people as $pid => $p) {
            $d = $dups[$pid] ?? null;
            if ($d !== null && !isset($d['marriage_family'])) {
                $childTarget[$pid] = ['x' => $d['x'], 'y' => $d['y']];
            } else {
                $childTarget[$pid] = ['x' => $p['x'], 'y' => $p['y']];
            }
        }
        // Mapování fam#fi → virtuální pozice f/m (pro multi-marriage fam s dup-partnerem).
        // U normální fam vrací primární pozici z $people.
        $famPartnerPos = []; // [fi => ['f'=>['x','y'], 'm'=>['x','y']]]
        foreach ($families as $fi => $fam) {
            $fPos = isset($fam['f'], $people[$fam['f']])
                ? ['x' => $people[$fam['f']]['x'], 'y' => $people[$fam['f']]['y']]
                : null;
            $mPos = isset($fam['m'], $people[$fam['m']])
                ? ['x' => $people[$fam['m']]['x'], 'y' => $people[$fam['m']]['y']]
                : null;
            foreach (['f', 'm'] as $role) {
                $pid = $fam[$role];
                if ($pid === null) {
                    continue;
                }
                $d = $dups[$pid] ?? null;
                if ($d !== null && ($d['marriage_family'] ?? null) === $fi) {
                    if ($role === 'f') {
                        $fPos = ['x' => $d['x'], 'y' => $d['y']];
                    } else {
                        $mPos = ['x' => $d['x'], 'y' => $d['y']];
                    }
                }
            }
            $famPartnerPos[$fi] = ['f' => $fPos, 'm' => $mPos];
        }
        // Manželské čáry pro páry bez vlastní fam (partnerství bez dětí, kde jeden
        // partner je dítě v jiné fam — vykreslujeme je vedle rodičovské fam).
        foreach ($couples as [$a, $b]) {
            if (!isset($people[$a], $people[$b])) {
                continue;
            }
            $pa = $people[$a];
            $pb = $people[$b];
            if ($pa['y'] !== $pb['y']) {
                continue; // bezpečnostní pojistka — nemělo by nastat
            }
            // Levý box bereme jako odkud vychází (f v klasické fam), pravý jako cíl.
            $left = $pa['x'] < $pb['x'] ? $pa : $pb;
            $right = $pa['x'] < $pb['x'] ? $pb : $pa;
            $fx = $left['x'] * self::CELL_W + self::BOX_CENTER_X + 55;
            $mx = $right['x'] * self::CELL_W + self::BOX_CENTER_X - 55;
            $yy = $left['y'] * self::CELL_H + 60;
            $push([
                'cls' => 'marriage',
                'd'   => "M $fx,$yy L $mx,$yy",
            ]);
        }

        foreach ($families as $fi => $fam) {
            $f = $fam['f'];
            $m = $fam['m'];
            $x = $fam['x'];
            $y = $fam['y'];
            $fp = $famPartnerPos[$fi]['f'] ?? null;
            $mp = $famPartnerPos[$fi]['m'] ?? null;

            // manželská čára
            if ($f !== null && $m !== null && $fp !== null && $mp !== null) {
                $baseFx = $fp['x'] * self::CELL_W + self::BOX_CENTER_X;
                $baseMx = $mp['x'] * self::CELL_W + self::BOX_CENTER_X;
                $yy = $fp['y'] * self::CELL_H + 60;
                // Multi-marriage: člověk má více svazků — druhá čára nesmí procházet přes
                // box primární partnerky. Vystoupíme proto z protilehlé strany rodiče
                // (tj. strana, k níž leží druhá partnerka). Pokud je druhá partnerka
                // vpravo od f (typicky muž), čára jde z jeho pravé strany; je-li vlevo,
                // z levé strany (obchází primární partnerku zvenčí).
                $otherX = $baseMx;
                if ($otherX > $baseFx) {
                    // partnerka vpravo (standard, nebo multi-marriage doprava): z pravé hrany f
                    $fx = $baseFx + 55;
                    $mx = $baseMx - 55;
                } else {
                    // partnerka vlevo (multi-marriage: druhá žena vedle manžela zleva): z levé hrany f
                    $fx = $baseFx - 55;
                    $mx = $baseMx + 55;
                }
                $push([
                    'cls' => 'marriage',
                    'd'   => "M $fx,$yy L $mx,$yy",
                ]);
            }

            // čáry k dětem — styl geni.com:
            //   rodiče ───── svislá čára ze středu manželské čáry dolů
            //                │
            //   sběrnice ────┴────┬─────┬───  (vodorovná, spojuje všechny děti v fam)
            //                     │     │
            //                   dítě1 dítě2
            // Box node v .tree-canvas: left = x*CELL_W+30, top = y*CELL_H+SVG_DY (family.php).
            // Skutečný spodek boxu = top + BOX_H.
            $kids = [];
            foreach ($fam['c'] ?? [] as $cid) {
                if (!isset($people[$cid])) {
                    continue;
                }
                $t = $childTarget[$cid] ?? ['x' => $people[$cid]['x'], 'y' => $people[$cid]['y']];
                $kids[] = [
                    'cx' => $t['x'] * self::CELL_W + self::BOX_CENTER_X,
                    'cy' => $t['y'] * self::CELL_H,
                ];
            }
            if ($kids !== []) {
                // rodičovský vznik: střed MANŽELSKÉ ČÁRY (u páru) nebo spodek boxu (u jediného rodiče)
                if ($f !== null && $m !== null && $fp !== null && $mp !== null) {
                    // Stejné výpočty jako u 'marriage' — čára jde od strany f k protější straně m
                    $baseFx = $fp['x'] * self::CELL_W + self::BOX_CENTER_X;
                    $baseMx = $mp['x'] * self::CELL_W + self::BOX_CENTER_X;
                    $yy = $fp['y'] * self::CELL_H + 60;
                    $fx = $baseMx > $baseFx ? $baseFx + 55 : $baseFx - 55;
                    $mx = $baseMx > $baseFx ? $baseMx - 55 : $baseMx + 55;
                    $px = intdiv($fx + $mx, 2);
                    $py = $yy;
                } else {
                    // Jednorodičová fam: vznik čáry vždy z pozice samotné fam (virtuální uzel),
                    // nikdy z primární pozice rodiče — ta může ležet na jiném řádku
                    // a child-link by pak procházel přes box jiné osoby (Q12: fi=Q676|NULL).
                    $px = $fam['x'] * self::CELL_W + self::BOX_CENTER_X;
                    $py = $fam['y'] * self::CELL_H + self::BOX_H;
                }

                // Společná sběrnice: y = půlka mezery mezi rodiči a nejvýše položeným dítětem.
                $minCy = min(array_column($kids, 'cy'));
                $midY = intdiv($py + $minCy, 2);
                // Hranice sběrnice = od nejlevnější kotvy po nejpravější (px + cx všech dětí).
                $anchors = array_merge([$px], array_column($kids, 'cx'));
                $busX1 = min($anchors);
                $busX2 = max($anchors);
                // 1) svislá od rodičů dolů ke sběrnici
                $push([
                    'cls' => 'child',
                    'd'   => "M $px,$py L $px,$midY",
                ]);
                // 2) vodorovná sběrnice (jen když má délku — u 1 dítěte přesně pod px je zbytečná)
                if ($busX1 !== $busX2) {
                    $push([
                        'cls' => 'child',
                        'd'   => "M $busX1,$midY L $busX2,$midY",
                    ]);
                }
                // 3) svislé čárky ze sběrnice do každého dítěte
                foreach ($kids as $k) {
                    $cx = $k['cx'];
                    $cy = $k['cy'];
                    $push([
                        'cls' => 'child',
                        'd'   => "M $cx,$midY L $cx,$cy",
                    ]);
                }
            }
        }
        return $paths;
    }
}
