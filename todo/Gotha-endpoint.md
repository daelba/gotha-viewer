# Gotha endpoint – návrh REST API pro gotha.wikibase.cloud

> Stav: návrh (k realizaci). Zpracováno: 13. 8. 2026.
> Rozhodnuto: samostatná PHP aplikace (**gotha-api**, např. na daelba.eu)
> jako cachující proxy nad SPARQL endpointem – viewer bude volat její REST API.

## Cíl

Zřídit stabilní JSON REST API nad daty z <https://gotha.wikibase.cloud>, které
poskytne všechny informace potřebné pro gotha-viewer (a případné další klienty),
aniž by klienti museli skládat surové SPARQL dotazy.

## Motivace

- **Jedna centrální cache** pro všechny klienty → menší zátěž Wikibase Query
  Service (dnes každý viewer dotazuje endpoint zvlášť).
- **Stabilní schéma výstupu** – viewer se nerozbije při úpravě SPARQL; změna
  dotazu se provede na jednom místě.
- **Normalizovaná data** – odpadne křehké parsování GROUP_CONCAT řetězců
  (oddělovače `|` a `~`) na straně klienta.
- **CORS + monitoring** – API může využívat i třetí strany; health endpoint
  pro diagnostiku.

## Architektura

- PHP 7.4+ aplikace dle vzoru gotha-viewer (`public/index.php` router, `src/`,
  `cache/`, Makefile s `make deploy`).
- Znovupoužití `FileCache` a `SparqlClient` (POST → WDQS, TTL 24 h).
- Základ URL: `https://gotha.daelba.cz/api/v1/…` (pracovní adresa, upřesnit
  při nasazení).
- Všechny dotazy míří na `https://gotha.wikibase.cloud/query/sparql`

### Povinné prefixy (hlavička každého SPARQL dotazu)

```sparql
PREFIX gd: <https://gotha.wikibase.cloud/entity/>
PREFIX gds: <https://gotha.wikibase.cloud/entity/statement/>
PREFIX gdv: <https://gotha.wikibase.cloud/value/>
PREFIX gdt: <https://gotha.wikibase.cloud/prop/direct/>
PREFIX gp: <https://gotha.wikibase.cloud/prop/>
PREFIX gps: <https://gotha.wikibase.cloud/prop/statement/>
PREFIX gpq: <https://gotha.wikibase.cloud/prop/qualifier/>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
```

## Přehled sub-endpointů

| Metoda | URL | Popis | TTL cache |
|---|---|---|---|
| GET | `/api/v1/families` | Seznam všech šlechtických rodů | 24 h |
| GET | `/api/v1/family/{QID}` | Metadata jednoho rodu | 24 h |
| GET | `/api/v1/family/{QID}/members` | Členové rodu + vztahy (pro strom) | 24 h |
| GET | `/api/v1/person/{QID}` | Detail osoby (všechny claimy) | 24 h |
| GET | `/api/v1/search?q=…` | Fulltext osob a rodů | 1 h |
| GET | `/api/v1/meta` | Zdraví endpointu, počty entit | 5 min |

---

## 1. `GET /api/v1/families` – seznam všech rodů

**Účel:** úvodní tabulka vieweru (název rodu cs/de/en, počet členů, odkaz na
Wikidata).

**SPARQL dotaz** (převzat z `FamilyRepository::families()`):

```sparql
SELECT ?item ?labelCs ?labelDe ?labelEn (COUNT(DISTINCT ?clen) AS ?pocet) (SAMPLE(?wdid) AS ?wikidataId) WHERE {
  ?item gdt:P1 gd:Q9 .
  OPTIONAL {?item rdfs:label ?labelCs. FILTER(LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER(LANG(?labelDe)="de")}
  OPTIONAL {?item rdfs:label ?labelEn. FILTER(LANG(?labelEn)="en")}
  OPTIONAL {?item gdt:P10 ?wdid}
  OPTIONAL {?clen gdt:P39 ?item}
} GROUP BY ?item ?labelCs ?labelDe ?labelEn
```

**Výstup** (řazení dle názvu v požadovaném jazyce, bez diakritiky, provede proxy):

```json
{
  "data": [
    {
      "id": "Q135",
      "labels": { "cs": "Kolovratové", "de": "Kolowrat", "en": "Kolowrat family" },
      "members": 128,
      "wikidata": "Q1782132"
    }
  ],
  "meta": { "count": 42, "generatedAt": "2026-08-13T10:00:00Z", "cached": true }
}
```

