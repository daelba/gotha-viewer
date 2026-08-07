<?php
declare(strict_types=1);

/**
 * Datová vrstva: dotazy na gotha wikibase + parsování výsledků.
 */
final class FamilyRepository
{
    private SparqlClient $sparql;

    public function __construct(SparqlClient $sparql)
    {
        $this->sparql = $sparql;
    }

    /**
     * Seznam všech šlechtických rodů.
     * @return array<int,array{id:string,labels:array{cs:?string,de:?string,en:?string},members:int,wd:?string}>|null
     */
    public function families(): ?array
    {
        $rows = $this->sparql->query(<<<'SPARQL'
SELECT ?item ?labelCs ?labelDe ?labelEn (COUNT(DISTINCT ?clen) AS ?pocet) (SAMPLE(?wdid) AS ?wikidataId) WHERE {
  ?item gdt:P1 gd:Q9 .
  OPTIONAL {?item rdfs:label ?labelCs. FILTER(LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER(LANG(?labelDe)="de")}
  OPTIONAL {?item rdfs:label ?labelEn. FILTER(LANG(?labelEn)="en")}
  OPTIONAL {?item gdt:P10 ?wdid}
  OPTIONAL {?clen gdt:P39 ?item}
} GROUP BY ?item ?labelCs ?labelDe ?labelEn
SPARQL);
        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            $id = self::qid($r, 'item');
            if ($id === null) {
                continue;
            }
            $out[] = [
                'id'      => $id,
                'labels'  => [
                    'cs' => self::val($r, 'labelCs'),
                    'de' => self::val($r, 'labelDe'),
                    'en' => self::val($r, 'labelEn'),
                ],
                'members' => (int) (self::val($r, 'pocet') ?? '0'),
                'wd'      => self::val($r, 'wikidataId'),
            ];
        }

        // Řazení dle název v aktuálním jazyce (bez diakritiky, case-insensitive)
        usort($out, static function (array $a, array $b): int {
            return strcasecmp(
                I18n::pick($a['labels']) ?: $a['id'],
                I18n::pick($b['labels']) ?: $b['id']
            );
        });
        return $out;
    }

