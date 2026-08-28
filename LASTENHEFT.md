# Lastenheft — Trainingsplan-Web-App

Zentral gepflegte Web-App zur Verwaltung und mobilen Nutzung von Studio-Trainingsplänen
für mehrere Benutzer. Umsetzung mit Claude Code.

> **Diese Datei beschreibt den SOLL-Zustand**, nicht seine Entstehung. Wie sie dorthin
> gekommen ist — welcher Nachtrag wann welche Annahme der Erstfassung abgelöst hat —, steht
> in `doku/historie.md`. Der Code beschreibt den Ist-Zustand; bei Widerspruch gewinnt für
> das **Was** dieses Dokument, für das **Wie** `CLAUDE.md`.
>
> **Konkrete Umgebungswerte schlagen allgemeine Annahmen:** §3.1 und `doku/nginx-vhost.conf`
> sind verbindlich.

---

## 1. Zweck & Nutzungsszenario

Ein Administrator pflegt über eine Weboberfläche Übungen und Trainingspläne für mehrere
Benutzer. Die Benutzer rufen ihren Plan im Studio am Smartphone auf, sehen die anstehenden
Übungen inkl. des zuletzt verwendeten Gewichts, haken erledigte Übungen ab und können bei
Bedarf eine Übung durch eine Alternative derselben primären Muskelgruppe ersetzen. Ein Training wird
als **Einheit (Session)** geführt, die auch über Mitternacht hinweg aktiv bleibt (siehe §7).

**Nutzerkreis:** klein und geschlossen (aktuell zwei Personen). Kein öffentliches
Self-Signup — Benutzer werden ausschließlich vom Administrator angelegt.

---

## 2. Technischer Stack & Konventionen

- **Backend:** PHP 8.3, ohne Framework, `declare(strict_types=1)` in jeder Datei
- **Datenbank:** SQLite (Datei-basiert), Zugriff ausschließlich über PDO mit
  Prepared Statements
- **Frontend:** serverseitig gerendertes HTML + Vanilla JavaScript, kein Framework,
  kein Build-Step
- **UI-Sprache:** Deutsch
- **Progressive Web App (PWA):** Web-App-Manifest + Service Worker, damit die App am
  Smartphone „zum Startbildschirm hinzufügen" installierbar ist und im Vollbild läuft.

**Service-Worker-Regel (zwingend):** Der Service Worker cacht **ausschließlich** statische
Assets — `assets/*.css`, `assets/*.js`, `manifest.json`, die Icons und (seit `1.2.11`) die
**Seiten-Skripte im Wurzelverzeichnis** (`index.js`, `plans.js`, …) — mit Strategie
**`stale-while-revalidate`**: Die Antwort kommt sofort aus dem Cache, parallel wird die
frische Fassung geholt und abgelegt.

> **Warum die Seiten-Skripte dazugehören.** Dass sie bis `1.2.10` fehlten, war keine
> Entscheidung, sondern eine Folge ihrer Ablage: `index.js` ist so groß wie `app.js`, trägt
> dieselbe `?v=`-Nummer und ändert sich genauso selten — lag aber nicht in `assets/` und
> ging deshalb bei **jedem** Seitenaufruf ans Netz. Zusammen mit `Cache-Control: no-cache`
> war das eine volle Netzrunde, bevor die Seite bedienbar wurde. Erfasst wird nur, was
> **direkt** im Wurzelverzeichnis liegt und auf `.js` endet; kein Unterordner, keine andere
> Endung. Vorab geladen werden sie **nicht** — sonst holte der Service Worker bei jeder
> Installation alle sieben, auch die für Seiten, die niemand öffnet.

> **Nicht `cache-first`.** Das ist eine Falle: Ein
> Service Worker wird nur neu installiert, wenn sich **seine eigene Datei** ändert. Bleibt
> `sw.js` unverändert, läuft `install()` nie wieder — und `caches.match()` liefert bis in
> alle Ewigkeit die Fassung vom ersten Besuch. `style.css` und `app.js` waren dadurch in
> jedem Browser eingefroren, über mehrere Versionen hinweg. Zusätzlich trägt der Cache eine
> Versionsnummer im Namen, die beim Ändern der Assets hochgezählt wird. **Kein einziges HTML-Dokument und keine API-Antwort** darf gecacht werden
(`network-only`). Andernfalls liefert die App nach dem Logout eingeloggten Zustand aus dem
Cache und veraltete CSRF-Tokens, die jeden POST mit 403 abweisen lassen.

**Netzverfügbarkeit:** Das Netz im Studio ist stabil. Die App wird **online-only** gebaut —
jede Aktion geht direkt an den Server, es gibt keine Offline-Queue und keinen Sync-Zustand.
Schlägt ein Request fehl, bleibt die betroffene Übung sichtbar **unbestätigt** und es erscheint
ein Wiederholen-Button; ein Fehler darf nie stillschweigend verschluckt werden. Sämtliche
Schreibzugriffe laufen über **einen** Wrapper (`apiFetch()`), sodass eine Offline-Queue später
an genau einer Stelle nachrüstbar bliebe.

### 2.1 Projektkonventionen

Grundlage ist der Stil des Repos **Body-Fat-Tracker**, ergänzt um die Bausteine, die
**Speisekarte** besser löst. Beide Repos sind Vorlage, nicht Vorschrift.

Übernommen aus **Body-Fat-Tracker**:
- Verzeichnisse `lib/` + `api/` + `assets/`, mit je einer `.htaccess` (`Require all denied`)
  in `lib/` und `data/`
- PDO-Singleton `db(): PDO` mit `ERRMODE_EXCEPTION`, `FETCH_ASSOC`,
  `EMULATE_PREPARES => false` sowie `PRAGMA journal_mode=WAL`, `foreign_keys=ON`,
  `busy_timeout=5000`
- Escaping über die Kurzfunktion `h(?string $v): string`, Ausgabe als `<?= h(...) ?>`
- JSON-Envelope `json_ok(array $data = [], int $status = 200)` /
  `json_err(string $error, int $status = 400, array $fields = [])`
- Frontend-Wrapper `apiFetch(url, options)`: setzt `X-CSRF-Token`, entpackt `{ok,data}`,
  leitet bei 401 auf `login.php`
- Eine Seite = eine `.php` + eine gleichnamige `.js`
- Zahleneingaben als `type="text" inputmode="decimal"` (Dezimalkomma), nicht `type="number"`

Übernommen aus **Speisekarte**:
- GD-Bildverarbeitung nach dem Muster `saveResizedImage()` (MIME-Prüfung per `finfo`,
  Re-Enkodierung, Alpha-Erhalt)
- Bild-Ausliefer-Endpoint mit Path-Jail (`realpath`-Prefix-Check + `basename()`)
- Wartungs-/Backup-Seite nach `Speisekarte/doku/maintenance_anleitung.md`
- Brute-Force-Bremse als DB-Tabelle statt in `$_SESSION`

Bewusste Abweichung von beiden Repos: **Kein aus jeder Seite kopierter `<head>`/`<nav>`-Block.**
Beide Vorlagen duplizieren diesen von Hand; bei rund einem Dutzend Seiten wird das zur
Fehlerquelle. Stattdessen zwei Partials `lib/view_header.php` und `lib/view_footer.php`.

### 2.2 Verzeichnisstruktur

```
Trainingsplan/
├── index.php  index.js          # Handy-Ansicht: aktive Einheit / Planvorschlag
├── login.php  logout.php  password.php  history.php
├── splits.php  splits.js       # Der EIGENE Splitbestand -- fuer ALLE Benutzer
├── plans.php   plans.js        # Plaene eines Splits -- ebenfalls fuer alle
├── admin.php                   # Einstieg in die Verwaltung, nur Kacheln
├── admin_splits.php  admin_splits.js   # Der Vorlagenkatalog, nur Admin
├── admin_users.php  admin_exercises.php
├── admin_muscle_groups.php  maintenance.php  download_backup.php
├── image.php                    # Bild-Ausliefer-Endpoint mit Path-Jail
├── api/    auth.php  session.php  log.php  swap.php  splits.php
│           exercises.php  plans.php  users.php  muscle_groups.php
├── lib/    db.php  auth.php  csrf.php  helpers.php  upload.php  splits.php
│           view_header.php  view_footer.php  view_split_karte.php
│           view_split_text_dialog.php        (+ .htaccess: Require all denied)
├── assets/ style.css  app.js  sw.js  manifest.json  icon-192.png  icon-512.png
├── data/     trainingsplan.db          ← Volume, .htaccess gesperrt
├── uploads/  <zufallsname>.jpg         ← Volume, kein PHP-Handler
├── schema.sql  Dockerfile  apache-app.conf  docker-compose.yml  .env.example
├── CLAUDE.md  README.md  LASTENHEFT.md
├── deploy/ stack.yml  env-vorlage.txt  paket_bauen.sh   # Ausrollen ueber Portainer
└── doku/   nginx-vhost.conf  deployment.md
```

---

## 3. Deployment & Initialisierung

Die App läuft als **Docker-Container** auf einem Hetzner-Rootserver, erreichbar über eine
eigene **Subdomain mit Let's-Encrypt-Zertifikat**. Ein Reverse-Proxy (Nginx auf dem Host)
leitet auf den veröffentlichten Container-Port weiter.

### 3.1 Zielumgebung (verbindlich)

Der Rootserver betreibt **Proxmox** mit mehreren LXC-Containern auf den Adressen
`10.10.10.2`, `10.10.10.3` usw. Dieser Dienst läuft im LXC **`10.10.10.2`**; Docker läuft
innerhalb dieses LXC. Die folgenden Werte sind keine Beispiele, sondern der eingerichtete
und funktionierende Ist-Zustand:

| | |
|---|---|
| Subdomain | `training.jadefalke.net`, im Browser erreichbar |
| TLS | Let's Encrypt via Certbot, terminiert am Host-Nginx |
| Host-Nginx | leitet weiter auf `http://10.10.10.2:8066` (`proxy_pass`) |
| Weitergereichte Header | `Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto` |
| Port 80 | `301` auf HTTPS, sonst `404` |
| Docker-Port-Binding | `10.10.10.2:8066` → Container-Port `80` |
| Verwaltung | **Portainer**, zentral für alle LXC; jeder LXC ist dort ein eigenes Environment |
| Persistenz | Named Volumes `trainingsplan-data`, `trainingsplan-uploads` — **keine** Host-Pfade |

Auf demselben LXC laufen bereits andere Docker-Projekte (u. a. `solarwatch`). Deren
Konventionen gelten auch hier: Image in Portainer bauen, Stack ohne `build:`, Named Volumes.

Die aktive Nginx-Konfiguration liegt wortgetreu in `doku/nginx-vhost.conf`, die
Betriebsanleitung in `doku/deployment.md`. **Bei Widerspruch zwischen diesem Lastenheft und
`doku/nginx-vhost.conf` gilt die Nginx-Datei** — sie bildet den Server ab.

Wo in diesem Dokument sonst noch Adressen, Ports oder Pfade auftauchen, sind sie
Platzhalter aus der Erstfassung und den Werten dieser Tabelle nachgeordnet.

**Container-Anforderungen:**

- Basis-Image `php:8.3-apache`
- Zusätzlich installiert: PHP-Extensions `pdo_sqlite` und `gd` (Bildverarbeitung),
  Apache-Module `rewrite`, `headers` und **`remoteip`**
- Dockerfile-Kern:
  ```dockerfile
  FROM php:8.3-apache
  RUN apt-get update && apt-get install -y libsqlite3-dev libpng-dev libjpeg-dev \
   && docker-php-ext-configure gd --with-jpeg \
   && docker-php-ext-install pdo_sqlite gd \
   && a2enmod rewrite headers remoteip
  COPY . /var/www/html
  ```
  **`libsqlite3-dev` ist zwingend** und fehlte in der Erstfassung dieses Beispiels: Das
  Basis-Image bringt SQLite zur Laufzeit mit, aber nicht die Header und die
  pkg-config-Datei. Ohne das Paket bricht `docker-php-ext-install pdo_sqlite` mit
  *„Package 'sqlite3', required by 'virtual:world', not found"* ab.
  Der Repo-Wurzelordner **ist** das Anwendungsverzeichnis (`index.php` liegt im Root, siehe
  §2.2) — es gibt kein Unterverzeichnis `app/`. Was nicht ins Image gehört, steht in
  `.dockerignore`.
- **`mod_remoteip` ist keine Kür.** Ohne das Modul steht in `REMOTE_ADDR` bei jedem Request
  die Adresse des Nginx statt die des Clients. Die Brute-Force-Bremse aus §5 zählt dann alle
  Fehlversuche auf dieselbe IP und sperrt nach fünf Versuchen **alle** Benutzer gemeinsam
  aus. Konfiguration: `RemoteIPHeader X-Forwarded-For`, `RemoteIPTrustedProxy 10.10.10.0/24`.

**Persistenz (zwingend):** Zwei Pfade müssen als Volume/Bind-Mount außerhalb des Images
liegen, sonst gehen bei jedem Rebuild alle Daten verloren:

- `/var/www/html/data` → Named Volume `trainingsplan-data` — die SQLite-Datenbankdatei
- `/var/www/html/uploads` → Named Volume `trainingsplan-uploads` — die Übungsbilder

**Named Volumes, keine Bind-Mounts.** Das ist keine Geschmacksfrage: Der Apache im Container
läuft als `www-data` (UID 33). Ein Bind-Mount auf ein Host-Verzeichnis entsteht als
`root:root`, und die App könnte weder die Datenbank anlegen noch Bilder speichern — es
brauchte ein `chown -R 33:33` von Hand auf der LXC-Shell, an Portainer vorbei. Ein neu
angelegtes Named Volume befüllt Docker dagegen aus dem Image und übernimmt dabei Eigentümer
und Rechte; der `chown` im `Dockerfile` genügt damit, und auf dem Host ist **nichts**
anzulegen.

**Port-Binding:** Der Container-Port wird an die **LXC-interne Adresse** `10.10.10.2:8066`
gebunden — weder an `0.0.0.0` noch an `127.0.0.1`. Loopback wäre hier falsch: der Nginx läuft
auf dem Proxmox-Host, also außerhalb des LXC, und käme an einen Loopback-Port nicht heran.
Entscheidend für die Sicherheit ist ohnehin nicht das Interface, sondern dass
**ausschließlich der vorgelagerte Nginx** den Port erreicht: `10.10.10.0/24` ist ein reines
Proxmox-Internetz ohne Route oder Port-Forwarding von außen. Genau das ist die Voraussetzung
dafür, dass dem `X-Forwarded-Proto`-Header vertraut werden darf (siehe §5) — er ist nur
fälschungssicher, solange der Container nicht direkt von außen erreichbar ist. Bekäme der LXC
je ein zweites, öffentliches Interface, fiele diese Annahme und mit ihr die Header-Prüfung.

**Konfiguration** über Umgebungsvariablen, keine Secrets im Image/Repository:

- `ADMIN_USER`, `ADMIN_PASSWORD` — für die einmalige Erst-Admin-Erzeugung (siehe unten)
- `APP_SECRET` — serverseitiges Secret, ausschließlich für den Hash des
  Remember-Me-Validators (siehe §5)
- `DB_PATH` — Pfad zur SQLite-Datei (im `data`-Volume)
- `TZ` — Zeitzone, Standard `Europe/Vienna`

**Initialisierung & Erst-Admin (Bootstrapping):**

- Beim ersten Start legt die App die DB an, falls sie fehlt: Schema aus einer mitgelieferten
  `schema.sql` (ausschließlich `CREATE TABLE IF NOT EXISTS`, daher bei jedem Start
  ausführbar), danach Seeding der Standard-Muskelgruppen.
- Existiert noch kein Benutzer, wird **ein initialer Admin aus `ADMIN_USER`/`ADMIN_PASSWORD`**
  erzeugt (Passwort beim ersten Boot gehasht). Damit ist das Henne-Ei-Problem ohne
  Self-Signup gelöst. Alle weiteren Benutzer entstehen über die Admin-Oberfläche.
- `ADMIN_PASSWORD` bleibt in der Compose-Datei dauerhaft im Klartext sichtbar. Deshalb wird
  der Erst-Admin mit `must_change_password = 1` angelegt und beim ersten Login zwingend auf
  `password.php` geleitet. Nach dem Bootstrapping wird die Variable ignoriert — eine Änderung
  wirkt sich nicht auf bestehende Benutzer aus.

**Zeitzone:** Container-`TZ` und PHP-Default-Zeitzone auf `Europe/Vienna` setzen. Hinweis:
Die Trainingslogik ist bewusst **session-basiert** (§7) und hängt nicht am Kalendertag; die
Zeitzone betrifft daher vor allem korrekte Zeitstempel und Datumsanzeigen.

**Zeitstempel-Regel (zwingend):** Alle Zeitstempel werden **in PHP** erzeugt und als
`Y-m-d H:i:s` gespeichert. SQLites `CURRENT_TIMESTAMP` liefert **UTC** und darf nirgends als
Spalten-Default oder im SQL verwendet werden — sonst mischen sich zwei Zeitzonen in derselben
Spalte.

**HTTPS & Reverse-Proxy:** HTTPS ist Voraussetzung — die sicheren Cookie-Flags (siehe §5)
funktionieren nur über eine verschlüsselte Verbindung. Wichtig für die Implementierung:
**TLS terminiert am vorgelagerten Host-Nginx**; zwischen Nginx und Container läuft die
Verbindung intern **unverschlüsselt über HTTP** — nicht über `localhost`, sondern über das
Proxmox-Internetz von der Nginx-Maschine zu `10.10.10.2:8066`. Der Nginx reicht das
ursprüngliche Protokoll über den Header `X-Forwarded-Proto` (sowie `X-Forwarded-For`,
`X-Real-IP`, `Host`) weiter; alle vier Header sind in der aktiven Konfiguration gesetzt. Die App darf sich zur Protokoll-Erkennung deshalb **nicht** auf
`$_SERVER['HTTPS']` verlassen (intern leer/`off`), sondern muss `X-Forwarded-Proto`
auswerten — siehe §5.

---

## 4. Datenmodell

SQLite-Tabellen (Feldnamen als Vorschlag, Typen sinngemäß).