**Chyby:** 502 `upstream_error` při výpadku SPARQL endpointu.
**Cache:** 24 h, `?force=1` obnoví.

## 2. `GET /api/v1/family/{QID}` – metadata rodu

**Účel:** hlavička stránky detailu rodu (název, odkaz na Wikidata).

**SPARQL dotaz** (převzat z `FamilyRepository::family()`):

```sparql
SELECT ?labelCs ?labelDe ?labelEn (SAMPLE(?wdid) AS ?wikidataId) WHERE {
  VALUES ?item { gd:Q135 }
  ?item gdt:P1 gd:Q9 .
  OPTIONAL {?item rdfs:label ?labelCs. FILTER(LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER(LANG(?labelDe)="de")}
  OPTIONAL {?item rdfs:label ?labelEn. FILTER(LANG(?labelEn)="en")}
  OPTIONAL {?item gdt:P10 ?wdid}
} GROUP BY ?labelCs ?labelDe ?labelEn
```

**Výstup:**

```json
{
  "data": {
    "id": "Q135",
    "labels": { "cs": "Kolovratové", "de": "Kolowrat", "en": "Kolowrat family" },
    "wikidata": "Q1782132"
  }
}
```

**Chyby:** 400 `invalid_id` (QID neodpovídá `/^Q[1-9][0-9]*$/`), 404 `not_found`
(entita neexistuje nebo není instance rodu, tj. chybí `gdt:P1 gd:Q9`).
**Cache:** 24 h, `?force=1` obnoví.

---

## 3. `GET /api/v1/family/{QID}/members` – členové rodu + vztahy

**Účel:** data pro genealogický strom (`TreeBuilder`). Klíčový endpoint –
dosud nejnáročnější dotaz vieweru. Rozsah odpovídá `FamilyRepository::members()`:
přímí členové (`gdt:P39`) + potomci členů, kteří sami nejsou členy žádného rodu
(`?parent gdt:P5 ?item` + `FILTER NOT EXISTS { ?item gdt:P39 ?anyFamily }`,
příznak `isMemberChild`).

**SPARQL dotaz** (GROUP_CONCAT sloupce proxy interně rozparsuje na JSON pole):

```sparql
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
    ?item gdt:P39 gd:Q135 . BIND(1 AS ?kid)
  } UNION {
    ?parent gdt:P39 gd:Q135 .
    ?parent gdt:P5 ?item .
    FILTER NOT EXISTS { ?item gdt:P39 ?anyFamily }
    BIND(0 AS ?kid)
  }
  OPTIONAL { ?item gdt:P43 ?pohl }
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
  OPTIONAL {
    ?item gdt:P39 ?rodItem .
    FILTER(?rodItem != gd:Q135)
    BIND(STR(?rodItem) AS ?rodQ)
    OPTIONAL { ?rodItem rdfs:label ?rodNazevCs . FILTER(LANG(?rodNazevCs)="cs") }
    OPTIONAL { ?rodItem rdfs:label ?rodNazevDe . FILTER(LANG(?rodNazevDe)="de") }
    OPTIONAL { ?rodItem rdfs:label ?rodNazevEn . FILTER(LANG(?rodNazevEn)="en") }
  }
} GROUP BY ?item ?pohl
```

**Výstup** (řazeno vzestupně dle data narození; data zkrácena na `YYYY-MM-DD`):

```json
{
  "data": [
    {
      "id": "Q647",
      "labels": { "cs": "Jan Kolovrat", "de": "Johann Kolowrat", "en": "Johann Kolowrat" },
      "gender": { "id": "Q460", "label": "muž" },
      "birth": "1766-07-01",
      "death": "1830-01-15",
      "wikidata": "Q123456",
      "father": "Q600",
      "mother": "Q601",
      "partners": ["Q700"],
      "titles": ["Q800"],
      "isMemberChild": false,
      "otherFamilies": [
        { "id": "Q200", "labels": { "cs": "Lobkovicové", "de": "Lobkowitz", "en": "Lobkowitz" } }
      ]
    }
  ],
  "meta": { "count": 128, "generatedAt": "2026-08-13T10:00:00Z", "cached": true }
}
```

Poznámky k výstupu oproti dnešní implementaci vieweru:

- `partners`, `titles` a `otherFamilies` jsou **skutečná JSON pole** – proxy
  rozdělí interní `|`-/`~`-separované GROUP_CONCAT sloupce; klientské pomocné
  funkce `uriList()`, `firstUri()` a `otherFamilies()` ve vieweru odpadnou.
