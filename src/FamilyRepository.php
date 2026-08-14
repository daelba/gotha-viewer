<?php
declare(strict_types=1);

/**
 * Datová vrstva: mapování odpovědí gotha-api na interní struktury vieweru.
 * Žádný SPARQL – vše přes ApiClient.
 */
final class FamilyRepository
{
    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Seznam všech šlechtických rodů.
     * API: [{qid,labels{cs,de,en},members:int,wikidata}]
     * Interně: {id,labels,members:int,wd}
     *
     * @return array<int,array{id:string,labels:array{cs:?string,de:?string,en:?string},members:int,wd:?string}>|null
     */
    public function families(): ?array
    {
        try {
            $rows = $this->api->families();
        } catch (ApiException $e) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = self::str($r['qid'] ?? $r['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $labels = self::labels($r['labels'] ?? null);
            $out[] = [
                'id'      => $id,
                'labels'  => $labels,
                'members' => (int) ($r['members'] ?? 0),
                'wd'      => self::str($r['wikidata'] ?? $r['wd'] ?? null),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcasecmp(
                I18n::pick($a['labels']) ?: $a['id'],
                I18n::pick($b['labels']) ?: $b['id']
            );
        });
        return $out;
    }

    /**
     * Jeden rod dle QID.
     * API: {qid,labels,wikidata}
     * Interně: {id,labels,wd}
     *
     * @return array{id:string,labels:array,wd:?string}|null
     */
    public function family(string $qid): ?array
    {
        try {
            $r = $this->api->family($qid);
        } catch (ApiException $e) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }
        return [
            'id'     => $qid,
            'labels' => self::labels($r['labels'] ?? null),
            'wd'     => self::str($r['wikidata'] ?? $r['wd'] ?? null),
        ];
    }