    /**
     * Jeden rod dle QID (label + wikidata id).
     * @return array{id:string,labels:array,wd:?string}|null
     */
    public function family(string $qid): ?array
    {
        $rows = $this->sparql->query(sprintf(<<<'SPARQL'
SELECT ?labelCs ?labelDe ?labelEn (SAMPLE(?wdid) AS ?wikidataId) WHERE {
  VALUES ?item { gd:%s }
  ?item gdt:P1 gd:Q9 .
  OPTIONAL {?item rdfs:label ?labelCs. FILTER(LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER(LANG(?labelDe)="de")}
  OPTIONAL {?item rdfs:label ?labelEn. FILTER(LANG(?labelEn)="en")}
  OPTIONAL {?item gdt:P10 ?wdid}
} GROUP BY ?labelCs ?labelDe ?labelEn
SPARQL, self::sanitizeQid($qid) ?? 'Q0'));

        if ($rows === null || $rows === []) {
            return null;
        }
        $r = $rows[0];
        return [
            'id'     => $qid,
            'labels' => [
                'cs' => self::val($r, 'labelCs'),
                'de' => self::val($r, 'labelDe'),
                'en' => self::val($r, 'labelEn'),
            ],
            'wd'     => self::val($r, 'wikidataId'),
        ];
    }

    /**
     * Všichni členové rodu + vztahy (otec/matka/partner).
     * @return array<int,array{id:string,labels:array,gender:?string,birth:?string,death:?string,wd:?string,father:?string,mother:?string,partners:array<int,string>,titles:array<int,string>,kid?:bool,other_families?:array}>|null
     */
    public function members(string $familyQid): ?array
    {
        $safe = self::sanitizeQid($familyQid);
        if ($safe === null) {
            return null;
        }
        $rows = $this->sparql->query(sprintf(<<<'SPARQL'
SELECT ?item ?pohl (SAMPLE(?cs) AS ?labelCs) (SAMPLE(?de) AS ?labelDe) (SAMPLE(?en) AS ?labelEn)
       (SAMPLE(?pohlLbl) AS ?pohlLabel)
       (SAMPLE(?nar) AS ?narkdy) (SAMPLE(?umr) AS ?zemkdy) (SAMPLE(?wdid) AS ?wikidataId)
       (SAMPLE(?kid) AS ?fromChild)
       (GROUP_CONCAT(DISTINCT ?ot;separator="|") AS ?otecIds)
       (GROUP_CONCAT(DISTINCT ?ma;separator="|") AS ?matkaIds)
       (GROUP_CONCAT(DISTINCT ?pa;separator="|") AS ?partnerIds)
       (GROUP_CONCAT(DISTINCT ?tit;separator="|") AS ?titulIds)
       (GROUP_CONCAT(DISTINCT ?rodQ;separator="|") AS ?dalsiRodyQid)
       (GROUP_CONCAT(DISTINCT ?rodNazevCs;separator="~") AS ?dalsiNazevCs)
       (GROUP_CONCAT(DISTINCT ?rodNazevDe;separator="~") AS ?dalsiNazevDe)
       (GROUP_CONCAT(DISTINCT ?rodNazevEn;separator="~") AS ?dalsiNazevEn)
WHERE {
  {
    ?item gdt:P39 gd:%s . BIND(1 AS ?kid)
  } UNION {
    ?parent gdt:P39 gd:%s .
    ?parent gdt:P5 ?item .
    FILTER NOT EXISTS { ?item gdt:P39 ?anyFamily }
    BIND(0 AS ?kid)
  }
  OPTIONAL { ?item gdt:P43 ?pohl }
  # label pohlaví: preferujeme cs/en/mul, fallback na de
  OPTIONAL {
    ?pohl rdfs:label ?pohlLbl .
    FILTER(LANG(?pohlLbl) IN ("cs","en","mul","de"))
  }
  OPTIONAL { ?item rdfs:label ?cs. FILTER(LANG(?cs)="cs") }
  OPTIONAL { ?item rdfs:label ?de. FILTER(LANG(?de)="de") }
  OPTIONAL { ?item rdfs:label ?en. FILTER(LANG(?en)="en") }
  OPTIONAL { ?item gdt:P33 ?nar }
  OPTIONAL { ?item gdt:P34 ?umr }
  OPTIONAL { ?item gdt:P10 ?wdid }
  OPTIONAL { ?item gdt:P3 ?ot }
  OPTIONAL { ?item gdt:P4 ?ma }
  OPTIONAL { ?item gdt:P32 ?pa }
  OPTIONAL { ?item gdt:P38 ?tit }
  # Další rody (P39) kromě aktuálního. QID + názvy oddělenými sloupci
  # (labely spojujeme separátorem "~", který se v názvech rodů nevyskytuje).
  OPTIONAL {
    ?item gdt:P39 ?rodItem .
    FILTER(?rodItem != gd:%s)
    BIND(STR(?rodItem) AS ?rodQ)
    OPTIONAL { ?rodItem rdfs:label ?rodNazevCs . FILTER(LANG(?rodNazevCs)="cs") }
    OPTIONAL { ?rodItem rdfs:label ?rodNazevDe . FILTER(LANG(?rodNazevDe)="de") }
    OPTIONAL { ?rodItem rdfs:label ?rodNazevEn . FILTER(LANG(?rodNazevEn)="en") }
  }
} GROUP BY ?item ?pohl
SPARQL, $safe, $safe, $safe));

        if ($rows === null) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            $id = self::qid($r, 'item');
            if ($id === null) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'labels'   => [
                    'cs' => self::val($r, 'labelCs'),
                    'de' => self::val($r, 'labelDe'),
                    'en' => self::val($r, 'labelEn'),
                ],
                'gender'       => self::qid($r, 'pohl'),
                'gender_label' => self::val($r, 'pohlLabel'),
                'birth'    => self::time($r, 'narkdy'),
                'death'    => self::time($r, 'zemkdy'),
                'wd'       => self::val($r, 'wikidataId'),
                'father'   => self::firstUri($r, 'otecIds'),
                'mother'   => self::firstUri($r, 'matkaIds'),
                'partners' => self::uriList($r, 'partnerIds'),
                'titles'   => self::uriList($r, 'titulIds'),
                'kid'      => self::val($r, 'fromChild') !== '1',
                'other_families' => self::otherFamilies($r),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($a['birth'] ?? '9999', $b['birth'] ?? '9999');
        });
        return $out;
    }

    /**
     * Detail osoby: všechny direct claimy + labely vlastností a hodnot (pro URI).
     * @return array{id:string,labels:array,claims:array<int,array{pid:string,plabel:string,value:string,vlabel:?string,type:string}>}|null
     */
    public function person(string $qid): ?array
    {
        $safe = self::sanitizeQid($qid);
        if ($safe === null) {
            return null;
        }
        $rows = $this->sparql->query(sprintf(<<<'SPARQL'
SELECT ?prop ?propLabel ?value ?valueLabel ?cs ?de ?en WHERE {
  VALUES ?item { gd:%s }
  OPTIONAL {?item rdfs:label ?cs. FILTER(LANG(?cs)="cs")}
  OPTIONAL {?item rdfs:label ?de. FILTER(LANG(?de)="de")}
  OPTIONAL {?item rdfs:label ?en. FILTER(LANG(?en)="en")}
  ?item ?p ?value .
  ?prop wikibase:directClaim ?p .
  SERVICE wikibase:label { bd:serviceParam wikibase:language "cs,de,en". }
}
SPARQL, $safe));

        if ($rows === null) {
            return null;
        }

        $labels = ['cs' => null, 'de' => null, 'en' => null];
        $claims = [];
        foreach ($rows as $r) {
            if ($labels['cs'] === null) {
                $labels['cs'] = self::val($r, 'cs');
            }
            if ($labels['de'] === null) {
                $labels['de'] = self::val($r, 'de');
            }
            if ($labels['en'] === null) {
                $labels['en'] = self::val($r, 'en');
            }

            $pid = self::qid($r, 'prop');
            $raw = self::val($r, 'value');
            if ($pid === null || $raw === null) {
                continue;
            }
            $type = $r['value']['type'] ?? 'literal';
            $claims[] = [
                'pid'    => $pid,
                'plabel' => self::val($r, 'propLabel') ?? $pid,
                'value'  => self::qid(['value' => ['value' => $raw]], 'value') ?? $raw,
                'vlabel' => $type === 'uri' ? (self::val($r, 'valueLabel') ?? $raw) : null,
                'type'   => $type,
            ];
        }

        return ['id' => $safe, 'labels' => $labels, 'claims' => $claims];
    }

    // ---------- pomocné parsování ----------

    private static function val(array $row, string $key): ?string
    {
        $v = $row[$key]['value'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Poslední segment URI (Q/P id). */
    private static function qid(array $row, string $key): ?string
    {
        $v = self::val($row, $key);
        if ($v === null || strpos($v, 'http') !== 0) {
            return null;
        }
        $pos = strrpos($v, '/');
        return $pos === false ? $v : substr($v, $pos + 1);
    }

    /** První URI id z pipe-separovaného seznamu. */
    private static function firstUri(array $row, string $key): ?string
    {
        $items = self::uriList($row, $key);
        return $items[0] ?? null;
    }

    /** @return array<int,string> */
    private static function uriList(array $row, string $key): array
    {
        $v = self::val($row, $key);
        if ($v === null) {
            return [];
        }
        $out = [];
        foreach (explode('|', $v) as $uri) {
            $pos = strrpos($uri, '/');
            $out[] = $pos === false ? $uri : substr($uri, $pos + 1);
        }
        return $out;
    }

    /**
     * Sestaví seznam dalších rodů (P39) kromě aktuálního.
     * Labely z GROUP_CONCAT jsou oddělené "~", QIDy "|".
     * Protože se v datech nevyskytují osoby s více než ~3 dalšími rody,
     * postačuje jednoduchá mapa po pořadí.
     *
     * @return array<int,array{id:string,labels:array{cs:?string,de:?string,en:?string}}>
     */
    private static function otherFamilies(array $row): array
    {
        $qids = self::uriList($row, 'dalsiRodyQid');
        if ($qids === []) {
            return [];
        }
        $cs = self::val($row, 'dalsiNazevCs') ?? '';
        $de = self::val($row, 'dalsiNazevDe') ?? '';
        $en = self::val($row, 'dalsiNazevEn') ?? '';
        $csA = $cs !== '' ? explode('~', $cs) : [];
        $deA = $de !== '' ? explode('~', $de) : [];
        $enA = $en !== '' ? explode('~', $en) : [];
        $out = [];
        foreach ($qids as $i => $qid) {
            $out[] = [
                'id'     => $qid,
                'labels' => [
                    'cs' => $csA[$i] ?? null,
                    'de' => $deA[$i] ?? null,
                    'en' => $enA[$i] ?? null,
                ],
            ];
        }
        return $out;
    }

    /** "1766-07-01T00:00:00Z" → "1766-07-01" */
    private static function time(array $row, string $key): ?string
    {
        $v = self::val($row, $key);
        if ($v === null) {
            return null;
        }
        return substr($v, 0, 10);
    }

    /** Bezpečnostní filtr pro QID (používá se v SPARQL VALUES). */
    private static function sanitizeQid(string $qid): ?string
    {
        return preg_match('/^Q[1-9][0-9]*$/', $qid) === 1 ? $qid : null;
    }
}