- `isMemberChild` = true pro osoby dotáhnuté přes P5, které samy nemají P39
  (dnešní inverzní příznak `kid` z vieweru).
- `father`/`mother` = první QID ze seznamu (dnes `firstUri()`).

**Chyby:** 400 `invalid_id`, 502 `upstream_error`.
**Cache:** 24 h, `?force=1` obnoví.

---

## 4. `GET /api/v1/person/{QID}` – detail osoby

**Účel:** postranní panel osoby ve stromu (dnes AJAX `/api/person/{QID}`,
`ApiController::person()` → `FamilyRepository::person()`).

**SPARQL dotaz** (všechny direct claimy + labely vlastností a hodnot):

```sparql
SELECT ?prop ?propLabel ?value ?valueLabel ?cs ?de ?en WHERE {
  VALUES ?item { gd:Q647 }
  OPTIONAL {?item rdfs:label ?cs. FILTER(LANG(?cs)="cs")}
  OPTIONAL {?item rdfs:label ?de. FILTER(LANG(?de)="de")}
  OPTIONAL {?item rdfs:label ?en. FILTER(LANG(?en)="en")}
  ?item ?p ?value .
  ?prop wikibase:directClaim ?p .
  SERVICE wikibase:label { bd:serviceParam wikibase:language "cs,de,en". }
}
```

**Výstup** (claimy seřazeny dle pid, pak podle hodnoty; P10 promítnuto do
`wikidataId` + připravený odkaz):

```json
{
  "data": {
    "id": "Q647",
    "labels": { "cs": "Jan Kolovrat", "de": "Johann Kolowrat", "en": "Johann Kolowrat" },
    "wikidataId": "Q123456",
    "claims": [
      {
        "pid": "P3",
        "plabel": "otec",
        "value": "Q600",
        "vlabel": "Karel Kolovrat",
        "type": "uri",
        "link": null
      },
      {
        "pid": "P10",
        "plabel": "Wikidata ID",
        "value": "Q123456",
        "vlabel": null,
        "type": "literal",
        "link": "https://www.wikidata.org/wiki/Q123456"
      },
      {
        "pid": "P33",
        "plabel": "datum narození",
        "value": "1766-07-01T00:00:00Z",
        "vlabel": null,
        "type": "literal",
        "link": null
      }
    ]
  }
}
```

Pravidla naplnění výstupu:

- `vlabel` se plní jen pro hodnoty typu `uri` (label cílové entity); jinak `null`.
- `link` se generuje pro P10 (Wikidata); u hodnot typu `uri` může proxy doplnit
  interní odkaz `…/person/{QID}` ve formě relativní URL.
- `labels` cs/de/en se složí z prvního řádku (opakují se ve všech řádcích).

**Chyby:** 400 `invalid_id`, 404 `not_found` (entita bez claimů), 502
`upstream_error`. **Cache:** 24 h, `?force=1` obnoví (nahradí dosavadní trik
s dočasným cache adresářem z `ApiController::person()`).

## 5. `GET /api/v1/search?q=…` – fulltext osob a rodů

**Účel:** novinka – server-side hledání pro hlavičku vieweru. Dnes viewer
filtruje jen klientsky nad načtenou tabulkou rodů a osob ve stromu.

**Parametry:** `q` (min. 2 znaky, povinný), `limit` (výchozí 20, max 50),
`type` (`person|family|all`, výchozí `all`).

**SPARQL dotaz** (case-insensitive shoda podřetězce v labelu cs/de/en):

```sparql
SELECT DISTINCT ?item ?itemLabel ?typ WHERE {
  {
    ?item gdt:P1 gd:Q9 . BIND("family" AS ?typ)
  } UNION {
    ?item gdt:P39 ?rod . BIND("person" AS ?typ)
  }
  ?item rdfs:label ?itemLabel .
  FILTER(LANG(?itemLabel) IN ("cs","de","en"))
  FILTER(CONTAINS(LCASE(?itemLabel), LCASE("kolovrat")))
} LIMIT 20
```

(Pozn.: hledaný řetězec se vkládá pouze po escapování uvozovek a zpětných
lomítek; `limit` jako číslo po validaci.)

**Výstup:**

```json
{
  "data": [
    { "id": "Q135", "label": "Kolovratové", "type": "family" },
    { "id": "Q647", "label": "Jan Kolovrat", "type": "person" }
  ],
  "meta": { "count": 2, "query": "kolovrat" }
}
```

**Chyby:** 400 `invalid_query` (krátký/prázdný `q`). **Cache:** 1 h dle q.

## 6. `GET /api/v1/meta` – zdraví a statistiky

