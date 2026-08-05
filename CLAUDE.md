# CLAUDE.md — Trainingsplan-Web-App

Arbeitsanweisungen für Claude Code in diesem Repo. `LASTENHEFT.md` ist maßgeblich für das
**Was** (Fachlichkeit, Abnahmekriterien); diese Datei regelt das **Wie** (Konventionen).
Bei Widerspruch gewinnt der Code als Beschreibung des Ist-Zustands, das Lastenheft als
Beschreibung des Soll-Zustands.

## Lokale Entwicklung

```bash
php -S 127.0.0.1:8100 -t "$(pwd)"      # Dev-Server, im Hintergrund starten
```

Kein Build-Step, kein Paketmanager, kein Composer, kein npm, kein Migrationstool.
Port 8100, weil 8765 (Body-Fat-Tracker) und 8090 (Speisekarte) bereits belegt sind.

Prüfen statt Tests:

```bash
find . -name '*.php' -not -path './data/*' -exec php -l {} \;
find assets api -name '*.js' -exec node --check {} \;
```

Nicht lokal prüfbar und nur über die Subdomain testbar: `Secure`-Cookies, Remember-Me,
PWA-Installation, `X-Forwarded-Proto`-Verhalten.

## Architektur

**Stack:** PHP 8.3 ohne Framework, SQLite über PDO, serverseitig gerendertes HTML +
Vanilla JS. `declare(strict_types=1)` in **jeder** PHP-Datei, alle Funktionen typisiert.

**Verzeichnisse:** siehe `LASTENHEFT.md` §2.2. Kurzfassung: Seiten liegen im Root,
`lib/` enthält die Bausteine, `api/` die JSON-Endpunkte, `assets/` CSS/JS/PWA.
`lib/` und `data/` bekommen je eine `.htaccess` mit `Require all denied`.

