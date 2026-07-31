# Cíl
Tenká php/html vrstva pro zobrazování dat z https://gotha.wikibase.cloud pomocí SPARQL dotazů na endpoint https://gotha.wikibase.cloud/query/sparql.

# Prefixy
Všechny sparql dotazy musí být uvozeny prefixy:
```
PREFIX gd: <https://gotha.wikibase.cloud/entity/>
PREFIX gds: <https://gotha.wikibase.cloud/entity/statement/>
PREFIX gdv: <https://gotha.wikibase.cloud/value/>
PREFIX gdt: <https://gotha.wikibase.cloud/prop/direct/>
PREFIX gp: <https://gotha.wikibase.cloud/prop/>
PREFIX gps: <https://gotha.wikibase.cloud/prop/statement/>
PREFIX gpq: <https://gotha.wikibase.cloud/prop/qualifier/>
```

# Zobrazovací stránky
## Všechny šlechtické rody
```sparql
SELECT ?item ?labelCs ?labelDe ?wikidata WHERE {
  SERVICE wikibase:label { bd:serviceParam wikibase:language "[AUTO_LANGUAGE],mul,en". }
  ?item gdt:P1 gd:Q9.
  OPTIONAL {?item rdfs:label ?labelCs. FILTER (LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER (LANG(?labelDe)="de")}
  OPTIONAL {?item gdt:P10 ?wd. BIND(URI(CONCAT("http://www.wikidata.org/entity/",?wd)) AS ?wikidata)}
}
```
- Zobrazit jako tabulku
- Link na stránky jednotlivých rodů
- Link na Wikidata (prolinkovaná ikona Wikidat)

## Jednotlivé rody
```sparql
SELECT ?item ?pohl ?labelCs ?labelDe ?wikidata ?otec ?matka ?partner WHERE {
  SERVICE wikibase:label { bd:serviceParam wikibase:language "[AUTO_LANGUAGE],mul,en". }
  # členové
  ?item gdt:P39 gd:Q135.
  OPTIONAL { ?item gdt:P43 ?pohl }
  
  # labels
  OPTIONAL {?item rdfs:label ?labelCs. FILTER (LANG(?labelCs)="cs")}
  OPTIONAL {?item rdfs:label ?labelDe. FILTER (LANG(?labelDe)="de")}
  
  # Wikidata
  OPTIONAL {?item gdt:P10 ?wd. BIND(URI(CONCAT("http://www.wikidata.org/entity/",?wd)) AS ?wikidata)}
  
  # vztahy
  OPTIONAL { ?item gdt:P3 ?otec }
  OPTIONAL { ?item gdt:P4 ?otec }
  OPTIONAL { ?item gdt:P32 ?partner }
}
```
- Zobrazit jako genealogický strom (typu https://www.geni.com/family-tree). Jednotlivé boxy mají obsahovat labelCs, narkdy (datum narození) a zemkdy (datum úmrtí). Po kliknutí na box se v postraním panelu zobrazí detailní informace k osobě (všechny položky uložené v entitě).

# Instrukce
- SPARQL lze optimalizovat, doplňovat.