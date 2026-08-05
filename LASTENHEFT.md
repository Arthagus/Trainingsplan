# Lastenheft — Trainingsplan-Web-App

Zentral gepflegte Web-App zur Verwaltung und mobilen Nutzung von Studio-Trainingsplänen
für mehrere Benutzer. Umsetzung mit Claude Code.

> **Stand:** überarbeitete Fassung vom 2026-08-05. Gegenüber der Erstfassung wurden fünf
> Widersprüche aufgelöst (§4 `plan_exercise_id`, §6.3 Soft-Delete, §7.5 Tausch vor Sessionstart,
> §7.6 Auto-Ende, §7.3 Gewichts-Fallback) und vier Funktionen ergänzt (§6.5 Wartung, §7.7
> Passwortwechsel und Geräteverwaltung, §7.6 Hinweis bei alter Einheit). Die Erstfassung liegt
> im Git-Verlauf (Commit „Lastenheft (Originalfassung)").

---

## 1. Zweck & Nutzungsszenario

Ein Administrator pflegt über eine Weboberfläche Übungen und Trainingspläne für mehrere
Benutzer. Die Benutzer rufen ihren Plan im Studio am Smartphone auf, sehen die anstehenden
Übungen inkl. des zuletzt verwendeten Gewichts, haken erledigte Übungen ab und können bei
Bedarf eine Übung durch eine Alternative derselben Muskelgruppe ersetzen. Ein Training wird
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
`cache-first`. **Kein einziges HTML-Dokument und keine API-Antwort** darf gecacht werden
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
├── login.php  logout.php  password.php  devices.php
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
├── schema.sql  Dockerfile  docker-compose.yml  .env.example
├── CLAUDE.md  README.md  LASTENHEFT.md
└── doku/   nginx-vhost.conf  deployment.md
```

---

## 3. Deployment & Initialisierung

Die App läuft als **Docker-Container** auf einem Hetzner-Rootserver, erreichbar über eine
eigene **Subdomain mit Let's-Encrypt-Zertifikat**. Ein Reverse-Proxy (Nginx auf dem Host)
leitet auf den veröffentlichten Container-Port weiter.

**Container-Anforderungen:**

- Basis-Image `php:8.3-apache`
- Zusätzlich installiert: PHP-Extensions `pdo_sqlite` und `gd` (Bildverarbeitung),
  Apache-Modul `rewrite`
- Beispiel-Dockerfile-Kern:
  ```dockerfile
  FROM php:8.3-apache
  RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev \
   && docker-php-ext-configure gd --with-jpeg \
   && docker-php-ext-install pdo_sqlite gd \
   && a2enmod rewrite
  COPY ./app /var/www/html
  ```

**Persistenz (zwingend):** Zwei Pfade müssen als Volume/Bind-Mount außerhalb des Images
liegen, sonst gehen bei jedem Rebuild alle Daten verloren:

- `/var/www/html/data` — die SQLite-Datenbankdatei
- `/var/www/html/uploads` — die hochgeladenen Übungsbilder

**Port-Binding:** Der Container-Port wird ausschließlich an `127.0.0.1` gebunden, nie an
`0.0.0.0`. Das ist die Voraussetzung dafür, dass dem `X-Forwarded-Proto`-Header vertraut werden
darf (siehe §5) — er ist nur dann fälschungssicher, wenn der Container nicht direkt von außen
erreichbar ist.

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
Verbindung intern **unverschlüsselt über HTTP** auf `localhost`. Der Nginx reicht das
ursprüngliche Protokoll über den Header `X-Forwarded-Proto` (sowie `X-Forwarded-For`,
`X-Real-IP`, `Host`) weiter. Die App darf sich zur Protokoll-Erkennung deshalb **nicht** auf
`$_SERVER['HTTPS']` verlassen (intern leer/`off`), sondern muss `X-Forwarded-Proto`
auswerten — siehe §5.

---

## 4. Datenmodell

SQLite-Tabellen (Feldnamen als Vorschlag, Typen sinngemäß).

**Fremdschlüssel:** `PRAGMA foreign_keys = ON` ist gesetzt, das `ON DELETE`-Verhalten ist
deshalb je Beziehung explizit festzulegen (siehe §4.1).

**muscle_groups** — kontrolliertes Vokabular für Muskelpartien
- `id`, `name_de`, `name_en`, `sort_order`
- Standardwerte geseedet: Brust, Rücken, Schultern, Bizeps, Trizeps, Beine, Waden, Bauch
  (im Admin erweiterbar).

**exercises** — Übungen
- `id`, `name_de`, `name_en`, `muscle_group_id` (FK → muscle_groups),
  `description`, `image_path`, `archived` (bool, Default 0),
  `archived_at` (datetime, nullable), `created_at`
- **`archived`** ersetzt das harte Löschen (§6.3). Archivierte Übungen verschwinden aus
  Dropdowns und Tauschvorschlägen, bleiben aber für die Historie referenzierbar und sind im
  Admin jederzeit einsehbar (§6.3).

**users** — Benutzer
- `id`, `name` (eindeutiger Login-Name), `password_hash`, `is_admin` (bool),
  `must_change_password` (bool, Default 0), `last_plan_id` (FK → plans, nullable), `created_at`

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
  `plan_id` (FK), `weight` (decimal, **nullable**), `reps` (int, nullable),
  `performed_at` (datetime)
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
  ORDER BY performed_at DESC LIMIT 1`. So geht ein Gewicht nicht verloren, nur weil es einmal
  nicht eingetragen wurde. `reps` wird nach derselben Regel vorbelegt.

**exercise_swaps** — einmaliger Übungstausch, an die Einheit gebunden (siehe §7.5)
- `id`, `session_id` (FK), `plan_exercise_id` (FK), `replacement_exercise_id` (FK)
- Eindeutig ist `(session_id, plan_exercise_id)`.
- Gilt, solange die zugehörige Einheit offen ist.

### 4.1 Lösch- und Kaskadenverhalten

| Aktion | Verhalten |
|---|---|
| Übung löschen | Regelfall: kein hartes Löschen, sondern `archived = 1` (§6.3) — Historie bleibt vollständig. Hartes Löschen nur, wenn die Übung weder in einem Plan referenziert wird noch `workout_log`-Einträge hat; dann inkl. Bilddatei und Thumbnail. |
| Muskelgruppe löschen | Nur zulässig, wenn keine Übung sie referenziert — sonst Hinweis. Umbenennen ist immer erlaubt. |
| Plan löschen | Verboten, solange eine offene Einheit auf ihn zeigt. Sonst: `plan_exercises` kaskadiert, `users.last_plan_id` → `ON DELETE SET NULL`, `sessions`/`workout_log` bleiben erhalten. |
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
- Benutzer anlegen (Name, Passwort, Admin-Flag), Passwort zurücksetzen, Benutzer löschen
  (Löschverhalten siehe §4.1). Ein zurückgesetztes Passwort setzt `must_change_password = 1`.
- Der letzte verbliebene Admin kann weder gelöscht noch degradiert werden.

**6.2 Muskelgruppen**
- Liste einsehen, erweitern, umbenennen und sortieren (Standardwerte vorgeseedet).
- Löschen nur, wenn keine Übung die Gruppe referenziert.

**6.3 Übungsverwaltung**
- CRUD für Übungen mit Feldern: Name (deutsch + englisch), Muskelgruppe (Dropdown aus
  `muscle_groups`), Beschreibung, Bild.
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
- Pro Benutzer **einen oder zwei** Pläne anlegen (Maximum 2 erzwingen — die Alternation in
  §7.6 ist für den Zwei-Plan-Fall definiert).
- Übungen zu einem Plan hinzufügen/entfernen und **in Reihenfolge sortieren**.
- **Sperre bei offener Einheit:** Hat der betroffene Benutzer eine offene Einheit, ist die
  Planbearbeitung blockiert (Hinweis anzeigen). Sonst würde sich `n` in der laufenden
  Fortschrittsanzeige „x/n" mitten im Training verschieben.

**6.5 Wartung & Backup**
- Eigene Seite `maintenance.php`, nur für Admins, nach dem Muster aus
  `Speisekarte/doku/maintenance_anleitung.md`.
- Aktionen: `backup`, `restore`, `upload`, `delete_backup`, `vacuum`, `integrity`,
  `optimize`, `checkpoint`.
- Backup als ZIP in zwei Varianten: **vollständig** (DB + Bilder) und **ohne Bilder**.
- Ein Restore prüft `PRAGMA integrity_check` erst auf einer Kopie, bevor die Live-DB ersetzt
  wird.
- Download über `download_backup.php` mit `basename()`-Filter und Extension-Whitelist
  (`zip`, `db`).

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
  - Name (deutsch, optional englisch), Muskelgruppe, Bild-Thumbnail (antippbar für
    Beschreibung/großes Bild),
  - **Gewichts-Eingabefeld, vorbelegt mit dem zuletzt protokollierten Gewicht** nach der
    Regel in §4 (leere Werte werden übersprungen; leer, falls noch nie ein Gewicht
    protokolliert wurde). Das Feld **darf leer bleiben** (z. B. Bauch/Dips ohne
    Zusatzgewicht), optional Wiederholungen (gleiche Vorbelegungsregel),
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
  Gewicht, optional Wiederholungen, `performed_at`). Ein Eintrag pro Einheit + Planposition.
