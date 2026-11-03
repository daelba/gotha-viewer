<?php
declare(strict_types=1);

/**
 * Šablona postranního panelu osoby (config/panel-fields.json).
 *
 * Pořadí prvků v poli "fields" = pořadí řádků v panelu. Prvky:
 *   { "pid": "P39" }                     – konkrétní vlastnost
 *   { "pid": "*" }                       – všechny zbývající vlastnosti (pořadí z API)
 *   { "pid": "P1",  "hidden": true }     – vlastnost se nezobrazí
 *   { "pid": "P33", "format": "date" }   – "1800-01-13T00:00:00Z" → "13. ledna 1800"
 *   { "pid": "P38", "join": ", " }       – více hodnot vlastnosti na jednom řádku
 *   { "pid": "P44", "label": "…" }       – přepsání popisku vlastnosti
 *
 * Známé PIDy gotha wikibase: P39 rod, P43 pohlaví, P33/P34 datum narození/úmrtí,
 * P3/P4 otec/matka, P32 choť, P38 šlechtický titul, P48 poznámka,
 * P44 popsáno ve zdroji, P1 typ záznamu, P10 Wikidata ID entity.
 */
final class PanelTemplate
{
    /** České genitivy měsíců pro formát „j. měsíce rok“. */
    private const MONTHS_CS = [
        1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince',
    ];

    /** @var array<int,array<string,mixed>> Zvalidované prvky šablony (v pořadí). */
    private array $fields;

    /** @var array<string,true> Vlastnosti pojmenované v šabloně (mimo "*"). */
    private array $named = [];

    /** @var array<string,true> Vlastnosti s hidden – nikdy se nezobrazí. */
    private array $hidden = [];

    /** @param array<int,mixed> $fields Syrové "fields" z JSON. */
    private function __construct(array $fields)
    {
        $this->fields = [];
        foreach ($fields as $f) {
            if (!is_array($f) || !isset($f['pid']) || !is_string($f['pid']) || $f['pid'] === '') {
                continue;
            }
            $this->fields[] = $f;
            if ($f['pid'] === '*') {
                continue;
            }
            $this->named[$f['pid']] = true;
            if (($f['hidden'] ?? false) === true) {
                $this->hidden[$f['pid']] = true;
            }
        }
    }

    /**
     * Načte šablonu ze souboru. Chybí-li nebo je nevalidní, vrátí null –
     * volající pak claims předá nezpracované (panel degraduje do původní
     * podoby místo pádu).
     */
    public static function load(string $path): ?self
    {
        if (!is_file($path)) {
            error_log("PanelTemplate: šablona $path neexistuje, panel ji přeskakuji.");
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        $fields = is_array($json) ? ($json['fields'] ?? null) : null;
        if (!is_array($fields)) {
            error_log("PanelTemplate: nevalidní šablona $path, panel ji přeskakuji.");
            return null;
        }
        return new self($fields);
    }

    /**
     * Aplikuje šablonu na claims z FamilyRepository::entity().
     *
     * @param array<int,array{pid:string,plabel:string,value:string,type:string,link:?string}> $claims
     * @return array<int,array{pid:string,plabel:string,values:array<int,array{value:string,link:?string}>,join?:string}>
     */
    public function apply(array $claims): array
    {
        // Seskupit dle pid; pořadí = první výskyt (pořadí z API), uvnitř pid zachováno.
        $byPid = [];
        $pidOrder = [];
        foreach ($claims as $c) {
            $pid = $c['pid'];
            if (!isset($byPid[$pid])) {
                $byPid[$pid] = [];
                $pidOrder[] = $pid;
            }
            $byPid[$pid][] = $c;
        }

        $rows = [];
        $done = [];
        foreach ($this->fields as $field) {
            $pid = $field['pid'];
            if ($pid === '*') {
                // všechny dosud neuvedené ne-skryté vlastnosti, v pořadí z API
                foreach ($pidOrder as $p) {
                    if (isset($this->hidden[$p]) || isset($this->named[$p]) || isset($done[$p])) {
                        continue;
                    }
                    $done[$p] = true;
                    foreach ($this->buildRows($p, $byPid[$p], []) as $row) {
                        $rows[] = $row;
                    }
                }
                continue;
            }
            if (isset($this->hidden[$pid]) || isset($done[$pid]) || !isset($byPid[$pid])) {
                continue;
            }
            $done[$pid] = true;
            foreach ($this->buildRows($pid, $byPid[$pid], $field) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Sestaví výstupní řádky pro jednu vlastnost. Má-li prvek šablony "join",
     * všechny hodnoty se sloučí do jednoho řádku (separátor doplní JS);
     * jinak jedna hodnota = jeden řádek.
     *
     * @param array<int,array{pid:string,plabel:string,value:string,link:?string}> $claims
     * @param array<string,mixed> $field
     * @return array<int,array{pid:string,plabel:string,values:array<int,array{value:string,link:?string}>,join?:string}>
     */
    private function buildRows(string $pid, array $claims, array $field): array
    {
        $label = isset($field['label']) && is_string($field['label']) && $field['label'] !== ''
            ? $field['label']
            : ($claims[0]['plabel'] ?? $pid);
        $format = $field['format'] ?? null;

        $values = [];
        foreach ($claims as $c) {
            $value = $c['value'];
            if ($format === 'date') {
                $value = self::formatDateCs($value);
            }
            $values[] = ['value' => $value, 'link' => $c['link'] ?? null];
        }
        if ($values === []) {
            return [];
        }

        $join = isset($field['join']) && is_string($field['join']) ? $field['join'] : null;
        if ($join !== null) {
            return [[
                'pid'    => $pid,
                'plabel' => $label,
                'values' => $values,
                'join'   => $join,
            ]];
        }
        $rows = [];
        foreach ($values as $v) {
            $rows[] = [
                'pid'    => $pid,
                'plabel' => $label,
                'values' => [$v],
            ];
        }
        return $rows;
    }

    /** "1800-01-13T00:00:00Z" → "13. ledna 1800"; neparsovatelné projde beze změny. */
    private static function formatDateCs(string $value): string
    {
        if (preg_match('/^(\d{1,4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return $value;
        }
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return $value;
        }
        return $day . '. ' . self::MONTHS_CS[$month] . ' ' . $year;
    }
}
