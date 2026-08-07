# Deployment

Konkrete Werte der Installation. Das *Warum* steht in `LASTENHEFT.md` §3 und §5,
hier steht das *Wie*.

## Topologie

```
Internet
   │  HTTPS 443, Let's Encrypt
   ▼
Proxmox-Host ── Nginx (Reverse-Proxy, TLS-Terminierung)
   │  HTTP, internes Netz 10.10.10.0/24
   ▼
LXC 10.10.10.2 ── Docker (verwaltet über Portainer)
                     └── Container "trainingsplan" (Apache + PHP 8.3)
                           ├── /var/www/html/data     → Volume trainingsplan-data
                           └── /var/www/html/uploads  → Volume trainingsplan-uploads
```

| | |
|---|---|
| Subdomain | `training.jadefalke.net` |
| Zertifikat | Let's Encrypt / Certbot, auf dem Proxmox-Host |
| Ziel des `proxy_pass` | `http://10.10.10.2:8066` |
| LXC | `10.10.10.2` (Proxmox-Internetz, keine Route von aussen) |
| Docker-Port-Binding | `10.10.10.2:8066` → Container-Port 80 |
| Verwaltung | Portainer, Environment des LXC `10.10.10.2` |
| Persistenz | Named Volumes `trainingsplan-data`, `trainingsplan-uploads` |

Auf demselben LXC laufen bereits andere Projekte (u. a. `solarwatch`). Das
Vorgehen hier ist bewusst dasselbe, damit sich nicht jeder Container anders
bedienen lässt.

**Zum Port-Binding:** `LASTENHEFT.md` §3 verlangte in der Erstfassung
`127.0.0.1`. Das gilt hier nicht — der Nginx liegt auf dem Proxmox-Host, ein an
den Loopback des LXC gebundener Port wäre für ihn unerreichbar. Die
Schutzwirkung bleibt trotzdem erhalten, denn sie hängt nicht am Loopback,
sondern daran, dass **niemand ausser dem Proxy den Port erreicht**:
`10.10.10.0/24` ist ein reines Proxmox-Internetz ohne NAT oder Port-Forwarding
von aussen. Genau deshalb darf `X-Forwarded-Proto` vertraut werden.

Nicht auf `0.0.0.0` binden. Sobald der LXC ein zweites, öffentliches Interface
bekäme, wäre die App unter Umgehung des Proxy erreichbar — und damit jeder
`X-Forwarded-*`-Header fälschbar.

## Persistenz: nichts anzulegen

Datenbank und Bilder liegen in **Named Volumes**, nicht in Host-Verzeichnissen.
Auf der LXC-Shell ist dafür nichts vorzubereiten — kein `mkdir`, kein `chown`.

Der Grund ist die Rechtevergabe: Der Apache im Container läuft als `www-data`
(UID 33). Docker befüllt ein **neu angelegtes** Named Volume aus dem Image und
übernimmt dabei Eigentümer und Rechte; da das `Dockerfile` beide Verzeichnisse
auf `www-data` chownt, gehören auch die Volumes ihm. Ein Bind-Mount auf ein
Host-Verzeichnis entstünde dagegen als `root:root` — die App könnte die
SQLite-Datei nicht anlegen und keine Bilder speichern.

Wichtig dabei: Diese Übernahme passiert **nur beim ersten Befüllen** eines
leeren Volumes. Ein bereits vorhandenes Volume rührt Docker nicht mehr an; ein
späterer `chown` im `Dockerfile` erreicht es also nicht mehr.

In Portainer sind die Volumes unter *Volumes* sichtbar (mit dem Stack-Namen als
Präfix). Auf der LXC-Shell liegen sie unter
`/var/lib/docker/volumes/<stack>_trainingsplan-data/_data`; nachsehen mit
`docker volume ls` und `docker volume inspect <name>`.

> **Das einzige Szenario, in dem die Daten verloren gehen:** Ein Image-Update
> oder ein *Recreate* fasst die Volumes nicht an — wohl aber das **Löschen des
> Stacks mit angehakter Volume-Option** in Portainer bzw. `docker compose down -v`
> auf der Shell. Vor beidem ein Backup über `maintenance.php` ziehen.

## Ausrollen über Portainer

Wie bei solarwatch in zwei Schritten, weil Portainer den Quelltext nicht sieht
und das Image deshalb nicht selbst aus dem Repo bauen kann.

1. **Paket schnüren** (auf dem Entwicklungsrechner, im Projektverzeichnis):
   `bash deploy/paket_bauen.sh` → `deploy/trainingsplan-build.tar.gz`
2. **Image bauen:** Portainer → Environment `10.10.10.2` → *Images* → *Build a
   new image* → Tarball hochladen → Name `trainingsplan:1.0.0`
3. **Stack anlegen:** Portainer → *Stacks* → *Add stack* → *Web editor* →
   Inhalt von `deploy/stack.yml` einfügen
4. **Umgebungsvariablen** darunter im Feld *Environment variables* eintragen,
   Vorlage in `deploy/env-vorlage.txt`. `APP_SECRET` und `ADMIN_PASSWORD`
   gehören **dorthin**, nicht in die Stack-Definition.
