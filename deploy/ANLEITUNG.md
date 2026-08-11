# Trainingsplan in Portainer einrichten

Diese Anleitung bringt die App als Container auf den LXC `10.10.10.2`. **Alles
Nötige liegt in diesem Ordner** — es muss vorher nichts gebaut, kompiliert oder
installiert werden. Rechnen Sie mit etwa 15 Minuten.

**Was hier drin liegt**

| Datei | Wozu |
|---|---|
| `trainingsplan-build-<version>.tar.gz` | Das fertige Paket, aus dem Portainer das Image baut (Schritt 2). Die Nummer aus `VERSION` steht im Dateinamen |
| `stack.yml` | Die Stack-Definition zum Einfügen in Portainer (Schritt 3) |
| `env-vorlage.txt` | Die Umgebungsvariablen zum Eintragen (Schritt 4) |
| `paket_bauen.sh` | Baut das `.tar.gz` neu — **nur nötig, wenn sich der Code geändert hat** |

> **Muss ich `paket_bauen.sh` ausführen?**
> Für die Ersteinrichtung **nein**. Das Paket ist bereits gebaut und liegt hier.
> Das Skript brauchen Sie erst, wenn am Code etwas geändert wurde und ein neues
> Image entstehen soll (siehe *Updates* am Ende).

> **Warum überhaupt ein Paket?** Portainer läuft auf dem Server und sieht das
> Projektverzeichnis auf Ihrem Rechner nicht. Zum Bauen eines Images will es den
> Quelltext als **eine hochladbare Datei** — das ist dieses `.tar.gz`. Deshalb
> steht in `stack.yml` auch kein `build:`, sondern nur ein Verweis auf das
> fertige Image.

---

## Schritt 1 — Vorbereitung

Sie brauchen:

- Zugang zur Portainer-Oberfläche, Environment **`10.10.10.2`**
- Ein **`APP_SECRET`** (wird gleich erzeugt)
- Ein **Startpasswort** für den ersten Administrator

**Auf dem LXC selbst ist nichts vorzubereiten** — keine Verzeichnisse, keine
Rechte. Datenbank und Bilder landen in Named Volumes, die Docker beim ersten
Start selbst anlegt und dabei korrekt dem Webserver-Benutzer zuordnet.

**Der Nginx ist bereits eingerichtet.** `training.jadefalke.net` zeigt auf
`http://10.10.10.2:8066`, das Zertifikat läuft. Daran ist nichts zu tun.

### APP_SECRET erzeugen

Auf einer beliebigen Shell:

```bash
openssl rand -hex 32
```

Die ausgegebenen 64 Zeichen notieren. Das Secret schützt ausschließlich die
„Angemeldet bleiben"-Tokens — **nicht** die Passwörter. Geht es später verloren,
müssen sich alle Geräte einmal neu anmelden, mehr passiert nicht.

> ### Zum Passwort: `$` möglichst vermeiden
>
> **Am einfachsten ist ein Startpasswort ganz ohne `$`-Zeichen.** Docker Compose
> liest ein `$` als Beginn einer Variablen und schneidet den Rest weg — im
> Container kommt dann etwas anderes an, und die Anmeldung scheitert, obwohl das
> Passwort nachweislich stimmt.
>
> Wenn Sie ein `$` behalten wollen: In Portainer muss es **verdoppelt**
> geschrieben werden (`$$`).
>
> Das Startpasswort ist ohnehin nur für den allerersten Login gedacht — die App
> verlangt sofort danach ein eigenes. Ein einfaches genügt völlig.

---

## Schritt 2 — Image bauen

1. In Portainer oben rechts das Environment **`10.10.10.2`** auswählen.
2. Links auf **Images**.
3. Oben auf **Build a new image**.
4. **Naming**: den Namen aus der `image:`-Zeile von `stack.yml` eintragen,
   derzeit **`trainingsplan:1.1.4`**.
   Genau diese Schreibweise — die Stack-Datei sucht nach diesem Namen.
   `paket_bauen.sh` hat ihn beim Packen als letzte Zeile ausgegeben und vorher
   geprüft, dass er zur Datei `VERSION` passt; abtippen genügt.
