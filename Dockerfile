FROM php:8.3-apache

ENV TZ=Europe/Vienna \
    DB_PATH=/var/www/html/data/trainingsplan.db

# pdo_sqlite fuer die Datenbank, gd fuer die Bildverarbeitung (§3 Lastenheft).
#
# libsqlite3-dev ist zwingend und wird leicht uebersehen: Das Basis-Image
# bringt zwar eine SQLite-Bibliothek zur Laufzeit mit, aber nicht die Header
# und die pkg-config-Datei. Ohne sie bricht docker-php-ext-install mit
# "Package 'sqlite3', required by 'virtual:world', not found" ab.
#
# remoteip ist Pflicht hinter dem Reverse-Proxy: ohne das Modul steht in
# REMOTE_ADDR die IP des Nginx, und die Brute-Force-Bremse (§5) wuerde alle
# Benutzer gemeinsam aussperren statt einzelner Angreifer.
# zip wird fuer die Wartungsseite gebraucht (§6.5): Ein vollstaendiges Backup
# buendelt Datenbank und Bilder in einer Datei. Ohne die Erweiterung gaebe es
# nur die nackte .db -- die Uebungsbilder blieben ungesichert.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libsqlite3-dev libpng-dev libjpeg-dev libwebp-dev libzip-dev tzdata \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_sqlite gd zip \
 && a2enmod rewrite headers remoteip \
 && rm -rf /var/lib/apt/lists/*

# memory_limit 256M wegen GD: Ein 25-Megapixel-Bild belegt beim Dekodieren
# rund 100 MB, unabhaengig von der Dateigroesse. lib/upload.php weist alles
# darueber vorher mit einer verstaendlichen Meldung ab.
RUN printf 'date.timezone = %s\nexpose_php = Off\nupload_max_filesize = 8M\npost_max_size = 10M\nmemory_limit = 256M\n' "$TZ" \
      > /usr/local/etc/php/conf.d/app.ini

COPY apache-app.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app

COPY . /var/www/html

# Die beiden Verzeichnisse werden zur Laufzeit von Named Volumes ueberlagert (§3).
# Dieser chown ist trotzdem entscheidend und keine Formsache: Docker befuellt ein
# frisch angelegtes Volume aus dem Image und uebernimmt dabei Eigentuemer und
# Rechte. Faellt der chown weg, gehoert das Volume root und der Apache kann
# weder die Datenbank anlegen noch Bilder speichern.
RUN mkdir -p /var/www/html/data /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html

VOLUME ["/var/www/html/data", "/var/www/html/uploads"]

# Wird in Portainer als "healthy"/"unhealthy" angezeigt.
#
# Geprueft wird health.php und nicht die Startseite: Die leitet ohne jeden
# Datenbankzugriff auf login.php um, ein nicht beschreibbares Volume bliebe
# damit unentdeckt. health.php fasst die Datenbank an und ist nur ueber das
# Loopback erreichbar.
#
# Die Pruefung steckt in einem eigenen Skript, nicht in einem php -r-Einzeiler:
# Der laesst sich zur Fehlersuche nicht aufrufen und sagt nie, WAS nicht
# stimmt. lib/healthcheck.php gibt eine Begruendung aus, die in
# "docker inspect" bzw. in Portainer sichtbar wird.
# Zeitlimits bewusst grosszuegig: Beim ersten Rollout stand der Container
# minutenlang auf "unhealthy", weil der LXC ausgelastet war und die Pruefung
# die damaligen 10 s ueberschritt -- die App selbst antwortete die ganze Zeit
# mit 200. Ein Healthcheck, der bei Last falschen Alarm schlaegt, ist
# schlimmer als keiner: Man gewoehnt sich an, ihn zu ignorieren.
HEALTHCHECK --interval=1m --timeout=30s --start-period=60s --retries=3 \
    CMD ["php", "/var/www/html/lib/healthcheck.php"]
