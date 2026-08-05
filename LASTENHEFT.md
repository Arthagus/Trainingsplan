# Lastenheft — Trainingsplan-Web-App

Zentral gepflegte Web-App zur Verwaltung und mobilen Nutzung von Studio-Trainingsplänen
für mehrere Benutzer. Umsetzung mit Claude Code.

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

- **Backend:** PHP 8.3, ohne Framework
- **Datenbank:** SQLite (Datei-basiert), Zugriff ausschließlich über PDO mit
  Prepared Statements
- **Frontend:** serverseitig gerendertes HTML + Vanilla JavaScript, kein Framework,
  kein Build-Step
- **UI-Sprache:** Deutsch
- **Progressive Web App (PWA):** Web-App-Manifest + Service Worker, damit die App am
  Smartphone „zum Startbildschirm hinzufügen" installierbar ist und im Vollbild läuft.
  Der Service Worker cacht nur die App-Shell (statische Assets); Live-Daten benötigen
  eine Verbindung.

**Konventionen:** Claude Code soll den Konventionen der bestehenden Repos des Betreibers
(**Body-Fat-Tracker** und **Speisekarte**) folgen — Verzeichnisstruktur, PDO-Zugriffs-Wrapper,
Routing- und Template-Stil. Kein dritter, neu erfundener Projektstil.

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

**Konfiguration** über Umgebungsvariablen, keine Secrets im Image/Repository:

- `ADMIN_USER`, `ADMIN_PASSWORD` — für die einmalige Erst-Admin-Erzeugung (siehe unten)
- `APP_SECRET` — serverseitiger Pepper/Secret für Hashing/Tokens
- `DB_PATH` — Pfad zur SQLite-Datei (im `data`-Volume)
- `TZ` — Zeitzone, Standard `Europe/Vienna`

**Initialisierung & Erst-Admin (Bootstrapping):**

- Beim ersten Start legt die App die DB an, falls sie fehlt: Schema aus einer mitgelieferten
  `schema.sql`, danach Seeding der Standard-Muskelgruppen.
- Existiert noch kein Benutzer, wird **ein initialer Admin aus `ADMIN_USER`/`ADMIN_PASSWORD`**
  erzeugt (Passwort beim ersten Boot gehasht). Damit ist das Henne-Ei-Problem ohne
  Self-Signup gelöst. Alle weiteren Benutzer entstehen über die Admin-Oberfläche.

**Zeitzone:** Container-`TZ` und PHP-Default-Zeitzone auf `Europe/Vienna` setzen. Hinweis:
Die Trainingslogik ist bewusst **session-basiert** (§7) und hängt nicht am Kalendertag; die
Zeitzone betrifft daher vor allem korrekte Zeitstempel und Datumsanzeigen.

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

SQLite-Tabellen (Feldnamen als Vorschlag, Typen sinngemäß):

**muscle_groups** — kontrolliertes Vokabular für Muskelpartien
- `id`, `name_de`, `name_en`, `sort_order`
- Standardwerte geseedet: Brust, Rücken, Schultern, Bizeps, Trizeps, Beine, Waden, Bauch
  (im Admin erweiterbar).

**exercises** — Übungen
- `id`, `name_de`, `name_en`, `muscle_group_id` (FK → muscle_groups),
  `description`, `image_path`, `created_at`

**users** — Benutzer
- `id`, `name` (eindeutiger Login-Name), `password_hash`, `is_admin` (bool),
  `last_plan_id` (FK → plans, nullable), `created_at`

**remember_tokens** — „Angemeldet bleiben"-Tokens (siehe §5)
- `id`, `user_id` (FK), `selector` (eindeutig), `validator_hash`, `expires_at`, `created_at`

**plans** — Trainingspläne (1–2 pro Benutzer)
- `id`, `user_id` (FK), `name`, `sort_order`, `created_at`

**plan_exercises** — Übungen innerhalb eines Plans, geordnet
- `id`, `plan_id` (FK), `exercise_id` (FK), `sort_order`

**sessions** — Trainingseinheiten (siehe §7.6)
- `id`, `user_id` (FK), `plan_id` (FK), `started_at` (datetime), `ended_at` (datetime, nullable)
- **Offene Einheit** = `ended_at IS NULL`. Pro Benutzer darf höchstens **eine** Einheit offen
  sein. Die Einheit ist die Einheit der Trainingslogik — nicht der Kalendertag.

