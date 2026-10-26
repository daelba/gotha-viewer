# Gotha viewer – PHP/Apache image. DocumentRoot = public/, RESTful routing
# řeší public/.htaccess přes mod_rewrite. Aplikace se kopíruje do image
# (výjimky viz .dockerignore); cache API odpovědí žije v named volume
# gotha-viewer-cache (docker-compose.yml).
FROM php:8.3-apache

# mbstring: šablony používají mb_strtolower() (fulltext v tabulce/stromu);
# potřebuje systémovou onigurumu (libonig5 necháváme pro runtime)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring \
    && apt-mark manual libonig5 \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/*
# mod_rewrite = RESTful URL z .htaccess, mod_headers = skrytí X-Powered-By
RUN a2enmod rewrite headers
# Debianí Apache nese default "Alias /icons/ /usr/share/apache2/icons/"
# (mods-enabled/alias.conf), který by zastínil public/icons aplikace → vypnout
RUN rm -f /etc/apache2/mods-enabled/alias.conf

COPY deploy/apache/gotha-viewer.conf /etc/apache2/sites-available/gotha-viewer.conf
RUN a2dissite 000-default && a2ensite gotha-viewer

COPY public/ /var/www/html/public/
COPY src/ /var/www/html/src/
COPY templates/ /var/www/html/templates/
# cache/ musí být zapisovatelná webserverem (při prvním mountu named volume
# se vlastník převezme do volume)
RUN install -d -o www-data -g www-data /var/www/html/cache
