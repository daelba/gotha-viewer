#!/bin/bash
# Smoke testy pro Gotha viewer
# Usage: bash tests/smoke/smoke.sh [BASE_URL]
# Bez parametru se BASE_URL dopočítá z .env v kořeni projektu:
#   BASE = APP_DOMAIN + APP_PATH   (APP_PATH včetně /public)
# Fallback: http://127.0.0.1:8080 (vestavěný PHP server).
#
# Pozn.: RESTful URL jsou implementovány v index.php routeru, takže fungují
# i s vestavěným PHP serverem (php -S -t public), i bez .htaccess.

set -u

ENV_FILE="$(cd "$(dirname "$0")/../.." && pwd)/.env"

if [ -n "${1:-}" ]; then
    BASE="$1"
elif [ -r "$ENV_FILE" ]; then
    APP_DOMAIN=$(grep -E '^APP_DOMAIN=' "$ENV_FILE" | cut -d= -f2- | tr -d '[:space:]')
    APP_PATH=$(grep -E '^APP_PATH=' "$ENV_FILE" | cut -d= -f2- | sed 's/[[:space:]]*$//')
    BASE="${APP_DOMAIN}${APP_PATH}"
    if [ "$BASE" = "" ]; then
        BASE="http://127.0.0.1:8080"
    fi
    echo "(BASE URL načtena z .env: $BASE)"
else
    BASE="http://127.0.0.1:8080"
fi
FAIL=0

check() {
    local desc="$1" url="$2" expect="${3:-200}"
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$url")
    if [ "$code" = "$expect" ]; then
        echo "  OK   [$code] $desc"
    else
        echo "  FAIL [$code ≠ $expect] $desc → $url"
        FAIL=1
    fi
}

check_grep() {
    local desc="$1" url="$2" needle="$3"
    if curl -s --max-time 40 "$url" | grep -q "$needle"; then
        echo "  OK   $desc"
    else
        echo "  FAIL ${desc} (nenalezeno '${needle}') → ${url}"
        FAIL=1
    fi
}

echo "== Gotha viewer smoke testy @ $BASE =="
echo "POZOR: data tečou přes gotha-api; při nezatížené cache mohou některé requesty trvat déle."

check "Úvodní stránka vrací 200" "$BASE/"
check_grep "Úvodní stránka obsahuje tabulku rodů" "$BASE/" "families-table"

check "Přepnutí jazyka (de) vrací 200" "$BASE/?lang=de"

# Referenční rod pro testy: Q135 (Fuggerové z Babenhausenu) — má členy se vazbami,
# takže ověří jak 200, tak obsah stromu.
FAMILY_QID="Q135"
check "Detail rodu ($FAMILY_QID) vrací 200" "$BASE/family/$FAMILY_QID"
check_grep "Detail rodu obsahuje strom nebo hlášku" "$BASE/family/$FAMILY_QID" "tree-canvas\|family.no_members\|no-members"

# API osoby – první node z detailu rodu
PERSON_QID=$(curl -s --max-time 40 "$BASE/family/$FAMILY_QID" | grep -oP 'data-id="(Q[0-9]+)"' | head -1 | grep -oP 'Q[0-9]+')
if [ -n "$PERSON_QID" ]; then
    check "API osoby ($PERSON_QID) vrací 200" "$BASE/api/person/$PERSON_QID"
    check_grep "API osoby vrací jméno" "$BASE/api/person/$PERSON_QID" '"name"'
else
    echo "  SKIP API osoby (rod $FAMILY_QID nemá členy)"
fi

check "API s neplatným ID vrací 400" "$BASE/api/person/XXX" 400
check "Neexistující rod vrací 404" "$BASE/family/Q9999999999" 404

check "CSS je dostupné" "$BASE/css/style.css"
check "JS je dostupné" "$BASE/js/app.js"
check "Ikona Wikidata je dostupná" "$BASE/icons/wikidata.svg"

# Statické TreeBuilder testy (regrese na konkrétních rodinách)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
for t in "$SCRIPT_DIR"/tree_*.php; do
    [ -f "$t" ] || continue
    name=$(basename "$t")
    if php "$t" > /dev/null 2>&1; then
        echo "  OK   $name"
    else
        echo "  FAIL $name"
        FAIL=1
    fi
done

echo
if [ "$FAIL" = 0 ]; then
    echo "VŠECHNY SMOKE TESTY PROŠLY ✔"
    exit 0
else
    echo "NĚKTERÉ TESTY SELHALY ✘"
    exit 1
fi
