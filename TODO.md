y# gotha-viewer – implementační plán

Tenký PHP/HTML viewer pro https://gotha.wikibase.cloud přes SPARQL endpoint.

## Rozhodnutí (odsouhlaseno)
- PHP 7.4+, Apache (`/var/www/`), bez Dockeru, bez Composeru
- Vlastní mini autoloader, SPARQL přes `stream_context` POST
- Souborová cache TTL 24 h + tlačítko „Aktualizovat data"
- Jazyky cs (default) / de / en – přepínač vlaječkami
- Genealogický strom: vlastní CSS+SVG + vanilla JS, hledání ve stromě
- Data jsou řídká → UI musí zvládat prázdné hodnoty

## Struktura
```
public/    index.php, .htaccess, css/, js/, icons/
src/       bootstrap.php, SparqlClient, FileCache, I18n, View, FamilyRepository, ApiController, helpers
templates/ layout, families, family (strom + postranní panel)
cache/     souborová cache (deny all)
tests/smoke/  smoke testy
docs/      deployment.md, README.md
```

## Checklist
- [x] Průzkum SPARQL endpointu a datového modelu
- [x] Odsouhlasení architektury
- [x] 1. Základní struktura: bootstrap, router, .htaccess, helpers
- [x] 2. SparqlClient + FileCache (TTL 24h, flush)
- [x] 3. I18n (cs/de/en) + layout (vlaječky, refresh tlačítko)
- [x] 4. Seznam rodů: tabulka, filtr, řazení, odkaz na Wikidata
- [x] 5. Rod – strom: FamilyRepository, layout algoritmus, SVG hrany, boxy osob
- [x] 6. Hledání ve stromě (scroll + zvýraznění)
- [x] 7. Postranní panel: detail osoby (AJAX /api/person)
- [x] 8. Ikony z Wikimedia Commons
- [x] 9. Dokumentace: README, docs/deployment.md
- [x] 10. Smoke testy (tests/smoke/)
- [x] 11. End-to-end ověření na PHP built-in serveru

## Známé poznámky / další kroky
- Většina rodů v databázi zatím nemá členy s vazbami → strom je prázdný,
  zobrazuje se hláška „Tento rod zatím nemá...". Je to stav dat, ne chyba.
- Zoom/pan ve stromě je doporučené rozšíření (zatím jen scroll).
- Fotografie osob se zatím nezobrazují (endpoint `wikidataImage` je připraven
  v ApiController, ale SPARQL ho nevrací – doplnit dotaz při potřebě).
- Git commit zatím odložen – udělat po nasazení na produkční.
- Makefile připraven: `make deploy` nasadí do /var/www/html/gotha-viewer
