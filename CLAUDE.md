# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

Arbeitsanweisungen für Claude Code in diesem Repo. `LASTENHEFT.md` ist maßgeblich für das
**Was** (Fachlichkeit, Abnahmekriterien); diese Datei regelt das **Wie** (Konventionen).
Bei Widerspruch gewinnt der Code als Beschreibung des Ist-Zustands, das Lastenheft als
Beschreibung des Soll-Zustands.

**Diese Datei enthält nur Dauerhaftes.** Welche Version läuft, welche Daten drin sind und
was gerade offen ist, steht in **`doku/stand.md`** — dort nachsehen und dort nachziehen.
Was hier steht, ändert sich nur mit dem Code.

**Konkrete Umgebungswerte schlagen das Lastenheft.** Die Erstfassung ging von einem
allgemeinen Setting aus; ihre IP-Adressen, Ports und Pfade sind Platzhalter. Maßgeblich sind
`LASTENHEFT.md` §3.1 und `doku/nginx-vhost.conf` (wortgetreue Kopie der aktiven
Server-Konfiguration). Widerspricht eine Stelle des Lastenhefts diesen Werten, ist sie zu
korrigieren, nicht zu befolgen. Im Zweifel nachfragen statt raten.

## Wo was steht

| Datei | Inhalt |
|---|---|
| `LASTENHEFT.md` | Fachlichkeit, Datenmodell, die 19 Abnahmekriterien |
| `doku/stand.md` | **Flüchtig:** laufende Version, Datenstand, offene Punkte |
| `doku/deployment.md` | Topologie, Portainer-Ablauf, Fehlersuche |
| `deploy/ANLEITUNG.md` | Schritt-für-Schritt-Anleitung zum Ausrollen |
| `doku/bestand_gruppen_uebungen.md` | Muskelgruppen, Übungen und Pläne im Wortlaut |
| `doku/rueckmeldungen_praxistest.md` | Rückmeldungen aus dem Studio samt Begründungen |
| `doku/nginx-vhost.conf` | Kopie der aktiven Server-Konfiguration |

## Lokale Entwicklung

```bash
php -S 127.0.0.1:8100 -t "$(pwd)"      # Dev-Server, im Hintergrund starten
```

Kein Build-Step, kein Paketmanager, kein Composer, kein npm, kein Migrationstool.
Port 8100, weil 8765 (Body-Fat-Tracker) und 8090 (Speisekarte) belegt sind.

**Ein frischer Checkout hat keine Datenbank und kommt ohne Zutun auch zu keiner, in die
man sich anmelden könnte.** `data/` enthält nur die `.htaccess`; `db()` legt beim ersten
Aufruf Schema und Muskelgruppen an, aber `bootstrap_first_admin()` steigt ohne
`ADMIN_USER`/`ADMIN_PASSWORD` still wieder aus — Ergebnis ist eine Datenbank mit **null**
Benutzern und eine Anmeldeseite, an der niemand vorbeikommt. Im Container kommen die
Werte aus dem Stack; lokal muss man sie selbst setzen:

```bash
php -r 'putenv("ADMIN_USER=tester"); putenv("ADMIN_PASSWORD=geheim12345");
        require "lib/db.php"; db();
        db()->prepare("UPDATE users SET must_change_password = 0,
                                        expert_mode = 1")->execute();'
```

Das `UPDATE` ist kein Schönheitsfehler, sondern nötig: Ohne es sperrt
`require_passwort_gesetzt_api()` **jeden** Endpunkt außer `api/auth.php` (Fallstrick 3),
und der erste `curl`-Test läuft in ein 403, das wie ein Fehler aussieht. `expert_mode = 1`
nur, wenn die Satzerfassung geprüft werden soll — im Standardmodus rendert `index.php` gar
keinen Satzblock, und man sucht den Fehler an der falschen Stelle.

Danach `data/trainingsplan.db*` wieder löschen — die Datei gehört nicht in den
Arbeitsstand, und ein liegengebliebener Testbestand verfälscht die nächste Prüfung.

**Dem PHP auf diesem Rechner fehlen `zip` und `gd`** (im Container sind beide da, siehe
`Dockerfile`). Lokal **nicht** prüfbar sind deshalb: Bild-Upload und Thumbnails (§6.3),
Sicherungen *mit Bildern* als ZIP und deren Wiederherstellung (§6.5). Die reine
`.db`-Sicherung läuft lokal durch. Wer hier „getestet" meldet, ohne das zu erwähnen, meldet
zu viel.

**Es gibt kein Test-Framework** — wie in beiden Vorlagen-Repos wird gelintet:

```bash
# alles
find . -name '*.php' -not -path './data/*' -exec php -l {} \;
find . -name '*.js'  -not -path './data/*' -exec node --check {} \;

# einzelne Datei (der übliche Fall nach einer Änderung)
php -l api/session.php
node --check assets/app.js
```

**Geprüft wird gegen den Dev-Server per `curl`**: Sitzung aufbauen, Endpunkt aufrufen,
Antwort und Datenbankzustand vergleichen. Die Abnahme läuft über die 19 manuellen Kriterien
in `LASTENHEFT.md` §11.

Der Sitzungsaufbau, weil das Token die einzige fummelige Stelle ist:

```bash
J=/tmp/kekse.txt; rm -f $J
tok() { curl -s -b $J -c $J "http://127.0.0.1:8100/$1" \
        | grep -o 'name="csrf-token" content="[^"]*"' | sed 's/.*content="//;s/"//'; }
TOK=$(tok login.php)
curl -s -b $J -c $J -X POST http://127.0.0.1:8100/api/auth.php \
     -H "Content-Type: application/json" -H "X-CSRF-Token: $TOK" \
     -d '{"action":"login","name":"tester","password":"geheim12345"}'
```

**Das Muster muss `name="csrf-token"` enthalten.** Ein bloßes `content="[^"]*"` greift den
ersten Treffer im `<head>`, und das ist der Viewport — die Anmeldung scheitert dann mit
„Nicht angemeldet", obwohl Benutzername und Passwort stimmen. Nach jedem Neuladen liefert
`tok` das aktuelle Token; `-b` **und** `-c` gehören an jeden Aufruf, sonst geht die Sitzung
verloren.

**Den Zustand liest man von der Seite, nicht aus der API.** `api/*` ist fast rein
schreibend — die einzigen lesenden Aktionen sind `plans.php → exercise_picker` und die
Tauschvorschläge (`swap.php → suggestions`, `plans.php → swap_suggestions`). Es gibt
**keinen** Endpunkt, der den Übungs-, Plan- oder Benutzerbestand ausliefert; die Daten
entstehen server-gerendert in der jeweiligen Seite. Wer per `curl` nachsehen will, was
wirklich in der Datenbank steht, holt also `admin_exercises.php?filter=alle` und liest die
**Bearbeiten-Formulare** aus — dort steht jedes Feld als `value`/`selected`/`checked`, und
zwar mit den IDs, die ein Schreibaufruf zurückgeben muss. `DOMDocument` + `DOMXPath` reichen
dafür; ein Abzug dieser Formulare ist zugleich die Sicherung, aus der man einen fehlerhaften
Schreiblauf feldgenau zurücknimmt (siehe Fallstrick 22).

Lokal geht auch der direkte Blick über `sqlite3 data/trainingsplan.db` — gegen die **Live**-
Instanz bleibt nur der Weg über die Seite.

**Was dieser Weg nicht abdeckt** — hier sind schon zwei echte Fehler durchgerutscht:

- **Service Worker und Browser-Cache.** `curl` hat keinen. Der eingefrorene Asset-Cache
  (Fallstrick 12) war über mehrere Versionen unsichtbar und fiel erst durch einen Screenshot
  des Benutzers auf.
- **Darstellung.** Ob eine Regel greift, sieht man im HTML; ob es *gut aussieht*, nicht.
- **PWA-Installation, Remember-Me über mehrere Geräte, `X-Forwarded-Proto`** hinter dem
  echten Nginx — nur über die Subdomain testbar.

Bei Änderungen an CSS oder am Frontend deshalb **den Benutzer um eine Gegenprobe bitten**,
statt „geprüft" zu melden.

Das `Secure`-Flag ist am Dev-Server **kein** Hindernis: Browser behandeln `http://127.0.0.1`
als sicheren Kontext und speichern `Secure`-Cookies dort. Das Flag bleibt bedingungslos
gesetzt.

## Architektur

**Stack:** PHP 8.3 ohne Framework, SQLite über PDO, serverseitig gerendertes HTML +
Vanilla JS. `declare(strict_types=1)` in **jeder** PHP-Datei, alle Funktionen typisiert.

**Verzeichnisse** (Einzelheiten in `LASTENHEFT.md` §2.2): Seiten im Wurzelverzeichnis,
`lib/` die Bausteine, `api/` die JSON-Endpunkte, `assets/` CSS/JS/PWA. `lib/` und `data/`
haben je eine `.htaccess` mit `Require all denied`.

| Baustein | Wofür |
|---|---|
| `lib/db.php` | PDO-Singleton, Schema, Migrationen, Erst-Admin |
| `lib/auth.php` | Sitzung, Rollen, Remember-Me, Brute-Force-Bremse |
| `lib/helpers.php` | `h()`, JSON-Envelope, Eingabenormalisierung, Auffangnetz |
| `lib/training.php` | Die Fachlichkeit aus §7: Rotation, Positionen, Tausch, Sätze, Verlauf |
| `lib/geraete.php` | Codeliste der Trainingsgeräte, `geraet_abzeichen()` |
| `lib/backup.php` | Sichern über `VACUUM INTO`, Prüfen, Wiederherstellen |
| `lib/upload.php` | Bildannahme mit MIME-Prüfung und GD-Re-Enkodierung |
| `lib/view_header.php` / `view_footer.php` | Layout als Partial |
| `lib/view_geraet_symbole.php` | SVG-Symbolvorrat + Beschriftungen, aus dem Header eingebunden |