    /**
     * Všichni členové rodu + vztahy.
     * API člen:
     *   {qid,labels,gender:{gender_qid,label},birth,death,wikidata,
     *    father_qid,mother_qid,partner_qids[],title_qids[],isMemberChild:bool,otherFamilies[]}
     * Interně:
     *   {id,labels,gender,gender_label,birth,death,wd,father,mother,partners[],titles[],kid,other_families[]}
     *
     * Pozn.: API příznak isMemberChild (true = "dítě člena rodu, samo bez P39")
     * je přesný opak dřívějšího interního `kid` (true = "člen rodu"). Zde se invertuje.
     *
     * @return array<int,array{id:string,labels:array,gender:?string,gender_label:?string,birth:?string,death:?string,wd:?string,father:?string,mother:?string,partners:array<int,string>,titles:array<int,string>,kid:bool,other_families:array}>|null
     */
    public function members(string $familyQid): ?array
    {
        try {
            $rows = $this->api->members($familyQid);
        } catch (ApiException $e) {
            return null;
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = self::str($r['qid'] ?? $r['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $gender = null;
            $genderLabel = null;
            if (isset($r['gender']) && is_array($r['gender'])) {
                $gender = self::str($r['gender']['gender_qid'] ?? null);
                $genderLabel = self::str($r['gender']['label'] ?? null);
            } else {
                $gender = self::str($r['gender'] ?? null);
                $genderLabel = self::str($r['gender_label'] ?? null);
            }

            $partners = self::strList($r['partner_qids'] ?? $r['partners'] ?? null);
            $titles   = self::strList($r['title_qids'] ?? $r['titles'] ?? null);
            $other    = self::otherFamilies($r['otherFamilies'] ?? $r['other_families'] ?? null);

            $isChild = (bool) ($r['isMemberChild'] ?? false);

            $out[] = [
                'id'             => $id,
                'labels'         => self::labels($r['labels'] ?? null),
                'gender'         => $gender,
                'gender_label'   => $genderLabel,
                'birth'          => self::date($r['birth'] ?? null),
                'death'          => self::date($r['death'] ?? null),
                'wd'             => self::str($r['wikidata'] ?? $r['wd'] ?? null),
                'father'         => self::str($r['father_qid'] ?? $r['father'] ?? null),
                'mother'         => self::str($r['mother_qid'] ?? $r['mother'] ?? null),
                'partners'       => $partners,
                'titles'         => $titles,
                // isMemberChild (API, true = dotáhnout přes rodiče) → kid (interní, true = člen rodu)
                'kid'            => !$isChild,
                'other_families' => $other,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return strcmp($a['birth'] ?? '9999', $b['birth'] ?? '9999');
        });
        return $out;
    }

    /**
     * Detail entity (osoba / rod / cokoli).
     * API (display=full&lang=cs):
     *   {qid,labels,aliases,claims:{PID:[{type,value,property_label,value_uuid,value_label,value_url}]},label,wiki_url}
     * Interně:
     *   {id,label,labels,wiki_url,wikidataId,claims:[{pid,plabel,value,vlabel,type,link}]}
     *
     * – claims jsou seřazené dle pid (a uvnitř pid podle pořadí z API)
     * – value=vlabel ?? value (zobrazení textově)
     * – link = Wikidata URL pro P10, interní /person/{QID} link pro entity
     * – wikidataId promítnuto z P10
     *
     * @return array{id:string,label:string,labels:array,wiki_url:?string,wikidataId:?string,claims:array<int,array{pid:string,plabel:string,value:string,vlabel:?string,type:string,link:?string}>}|null
     */
    public function entity(string $qid): ?array
    {
        try {
            $r = $this->api->entity($qid);
        } catch (ApiException $e) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }

        $labels = self::labels($r['labels'] ?? null);
        $label  = self::str($r['label'] ?? null) ?: (I18n::pick($labels) ?: $qid);
        $wikiUrl = self::str($r['wiki_url'] ?? null);

        $claimsOut = [];
        $wikidataId = null;

        $claims = is_array($r['claims'] ?? null) ? $r['claims'] : [];
        foreach ($claims as $pid => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $type = self::str($c['type'] ?? null) ?? 'literal';
                $rawValue = $c['value'] ?? null;
                $vlabel = self::str($c['value_label'] ?? null);
                $display = $vlabel ?? (is_scalar($rawValue) ? (string) $rawValue : null);
                if ($display === null) {
                    continue;
                }
                $link = null;
                if ($pid === 'P10') {
                    $wikidataId = is_scalar($rawValue) ? (string) $rawValue : null;
                    if ($wikidataId !== null) {
                        $link = 'https://www.wikidata.org/wiki/' . rawurlencode($wikidataId);
                    }
                } elseif ($type === 'entity') {
                    $targetQid = self::str($c['value_uuid'] ?? $rawValue);
                    if ($targetQid !== null && preg_match('/^Q[1-9][0-9]*$/', $targetQid) === 1) {
                        $link = APP_PATH . '/person/' . rawurlencode($targetQid);
                    }
                }
                $claimsOut[] = [
                    'pid'    => (string) $pid,
                    'plabel' => self::str($c['property_label'] ?? null) ?? (string) $pid,
                    'value'  => $display,
                    'vlabel' => $vlabel,
                    'type'   => $type,
                    'link'   => $link,
                ];
            }
        }

        return [
            'id'         => $qid,
            'label'      => $label,
            'labels'     => $labels,
            'wiki_url'   => $wikiUrl,
            'wikidataId' => $wikidataId,
            'claims'     => $claimsOut,
        ];
    }

    // ---------- pomocné normalizační funkce ----------

    private static function str($v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** "1766-07-01T00:00:00Z" → "1766-07-01" (pro jistotu; API už vrací YYYY-MM-DD) */
    private static function date($v): ?string
    {
        $s = self::str($v);
        if ($s === null) {
            return null;
        }
        return strlen($s) >= 10 ? substr($s, 0, 10) : $s;
    }

    /** @return array{cs:?string,de:?string,en:?string} */
    private static function labels($labels): array
    {
        $labels = is_array($labels) ? $labels : [];
        return [
            'cs' => self::str($labels['cs'] ?? null),
            'de' => self::str($labels['de'] ?? null),
            'en' => self::str($labels['en'] ?? null),
        ];
    }

    /** @return array<int,string> */
    private static function strList($arr): array
    {
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * otherFamilies: API vrací [{qid,labels{cs,de,en}}]; normalizujeme na [{id,labels}].
     * @return array<int,array{id:string,labels:array{cs:?string,de:?string,en:?string}}>
     */
    private static function otherFamilies($arr): array
    {
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $f) {
            if (!is_array($f)) {
                continue;
            }
            $id = self::str($f['qid'] ?? $f['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $out[] = [
                'id'     => $id,
                'labels' => self::labels($f['labels'] ?? null),
            ];
        }
        return $out;
    }
}