**workout_log** — Protokoll je Übung innerhalb einer Einheit (Basis für „letztes Gewicht")
- `id`, `session_id` (FK), `user_id` (FK), `exercise_id` (FK), `plan_id` (FK),
  `weight` (decimal, **nullable**), `reps` (int, nullable), `performed_at` (datetime)
- Genau **ein Eintrag pro `session_id` + `exercise_id`** (Upsert beim Abhaken, Löschen beim
  Ab-wählen).
- Das vorbelegte „letzte Gewicht" einer Übung ist der jüngste `workout_log.weight` dieses
  Benutzers für diese Übung **über alle Einheiten hinweg**.

**exercise_swaps** — einmaliger Übungstausch, an die Einheit gebunden (siehe §7.5)
- `id`, `session_id` (FK), `plan_exercise_id` (FK), `replacement_exercise_id` (FK)
- Gilt, solange die zugehörige Einheit offen ist.

---

## 5. Sicherheit & Authentifizierung (harte Anforderung)

- **Passwörter** nur als Hash speichern (`password_hash()` mit `PASSWORD_ARGON2ID`,
  ersatzweise bcrypt), Verifikation mit `password_verify()`. Niemals Klartext.
  Optional serverseitiger Pepper aus `APP_SECRET`.
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
    den vorgelagerten, vertrauenswürdigen Proxy erreichbar ist (nicht direkt am Container-Port).
- **„Angemeldet bleiben" (Remember-Me) nach Selector/Validator-Muster** — Kernanforderung,
  damit das Passwort real nur einmal pro Gerät eingegeben werden muss:
  - Beim Login Zufalls-Token erzeugen. Cookie enthält `selector:validator`.
  - In der DB nur der **gehashte** Validator plus Ablaufdatum (60–90 Tage).
  - Bei Wiederkehr: Lookup über `selector`, **konstantzeit-Vergleich** des Validators,
    danach **Token rotieren** (neuen Validator setzen).
  - Serverseitig jederzeit widerrufbar: Löschen der DB-Zeile loggt das Gerät sofort aus.
- **CSRF-Schutz:** Token auf allen zustandsändernden Requests (Gewicht/Erledigt speichern,
  Übung tauschen, Training beenden, Admin-CRUD).
- **Kein Self-Signup.** Benutzeranlage ausschließlich über die Admin-Oberfläche.
- **Brute-Force-Bremse** am Login: Fehlversuchszähler mit ansteigender Verzögerung bzw.
  temporärer Sperre. (Optionale zusätzliche Härtung über fail2ban am Reverse-Proxy —
  außerhalb des App-Umfangs.)
- **Rollen:** ein `is_admin`-Flag. Admin pflegt Übungen/Pläne/Benutzer; normale Benutzer
  sehen ihren Plan und protokollieren Gewichte.
- **Zugriffskontrolle / IDOR-Schutz:** Ein normaler Benutzer darf ausschließlich **seine
  eigenen** Pläne, Einheiten und Logs sehen und ändern. Jede ID-basierte Anfrage (Plan, Log,
  Einheit) prüft serverseitig die Eigentümerschaft (`user_id`-Abgleich); Admin-Funktionen nur
  mit gesetztem `is_admin`.
- **Upload-Sicherheit:** Nur JPEG/PNG; die Datei serverseitig als **echtes Bild verifizieren**
  (Inhalt prüfen, nicht nur die Endung) und via GD neu enkodieren/normalisieren; unter einem
  **zufälligen Dateinamen** im `uploads`-Volume ablegen. Der Webserver muss so konfiguriert
  sein, dass in `/uploads` **keine Skriptausführung** möglich ist (rein statische
  Auslieferung, PHP-Handler dort deaktiviert).
- **SQL-Injection:** ausschließlich PDO Prepared Statements. Alle Ausgaben kontextgerecht
  escapen (XSS-Schutz).

---

## 6. Admin-Weboberfläche

Nur für Benutzer mit `is_admin`.

**6.1 Benutzerverwaltung**
- Benutzer anlegen (Name, Passwort, Admin-Flag), Passwort zurücksetzen, Benutzer löschen.

**6.2 Muskelgruppen**
- Liste einsehen/erweitern (Standardwerte vorgeseedet).

**6.3 Übungsverwaltung**
- CRUD für Übungen mit Feldern: Name (deutsch + englisch), Muskelgruppe (Dropdown aus
  `muscle_groups`), Beschreibung, Bild.
- **Bild-Upload** gemäß §5 (Validierung, Re-Enkodierung, zufälliger Dateiname).
- **Löschschutz:** Eine Übung kann nicht gelöscht werden, solange sie in einem Plan
  referenziert wird (Hinweis anzeigen). Die `workout_log`-Historie bleibt in jedem Fall
  erhalten.

**6.4 Planverwaltung**
- Pro Benutzer **einen oder zwei** Pläne anlegen (Maximum 2 erzwingen — die Alternation in
  §7.6 ist für den Zwei-Plan-Fall definiert).
- Übungen zu einem Plan hinzufügen/entfernen und **in Reihenfolge sortieren**.

---

## 7. Handy-Ansicht & Trainingslogik

**7.1 Login**
- Login-Formular mit „Angemeldet bleiben"-Option (Remember-Me aus §5).

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
  - **Gewichts-Eingabefeld, vorbelegt mit dem zuletzt protokollierten Gewicht** (leer, falls
    noch nie protokolliert). Das Feld **darf leer bleiben** (z. B. Bauch/Dips ohne
    Zusatzgewicht), optional Wiederholungen,
  - **„Erledigt"-Häkchen**,
  - Aktion **„Übung tauschen"** (§7.5).
- Fortschrittsanzeige „x/n erledigt" und der **„Training beendet"-Button** (§7.6) sind während
  einer offenen Einheit sichtbar.

**7.4 Gewichts-Logging & „Erledigt"**
- Das Gewicht muss **nicht** jedes Mal neu eingegeben werden — Default ist der letzte Wert;
  der Benutzer passt es nur bei Änderung an. „Erledigt" funktioniert **auch ohne Gewichtswert**.
- Beim Setzen von „Erledigt" wird ein `workout_log`-Eintrag für die **aktive Einheit** +
  Übung geschrieben bzw. aktualisiert (`session_id`, Gewicht, optional Wiederholungen,
  `performed_at`). Ein Eintrag pro Einheit + Übung.
- **Ab-wählen** von „Erledigt" (versehentliches Häkchen) löscht den zugehörigen
  `workout_log`-Eintrag dieser Einheit wieder.
- **Wiederherstellung des Erledigt-Status:** Beim Laden gilt eine Übung als erledigt, wenn für
  die aktive Einheit + Übung bereits ein Log-Eintrag existiert. Der Fortschritt geht also
  nicht verloren, wenn das Handy zwischendurch geschlossen wird.

**7.5 Übungstausch (Alternativen)**
- „Übung tauschen" schlägt alternative Übungen **derselben Muskelgruppe** vor
  (`WHERE muscle_group_id = <aktuell> AND id != <aktuell>`).
- Nach Auswahl fragt die App den Modus:
  - **Nur diese Einheit (einmalig einstreuen):** `exercise_swaps`-Eintrag für die aktive
    Einheit + das betreffende `plan_exercise`. Die Ansicht zeigt die Ersatzübung; wird
    abgehakt, protokolliert der Log die Ersatzübung. **Der Plan bleibt unverändert** — in der
    nächsten Einheit steht wieder die Original-Übung.
  - **Dauerhaft (neue Default-Übung):** Der `plan_exercises`-Eintrag wird geändert
    (`exercise_id` = Ersatzübung). Ab sofort fester Bestandteil des Plans.
- Das vorbelegte Gewicht folgt immer der tatsächlich angezeigten Übung (Historie ist pro
  Übung geführt).

**7.6 Trainingseinheit (Session) & Plan-Alternation**
- **Start:** Sobald in einer Situation ohne offene Einheit die **erste Übung** als „erledigt"
  markiert wird, wird eine neue `sessions`-Zeile angelegt (`started_at` = jetzt, `plan_id` =
  aktueller Plan). Bloßes Anschauen startet **keine** Einheit.
- **Mitternachts-Robustheit:** Die Einheit ist die Einheit der Logik, nicht der Kalendertag.
  Eine offene Einheit bleibt aktiv, auch wenn das Datum während des Trainings wechselt.
- **Ende — auf zwei Wegen:**
  1. **Automatisch**, sobald **alle** Übungen des Plans als „erledigt" markiert sind, oder
  2. **manuell** über den **„Training beendet"-Button** — nötig, wenn absichtlich Übungen
     ausgelassen werden (z. B. aus Zeitmangel).
  Beim Ende wird `ended_at` = jetzt gesetzt und `users.last_plan_id` = dieser Plan.
- Pro Benutzer ist **höchstens eine** Einheit offen. Der „Training beendet"-Button ist während
  einer offenen Einheit stets erreichbar, sodass eine vergessene offene Einheit jederzeit
  geschlossen werden kann.
- **Plan-Alternation:** Bei nur einem Plan wird immer dieser genommen. Bei zwei Plänen (und
  keiner offenen Einheit) schlägt die App den Plan vor, der **nicht** `users.last_plan_id`
  entspricht. Der Vorschlag ist vor dem Start manuell auf den anderen Plan umschaltbar.

---

## 8. Nicht-funktionale Anforderungen

- Mobile-first, responsive Gestaltung.
- Als PWA installierbar (Manifest + Service Worker, App-Shell-Caching).
- Geringe Abhängigkeiten, kein Build-Step, kein Framework.
- Durchgängig Prepared Statements und kontextgerechtes Escaping.
- Deutschsprachige Oberfläche.

---

## 9. Nicht im Umfang von v1

- Native Apps, Push-Benachrichtigungen.
- Getrennte Erfassung mehrerer Sätze pro Übung (v1: ein Gewicht/Wiederholungen pro Übung
  und Einheit).
- Fortschritts-Charts/Statistik-Auswertungen.
- Passwort-Reset per E-Mail (Reset erfolgt durch den Admin).
- Mehr als zwei Pläne pro Benutzer / kalenderbasierte Wochenpläne.

---

## 10. Spätere Erweiterungen (vorgemerkt)

- Fortschritts-Charts je Übung (Gewichtsverlauf über die Zeit) — die Daten liegen durch
  `workout_log` bereits vor.
- Satz-genaues Logging (mehrere Sätze pro Übung).
- Mehr als zwei Pläne bzw. Wochentags-/Kalenderplanung.
- Trainingshistorie/Kalenderansicht abgeschlossener Einheiten.