- Wird das Gewicht **nach** dem Abhaken korrigiert, speichert die Änderung per `onchange`
  sofort (Upsert auf denselben Eintrag). Es geht kein Wert dadurch verloren, dass der Benutzer
  die Seite verlässt, ohne erneut abzuhaken.
- **Ab-wählen** von „Erledigt" (versehentliches Häkchen) löscht den zugehörigen
  `workout_log`-Eintrag dieser Einheit wieder.
- **Wiederherstellung des Erledigt-Status:** Beim Laden gilt eine Planposition als erledigt,
  wenn für die aktive Einheit + Position bereits ein Log-Eintrag existiert. Der Fortschritt
  geht also nicht verloren, wenn das Handy zwischendurch geschlossen wird.
- **Fehlerfall:** Schlägt ein Speichern fehl, bleibt das Häkchen sichtbar unbestätigt und ein
  Wiederholen-Button erscheint (§2).

**7.5 Übungstausch (Alternativen)**
- „Übung tauschen" schlägt alternative Übungen **derselben Muskelgruppe** vor
  (`WHERE muscle_group_id = <aktuell> AND id != <aktuell> AND archived = 0`).
  Gibt es keine Alternative, wird das als Hinweis ausgegeben, nicht als leere Liste.
- Nach Auswahl fragt die App den Modus:
  - **Nur diese Einheit (einmalig einstreuen):** `exercise_swaps`-Eintrag für die aktive
    Einheit + das betreffende `plan_exercise`. Die Ansicht zeigt die Ersatzübung; wird
    abgehakt, protokolliert der Log die Ersatzübung in `exercise_id` bei unveränderter
    `plan_exercise_id`. **Der Plan bleibt unverändert** — in der nächsten Einheit steht wieder
    die Original-Übung.
    **Existiert noch keine offene Einheit, startet dieser Tausch die Einheit** (siehe §7.6).
  - **Dauerhaft (neue Default-Übung):** Der `plan_exercises`-Eintrag wird geändert
    (`exercise_id` = Ersatzübung). Ab sofort fester Bestandteil des Plans.
    Für Positionen, die in der laufenden Einheit **bereits abgehakt** sind, ist dieser Modus
    gesperrt (Hinweis anzeigen) — sonst zeigte der Log auf die alte, die Ansicht auf die neue
    Übung.
