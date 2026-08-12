# Lastenheft — Trainingsplan-Web-App

Zentral gepflegte Web-App zur Verwaltung und mobilen Nutzung von Studio-Trainingsplänen
für mehrere Benutzer. Umsetzung mit Claude Code.

> **Stand:** überarbeitete Fassung vom 2026-08-05. Gegenüber der Erstfassung wurden fünf
> Widersprüche aufgelöst (§4 `plan_exercise_id`, §6.3 Soft-Delete, §7.5 Tausch vor Sessionstart,
> §7.6 Auto-Ende, §7.3 Gewichts-Fallback) und vier Funktionen ergänzt (§6.5 Wartung, §7.7
> Passwortwechsel und Geräteverwaltung, §7.6 Hinweis bei alter Einheit). Die Erstfassung liegt
> im Git-Verlauf (Commit „Lastenheft (Originalfassung)").
>
> **Nachtrag:** Muskelgruppen hängen nicht mehr als einzelner Fremdschlüssel an der Übung,
> sondern als n:m-Zuordnung mit Primär-Kennzeichnung (§4 `exercise_muscle_groups`,
> §6.3 Checkbox-Auswahl, §7.5 Tauschlogik).
>
> **Nachtrag 2026-08-11 (`1.1.3` und `1.1.4`):** Zwei weitere Punkte aus dem Einsatz —
> (1) Beim Weiterspringen nach dem Abhaken landete die nächste Karte **unter der
> Verbindungsleiste** (sticky, wird genau in dem Moment sichtbar); ihre Höhe wird jetzt
> abgezogen (§7.3). (2) **Abgehakte Übungen sind festgeschrieben**: Im Expertenmodus ließen
> sich Wiederholungen und Gewicht nachträglich ändern und Sätze hinzufügen oder löschen. Das
> war ein Überbleibsel aus `1.1.0`, als der erste Satz die Übung noch selbst abhakte; mit dem
> Schalter aus `1.1.1` ist die Begründung entfallen. Gesperrt wird **serverseitig**, mit einer
> ausdrücklichen Ausnahme für unveränderte Nutzlasten — sonst zerbräche die Idempotenz der
> Warteschlange (§7.4).
>
> **Nachtrag 2026-08-11 (Feinschliff aus dem zweiten Einsatz, `1.1.2`):** Vier Punkte, alle
> zur Bedienung am Gerät — (1) **Farbleitsystem am linken Kartenrand**: grün = hier bist du,
> blau = erledigt, grau = kommt noch. Grün zieht den Blick, und den soll ziehen, was als
> Nächstes zu tun ist (§7.3). (2) **Aktive Position und aufgeklappter Satzblock nur während
> eines Trainings**; „Training starten" öffnet die erste Übung und scrollt dorthin (§7.3).
> (3) Der **Wartezustand** strichelt die vorhandene Balkenfarbe, statt orange zu werden, und
> der Hinweissatz in der Karte entfällt — er veränderte die Kartenhöhe und ließ die Liste bei
> jedem Satz springen (§7.4). (4) Der **Fokusrahmen** des Wiederholungsfeldes lag über den
> Steppern; die Satzzeile hat jetzt ein nachgerechnetes Breitenbudget.
>
> **Nachtrag 2026-08-11 (Korrekturen aus dem ersten Einsatz, `1.1.1`):** Drei Punkte aus dem
> Praxistest von `1.1.0` — (1) **„Erledigt" ist ein Schalter**, kein Nebeneffekt der Sätze:
> Wer den ersten Satz einträgt, ist nicht fertig mit der Übung. Dafür trennt die neue Spalte
> `workout_log.done` „protokolliert" von „fertig" (§4, §7.4). Abhaken klappt den Satzblock zu
> und springt zur nächsten offenen Übung. (2) Eine **noch leere Satzzeile wird nicht
> abgeschickt** — sie lief sonst in ein 422 und markierte die Zeile als fehlerhaft, obwohl
> nichts falsch war (§7.4). (3) **Farbhierarchie**: Der Satzkopf ist leise, kräftig ist
> „+ Satz". Dazu stehen alle `:hover`-Regeln hinter `@media (hover: hover)` — auf einem
> Touchscreen blieb Hover am zuletzt angetippten Element kleben und ließ die Knöpfe ihre
> Blautöne tauschen.
>
> **Nachtrag 2026-08-11 (Expertenmodus, `1.1.0`):** Satzgenaues Protokollieren war in §9 aus
> v1 ausgenommen und in §10 vorgemerkt; es kommt auf Wunsch des Benutzers hinein — als
> **je Benutzer abschaltbarer Expertenmodus**, der den einfachen Weg unangetastet lässt.
> Damit wird die Zusage von 2026-08-07 eingelöst: Die Wiederholungen kehren **nicht** als
> Spalte zurück (die Begründung dagegen gilt unverändert), sondern als eigene Tabelle
> `workout_sets` — genau so, wie §4 es angekündigt hatte. `workout_log.weight` bleibt als
> **Leitgewicht** bestehen und trägt im Expertenmodus den schwersten Satz; dadurch bleiben
> „letztes Gewicht", Gewichtsverlauf und Bestwert über beide Modi hinweg durchgehend. Neu
> oder geändert: §4 (`workout_sets`, `users.expert_mode`), §7.3, §7.4, §7.7, §7.8 (Sätze,
> Volumen, geschätztes 1RM) und Abnahmekriterium 19.
>
> **Nachtrag 2026-08-07 (Trainingshistorie):** Die Auswertung war in §9 ausdrücklich aus v1
> ausgenommen und in §10 vorgemerkt. Sie kommt auf Wunsch des Benutzers doch hinein — die
> Daten lagen ohnehin vollständig vor, es fehlte nur die Ansicht. Neu: §7.8, `history.php`.
>
> **Nachtrag 2026-08-07 (nach dem ersten Studio-Training):** Vier Änderungen aus dem
> Praxistest — (1) **Wiederholungen entfallen ersatzlos**, Feld und Spalte
> `workout_log.reps`: Ein Wert je Einheit kann 12/10/9 über drei Sätze nicht abbilden (§4,
> §7.3, §7.4). (2) Das Gewichtsfeld ist nach dem Abhaken **schreibgeschützt**; geändert wird
> über Häkchen entfernen → korrigieren → neu abhaken (§7.4). (3) Eine Einheit lässt sich
> **ausdrücklich starten** — vorher hielt der Zeitstempel das Ende der ersten Übung fest
> statt des Trainingsbeginns (§7.6). (4) Hauptgruppen mit Untergruppen sind in der
> Übungsmaske **nicht mehr wählbar** (§6.3). Einzelheiten in
> `doku/rueckmeldungen_praxistest.md`.
>
> **Nachtrag 2026-08-06 (Pläne):** Die Obergrenze von zwei Plänen je Benutzer entfällt
> (§6.4). Aus der Plan-*Alternation* wird damit eine Plan-*Rotation* entlang der
> Sortierreihenfolge (§7.6); bei zwei Plänen verhält sie sich unverändert. Anlass ist eine
> mögliche Umstellung auf Push/Pull/Legs. Die Planpflege bleibt ausdrücklich Adminsache —
> Benutzer stellen sich keine eigenen Pläne zusammen.
>
> **Nachtrag 2026-08-06:** Die Zielumgebung steht und ist eingerichtet — Subdomain,
> Proxy-Ziel und Volume-Pfade sind in §3.1 als verbindliche Werte nachgetragen. Die
> Erstfassung ging von einem allgemeinen Setting aus; wo ihre Annahmen dem widersprechen,
> wurden sie gestrichen. Betroffen: das Port-Binding (`127.0.0.1` → LXC-IP `10.10.10.2:8066`)
> und die Beschreibung der internen Strecke (nicht `localhost`, sondern Proxmox-Internetz).

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
Assets — `assets/*.css`, `assets/*.js`, `manifest.json` und die Icons — mit Strategie
**`stale-while-revalidate`**: Die Antwort kommt sofort aus dem Cache, parallel wird die
frische Fassung geholt und abgelegt.

> Ursprünglich stand hier `cache-first`. Das hat sich am 2026-08-07 als Falle erwiesen: Ein
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
├── login.php  logout.php  password.php  devices.php  history.php
├── admin_users.php  admin_exercises.php  admin_plans.php
├── admin_muscle_groups.php  maintenance.php  download_backup.php
├── image.php                    # Bild-Ausliefer-Endpoint mit Path-Jail
├── api/    auth.php  session.php  log.php  swap.php
│           exercises.php  plans.php  users.php  muscle_groups.php
├── lib/    db.php  auth.php  csrf.php  helpers.php  upload.php
│           view_header.php  view_footer.php   (+ .htaccess: Require all denied)
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
  *„Package 'sqlite3', required by 'virtual:world', not found"* ab — beim ersten
  Image-Bau am 2026-08-06 genau so passiert.
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
- `id`, `name` (eindeutiger Login-Name), `password_hash`, `is_admin` (bool),
  `must_change_password` (bool, Default 0), `expert_mode` (bool, Default 0),
  `last_plan_id` (FK → plans, nullable — **seit 2026-08-12 unbenutzt**, siehe §7.6;
  die Spalte bleibt nur stehen, weil ihr Entfernen eine löschende Migration ohne
  Gegenwert wäre), `created_at`

**remember_tokens** — „Angemeldet bleiben"-Tokens (siehe §5)
- `id`, `user_id` (FK), `selector` (eindeutig), `validator_hash`, `expires_at`,
  `last_used_at` (datetime, nullable), `user_agent` (text, nullable), `created_at`
- `last_used_at` und `user_agent` dienen der Geräteliste in §7.7 und fallen bei der
  Token-Rotation ohnehin an.

**login_attempts** — Brute-Force-Bremse (siehe §5)
- `id`, `ip`, `attempted_at`
- Bewusst eine Tabelle und nicht `$_SESSION`: Ein sessionbasierter Zähler ist durch simples
  Löschen des Cookies zu umgehen.

**plans** — Trainingspläne (1–2 pro Benutzer)
- `id`, `user_id` (FK), `name`, `sort_order`, `created_at`

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
- **„x/n" zählt `done = 1`**, nicht die bloße Existenz der Zeile — in der Trainingsansicht
  wie im Verlauf. **Die Tauschsperre (§7.5) hängt dagegen an der Existenz der Zeile**: Wer
  zwei Sätze Bankdrücken gemacht hat, kann die Position nicht mehr tauschen, auch ohne
  Häkchen. Die zwei Sätze waren Bankdrücken.
- **Hier steht keine Wiederholungsspalte, und die Begründung von 2026-08-07 gilt
  unverändert.** Ein Feld je Einheit kann nicht abbilden, was tatsächlich passiert — bei
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
| Plan löschen | Verboten, solange eine offene Einheit auf ihn zeigt. Sonst: `plan_exercises` kaskadiert, `sessions.plan_id` → `ON DELETE SET NULL`, `sessions`/`workout_log` bleiben erhalten. Eine Einheit ohne Plan zählt für die Rotation nicht mehr mit (§7.6). |
| Planposition entfernen | Verboten, solange eine offene Einheit läuft (§6.4). Sonst bleibt der `workout_log` erhalten; die Historie zeigt weiter auf `exercise_id`. |
| Benutzer löschen | Löscht `remember_tokens` und `plans` kaskadierend, entfernt aber **nicht** `sessions`/`workout_log` — der Admin bekommt vor dem Löschen die Anzahl betroffener Einheiten angezeigt und bestätigt explizit. |

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

**6.4 Planverwaltung**
- Pro Benutzer **beliebig viele** Pläne anlegen — mindestens einer, nach oben ohne feste
  Grenze. Zwei Pläne (Ober-/Unterkörper) und drei (Push/Pull/Legs) sind die erwarteten
  Fälle; die Rotation in §7.6 ist für jede Anzahl definiert.
- **Die Reihenfolge der Pläne ist fachlich bedeutsam**, nicht bloß Anzeigesache: Sie legt
  die Rotationsreihenfolge fest (§7.6). Push → Pull → Legs muss sich deshalb sortieren
  lassen, mit denselben Mitteln wie die Übungen innerhalb eines Plans.
- Übungen zu einem Plan hinzufügen/entfernen und **in Reihenfolge sortieren**.
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
  - Archivierte Übungen erscheinen nicht (§6.3).
- **Sperre bei offener Einheit:** Hat der betroffene Benutzer eine offene Einheit, ist die
  Planbearbeitung blockiert (Hinweis anzeigen). Sonst würde sich `n` in der laufenden
  Fortschrittsanzeige „x/n" mitten im Training verschieben.

**6.5 Wartung & Backup**
- Eigene Seite `maintenance.php`, nur für Admins, nach dem Muster aus
  `Speisekarte/doku/maintenance_anleitung.md`.
- Aktionen: `backup`, `restore`, `upload`, `delete_backup`, `vacuum`, `integrity`,
  `optimize`, `checkpoint`.
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
- Existiert keine offene Einheit, schlägt die App den nächsten Plan vor (§7.6) und zeigt ihn
  startbereit an.

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

  **Aktiv ist nicht „die erste noch nicht erledigte"** (Änderung vom 2026-08-12). Das war es
  bis dahin und geht schief, sobald man eine Übung auslässt, weil das Gerät besetzt ist: Die
  Markierung blieb auf der ausgelassenen Übung stehen, während man längst zwei Geräte weiter
  war, und dass die ausgelassene noch aussteht, war von „kommt noch" nicht zu unterscheiden.
  Die Regel lautet, in dieser Reihenfolge:

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
  Dabei wird die Höhe der Verbindungsleiste abgezogen — sie klebt oben am Rand und verdeckte
  sonst genau den Übungsnamen der angesprungenen Karte.
  - **„Erledigt"-Häkchen**,
  - Aktion **„Übung tauschen"** (§7.5).
- Fortschrittsanzeige „x/n erledigt" und der **„Training beendet"-Button** (§7.6) sind während
  einer offenen Einheit sichtbar. `n` ist die Anzahl der Planpositionen, `x` die Anzahl der
  Positionen mit `workout_log`-Eintrag in dieser Einheit.

**7.4 Gewichts-Logging & „Erledigt"**
- Das Gewicht muss **nicht** jedes Mal neu eingegeben werden — Default ist der letzte Wert;
  der Benutzer passt es nur bei Änderung an. „Erledigt" funktioniert **auch ohne Gewichtswert**.
- Beim Setzen von „Erledigt" wird ein `workout_log`-Eintrag für die **aktive Einheit** +
  Planposition geschrieben bzw. aktualisiert (`session_id`, `plan_exercise_id`, `exercise_id`,
  Gewicht, `performed_at`). Ein Eintrag pro Einheit + Planposition.
- **Nach dem Abhaken ist das Gewichtsfeld schreibgeschützt.** Wer den Wert korrigieren will,
  entfernt das Häkchen, ändert ihn und hakt neu ab. Damit gibt es **einen** Mechanismus
  statt zweier — genau so ist der Übungstausch geregelt (§7.5). **Seit `1.1.4` weist auch der
  Server einen abweichenden Wert auf einer abgehakten Position ab** (409); bis dahin war das
  nur eine Regel der Oberfläche.

  Der Preis: Wer abwählt, ändert und dann vergisst, wieder abzuhaken, hat für diese Position
  nichts protokolliert. Das ist aber sichtbar — das Häkchen fehlt und „x/n" steht niedriger.
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

  - **Ein Tipp je Satz.** `+ Satz` legt eine Zeile an, die schon gefüllt ist: **Satz k wird
    mit Satz k der letzten Einheit vorbelegt** — nicht mit dem vorherigen Satz von heute.
    Genau das macht den Modus im Studio schnell: Wer 12/10/9 gewohnt ist, bekommt beim
    dritten Antippen 9 vorgeschlagen und nicht 10. Hatte das letzte Mal weniger Sätze, gilt
    der vorherige Satz von heute; gibt es gar keine Vorlage, das zuletzt bekannte Gewicht
    dieser Übung mit leerem Wiederholungsfeld. Der Vorschlag steht im Knopf („+ Satz
    (9 × 45)"), damit vor dem Tippen sichtbar ist, was entsteht.
  - **Wiederholungen mit −/+**, Gewicht als Textfeld. Die Wiederholungen weichen fast immer
    nur um ±1 vom Vorschlag ab — dafür sind zwei große Tippziele schneller und sicherer als
    die Zifferntastatur mit feuchten Fingern. Das Gewicht ändert sich selten und dann in
    beliebigen Sprüngen; ein fester Stepper-Schritt wäre bei Maschine (5 kg), Kurzhantel
    (2 kg) und Scheiben (1,25 kg) immer für etwas falsch.
  - **„Erledigt" ist ein Schalter und kein Nebeneffekt** (korrigiert in `1.1.1` nach dem
    ersten Studio-Einsatz). Sätze einzutragen heißt *nicht*, mit der Übung fertig zu sein —
    man will ja gleich den nächsten machen. Die `workout_log`-Zeile entsteht mit dem ersten
    Satz, aber mit `done = 0`; „x/n" springt erst, wenn der Benutzer das Häkchen selbst
    setzt.

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
  **Wie viele Eingaben ausstehen, sagt die Leiste am oberen Rand**, und die genügt: Sie ist
  `sticky` und damit immer im Blick.

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
    Satzliste** — deshalb heißt der `localStorage`-Schlüssel seit `1.1.0`
    `trainingsplan-warteschlange-v2`: Ein alter Eintrag ohne dieses Feld liefe als „check
    ohne Satzliste" durch und löschte damit die Sätze der Position.
  - **Endgültige Ablehnungen (4xx) fliegen aus der Schlange** und erscheinen als Zeilenfehler
    mit Wiederholen-Knopf. Bliebe der Eintrag liegen, blockierte er alle folgenden dauerhaft.
  - **Beenden ist gesperrt, solange etwas aussteht** — eine geschlossene Einheit nähme die
    nachgeholten Einträge nicht mehr an.

- **Verbindungsleiste.** Eine Leiste am oberen Rand nennt den Zustand und die Zahl der
  wartenden Eingaben. Maßgeblich ist dabei, was tatsächlich gescheitert ist, **nicht**
  `navigator.onLine`: Das steht im Studio-WLAN ohne Internet und bei einem Balken Mobilfunk
  durchgehend auf `true`.

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

  **Warum ausschließlich** (Änderung vom 2026-08-12): Bis dahin startete auch das erste
  „Erledigt" und ein Tausch „nur für diese Einheit" eine Einheit — als Auffangnetz für den,
  der den Knopf übersieht. Das Netz fing das Falsche: Ein Fehlgriff beim bloßen Durchsehen
  des Plans begann ein Training, das niemand wollte, und die versehentliche Einheit stand
  danach im Verlauf und verstellte die Rotation. Beide Wege sind deshalb geschlossen.

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
- Pro Benutzer ist **höchstens eine** Einheit offen. Der „Training beendet"-Button ist während
  einer offenen Einheit stets erreichbar, sodass eine vergessene offene Einheit jederzeit
  geschlossen werden kann.
- **Hinweis bei alter Einheit:** Ist `started_at` der offenen Einheit älter als 12 Stunden,
  zeigt die App beim Start ein Banner „Deine Einheit läuft seit … — fortsetzen oder beenden?"
  mit beiden Buttons. Es gibt **kein** automatisches Schließen (das würde §7.2 aushebeln), aber
  ohne diesen Hinweis blockiert eine einmal vergessene Einheit dauerhaft die Plan-Alternation.
- **Plan-Rotation:** Liegt keine offene Einheit vor, schlägt die App den Plan vor, der in
  der Sortierreihenfolge (§6.4) **auf den Plan der letzten Einheit in der Historie folgt** —
  zyklisch, nach dem letzten kommt wieder der erste.
  - Bei **einem** Plan ist das immer derselbe.
  - Bei **zwei** Plänen ergibt die Regel exakt die frühere Alternation: vorgeschlagen wird
    der jeweils andere.
  - Bei **drei und mehr** (Push/Pull/Legs) läuft die Rotation der Reihe nach durch.
  - Gibt es **noch keine Einheit** oder zeigt die letzte auf einen gelöschten Plan, wird der
    **erste** Plan der Sortierung vorgeschlagen.

  **Maßgeblich ist die Historie, nicht ein gemerkter Wert** (Änderung vom 2026-08-12). Bis
  dahin stand der Ausgangspunkt in `users.last_plan_id` — geschrieben nur beim *Beenden*
  einer Einheit, zurückgenommen beim *Löschen* nie. Eine gelöschte Testeinheit verstellte
  den Vorschlag dauerhaft: Die Einheit war weg, ihre Wirkung blieb. Die Spalte bleibt in der
  Tabelle stehen, wird aber weder gelesen noch geschrieben.

  **Gezählt wird jede Einheit, auch eine ohne Protokollzeile.** Die Rotation richtet sich
  starr nach der Historie; eine leere Einheit steht in der Historie und zählt deshalb mit.
  Wer das nicht will, löscht sie — **die Historie sauber zu halten ist Sache des
  Benutzers**. Eine zweite, stille Regel wäre am Verlauf nicht ablesbar.

  Ein Rotationszähler ist nicht nötig: Die Position in der Reihenfolge bestimmt den
  Nachfolger eindeutig.
- Der Vorschlag ist vor dem Start **manuell auf jeden anderen Plan umschaltbar**. Bei mehr
  als zwei Plänen ist dafür eine Auswahl nötig, kein bloßes Umschalten.

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
- **Geräte** (`devices.php`): Liste der aktiven `remember_tokens` des Benutzers (angelegt am,
  zuletzt genutzt, Gerätekennung aus `user_agent`, das aktuelle Gerät markiert), einzeln
  abmeldbar, plus **„Auf allen Geräten abmelden"**. Das ist die Oberfläche zu der in §5
  zugesagten serverseitigen Widerrufbarkeit.
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
  Genauigkeit hat 2026-08-07 das Wiederholungsfeld gekostet.

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

  Die ursprüngliche Annahme „das Netz im Studio ist stabil" hat sich allerdings nicht
  gehalten. Fällt das WLAN vor Ort aus, hängt das Handy am Mobilfunk, und dort ist der
  Regelfall nicht *kein* Empfang, sondern *schlechter* — Anfragen, die weder ankommen noch
  fehlschlagen, sondern hängen. Deshalb sind zwei Dinge **doch** im Umfang, siehe §7.4:

  - **Zeitlimit und Wiederversuche** auf jedem Serveraufruf (`apiFetch`).
  - **Eine Warteschlange ausschließlich für das Abhaken** innerhalb einer bereits laufenden
    Einheit. Nicht für Start, Ende, Tausch, Verlauf oder Verwaltung — die bleiben online-only.

  Das ist bewusst die kleinstmögliche Ausnahme: Das Abhaken ist die einzige Handlung, die
  minütlich am Gerät stehend passiert, und die einzige, deren Endpunkt beliebig oft
  wiederholt werden darf (`api/log.php` schreibt per Upsert über
  `(session_id, plan_exercise_id)`).
- ~~Getrennte Erfassung mehrerer Sätze pro Übung~~ — **umgesetzt in `1.1.0`** als
  abschaltbarer Expertenmodus, siehe §7.4. (Die frühere Klammer „ein Gewicht/Wiederholungen
  pro Übung und Einheit" war schon seit `1.0.3` falsch: Wiederholungen entfielen damals
  ersatzlos.)
- Passwort-Reset per E-Mail (Reset erfolgt durch den Admin; der Benutzer kann sein Passwort
  aber selbst ändern, §7.7).
- Kalenderbasierte Wochenpläne (fester Plan je Wochentag). Die Anzahl der Pläne ist
  dagegen **nicht** begrenzt — siehe §6.4.

---

## 10. Spätere Erweiterungen (vorgemerkt)

- ~~Fortschritts-Charts je Übung~~ — **umgesetzt am 2026-08-07** als `history.php`, siehe
  §7.8.
- ~~Satz-genaues Logging (mehrere Sätze pro Übung)~~ — **umgesetzt am 2026-08-11** als
  Expertenmodus, siehe §7.4 und die Tabelle `workout_sets` in §4.
- Wochentags-/Kalenderplanung (welcher Plan an welchem Tag).
- Kalenderansicht der Einheiten (Monatsraster) — die Listenansicht in §7.8 deckt den
  Alltag ab; ein Kalender wäre Zierde.
- Offline-Queue, falls sich die Netzsituation im Studio ändert (§2: der Schreibpfad ist
  über `apiFetch()` dafür bereits gekapselt).

---

## 11. Abnahmekriterien

Manuell am echten Handy über die Subdomain zu prüfen — `Secure`-Cookies, Remember-Me und die
PWA-Installation lassen sich lokal nicht sinnvoll testen.

1. Admin legt Benutzer, Muskelgruppen, 5 Übungen mit Bild und mindestens 2 Pläne an. Mindestens eine Übung
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
9. „Training beendet" → `ended_at` gesetzt, `last_plan_id` aktualisiert.
10. App neu starten → **der nächste Plan der Reihenfolge** wird vorgeschlagen (Rotation).
    Gegenprobe mit drei Plänen (Push/Pull/Legs): Nach Push wird Pull vorgeschlagen, nach
    Legs wieder Push — und nicht etwa der zuletzt nicht benutzte.
11. Nächste Einheit: die getauschte Position zeigt wieder die **Original**-Übung; die
    vorbelegten Gewichte entsprechen dem letzten Mal.
12. Als Benutzer A per manipulierter ID einen Plan/Log von Benutzer B aufrufen → **403**.
13. Eine benutzte Übung archivieren → sie verschwindet aus Dropdowns und Tauschvorschlägen,
    der Filter zeigt „Archiviert (1)", die Zeile nennt Archivierungsdatum, betroffene Pläne
    und die Anzahl der Log-Einträge; „endgültig löschen" ist deaktiviert. Reaktivieren bringt
    sie zurück. Eine nie benutzte Übung lässt sich dagegen endgültig löschen, Bild inklusive.
14. Eine `.php`-Datei mit Bild-Endung hochladen → **abgelehnt**; ein gültiges Bild landet
    re-enkodiert unter Zufallsnamen und ist über `/uploads/...` **nicht ausführbar**.
15. 6× falsches Passwort → Sperre greift; nach erfolgreichem Login ist der Zähler zurückgesetzt.
16. Ein Gerät über `devices.php` abmelden → dieses Gerät verlangt beim nächsten Aufruf wieder
    das Passwort, die anderen nicht.
17. Backup erstellen, herunterladen, wieder einspielen → Datenbestand unverändert.
18. Container `down` + `up --build` → Daten und Bilder sind vollständig da.
19. **Expertenmodus** (§7.4): Auf der Kontoseite „Sätze einzeln erfassen" einschalten — bei
    laufendem Training ist der Schalter gesperrt. Training starten, bei einer Übung **ohne
    Vorgeschichte** einmal „+ Satz" antippen → es erscheint eine leere Zeile, **kein** roter
    Rand und **kein** „Erneut versuchen". Bei einer Übung **mit** Vorgeschichte dreimal
    „+ Satz": Die Vorschläge entsprechen Satz für Satz der letzten Einheit, die
    Wiederholungen lassen sich mit −/+ korrigieren. **„x/n" bleibt dabei stehen** — die
    Position ist protokolliert, aber nicht fertig; „Tauschen" ist trotzdem schon gesperrt.
    Erst das Häkchen lässt „x/n" springen, klappt den Satzblock zu und springt zur nächsten
    offenen Übung. Häkchen wieder entfernen → „x/n" geht zurück, **die Sätze bleiben
    stehen**. Handy sperren und App neu öffnen → die Sätze stehen noch. Flugmodus
    einschalten, einen Satz ändern → der grüne Balken strichelt (er wird **nicht** orange,
    und die Karte ändert ihre Höhe nicht), oben erscheint die Leiste mit der Anzahl, Beenden
    ist gesperrt; Flugmodus aus → der Satz wird nachgeholt. Training beenden; im
    Verlauf steht unter „Einheiten" die Folge `12×40 · 10×40 · 9×45`, unter „Übungen" laufen
    Volumen- und 1RM-Kurve samt Näherungs-Hinweis. Gegenprobe: Expertenmodus wieder
    ausschalten → die alte Ansicht ist unverändert da, die protokollierten Einheiten bleiben
    lesbar.
