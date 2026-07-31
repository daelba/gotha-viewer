# Deployment

## Lokální vývoj

```bash
# nejrychlejší: vestavěný PHP server (pro testování)
# POZOR: vestavěný server nezpracovává .htaccess → RESTful URL nefungují
# pro lokální test doporučuji Apache nebo Nginx+PHP-FPM

# Apache
cp -r gotha-viewer /var/www/html/gotha-viewer
cd /var/www/html/gotha-viewer
# povolit .htaccess – v /etc/apache2/apache2.conf nebo vhostu:
#     <Directory /var/www/html/gotha-viewer/public>
#         AllowOverride All
#         Require all granted
#     </Directory>
# a zapnout mod_rewrite:
#     sudp a2enmod rewrite headers
#     sudo systemctl restart apache2

# přístup
# http://localhost/gotha-viewer/public/      (seznam rodů)
# http://localhost/gotha-viewer/public/family/Q13   (detail rodu)
```

## Produkční nasazení

```bash
# 1. Naklonovat nebo nakopírovat repozitář na server
git clone git@github.com:daelba/gotha-viewer.git
cd gotha-viewer

# 2. Nastavit DocumentRoot na public/
# nebo vložit soubory z public/ do webového rootu

# 3. Práva pro cache
mkdir -p cache
chmod 777 cache          # nebo chown www-data:www-data cache
# cache adresář musí být zapisovatelný webserverem

# 4. Apache vhost (doporučený)
<VirtualHost *:80>
    ServerName gotha.example.com
    DocumentRoot /var/www/gotha-viewer/public
    <Directory /var/www/gotha-viewer/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
</VirtualHost>

# 5. HTTPS (doporučeno přes Certbot / Let's Encrypt)
#    sudp certbot --apache -d gotha.example.com
```

## Konfigurace

Celá konfigurace je v `src/SparqlClient.php` (URL endpointu) a `src/I18n.php` (slovník).
Pro produkční nasazení není nic jiného potřeba měnit.

## Kontrola po nasazení

1. Otevřít `/` – měla by se načíst tabulka rodů.
2. Kliknout na libovolný rod – genealogický strom, hledání funguje.
3. Kliknout na osobu – postraní panel s detaily.
4. Výběr jazyka (vlaječky) – stránka se překreslí.
5. „Aktualizovat data" – vymaže cache, refresh.

## Smoke testy

```bash
# rychlý test dostupnosti (z rootu projektu)
bash tests/smoke/smoke.sh http://localhost/gotha-viewer/public
```