- Besteht für eine Position sowohl ein `exercise_swaps`-Eintrag der offenen Einheit als auch
  ein geänderter Plan, **gewinnt der Swap** für die Dauer dieser Einheit.
- Das vorbelegte Gewicht folgt immer der tatsächlich angezeigten Übung (Historie ist pro
  Übung geführt).

**7.6 Trainingseinheit (Session) & Plan-Alternation**
- **Start:** Sobald in einer Situation ohne offene Einheit die **erste zustandsändernde
  Trainingsaktion** stattfindet, wird eine neue `sessions`-Zeile angelegt (`started_at` =
  jetzt, `plan_id` = aktueller Plan). Zustandsändernd sind:
  1. eine Übung als „erledigt" markieren, **oder**
  2. eine Übung „nur für diese Einheit" tauschen (§7.5).

  Bloßes Anschauen startet **keine** Einheit. Punkt 2 ist notwendig, weil ein
  `exercise_swaps`-Eintrag eine `session_id` benötigt — und der reale Ablauf im Studio lautet:
  Plan öffnen, Gerät besetzt vorfinden, tauschen, *dann* trainieren.
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

  Beim Ende wird `ended_at` = jetzt gesetzt und `users.last_plan_id` = dieser Plan.
- Pro Benutzer ist **höchstens eine** Einheit offen. Der „Training beendet"-Button ist während
  einer offenen Einheit stets erreichbar, sodass eine vergessene offene Einheit jederzeit
  geschlossen werden kann.
