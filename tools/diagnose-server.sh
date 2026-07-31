#!/bin/bash
# Diagnostika Gotha viewer na produkčním serveru (pouze čtení, nic nemění)
# Spusťte jako: sudo bash tools/diagnose-server.sh   (nebo root)

echo "==================================================="
echo " GOTHviewer – diagnostika Apache / AllowOverride"
echo " (pouze čtení – žádné změny konfigurace)"
echo "==================================================="
echo

echo "### 1) Je načten mod_rewrite?"
if apachectl -M 2>/dev/null | grep -q rewrite_module; then
    echo "  -> rewrite_module: NAČTEN"
else
    echo "  -> rewrite_module: NENALEZEN v apachectl -M  (zkontrolujte!)"
fi
echo

echo "### 2) AllowOverride pro /var/www..."
# Najdi všechny <Directory> bloky, které se týkají /var/www a ukazuj AllowOverride
grep -RniE '<Directory|AllowOverride' /etc/apache2/ 2>/dev/null | grep -i 'www\|allowoverride' | head -40
echo

echo "### 3) Konkrétní nova virtualhost / includy pro daelba.eu"
grep -RniE 'ServerName|DocumentRoot|AllowOverride|<Directory' /etc/apache2/sites-enabled/ 2>/dev/null | head -40
echo

echo "### 4) Existuje a je čitelný .htaccess v public/?"
ls -l /var/www/html/gotha-viewer/public/.htaccess 2>&1
echo "--- obsah (prvních 25 řádků) ---"
head -25 /var/www/html/gotha-viewer/public/.htaccess 2>&1
echo

echo "### 5) Test čtení .htaccess: zkusíme zapnout rewrite a podívat se do logu"
# Vytvoříme dočasný .htaccess s viditelným markerem (bezpečné, nic nerozbije)
echo "  -> Návrh: pokud chcete ověřit, že se .htaccess vůbec čte, zkuste do něj"
echo "             dočasně vložit neplatný řádek 'FooBarBaz 1' – mělo by se objevit 500."
echo "             (Pak ho ihned smažte.)"
echo
echo "### 6) Poslední chybové záznamy Apache (pomůže při 404/500)"
tail -5 /var/log/apache2/error.log 2>/dev/null || tail -5 /var/log/httpd/error_log 2>/dev/null || echo "  (nelze najít error log)"
echo

echo "==================================================="
echo " Hotovo. Zkopírujte výstup a pošlete ho mi."
echo "==================================================="