5. *Deploy the stack*

Danach `https://training.jadefalke.net/` aufrufen und mit `ADMIN_USER` /
`ADMIN_PASSWORD` anmelden — die App erzwingt sofort einen Passwortwechsel
(`must_change_password = 1`, §3). Das Klartext-Passwort aus den
Stack-Variablen ist ab diesem Moment wertlos und wird nicht mehr ausgewertet.

**Update:** neues Image mit erhöhter Versionsnummer bauen (`trainingsplan:1.1.0`),
im Stack die Zeile `image:` anpassen, *Update the stack*. Die Volumes bleiben
unberührt. Feste Versionsnummern statt `:latest`, damit ein Rückschritt
möglich bleibt.

`schema.sql` besteht ausschliesslich aus `CREATE TABLE IF NOT EXISTS` /
`CREATE INDEX IF NOT EXISTS` und läuft bei jedem Start durch; neue Spalten
kommen als idempotenter `PRAGMA table_info`-Block in `init_schema()` dazu. Es
gibt kein Migrationstool und kein Migrationsverzeichnis.

### Alternative: direkt auf der LXC-Shell

Für Tests ohne Portainer liegt im Projektwurzelverzeichnis eine
`docker-compose.yml` mit `build: .`:

```bash
cp .env.example .env
openssl rand -hex 32        # Ausgabe als APP_SECRET eintragen
docker compose up -d --build
curl -sI http://10.10.10.2:8066/ | head -n 1
```

## Nginx auf dem Proxmox-Host

`doku/nginx-vhost.conf` ist eine **wortgetreue Kopie** der aktiven
Konfiguration, keine Vorlage zum Ausrollen. Sie wird nachgezogen, wenn sich der
Server ändert — nicht umgekehrt.

Zwei Punkte fielen beim Lesen auf. Beides ist **optional**, die laufende
Konfiguration funktioniert:

1. `client_max_body_size` steht auf `50000M`. Das grösste legitime Upload ist ein
   Übungsbild mit 5 MB Limit; `16m` genügt und begrenzt den Schaden eines
   überlangen Request-Bodys.
2. `Upgrade`/`Connection: upgrade` werden unbedingt gesetzt. Die App nutzt keine
   Websockets; `proxy_set_header Connection "";` erhält stattdessen Keep-Alive
   zum Upstream.

Wird eine der beiden Änderungen vorgenommen, gehört sie in
`doku/nginx-vhost.conf` nachgetragen, damit Kopie und Server übereinstimmen.
Nach einer Änderung:

```bash
nginx -t && systemctl reload nginx
```

## Backup und Datentransfer

**Niemals die laufende `.db` aus dem Volume kopieren.** Die Datenbank läuft im
WAL-Modus; eine Dateikopie ohne `-wal`/`-shm` ist im besten Fall veraltet, im
schlechteren inkonsistent. Das gilt auch für Portainers Datei-Browser und für
Volume-Backup-Werkzeuge, die den Container nicht anhalten.

Der einzige unterstützte Weg ist die Backup-/Restore-Funktion in
`maintenance.php` (`download_backup.php` erzeugt eine konsistente Kopie über
`VACUUM INTO`). Das gilt in beide Richtungen — auch für Test → Live.

> **Achtung:** Ein Restore überschreibt den kompletten Datenbestand der
> Zieldatenbank. Test- und Live-Datenbank divergieren; vor jedem Restore prüfen,
> welche der beiden gerade das Ziel ist.

Die Bilder im Volume `trainingsplan-uploads` sind normale Dateien und lassen
sich regulär sichern.

## Fehlersuche

| Symptom | Wahrscheinliche Ursache |
|---|---|
| Redirect-Schleife nach dem Login | `X-Forwarded-Proto` kommt nicht an, oder die App prüft `$_SERVER['HTTPS']` statt des Headers (§5) |
| Login klappt, nächste Seite ist wieder abgemeldet | Session-Cookie ohne `Secure` gesetzt oder Domain-Mismatch |
| Alle Benutzer gleichzeitig durch die Brute-Force-Bremse gesperrt | `mod_remoteip` inaktiv oder `RemoteIPTrustedProxy` passt nicht — dann steht in `REMOTE_ADDR` für alle die Nginx-IP |
| 502 vom Nginx | Container down oder Bindung nicht auf `10.10.10.2` (`docker compose ps`, `ss -tlnp \| grep 8066`) |
| „unable to open database file" / Uploads schlagen fehl | Volume gehört `root` statt UID 33 — passiert bei Bind-Mounts oder bei einem Volume, das schon vor dem `chown` im Image existierte. Volume löschen und neu anlegen lassen (Datenverlust!) oder `chown` im laufenden Container nachziehen |
| Daten nach dem Update verschwunden | Stack ohne Volume-Definition neu angelegt, oder Volume-Name geändert |
| Alte CSS/JS-Stände am Handy | Service Worker; er darf nur Assets cachen, niemals HTML oder API-Antworten (§2) |
