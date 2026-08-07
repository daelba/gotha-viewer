# TODO: Přidání externích odkazů do panelu osoby

## Cíl
V personal-panel vytvořit vedle odkazu na Wikibase (wb-link) také odkaz na Wikidata a odkaz na Entitree.

## Kroky
- [x] Analyzovat kód (app.js, ApiController.php, style.css)
- [x] Upravit ApiController.php (přidat wikidataId do JSON)
- [x] Upravit app.js (přidat odkazy s ikonami vedle wb-link)
- [x] Upravit style.css (styly pro .person-links a .ext-link)
- [x] Ověřit funkcionalitu (smoke testy + API test s force)