5. **Build method**: **Upload** wählen.
6. **Select file**: die Datei `trainingsplan-build-<version>.tar.gz` aus diesem
   Ordner hochladen. Es liegt immer genau **eine** davon da — `paket_bauen.sh`
   räumt ältere weg —, und die Nummer im Dateinamen ist dieselbe, die Sie eben
   in Schritt 4 eingetippt haben.
7. **Dockerfile path**: `Dockerfile` (Standard, unverändert lassen).
8. Auf **Build the image** klicken.

Der Bau dauert ein bis zwei Minuten — Portainer zeigt die Ausgabe live. Am Ende
muss dort `Successfully tagged trainingsplan:<Ihre Nummer>` stehen.

> Schlägt der Bau fehl, liegt es fast immer am Netz des Servers: Das Image lädt
> Debian-Pakete für die Bildverarbeitung nach. Die letzte Zeile der Ausgabe sagt,
> woran es lag.

---

## Schritt 3 — Stack anlegen

1. Links auf **Stacks**.
2. Oben auf **Add stack**.
3. **Name**: `trainingsplan`
4. **Build method**: **Web editor** (Standard).
5. Den **gesamten Inhalt von `stack.yml`** in das große Textfeld kopieren.

Noch **nicht** deployen — erst die Variablen in Schritt 4 eintragen.

---

## Schritt 4 — Umgebungsvariablen eintragen

Unterhalb des Editors gibt es den Bereich **Environment variables**.

Dort diese vier Paare eintragen (über *Add an environment variable*, oder über
*Advanced mode* als Block einfügen):

```
APP_SECRET=<die 64 Zeichen aus Schritt 1>
ADMIN_USER=oli
ADMIN_PASSWORD=<Ihr Startpasswort>
TZ=Europe/Vienna
```

**Warum hier und nicht in der Stack-Datei:** Die Stack-Definition wird in
Portainer gespeichert und ist für jeden sichtbar, der die Oberfläche öffnet. Die
Variablen liegen getrennt davon.

Jetzt auf **Deploy the stack**.

---

## Schritt 5 — Prüfen

Nach etwa einer halben Minute sollte unter **Containers** der Container
`trainingsplan` als **running** und kurz darauf zusätzlich als **healthy**
erscheinen.

> Bleibt er auf *starting* oder wird *unhealthy*: Das ist meistens das
> Datenverzeichnis. Der Healthcheck fasst die Datenbank wirklich an — er meldet
> also auch dann einen Fehler, wenn die Seite selbst noch lädt. Die Ursache steht
> im Container-Log (**Containers → trainingsplan → Logs**).

Dann im Browser:

1. **https://training.jadefalke.net/** aufrufen → die Anmeldeseite erscheint.
2. Mit `ADMIN_USER` und `ADMIN_PASSWORD` anmelden.
3. Die App leitet **sofort** auf den Passwortwechsel — das ist so gewollt. Das
   Startpasswort steht in Portainer im Klartext und ist damit kein Geheimnis.
   Nach dem Wechsel wird die Variable nie wieder ausgewertet.
4. Ein eigenes Passwort setzen (mindestens 8 Zeichen).

Danach steht die Verwaltung offen. Sinnvolle Reihenfolge beim Einrichten:

**Muskelgruppen** (acht sind bereits angelegt) → **Übungen** mit Bild →
**Benutzer** für die zweite Person → **Pläne** je Benutzer.

---

## Was zuerst ausprobiert werden sollte

Diese drei Dinge ließen sich vorab nicht testen, weil sie echte Hardware
brauchen:

| Was | Wie |
|---|---|
| **Bild-Upload** | Eine Übung mit Foto anlegen. Das Bild wird serverseitig neu gezeichnet und verkleinert — es darf im Anschluss in der Liste erscheinen. |
| **PWA-Installation** | Am Handy die Seite aufrufen → *Zum Startbildschirm hinzufügen*. Die App muss danach ohne Browserleiste im Vollbild starten. |
| **Angemeldet bleiben** | Am Handy mit gesetztem Häkchen anmelden, Browser vollständig schließen, App neu öffnen → **kein erneuter Login**. |