- **Hinweis bei alter Einheit:** Ist `started_at` der offenen Einheit älter als 12 Stunden,
  zeigt die App beim Start ein Banner „Deine Einheit läuft seit … — fortsetzen oder beenden?"
  mit beiden Buttons. Es gibt **kein** automatisches Schließen (das würde §7.2 aushebeln), aber
  ohne diesen Hinweis blockiert eine einmal vergessene Einheit dauerhaft die Plan-Alternation.
- **Plan-Alternation:** Bei nur einem Plan wird immer dieser genommen. Bei zwei Plänen (und
  keiner offenen Einheit) schlägt die App den Plan vor, der **nicht** `users.last_plan_id`
  entspricht. Der Vorschlag ist vor dem Start manuell auf den anderen Plan umschaltbar.

**7.7 Konto & Geräte**
- **Passwort ändern** (`password.php`): Der Benutzer ändert sein eigenes Passwort. Das alte
  Passwort wird per `password_verify()` geprüft, danach `session_regenerate_id(true)`.
  Bei gesetztem `must_change_password` ist diese Seite die einzige erreichbare (§3, §7.1).
- **Geräte** (`devices.php`): Liste der aktiven `remember_tokens` des Benutzers (angelegt am,
  zuletzt genutzt, Gerätekennung aus `user_agent`, das aktuelle Gerät markiert), einzeln
  abmeldbar, plus **„Auf allen Geräten abmelden"**. Das ist die Oberfläche zu der in §5
  zugesagten serverseitigen Widerrufbarkeit.

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
- Offline-Betrieb / Sync-Queue (§2: das Netz im Studio ist stabil).
- Getrennte Erfassung mehrerer Sätze pro Übung (v1: ein Gewicht/Wiederholungen pro Übung
  und Einheit).
- Fortschritts-Charts/Statistik-Auswertungen.
- Passwort-Reset per E-Mail (Reset erfolgt durch den Admin; der Benutzer kann sein Passwort
  aber selbst ändern, §7.7).
- Mehr als zwei Pläne pro Benutzer / kalenderbasierte Wochenpläne.

---

## 10. Spätere Erweiterungen (vorgemerkt)

- Fortschritts-Charts je Übung (Gewichtsverlauf über die Zeit) — die Daten liegen durch
  `workout_log` bereits vor.
- Satz-genaues Logging (mehrere Sätze pro Übung).
- Mehr als zwei Pläne bzw. Wochentags-/Kalenderplanung.
- Trainingshistorie/Kalenderansicht abgeschlossener Einheiten.
- Offline-Queue, falls sich die Netzsituation im Studio ändert (§2: der Schreibpfad ist
  über `apiFetch()` dafür bereits gekapselt).

---

## 11. Abnahmekriterien

Manuell am echten Handy über die Subdomain zu prüfen — `Secure`-Cookies, Remember-Me und die
PWA-Installation lassen sich lokal nicht sinnvoll testen.

1. Admin legt Benutzer, Muskelgruppen, 5 Übungen mit Bild und 2 Pläne an.
2. Benutzer meldet sich am Handy an **mit** „Angemeldet bleiben" und installiert die PWA über
   „Zum Startbildschirm hinzufügen".
3. Browser schließen, App vom Startbildschirm öffnen → **kein erneuter Login**; der Token in
   der DB ist rotiert, `last_used_at` aktualisiert.
4. Erste Übung abhaken → Einheit entsteht, Gewicht wird gespeichert.
5. Zweite Übung tauschen („nur diese Einheit") → Ersatzübung erscheint, Plan bleibt unverändert.
6. Handy sperren, App neu öffnen → Fortschritt und Häkchen sind erhalten.
7. Ein Häkchen ab-wählen → Log-Eintrag verschwindet, Zähler springt zurück.
8. Alle Übungen abhaken → **Abschluss-Bestätigung** erscheint; „Noch nicht" lässt die Einheit
   offen, ein Häkchen ist danach weiterhin ab-wählbar.
9. „Training beendet" → `ended_at` gesetzt, `last_plan_id` aktualisiert.
10. App neu starten → **der andere Plan** wird vorgeschlagen (Alternation).
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
