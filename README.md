# Gotha viewer

Tenký PHP/HTML prohlížeč šlechtických rodů z [gotha.wikibase.cloud](https://gotha.wikibase.cloud).
Data získává SPARQL dotazy na endpoint `https://gotha.wikibase.cloud/query/sparql`.

## Funkce

- **Seznam rodů**: tabulka s textovým filtrem, rychlým náhledem názvů (cs/de/en)
  a odkazem na Wikidata.
- **Detail rodu**: genealogický strom (vlastní CSS+SVG, žádná externí knihovna),
  hledání osob v reálném čase (scroll + zvýraznění), postraní panel s detaily osoby.
- **Jazyky**: čeština (výchozí), němčina, angličtina – přepínač vlaječkami.
- **Cache**: souborová cache pro SPARQL výsledky (TTL 24 hodin), tlačítko
  „Aktualizovat data" pro okamžité obnovení.

## Nasazení

Viz [docs/deployment.md](docs/deployment.md).

## Požadavky

- PHP 7.4+ s rozšířeními `json`, `mbstring`, `openssl`
- Apache s `mod_rewrite` a `mod_headers`
- Oprávnění `AllowOverride All` pro adresář projektu

## RESTful URL

- `/` – seznam všech rodů
- `/family/{QID}` – detail rodu + strom
- `/api/person/{QID}` – JSON detail osoby (AJAX z postranního panelu)
- `/refresh` – vyprázdnění cache a návrat na předchozí stránku

## Ikony

SVG ikony z Wikimedia Commons:
- `public/icons/refresh.svg` – Font Awesome 5 solid (public domain)
- `public/icons/wikidata.svg` – Wikidata logo (CC0)
- `public/icons/flag-{cs,de,en}.svg` – vlaječky (public domain)