**Účel:** monitoring/diagnostika (navazuje na `tools/diagnose-server.sh`),
landing informace pro dokumentaci API.

**SPARQL dotazy** (dva jednoduché COUNT dotazy):

```sparql
SELECT (COUNT(DISTINCT ?item) AS ?pocet) WHERE { ?item gdt:P1 gd:Q9 . }
SELECT (COUNT(DISTINCT ?item) AS ?pocet) WHERE { ?item gdt:P39 ?rod . }
```

**Výstup:**

```json
{
  "status": "ok",
  "apiVersion": "1",
  "sparql": "up",
  "counts": { "families": 42, "persons": 1530 },
  "cache": { "dir": "ok", "entries": 17 },
  "generatedAt": "2026-08-13T10:00:00Z"
}
```

**Chyby:** 503 `unavailable` při `sparql = "down"`. **Cache:** 5 min.

---

## Průřezová pravidla API

### Bezpečnost a validace

- QID se vždy validuje regexem `/^Q[1-9][0-9]*$/` a do dotazu se vkládá pouze
  přes `VALUES` (jako dnešní `FamilyRepository::sanitizeQid()`).
- Hledaný řetězec v `/search` se escapuje (uvozovky, zpětná lomítka).
- Timeout na SPARQL dotaz 30 s (jako `SparqlClient::TIMEOUT`); při timeoutu
  502 `upstream_error`.
- User-Agent `gotha-api/1.0 (+https://gotha.daelba.cz)` ve všech požadavcích
  na WDQS.

### Formát chyb

```json
{ "error": { "code": "invalid_id", "message": "Neplatné QID entity." } }
```

Kódy: `invalid_id` (400), `invalid_query` (400), `not_found` (404),
`upstream_error` (502), `unavailable` (503).

### Cache

- Souborová cache (`FileCache`), klíč = hash normalizovaného SPARQL dotazu /
  hledacího dotazu.
- TTL: entities/data endpointy 24 h, search 1 h, meta 5 min.
- Parametr `?force=1` přeskočí cache daného requestu a uloží čerstvý výsledek.
- HTTP hlavičky: `Cache-Control: public, max-age=86400`, `ETag` dle obsahu.

### CORS a kódování

- `Content-Type: application/json; charset=utf-8` (JSON_UNESCAPED_UNICODE +
  JSON_UNESCAPED_SLASHES, jako dnešní `ApiController`).
- `Access-Control-Allow-Origin: *` pro GET endpointy (veřejná data CC0).
- JSON výstupy UTF-8; datumové hodnoty ISO 8601, u members zkrácené na
  `YYYY-MM-DD` (helper `time()` z vieweru přesune do proxy).

## Dopad na gotha-viewer

- `SparqlClient` nahradí tenký HTTP klient volající gotha-api (např. třída
  `ApiClient` s metodami `families()`, `family()`, `members()`, `person()`).
- `FamilyRepository` přestane skládat SPARQL; odpadne parsování GROUP_CONCAT
  řetězců (`uriList()`, `firstUri()`, `otherFamilies()`).
- `ApiController::person()` se přepne na `/api/v1/person/{QID}`; personal panel
  (`public/js/app.js`) beze změny nebo drobná úprava tvaru klíčů (`data.*`).
- Cache ve vieweru zůstane jen velmi krátká nebo žádná – primární cache je
  v gotha-api.
- Migrace postupně: 1) gotha-api nasadit, 2) viewer přepnout, 3) smoke testy
  (`make smoke-local`, `tests/smoke/smoke.sh`), 4) deploy (`make deploy`).

## Kroky realizace

- [ ] Vytvořit repozitář `gotha-api` (struktura `public/`, `src/`, `cache/`,
      `tests/smoke/`, Makefile dle vzoru gotha-viewer).
- [ ] Přenést `FileCache` a `SparqlClient`; doplnit router `/api/v1/*` a
      jednotný JSON error handler.
- [ ] Implementovat endpointy v pořadí: meta → families → family → members →
      person → search.
- [ ] Normalizátor výstupů (rozpad GROUP_CONCAT sloupců na pole, `YYYY-MM-DD`
      čas, složení `otherFamilies`).
- [ ] Smoke testy pro každý endpoint (validní QID, nevalidní QID, neexistující
      entita, force).
- [ ] Nasadit na daelba.eu, ověřit CORS a `?force=1`.
- [ ] Přepnout gotha-viewer na API registry z `.env` (`API_BASE`), odebrat
      interní SPARQL skládání.
- [ ] Aktualizovat README obou projektů.




