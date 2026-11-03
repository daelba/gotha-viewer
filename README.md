# Gotha viewer

Tenký PHP/HTML prohlížeč šlechtických rodů z [gotha.wikibase.cloud](https://gotha.wikibase.cloud).
Surová data získává výhradně přes **gotha-api** (`https://tools.daelba.eu/gotha/api/v1`),
která je cachující proxy nad SPARQL endpointem `https://gotha.wikibase.cloud/query/sparql`.
Viewer sám žádné SPARQL dotazy nesestavuje.

## Funkce

- **Seznam rodů**: tabulka s textovým filtrem, rychlým náhledem názvů (cs/de/en)
  a odkazem na Wikidata.
- **Detail rodu**: genealogický strom (vlastní CSS+SVG, žádná externí knihovna),
  hledání osob v reálném čase (scroll + zvýraznění), postraní panel s detaily osoby.
- **Jazyky**: čeština (výchozí), němčina, angličtina – přepínač vlaječkami.
- **Cache**: primární cache je v gotha-api (TTL 24 hodin); tlačítko
  „Aktualizovat data" vede na `/refresh?back=<aktuální cesta>`, která
  přesměruje zpět s `?force=1` a to viewer předá do gotha-api (přeskočí cache
  a uloží čerstvá data).

## Nasazení

Viz [docs/deployment.md](docs/deployment.md). Produkce běží v Dockeru na hub VM1
jako https://gotha-view.daelba.eu (compose projekt `gotha-viewer` v síti `proxy-net`).

## Požadavky

- PHP 7.4+ s rozšířeními `json`, `mbstring`, `openssl`
- Apache s `mod_rewrite` a `mod_headers`
- Oprávnění `AllowOverride All` pro adresář projektu

## RESTful URL

- `/` – seznam všech rodů
- `/family/{QID}` – detail rodu + strom
- `/api/person/{QID}` – JSON detail osoby (AJAX z postranního panelu)
- `/refresh?back=<cesta>` – přesměruje na `<cesta>?force=1` (bez `back` použije
  Referer, jinak `/`); `force` viewer předá do gotha-api, které přeskočí cache
  a uloží čerstvá data

## Postranní panel osoby

Řazení a formát řádků detailu osoby (`/api/person/{QID}`) řídí šablona
`config/panel-fields.json` – pořadí prvků v poli `fields` = pořadí řádků v panelu.
Prvky:

- `pid`: PID vlastnosti nebo `"*"` = všechny zbývající vlastnosti (pořadí z API)
- `format: "date"`: čas `1800-01-13T00:00:00Z` zobrazí jako „13. ledna 1800"
- `join: ", "`: více hodnot vlastnosti sloučí do jednoho řádku (např. tituly P38)
- `hidden: true`: vlastnost se nezobrazí (P1 typ záznamu, P10 Wikidata ID entity)
- `label`: přepíše popisek vlastnosti

Šablonu aplikuje server (`src/PanelTemplate.php`); chybí-li nebo je nevalidní,
claims projdou v původní podobě z API. Změna šablony se projeví po redeployi
(`make restart`); viewer odpovědi API necachuje.
Známé PIDy gotha wikibase: P39 rod, P43 pohlaví, P33/P34 narození/úmrtí,
P3/P4 otec/matka, P32 choť, P38 titul, P48 poznámka, P44 zdroj.

## Ikony

SVG ikony z Wikimedia Commons:
- `public/icons/refresh.svg` – Font Awesome 5 solid (public domain)
- `public/icons/wikidata.svg` – Wikidata logo (CC0)
- `public/icons/flag-{cs,de,en}.svg` – vlaječky (public domain)