Die Seiten erklären sich über ihren Namen, mit **einer** Ausnahme: **`devices.php` ist die
Geräteverwaltung** (§7.7) — die Oberfläche zu der in §5 zugesagten serverseitigen
Widerrufbarkeit der Remember-Me-Tokens. Sie listet `remember_tokens` des angemeldeten
Benutzers und erkennt das eigene Gerät am Selector aus dem Cookie; widerrufen wird über
`api/auth.php → revoke_device` / `revoke_all`. Nicht mit `lib/geraete.php` verwechseln —
das sind die *Trainings*geräte (Hantel, Maschine), zwei völlig verschiedene Dinge unter
ähnlichem Namen. Abnahmekriterium 16 („ein Gerät abmelden") hängt an dieser Seite.

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

**Seite = `.php` + gleichnamige `.js`.** `index.php` lädt `index.js`, `admin_plans.php` lädt
`admin_plans.js` — `lib/view_footer.php` bindet sie selbsttätig ein. Geteiltes gehört in
`assets/app.js`.

**Layout über Partials, nicht kopiert.** Das ist die bewusste Abweichung von
Body-Fat-Tracker und Speisekarte, die ihren `<head>`/`<nav>` in jede Seite duplizieren.

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
  Anders als in den Vorlagen-Repos nicht hartkodiert — der Container braucht ihn konfigurierbar.
- **Die Datenbank entsteht faul**, nicht beim Containerstart: `db()` legt Schema,
  Muskelgruppen und Erst-Admin beim ersten Aufruf an. Seiten ohne Datenbankzugriff
  (`login.php` ohne Sitzung) laden deshalb auch bei kaputtem Volume. Damit das trotzdem
  auffällt, prüft der HEALTHCHECK `health.php` — die Seite fasst die Datenbank wirklich an
  und ist nur über das Loopback erreichbar.
- **Schema in `schema.sql`**, ausschließlich `CREATE TABLE IF NOT EXISTS` /
  `CREATE INDEX IF NOT EXISTS`, läuft bei jedem Start. Neue Spalten kommen als idempotenter
  `PRAGMA table_info`-Block in `apply_migrations()` dazu — **kein** Migrationsverzeichnis.

  **Der Umweg gilt nur für Spalten, nicht für ganze Tabellen.** Eine neue Tabelle gehört nach
  `schema.sql`: `CREATE TABLE IF NOT EXISTS` läuft bei jedem Start und legt sie auch auf der
  Bestandsdatenbank an. So kam `workout_sets` in `1.1.0` dazu. Nur `ALTER TABLE ADD COLUMN`
  kennt kein `IF NOT EXISTS` und braucht deshalb den Blick in `PRAGMA table_info`.
- **`schema.sql` darf nichts voraussetzen, was erst eine Migration schafft.** Es läuft
  **vor** `apply_migrations()`. Ein Index auf einer nachgerüsteten Spalte gehört deshalb in
  die Migration, nicht ins Schema — sonst scheitert er auf jeder Bestandsdatenbank, die
  Ausnahme bricht den Start ab, und ausgerechnet die Migration, die die Spalte anlegen würde,
  wird nie erreicht. Genau das ist mit `muscle_groups.parent_id` passiert.
- **`COALESCE()` verliert die Spaltenaffinität.** PDO bindet Werte aus `execute([...])` als
  Text; SQLite vergleicht Integer nie mit Text. Bei
  `WHERE COALESCE(mg.parent_id, mg.id) = ?` ist deshalb ein `CAST(? AS INTEGER)` Pflicht.
  Der Fehler erzeugt keine Meldung — die Ergebnismenge bleibt einfach leer.
- **Ausschließlich Prepared Statements.** Kein String-Interpolieren in SQL, nirgends.
- **Zeitstempel immer in PHP erzeugen** (`date('Y-m-d H:i:s')`), nie `CURRENT_TIMESTAMP` —
  SQLite liefert dort UTC, die App läuft auf `Europe/Vienna`.
- **Die `sqlite3`-Kommandozeile erzwingt KEINE Fremdschlüssel.** Das `PRAGMA foreign_keys =
  ON` oben gilt für die PDO-Verbindung der App, nicht für ein Werkzeug daneben; im CLI ist
  die Vorgabe `OFF`. Wer beim Prüfen von Hand etwas löscht, muss es selbst voranstellen:

  ```bash
  sqlite3 data/trainingsplan.db "PRAGMA foreign_keys=ON; DELETE FROM sessions WHERE id = 3;"
  ```

  Ohne das bleiben die `workout_log`-Zeilen der Einheit als Waisen stehen, und die
  anschließende Zählung stimmt nicht mehr — was wie ein Fehler im CASCADE aussieht, aber
  keiner ist. Genau so ist beim Prüfen von `workout_sets` einmal ein falscher Befund
  entstanden.
- **Neue Tabellen brauchen an der Sicherung nichts.** `backup_erstellen()` kopiert über
  `VACUUM INTO` die **ganze** Datei — es gibt keine Tabellenliste, die man beim Erweitern
  des Schemas vergessen könnte. Nachgemessen für `workout_sets`: angelegt, gesichert,
  zerstört, eingespielt — Sätze, Leitgewicht und `done` kamen unverändert zurück.

  **Die Liste `$noetig` in `backup_pruefen()` (`lib/backup.php`) ist bewusst unvollständig**
  — `users, exercises, plans, sessions, workout_log`. Sie beantwortet die Frage „ist das
  überhaupt eine Trainingsplan-Datenbank", nicht „ist sie aktuell". **Neue Tabellen gehören
  dort NICHT hinein**: Eine ältere Sicherung kennt sie nicht und würde sonst abgelehnt —
  ausgerechnet die, die man im Ernstfall braucht. Fehlende Strukturen sind ohnehin kein
  Problem, siehe unten.

- **Ein Restore repariert das Schema selbst.** `backup_wiederherstellen()` ruft nach dem
  Einspielen `db()` auf, und damit `init_schema()`: `schema.sql` legt fehlende Tabellen an,
  `apply_migrations()` fehlende Spalten. Eine Sicherung aus der Zeit vor `1.1.0` — ohne
  `workout_sets`, ohne `expert_mode`, ohne `done` — ist deshalb **einspielbar**, und die App
  läuft unmittelbar danach weiter; die Vorgabewerte der Migrationen ordnen den Bestand
  richtig ein (`done = 1`, alte Zeilen gelten als erledigt). Durchgespielt, nicht gefolgert.

- **Sicherungen über `VACUUM INTO`, nie als Dateikopie** (`lib/backup.php`). Im WAL-Modus ist
  ein `cp` der `.db` ohne `-wal`/`-shm` im besten Fall veraltet, im schlechteren unbrauchbar.
  Gilt auch für Portainers Dateibrowser und Volume-Backup-Werkzeuge.
- **Die PDO-Verbindung nie in einer Variablen über einen `db_close()` hinweg festhalten.**
  Überall im Code steht `db()->prepare(...)` verkettet, und das ist kein Stil, sondern
  Notwendigkeit: `db_close()` setzt nur die Singleton-Referenz auf `null`. Hält daneben noch
  ein `$pdo = db()` die Verbindung, gibt PHP sie nicht frei, SQLite behält die Dateisperre —
  und das anschließende `PRAGMA journal_mode = WAL` scheitert mit `database is locked`.
  Betrifft alles, was die Datei unter der laufenden Verbindung austauscht, allen voran
  `backup_wiederherstellen()`. Das hat schon einmal einen falschen Alarm erzeugt, der Restore
  sei kaputt — er war es nicht, das Prüfskript hielt die Referenz.

## Sicherheit — nicht verhandelbar

Die Punkte aus `LASTENHEFT.md` §5 sind harte Anforderungen. Was am ehesten übersehen wird:

- **`X-Forwarded-Proto`, nicht `$_SERVER['HTTPS']`.** Intern läuft die Verbindung
  unverschlüsselt. Wer auf `HTTPS` prüft, baut eine Redirect-Schleife und kaputte
  `Secure`-Cookies.
- **Cookies immer mit `Secure`**, auch wenn die Verbindung intern HTTP ist.
- **Kein Passwort-Pepper.** `APP_SECRET` dient ausschließlich dem
  Remember-Me-Validator-Hash. Ein Pepper würde bei Verlust des Secrets alle Passwörter
  unbrauchbar machen.
- **Remember-Me:** Selector/Validator, nur der **gehashte** Validator in der DB,
  `hash_equals()` für den Vergleich, **Rotation bei jeder Nutzung**.
- **IDOR:** Jede ID-basierte Anfrage prüft `user_id`-Eigentümerschaft serverseitig — und zwar
  **in der WHERE-Klausel**, nicht in einer Prüfung davor. Ein Plan, eine Einheit oder ein Log
  gehören immer genau einem Benutzer.
- **CSRF auf jedem Nicht-GET-Request**, auch in `api/*`.
- **Uploads:** MIME aus dem **Inhalt** per `finfo`, nie aus der Endung; Abmessungen **vor**
  dem Dekodieren prüfen (GD arbeitet unkomprimiert, ein 25-MP-Bild belegt ~100 MB);
  Re-Enkodierung via GD; Zufallsdateiname; Auslieferung über `image.php` mit
  `realpath`-Path-Jail.
- **Selbstsperren sind ausgeschlossen** (`api/users.php`): Ein Admin kann weder sein eigenes
  Konto löschen noch sich selbst das Adminrecht entziehen — beides führte in einen Zustand,
  den er selbst nicht mehr rückgängig machen könnte. Das geht über §6.1 hinaus, das nur den
  *letzten* Admin schützt.

  **Umbenennen fällt ausdrücklich nicht darunter** (§6.1, §7.7): Wer umbenennt, kennt den
  neuen Namen, und an den Rechten ändert sich nichts. Deshalb keine Ausnahme fürs eigene
  Konto und keine für den letzten Admin — die Sperren gehören dorthin, wo sie eine echte
  Sackgasse verhindern, sonst gewöhnt man sich an, sie zu umgehen.
- **Benutzernamen prüft `benutzername_pruefen()` in `lib/auth.php`**, nicht der jeweilige
  Endpunkt. Es gibt drei Aufrufstellen — anlegen, vom Admin umbenennen, selbst umbenennen —
  und ein Name, den der eine Weg zulässt und der andere ablehnt, ist nicht erklärbar.
  Geschrieben wird über `benutzer_umbenennen()`, das die Kollision am `UNIQUE`-Index abfängt
  statt mit einem `SELECT` davor: dazwischen läge sonst ein Zeitfenster.
- **Namen sind unabhängig von der Schreibweise eindeutig** — und das sind **zwei** Stellen,
  die zusammengehören: der Index `idx_users_name_nocase` (`users(name COLLATE NOCASE)`, in
  `apply_migrations()`) **und** `WHERE name = ? COLLATE NOCASE` in `attempt_login()`. Nur
  eine von beiden reicht nie: ohne den Index könnte die Anmeldeabfrage mehrere Zeilen
  treffen, ohne das `COLLATE` käme `Oliver` mit der Eingabe `oliver` nicht herein. Das
  Standard-`UNIQUE` aus `schema.sql` vergleicht binär — genau deshalb ließ sich am
  2026-08-07 auf dem Live-System ein zweites Konto `oliver` neben `Oliver` anlegen.

  `index_name_nocase()` prüft **vorher** auf bestehende Dubletten und wirft mit deren Namen,
  statt SQLite eine nackte `UNIQUE constraint failed`-Meldung werfen zu lassen. Das bricht
  den Start ab — bewusst: Der übrige Code verlässt sich darauf, dass der Index die Kollision
  abfängt. Denkbar ist der Fall nur beim Zurückspielen einer alten Sicherung.

  **Grenze:** SQLites `NOCASE` faltet ausschließlich ASCII `A`–`Z`. `Müller` und `müller`
  bleiben zwei verschiedene Namen.
- **Der Benutzername ist die Anmeldekennung.** Wer ihn ändern lässt, muss das **aktuelle
  Passwort verlangen** (`api/auth.php → change_name`), sonst sperrt ein fremder Griff zum
  entsperrten Handy den Besitzer aus seinem eigenen Konto aus.

## Frontend

- **Ein `fetch`-Wrapper:** `apiFetch(url, options)` in `assets/app.js`. Setzt `X-CSRF-Token`,
  JSON-encodet Objekt-Bodies, reicht `FormData` durch, entpackt `{ok, data}`, wirft mit
  `err.fields`, leitet bei 401 auf `login.php` (außer man ist dort bereits — sonst
  verschluckt der Reload die Meldung „Passwort falsch"). **Kein direkter `fetch()`-Aufruf in
  Seiten-JS.**

  Dazu die Vorkehrungen gegen schwaches Netz (§7.4): **Zeitlimit** von 12 s, bei
  `FormData`-Bodies 120 s — das hängt am Body und nicht an der Aufrufstelle, damit man es
  beim nächsten Upload nicht vergessen kann. Netzfehler tragen `err.offline`, ein
  abgelaufenes Limit zusätzlich `err.timeout`.

  **`wiederholen: true` ist ausdrücklich anzufordern und nie der Standard.** Erlaubt nur,
  wo der Endpunkt einen zweiten Aufruf verträgt: `api/log.php` (Upsert), `api/session.php →
  start` (liefert die laufende Einheit zurück), lesende Aktionen. **`api/session.php → end`
  verträgt es nicht** — der zweite Aufruf findet keine offene Einheit und antwortet 409.
  Deshalb behandelt `index.js` genau diesen 409 als Erfolg und lädt neu, statt einen Fehler
  zu zeigen.
- **JSON-Envelope serverseitig:** `json_ok(array $data = [], int $status = 200)` und
  `json_err(string $error, int $status = 400, array $fields = [])`.
- **Escaping:** serverseitig `h()` aus `lib/helpers.php`, clientseitig `escapeHtml()` aus
  `assets/app.js` vor jedem `innerHTML`.
- **Zahleneingaben:** `type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"` —
  nicht `type="number"`, sonst bricht das Dezimalkomma am Handy.
- **Service Worker:** cacht **nur** `assets/*.css`, `assets/*.js`, `manifest.json`, Icons —
  mit **`stale-while-revalidate`**. **Niemals HTML oder API-Antworten** (`network-only`),
  sonst wird eingeloggter Zustand nach dem Logout ausgeliefert und veraltete CSRF-Tokens
  erzeugen 403er. Zur Cache-Falle siehe Fallstrick 12.
- **Asset-Adressen tragen die Version** (`style.css?v=1.1.8`), gesetzt in
  `lib/view_header.php` und `lib/view_footer.php` aus `app_version()`. Wer eine neue CSS-
  oder JS-Datei einbindet, hängt sie dort mit an — ohne den Parameter friert die Datei im
  Browser-Cache ein, und zwar unbemerkt. Eine Nummer in `sw.js` gibt es dafür **nicht mehr**;
  Fallstrick 12 sagt, warum sie nicht genügt hat.
- **`[hidden]` braucht eine eigene Regel** ganz oben im Stylesheet:
  `[hidden] { display: none !important; }`. Browser blenden `[hidden]` nur über ihr
  *Standard*-Stylesheet aus — jede Autorenregel schlägt das, unabhängig von der Spezifität.
  Wegen `button { display: inline-flex }` stand der Wiederholen-Knopf sonst dauerhaft
  sichtbar in jeder Übungszeile.

  **Dasselbe gilt für `<dialog>`:** Ein geschlossener Dialog ist ebenfalls nur über das
  Standard-Stylesheet unsichtbar. Wer ihm ein Layout gibt, muss auf `[open]` einschränken —
  `#waehlen-dialog[open] { display: flex }` —, sonst steht der Dialog dauerhaft offen in
  der Seite.
- **Mobile-first.** Die Handy-Ansicht ist der Hauptfall, nicht der Sonderfall.
- **Fehler nie stillschweigend verschlucken:** Schlägt ein Speichern fehl, bleibt das Häkchen
  sichtbar unbestätigt und ein Wiederholen-Knopf erscheint.

## Fachliche Fallstricke

Die Stellen, an denen eine naive Umsetzung falsch wird. Fast jede steht hier, weil sie schon
einmal zugeschlagen hat — die Ausnahme ist **22**, der beim Lesen des Endpunkts auffiel,
bevor er es konnte. Er steht trotzdem hier, weil sein Schaden lautlos gewesen wäre: Die
Antwort lautet auch dann `ok:true`, wenn die halbe Zeile verlorengeht.

1. **Eine Einheit entsteht AUSSCHLIESSLICH über „Training starten"** (§7.6, seit `1.1.6`).
   `einheit_sicherstellen()` hat genau **einen** Aufrufer: `api/session.php → start`. Wer
   einen zweiten ergänzt, hebt die Zusicherung auf.

   Bis `1.1.5` war es umgekehrt: Auch das erste „Erledigt" (`api/log.php`) und ein Tausch
   „nur diese Einheit" (`api/swap.php`) legten stillschweigend eine Einheit an. Die
   Begründung war der reale Ablauf im Studio — Plan öffnen, Gerät besetzt vorfinden,
   tauschen, *dann* trainieren. In der Praxis überwog der andere Fall: Ein Fehlgriff beim
   bloßen Durchsehen begann ein Training, das niemand wollte, und `started_at` hielt dann
   den Fehlgriff fest statt des Trainingsbeginns. Beide Endpunkte antworten deshalb mit
   **409**, statt anzulegen.

   Daraus folgt die Sperre in der Oberfläche: **Vor dem Start sind „Erledigt", „+ Satz"
   und das Gewichtsfeld deaktiviert** (`index.php` über `$laeuft`, `index.js` über
   `sessionId > 0`). Das ist nur die Bequemlichkeit davor — verboten wird es serverseitig.

   **Der dauerhafte Tausch bleibt vor dem Start möglich**, und das ist kein Versehen: Er
   schreibt in `plan_exercises` und braucht überhaupt keine `session_id`. Nur „Nur diese
   Einheit" braucht eine und wird deshalb vorher gar nicht erst angeboten (`TAUSCH_KNOEPFE`
   in `index.js`) — mit einem Hinweissatz, sonst sieht der fehlende zweite Knopf wie ein
   Fehler aus.

2. **Eine Einheit gehört zu genau einem Plan.** Jeder Endpunkt, der eine
   `plan_exercise_id` entgegennimmt, prüft neben der Eigentümerschaft auch, dass die Position
   zum Plan der laufenden Einheit gehört (`position_passt_zur_einheit()`). Ohne diese Prüfung
   ließe sich eine Position aus einem anderen Plan in die offene Einheit protokollieren:
   `workout_log.plan_id` widerspräche dann `sessions.plan_id`, und weil „x" über die Einheit,
   „n" aber über den Plan gezählt wird, stünde in der Anzeige so etwas wie „2/1". Über die
   Oberfläche nicht erreichbar — über einen zweiten Tab mit veraltetem Plan schon.

3. **Die API kennt dieselben Sperren wie die Seiten.** `require_login_api()` allein genügt
   nicht: `require_passwort_gesetzt_api()` gehört in **jeden** Endpunkt außer `api/auth.php`.
   Sonst kann jemand mit dem vom Admin vergebenen Startpasswort die ganze API benutzen, ohne
   je ein eigenes Passwort zu setzen — und `must_change_password` wäre wirkungslos.

4. **`workout_log` ist eindeutig über `(session_id, plan_exercise_id)`**, nicht über
   `exercise_id` (§4). Nach einem Tausch steht in `exercise_id` die Ersatzübung; ohne die
   Planposition ist weder „x/n" zählbar noch der Schlüssel eindeutig.

5. **Kein Auto-Ende ohne Rückfrage** (§7.6). Sind alle Positionen abgehakt, erscheint eine
   Bestätigung — die Einheit schließt sich nie von selbst, sonst wird das Ab-wählen eines
   versehentlichen Häkchens undefiniert.

6. **Eine abgehakte Position lässt sich nicht tauschen** (§7.5) — weder für die Einheit noch
   dauerhaft. Der Weg ist: Häkchen entfernen, tauschen, neu abhaken. Grund: Ein
   Protokolleintrag dokumentiert eine *tatsächlich ausgeführte* Übung. Ihn beim Tausch auf
   die Ersatzübung umzuschreiben würde das erreichte Gewicht einer Übung zuschlagen, die gar
   nicht gemacht wurde; ihn stehen zu lassen, während die Ansicht etwas anderes zeigt, wäre
   ebenso falsch. Gesperrt wird **serverseitig** (`api/swap.php`); der deaktivierte Knopf ist
   nur die Bequemlichkeit davor.

   **Dasselbe gilt für Werte:** Geändert wird, indem man das Häkchen entfernt — nicht durch
   direktes Bearbeiten (§7.4). Das Gewichtsfeld ist nach dem Abhaken `readonly`, und
   `api/log.php` hat bewusst **keine** `update`-Aktion. Ein Mechanismus statt zweier.

7. **Übungen werden archiviert, nicht gelöscht** (`archived = 1`, §6.3). Hartes Löschen
   verletzt die Fremdschlüssel des `workout_log` — die Historie muss vollständig bleiben.
   Archiviert heißt aber **versteckt, nicht verschwunden**: Die Admin-Übungsliste hat einen
   Filter *Aktiv / Archiviert / Alle* mit sichtbaren Anzahlen, und jede archivierte Zeile
   nennt Archivierungsdatum, betroffene Pläne und die Anzahl ihrer Log-Einträge. Endgültig
   löschbar ist nur, was weder in einem Plan steht noch Log-Einträge hat.

8. **„Letztes Gewicht" überspringt leere Werte:** `WHERE weight IS NOT NULL ORDER BY
   performed_at DESC, id DESC LIMIT 1` (§4). Sonst geht ein Gewicht verloren, nur weil es
   einmal nicht eingetragen wurde. Das `id DESC` ist Pflicht und keine Kosmetik: Zeitstempel
   haben Sekundenauflösung, und zwei Einträge derselben Sekunde hätten ohne zweites
   Sortierkriterium keine definierte Reihenfolge — die Vorbelegung wäre dann zufällig.

   **Wiederholungen gibt es nicht** (§4): Ein Feld je Einheit kann 12/10/9 über drei Sätze
   nicht abbilden. Nicht wieder einführen, ohne satzgenau zu protokollieren.

9. **Muskelgruppen sind zweistufig, und der Tausch vergleicht die HAUPTGRUPPE** (§4, §7.5).
   Hauptgruppen haben `parent_id IS NULL`, Untergruppen zeigen darauf; mehr als zwei Ebenen
   gibt es nicht. Dadurch darf die Unterteilung beliebig fein werden, ohne die Vorschläge
   auszudünnen — eine Übung an `Brust (oben)` bekommt alles unter `Brust` als Ersatz.

   In der Übungsmaske sind Hauptgruppen **mit** Untergruppen deshalb nicht wählbar, sondern
   Gliederungsüberschrift; sonst gäbe es zwei Wege für dieselbe Aussage. Ein Hinweistext an
   der Maske erklärt die Tauschregel — ohne ihn überrascht es, dass man `Trizeps` anhakt und
   Bizeps-Übungen vorgeschlagen bekommt.

10. **Genau eine Primärgruppe je Übung** (`exercise_muscle_groups`, §4), abgesichert durch
    den partiellen Unique-Index `idx_emg_one_primary`. Primär = die Gruppe, **wegen der** man
    die Übung macht; sekundär = wird mittrainiert. Beim Umsetzen erst alle auf 0, dann die
    neue auf 1 — **in einer Transaktion**, sonst schlägt der Index zwischendurch zu.

11. **Eine Einheit wird ausdrücklich gestartet** (§7.6, `api/session.php` → `start`) — seit
    `1.1.6` ist das nicht mehr der Regelfall, sondern der **einzige** Weg; siehe
    Fallstrick 1. Ohne den Knopf hielt `started_at` das *Ende* der ersten Übung fest — für
    jede Auswertung der Trainingsdauer systematisch zu kurz.

12. **Der Service-Worker-Cache friert Assets ein, wenn `sw.js` unverändert bleibt.** Ein
    Service Worker wird **nur neu installiert, wenn sich seine eigene Datei ändert**. Bleibt
    sie gleich, läuft `install()` nie wieder, `cache.addAll()` ebenso wenig — und
    `caches.match()` liefert bis in alle Ewigkeit die Fassung vom ersten Besuch. `style.css`
    und `app.js` waren dadurch über mehrere Versionen in jedem Browser eingefroren; keine
    einzige Stiländerung kam an, obwohl Server und HTML korrekt waren.

    **Das Hochzählen von `CACHE` war die Antwort darauf — und sie hat nicht gereicht.** In
    `1.1.7` ist derselbe Fehler ein zweites Mal aufgetreten, diesmal **nur am Smartphone**,
    während der PC korrekt aussah. Die Nummer war ordentlich hochgezählt. Der Grund liegt
    eine Ebene tiefer:

    **Es gibt ZWEI Caches hintereinander, und `CACHE` erreicht nur den ersten.** Hinter dem
    Service-Worker-Cache sitzt der gewöhnliche HTTP-Cache des Browsers. Apache sendete für
    Assets **kein `Cache-Control`**, nur `ETag` und `Last-Modified` — und ohne Angabe zur
    Haltbarkeit darf ein Browser *heuristisch* cachen, üblicherweise 10 % der Zeit seit
    `Last-Modified`. Der Ablauf, der daraus folgt:

    1. Neues Image ausgerollt, `sw.js` mit neuem Cache-Namen → `install()` läuft.
    2. `cache.addAll()` holt die Dateien mit **normalem** `fetch` — also **durch den
       HTTP-Cache**. Der liefert die *alte* `style.css`.
    3. `activate()` löscht den vorherigen Cache.
    4. Der frische Cache enthält jetzt den alten Stand. Dauerhaft, und ohne jede Meldung.

    Am PC fiel es nicht auf, weil dort hart neu geladen worden war. Am Handy blieb neues
    HTML auf altem Stylesheet stehen — sichtbar daran, dass ein Element in einer *dritten*
    Rasterspalte landete, die es weder im alten noch im neuen Entwurf gibt.

    **Und `stale-while-revalidate` hat den Zustand nicht geheilt, sondern zementiert.** Das
    ist der Teil, den man beim Entwurf übersieht: Die Revalidierung ist ein gewöhnlicher
    `fetch` und läuft damit durch **denselben** HTTP-Cache, der die veraltete Datei für
    gültig hält. Sie schrieb den alten Stand bei jedem Aufruf zurück in den
    Service-Worker-Cache. Vier bis fünf Neuladungen brachten deshalb keine Besserung — das
    ist zugleich das Merkmal, an dem man diesen Fehler von einem bloßen Übergangszustand
    nach einem Rollout unterscheidet.

    Die Lehre über den Einzelfall hinaus: **Ein Reparaturweg, der durch die kaputte Ebene
    läuft, ist keiner.** Wer einen Cache mit einem Selbstheilungsmechanismus absichert, muss
    sicherstellen, dass dieser Mechanismus an der Fehlerquelle vorbeigeht.

    **Seit `1.1.8` hängt die Version an der Adresse: `assets/style.css?v=1.1.8`**
    (`lib/view_header.php`, `lib/view_footer.php`, aus `app_version()`). Das ist die einzige
    Lösung, die nicht davon abhängt, dass sich jemand richtig verhält — eine geänderte
    Adresse kann in **keinem** der beiden Caches einen alten Eintrag treffen. Dazu kommen
    drei Dinge, die zusammengehören:

    - **`CACHE` wird nicht mehr von Hand gepflegt.** `sw.js` liest die Version aus seiner
      eigenen Adresse (`self.location`), registriert wird als `assets/sw.js?v=…`. Damit gilt
      der Worker dem Browser zugleich als neuer und wird sofort installiert, statt bis zu
      24 Stunden auf die nächste Prüfung zu warten. **Es gibt keine Nummer mehr, die man
      vergessen kann.**
    - **`cache: 'reload'`** in `install()` **und** bei der Revalidierung im `fetch`-Handler.
      Ohne das läuft auch die Revalidierung durch denselben HTTP-Cache und bestätigt nur den
      alten Stand — deshalb heilte der zweite Seitenaufruf früher nichts.
    - **`Cache-Control: no-cache`** für `assets/` und alle `*.js` (`apache-app.conf`). Das
      heißt nicht „nicht speichern", sondern „vor jeder Benutzung rückfragen"; mit `ETag`
      kostet es ein 304 ohne Rumpf.

    **Präcache-Adressen müssen exakt die sein, die der Header anfragt.** `ASSETS` in `sw.js`
    führt `style.css` und `app.js` **mit** `?v=`, Manifest und Icons **ohne** — genau so
    stehen sie im `<head>`. Ein Precache unter einer Adresse, die nie jemand anfragt, ist
    toter Ballast, und der erste Aufruf ginge trotzdem ans Netz.

13. **Die Warteschlange fürs Abhaken hängt an `user_id` UND `session_id`** (§7.4). Beide
    Schlüssel sind Pflicht, und jeder aus einem eigenen Grund:

    - `localStorage` gehört der **Herkunft**, nicht der Sitzung. Auf einem geteilten Gerät
      holte der nächste Angemeldete sonst die Häkchen seines Vorgängers nach.
    - Ein wartendes Häkchen gehört zu **genau einer Einheit**. Läuft es in eine andere,
      eröffnet `einheit_sicherstellen()` in `api/log.php` stillschweigend eine **neue**
      Einheit — das ist verifiziert: Ein `check` nach dem Beenden liefert eine frische
      `session_id` zurück, ohne Fehler. Passt der Schlüssel nicht, wird die Ablage deshalb
      **verworfen**, nicht geraten.

    Daraus folgt der Rest: **Beenden ist gesperrt, solange etwas aussteht** (die geschlossene
    Einheit nähme nichts mehr an), **Tauschen einer wartenden Zeile ebenso** (der Server
    kennt ihren Zustand noch nicht), und die Schlange läuft **nur innerhalb einer bereits
    laufenden Einheit** — ohne offene Einheit gibt es keine `session_id`, an der sie hinge.

    **Eine 4xx-Antwort muss den Eintrag aus der Schlange entfernen.** Sie fällt bei jedem
    weiteren Versuch gleich aus; bliebe der Eintrag liegen, blockierte er alle folgenden
    dauerhaft. Nur `err.offline` darf ihn liegen lassen.

    **`navigator.onLine` ist als Verbindungsanzeige unbrauchbar** — es meldet nur, ob eine
    Netzwerkschnittstelle da ist. Im Studio-WLAN ohne Internet und bei einem Balken Mobilfunk
    steht es auf `true`, während jeder Aufruf ins Leere läuft. Deshalb speist `apiFetch` die
    Leiste aus dem, was **tatsächlich gescheitert** ist, und deshalb braucht das Nachholen
    einen **eigenen Zeitgeber**: Das `online`-Ereignis feuert in genau diesem Fall nie.

14. **Ein Restore ersetzt die Datenbankdatei, er mischt nicht** (`lib/backup.php`, §6.5) —
    und daraus folgt mehr, als man zunächst sieht:

    - Der Erst-Admin des leeren Systems ist danach **weg**, mitsamt den Muskelgruppen aus
      dem Seed. Das ist richtig so: `seed_muscle_groups()` und `bootstrap_first_admin()`
      steigen bei nicht leerer Tabelle aus, es entstehen also keine Dubletten.
    - **`ADMIN_USER`/`ADMIN_PASSWORD` sind keine Schlüssel zu den Daten**, sondern eine
      Starthilfe für die leere Benutzertabelle. Beim Wiederaufbau darf man sie frei neu
      wählen; nach dem Einspielen gelten wieder die Passwörter aus der Sicherung.
      Ebenso ist ein verlorenes `APP_SECRET` harmlos — es hängt allein am
      Remember-Me-HMAC (`remember_validator_hash()`), Passwörter werden ohne Pepper
      gehasht.
    - **Die offene Sitzung überlebt den Restore und zeigt danach die falsche Person.** In
      `$_SESSION` steht nur die `user_id`, und die gehört in der eingespielten Datenbank
      jemand anderem. Kein Rechteproblem — einen Restore kann ohnehin nur ein Admin
      auslösen —, aber verwirrend. Nach dem Einspielen ab- und neu anmelden.

    Der ganze Weg ist durchgespielt: Klon → leeres System → Sicherung einspielen →
    Zustand identisch, Anmeldung mit den alten Passwörtern.

15. **`history.php` zeigt ausschließlich eigene Daten** (§7.8) — auch Admins sehen nichts
    Fremdes. Die `user_id` kommt in jeder Abfrage aus der Sitzung, nie aus einem Parameter;
    es gibt bewusst keine Benutzerauswahl. Wer das ändert, öffnet fremde Trainingsdaten.

16. **Das Trainingsgerät ist Anzeige und Filter — nicht Teil der Tauschlogik** (§6.3, §7.5).
    `exercises.equipment` trägt einen Schlüssel aus der Codeliste `GERAETE` in
    `lib/geraete.php`. Naheliegend und falsch wäre, den Tausch darauf einzuschränken: Der
    häufigste Grund zu tauschen ist eine *besetzte Maschine*, und ein Filter auf dasselbe
    Gerät verböte genau den Ausweg, den man in dem Moment sucht. `tausch_vorschlaege()`
    liefert das Feld deshalb nur mit.

    Der Gerätefilter **im** Tauschdialog ist davon unberührt: Er arbeitet auf der bereits
    abgerufenen Antwort, rein im Browser, und bietet nur an, was darin vorkommt. Kein
    zweiter Serverabruf — man steht damit im Studio, wo das Netz schwach ist, und die Liste
    liegt vollständig vor. Beide Dialoge — Training und Planverwaltung — teilen sich dafür
    `geraetFilterFuellen()` und `geraetGefiltert()` aus `assets/app.js`, aus demselben
    Grund wie bei `vorschlagMarkup()`: zweimal gepflegt wären sie irgendwann verschieden.

    **Die zwei Filter der Übungsauswahl (§6.4) schränken sich gegenseitig ein, und jede
    Facette wird ohne ihren EIGENEN Filter gerechnet** (`auswahl_facetten()` in
    `api/plans.php`). Beide Filter auf beide Listen anzuwenden ist der naheliegende Fehler:
    Dann bliebe nach der Wahl von „Kurzhantel" nur noch „Kurzhantel" im Gerätefeld stehen,
    und die Einschränkung wäre eine Sackgasse. Zur Gruppen-Facette gehören außerdem die
    **Elterngruppen** der Treffer — eine Hauptgruppe schließt ihre Untergruppen ein und
    hätte also Treffer, fiele aber sonst aus der Liste.

    **Daneben liegt in derselben Datei die zweite Codeliste `ZUSCHNITT`** (`links` /
    `mitte` / `rechts`, seit `1.1.7`): welche Seite eines breiten Bildes im quadratischen
    Rahmen stehen bleibt. Sie folgt demselben Muster — Codeliste statt Tabelle, geprüft in
    `api/exercises.php`, Vorgabe `mitte` — und hat **eine Voraussetzung, die man leicht
    kaputtmacht**: Der Wert wirkt allein über `object-position` im Stylesheet, weil
    `write_resized()` in `lib/upload.php` ausschließlich **skaliert und nicht beschneidet**.
    Das Vorschaubild trägt deshalb noch das volle Seitenverhältnis, und die Einstellung
    lässt sich beliebig oft ändern, ohne eine Datei anzufassen. Wer dort je einen Zuschnitt
    einbaut, nimmt ihr die Grundlage: Ein bereits quadratisch beschnittenes Thumbnail ist
    nachträglich nicht mehr anders auszurichten.

    Auch hier stehen die Werte **zweimal**: `bild_zuschnitt_klasse()` in PHP für die
    server-gerenderten Listen, die gleiche Zuordnung in `vorschlagMarkup()`
    (`assets/app.js`) für den Tauschdialog — dieselbe Konstellation wie bei
    `geraet_abzeichen()`. `mitte` liefert bewusst **keine** Klasse: Das ist der Vorgabewert
    von `object-position`, und eine Klasse dafür wäre Rauschen im Markup.

    **Der Schlüssel steht in der Datenbank, die Beschriftung nur in `GERAETE`.** Eine
    Umbenennung ist deshalb eine Textänderung ohne Migration — so wurde `kabel` in `1.0.16`
    von „Kabel" zu „Kabelzug". Wer den *Schlüssel* ändert, braucht dagegen ein `UPDATE`.

    **Eine Codeliste, keine Tabelle, und kein `CHECK`.** Die Menge ist klein, geschlossen
    und hängt nicht am Datenbestand — eine Tabelle bräuchte Verwaltungsseite, API und
    Löschschutz. Ein `CHECK`-Constraint wiederum ließe sich in SQLite nur über einen
    Tabellen-Neuaufbau ändern; ein achter Gerätetyp soll eine Zeile PHP kosten. Geprüft
    wird in `api/exercises.php` gegen `GERAETE`.

    **Das Feld ist auch beim Bearbeiten Pflicht.** Das ist kein Versehen, sondern der
    Mechanismus, über den die Übungen aus der Zeit vor `1.0.15` ihren Wert bekommen: Die
    Migration setzt nichts, die Liste mahnt „Gerät fehlt", der Filter `GERAET_LEER` findet
    genau diese Zeilen. Ein Vorgabewert wäre bequemer und für die meisten falsch gewesen.

    **Symbol und Beschriftung haben genau eine Quelle:** `lib/view_geraet_symbole.php`,
    eingebunden aus `lib/view_header.php`. Es legt die `<symbol>`-Definitionen und die
    Beschriftungen als JSON ins Dokument; `geraet_abzeichen()` (PHP) und `geraetAbzeichen()`
    (`assets/app.js`) referenzieren beides nur. Das ist nötig, weil die Abzeichen an zwei
    Stellen entstehen — server-gerendert in den Listen und clientseitig in
    `vorschlagMarkup()`. **Wer einen Wert in `GERAETE` ergänzt, ergänzt dort das passende
    `<symbol>`** — sonst bleibt das Abzeichen leer, und zwar ohne Fehlermeldung.

17. **Im Expertenmodus ist die ganze SATZLISTE die Nutzlast, nicht der einzelne Satz** (§7.4,
    `api/log.php`). `check` nimmt sie als Feld `sets` entgegen und ersetzt die Sätze der
    Position vollständig — erst `DELETE`, dann `INSERT` von 1 an, alles in **einer**
    Transaktion. Damit ist der Aufruf idempotent, und genau darauf verlässt sich die
    Warteschlange: Sie hält einen Eintrag je Planposition, überschreibt ihn bei jeder
    Änderung und schickt ihn nach einem Funkloch erneut. Ein „Satz anlegen"-Endpunkt hätte
    bei jedem Wiederversuch einen weiteren Satz erzeugt.

    Daraus folgen drei Dinge, die zusammengehören:

    - **Ein `check` OHNE `sets` löscht vorhandene Sätze.** Die Nutzlast beschreibt die Zeile
      vollständig; ließe man die alten stehen, zeigte die Position ein Leitgewicht aus einer
      Satzfolge, die niemand mehr sieht.
    - **Deshalb ist der Moduswechsel bei laufender Einheit gesperrt** (`api/auth.php →
      set_expert_mode`, 409). Die Ablage im `localStorage` überlebt einen Wechsel, und ein
      wartender Eintrag aus dem einfachen Modus trägt keine Satzliste — das wäre stiller
      Datenverlust mitten im Training.
    - **Deshalb heißt der `localStorage`-Schlüssel `…-warteschlange-v2`.** Ein Eintrag aus
      `1.0.x` liefe sonst als `check` ohne Satzliste durch.

    `workout_log.weight` bleibt gefüllt und trägt den **schwersten** Satz. Das ist keine
    Redundanz: `letztes_gewicht()`, `gewichts_verlauf()` und `uebungen_mit_verlauf()` lesen
    ausschließlich diese Spalte und funktionieren dadurch über beide Modi hinweg unverändert.
    `workout_sets` hängt an `workout_log.id` mit `ON DELETE CASCADE` — Ab-wählen und
    Einheit-Löschen räumen die Sätze von selbst mit weg, es gibt **keinen** eigenen
    Löschpfad.

    **Eine leere Satzzeile darf nicht mitgeschickt werden.** `+ Satz` legt bei einer Übung
    ohne Vorlage eine leere Zeile an; `saetze_pruefen()` weist einen Satz ohne Wiederholungen
    *und* ohne Gewicht zu Recht mit 422 ab. Das Filtern erledigt `saetzeFuerServer()` in
    `index.js` — `saetzeLesen()` liefert weiterhin **alle** Zeilen, weil die leere im DOM
    stehen bleiben muss, sie wird ja gerade ausgefüllt. Wer die beiden verwechselt, erzeugt
    entweder einen roten Fehlerrand ohne Anlass oder eine Zeile, die beim Tippen verschwindet.

18. **„Protokolliert" und „erledigt" sind zwei Zustände** (`workout_log.done`, §7.4). Im
    einfachen Modus fallen sie zusammen; im Expertenmodus entsteht die Zeile mit dem **ersten
    Satz**, und da ist man mitten in der Übung. Die erste Fassung von `1.1.0` hatte
    „Erledigt" an die Existenz der Zeile gekoppelt — ab dem zweiten Satz stand die Übung als
    fertig da, während der Benutzer noch am Gerät stand.

    Die Trennung zieht sich durch und muss zusammen gedacht werden:

    - **`done = 1` zählt „x/n"** — in `fortschritt()` (`api/log.php`) *und* in
      `einheiten_verlauf()`. Beide, sonst heißt „erledigt" im Verlauf etwas anderes als im
      Training.
    - **Die Tauschsperre hängt an der EXISTENZ der Zeile**, nicht an `done`
      (`position_abgehakt()` in `api/swap.php`). Wer zwei Sätze Bankdrücken gemacht hat, kann
      die Position nicht mehr tauschen — die zwei Sätze waren Bankdrücken. `plan_positionen()`
      liefert dafür `hat_eintrag` neben `erledigt`.
    - **`done` fehlt in der Nutzlast ⇒ erledigt.** Das ist die Vorgabe für den einfachen
      Modus und der Rückfall für eine ältere Nutzlast — und der Grund, warum der
      Warteschlangen-Schlüssel auf `-v3` ging: Ein Eintrag aus `1.1.0` ohne `done` hakte die
      Übung sonst beim Nachholen ab.
    - **Ab-wählen löscht im Expertenmodus keine Sätze.** Es setzt nur `done = 0`; gelöscht
      wird eine Zeile erst, wenn kein Satz mehr übrig und kein Häkchen gesetzt ist.
    - **`done = 1` schreibt die Position fest** (`abgeschlossene_position_schuetzen()`).
      Wiederholungen, Gewicht, Sätze hinzufügen oder löschen — alles abgelehnt, bis das
      Häkchen weg ist. Das gilt seit `1.1.4` auch für das Gewichtsfeld im einfachen Modus,
      wo es bis dahin nur eine Regel der Oberfläche war.

      **Die eine Ausnahme, die man nicht vergessen darf: Eine unveränderte Nutzlast muss
      durchgehen.** Die Warteschlange schickt einen Eintrag nach einem Funkloch erneut, und
      der zweite Aufruf trifft dann auf die bereits abgehakte Position. Ohne diese Ausnahme
      schlüge er mit 409 fehl — für den Benutzer ein Fehler, obwohl längst alles gespeichert
      ist. Verglichen wird deshalb inhaltlich (`saetze_gleich()`), und Gewichte nie mit
      `===`: 40.0 aus der Datenbank und 40.0 aus der Eingabe sind dasselbe Gewicht, aber
      nicht zwingend dasselbe Bitmuster.

19. **Alle `:hover`-Regeln stehen hinter `@media (hover: hover)`.** Auf einem Touchscreen gibt
    es kein Verlassen mit dem Zeiger, deshalb bleibt `:hover` am zuletzt angetippten Element
    kleben. Im Studio sah das so aus, dass Satzkopf und „+ Satz" beim Tippen ihre Blautöne
    tauschten (`--akzent` gegen `--akzent-tief`) — als reagierte die Oberfläche auf etwas
    anderes als den Tipp.

    **Dieselbe Klasse von Fehler: Anzeigen, die die Kartenhöhe ändern.** Der wartende
    Zustand trug bis `1.1.1` einen Hinweissatz in der Karte — sie wurde für die Dauer des
    Speicherns höher und danach wieder niedriger, und bei jedem Satz sprang die ganze Liste
    darunter. Was sich im Sekundentakt ändert, darf **nichts verschieben**: `.zeile-wartet`
    ändert deshalb nur noch `border-left-style` und nicht einmal die Farbe. Wie viele
    Eingaben ausstehen, sagt die `sticky` Leiste am oberen Rand — eine Anzeige genügt.

    **Und die Farbe des Balkens ist ein Leitsystem, keine Dekoration:** grün = hier bist du
    (`.zeile-aktiv`), blau = erledigt, **orange = übersprungen** (`.zeile-uebersprungen`,
    `#ff6600`), grau = kommt noch. Grün für „erledigt" ist der naheliegende Griff und falsch
    herum — Grün zieht den Blick, und den soll ziehen, was als Nächstes zu tun ist.
    **`.zeile-aktiv` gibt es nur bei laufender Einheit**, ebenso Orange und den aufgeklappten
    Satzblock: Alles drei ist eine Aussage über einen Ablauf, und ohne Training läuft keiner.

    **Grün ist NICHT „die erste noch nicht erledigte Position".** Das war es bis `1.1.5` und
    ist falsch, sobald man eine Übung auslässt, weil das Gerät besetzt ist: Die Markierung
    blieb auf der ausgelassenen Übung stehen, während man längst zwei Geräte weiter war. Die
    Regel steht ausgeschrieben in **`positions_zustaende()`** (`lib/training.php`); kurz:
    grün ist die Position, an der gerade protokolliert wird (Eintrag, aber noch nicht
    erledigt), sonst die erste offene *nach* der letzten mit Eintrag, sonst die erste offene
    überhaupt. Orange ist jede offene Position **davor** — das übersprungene Gerät, zu dem
    man zurückwill.

    **Die Regel steht zwangsläufig zweimal:** `positions_zustaende()` in PHP für den
    Seitenaufbau, `aktiveMarkieren()` in `index.js` für den Betrieb. Beide Hälften gehören
    zusammen geändert, sonst springt die Farbe beim nächsten Neuladen. Dasselbe gilt für
    `zurAktivenSpringen()`: Es muss auf `.zeile-aktiv` zielen und darf nicht selbst „die
    erste nicht erledigte" suchen — sonst springt die Ansicht nach dem Auslassen zurück auf
    das besetzte Gerät.

    **Und beim Scrollen an eine Position gehört die Verbindungsleiste eingerechnet.** Sie
    hängt als **erstes Element im `<body>`** (`assets/app.js`, `verbindung._element()`) und
    ist `position: sticky; top: 0; z-index: 20` — sie überlagert also alles, was darunter
    durchscrollt. `scrollIntoView({ block: 'start' })` setzt das Ziel exakt an den oberen
    Viewport-Rand und damit **unter** die Leiste. Im Training fiel das zuverlässig auf:
    Genau beim Abhaken wird die Leiste sichtbar (die Eingabe geht in die Warteschlange), und
    die nächste Übungskarte landete verdeckt — der Name war weg. `zurAktivenSpringen()` in
    `index.js` rechnet die Höhe deshalb **gemessen** heraus (`offsetHeight`, 0 wenn
    ausgeblendet) statt mit einer festen Zahl: Der Text der Leiste kann auf schmalen Geräten
    zweizeilig werden.

    Dazu die Lehre daneben: **`.saetze-kopf` allein reicht als Selektor nicht.**
    `.summary-knopf` steht weiter unten in derselben Datei und hat dieselbe Spezifität, also
    gewinnt die spätere Regel. Deshalb `.saetze-block > .saetze-kopf`. Wer einen
    `.summary-knopf` irgendwo umfärben will, braucht denselben Griff — sonst passiert
    schlicht nichts, und zwar ohne Fehlermeldung.

20. **Der verzögerte Satz-Speicher muss vor Beenden und Tauschen ausgelöst werden**
    (`satzSpeichernJetzt()` in `index.js`). Änderungen an der Satzliste gehen erst 800 ms
    nach der letzten Eingabe raus — sonst löste jeder Tipp auf `−`/`+` einen eigenen Aufruf
    aus. Der Preis: In diesem Fenster steht der Eintrag **noch nicht in der Warteschlange**.

    `einheitBeenden()` und `tauschOeffnen()` prüfen aber beide genau die Warteschlange
    (`schlange.anzahl()` bzw. `schlange.eintrag(peId)`). Ohne den vorgezogenen Aufruf sähen
    sie über einen wartenden Satz hinweg — die Einheit schlösse über ihn hinweg, oder ein
    Tausch liefe an ihm vorbei. Das ist dieselbe Falle wie in Fallstrick 13, nur eine Ebene
    davor.

    Aus demselben Grund zeichnet `saetzeSetzen()` die Zeilen **nur** neu, wenn sich ihre
    Anzahl geändert hat. Beim Tippen und beim Stepper stehen die Felder schon richtig; sie
    über `innerHTML` zu ersetzen risse dem Benutzer den Fokus mitten aus der Eingabe und den
    Knopf unter dem Finger weg.

    **Die Satzzeile hat genau einen Erzeuger:** `satzZeileMarkup()` in `index.js`.
    `index.php` rendert sie *nicht*, sondern liefert die Werte als JSON in `data-saetze` und
    dazu die Zusammenfassung im `<summary>`. Das ist die Ausnahme von „serverseitig
    gerendertes HTML", und sie ist begründet: Die Zeile ist ein Bedienelement, das sich im
    Betrieb ständig ändert (Satz dazu, Satz weg), JS muss sie also ohnehin bauen können —
    eine zweite Fassung in PHP wäre irgendwann verschieden. Für die Formatierung
    Für die Formatierung einer Satzfolge gibt es dagegen zwangsläufig **zwei Paare**, jeweils
    PHP + JS, und beide Hälften gehören zusammen geändert:

    | Funktion | Ergebnis | Wofür |
    |---|---|---|
    | `saetze_text()` / `saetzeText()` | `12×40 · 10×40 · 9×45` | die bloße Liste — für die Spalte „Sätze" im Verlauf, deren Kopf die Anzahl schon nennt |
    | `saetze_zusammenfassung()` / `saetzeZusammenfassung()` | `3 Sätze (12×40 · 10×40 · 9×45)` | eine Satzfolge für sich — Zeile „zuletzt …" und Kopf des Satzblocks |

    **Die Zusammenfassung hat genau eine Schreibweise, und das ist keine Kosmetik.** Die
    Zeile „zuletzt …" (server-gerendert) und der Kopf des Satzblocks (im Browser gebaut)
    stehen am Handy direkt übereinander — oben, was letztes Mal war, darunter, was gerade
    entsteht. Zwei verschiedene Schreibweisen liest man dort unwillkürlich als Unterschied in
    der Sache. Klammer statt `3 Sätze · 12×40`, weil der Mittelpunkt schon die Sätze
    untereinander trennt: Als Trenner zwischen Anzahl und Liste gelesen, sieht „3 Sätze" aus
    wie ein weiterer Listeneintrag.

21. **Die Plan-Rotation liest ihren Stand aus der Historie, sie merkt ihn sich nicht**
    (`zuletzt_trainierter_plan()` in `lib/training.php`, §7.6). Bis `1.1.5` stand der
    Ausgangspunkt in `users.last_plan_id` — geschrieben **nur beim Beenden** einer Einheit,
    zurückgenommen **nie**. Wer eine Einheit zum Ausprobieren startete, beendete und wieder
    löschte, bekam von da an dauerhaft den falschen Plan vorgeschlagen: Die Einheit war weg,
    ihre Wirkung auf die Rotation blieb. Aufgefallen am 2026-08-12, als nach einer
    Pull-Einheit wieder *Pull* vorgeschlagen wurde.

    Die allgemeine Form des Fehlers: **Was sich aus der Historie ableiten lässt, gehört nicht
    zusätzlich in eine Spalte.** Zwei Quellen für dieselbe Aussage laufen auseinander, sobald
    ein Löschpfad die eine anfasst und die andere nicht — und der Löschpfad wird immer
    vergessen, weil er anderswo steht. Ein `SELECT … ORDER BY started_at DESC LIMIT 1` kostet
    nichts und kann gar nicht veralten.

    Zwei Feinheiten, die dabei entschieden wurden:

    - **Gezählt wird JEDE Einheit, auch eine ohne einzige Protokollzeile.** Das ist eine
      ausdrückliche Entscheidung des Benutzers (2026-08-12) gegen den naheliegenden
      Gegenentwurf: Die Rotation richtet sich **starr** nach der Historie, und eine leere
      Einheit *steht* in der Historie. Wer sie nicht gezählt haben will, löscht sie — die
      Historie sauber zu halten ist Sache des Benutzers. Eine zweite, stille Regel („zählt
      nur mit Protokollzeile") sähe man beim Blick auf den Verlauf nicht, und dann wäre
      wieder unerklärlich, warum ein Plan vorgeschlagen wird.
    - **`users.last_plan_id` bleibt als Spalte stehen**, wird aber weder gelesen noch
      geschrieben. Sie zu entfernen wäre eine löschende Migration ohne Gegenwert; sie ist
      deshalb in `schema.sql` als tot gekennzeichnet. **Nicht wieder in Betrieb nehmen.**

22. **`api/exercises.php → update` ersetzt die ganze Übung, es ändert keine Felder.**
    `aktion_bearbeiten()` schreibt `name_de`, `name_en`, `description`, `focus`, `equipment`
    **und** über `gruppen_schreiben()` die komplette n:m-Zuordnung neu — aus dem, was in der
    Nutzlast steht. Wer nur die zwei Felder schickt, die er ändern will, verliert Name, Gerät
    und **sämtliche Muskelgruppen**; `eingabe_pruefen()` verlangt `name_de`, `equipment` und
    `primary_group_id` als Pflicht, alles andere fällt still auf `null` oder die leere Liste.

    Und das ist der eigentliche Fallstrick: **Die Antwort lautet trotzdem `ok:true`.** Es
    gibt keine Fehlermeldung, keine 422 — die Übung steht danach ohne Zuordnung da und
    taucht im Tausch nie wieder auf. Jeder Aufruf muss die unveränderten Felder aus einem
    **vorher gezogenen Abzug** wörtlich mittragen.

    **Das Muster ist nicht einheitlich, deshalb hilft Analogieschluss nicht.**
    `api/plans.php → rename_plan` und `api/users.php → set_admin` fassen genau eine Spalte
    an (`UPDATE plans SET name = ?`); `api/exercises.php → update` und
    `api/muscle_groups.php → update` ersetzen die ganze Zeile. Vor dem ersten Schreibzugriff
    auf einen unbekannten Endpunkt gehört das `UPDATE`-Statement gelesen.

    **Jedes neue Feld an `exercises` erbt dieses Verhalten.** So kam `image_crop` in
    `1.1.7` dazu: Ein `update` ohne das Feld setzt es auf `mitte` zurück, nicht etwa auf
    den bisherigen Wert. Das ist beabsichtigt und konsistent — die Nutzlast beschreibt die
    Übung vollständig —, aber es heißt, dass ein selbstgebautes Skript mit jedem neuen Feld
    stiller veraltet. Wer eines schreibt, zieht den Abzug **unmittelbar vorher** und schickt
    alles zurück, was darin steht.

    **Das Bild bleibt dagegen von selbst stehen** — aber nur, solange nichts mitkommt:
    `$bildSpalte = $neuesBild ?? ($entfernen ? null : $altesBild)`. Ohne `$_FILES` und ohne
    `image_remove` fällt es auf den bestehenden Pfad zurück. Bei einer **JSON**-Nutzlast ist
    `$_FILES` zwangsläufig leer, das Bild also sicher; wer `multipart` schickt, muss
    aufpassen. `read_input()` nimmt `$_POST`, wenn es nicht leer ist, sonst den JSON-Body —
    beide Wege stehen offen, obwohl das Formular `multipart` benutzt.

## Deployment

Docker-Container (`php:8.3-apache`) im LXC `10.10.10.2` auf einem Hetzner-Rootserver mit
Proxmox, davor der Host-Nginx mit Let's-Encrypt für `training.jadefalke.net`.
Ablauf in `deploy/ANLEITUNG.md`, Topologie in `doku/deployment.md`.

```bash
bash deploy/paket_bauen.sh    # Positivliste packen, lintet vorher
```

**Das Paket trägt die Versionsnummer im Dateinamen** —
`deploy/trainingsplan-build-1.1.2.tar.gz`. Es verlässt den Rechner: Hochgeladen wird es
irgendwann später in Portainer, und ein Tarball ohne Nummer sieht aus wie jeder andere. Der
Name wird erst **nach** dem Versionsabgleich gebildet, damit die Nummer darin geprüft ist.
Existiert die Datei schon, warnt das Skript und packt trotzdem — beim Nachbessern *vor* dem
Ausrollen ist Überschreiben richtig, und nur der Benutzer weiß, ob die Nummer bereits
draußen ist.

**Nach dem Bau bleibt genau ein Paket liegen**: Das Skript löscht ältere
`trainingsplan-build-*.tar.gz`, sonst sammelten sich dort mit jeder Runde welche an und der
falsche wäre beim Hochladen einen Griff entfernt. Das Aufräumen läuft **erst nach der
Sicherungsprüfung** — bricht der Bau vorher ab, soll das zuletzt funktionierende Paket noch
dastehen. Und ausschließlich auf dem eigenen Namensmuster, nie auf `*.tar.gz`.

**Das Paket wird ausschließlich auf ausdrückliche Ansage gebaut.** Nicht nach jeder
Änderung, nicht „damit es bereitliegt". Der Benutzer gibt seine Rückmeldungen aus dem
Praxistest in mehreren kleinen Runden durch; ein Paket nach jeder Runde verbraucht eine
Versionsnummer für einen Stand, der nie ausgerollt wird.

Daraus die Zählweise:

- **Solange kein Paket gebaut ist, bleibt die Nummer stehen.** Fünf Änderungswünsche
  nacheinander ergeben *eine* Version, nicht fünf.
- **Sobald `paket_bauen.sh` unter einer Nummer gelaufen ist, ist sie vergeben.** Die
  nächste Änderung an etwas, das **im Paket steckt**, hebt sofort auf die nächste Nummer —
  sonst weicht der Arbeitsstand von einem Paket ab, das denselben Namen trägt. Genau so
  ging am 2026-08-10 verloren, welche von zwei Fassungen als `1.0.16` ausgerollt worden war.
- **Änderungen außerhalb des Pakets zählen nicht mit.** `doku/`, `CLAUDE.md` und
  `LASTENHEFT.md` stehen nicht in der Positivliste von `paket_bauen.sh`; wer nur dort
  schreibt, lässt die Nummer stehen. Eine neue Version ohne jeden Codeunterschied wäre ein
  zweites Image mit identischem Inhalt — Verschwendung und irreführend zugleich.
- Der Sprung selbst ist gratis und braucht keine Rückfrage; nur das **Bauen** braucht sie.

- **Es gibt zwei Compose-Dateien, und sie tun mit Absicht Gegenteiliges.**
  `docker-compose.yml` im Wurzelverzeichnis hat `build: .` und `image: trainingsplan:latest`
  — das ist die Fassung zum Selberbauen auf einer Maschine, die den Quelltext sieht.
  `deploy/stack.yml` ist die Portainer-Fassung: **ohne** `build:` und mit fester
  Versionsnummer. Nur letztere gehört ins Stack-Feld; wer die Wurzeldatei einträgt, lässt
  Portainer nach einem Quelltext suchen, den es dort nie gibt. Der Versionsabgleich in
  `paket_bauen.sh` prüft `deploy/stack.yml`, nicht `docker-compose.yml`.
- **Verwaltung über Portainer**, wie die übrigen Container auf diesem LXC (u. a.
  `solarwatch`, `/home/rezeption/Projekte/Solarwatch` — dort steht die Vorlage für
  `deploy/stack.yml`, `deploy/env-vorlage.txt` und `deploy/paket_bauen.sh`). Portainer sieht
  den Quelltext nicht: erst Image aus einem hochgeladenen Tarball bauen, dann Stack **ohne**
  `build:` und mit fester Versionsnummer statt `:latest`.
- **Immer korrekt zur nächsten Versionsnummer weiterzählen.** Eine Nummer steht für genau
  einen Stand — weder das Paket noch das Image werden unter einer schon vergebenen Nummer
  ein zweites Mal gebaut, sonst trügen zwei verschiedene Stände denselben Namen. Wie viele
  Nummern an einem Kalendertag anfallen, spielt keine Rolle: Mal sind es fünf, mal
  wochenlang keine.
- **„Re-pull image" beim Stack-Update ausgeschaltet lassen.** Das Image existiert nur lokal
  auf dem LXC; ein Pull-Versuch scheitert mit `manifest unknown`.
- **Zwei Volumes zwingend:** `/var/www/html/data` und `/var/www/html/uploads`. **Named
  Volumes, keine Bind-Mounts** — nur so erbt das Volume beim ersten Befüllen den `chown` auf
  `www-data` (UID 33) aus dem Image; ein Bind-Mount entstünde als `root:root` und die App
  könnte weder DB anlegen noch Bilder speichern.
- **Container-Port an die LXC-IP `10.10.10.2:8066` binden**, nie `0.0.0.0` — und ausdrücklich
  **nicht** an `127.0.0.1`, das wäre für den Nginx auf dem Proxmox-Host unerreichbar. Davon
  hängt ab, dass dem `X-Forwarded-Proto`-Header vertraut werden darf.
- **Pflicht im `Dockerfile`:** `mod_remoteip` (ohne echte Client-IP sperrt die
  Brute-Force-Bremse alle Benutzer gemeinsam), `libsqlite3-dev` (Header für `pdo_sqlite`),
  `libzip-dev` + `zip` (Sicherung mit Bildern), `libwebp-dev` (WebP-Upload).
- **`COPY . /var/www/html` legt auch `Dockerfile`, `apache-app.conf`, `schema.sql` und
  `VERSION` ins Webroot.** Ein `<FilesMatch>` in `apache-app.conf` sperrt sie für HTTP; PHP
  liest `schema.sql` und `VERSION` weiterhin über das Dateisystem. Nicht entfernen —
  `schema.sql` verrät sonst die komplette Datenstruktur.

  **Wer eine Datei ohne Endung ins Wurzelverzeichnis legt, muss sie dort namentlich
  eintragen.** Das Muster passt auf Endungen (`.sql`, `.conf`, `.md`, …) und auf eine
  Handvoll fester Namen; alles ohne Punkt im Namen fällt sonst durch. Genau so lieferte
  der Server `VERSION` von `1.0.10` bis `1.0.11` an jeden aus, der danach fragte —
  aufgefallen erst beim Abgleich gegen die laufende Instanz, nicht lokal. Preisgegeben
  war nur die Versionsnummer, aber die Datei hat im Web nichts verloren.
- **Keine Secrets im Repo.** Konfiguration über `.env` (gitignored), Vorlage `.env.example`.
- **Datentransfer Test ↔ Live** ausschließlich über Backup/Restore in `maintenance.php`, nie
  per Filecopy der laufenden `.db`.

## Warnung: Datenbankänderungen

Test- und Live-Datenbank divergieren. **Vor jeder Schema- oder Datenänderung explizit warnen**
und angeben, was sie auf der Live-DB bewirkt. Ein Restore überschreibt den kompletten
Datenbestand.

Besonders bei **löschenden** Migrationen (`DROP COLUMN`, `DELETE`): vorher prüfen, ob
inzwischen echte Daten betroffen sind, und erneut fragen. Eine einmal erteilte Zustimmung
galt für den Datenstand von damals.

## Vorlagen-Repos

Die Konventionen oben stammen von dort; die genannten Bausteine lassen sich weitgehend
übernehmen statt neu zu schreiben.

- `/home/rezeption/Projekte/Body-Fat-Tracker` — PDO-Setup (`lib/db.php`), `h()`,
  `json_ok()`/`json_err()`, `apiFetch()`, CSRF, Seiten+JS-Paarung
- `/home/rezeption/Projekte/Speisekarte` — GD-Bildverarbeitung, Path-Jail-Auslieferung,
  Rate-Limit-Tabelle; `doku/maintenance_anleitung.md` war die Bauanleitung für die
  Wartungsseite

Beide sind Vorlage, nicht Vorschrift. Wo sie sich widersprechen, gilt der Body-Fat-Tracker
(siehe `LASTENHEFT.md` §2.1).

## Arbeitsweise

- **Deutsch** für Oberfläche, Fehlermeldungen, Kommentare und Commit-Messages.
- Am Ende einer Sitzung die **geänderten Dateien auflisten** (`Geänderte Dateien:` +
  Aufzählung) — das Deployment ist manuell.
- **`doku/stand.md` nachziehen**, sobald sich Version oder Datenstand ändern.
- **Version anheben heißt: an drei Stellen, und `deploy/paket_bauen.sh` erzwingt es.**
  Die Datei **`VERSION`** im Wurzelverzeichnis ist die Quelle — `app_version()` liest sie,
  die Wartungsseite zeigt sie als erste Kachel an, und damit weiß man ohne Portainer,
  welcher Stand live läuft. Dieselbe Nummer gehört in `deploy/stack.yml` (`image:`) und in
  `deploy/ANLEITUNG.md` Schritt 2, wo der Name wörtlich zum Eintippen steht. Weichen die
  drei voneinander ab, packt das Skript nicht — der Abgleich läuft vor allem anderen.

  Der Grund für die Sperre ist nicht Ordnungsliebe: Eine angezeigte Version, die nicht zum
  laufenden Image gehört, ist **schlechter als gar keine**, weil man sich auf sie verlässt.
  Der Rest ergibt sich von selbst — stimmen `VERSION` und `image:` nicht überein, findet
  der Stack sein Image ohnehin nicht.

## Git

Das Repo liegt **privat** auf `git@github.com:Arthagus/Trainingsplan.git`, Branch `main`.
Es sichert **ausschließlich Quelltext** — keine Datenbank, auch keine leere, keine
Übungsbilder, keine `.env`. Die Datenbank entsteht beim ersten Aufruf aus `schema.sql` und
`apply_migrations()`; eine mitgelieferte leere `.db` würde bei jeder Schemaänderung
veralten, und die Ausnahme in der `.gitignore` wäre genau die Lücke, durch die irgendwann
die echte Datenbank rutscht.

**Nicht ungefragt committen** — Zeitpunkt und Umfang bestimmt der Benutzer. Wenn er es
verlangt, vorher prüfen, dass nichts davon im Index steht:

```bash
git diff --cached --name-only | grep -iE '\.(db|zip|tar\.gz)$|^uploads/[^.]|^\.env$|settings\.local'
```

Die `.gitignore` ist eine **Positivliste-Denkweise**: Was neu dazukommt und nicht in ein
öffentliches Verzeichnis gehört, wird dort ergänzt. Schon einmal durchgerutscht wäre
`.claude/settings.local.json.tmp.<pid>.<hash>` — die Regel traf nur den exakten Dateinamen,
nicht die Tempdatei daneben. Deshalb steht dort jetzt ein Stern.

**Git ersetzt die Datensicherung nicht.** Die Daten liegen im Docker-Volume und werden
über die Wartungsseite gesichert (§6.5, ZIP mit Bildern). Aus dem Repo allein entsteht ein
lauffähiges, leeres System — nicht mehr.