**Fremdschlüssel:** `PRAGMA foreign_keys = ON` ist gesetzt, das `ON DELETE`-Verhalten ist
deshalb je Beziehung explizit festzulegen (siehe §4.1).

**muscle_groups** — kontrolliertes Vokabular für Muskelpartien, **zweistufig**
- `id`, `name_de`, `name_en`, `parent_id` (FK → muscle_groups, nullable), `sort_order`
- **Genau zwei Ebenen:** Hauptgruppen haben `parent_id IS NULL`, Untergruppen zeigen auf
  ihre Hauptgruppe. Eine Untergruppe darf selbst keine Kinder haben.
- **Der Übungstausch (§7.5) vergleicht auf Hauptgruppen-Ebene.** Das ist der ganze Zweck
  der zweiten Stufe: Die Unterteilung darf beliebig fein werden (`Brust (oben)`,
  `Brust (mitte)`, `Brust (unten)`), ohne dass die Vorschlagslisten leer laufen — für eine
  Übung an `Brust (oben)` kommt alles unter `Brust` als Ersatz infrage.
- Ohne die zweite Stufe müsste man zwischen zwei Übeln wählen: grobe Gruppen, bei denen
  Gegenspieler einander vorgeschlagen werden, oder feine, bei denen fast jede Übung allein
  in ihrer Klasse steht.
- Standardwerte geseedet: Brust, Rücken, Schultern, Bizeps, Trizeps, Beine, Waden, Bauch
  (im Admin erweiterbar, §6.2). Muskelgruppen sind Voraussetzung für das Anlegen von Übungen —
  sie werden vorab im Adminbereich gepflegt, nicht nebenbei beim Übungsanlegen erfasst.

**exercise_muscle_groups** — Zuordnung Übung ↔ Muskelgruppen (n:m)
- `exercise_id` (FK → exercises), `muscle_group_id` (FK → muscle_groups),
  `is_primary` (bool, Default 0)
- Primärschlüssel ist `(exercise_id, muscle_group_id)`.
- **Eine Übung kann mehrere Muskelgruppen haben** — Bankdrücken trifft Brust *und* Trizeps,
  Klimmzüge Rücken *und* Bizeps. Mindestens eine Zuordnung ist Pflicht.
- **`is_primary` unterscheidet primär und sekundär.** Genau eine Zuordnung je Übung trägt
  `is_primary = 1` (die **primäre** Muskelgruppe — die, wegen der man die Übung macht), alle
  weiteren sind **sekundär** (werden mittrainiert, sind aber nicht der Zweck).
  Bankdrücken: Brust primär, Trizeps sekundär. Klimmzüge: Rücken primär, Bizeps sekundär.
- Diese Unterscheidung trägt die gesamte Tauschlogik (§7.5): Vorgeschlagen wird nur, was
  dieselbe **primäre** Gruppe hat. Ohne sie liefen die Vorschläge in beide Richtungen falsch —
  für Bankdrücken kämen reine Trizeps-Übungen, und für eine Trizeps-Übung käme Bankdrücken,
  das niemand macht, um den Trizeps zu trainieren.
- **Datenbankseitig abgesichert**, damit „genau eine" nicht bloß Konvention bleibt:
  ```sql
  CREATE UNIQUE INDEX IF NOT EXISTS idx_emg_one_primary
      ON exercise_muscle_groups(exercise_id) WHERE is_primary = 1;
  ```
  Ein partieller Unique-Index (von SQLite unterstützt) macht eine zweite Primärgruppe zum
  Fehler statt zu einem stillen Datenschaden. Beim Umsetzen der Primärgruppe deshalb erst
  zurücksetzen, dann neu setzen — beides in einer Transaktion.

**exercises** — Übungen
- `id`, `name_de`, `name_en`, `description`, `focus` (text, nullable), `equipment`
  (text, nullable), `image_path`, `archived` (bool, Default 0), `archived_at` (datetime,
  nullable), `created_at`