Wenn dabei etwas klemmt: Container-Log in Portainer ansehen, die Meldung sagt
in aller Regel direkt, woran es lag.

---

## Updates einspielen

Nach Änderungen am Code:

1. Auf dem Entwicklungsrechner die **neue Versionsnummer in die Datei `VERSION`**
   schreiben — eine Stelle höher als die zuletzt ausgerollte, die in
   `doku/stand.md` steht. Feste Nummern statt `:latest`, damit ein Rückschritt
   möglich bleibt und damit nie zwei verschiedene Stände denselben Namen tragen.

   Dieselbe Nummer in `deploy/stack.yml` (Zeile `image:`) und oben in Schritt 2
   dieser Anleitung eintragen.

2. Im Projektverzeichnis:
   ```bash
   bash deploy/paket_bauen.sh
   ```
   Das Skript gleicht die drei Stellen ab und **bricht ab, wenn sie sich
   unterscheiden** — sonst zeigte die Wartungsseite später eine Version an, die
   gar nicht läuft. Danach prüft es die Syntax aller Dateien, packt sie neu und
   bricht ebenfalls ab, falls versehentlich Zugangsdaten oder die Datenbank im
   Paket landen würden. Die letzte Zeile nennt den Image-Namen zum Abtippen.

3. In Portainer das Image mit dieser Nummer bauen (Schritt 2).

4. **Stacks → trainingsplan → Editor**: in der Zeile `image:` die neue Nummer
   eintragen, dann **Update the stack**.

5. Zur Kontrolle in der App **Wartung & Sicherung** öffnen: Die Kachel
   *Version* ganz oben muss die neue Nummer zeigen. Steht dort noch die alte,
   läuft der alte Container weiter — dann hat das Stack-Update nicht gegriffen.

> ### „Re-pull image" ausgeschaltet lassen
>
> Im Bestätigungsdialog von *Update the stack* bietet Portainer
> **„Re-pull image and redeploy"** an. Diese Option **muss aus bleiben.**
>
> Sie weist Docker an, das Image aus einer Registry zu holen. Unser Image
> existiert aber nur lokal auf dem LXC — es wurde dort gebaut und nirgends
> hochgeladen. Mit eingeschalteter Option scheitert das Update mit einer
> Meldung wie *„manifest unknown"* oder *„pull access denied"*.
>
> Ausgeschaltet nimmt Docker schlicht das lokal vorhandene Image mit dem
> angegebenen Namen — genau das ist gewollt.

**Datenbank und Bilder bleiben dabei unberührt** — sie liegen in den Volumes
`trainingsplan-data` und `trainingsplan-uploads`, nicht im Image. Neue Tabellen
oder Spalten legt die App beim Start selbst an.

> **Das einzige, was Daten kostet:** den Stack löschen und dabei die
> Volume-Option anhaken (oder auf der Shell `docker compose down -v`). Ein
> Image-Update tut das nie.

---

## Wenn etwas nicht stimmt

| Symptom | Wahrscheinliche Ursache |
|---|---|
| 502 vom Nginx | Container läuft nicht, oder er ist nicht an `10.10.10.2:8066` gebunden |
| Endlose Weiterleitung nach dem Login | `X-Forwarded-Proto` kommt nicht an — Nginx-Konfiguration mit `doku/nginx-vhost.conf` vergleichen |
| Login klappt, nächste Seite ist wieder abgemeldet | Aufruf über `http://` statt `https://` |
| Nach fünf Fehlversuchen sind **alle** ausgesperrt | `mod_remoteip` inaktiv — dann steht für jeden Request die Nginx-IP in der Log-Auswertung |
| Bild-Upload schlägt fehl | Datei zu groß (über 5 MB) oder über 25 Megapixel; die Meldung nennt den Grund |
| Container *unhealthy* | Datenverzeichnis nicht beschreibbar — Log ansehen |

Ausführlicher steht das in `doku/deployment.md`.
