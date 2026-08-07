#!/usr/bin/env bash
# Schnuert das Paket, das in Portainer zum Bauen des Images hochgeladen wird.
#
# Aufruf aus dem Projektverzeichnis:
#     bash deploy/paket_bauen.sh
#
# Ergebnis: deploy/trainingsplan-build.tar.gz
#
# Eingepackt wird eine POSITIVLISTE, keine Ausschlussliste. Das ist Absicht:
# Eine Ausschlussliste vergisst man irgendwann zu pflegen, und dann liegt die
# .env mit APP_SECRET und ADMIN_PASSWORD im Paket -- oder die Datenbank mit
# allen Passwort-Hashes. Was neu dazukommt, gehoert bewusst in PFADE ergaenzt.

set -euo pipefail

PROJEKT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIEL="$PROJEKT/deploy/trainingsplan-build.tar.gz"

cd "$PROJEKT"

# --- Was ins Image gehoert ---------------------------------------------------
# Verzeichnisse und Einzeldateien; die Seiten im Wurzelverzeichnis kommen
# ueber die Glob-Zeilen weiter unten dazu.
PFADE=(
    Dockerfile          # Bauanleitung
    .dockerignore       # haelt Dockerfile und apache-app.conf aus dem Webroot
    apache-app.conf     # wird darin nach conf-available kopiert
    schema.sql          # wird bei jedem Start ausgefuehrt
    api                 # JSON-Endpunkte
    lib                 # Bausteine (enthaelt seine eigene .htaccess)
    assets              # CSS, JS, Service Worker, Manifest, Icons
    data/.htaccess      # Sperre; wandert beim ersten Start ins Volume
)

# Seiten und ihr gleichnamiges JS im Wurzelverzeichnis.
shopt -s nullglob
PFADE+=(*.php *.js)
shopt -u nullglob

# --- Vollstaendigkeit pruefen ------------------------------------------------
for pfad in "${PFADE[@]}"; do
    if [[ ! -e "$pfad" ]]; then
        echo "FEHLER: $pfad fehlt - wird das Skript im Projektverzeichnis ausgefuehrt?" >&2
        exit 1
    fi
done

# Ohne diese drei startet der Container nicht sinnvoll.
for pflicht in index.php login.php schema.sql; do
    if [[ ! -f "$pflicht" ]]; then
        echo "FEHLER: $pflicht fehlt im Paket." >&2
        exit 1
    fi
done

# --- Syntax pruefen ----------------------------------------------------------
# Ein Tippfehler faellt sonst erst im laufenden Container auf, und dort ist die
# Fehlersuche deutlich unangenehmer als hier.
echo "Pruefe Syntax ..."
fehler=0
while IFS= read -r datei; do
    php -l "$datei" > /dev/null 2>&1 || { echo "  PHP-Fehler in $datei" >&2; fehler=1; }
done < <(find api lib . -maxdepth 1 -name '*.php' 2>/dev/null; find api lib -name '*.php')

if command -v node > /dev/null 2>&1; then
    while IFS= read -r datei; do
        node --check "$datei" > /dev/null 2>&1 || { echo "  JS-Fehler in $datei" >&2; fehler=1; }
    done < <(find . -maxdepth 1 -name '*.js'; find assets -name '*.js')
else
    echo "  Hinweis: node fehlt, JavaScript ungeprueft."
fi

if [[ $fehler -ne 0 ]]; then
    echo "FEHLER: Syntaxfehler gefunden - Paket nicht gebaut." >&2
    exit 1
fi
echo "  ok"

# --- Packen ------------------------------------------------------------------
rm -f "$ZIEL"
tar --create --gzip --file "$ZIEL" \
    --exclude='*.db' --exclude='*.db-wal' --exclude='*.db-shm' \
    --exclude='.env' \
    "${PFADE[@]}"

echo
echo "Paket gebaut: ${ZIEL#"$PROJEKT/"} ($(du -h "$ZIEL" | cut -f1))"
echo
echo "Inhalt:"
tar --list --file "$ZIEL" | sed 's/^/  /'

# --- Letzte Sicherung --------------------------------------------------------
# Taucht hier etwas auf, ist beim Pflegen der Positivliste etwas grob
# schiefgelaufen. Lieber abbrechen als Zugangsdaten hochladen.
echo
if tar --list --file "$ZIEL" | grep -qE '(^|/)\.env|\.db($|-)|(^|/)\.git|(^|/)uploads/'; then
    echo "ACHTUNG: Das Paket enthaelt Zugangsdaten, eine Datenbank oder Uploads." >&2
    echo "NICHT hochladen. Positivliste in diesem Skript pruefen." >&2
    rm -f "$ZIEL"
    exit 1
fi
echo "Geprueft: keine .env, keine Datenbank, keine Uploads, kein .git enthalten."
echo
echo "Naechster Schritt in Portainer (Environment 10.10.10.2):"
echo "  Images -> Build a new image -> Upload -> ${ZIEL##*/}"
echo "  Name z. B. trainingsplan:1.0.0, danach in deploy/stack.yml eintragen."