- **`name_en` steht überall dort, wo `name_de` steht** — Trainingsansicht, Planpositionen,
  Übungsverwaltung, Verlauf, Tauschfenster und Übungsauswahl. Die Geräte im Studio sind
  englisch beschriftet, und wer eine Übung nachschlägt, findet unter dem englischen Namen
  mehr; ein Name, der nur auf manchen Seiten zweisprachig ist, zwingt zum Nachsehen auf
  einer anderen. Der englische Name steht **unter** dem deutschen, gedämpft — nebeneinander
  gelesen verschmelzen beide zu einem langen Namen. Wo der Name in eine laufende Zeile muss
  (Abzeichen „statt …" nach einem Tausch, Kopf einer Verlaufskarte), steht er einzeilig als
  `Deutsch · English`. Ist `name_en` leer, entfällt er ersatzlos.
- **`focus`** ist der Schwerpunkt *innerhalb* der Primärgruppe — „oben" bei Brust, „stehend"
  bei Waden. Reine Anzeige-Information: Die Tauschlogik (§7.5) zieht ausschließlich die
  Primärgruppe heran und ignoriert dieses Feld. Genau deshalb ist es ein Textfeld und keine
  weitere Muskelgruppe — sonst zersplitterten die Tauschklassen so weit, dass keine Übung
  mehr eine Alternative hätte.
- **`equipment`** ist das Trainingsgerät — das *Womit* neben dem *Was* (Muskelgruppen) und
  dem *Wie* (`focus`). Es trägt einen Schlüssel aus einer **festen Codeliste** in
  `lib/geraete.php`: `maschine`, `multipresse`, `kabel` (Kabelzug), `langhantel`,
  `kurzhantel`, `kettlebell`, `koerper` (Körpergewicht). Der Schlüssel steht in der
  Datenbank, die Beschriftung nur im Code — eine Umbenennung ist deshalb eine
  Textänderung und keine Migration. Keine eigene Tabelle — die Menge ist klein und
  geschlossen —, und bewusst **kein `CHECK`-Constraint**, weil SQLite es nur über einen
  Tabellen-Neuaufbau ändern könnte; geprüft wird in `api/exercises.php`.

  In der Oberfläche ist das Feld **Pflicht**, auch beim Bearbeiten. Die Spalte lässt
  trotzdem `NULL` zu: Übungen aus der Zeit vor diesem Feld tragen keinen Wert und werden in
  der Liste als „Gerät fehlt" angemahnt, statt mit einem geratenen Vorgabewert belegt zu
  werden.

  Wie `focus` ist es **Anzeige und Filter, kein Kriterium der Tauschlogik** (§7.5): Der
  häufigste Grund zu tauschen ist ein besetztes Gerät, und eine Einschränkung auf dasselbe
  Gerät verböte genau den gesuchten Ausweg.

  Nicht als eigener Typ vorgesehen, weil es in `focus` gehört: SZ-Stange und Trap-Bar sind
  Langhantel, Klimmzugstange und Dip-Barren sind Körpergewicht.
- Die Muskelgruppen hängen **nicht** als Fremdschlüssel an der Übung, sondern an der
  Zuordnungstabelle `exercise_muscle_groups` (n:m, siehe unten).
- **`archived`** ersetzt das harte Löschen (§6.3). Archivierte Übungen verschwinden aus
  Dropdowns und Tauschvorschlägen, bleiben aber für die Historie referenzierbar und sind im
  Admin jederzeit einsehbar (§6.3).

**users** — Benutzer
- `active_split_id` (FK → splits, nullable) trägt seit `1.2.0`, welcher Split gerade
  gewählt ist. Das ist eine **Auswahl** und keine ableitbare Tatsache — deshalb darf sie
  als Spalte stehen, anders als `last_plan_id` daneben. Die Rotation *innerhalb* des
  Splits wird weiterhin aus der Historie gelesen und nirgends notiert. Damit die Auswahl
  nicht veraltet, setzt der Start einer Einheit sie mit; ist sie leer oder zeigt sie ins
  Leere, fällt die App auf den Split der letzten Einheit zurück, sonst auf den ersten
  eigenen.
- `id`, `name` (eindeutiger Login-Name), `password_hash`, `is_admin` (bool),
  `must_change_password` (bool, Default 0), `expert_mode` (bool, Default 0),
  `last_plan_id` (FK → plans, nullable — **unbenutzt**, siehe §7.6;
  die Spalte bleibt nur stehen, weil ihr Entfernen eine löschende Migration ohne
  Gegenwert wäre), `blocked_at` (nullable, siehe §6.1),
  `satz_vorlage` (Codeliste, Default `gleicher_satz`, siehe §7.4), `created_at`
- **`blocked_at` ist ein Zeitstempel und kein Flag:** `NULL` heißt aktiv, sonst steht dort
  der Zeitpunkt der Sperre. Ein Paar aus `blocked` und `blocked_at` — wie es
  `exercises.archived`/`archived_at` vorexerziert — könnte sich widersprechen, und dann
  hinge das Verhalten davon ab, welche der beiden Spalten gerade gelesen wird.

**remember_tokens** — „Angemeldet bleiben"-Tokens (siehe §5)
- `id`, `user_id` (FK), `selector` (eindeutig), `validator_hash`, `expires_at`,
  `last_used_at` (datetime, nullable), `user_agent` (text, nullable), `created_at`
- `last_used_at` und `user_agent` dienen der Geräteliste in §7.7 und fallen bei der
  Token-Rotation ohnehin an.

**login_attempts** — Brute-Force-Bremse (siehe §5)
- `id`, `ip`, `attempted_at`
- Bewusst eine Tabelle und nicht `$_SESSION`: Ein sessionbasierter Zähler ist durch simples
  Löschen des Cookies zu umgehen.

**splits** — Workout-Splits, die Klammer um eine Rotation (seit `1.2.0`)
- `id`, `user_id` (FK → users, **nullable**), `name`, `beschreibung`, `sort_order`,
  `created_at`
- **Zwei Arten, kein dritter Zustand:**
  - `user_id IS NULL` → **Vorlage.** Der Katalog. Für alle Benutzer sichtbar, nur von
    Admins bearbeitbar, und **niemand trainiert darauf**.
  - `user_id = X` → **persönlicher Split von X.** Nur X (und ein Admin) sieht und
    bearbeitet ihn, und **nur darauf wird trainiert**.
- **Zwischen beiden gibt es genau eine Verbindung, und die ist eine Kopie.** Wer eine
  Vorlage benutzen will, kopiert sie zu sich (Split, Pläne und Positionen werden neu
  angelegt); danach sind beide Seiten unabhängig. Es gibt **keine Vererbung und kein
  automatisches Nachziehen**:
  - Ändert der Admin die Vorlage, bleibt jede bestehende Kopie unberührt.
  - Ändert ein Benutzer seine Kopie — dauerhafter Tausch, Übung entfernen, ergänzen,
    umsortieren —, merkt davon weder die Vorlage noch ein anderer Benutzer. **Das ist
    der Zweck des Ganzen:** Zwei Leute dürfen denselben Split fahren, ohne sich
    gegenseitig in den Bestand zu schreiben.
- **Eine Kopie kennt ihre Vorlage** (`splits.vorlage_id`) und lässt sich auf deren Stand
  **zurücksetzen** — auf ausdrücklichen Knopfdruck des Eigentümers und sonst nie. Der
  Knopf erscheint nur, wenn Kopie und Vorlage auseinanderliegen; ob das an einer eigenen
  Anpassung liegt oder an einer verbesserten Vorlage, spielt keine Rolle. Die Herkunft
  entsteht beim Kopieren und lässt sich an der Karte auch nachträglich zuordnen — für
  Splits, die vor `1.2.11` entstanden sind und ihre Vorlage nicht kennen.

  > Bis `1.2.10` gab es diesen Weg ausdrücklich **nicht**: „Wer den neuen Stand will,
  > kopiert erneut." Als Weg, eine verbesserte Vorlage zu übernehmen, taugte das nicht —
  > erneut kopieren erzeugt `… (2)`, lässt den alten Split stehen und wirft die Auswahl
  > *Diesen trainieren* um. Der Verweis ist reine Herkunftsangabe; er ändert nichts von
  > selbst.
- **Dass auf einer Vorlage niemand trainiert, ist keine Frage der Oberfläche.** Der
  dauerhafte Tausch schreibt in `plan_exercises` (§7.5) — auf einer Vorlage wäre das ein
  Schreibzugriff auf fremden Bestand. Durchgesetzt wird es serverseitig in
  `api/session.php`, `api/log.php` und `api/swap.php`; der fehlende Startknopf ist nur
  die Bequemlichkeit davor.
- Kein `UNIQUE` auf `name`: Zwei Benutzer dürfen denselben Splitnamen führen, und
  derselbe Benutzer mehrere Fassungen einer Vorlage nebeneinander.

**plans** — Trainingspläne innerhalb eines Splits
- `id`, `split_id` (FK → splits), `name`, `sort_order`, `created_at`
- `sort_order` ist die **Rotationsreihenfolge innerhalb des Splits** (§7.6), nicht bloß
  Anzeigesache.
- `user_id` (FK → users) steht weiter in der Tabelle, ist seit `1.2.0` aber **tot**: Wem
  ein Plan gehört, sagt allein `splits.user_id`. Sie bleibt aus einem einzigen Grund
  stehen — sie ist der Anker der Migration. Wird eine Sicherung von vor `1.2.0`
  eingespielt, stehen dort wieder Pläne ohne `split_id`, und nur `user_id` sagt dann
  noch, wem sie gehören. Einziger Leser ist `apply_migrations()`. **Nicht wieder in
  Betrieb nehmen** — dasselbe gilt für `users.last_plan_id`.

**plan_exercises** — Übungen innerhalb eines Plans, geordnet
- `id`, `plan_id` (FK), `exercise_id` (FK), `sort_order`

**sessions** — Trainingseinheiten (siehe §7.6)
- `id`, `user_id` (FK), `plan_id` (FK), `started_at` (datetime), `ended_at` (datetime, nullable)
- **Offene Einheit** = `ended_at IS NULL`. Pro Benutzer darf höchstens **eine** Einheit offen
  sein. Die Einheit ist die Einheit der Trainingslogik — nicht der Kalendertag.

**workout_log** — Protokoll je Planposition innerhalb einer Einheit (Basis für „letztes Gewicht")
- `id`, `session_id` (FK), `plan_exercise_id` (FK), `user_id` (FK), `exercise_id` (FK),
  `plan_id` (FK), `weight` (decimal, **nullable**), `done` (0/1, Vorgabe 1),
  `performed_at` (datetime)
- **`done` trennt „hier steht etwas protokolliert" von „die Übung ist fertig"** (seit
  `1.1.1`). Im Standardmodus fallen beide zusammen — deshalb die Vorgabe 1, die auch jede
  Bestandszeile richtig einordnet. Im Expertenmodus nicht: Dort entsteht die Zeile mit dem
  **ersten Satz**, und da ist man mitten in der Übung. Ohne diese Spalte hakte sich die
  Übung mit dem ersten Satz selbst ab.
- **Als „beendet" zählt `done = 1`**, nicht die bloße Existenz der Zeile — in der
  Trainingsansicht wie im Verlauf. **Die Tauschsperre (§7.5) hängt dagegen an der Existenz der Zeile**: Wer
  zwei Sätze Bankdrücken gemacht hat, kann die Position nicht mehr tauschen, auch ohne
  Häkchen. Die zwei Sätze waren Bankdrücken.
- **Hier steht keine Wiederholungsspalte.** Ein Feld je Einheit kann nicht abbilden, was tatsächlich passiert — bei
  drei Sätzen etwa 12, dann 10, dann 9. Ein solches Feld täuscht eine Genauigkeit vor, die
  es nicht hat. Genau deshalb bekam das satzgenaue Protokollieren seit `1.1.0` **eine eigene
  Tabelle** (`workout_sets`) statt einer Spalte hier — so, wie es an dieser Stelle
  angekündigt war.
- `weight` bleibt auch im Expertenmodus gefüllt und trägt dann das **Leitgewicht** der
  Position: den **schwersten Satz**. Das ist keine Redundanz, sondern der Grund, warum
  „letztes Gewicht" (unten), der Gewichtsverlauf und der Bestwert (§7.8) über beide Modi
  hinweg eine durchgehende Reihe bleiben. Der schwerste und nicht der letzte Satz, weil der
  Bestwert die Zahl ist, an der man Fortschritt misst.
- **Eindeutig ist `(session_id, plan_exercise_id)`** — genau ein Eintrag pro Einheit und
  Planposition (Upsert beim Abhaken, Löschen beim Ab-wählen).
- `plan_exercise_id` ist zwingend: Nach einem Tausch (§7.5) steht in `exercise_id` die
  **Ersatz**übung. Ohne die Planposition ließe sich weder der Fortschritt „x/n" zählen, noch
  wäre der Schlüssel eindeutig, sobald die Ersatzübung ohnehin schon im Plan steht.
- `exercise_id` bleibt zusätzlich erhalten — sie ist die Grundlage für „letztes Gewicht" und
  für die späteren Charts (§10).
- Das vorbelegte „letzte Gewicht" einer Übung ist der jüngste `workout_log.weight` dieses
  Benutzers für diese Übung **über alle Einheiten hinweg**, wobei **leere Werte übersprungen
  werden**: `WHERE user_id = ? AND exercise_id = ? AND weight IS NOT NULL
  ORDER BY performed_at DESC, id DESC LIMIT 1`. So geht ein Gewicht nicht verloren, nur weil
  es einmal nicht eingetragen wurde. Das `id DESC` ist Pflicht: Zeitstempel haben
  Sekundenauflösung, zwei Einträge derselben Sekunde hätten sonst keine definierte
  Reihenfolge.

**workout_sets** — die einzelnen Sätze einer Planposition (Expertenmodus, siehe §7.4)
- `id`, `workout_log_id` (FK, **CASCADE**), `satz_nr` (int), `reps` (int, nullable),
  `weight` (decimal, nullable)
- **Eindeutig ist `(workout_log_id, satz_nr)`.** Die Nummern werden bei jedem Speichern neu
  von 1 an vergeben — die Reihenfolge der gesendeten Liste *ist* die Reihenfolge der Sätze.
  Damit gibt es weder Lücken noch ein Umnummerieren.
- **Hängt an `workout_log`, nicht direkt an `(session_id, plan_exercise_id)`.** Dadurch
  erledigt `ON DELETE CASCADE` das gesamte Aufräumen: Ab-wählen löscht die Protokollzeile →
  Sätze weg; eine Einheit zu löschen räumt `sessions` → `workout_log` → Sätze. Es gibt
  keinen eigenen Löschpfad, den man beim nächsten Mal vergessen könnte.
- `reps` **und** `weight` sind nullbar — Körpergewichtsübungen haben kein Gewicht,
  Halte-Übungen keine Wiederholungszahl. Ein Satz, in dem **beides** leer ist, wird
  abgelehnt: Er beantwortet keine Frage.
- **Die ganze Satzliste ist die Nutzlast, nicht der einzelne Satz.** `api/log.php → check`
  nimmt sie als Feld `sets` entgegen und ersetzt die Sätze der Position vollständig. Der
  Aufruf ist damit idempotent und beliebig oft wiederholbar — worauf sich die Warteschlange
  aus §7.4 verlässt, die einen Eintrag je Planposition hält und ihn nach einem Funkloch
  erneut abschickt. Ein „Satz anlegen"-Endpunkt hätte bei jedem Wiederversuch einen weiteren
  Satz erzeugt.
- **Fehlt `sets` in der Nutzlast, werden vorhandene Sätze gelöscht.** Die Nutzlast beschreibt
  die Zeile vollständig; alles andere ließe Leitgewicht und Satzliste auseinanderlaufen.

**users.expert_mode** (0/1, Vorgabe 0) — steuert **ausschließlich die Darstellung**. Der
Server nimmt Sätze unabhängig davon entgegen; es gibt keine modusabhängige Sonderbehandlung
in `api/log.php`. Umgeschaltet wird auf der Kontoseite (§7.7), **nicht während einer
laufenden Einheit** — Begründung in §7.4.

**exercise_swaps** — einmaliger Übungstausch, an die Einheit gebunden (siehe §7.5)
- `id`, `session_id` (FK), `plan_exercise_id` (FK), `replacement_exercise_id` (FK)
- Eindeutig ist `(session_id, plan_exercise_id)`.
- Gilt, solange die zugehörige Einheit offen ist.

### 4.1 Lösch- und Kaskadenverhalten

| Aktion | Verhalten |
|---|---|
| Übung löschen | Regelfall: kein hartes Löschen, sondern `archived = 1` (§6.3) — Historie bleibt vollständig. Hartes Löschen nur, wenn die Übung weder in einem Plan referenziert wird noch `workout_log`-Einträge hat; dann inkl. Bilddatei und Thumbnail. |
| Muskelgruppe löschen | Nur zulässig, wenn keine Zuordnung in `exercise_muscle_groups` auf sie zeigt — auch keine von archivierten Übungen. Sonst Hinweis mit der Liste der betroffenen Übungen. Umbenennen und Umsortieren sind immer erlaubt. |
| Split löschen | Verboten, solange eine offene Einheit auf einen seiner Pläne zeigt. Sonst kaskadiert er auf `plans` und weiter auf `plan_exercises`; `sessions`/`workout_log` bleiben erhalten und zeigen danach „gelöschter Plan". Eine gelöschte **Vorlage** berührt keine einzige Kopie — die Kopien hängen nicht an ihr. |
| Plan löschen | Verboten, solange eine offene Einheit auf ihn zeigt. Sonst: `plan_exercises` kaskadiert, `sessions.plan_id` → `ON DELETE SET NULL`, `sessions`/`workout_log` bleiben erhalten. Eine Einheit ohne Plan zählt für die Rotation nicht mehr mit (§7.6). |
| Planposition entfernen | Verboten, solange eine offene Einheit läuft (§6.4). Sonst bleibt der `workout_log` erhalten; die Historie zeigt weiter auf `exercise_id`. |
| Benutzer löschen | Löscht `remember_tokens` und `splits` (und damit `plans`) kaskadierend, entfernt aber **nicht** `sessions`/`workout_log` — der Admin bekommt vor dem Löschen die Anzahl betroffener Einheiten angezeigt und bestätigt explizit. |

---

## 5. Sicherheit & Authentifizierung (harte Anforderung)

- **Passwörter** nur als Hash speichern (`password_hash()` mit `PASSWORD_ARGON2ID`,
  ersatzweise bcrypt), Verifikation mit `password_verify()`. Niemals Klartext.
  **Kein Pepper** — Argon2id genügt, und ein Verlust von `APP_SECRET` würde sonst sämtliche
  Passwörter unbrauchbar machen. `APP_SECRET` wird ausschließlich für den
  Remember-Me-Validator-Hash verwendet.
- **Sessions:** Cookie-Flags `HttpOnly`, `Secure`, `SameSite=Lax`; beim erfolgreichen
  Login `session_regenerate_id(true)` gegen Session-Fixation.
- **HTTPS-Erkennung hinter dem Reverse-Proxy (zwingend für Login & Cookies):** Die App läuft
  hinter einem TLS-terminierenden Nginx und sieht intern nur HTTP (`$_SERVER['HTTPS']` ist
  leer). Der öffentliche Endpunkt ist aber immer HTTPS, daher:
  - Session- und Remember-Me-Cookies werden **unbedingt mit `Secure`** gesetzt.
  - Für jede protokollabhängige Logik (HTTPS-Erzwingung/Redirects, Erzeugung absoluter URLs)
    leitet die App das Ursprungsprotokoll aus `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'`
    ab — **nicht** aus `$_SERVER['HTTPS']`. Andernfalls hält die App die Verbindung
    fälschlich für unverschlüsselt, was bei einer HTTPS-Erzwingung zu einer Redirect-Schleife
    führt und die `Secure`-Cookies/Remember-Me-Mechanik am Handy stört.
  - Dem `X-Forwarded-Proto`-Header darf vertraut werden, da der Dienst ausschließlich über
    den vorgelagerten, vertrauenswürdigen Proxy erreichbar ist (nicht direkt am Container-Port,
    siehe Port-Binding in §3).
- **„Angemeldet bleiben" (Remember-Me) nach Selector/Validator-Muster** — Kernanforderung,
  damit das Passwort real nur einmal pro Gerät eingegeben werden muss:
  - Beim Login Zufalls-Token erzeugen. Cookie enthält `selector:validator`.
  - In der DB nur der **gehashte** Validator plus Ablaufdatum (60–90 Tage).
  - Bei Wiederkehr: Lookup über `selector`, **konstantzeit-Vergleich** des Validators,
    danach **Token rotieren** (neuen Validator setzen, `last_used_at` aktualisieren).
  - Serverseitig jederzeit widerrufbar: Löschen der DB-Zeile loggt das Gerät sofort aus.
    Oberfläche dazu siehe §7.7.
- **CSRF-Schutz:** Token auf allen zustandsändernden Requests (Gewicht/Erledigt speichern,
  Übung tauschen, Training beenden, Admin-CRUD). Token in der Session, gerendert als
  `<meta name="csrf-token">`, vom Client als Header `X-CSRF-Token` gesendet, serverseitig
  mit `hash_equals()` geprüft.
- **Kein Self-Signup.** Benutzeranlage ausschließlich über die Admin-Oberfläche.
- **Brute-Force-Bremse** am Login über die Tabelle `login_attempts`: max. 5 Fehlversuche je IP
  in 15 Minuten, danach temporäre Sperre; bei Erfolg werden die Einträge der IP gelöscht,
  Einträge älter als 24 h aufgeräumt. (Optionale zusätzliche Härtung über fail2ban am
  Reverse-Proxy — außerhalb des App-Umfangs.)
- **Rollen:** ein `is_admin`-Flag. Admin pflegt Übungen/Pläne/Benutzer; normale Benutzer
  sehen ihren Plan und protokollieren Gewichte.
- **Zugriffskontrolle / IDOR-Schutz:** Ein normaler Benutzer darf ausschließlich **seine
  eigenen** Pläne, Einheiten und Logs sehen und ändern. Jede ID-basierte Anfrage (Plan, Log,
  Einheit) prüft serverseitig die Eigentümerschaft (`user_id`-Abgleich); Admin-Funktionen nur
  mit gesetztem `is_admin`.
- **Upload-Sicherheit:** Nur JPEG/PNG; der MIME-Typ wird serverseitig aus dem **Inhalt**
  bestimmt (`finfo_open(FILEINFO_MIME_TYPE)`, nicht die Dateiendung), die Datei via GD
  neu enkodiert/normalisiert und unter einem **zufälligen Dateinamen** im `uploads`-Volume
  abgelegt. Größenlimit 5 MB. Zusätzlich wird ein Thumbnail (max. 320 px Kante) nach der
  Konvention `<zufallsname>_thumb.jpg` erzeugt.
  Der Webserver muss so konfiguriert sein, dass in `/uploads` **keine Skriptausführung**
  möglich ist (rein statische Auslieferung, PHP-Handler dort deaktiviert). Die Auslieferung
  läuft über `image.php` mit Path-Jail (`realpath`-Prefix-Check + `basename()`).
- **SQL-Injection:** ausschließlich PDO Prepared Statements. Alle Ausgaben kontextgerecht
  escapen (XSS-Schutz).

---

## 6. Admin-Weboberfläche

Nur für Benutzer mit `is_admin`.

**Der Einstieg ist `admin.php`** (seit `1.2.2`): eine Seite mit vier Kacheln zu
Übungen (§6.3), Muskelgruppen (§6.2), Benutzern (§6.1) und Wartung (§6.5), jede mit
einem Satz dazu, wofür sie da ist. Sie hat **kein eigenes Können** und verlinkt nur —
Funktionen dort wären eine fünfte Verwaltungsoberfläche neben den vieren, die es gibt.

Der Grund ist die Kopfzeile am Handy: Acht Punkte nebeneinander sind dort unbrauchbar, und
die vier hinteren braucht im Studio niemand. Sie liegen jetzt hinter **einem** Punkt
*Admin*, der auch dann hervorgehoben bleibt, wenn man auf einer seiner Unterseiten steht —
sonst wirkte die Kopfzeile dort, als stünde man nirgends. **Splits und Pläne bleiben oben**:
Sie sind seit `1.2.0` keine Adminsache mehr, jeder Benutzer verwaltet seine eigenen.

**6.1 Benutzerverwaltung**
- Benutzer anlegen (Name, Passwort, Admin-Flag), **umbenennen**, Passwort zurücksetzen,
  Benutzer löschen (Löschverhalten siehe §4.1). Ein zurückgesetztes Passwort setzt
  `must_change_password = 1`.
- Der letzte verbliebene Admin kann weder gelöscht noch degradiert werden.
- **Benutzernamen sind unabhängig von der Groß-/Kleinschreibung eindeutig.** `Oliver` und
  `oliver` sind derselbe Name; die Anmeldung fragt ebenfalls nicht nach der Schreibweise.
  Ohne das ließen sich zwei Konten anlegen, die in keiner Liste auseinanderzuhalten sind,
  und die Handytastatur mit ihrer selbsttätigen Großschreibung wäre die häufigste
  Anmelde-Fehlerquelle. Technisch: `UNIQUE INDEX ... COLLATE NOCASE` plus `COLLATE NOCASE`
  in der Anmeldeabfrage. Grenze: Das faltet nur ASCII, `Müller`/`müller` bleiben getrennt.
- **Umbenennen kennt keine dieser Ausnahmen** — auch das eigene Konto und der letzte Admin
  sind umbenennbar. Ein Name ändert an den Rechten nichts, und wer umbenennt, kennt den neuen
  Namen und sperrt sich damit nicht aus. Der Benutzer meldet sich danach mit dem neuen Namen
  an; angemeldete Geräte bleiben angemeldet, weil die Tokens an der `user_id` hängen.
- **Konten sperren und wieder freigeben.** Ein Admin kann jedes andere Konto sperren —
  normale Benutzer **und** Admins. Ein gesperrtes Konto kommt nicht mehr herein: weder
  über Benutzername und Passwort noch über ein angemeldetes Gerät, und eine laufende
  Sitzung endet beim nächsten Seitenaufruf. **Sämtliche Daten bleiben erhalten** — Pläne,
  Einheiten, Protokoll, Sätze; ein Entsperren stellt den vorherigen Zustand vollständig
  wieder her, nur anmelden muss sich der Benutzer neu (die Remember-Me-Tokens werden beim
  Sperren widerrufen).
  - **Das eigene Konto ist ausgenommen** — dieselbe Begründung wie beim Löschen und beim
    Adminrecht: Die Sperre wirkt sofort, man wäre also im selben Klick ausgesperrt und
    könnte sie nicht zurücknehmen.
  - Eine Letzter-Admin-Regel braucht es hier **nicht**: Sperren darf nur ein angemeldeter
    Admin, und sich selbst kann er nicht sperren — es bleibt also zwangsläufig immer
    mindestens ein aktiver Admin übrig.
  - Der Anwendungsfall ist ein Konto, das nur zeitweise gebraucht wird — etwa ein
    Wartungszugang, der zwischen zwei Arbeitsrunden nichts zu suchen hat. Löschen und neu
    anlegen wäre der falsche Weg, weil es die Daten mitnimmt.
  - Sichtbar in der Benutzerliste als Abzeichen **Gesperrt** samt Sperrdatum.

**6.2 Muskelgruppen**
- Liste einsehen, erweitern, umbenennen und sortieren (Standardwerte vorgeseedet).
- Löschen nur, wenn keine Übung die Gruppe referenziert (§4.1).
- Diese Pflege geht dem Anlegen von Übungen **voraus**: Die Übungsmaske bietet ausschließlich
  hier definierte Gruppen an und erlaubt kein Anlegen neuer Gruppen nebenbei. Die Liste zeigt
  je Gruppe die **Anzahl zugeordneter Übungen** (aktiv/archiviert getrennt), damit erkennbar
  ist, was benutzt wird und was leer steht.

**6.3 Übungsverwaltung**
- CRUD für Übungen mit Feldern: Name (deutsch + englisch), Muskelgruppen (siehe unten),
  Trainingsgerät, Schwerpunkt, Beschreibung, Bild.
- **Muskelgruppen-Auswahl per Checkboxen, mehrfach möglich.** Die Maske zeigt alle in §6.2
  definierten Gruppen als Checkbox-Liste in deren `sort_order`. Kein Dropdown — bei
  Mehrfachauswahl ist eine Checkbox-Liste die passende Bedienform, und alle verfügbaren
  Gruppen sind auf einen Blick sichtbar.
  - **Zwei getrennte Spalten:** links ein **Radiobutton „primär"**, daneben eine
    **Checkbox „sekundär"**, dahinter der Name. Beide Spalten sind direkt anklickbar.
  - **Hauptgruppen mit Untergruppen sind nicht wählbar.** Sie erscheinen als
    Gliederungsüberschrift ohne Bedienelemente, die Untergruppen eingerückt darunter.
    Sonst gäbe es zwei Wege für dieselbe Aussage (`Arme` oder `Trizeps`) und eine
    uneindeutige Datenlage — und der Zusammenhang zur Tauschregel bliebe unsichtbar. Eine
    Hauptgruppe **ohne** Untergruppen bleibt wählbar, sonst ließe sich für sie überhaupt
    keine Übung anlegen.
  - **An der Maske steht, wie der Tausch funktioniert:** „Getauscht wird innerhalb der
    Hauptgruppe — für eine Übung an *Trizeps* kommt alles unter *Arme* infrage." Ohne diesen
    Satz überrascht das Verhalten, weil man in der Maske nur die Untergruppe sieht.
  - Die Primärgruppe ist **Pflicht** und durch den Radiobutton von selbst eindeutig — eine
    neue Wahl hebt die alte auf.
  - Sekundär sind beliebig viele. Die Zeile, die gerade primär ist, hat ihre
    Sekundär-Checkbox **gesperrt und geleert**: Eine Gruppe kann nicht zugleich primär und
    sekundär sein. Der Server entfernt eine trotzdem mitgeschickte Doppelung.
  - Die Spaltenüberschriften heißen entsprechend „primär" und „sekundär", damit beim Anlegen
    klar ist, dass primär die Gruppe meint, **wegen der** man die Übung macht — nicht bloß
    die am stärksten beteiligte.
  - Beispiel: Bankdrücken → Brust primär, Trizeps sekundär; Klimmzüge → Rücken primär,
    Bizeps sekundär.
  - Neue Gruppen lassen sich hier **nicht** anlegen; dafür gibt es §6.2. Ein Link dorthin
    steht neben der Liste.
- **Trainingsgerät (Pflicht):** ein Auswahlfeld mit den sieben Werten aus §4 — Maschine,
  Multipresse, Kabel, Langhantel, Kurzhantel, Kettlebell, Körpergewicht. Kein Freitext, damit
  der Filter darauf verlässlich ist; kein Checkbox-Fächer wie bei den Muskelgruppen, weil es
  genau einen Wert aus sieben gibt. Angezeigt wird es als **Abzeichen mit Symbol und Text**
  — überall dort, wo eine Übung erscheint: Übungsliste, Planverwaltung, Handy-Ansicht,
  Tauschvorschläge und Übungsauswahl. Symbol *und* Text, weil ein Piktogramm allein
  verlangte, dass man sieben Zeichen auswendig kennt.

  Das Feld ist auch beim **Bearbeiten** Pflicht. Ein fehlender Wert kann dadurch nicht neu
  entstehen; wo doch einer fehlt — etwa nach dem Einspielen einer alten Sicherung —, zeigt
  die Zeile ein „Gerät fehlt"-Abzeichen.
- **Schwerpunkt (optional):** ein kurzes Textfeld für den Teilbereich innerhalb der
  Primärgruppe („oben", „mitte/unten", „stehend"). Wird als Abzeichen neben der Primärgruppe
  angezeigt — in der Übungsliste, in der Handy-Ansicht und in den Tauschvorschlägen. Bewusst
  optisch von den Muskelgruppen unterschieden, weil er **keine** Tauschklasse ist.
- Die Übungsliste zeigt je Übung die Primärgruppe hervorgehoben und die weiteren Gruppen
  dahinter, und lässt sich nach **Muskelgruppe und Trainingsgerät** filtern — einzeln und
  **kombiniert**, sodass „alle Kurzhantel-Übungen für den Bizeps" eine Abfrage ist. Der
  Gruppenfilter trifft Primär- **und** Sekundärgruppen; eine Hauptgruppe schließt ihre
  Untergruppen ein. Beide Filter bleiben beim Umschalten zwischen Aktiv/Archiviert/Alle
  erhalten und stehen mit den drei Zustandsknöpfen in **einer** Zeile.
- **Bild-Upload** gemäß §5 (Validierung, Re-Enkodierung, zufälliger Dateiname, Thumbnail).
- **Der einfarbige Rand wird beim Hochladen abgeschnitten** (seit `1.2.21`), bei Vollbild
  und Thumbnail gleichermaßen. Katalogbilder zeigen ein Motiv auf weißer Fläche, und der
  Rand ist selten mittig — im quadratischen Rahmen sieht man dann vor allem die Leere.
  - **Ein hochkantes Motiv wird im Thumbnail auf quadratisch aufgefüllt**, mit der
    erkannten Randfarbe. Damit schneidet die Anzeige ihm weder Kopf noch Füße ab;
    geschnitten wird, wenn überhaupt, nur links und rechts. Für solche Bilder ist die
    *Bildausrichtung* danach wirkungslos — es gibt nichts mehr zu schneiden. Das
    **Vollbild** bleibt unaufgefüllt: Es ist die Vorlage für jede spätere Ableitung, und im
    Dialog wird es ohnehin ganz gezeigt.
  - **Ohne einfarbigen Rand passiert nichts.** Weichen die vier Ecken farblich voneinander
    ab (ein Foto), bleibt das Bild unverändert. Ebenso, wenn nach dem Schnitt weniger als
    ein Fünftel der Kante übrig bliebe.
  - **Bestehende Bilder holt ein Wartungspunkt nach** (§6.5, *Bestandsbilder
    nachschneiden*) — oder man lädt sie erneut hoch, was die bessere Qualität ergibt.
- **Archivieren statt Löschen:** Übungen werden nicht hart gelöscht, sondern mit
  `archived = 1` archiviert. Archivierte Übungen erscheinen nicht mehr in Dropdowns und
  Tauschvorschlägen, die `workout_log`-Historie bleibt vollständig erhalten und
  referenzierbar. Ist eine Übung noch in einem Plan referenziert, wird beim Archivieren ein
  Hinweis mit der Liste der betroffenen Pläne angezeigt und bestätigt.

**Einsicht in archivierte Übungen (zwingend).** Archiviert heißt versteckt, nicht verschwunden —
der Admin muss jederzeit sehen können, was und wie viel deaktiviert ist:

- Die Übungsliste hat einen **Filter mit drei Zuständen**: *Aktiv* (Standard), *Archiviert*,
  *Alle*. Im Zustand *Alle* sind archivierte Zeilen sichtbar abgesetzt (gedimmt, mit Badge
  „archiviert").
- Der Filter zeigt die **Anzahl** je Zustand direkt an, z. B. „Aktiv (23) · Archiviert (4) ·
  Alle (27)". Die Zahl ist auch dann sichtbar, wenn gerade *Aktiv* gewählt ist — sonst merkt
  niemand, dass es überhaupt ein Archiv gibt.
- Jede archivierte Übung zeigt in der Liste zusätzlich:
  - **archiviert am** (`archived_at`),
  - **noch in Plänen referenziert?** — mit Namen der betroffenen Pläne und Benutzer,
  - **Anzahl der `workout_log`-Einträge**, also wie viel Historie an ihr hängt.

  Damit ist auf einen Blick erkennbar, ob eine Übung folgenlos archiviert wurde oder ob noch
  Pläne und Historie daran hängen.
- **Reaktivieren** setzt `archived = 0` und `archived_at = NULL`; die Übung erscheint danach
  wieder in Dropdowns und Tauschvorschlägen.
- **Endgültig löschen** ist nur zulässig, wenn die Übung **weder** in einem Plan referenziert
  wird **noch** `workout_log`-Einträge besitzt (also faktisch nie benutzt wurde — typischer
  Fall: Tippfehler beim Anlegen). Der Button ist sonst deaktiviert, mit Begründung als
  Tooltip. Beim Löschen wird auch die Bilddatei samt Thumbnail aus `uploads/` entfernt.

**6.4 Splits und Planverwaltung**

**Seit `1.2.0` steht über den Plänen der Workout-Split** (§4). Er ist die Klammer um eine
Rotation: „Push / Pull" sind zwei Pläne in einem Split, „Ganzkörper" die beiden A/B, und
„Upper / Lower / Push / Pull / Legs" eben fünf. Ein Benutzer führt beliebig viele Splits
nebeneinander und wechselt jederzeit zwischen ihnen; die Rotation läuft **je Split
getrennt** weiter (§7.6).

- **Es gibt zwei Seiten, und die Trennlinie ist der Besitz** (seit `1.2.23`):
  `splits.php` im Hauptmenü zeigt **nur den eigenen Bestand** — für jeden gleich, ein
  Admin sieht dort dasselbe wie jeder andere. Der Katalog und alles, was man an ihm
  tut, liegt auf `admin_splits.php` im Adminbereich.

  Bis `1.2.22` stand beides auf `splits.php`. Für einen normalen Benutzer war die
  halbe Seite ein Katalog mit genau einem erlaubten Knopf darin; für einen Admin war
  dieselbe Seite gleichzeitig Selbstbedienung und Verwaltung. Eine Seite, auf der zwei
  Rollen zwei verschiedene Dinge tun, beantwortet keine der beiden Fragen gut.
- **Eine Vorlage wird beim Auswählen kopiert, nicht verwendet.** „Zu mir kopieren" legt
  Split, Pläne und Positionen neu an; ab da gehört die Kopie dem Benutzer allein. Er
  darf darin tauschen (einmalig wie dauerhaft), Übungen entfernen, ergänzen und
  umsortieren — nichts davon wirkt auf die Vorlage oder auf andere Benutzer, und eine
  spätere Änderung des Admins an der Vorlage wirkt nicht auf die Kopie.
- **Weicht eine Kopie INHALTLICH von ihrer Vorlage ab, trägt ihre Karte den Knopf *Auf
  Vorlage zurücksetzen*.** Er bringt Pläne, deren Reihenfolge und Übungen auf den Stand
  der Vorlage; der **Name des Splits bleibt der des Benutzers**. Vorher fragt die
  Oberfläche nach und nennt dabei zweierlei: dass eigene Anpassungen verlorengehen, und
  dass Übungen, die es in der Vorlage nicht mehr gibt, ihren Bezug zwischen bereits
  protokollierten Sätzen und der Planposition verlieren. Während einer laufenden Einheit
  ist der Knopf gesperrt. Daneben steht ein Auswahlfeld **Vorlage**, mit dem sich die
  Herkunft setzen und lösen lässt.
- **Inhaltlich heißt: Anzahl und Reihenfolge der Pläne, und darin die Übungen** — ihre
  Anzahl, ihre Reihenfolge und welche es sind. **Die Plannamen zählen ausdrücklich nicht
  mit** (seit `1.2.23`): Wer seine Kopie „Tag A"/„Tag B" nennt, hat sein Training nicht
  geändert, und ein Knopf, der ihm genau diese Beschriftung wieder wegnehmen will, ist
  eine Falschmeldung. Solange nur die Namen abweichen, erscheint er deshalb **gar nicht**.
- **Erscheint er, sind die Plannamen eine eigene Frage.** Die Rückfrage ist ein Dialog mit
  dem Kästchen *Auch die Namen der Pläne auf die Vorlage zurücksetzen* — **unangekreuzt
  vorbelegt**, denn die eigene Beschriftung ist die, die der Benutzer selbst gewählt hat.
  Das Kästchen steht nur da, wenn die Namen wirklich auseinandergehen; sind sie ohnehin
  gleich, wäre es eine Frage ohne Folge. **Ein Plan, den es in der Kopie noch nicht gibt,
  entsteht in jedem Fall unter dem Namen der Vorlage** — es gibt keinen eigenen, den man
  behalten könnte.
- **Auf einer Vorlage trainiert niemand**, auch kein Admin. Sie ist Katalog, kein
  Bestand — sonst schriebe der erste dauerhafte Tausch in den Bestand aller.
- **Jede Karte hat einen Knopf *Als Text*.** Er zeigt den Split als reinen Text in einem
  Dialog, aus dem er sich in die Zwischenablage kopieren lässt — gedacht zum Einfügen
  anderswo, etwa in einen KI-Chat, eine Notiz oder eine Nachricht. Der Aufbau:

  ```
  Push/Pull

  Push
  1. Bankdrücken (Bench Press)
  2. Butterfly

  Pull
  1. Klimmzüge (Pull-ups)
  ```

  - **Splitname oben, Pläne durch Leerzeilen getrennt**, innerhalb eines Plans keine —
    so bleibt die Trennung eindeutig. Die Nummern tragen die Reihenfolge im Studio.
  - **Nur die Übungsnamen**, deutsch und englisch (§4), der englische in Klammern. Kein
    Bild, keine Ausführung, keine Beschreibung, kein Gerät, keine Muskelgruppe: Der Text
    soll den *Aufbau* zeigen. In einer reinen Namensliste liest sich `Name · English`
    wie zwei Übungen — deshalb hier die Klammer und nicht das Trennzeichen der
    Oberfläche.
  - **Ein leerer Plan und ein Split ohne Plan bekommen eine Zeile in Klammern**
    (`(noch keine Übung)`, `(noch kein Plan)`). Zwei Plannamen direkt untereinander
    sähen nach einem Fehler in der Ausgabe aus.
  - Der Text **steht fertig in der Seite** und wird nicht beim Antippen nachgeladen: Das
    Schreiben in die Zwischenablage muss in derselben Benutzeraktion geschehen wie der
    Klick, sonst verweigern strengere Browser den Zugriff. Nebenbei arbeitet der Knopf
    damit auch ohne Netz.
  - Lässt der Browser das Kopieren nicht zu, wird der Text **markiert** und ein Hinweis
    nennt `Strg+C`. Er steht sichtbar im Dialog — ein Fehlschlag darf nicht in einer
    Sackgasse enden.
  - Der Knopf steht an **jedem** Split, auch an einer Vorlage — auf `splits.php` an
    der eigenen Karte und im Kasten *Vorlage übernehmen*, auf `admin_splits.php` an
    der Katalogkarte. Man will einen Split auch besprechen können, bevor man ihn zu
    sich kopiert.
- **Wer darf was:** Jeder Benutzer legt eigene Splits an, benennt sie um, dupliziert und
  löscht sie und bearbeitet ihre Pläne — auf `splits.php`, und ein Admin dort genauso
  wie jeder andere. **Vorlagen** legt und bearbeitet nur ein Admin, und zwar auf
  `admin_splits.php`; ebendort darf er auch die Splits anderer Benutzer zum Bearbeiten
  aufrufen (Nachfolger des früheren Benutzer-Dropdowns).
- **`splits.php` zeigt drei Dinge, und alle drei gehören dem Aufrufer:**
  1. **Meine Splits** — der eigene Bestand mit allem, was Verwaltung ausmacht:
     trainieren, Pläne bearbeiten, umbenennen, duplizieren, löschen, dazu Herkunft und
     *Auf Vorlage zurücksetzen*. **Sichtbar ist immer genau eine Karte** (seit `1.3.2`):
     ohne Weiteres die des aktiven Splits, und im Kartenkopf steht statt des Namens ein
     **Auswahlfeld**, mit dem man zwischen den eigenen Splits wechselt. Umbenannt wird
     seither in einem überblendeten Dialog hinter *Umbenennen*. Hat jemand nur einen
     Split, steht dort der Name und kein Auswahlfeld — es gäbe nichts zu wählen.
  2. **Vorlage übernehmen** — ein Kasten mit **Auswahlfeld** über den Katalog, der
     Planvorschau der gewählten Vorlage und zwei Knöpfen: *Zu mir kopieren* und
     *Als Text*. Bewusst keine Kartenliste: Zu tun gibt es hier genau eines, und die
     Karten boten einem normalen Benutzer ohnehin nur diesen einen Knopf. Gibt es
     keine Vorlage, fehlt der Kasten ganz.
  3. **Split anlegen** — ein leerer eigener Split.
- **`admin_splits.php` („Vorlagen") hat drei Abschnitte**, und ihre Zuschnitte sind
  verschieden, weil die Handlungen es sind:
  1. **Vorlagen** — der Katalog, in derselben Darstellung wie *Meine Splits*: eine Karte
     offen, im Kopf das Auswahlfeld über den ganzen Katalog. Daran *Umbenennen* (Dialog,
     wie beim eigenen Split), *Vorlage bearbeiten*, *Duplizieren*, *Als Text*, *Löschen*. Darunter ein Formular **Vorlage anlegen** für einen leeren
     Katalogeintrag.
     - **Kein *Zu mir kopieren*.** Das ist keine Verwaltung, sondern Selbstbedienung,
       und dafür ist `splits.php` da — eine Handlung, ein Ort. Ein Admin geht denselben
       Weg wie jeder andere.
     - **Duplizieren legt eine zweite Vorlage an**, keine persönliche Kopie. Es ist der
       Weg zu einer **Variante im Katalog**: duplizieren, umbenennen, dann *Vorlage
       bearbeiten*. Ohne ihn führte der einzige Weg über eine persönliche Kopie und ein
       erneutes Veröffentlichen.
     - **Duplizieren fragt nicht nach dem Namen.** Die Kopie heißt `… (Kopie)`, und beim
       zweiten Mal `… (Kopie) (2)`. Der Grund: Beim Duplizieren weiß man noch gar nicht,
       wie die Variante heißen soll — das ergibt sich erst beim Bearbeiten. Eine
       Rückfrage, die man mit dem Vorschlag bestätigt, ist keine Frage, sondern ein Klick
       mehr. Umbenannt wird danach an der Karte.
  2. **Aus einem Benutzer-Split** — ein **Pulldown** über die persönlichen Splits
     **aller** Benutzer, die eigenen eingeschlossen, und darunter **genau ein** Knopf:
     *Als Vorlage übernehmen*. Bewusst keine Kartenliste und bewusst kein Umbenennen
     oder Löschen — das sind fremde persönliche Splits, und der Abschnitt soll nur einen
     einzigen Zweck erfüllen: aus einem davon eine Vorlage machen.
     - **Was inhaltlich schon im Katalog steht, erscheint nicht.** Verglichen wird
       allein der Inhalt — die Reihenfolge der Pläne und darin die der Übungen —,
       **ohne jeden Namen**. Wer eine Vorlage kopiert und sie umbenennt, hat kein neues
       Konzept, sondern dasselbe mit eigener Beschriftung; wäre der Name Teil des
       Vergleichs, füllte sich der Katalog mit Dubletten. Sobald jemand eine Übung
       tauscht oder die Planreihenfolge ändert, taucht der Split wieder auf.
     - Splits **ohne jeden Plan** erscheinen ebenfalls nicht — an ihnen ist nichts zu
       veröffentlichen.
     - Nach dem Veröffentlichen verschwindet der Split von selbst aus dem Pulldown:
       Seine Signatur entspricht jetzt der neuen Vorlage.
  3. **Splits anderer Benutzer** — ein Pulldown über die persönlichen Splits der
     anderen, nach Besitzer gruppiert, und ein Knopf *Pläne bearbeiten*, der auf
     `plans.php?split=…` führt. Er trägt den einen Fall, den ein Admin zusätzlich
     darf, und ist seit `1.2.23` der **einzige** Weg dorthin.
     - **Diese Liste ist ungefiltert**, anders als die darüber, und der Unterschied ist
       die Frage: Dort geht es darum, was sich noch veröffentlichen *lässt*, hier
       darum, was es *gibt*. Ein Split ohne Plan ist nichts zum Veröffentlichen, aber
       sehr wohl etwas zum Bearbeiten — er ist sogar der wahrscheinlichste Grund,
       warum jemand um Hilfe bittet. Zwei Pulldowns mit verschiedenem Inhalt sind
       deshalb richtig; ein einziges, das mal das eine und mal das andere meint, wäre
       keins.
- **Veröffentlichen ist eine Kopie**, kein Verschieben: Der Benutzer behält seinen Stand
  unverändert und wird von späteren Änderungen an der Vorlage nicht berührt. Der Name im
  Katalog wird beim Veröffentlichen eigens erfragt — er ist eine öffentliche Beschriftung
  und muss nicht heißen wie der private Split.
- Die Planverwaltung selbst heißt seit `1.2.0` **`plans.php`** (vorher `admin_plans.php`)
  und bearbeitet **einen Split**, gewählt über das Feld *Angezeigter Split*. Das Feld
  führt seit `1.2.23` **nur die eigenen Splits** — auch für einen Admin. Ein unbekannter
  oder fremder `?split=`-Wert fällt auf den aktiven Split zurück; die Liste selbst ist
  der IDOR-Schutz, wie `$erlaubt` in `index.php`.
- **Ein ausdrückliches `?split=` außerhalb dieser Liste ist erlaubt, wenn der Aufrufer
  den Split bearbeiten darf** — also ein Admin auf einer Vorlage oder im Bestand eines
  anderen. Beide Wege dorthin führen über `admin_splits.php`. Der Split kommt dann als
  **eigener Eintrag zur Liste dazu** und wird ausgewählt: Ein Auswahlfeld muss seinen
  eigenen Zustand anzeigen können, sonst stünde dort ein anderer Name als in der Seite
  darunter. Dazu erscheint eine Hinweiskarte (*Das ist eine Vorlage* bzw. *Das ist der
  Split eines anderen Benutzers*) mit dem Rückweg in den Adminbereich.
- **Ohne `?split=` steht das Feld auf dem Split, der für den Aufrufer gerade aktiv ist**
  (seit `1.2.12`) — also auf dem, mit dem er trainiert, und nicht auf dem ersten seiner
  Liste. Wer über die Kopfzeile auf *Pläne* geht, hat den im Sinn. Ein ausdrückliches
  `?split=` schlägt das weiterhin; über diesen Weg führt *Pläne bearbeiten* auf
  `splits.php`. Hat der Benutzer noch keinen aktiven Split, bleibt der erste erlaubte.
- Pro Split **beliebig viele** Pläne — mindestens einer, nach oben ohne feste Grenze.
  Zwei Pläne (Ober-/Unterkörper) und drei (Push/Pull/Legs) sind die erwarteten Fälle;
  die Rotation in §7.6 ist für jede Anzahl definiert.
- **Die Reihenfolge der Pläne ist fachlich bedeutsam**, nicht bloß Anzeigesache: Sie legt
  die Rotationsreihenfolge **innerhalb des Splits** fest (§7.6). Push → Pull → Legs muss
  sich deshalb sortieren lassen, mit denselben Mitteln wie die Übungen innerhalb eines
  Plans. Die Reihenfolge der *Splits* untereinander ist dagegen reine Anzeigesache.
- Übungen zu einem Plan hinzufügen/entfernen und **in Reihenfolge sortieren**.
- **Eine Übung in den Nachbarplan verschieben** (seit `1.2.4`): Neben den Pfeilen ↑ ↓, die
  innerhalb des Plans sortieren, stehen ⇈ und ⇊ — sie schieben die Übung in den Plan
  *darüber* bzw. *darunter*, also entlang derselben Reihenfolge, die auch die Rotation
  bildet. Im ersten Plan ist ⇈ deaktiviert, im letzten ⇊ (mit Begründung als Tooltip);
  deaktiviert und nicht weggelassen, damit die Knopfzeile nicht je nach Plan anders breit
  ist.
  - **Am Rand der Liste gilt dasselbe für die einfachen Pfeile** (seit `1.2.19`): Die
    oberste Übung eines Plans hat kein ↑, die unterste kein ↓, der erste Plan kein ↑ und
    der letzte kein ↓. Ein Pfeil, der nichts tut, ist kein Angebot. Weil das Umsortieren
    **innerhalb** eines Plans als einziger Vorgang der Seite ohne Neuladen auskommt, muss
    der Browser die Sperre nach jedem Tausch selbst nachziehen.
  - **Den Zielplan bestimmt der Server** aus der Sortierung; der Client schickt nur die
    Richtung. Eine mitgeschickte Ziel-ID wäre eine weitere Prüfung, die man vergessen kann.
  - Die Übung landet **am Ende** des Zielplans: Wo sie im einen Plan stand, sagt nichts
    darüber, wo sie im anderen hingehört.
  - Steht sie dort bereits, wird abgelehnt — dieselbe Regel wie beim Hinzufügen.
- **Ein Planname wird beim Umbenennen sofort auch in der Rotationsvorschau nachgezogen**
  (seit `1.2.4`). Die Seite lädt dabei bewusst *nicht* neu — Umbenennen ist der eine
  Vorgang, der die Seitenstruktur nicht ändert —, aber zwei Stellen auf einem Bildschirm
  dürfen sich nicht widersprechen.
- **Die Rotationsvorschau zeigt die Reihenfolge und sonst nichts** (seit `1.2.18`). Bis
  dahin hob sie den nächsten vorgeschlagenen Plan farbig hervor und nannte ihn in einem
  Satz darunter. Auf dieser Seite baut man den Plan **um**; wo man in der Rotation gerade
  steht, beantwortet die Trainingsansicht (§7.6) — dort wird die Auskunft gebraucht und
  dort steht sie. Ohne Plan entfällt der Abschnitt ganz.
- **Die Gliederung ist dieselbe wie auf `splits.php`** (seit `1.2.18`): Überschrift über
  dem Kasten, dann der Bestand, darunter der Kasten zum Anlegen. Eine Überschrift *im*
  Kasten bringt ihren oberen Abstand mit und sieht dort wie eine Leerzeile aus.
- **Die Nummer einer Position sitzt in der oberen linken Ecke** (seit `1.2.19`), als
  Kästchen mit abgerundeten Ecken auf einer Höhe mit dem deutschen Namen — dieselbe
  Anordnung wie im Training (§7.3), nur mit vollem Rahmen: Hier steht sie frei über dem
  Thumbnail und nicht am Rand einer Karte. Als eigene Spalte davor nahm sie dem Bild und
  dem Text Breite weg, die am Handy fehlt.
- **Hinzugefügt wird über eine überlagerte Auswahl**, nicht über ein Dropdown mit allen
  aktiven Übungen: Ein Knopf *Übung hinzufügen* öffnet einen Dialog, der sich nach
  **Muskelgruppe und Trainingsgerät** filtern lässt — einzeln oder kombiniert, mit denselben
  Regeln wie der Filter in §6.3. Ein ungefiltertes Auswahlfeld ist am Handy unbedienbar,
  sobald der Übungsbestand dreistellig wird, und es trug den ganzen Bestand je Plan im HTML.
  - **Die beiden Auswahlfelder schränken sich gegenseitig ein.** Nach der Wahl einer
    Muskelgruppe stehen unter *Trainingsgerät* nur noch die Geräte, für die es zu dieser
    Gruppe auch eine Übung gibt — und umgekehrt. Ein Filterwert, der zuverlässig eine leere
    Liste erzeugt, ist schlechter als kein Filterwert.
    - Maßgeblich: **Jede der beiden Listen wird ohne ihren eigenen Filter berechnet**, nur
      mit dem des anderen Feldes. Sonst bliebe nach der Wahl von „Kurzhantel" nur noch
      „Kurzhantel" im Gerätefeld übrig, und man käme aus der Einschränkung nicht mehr
      heraus, ohne zuerst die Muskelgruppe zurückzusetzen.
    - Eine **Hauptgruppe bleibt wählbar, sobald eine ihrer Untergruppen passt** — sie
      schließt ihre Untergruppen ein und hätte also Treffer.
    - Wird eine Wahl durch die andere ungültig (erst *Kurzhantel*, dann eine Muskelgruppe
      ohne Kurzhantelübung), springt das betroffene Feld auf *alle* zurück und die Liste
      wird neu geholt — statt einer garantiert leeren Anzeige.
    - Archivierte Übungen zählen dabei nicht mit: Sie sind als Auswahl ohnehin gesperrt.
  - Ein Treffer wird **wie ein Tauschvorschlag** dargestellt (Bild, Name, Muskelgruppen,
    Trainingsgerät, Schwerpunkt) — es ist dieselbe Information, nur mit einem anderen Knopf.
  - Übungen, die **bereits im Plan stehen**, bleiben sichtbar und sind lediglich gesperrt.
    Herausgefiltert wüsste man nicht, ob die gesuchte Übung fehlt oder längst dabei ist.
  - **Steht die Übung in einem anderen Plan DESSELBEN Splits**, sagt es ein zurückgenommener
    Hinweis **direkt links neben dem Knopf**: *Schon in Ganzkörper A* (seit `1.2.7`). Er **verbietet
    nichts** — dieselbe Übung darf bewusst in mehreren Plänen stehen —, sondern beantwortet
    die Frage, die man sich beim Füllen des zweiten Plans stellt: Wer *Ganzkörper A* und
    *Ganzkörper B* abwechselt und nicht zweimal dasselbe trainieren will, sieht ohne
    Umschalten, welche Übung im Split noch nicht vorkommt. Steht sie in mehreren, werden
    alle genannt.
    - Der gesperrte Knopf deckt das **nicht** mit ab: Er spricht nur über den Plan, in den
      gerade eingefügt wird.
    - **Der Hinweis steht in ALLEN drei Vorschlagslisten** (seit `1.2.9`): Übungsauswahl,
      Tauschfenster der Planverwaltung und Tauschfenster im Training. Er gehört zur Übung
      und nicht zum Knopf darunter; in nur einer Liste wäre er dort, wo man ihn braucht,
      gerade nicht da — beim Tauschen sucht man einen Ersatz und will am wenigsten die
      Übung erwischen, die im nächsten Plan ohnehin ansteht.
  - Archivierte Übungen erscheinen nicht (§6.3).
- **Sperre bei offener Einheit:** Hat der betroffene Benutzer eine offene Einheit, ist die
  Planbearbeitung blockiert (Hinweis anzeigen). Sonst würde sich `n` in der laufenden
  Fortschrittsanzeige „x/n" mitten im Training verschieben. Ebenso gesperrt sind der
  **Splitwechsel** und das **Löschen eines Splits**.
  **Vorlagen sind davon ausgenommen** und das ist kein Schlupfloch: Auf ihnen trainiert
  niemand, und wer sie kopiert hat, hängt nicht mehr an ihnen.

**6.5 Wartung & Backup**
- Eigene Seite `maintenance.php`, nur für Admins, nach dem Muster aus
  `Speisekarte/doku/maintenance_anleitung.md`.
- Aktionen: `backup`, `restore`, `upload`, `delete_backup`, `vacuum`, `integrity`,
  `optimize`, `checkpoint`, `images_orphans`, `images_cleanup`, `images_recut_check`,
  `images_recut`.
- **Bestandsbilder nachschneiden** (seit `1.2.21`): Holt den Randschnitt aus §6.3 für
  Bilder nach, die vor dieser Version hochgeladen wurden. Prüfen und Ausführen sind zwei
  Knöpfe; die Prüfung nennt jede betroffene Übung mit alter und neuer Größe.
  - **Die nachgeschnittenen Bilder bekommen neue Dateinamen**, und `exercises.image_path`
    wandert mit. Anders geht es nicht: `image.php` liefert Bilder mit
    `Cache-Control: immutable` und einem Jahr Haltbarkeit aus — der Dateiname ist der
    einzige Schlüssel, und wer dieselbe Datei überschreibt, sieht ein Jahr lang die alte.
  - **Reihenfolge wie beim Bildwechsel:** erst die neuen Dateien schreiben, dann die
    Datenbank, danach die alten löschen.
  - **Wiederholbar.** Ein zweiter Durchgang zieht höchstens einzelne Bilder um wenige Pixel
    nach, ein dritter findet nichts mehr.
  - Zurückholen lässt sich nichts — die einzige Kopie steckt in einer Sicherung *mit
    Bildern*.
- **Die Seite ist nach Häufigkeit gegliedert, nicht nach Gewicht** (seit `1.2.20`): Zustand,
  Datenbankpflege, Übungsbilder, dann alles zur Sicherung. Die Pflegepunkte ändern nichts am
  Datenbestand und schaut man im Vorbeigehen an; das Einspielen einer Sicherung ist der
  folgenreichste Vorgang der ganzen App und steht deshalb nicht zwischen ihnen.
- **Verwaiste Übungsbilder lassen sich suchen und entfernen** (seit `1.2.20`): Dateien in
  `uploads/`, zu denen es keine Übung mehr gibt.
  - **Im Normalbetrieb entstehen keine.** `api/exercises.php` löscht das alte Bild samt
    Thumbnail, sobald es ersetzt, entfernt oder die Übung endgültig gelöscht wird — und
    zwar **erst nach** dem erfolgreichen `UPDATE`. Karteileichen entstehen an den Rändern:
    beim Einspielen einer älteren Sicherung geht die Datenbank zurück, die Dateien nicht.
  - **Suchen und Löschen sind zwei Knöpfe.** Erst sieht man die Liste, dann entscheidet
    man; ein Knopf, der beides tut, lässt sich nicht zurückdrehen.
  - **Gefunden wird nur das eigene Namensmuster** (`<32 Hex>.jpg`, `<32 Hex>_thumb.jpg`).
    Was jemand von Hand nach `uploads/` gelegt hat, wird weder gemeldet noch angefasst.
    Das Thumbnail gilt als benutzt, sobald das Original benutzt ist.
  - **Dateien unter einer Stunde bleiben außen vor.** Ein Upload schreibt die Datei, bevor
    die Übung in der Datenbank steht — genau dort würde ein gleichzeitiges Aufräumen ein
    Bild löschen, das gerade entsteht.
  - **Die Liste kommt nicht vom Client.** Der Löschlauf ermittelt sie selbst neu; es geht
    kein Dateiname über die Leitung, auch nicht der aus der Anzeige von vorhin.
- Backup als ZIP in zwei Varianten: **vollständig** (DB + Bilder) und **ohne Bilder**.
- **Die Datenbankkopie entsteht über `VACUUM INTO`, nie als Dateikopie.** Im WAL-Modus ist
  ein `cp` der `.db` ohne die `-wal`/`-shm`-Dateien im besten Fall veraltet, im schlechteren
  unbrauchbar. `VACUUM INTO` liefert eine in sich geschlossene, bereits kompaktierte Kopie,
  während die App weiterläuft — kein Anhalten nötig.
- Ein Restore prüft, **bevor** irgendetwas überschrieben wird: Die Sicherung wandert in einen
  Zwischenordner, dort laufen `PRAGMA integrity_check` **und** ein Abgleich der erwarteten
  Tabellen (sonst ließe sich eine fremde, in sich gesunde SQLite-Datei einspielen). Erst
  danach wird der aktuelle Stand als Rückfallkopie beiseitegelegt und ersetzt; lässt sich die
  neue Datenbank nicht öffnen, kommt der alte Stand automatisch zurück.
- Ein Upload wird nach denselben Regeln geprüft, aber **nicht eingespielt** — das bleibt ein
  zweiter, bewusster Schritt.
- Download über `download_backup.php` mit `basename()`-Filter, Extension-Whitelist
  (`zip`, `db`) und `realpath`-Path-Jail. Nur für Admins: Im Archiv steckt der komplette
  Datenbestand samt Passwort-Hashes.
- Es werden höchstens 20 Sicherungen behalten; ältere entfallen beim Erstellen einer neuen.
- Die Seite zeigt oben den Zustand (Größen von Datenbank, WAL und Bildern, Zeilenzahlen,
  SQLite-Version) und **warnt sichtbar, wenn es keine Sicherung gibt oder die letzte älter
  als 14 Tage ist**. Das ist die wichtigste Aussage der Seite und gehört nicht in eine Liste
  versteckt.
- **Voraussetzung im Image:** PHP-Erweiterung `zip` (`libzip-dev`). Fehlt sie, entfällt die
  Bildersicherung und die Seite sagt das ausdrücklich.

---

## 7. Handy-Ansicht & Trainingslogik

**7.1 Login**
- Login-Formular mit „Angemeldet bleiben"-Option (Remember-Me aus §5).
- Ist `must_change_password` gesetzt, wird zwingend auf `password.php` geleitet.

**7.2 App-Start**
- Existiert für den Benutzer eine **offene Einheit** (§7.6), wird diese fortgesetzt und
  angezeigt — **unabhängig vom Datum**, inkl. bereits abgehakter Übungen. Damit bleibt ein
  über Mitternacht laufendes Training nahtlos erhalten.
- Existiert keine offene Einheit, schlägt die App den nächsten Plan **des gewählten
  Splits** vor (§7.6) und zeigt ihn startbereit an.
- **Hat der Benutzer noch keinen Split**, verweist die Seite auf `splits.php` — mit einem
  Satz, was ein Split ist, und dem Knopf „Split auswählen". Nicht „bitte beim
  Administrator nachfragen": Seit `1.2.0` kann sich jeder selbst einen aus dem Katalog
  ziehen.
- **Die Seitenüberschrift nennt den SPLIT**, in jedem Zustand der Seite und nicht den Plan
  (seit `1.2.10`; ohne gewählten Split heißt sie „Training"). **Welcher Plan gilt, sagt
  allein die Knopfreihe** darunter: alle Pläne des Splits, der vorgeschlagene blau
  markiert. Vorher stand der Planname an drei Stellen übereinander — als Überschrift, als
  Zeile „Vorgeschlagen: …" und als markierter Knopf; die mittlere ist ersatzlos entfallen.
  Die Knopfreihe steht auch dann, wenn der Split nur **einen** Plan hat: Sie ist nicht bloß
  Auswahl, sie nennt den Plan.
- **Der Startkasten in dieser Reihenfolge:** Planwahl → was der Start bedeutet →
  „Training starten" → „Aktuellen Split wechseln". Das Wechseln bleibt ein **Link** und wird
  kein Knopf: Es führt von der Seite weg und steht deshalb unter allem, was hier zu tun ist.
- **Läuft eine Einheit**, nennt deren Kasten den Plan („Pull A" läuft seit …). Dort gibt es
  keine Planwahl; ohne diese Angabe stünde nirgends mehr, welcher Plan gerade läuft.

**7.3 Plan-/Übungsansicht**
- Übungen des Plans in Reihenfolge. Pro Übung:
  - Name (deutsch, optional englisch), Muskelgruppen (Primärgruppe zuerst und hervorgehoben,
    weitere dahinter kleiner/gedämpft), Bild-Thumbnail (antippbar für Beschreibung/großes Bild),
  - **Gewichts-Eingabefeld, vorbelegt mit dem zuletzt protokollierten Gewicht** nach der
    Regel in §4 (leere Werte werden übersprungen; leer, falls noch nie ein Gewicht
    protokolliert wurde). Das Feld **darf leer bleiben** (z. B. Bauch/Dips ohne
    Zusatzgewicht). **Im Standardmodus werden keine Wiederholungen erfasst** — siehe §4.
  - **Im Expertenmodus** (§7.4, `users.expert_mode`) tritt an die Stelle dieses Feldes ein
    **aufklappbarer Satzblock**: je Satz Wiederholungen und Gewicht, dazu ein Knopf
    „+ Satz". Aufgeklappt ist genau **eine** Position — die aktive (siehe unten). Der
    Zustand wird bewusst nicht gespeichert; er wandert von selbst mit, während man den Plan
    durcharbeitet.
  - **Die Zeile „zuletzt …" nennt im Expertenmodus die ganze Satzfolge** —
    `zuletzt 3 Sätze (12×45 · 10×45 · 8×50)` statt einer einzelnen Zahl. Sie steht in
    derselben Form wie die Zusammenfassung im Satzblock darunter (erst wie viele, dann
    welche), damit sich beides ohne Umdenken vergleichen lässt: oben, was letztes Mal war —
    darunter, was gerade entsteht. Im Standardmodus bleibt es bei „zuletzt 45 kg".
- **Der Balken am linken Rand jeder Karte ist das Leitsystem beim Scrollen** — bei acht
  Übungen weiß man sonst nicht mehr, an welchem Gerät man steht:
  - **grün = hier bist du.** Die **aktive** Position.
  - **blau = erledigt.** Abgeschlossen, in der ruhigen Hausfarbe.
  - **orange (`#ff6600`) = übersprungen.** Noch offen, obwohl man schon weiter ist.
  - **grau = kommt noch.**

  Grün für „erledigt" wäre naheliegend und falsch herum: Grün zieht den Blick, und den soll
  das ziehen, was als Nächstes zu tun ist.

  **Aktiv ist nicht „die erste noch nicht erledigte".** Das geht schief, sobald man eine
  Übung auslässt, weil das Gerät besetzt ist: Die Markierung bliebe auf der ausgelassenen
  stehen, während man längst zwei Geräte weiter ist. Die Regel lautet, in dieser
  Reihenfolge:

  1. die Position, an der **gerade protokolliert wird** — sie hat einen Eintrag, ist aber
     noch nicht abgehakt (bei mehreren die spätere);
  2. sonst die **erste offene nach der letzten mit Eintrag**;
  3. sonst die **erste offene überhaupt** — der Rückweg zu dem, was ausgelassen wurde.

  **Orange ist jede offene Position vor der aktiven.** Beispiel: Push, die Dip-Maschine ist
  fertig, die Beinpresse besetzt, also geht es mit dem Beinstrecker weiter. Sobald dort der
  erste Satz steht, ist der Beinstrecker grün und die Beinpresse orange — sie ist nicht
  „kommt noch", sondern „steht noch aus".
- **Aktive Position und aufgeklappter Satzblock gibt es nur während eines Trainings.** Wer
  den Plan bloß anschaut, sieht eine ruhige Liste — beides wäre sonst eine Aussage über
  einen Ablauf, der noch gar nicht läuft. **„Training starten" öffnet den Block der ersten
  Übung und scrollt dorthin**; dasselbe passiert beim Abhaken einer Übung für die nächste.
  Dabei wird die Höhe des Leisten-Stapels abgezogen — er klebt oben am Rand und verdeckte
  sonst genau den Übungsnamen der angesprungenen Karte.
  - **„Erledigt"-Häkchen**,
  - Aktion **„Übung tauschen"** (§7.5).
- **Die Nummer der Übung sitzt in der oberen linken Ecke der Karte** (seit `1.2.15`). Nur
  die Ziffer, ohne Wort — sie beantwortet „die wievielte ist das": die Ansage im Studio und
  der Bezug zur Reihenfolge im Plan.

  **Gezeichnet werden nur zwei Linien:** rechts der Zahl von oben nach unten, dann unter ihr
  nach links bis an den farbigen Balken. Die anderen beiden Seiten des Rechtecks hat die
  Karte schon — ihr oberer Rand und der Balken links. Ein vollständiges Kästchen zöge dort,
  wo ohnehin eine Linie ist, eine zweite daneben.

  Wo die beiden Linien zusammentreffen — **unten rechts an der Zahl** —, ist die Ecke
  **abgerundet** (seit `1.2.17`, Radius wie bei jeder anderen Box, jedem Knopf und jeder
  Auswahl). Die übrigen Ecken des
  wahrgenommenen Kästchens sind keine: Oben links rundet die Karte selbst, oben rechts und
  unten links trifft je eine Linie auf den Kartenrand bzw. den farbigen Balken.

  **Die Ecke, weil sie in beiden Modi frei ist.** Mittig in der Aktionszeile wäre der
  naheliegende Platz und im Expertenmodus auch richtig — im Standardmodus sitzt dort das
  Gewichtsfeld, und zwei Dinge in einer Mitte sind keine Mitte mehr.

  Sie ist **Anzeige, kein Bedienelement** — kein Knopf, kein Tippziel, und ohne Zugriff auf
  Zeigereignisse: Das Bild darunter ist ein Knopf (Beschreibung und großes Bild), und die
  Ecke darf ihm keine Fläche wegnehmen, ausgerechnet dort, wo man beim Zielen zuerst
  hintippt. Die Zahl selbst steht im Innenabstand der Karte und damit nie über dem Bild;
  nur die beiden Linien streifen dessen abgerundete Ecke.
- **Die Trainingsleiste** und der **„Training beendet"-Button** (§7.6) sind während einer
  offenen Einheit sichtbar. Die Leiste nennt seit `1.2.15` zwei Zahlen, in dieser
  Reihenfolge:

  | | Was zählt | |
  |---|---|---|
  | **x/n beendet** | abgehakt (`done = 1`) von allen Planpositionen | der Fortschritt |
  | **n übersprungen** | offene Positionen **vor** der aktiven — die orangen Balken | die Merkliste |

  Der Fortschritt steht **vorn** (seit `1.2.16`): Er ist das, was man dauernd abliest. Die
  übersprungenen sind die Ausnahme und dürfen dahinter.

  **Sie ergänzen sich nicht zur Anzahl der Übungen, und das ist Absicht** — sie beantworten
  zwei Fragen. Was dazwischen liegt (offen und noch nicht drangewesen), braucht keine eigene
  Zahl: Es ist der Rest, und danach fragt im Studio niemand. Der Bruch spart dafür die
  Breite, die eine dritte Gruppe gekostet hätte.

  **Die übersprungenen kommen aus derselben Rechnung wie der orange Balken** (§7.3) und
  nicht aus einer eigenen Zählung — die Leiste nennt damit genau die Übungen, die man in der
  Liste auch orange sieht. Sind es keine, steht dort „0 übersprungen" in gedämpftem Grau;
  ab eins trägt die Zahl das **Signalorange** der Balken.

  Bis `1.2.14` stand dort „x/n erledigt · y offen".

**7.4 Gewichts-Logging & „Erledigt"**
- Das Gewicht muss **nicht** jedes Mal neu eingegeben werden — Default ist der letzte Wert;
  der Benutzer passt es nur bei Änderung an. „Erledigt" funktioniert **auch ohne Gewichtswert**.
- Beim Setzen von „Erledigt" wird ein `workout_log`-Eintrag für die **aktive Einheit** +
  Planposition geschrieben bzw. aktualisiert (`session_id`, `plan_exercise_id`, `exercise_id`,
  Gewicht, `performed_at`). Ein Eintrag pro Einheit + Planposition.
- **Nach dem Abhaken ist das Gewichtsfeld schreibgeschützt.** Wer den Wert korrigieren will,
  entfernt das Häkchen, ändert ihn und hakt neu ab. Damit gibt es **einen** Mechanismus
  statt zweier — genau so ist der Übungstausch geregelt (§7.5). **Der Server weist einen
  abweichenden Wert auf einer abgehakten Position ab** (409); das schreibgeschützte Feld ist
  nur die Bequemlichkeit davor.

  Der Preis: Wer abwählt, ändert und dann vergisst, wieder abzuhaken, hat für diese Position
  nichts protokolliert. Das ist aber sichtbar — das Häkchen fehlt und „beendet" steht
  niedriger.
  Kein stiller Verlust. Eine eigene „Wert nachträglich speichern"-Aktion gibt es
  dementsprechend **nicht**.
- **Ab-wählen** von „Erledigt" (versehentliches Häkchen) löscht den zugehörigen
  `workout_log`-Eintrag dieser Einheit wieder.
- **Wiederherstellung des Erledigt-Status:** Beim Laden gilt eine Planposition als erledigt,
  wenn für die aktive Einheit + Position bereits ein Log-Eintrag existiert. Der Fortschritt
  geht also nicht verloren, wenn das Handy zwischendurch geschlossen wird.
- **Fehlerfall:** Schlägt ein Speichern fehl, bleibt das Häkchen sichtbar unbestätigt und ein
  Wiederholen-Button erscheint (§2).

- **Expertenmodus: Sätze einzeln erfassen** (seit `1.1.0`, je Benutzer abschaltbar über
  `users.expert_mode`, umgeschaltet auf der Kontoseite §7.7). Statt eines Gewichts je Übung
  wird jeder Satz mit Wiederholungen und Gewicht eingetragen — 12×40, 10×40, 9×45. Der
  Standardmodus bleibt unverändert bestehen.

  - **Ein Tipp je Satz.** `+ Satz` legt eine Zeile an, die schon gefüllt ist. **Woher die
    Vorbelegung kommt, wählt jeder Benutzer selbst** (`users.satz_vorlage`, Codeliste
    `SATZ_VORLAGE` in `lib/training.php`, eingestellt auf der Kontoseite neben dem
    Expertenmodus):

    | | Satz 1 | Satz 2 | Satz 3 |
    |---|---|---|---|
    | `gleicher_satz` — „Wie beim letzten Training" *(Vorgabe)* | 12×40 | 10×40 | 9×45 |
    | `letzter_satz` — „Wie der Satz davor" | 12×40 | 12×40 | 12×40 |

    *(Beispiel: Die letzte Einheit dieser Übung war 12×40 · 10×40 · 9×45.)*

    **`gleicher_satz`** ist schnell für eine feste Satzfolge: Wer 12/10/9 gewohnt ist,
    bekommt beim dritten Antippen 9 vorgeschlagen und nicht 10. **`letzter_satz`** ist
    schnell für alle, die sich herantasten — eine Korrektur trägt sich von selbst weiter.

    Gemeinsam ist beiden: **Der erste Satz kommt immer vom letzten Mal**, der Unterschied
    beginnt ab Satz 2. Reicht die Vorlage nicht (heute mehr Sätze als letztes Mal), gilt der
    vorherige Satz von heute; gibt es gar keine Vorlage, das zuletzt bekannte Gewicht dieser
    Übung mit leerem Wiederholungsfeld. Der Vorschlag steht im Knopf („+ Satz (9 × 45)"),
    damit vor dem Tippen sichtbar ist, was entsteht.

    Die Auswahl ist **im einfachen Modus sichtbar, aber abgeblendet** — dort gibt es keine
    Sätze. Versteckt wird sie nicht: Eine Einstellung, die nur unter einer Bedingung
    erscheint, findet niemand. Ein Umschalten ist **auch während eines laufenden Trainings
    erlaubt**, anders als beim Expertenmodus; die Begründung steht in §7.4 weiter unten.
  - **Wiederholungen mit −/+**, Gewicht als Textfeld. Die Wiederholungen weichen fast immer
    nur um ±1 vom Vorschlag ab — dafür sind zwei große Tippziele schneller und sicherer als
    die Zifferntastatur mit feuchten Fingern. Das Gewicht ändert sich selten und dann in
    beliebigen Sprüngen; ein fester Stepper-Schritt wäre bei Maschine (5 kg), Kurzhantel
    (2 kg) und Scheiben (1,25 kg) immer für etwas falsch.
  - **„Erledigt" ist ein Schalter und kein Nebeneffekt** (korrigiert in `1.1.1` nach dem
    ersten Studio-Einsatz). Sätze einzutragen heißt *nicht*, mit der Übung fertig zu sein —
    man will ja gleich den nächsten machen. Die `workout_log`-Zeile entsteht mit dem ersten
    Satz, aber mit `done = 0`; „x/n beendet" springt erst, wenn der Benutzer das Häkchen
    selbst setzt.

    Die erste Fassung hatte das andersherum („Erledigt folgt den Sätzen") und war damit
    falsch: Ab dem zweiten Satz stand die Übung als erledigt da, während der Benutzer noch
    am Gerät stand.
  - **Abhaken klappt den Satzblock zu und springt zur nächsten offenen Übung**, die sich
    dabei aufklappt. Im Studio hält man das Handy in der Hand und will nicht scrollen, um
    die Übung zu suchen, die ohnehin an der Reihe ist.
  - **Das Häkchen zurückzunehmen löscht die Sätze NICHT** — in beide Richtungen bleiben sie
    stehen. Sie dokumentieren, was tatsächlich gemacht wurde; sie zu löschen, weil jemand
    eine Fertig-Markierung zurücknimmt, wäre dieselbe Sorte Fehler wie ein Tausch auf eine
    bereits protokollierte Position. Weg sind sie über das ✕ der einzelnen Satzzeile; ist
    die letzte weg und kein Häkchen gesetzt, verschwindet die Position aus dem Protokoll.
  - **Abgehakt heißt festgeschrieben** (seit `1.1.4`): Wiederholungen und Gewicht sind dann
    schreibgeschützt, „+ Satz" und das ✕ jeder Zeile sind gesperrt. Geändert wird über
    Häkchen entfernen → korrigieren → neu abhaken — derselbe **eine** Mechanismus wie beim
    Gewichtsfeld im Standardmodus und beim Übungstausch (§7.5).

    In `1.1.0`/`1.1.1` waren die Sätze noch dauerhaft änderbar, mit der Begründung,
    Nachtragen sei der Normalfall. Das galt, solange der erste Satz die Übung **selbst**
    abhakte — dann hätte eine Sperre den zweiten Satz verhindert. Seit „Erledigt" ein
    Schalter ist (§7.4, `1.1.1`), ist das Argument hinfällig: Wer noch einen Satz machen
    will, hat schlicht noch nicht abgehakt.

    **Gesperrt wird serverseitig** (`api/log.php`); die ausgegrauten Felder sind nur die
    Bequemlichkeit davor. Drei Ausnahmen sind nötig und ausdrücklich gewollt: ohne
    bestehende Zeile gibt es nichts zu schützen, `done = false` ist der Weg zum Entsperren,
    und eine **unverändert durchgereichte Nutzlast geht durch** — sonst zerbräche die
    Idempotenz, auf der die Warteschlange steht.
  - **Eine noch leere Satzzeile wird nicht abgeschickt.** „+ Satz" legt bei einer Übung ohne
    Vorlage eine leere Zeile an — die ist zum Ausfüllen da. Mitgeschickt lehnte der Server
    sie zu Recht ab („Wiederholungen oder Gewicht angeben"), und die Zeile bekäme einen
    roten Rand samt „Erneut versuchen", ohne dass jemand etwas falsch gemacht hätte.
  - **Änderungen werden gebündelt geschickt** (800 ms nach der letzten Eingabe). Wer dreimal
    auf „+" tippt, löst einen Aufruf aus und nicht drei. **Beenden und Tauschen lösen den
    Verzug vorher aus** — beide prüfen die Warteschlange, und ein Eintrag, der noch im
    Zeitgeber hängt, steht dort noch nicht drin.
  - **Umschalten nur außerhalb eines Trainings.** Die Warteschlange ist auf `user_id` und
    `session_id` geschlüsselt und überlebt einen Moduswechsel; ein wartender Eintrag aus dem
    einfachen Modus trägt keine Satzliste, und ein `check` ohne Satzliste löscht die Sätze
    der Position (§4). Das wäre stiller Datenverlust mitten im Training.
  - Grenzen: höchstens **20 Sätze** je Übung, **1 bis 200** Wiederholungen, **0 bis 1000 kg**
    je Satz; ein Satz ohne Wiederholungen **und** ohne Gewicht wird abgelehnt.

- **Schlechtes Netz.** Der Regelfall im Studio ist nicht *kein* Empfang, sondern *schwacher*.
  Drei Vorkehrungen, gestaffelt:

  1. **Zeitlimit auf jedem Aufruf** (12 s; 120 s, wenn eine Datei mitgeht). `fetch` kennt von
     sich aus keines — ohne Limit bliebe das Häkchen bei einem Balken Empfang bis zu zwei
     Minuten deaktiviert stehen, ohne Meldung und ohne Wiederholen-Knopf.
  2. **Bis zu zwei automatische Wiederversuche** (nach 2 s und 5 s) bei Netzfehlern und 5xx.
     Nur dort, wo der Endpunkt es verträgt, und **ausdrücklich pro Aufruf** angefordert —
     nicht als Standard. `api/session.php → end` verträgt es ausdrücklich **nicht**.
  3. **Warteschlange für das Abhaken**, siehe unten.

- **Warteschlange (`assets/app.js`, `index.js`).** Ein Häkchen springt bei Netzproblemen
  nicht mehr zurück. Es bleibt gesetzt, die Zeile trägt sichtbar den Vorbehalt, und der
  Eintrag wird nachgeholt, sobald das Netz wieder da ist.

  **Der Vorbehalt ist ausschließlich der gestrichelte Balken am linken Rand** — dieselbe
  Farbe wie sonst, nur gestrichelt. Zwei Dinge waren daran bis `1.1.1` falsch: Er wurde
  **orange**, was nach einem Fehler aussah, obwohl gerade alles seinen Gang geht; und in der
  Karte stand zusätzlich ein Hinweissatz, der sie für die Dauer des Speicherns höher und
  danach wieder niedriger machte — bei jedem Satz sprang dadurch die ganze Liste darunter.
  **Der gestrichelte Balken ist seit `1.2.15` der einzige Hinweis auf ein laufendes
  Speichern**, und er genügt: Er steht an der Zeile, um die es geht, und verschiebt nichts.
  Die Leiste am oberen Rand nennt die Zahl der wartenden Eingaben nur noch dann, wenn das
  Netz tatsächlich weg ist.

  - **Nur innerhalb einer bereits laufenden Einheit.** Ohne offene Einheit bleibt es beim
    direkten Aufruf. Sonst müsste die Anzeige eine Einheit zeigen, die es serverseitig nicht
    gibt — ohne Startzeit, ohne `session_id`, mit einem „x/n" ohne Bezugsgröße. Und beim
    Nachholen wäre nicht mehr entscheidbar, in welche Einheit die Einträge gehören.
  - **Nur `api/log.php`** (`check`/`uncheck`). Tausch, Start und Ende bleiben online-only.
  - **Ablage in `localStorage`**, geschlüsselt auf `user_id` **und** `session_id`. Beide sind
    zwingend: `localStorage` gehört der Herkunft und nicht der Sitzung, und ein Häkchen gehört
    zu genau einer Einheit. Passt eines nicht, wird die Ablage verworfen — nachgeholt würde
    es sonst über `einheit_sicherstellen()` eine **neue** Einheit eröffnen.
  - **Ein Eintrag je Planposition**, der neueste gewinnt. Die Schlange kann damit nie länger
    werden als der Plan. Im Expertenmodus trägt der Eintrag zusätzlich die **vollständige
    Satzliste** — deshalb bekam der `localStorage`-Schlüssel in `1.1.0` eine neue Nummer
    (`…-v2`): Ein alter Eintrag ohne dieses Feld liefe als „check ohne Satzliste" durch und
    löschte damit die Sätze der Position. **Aktuell ist `trainingsplan-warteschlange-v3`**
    — `1.1.1` hat mit `done` ein weiteres Pflichtfeld ergänzt (§4). Die Nummer steht in
    `assets/app.js` und wird nur hochgezählt, wenn sich die **Form** eines Eintrags ändert;
    eine neue Einstellung allein ist kein Grund, siehe `CLAUDE.md` Fallstrick 17.
  - **Endgültige Ablehnungen (4xx) fliegen aus der Schlange** und erscheinen als Zeilenfehler
    mit Wiederholen-Knopf. Bliebe der Eintrag liegen, blockierte er alle folgenden dauerhaft.
  - **Beenden ist gesperrt, solange etwas aussteht** — eine geschlossene Einheit nähme die
    nachgeholten Einträge nicht mehr an.

- **Verbindungsleiste.** Eine rote Leiste am oberen Rand meldet **ausschließlich einen
  echten Verbindungsverlust** — der Server ist nicht erreichbar, Eingaben kommen gerade
  nicht durch — und bleibt stehen, bis das Problem weg ist. Steht etwas in der
  Warteschlange, nennt sie die Zahl der wartenden Eingaben dazu.

  **Den flüchtigen Zustand „n Eingaben werden gespeichert …" gibt es seit `1.2.15` nicht
  mehr** (Rückmeldung 2026-08-25). Er erschien bei jedem Abhaken für einen Sekundenbruchteil
  und sagte nichts, wonach jemand handeln könnte: Dass eine Zeile noch aussteht, zeigt ihr
  gestrichelter Rand, und ein endgültig gescheitertes Speichern meldet die Zeile selbst mit
  Wiederholen-Knopf. Eine Leiste, die ständig auf- und zuklappt, wird überlesen — und genau
  dann fällt die eine Meldung nicht mehr auf, die zählt.

  Maßgeblich ist, was tatsächlich gescheitert ist, **nicht** `navigator.onLine`: Das steht im
  Studio-WLAN ohne Internet und bei einem Balken Mobilfunk durchgehend auf `true`.
  „Gescheitert" heißt dabei **endgültig gescheitert**, also nach allen Wiederversuchen des
  Aufrufs — ein Aussetzer, den der zweite Anlauf nach 400 ms auffängt, ist kein Zustand, den
  jemand sehen muss.

  Weil die Leiste stehen bleibt, braucht es etwas, das das **Ende** der Störung bemerkt: Sie
  fragt alle 15 s selbst nach (`api/token.php`, roher `fetch` — jede Antwort beweist, dass
  der Server wieder da ist). Ohne das stünde sie auf jeder Seite außerhalb des Trainings rot
  da, bis jemand von sich aus etwas anklickt; das `online`-Ereignis feuert beim klassischen
  einen Balken Empfang nie.

**7.5 Übungstausch (Alternativen)**
- „Übung tauschen" schlägt alternative Übungen **derselben primären Muskelgruppe** vor: alle
  nicht archivierten Übungen, deren `is_primary`-Zuordnung auf dieselbe Gruppe zeigt wie die
  der aktuellen Übung, ohne die aktuelle selbst.

  ```sql
  SELECT e.* FROM exercises e
    JOIN exercise_muscle_groups emg ON emg.exercise_id = e.id AND emg.is_primary = 1
    JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
   WHERE COALESCE(mg.parent_id, mg.id) = CAST(:hauptgruppe AS INTEGER)
     AND e.id != :current_exercise_id
     AND e.archived = 0
  ```
  Verglichen wird die **Hauptgruppe** (§4), nicht die genaue Untergruppe. Das `CAST` ist
  zwingend: `COALESCE()` liefert einen Wert ohne Spaltenaffinität, und PDO bindet Werte aus
  `execute([...])` als Text — ohne Cast vergleicht SQLite Integer gegen Text, was nie
  zutrifft und die Liste stumm leer lässt.

  **Bewusst nur die Primärgruppe, nicht jede Überschneidung.** Der Vergleich läuft
  primär-gegen-primär; Sekundärgruppen werden zum Matching **gar nicht** herangezogen. Das
  ist in beide Richtungen wichtig:
  - Für **Bankdrücken** (Brust primär) kämen sonst reine Trizeps-Übungen — kein Ersatz für
    eine Brustübung.
  - Für **Trizepsdrücken** (Trizeps primär) käme sonst Bankdrücken — das macht niemand, um
    den Trizeps zu trainieren.

  Umgekehrt landen Übungen mit passender Primärgruppe zuverlässig in der Liste, egal wie
  viele Sekundärgruppen sie mitbringen.
- Die Vorschlagsliste zeigt zu jeder Alternative deren weitere Muskelgruppen an, damit
  erkennbar ist, was man sich zusätzlich einhandelt — dazu das **Trainingsgerät**, das an
  dieser Stelle die entscheidende Angabe ist: Man tauscht meist, *weil* ein Gerät besetzt ist.
  Der Name steht zweisprachig (§4), das Bild bleibt: An der Hantelbank erkennt man die Übung
  schneller am Motiv als am Namen.
- **Die Ausführung (`focus`) steht hier ausdrücklich NICHT.** In der Übungszeile beschreibt
  sie eine Übung, die man ohnehin macht; im Tauschfenster stehen mehrere Karten
  untereinander, die man vor einem belegten Gerät in Sekunden überfliegt. Dort zählen Name,
  Muskelgruppen und Gerät — alles Weitere macht die Liste länger, ohne die Wahl zu
  erleichtern. Die Server-Antwort trägt das Feld deshalb gar nicht erst mit.
- **Die Vorschläge lassen sich nach Gerät filtern** — im Training wie in der Planverwaltung,
  beide Tauschdialoge verhalten sich gleich. Der Filter wirkt ausschließlich **innerhalb**
  der bereits abgerufenen Liste und läuft rein im Browser; die Frage, *was* überhaupt als
  Ersatz taugt, beantwortet weiterhin allein die Hauptgruppe (oben). Zwei Gründe für den
  Verzicht auf einen zweiten Serverabruf: Man steht damit im Studio vor einem belegten
  Gerät, wo das Netz schwach ist, und die Antwort ist ohnehin vollständig da.
  - Zur Auswahl stehen nur die Geräte, die in der Liste **tatsächlich vorkommen**. Ein
    Eintrag, der zuverlässig eine leere Liste erzeugt, ist schlechter als kein Eintrag.
  - Bleibt nur ein Gerät übrig, entfällt der Filter ganz.
  - Beim Öffnen für eine andere Übung steht er wieder auf *alle* — sonst zeigte der Dialog
    eine eingeschränkte und scheinbar unvollständige Liste.
- **Übungen, die in diesem Plan ohnehin anstehen, erscheinen nicht als Vorschlag.** Sie sind
  kein Ersatz — man macht sie an diesem Tag sowieso. Maßgeblich ist die *angezeigte* Übung je
  Position: Wurde eine Position bereits getauscht, ist die verdrängte Original-Übung heute
  nicht im Programm und darf anderswo als Alternative auftauchen.
- Gibt es keine Alternative, wird das als Hinweis ausgegeben, nicht als leere Liste — und
  zwar unterschieden nach Ursache: „es gibt keine" gegenüber „alle stehen schon in diesem
  Plan (n)". Für den Benutzer sind das zwei verschiedene Sachverhalte; der zweite sagt ihm,
  dass sich das Anlegen weiterer Übungen lohnt.
- Nach Auswahl fragt die App den Modus:
  - **Nur diese Einheit (einmalig einstreuen):** `exercise_swaps`-Eintrag für die aktive
    Einheit + das betreffende `plan_exercise`. Die Ansicht zeigt die Ersatzübung; wird
    abgehakt, protokolliert der Log die Ersatzübung in `exercise_id` bei unveränderter
    `plan_exercise_id`. **Der Plan bleibt unverändert** — in der nächsten Einheit steht wieder
    die Original-Übung.
    **Existiert noch keine offene Einheit, wird dieser Tausch abgelehnt** (409) und gar nicht
    erst angeboten — er startet ausdrücklich **keine** Einheit (siehe §7.6). Vor dem Start
    bleibt der dauerhafte Tausch möglich; er braucht keine `session_id`.
  - **Dauerhaft (neue Default-Übung):** Der `plan_exercises`-Eintrag wird geändert
    (`exercise_id` = Ersatzübung). Ab sofort fester Bestandteil des Plans.

    **Seit `1.2.0` heißt „dauerhaft" ausdrücklich „dauerhaft für mich".** Der Plan steht
    in der eigenen Kopie des Splits (§4, §6.4) — die Vorlage, aus der sie stammt, und
    jede Kopie anderer Benutzer bleiben unberührt. Das ist genau der Grund, warum eine
    Vorlage kopiert und nicht verwendet wird.

    **Dieser Weg verlangt eine Rückfrage**, die beide Übungsnamen nennt und sagt, dass die
    Änderung für alle künftigen Trainings gilt und protokollierte Einheiten unberührt
    bleiben. Die zwei Knöpfe stehen im Studio nebeneinander auf einem kleinen Bildschirm
    und unterscheiden sich in der Tragweite erheblich; ein Fehlgriff fiele erst Wochen
    später auf, und der Weg zurück führte über die Planverwaltung.

    **„Nur diese Einheit" fragt bewusst nicht.** Der Tausch gilt für ein Training, ist
    durch einen zweiten Tausch sofort korrigierbar — und eine Rückfrage bei jedem
    Handgriff gewöhnt man sich an wegzuklicken. Dann greift sie auch dort nicht mehr, wo
    sie zählt.

    In der **Planverwaltung** gibt es keine Rückfrage: Dort ist die dauerhafte Änderung des
    Plans der erklärte Zweck der Seite, nicht die überraschende Nebenwirkung.

- **Bereits abgehakte Positionen lassen sich gar nicht tauschen** — weder dauerhaft noch für
  diese Einheit. Der Tausch-Button ist dann deaktiviert, mit Begründung; wer doch tauschen
  will, entfernt erst das Häkchen, tauscht, und hakt neu ab.

  Der Grund: Ein Protokolleintrag dokumentiert eine tatsächlich ausgeführte Übung. Würde der
  Tausch ihn auf die Ersatzübung umschreiben, wanderte das erreichte Gewicht auf eine Übung,
  die gar nicht gemacht wurde — und verschwände aus der Historie der Übung, die man wirklich
  gemacht hat. Bliebe der Eintrag dagegen auf der alten Übung stehen, zeigten Log und Ansicht
  auf Verschiedenes. Beide Auswege sind schlechter als der eine zusätzliche Handgriff.
  Das Ab-wählen ist ohnehin verlustfrei (§7.4) und macht die Absicht explizit.
- Besteht für eine Position sowohl ein `exercise_swaps`-Eintrag der offenen Einheit als auch
  ein geänderter Plan, **gewinnt der Swap** für die Dauer dieser Einheit.
- Das vorbelegte Gewicht folgt immer der tatsächlich angezeigten Übung (Historie ist pro
  Übung geführt).

**7.6 Trainingseinheit (Session) & Plan-Alternation**
- **Start — auf genau einem Weg:** über den Knopf **„Training starten"** auf der
  Vorschlagsseite. Sonst nichts.

  **Warum ausdrücklich:** Ohne den Knopf entstand die Einheit frühestens beim Abhaken der
  ersten Übung — der Zeitstempel hielt damit deren *Ende* fest. Bei drei Sätzen sind das
  leicht zehn Minuten, und jede Auswertung der Trainingsdauer (§10) wäre systematisch zu
  kurz.

  **Warum ausschließlich:** Ein Auffangnetz — die Einheit beginnt auch beim ersten
  „Erledigt" oder beim Tausch — fängt das Falsche. Ein Fehlgriff beim bloßen Durchsehen des
  Plans begänne ein Training, das niemand wollte, und stünde danach im Verlauf, wo es die
  Rotation verstellt.

  Daraus folgt für die Trainingsansicht: **Solange keine Einheit läuft, sind „Erledigt",
  „+ Satz" und das Gewichtsfeld deaktiviert.** Serverseitig antworten `api/log.php` und
  `api/swap.php` (Modus „nur diese Einheit") mit **409**; die deaktivierten Bedienelemente
  sind nur die Bequemlichkeit davor.

  **Tauschen bleibt vor dem Start möglich, aber nur dauerhaft im Plan.** Ein dauerhafter
  Tausch schreibt in `plan_exercises` und braucht keine `session_id`; „nur diese Einheit"
  braucht eine und wird deshalb vorher nicht angeboten — mit einem Hinweissatz im Dialog,
  sonst wirkt der fehlende Knopf wie ein Fehler.
- **Mitternachts-Robustheit:** Die Einheit ist die Einheit der Logik, nicht der Kalendertag.
  Eine offene Einheit bleibt aktiv, auch wenn das Datum während des Trainings wechselt.
- **Ende — auf zwei Wegen:**
  1. **Sind alle Planpositionen als „erledigt" markiert**, zeigt die App eine
     **Abschluss-Bestätigung**: „Alle Übungen erledigt — Training beenden?" mit den Optionen
     *Beenden* und *Noch nicht*. Die Einheit wird **nicht** still geschlossen. Damit gibt es
     keinen Zustand, in dem das Ab-wählen eines versehentlichen Häkchens (§7.4) undefiniert
     wäre, und der Bildschirm wechselt nicht schlagartig, während man noch am Gerät steht.
  2. **Manuell** über den **„Training beendet"-Button** — nötig, wenn absichtlich Übungen
     ausgelassen werden (z. B. aus Zeitmangel).

  Beim Ende wird `ended_at` = jetzt gesetzt. Sonst nichts — die Rotation merkt sich nichts,
  sie liest die Historie (siehe unten).

  **Danach steht die Seite ganz oben und ohne `?plan=`** (seit `1.2.15`). Beides gehört
  zusammen und hängt an demselben Umstand: Was nach dem Beenden erscheint, ist nicht mehr das
  eben trainierte Training, sondern der Vorschlag für das **nächste**.

  - **Ganz oben**, weil der „Training beendet"-Button auch am **Ende** der Übungsliste steht
    (siehe unten) — man steht also unten, während die neue Seite oben beginnt: Startkasten,
    Planwahl, Vorschlag. Ohne den Sprung landete man mitten in der Übungsliste eines
    Trainings, das gar nicht läuft. Der Browser stellt beim Neuladen von sich aus die alte
    Position wieder her; das wird dafür ausdrücklich abgeschaltet.
  - **Ohne `?plan=`**, weil der Parameter aus der Planwahl **vor** dem Training stammt.
    Während der Einheit ist er wirkungslos (der Plan kommt aus `sessions.plan_id`), danach
    greift er wieder — und die Seite schlüge denselben Plan vor, den man gerade fertig
    trainiert hat, statt den nächsten aus der Rotation.
- Pro Benutzer ist **höchstens eine** Einheit offen. Der „Training beendet"-Button ist während
  einer offenen Einheit stets erreichbar, sodass eine vergessene offene Einheit jederzeit
  geschlossen werden kann.
- **Hinweis bei alter Einheit:** Ist `started_at` der offenen Einheit älter als 12 Stunden,
  zeigt die App beim Start ein Banner „Deine Einheit läuft seit … — fortsetzen oder beenden?"
  mit beiden Buttons. Es gibt **kein** automatisches Schließen (das würde §7.2 aushebeln), aber
  ohne diesen Hinweis blockiert eine einmal vergessene Einheit dauerhaft die Plan-Alternation.
- **Plan-Rotation:** Liegt keine offene Einheit vor, schlägt die App den Plan vor, der in
  der Sortierreihenfolge (§6.4) **auf den Plan der letzten Einheit in der Historie folgt** —
  zyklisch, nach dem letzten kommt wieder der erste. **Sichtbar ist der Vorschlag als der
  blau markierte Knopf der Planreihe** (§7.2) — eine eigene Zeile „Vorgeschlagen: …" gibt es
  seit `1.2.10` nicht mehr. An der Rotation selbst ändert das nichts.
  - **Gezählt wird ausschließlich, was IN DIESEM SPLIT trainiert wurde** (seit `1.2.0`).
    Genau daran hängt, dass ein Splitwechsel nichts vergisst: Wer von Push/Pull auf
    Ganzkörper wechselt und später zurück, bekommt wieder *Pull* vorgeschlagen und nicht
    *Push*. Ein gespeicherter Zähler je Split wäre dafür nicht nötig — und wäre genau die
    zweite Quelle, vor der die Regel darunter warnt.
  - Bei **einem** Plan ist das immer derselbe.
  - Bei **zwei** Plänen ergibt die Regel exakt die frühere Alternation: vorgeschlagen wird
    der jeweils andere.
  - Bei **drei und mehr** (Push/Pull/Legs) läuft die Rotation der Reihe nach durch.
  - Gibt es **noch keine Einheit** oder zeigt die letzte auf einen gelöschten Plan, wird der
    **erste** Plan der Sortierung vorgeschlagen.

  **Maßgeblich ist die Historie, nicht ein gemerkter Wert.** Ein gemerkter Wert wird beim
  *Beenden* geschrieben und beim *Löschen* einer Einheit vergessen — die Einheit ist weg,
  ihre Wirkung auf den Vorschlag bliebe. `users.last_plan_id` steht deshalb zwar noch in der
  Tabelle, wird aber weder gelesen noch geschrieben.

  **Gezählt wird jede Einheit, auch eine ohne Protokollzeile.** Die Rotation richtet sich
  starr nach der Historie; eine leere Einheit steht in der Historie und zählt deshalb mit.
  Wer das nicht will, löscht sie — **die Historie sauber zu halten ist Sache des
  Benutzers**. Eine zweite, stille Regel wäre am Verlauf nicht ablesbar.

  Ein Rotationszähler ist nicht nötig: Die Position in der Reihenfolge bestimmt den
  Nachfolger eindeutig.
- Der Vorschlag ist vor dem Start **manuell auf jeden anderen Plan umschaltbar**. Bei mehr
  als zwei Plänen ist dafür eine Auswahl nötig, kein bloßes Umschalten. Die Auswahl
  umfasst die Pläne **des aktiven Splits**, nicht alle eigenen — sonst mischten sich zwei
  Splits in einer Rotation.

  Damit ist zugleich der Fall abgedeckt, dass eine Woche ausfällt: Wer gewohnt ist, dass
  Mittwoch der Beine-Tag ist, springt vor dem Start einfach auf *Legs*; die Rotation setzt
  danach hinter *Legs* fort, weil sie aus der Historie liest.
- **Den Split wechselt man auf `splits.php`**, nicht hier. Er wird in
  `users.active_split_id` festgehalten und bleibt über Seitenaufrufe und Geräte hinweg
  stehen. **Während einer offenen Einheit ist der Wechsel gesperrt** (409, wie der
  Moduswechsel in §7.4): Die laufende Einheit hängt an einem Plan des aktuellen Splits und
  gewinnt die Anzeige ohnehin — ein Wechsel darunter wäre eine Änderung, die man erst nach
  dem Beenden zu sehen bekäme.

**7.7 Konto & Geräte**

Die Seite heißt `password.php` und trägt im Menü **„Konto"** — sie hat zwei Aufgaben.

- **Passwort ändern** (`password.php`): Der Benutzer ändert sein eigenes Passwort. Das alte
  Passwort wird per `password_verify()` geprüft, danach `session_regenerate_id(true)`.
  Bei gesetztem `must_change_password` ist diese Seite die einzige erreichbare (§3, §7.1).
- **Benutzernamen ändern** (`password.php`, `api/auth.php → change_name`): Der Benutzer ändert
  seinen eigenen Namen; Admins ändern über §6.1 jeden.
  - **Das aktuelle Passwort wird verlangt**, obwohl der Benutzer angemeldet ist. Der
    Benutzername *ist* die Anmeldekennung: Wer ein kurz unbeaufsichtigtes, entsperrtes Handy
    in die Hand bekäme, könnte den Besitzer sonst mit zwei Tipps aussperren — der kennt den
    neuen Namen nicht.
  - **`require_passwort_gesetzt_api()` gehört ausdrücklich in diese Aktion.** `api/auth.php`
    ist der einzige Endpunkt ohne die Sperre am Dateikopf; ohne sie könnte jemand mit dem vom
    Admin vergebenen Startpasswort seinen Namen ändern, ohne je ein eigenes gesetzt zu haben.
  - Solange ein Passwortwechsel erzwungen ist, wird der Abschnitt **gar nicht angezeigt**.
  - Kein Abmelden anderer Geräte, kein `session_regenerate_id()`: Es ändert sich keine
    Berechtigung, und Sitzung wie Tokens hängen an der `user_id`. Der Name liegt
    ausschließlich in `users.name` — das Umbenennen ist ein einziges `UPDATE`.
  - Kollisionen werden über den `UNIQUE`-Index abgefangen, nicht über ein `SELECT` davor:
    Dazwischen läge sonst ein Zeitfenster, in dem sich derselbe Name zweimal vergeben ließe.
- **Trainingsansicht** (`password.php`, seit `1.1.0`): Umschalter **„Sätze einzeln erfassen
  (Expertenmodus)"** — die Oberfläche zu `users.expert_mode` (§7.4).
  - **Ohne Passwortabfrage**, anders als beim Benutzernamen. Der ist die Anmeldekennung, und
    wer ihn ändert, kann den Besitzer aussperren; dieser Schalter ändert nur die Darstellung
    der eigenen Daten und ist mit einem Tipp zurückgedreht.
  - **Gesperrt, solange eine Einheit läuft** — Begründung in §7.4. Der Schalter erscheint
    dann deaktiviert mit Hinweis, statt in ein sicheres 409 zu laufen.
  - Schlägt das Speichern fehl, springt der Schalter zurück: Eine Anzeige, die etwas anderes
    behauptet als der Server, wäre schlimmer als die Fehlermeldung.
- **Geräte** (seit `1.2.3` auf derselben Seite, vorher `devices.php`): Liste der aktiven
  `remember_tokens` des Benutzers (angelegt am, zuletzt genutzt, Gerätekennung aus
  `user_agent`, das aktuelle Gerät markiert), einzeln abmeldbar, plus **„Auf allen Geräten
  abmelden"**. Das ist die Oberfläche zu der in §5 zugesagten serverseitigen
  Widerrufbarkeit.
- **Abmelden** (seit `1.2.3`): ganz unten auf der Seite, als leiser Knopf. Es stand bis
  dahin dauerhaft in der Kopfzeile — die präsenteste Stelle der App für ihre seltenste
  Handlung. **Der Abschnitt erscheint auch bei erzwungenem Passwortwechsel**, sonst käme
  ein Benutzer mit Startpasswort nicht mehr heraus.
- **Einheiten löschen** (in `history.php`, §7.8): Eine abgeschlossene Einheit lässt sich
  samt Protokoll entfernen — für versehentlich gestartete Einheiten, abgebrochene Trainings
  oder Testdaten. Nur die **eigenen**; die offene Einheit ist ausgenommen, die wird beendet.
  Ohne diesen Weg blieben Fehleingaben dauerhaft stehen und blockierten über ihre
  `workout_log`-Einträge sogar das endgültige Löschen von Übungen (§6.3).

**7.8 Trainingshistorie (`history.php`)**

Die Antwort auf „wann habe ich was trainiert" und „werde ich stärker". Zwei Ansichten, über
eine Filterleiste umschaltbar:

- **Einheiten** (Standard): abgeschlossene Trainingseinheiten, neueste zuerst, mit Datum,
  Plan, Dauer und „x/n Übungen". Aufklappbar mit den protokollierten Übungen und Gewichten;
  eine getauschte Position ist als „statt …" gekennzeichnet.
- **Übungen**: je Übung der Gewichtsverlauf — als kleine Kurve in der Kopfzeile, aufgeklappt
  als Tabelle mit Datum und Gewicht, dazu die Veränderung gegenüber dem ersten Eintrag und
  der Bestwert. Übungen ohne Gewichtsangabe erscheinen nicht.

**Satzgenau protokollierte Einheiten** (§7.4) zeigen zusätzlich:

- In der Ansicht **Einheiten** eine Spalte **„Sätze"** mit der vollen Folge
  (`12×40 · 10×40 · 9×45`). Positionen aus dem Standardmodus zeigen dort `—`; hat eine
  Einheit überhaupt keine Sätze, entfällt die Spalte ganz — eine dauerhaft leere Spalte wäre
  am Handy verschenkte Breite.
- In der Ansicht **Übungen** zwei weitere Kurven **innerhalb** des aufgeklappten Bereichs,
  jede beschriftet: **Volumen** (Σ Wiederholungen × Gewicht je Einheit) und **1RM
  (geschätzt)**. Die Kopfzeile bleibt der Gewichtskurve vorbehalten — drei Kurven
  nebeneinander machen sie am Handy unlesbar. Dazu die passenden Tabellenspalten; die
  Tabelle rollt dann seitwärts in ihrem eigenen Kasten.
- **Die Gewichtskurve bleibt unverändert** und läuft weiter über `workout_log.weight`, im
  Expertenmodus also über den schwersten Satz. Dadurch ist der Verlauf über beide Modi
  hinweg eine durchgehende Linie.
- **Volumen** zeigt Fortschritt auch dann, wenn das Gewicht gleich bleibt und nur die
  Wiederholungen steigen. Einheiten ohne Sätze sind **Lücken**, keine Nullen — eine 0 risse
  einen Einbruch in die Kurve, den es nie gegeben hat.
- **1RM** nach Epley (`kg × (1 + Wdh ÷ 30)`) aus dem besten Satz, **mit sichtbarem Vorbehalt
  direkt an der Zahl**: eine Näherung, kein gemessener Wert. Der Hinweis ist nicht Zierde —
  eine geschätzte Zahl sieht aus wie eine gemessene, und genau diese vorgetäuschte
  Genauigkeit hat das Wiederholungsfeld gekostet (§4).

**Jeder sieht ausschließlich seine eigenen Daten — auch Admins.** Trainingsdaten sind
persönlich. Es gibt hier bewusst keine Benutzerauswahl; die `user_id` stammt durchgehend aus
der Sitzung und nie aus einem Parameter. Damit ist der IDOR-Schutz (§5) keine Prüfung, die
sich vergessen ließe, sondern Bestandteil jeder Abfrage.

Die Kurve ist **Inline-SVG ohne Bibliothek** — das hält die Regel „kein Build-Step, keine
Abhängigkeiten" (§2) ein. Bei weniger als zwei Messpunkten entfällt sie; es gäbe nichts zu
verbinden.

Eine laufende Einheit erscheint nicht in der Liste, sondern als Hinweis mit Verweis auf die
Trainingsansicht: Sie hat noch keine Dauer.

---

## 8. Nicht-funktionale Anforderungen

- Mobile-first, responsive Gestaltung.
- Als PWA installierbar (Manifest + Service Worker, Asset-Caching gemäß §2).
- Geringe Abhängigkeiten, kein Build-Step, kein Framework.
- Durchgängig Prepared Statements und kontextgerechtes Escaping.
- Deutschsprachige Oberfläche.
- **Leerzustände** sind ausdrücklich zu gestalten: Benutzer ohne Plan, Plan ohne Übungen,
  Übung ohne Bild, Muskelgruppe ohne Tausch-Alternative — jeweils mit klarem deutschem
  Hinweistext statt leerer Seite.

---

## 9. Nicht im Umfang von v1

- Native Apps, Push-Benachrichtigungen.
- **Vollständiger Offline-Betrieb.** Die App bleibt online-only: Seiten, Pläne, der Verlauf
  und die Verwaltung brauchen eine Verbindung.

  **Zwei Dinge sind trotzdem im Umfang** (§7.4), weil das Netz im Studio nicht stabil ist:
  Fällt das WLAN aus, hängt das Handy am Mobilfunk, und dort ist der Regelfall nicht *kein*
  Empfang, sondern *schlechter* — Anfragen, die weder ankommen noch fehlschlagen, sondern
  hängen.

  - **Zeitlimit und Wiederversuche** auf jedem Serveraufruf (`apiFetch`).
  - **Eine Warteschlange ausschließlich für das Abhaken** innerhalb einer bereits laufenden
    Einheit. Nicht für Start, Ende, Tausch, Verlauf oder Verwaltung — die bleiben online-only.

  Das ist bewusst die kleinstmögliche Ausnahme: Das Abhaken ist die einzige Handlung, die
  minütlich am Gerät stehend passiert, und die einzige, deren Endpunkt beliebig oft
  wiederholt werden darf (`api/log.php` schreibt per Upsert über
  `(session_id, plan_exercise_id)`).
- Passwort-Reset per E-Mail (Reset erfolgt durch den Admin; der Benutzer kann sein Passwort
  aber selbst ändern, §7.7).
- Kalenderbasierte Wochenpläne (fester Plan je Wochentag). Die Anzahl der Pläne ist
  dagegen **nicht** begrenzt — siehe §6.4.

---

## 10. Spätere Erweiterungen (vorgemerkt)

Was hier stand und inzwischen umgesetzt ist — Fortschritts-Charts (§7.8), satzgenaues
Logging (§7.4) —, steht in `doku/historie.md`. Offen sind:

- Wochentags-/Kalenderplanung (welcher Plan an welchem Tag).
- Kalenderansicht der Einheiten (Monatsraster) — die Listenansicht in §7.8 deckt den
  Alltag ab; ein Kalender wäre Zierde.
- Offline-Queue, falls sich die Netzsituation im Studio ändert (§2: der Schreibpfad ist
  über `apiFetch()` dafür bereits gekapselt).

---

## 11. Abnahmekriterien

Manuell am echten Handy über die Subdomain zu prüfen — `Secure`-Cookies, Remember-Me und die
PWA-Installation lassen sich lokal nicht sinnvoll testen.

1. Admin legt Benutzer, Muskelgruppen, 5 Übungen mit Bild und mindestens einen **Split
   mit 2 Plänen** an. Mindestens eine Übung
   bekommt **zwei** Muskelgruppen (z. B. Bankdrücken → Brust als Primärgruppe + Trizeps); ein
   Speichern **ohne** angehakte Gruppe wird abgelehnt. Der Versuch, eine noch zugeordnete
   Muskelgruppe zu löschen, wird mit Nennung der betroffenen Übungen verweigert.
2. Benutzer meldet sich am Handy an **mit** „Angemeldet bleiben" und installiert die PWA über
   „Zum Startbildschirm hinzufügen".
3. Browser schließen, App vom Startbildschirm öffnen → **kein erneuter Login**; der Token in
   der DB ist rotiert, `last_used_at` aktualisiert.
4. Erste Übung abhaken → Einheit entsteht, Gewicht wird gespeichert.
5. Zweite Übung tauschen („nur diese Einheit") → Ersatzübung erscheint, Plan bleibt unverändert.
   Die Vorschläge enthalten **nur** Übungen mit derselben **primären Muskelgruppe** — eine reine
   Trizeps-Übung taucht als Ersatz für Bankdrücken **nicht** auf, obwohl sich beide die
   Sekundärgruppe Trizeps teilen. Gegenprobe: Beim Tausch einer reinen Trizeps-Übung taucht
   Bankdrücken **nicht** in den Vorschlägen auf.
6. Handy sperren, App neu öffnen → Fortschritt und Häkchen sind erhalten.
7. Ein Häkchen ab-wählen → Log-Eintrag verschwindet, Zähler springt zurück.
8. Alle Übungen abhaken → **Abschluss-Bestätigung** erscheint; „Noch nicht" lässt die Einheit
   offen, ein Häkchen ist danach weiterhin ab-wählbar.
9. „Training beendet" → `ended_at` gesetzt. Sonst nichts — die Rotation merkt sich nichts,
   sie liest die Historie (§7.6).
10. App neu starten → **der nächste Plan der Reihenfolge** wird vorgeschlagen (Rotation).
    Gegenprobe mit drei Plänen (Push/Pull/Legs): Nach Push wird Pull vorgeschlagen, nach
    Legs wieder Push — und nicht etwa der zuletzt nicht benutzte.
11. Nächste Einheit: die getauschte Position zeigt wieder die **Original**-Übung; die
    vorbelegten Gewichte entsprechen dem letzten Mal.
12. Als Benutzer A per manipulierter ID einen Plan/Log von Benutzer B aufrufen → **403**.
    Dazu die Split-Fälle (§4, §6.4), je einzeln: eine Einheit auf einem **Vorlagen**-Plan
    starten → **404**, auch als Admin; als Nicht-Admin eine Übung in einer Vorlage
    hinzufügen, dort dauerhaft tauschen oder protokollieren → je **403**; eine Vorlage als
    aktiven Split wählen → **403** mit dem Hinweis „bitte zuerst Zu mir kopieren".
13. **Splits** (§6.4): Admin legt auf *Admin → Vorlagen* die Vorlage *Push / Pull* mit
    zwei Plänen an. Benutzer B übernimmt sie auf *Splits*, tauscht darin eine Übung
    dauerhaft und entfernt eine Position → **die Vorlage bleibt unverändert**. Danach
    ändert der Admin die Vorlage → **Bs Kopie bleibt unverändert**. B übernimmt ein
    zweites Mal → die Kopie heißt *Push / Pull (2)* und steht neben der ersten.
    Gegenprobe zur Trennung der Seiten: **B sieht auf *Splits* nur seine eigenen Karten**,
    und der Admin dort ebenfalls nur seine eigenen — der Katalog steht bei beiden
    ausschließlich als Auswahlfeld *Vorlage übernehmen*. Gegenprobe zur Rotation: In
    Split 1 *Push* trainieren und beenden → Vorschlag *Pull*; auf Split 2 wechseln, dort
    *Push* trainieren → Vorschlag dort *Pull*; zurück auf Split 1 → Vorschlag weiterhin
    **Pull**, nicht Push.
14. **Migration und Restore** (§4): Eine Sicherung von vor `1.2.0` einspielen → jeder
    Benutzer bekommt automatisch einen Split *Meine Pläne* mit seinen bisherigen Plänen in
    unveränderter Reihenfolge, Historie und Gewichte sind vollständig, und die Rotation
    schlägt denselben Plan vor wie vor dem Umbau.
15. Eine benutzte Übung archivieren → sie verschwindet aus Dropdowns und Tauschvorschlägen,
    der Filter zeigt „Archiviert (1)", die Zeile nennt Archivierungsdatum, betroffene Pläne
    und die Anzahl der Log-Einträge; „endgültig löschen" ist deaktiviert. Reaktivieren bringt
    sie zurück. Eine nie benutzte Übung lässt sich dagegen endgültig löschen, Bild inklusive.
16. Eine `.php`-Datei mit Bild-Endung hochladen → **abgelehnt**; ein gültiges Bild landet
    re-enkodiert unter Zufallsnamen und ist über `/uploads/...` **nicht ausführbar**.
17. 6× falsches Passwort → Sperre greift; nach erfolgreichem Login ist der Zähler zurückgesetzt.
18. Ein Gerät über den Abschnitt *Geräte* auf der Kontoseite abmelden → dieses Gerät verlangt beim nächsten Aufruf wieder
    das Passwort, die anderen nicht.
19. Backup erstellen, herunterladen, wieder einspielen → Datenbestand unverändert.
20. Container `down` + `up --build` → Daten und Bilder sind vollständig da.
21. **Expertenmodus** (§7.4): Auf der Kontoseite „Sätze einzeln erfassen" einschalten — bei
    laufendem Training ist der Schalter gesperrt. Training starten, bei einer Übung **ohne
    Vorgeschichte** einmal „+ Satz" antippen → es erscheint eine leere Zeile, **kein** roter
    Rand und **kein** „Erneut versuchen". Bei einer Übung **mit** Vorgeschichte dreimal
    „+ Satz": Die Vorschläge entsprechen Satz für Satz der letzten Einheit, die
    Wiederholungen lassen sich mit −/+ korrigieren. **„x/n beendet" bleibt dabei stehen** —
    die Position ist protokolliert, aber nicht fertig; „Tauschen" ist trotzdem schon
    gesperrt. Erst das Häkchen lässt den Bruch springen, klappt den Satzblock zu und springt
    zur nächsten offenen Übung. Häkchen wieder entfernen → der Bruch geht zurück, **die
    Sätze bleiben stehen**. Handy sperren
    und App neu öffnen → die Sätze stehen noch. Flugmodus einschalten, einen Satz ändern →
    der grüne Balken strichelt (er wird **nicht** orange, und die Karte ändert ihre Höhe
    nicht), nach den Wiederversuchen erscheint oben die **rote** Leiste mit der Anzahl,
    Beenden ist gesperrt; Flugmodus aus → der Satz wird nachgeholt und die Leiste
    verschwindet. Training beenden; im
    Verlauf steht unter „Einheiten" die Folge `12×40 · 10×40 · 9×45`, unter „Übungen" laufen
    Volumen- und 1RM-Kurve samt Näherungs-Hinweis. Gegenprobe: Expertenmodus wieder
    ausschalten → die alte Ansicht ist unverändert da, die protokollierten Einheiten bleiben
    lesbar.
