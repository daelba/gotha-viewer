# Deployment

## Docker na VM1 (produkce, gotha-view.daelba.eu)

Produkce běží jako compose projekt `gotha-viewer` na hub VM1
(`~/docker/gotha-viewer`). Veřejný vstup řeší centrální `reverse-proxy/`
z infra repa (šablona `reverse-proxy/deploy/nginx/templates/gotha-view.daelba.eu.conf.template`,
sdílená síť `proxy-net`, TLS přes `make certbot-init` tamtéž).

```bash
git pull
make up          # build image + start (idempotentní)
make logs        # logy Apache/PHP
make smoke       # smoke testy proti https://gotha-view.daelba.eu (APP_DOMAIN/APP_PATH v .env)
```

Image kopíruje `public/ src/ templates/` (výjimky v `.dockerignore`),
DocumentRoot je `public/` a RESTful routing řeší `public/.htaccess`
(`AllowOverride All` v `deploy/apache/gotha-viewer.conf`). Cache API odpovědí
žije v named volume `gotha-viewer-cache`, žádná `.env` není potřeba
(výchozí `APP_PATH=''`, `API_BASE` default z `src/ApiClient.php`).

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

Viewer se připojuje na **gotha-api** (cachující proxy nad gotha.wikibase.cloud).
Výchozí URL je vestavěná v `src/ApiClient.php`; v `.env` lze přepsat klíčem
`API_BASE=https://tools.daelba.eu/gotha/api/v1` (bez koncového lomítka).
Slovník je v `src/I18n.php`. SPARQL dotazy viewer neskládá – nic dalšího se nemění.

## Kontrola po nasazení

1. Otevřít `/` – měla by se načíst tabulka rodů.
2. Kliknout na libovolný rod – genealogický strom, hledání funguje.
3. Kliknout na osobu – postraní panel s detaily (data z gotha-api).
4. Výběr jazyka (vlaječky) – stránka se překreslí.
5. „Aktualizovat data" – pošle `?force=1` do gotha-api a znovu načte data.

## Smoke testy

```bash
# rychlý test dostupnosti (z rootu projektu)
bash tests/smoke/smoke.sh http://localhost/gotha-viewer/public
```