**Pflicht-Boilerplate am Kopf jeder geschützten Seite:**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
bootstrap_session();
require_login();          // in api/*: require_login_api()
require_admin();          // nur auf Admin-Seiten
```

**Seite = `.php` + gleichnamige `.js`.** `index.php` lädt `index.js`, `admin_plans.php`
lädt `admin_plans.js`. Geteilte Hilfsmittel gehören in `assets/app.js`.

**Layout über Partials, nicht kopiert.** `lib/view_header.php` und `lib/view_footer.php`.
Das ist die bewusste Abweichung von Body-Fat-Tracker und Speisekarte, die ihren
`<head>`/`<nav>` in jede Seite duplizieren.

## Datenbank

`lib/db.php` stellt `db(): PDO` als Singleton bereit. Konfiguration wörtlich so:

```php
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
```

- **Pfad aus der Umgebung:** `getenv('DB_PATH')`, Fallback `__DIR__ . '/../data/trainingsplan.db'`.
  Anders als in den Vorlagen-Repos ist der Pfad hier **nicht** hartkodiert — der Container
  braucht ihn konfigurierbar.
- **Schema in `schema.sql`**, ausschließlich `CREATE TABLE IF NOT EXISTS` / `CREATE INDEX IF NOT
  EXISTS`, wird bei jedem Start ausgeführt. Neue Spalten kommen als idempotenter
  `PRAGMA table_info`-Block in `init_schema()` dazu — **kein** separates Migrationsverzeichnis.
- **Ausschließlich Prepared Statements.** Kein String-Interpolieren in SQL, nirgends.
- **Zeitstempel immer in PHP erzeugen** (`date('Y-m-d H:i:s')`), nie `CURRENT_TIMESTAMP` —
  SQLite liefert dort UTC, die App läuft auf `Europe/Vienna`.

## Sicherheit — nicht verhandelbar

Die Punkte aus `LASTENHEFT.md` §5 sind harte Anforderungen. Die Fallstricke, die beim
Implementieren am ehesten übersehen werden:

- **`X-Forwarded-Proto`, nicht `$_SERVER['HTTPS']`.** Intern läuft die Verbindung unverschlüsselt.
  Wer auf `HTTPS` prüft, baut eine Redirect-Schleife und kaputte `Secure`-Cookies.
- **Cookies immer mit `Secure`**, auch wenn die Verbindung intern HTTP ist.
- **Kein Passwort-Pepper.** `APP_SECRET` wird ausschließlich für den Remember-Me-Validator-Hash
  benutzt. Ein Pepper würde bei Verlust des Secrets alle Passwörter unbrauchbar machen.
- **Remember-Me:** Selector/Validator, nur der **gehashte** Validator in der DB,
  `hash_equals()` für den Vergleich, **Rotation bei jeder Nutzung**.
- **IDOR:** Jede ID-basierte Anfrage prüft `user_id`-Eigentümerschaft serverseitig.
  Ein Plan, eine Einheit oder ein Log gehören immer genau einem Benutzer.
- **CSRF auf jedem Nicht-GET-Request**, auch in `api/*`.
- **Uploads:** MIME aus dem **Inhalt** per `finfo`, nie aus der Endung; Re-Enkodierung via GD;
  Zufallsdateiname; Auslieferung über `image.php` mit `realpath`-Path-Jail.

## Frontend

- **Ein `fetch`-Wrapper:** `apiFetch(url, options)` in `assets/app.js`. Setzt `X-CSRF-Token`,
  JSON-encodet Objekt-Bodies, reicht `FormData` durch, entpackt `{ok, data}`, wirft mit
  `err.fields`, leitet bei 401 auf `login.php`. **Kein direkter `fetch()`-Aufruf in Seiten-JS.**
  Das ist auch die Stelle, an der eine Offline-Queue später nachrüstbar wäre.
- **JSON-Envelope serverseitig:** `json_ok(array $data = [], int $status = 200)` und
  `json_err(string $error, int $status = 400, array $fields = [])`.
- **Escaping:** serverseitig `h()` aus `lib/helpers.php`, clientseitig `escapeHtml()` aus
  `assets/app.js` vor jedem `innerHTML`.
- **Zahleneingaben:** `type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"` —
  nicht `type="number"`, sonst bricht das Dezimalkomma am Handy.
- **Service Worker:** cacht **nur** `assets/*.css`, `assets/*.js`, `manifest.json`, Icons
  (`cache-first`). **Niemals HTML oder API-Antworten** (`network-only`). Sonst wird eingeloggter
  Zustand nach dem Logout ausgeliefert und veraltete CSRF-Tokens erzeugen 403er.
- **Mobile-first.** Die Handy-Ansicht ist der Hauptfall, nicht der Sonderfall.
- **Fehler nie stillschweigend verschlucken:** Schlägt ein Speichern fehl, bleibt das Häkchen
  sichtbar unbestätigt und ein Wiederholen-Button erscheint.

## Fachliche Fallstricke

Diese fünf Punkte sind die Stellen, an denen eine naive Implementierung falsch wird:

1. **Eine Einheit startet auch durch einen Tausch**, nicht nur durch das erste „Erledigt"
   (§7.6). `exercise_swaps` braucht eine `session_id`, und im Studio wird oft getauscht,
   bevor die erste Übung gemacht ist.
2. **`workout_log` ist eindeutig über `(session_id, plan_exercise_id)`**, nicht über
   `exercise_id` (§4). Nach einem Tausch steht in `exercise_id` die Ersatzübung; ohne die
   Planposition ist weder „x/n" zählbar noch der Schlüssel eindeutig.
3. **Kein Auto-Ende ohne Rückfrage** (§7.6). Sind alle Positionen abgehakt, erscheint eine
   Bestätigung — die Einheit schließt sich nie von selbst, sonst wird das Ab-wählen eines
   versehentlichen Häkchens undefiniert.
4. **Übungen werden archiviert, nicht gelöscht** (`archived = 1`, §6.3). Hartes Löschen
   verletzt die Fremdschlüssel des `workout_log` — die Historie muss vollständig bleiben.
5. **„Letztes Gewicht" überspringt leere Werte:** `WHERE weight IS NOT NULL ORDER BY
   performed_at DESC LIMIT 1` (§4). Sonst geht ein Gewicht verloren, nur weil es einmal nicht
   eingetragen wurde.

## Deployment

Docker-Container (`php:8.3-apache`) auf einem Hetzner-Rootserver, davor Host-Nginx mit
Let's-Encrypt. Details in `LASTENHEFT.md` §3 und `doku/deployment.md`.

- **Zwei Volumes zwingend:** `/var/www/html/data` und `/var/www/html/uploads`. Ohne sie sind
  nach jedem Rebuild alle Daten weg.
- **Container-Port nur an `127.0.0.1` binden**, nie `0.0.0.0` — davon hängt ab, dass dem
  `X-Forwarded-Proto`-Header vertraut werden darf.
- **Keine Secrets im Repo.** Konfiguration über `.env` (gitignored), Vorlage `.env.example`.
- **Datentransfer Test ↔ Live** ausschließlich über die Backup/Restore-Funktion in
  `maintenance.php`, nie per Filecopy der laufenden `.db` (WAL-Modus).

## Warnung: Datenbankänderungen

Test- und Live-Datenbank divergieren. Vor jeder Schema- oder Datenänderung explizit warnen
und angeben, was sie auf der Live-DB bewirkt. Ein Restore überschreibt den kompletten
Datenbestand.

## Arbeitsweise

- **Deutsch** für Oberfläche, Fehlermeldungen, Kommentare und Commit-Messages.
- Am Ende einer Session die **geänderten Dateien auflisten** (`Geänderte Dateien:` +
  Aufzählung) — das Deployment ist manuell.
- Git ist Quellcode-Sicherung; `data/`, `uploads/` und `.env` gehören nie ins Repo.
