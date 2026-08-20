# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

Arbeitsanweisungen für Claude Code in diesem Repo. `LASTENHEFT.md` ist maßgeblich für das
**Was** (Fachlichkeit, Abnahmekriterien); diese Datei regelt das **Wie** (Konventionen).
Bei Widerspruch gewinnt der Code als Beschreibung des Ist-Zustands, das Lastenheft als
Beschreibung des Soll-Zustands.

**Vier Dateien, vier Fragen** — was in die eine gehört, gehört in keine andere:

| Frage | Datei |
|---|---|
| Wie arbeite ich hier? | **`CLAUDE.md`** — ändert sich nur mit dem Code |
| Was muss die App können? | **`LASTENHEFT.md`** — der Soll-Zustand, ohne seine Entstehung |
| Was läuft gerade, was ist offen? | **`doku/stand.md`** — kurz, nach jedem Rollout nachziehen |
| Wie kam es dazu? | **`doku/historie.md`** — Chronik, **keine** Anweisung |

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
| `LASTENHEFT.md` | Fachlichkeit, Datenmodell, die 21 Abnahmekriterien |
| `doku/stand.md` | **Flüchtig:** laufende Version, Datenstand, offene Punkte — kurz gehalten |
| `doku/historie.md` | **Chronik:** was wann ausgerollt wurde und warum. Keine Anweisung — hier steht, was WAR |
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
                                        expert_mode = 1")->execute();
        db()->prepare("INSERT INTO splits (user_id, name, sort_order, created_at)
                       VALUES (1, \"Test\", 10, ?)")->execute([date("Y-m-d H:i:s")]);
        db()->prepare("UPDATE users SET active_split_id = last_insert_rowid()
                        WHERE id = 1")->execute();'
```

Das `UPDATE` ist kein Schönheitsfehler, sondern nötig: Ohne es sperrt
`require_passwort_gesetzt_api()` **jeden** Endpunkt außer `api/auth.php` (Fallstrick 3),
und der erste `curl`-Test läuft in ein 403, das wie ein Fehler aussieht. `expert_mode = 1`
nur, wenn die Satzerfassung geprüft werden soll — im Standardmodus rendert `index.php` gar
keinen Satzblock, und man sucht den Fehler an der falschen Stelle.

**Der Split ist seit `1.2.0` genauso nötig.** Ohne ihn steht die Trainingsansicht auf
„Noch kein Workout-Split gewählt" und verweist auf `splits.php` — man sucht den Fehler dann
in `plan_positionen()`, wo keiner ist.

**Ohne `APP_SECRET` gibt es lokal kein „Angemeldet bleiben".** `remember_me_available()`
prüft die Variable; fehlt sie, wird `remember: true` **stillschweigend** verworfen, kein
Token entsteht, und der Geräte-Abschnitt auf `password.php` bleibt leer — was wie ein
Fehler in der Seite aussieht. Für Prüfungen daran den Server so starten:

```bash
APP_SECRET=beliebig php -S 127.0.0.1:8100 -t "$(pwd)"
```

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
Antwort und Datenbankzustand vergleichen. Die Abnahme läuft über die 21 manuellen Kriterien
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

**JS-Logik prüft man, indem man sie AUSFÜHRT — nicht, indem man den Quelltext liest.**
Node kann die echten Dateien laden; nachgebaut wird nichts, sonst prüft der Test seine
eigene Nachbildung. Bewährtes Muster (so entstanden die Prüfungen zu `apiFetch`,
`naechsterSatz()`, der Trainingsdauer und der Leisten-Reihenfolge):

```js
const fs = require('fs'), vm = require('node:vm');
// Genau die gebrauchte Funktion aus der Datei holen, statt sie abzuschreiben.
const hol = (t, m) => t.match(m)[0];
const src = fs.readFileSync('index.js', 'utf8');
const app = fs.readFileSync('assets/app.js', 'utf8');
const fn  = new Function('einstellung', [
    hol(app, /function zahlFuerAnzeige\([\s\S]*?\n\}/),
    hol(src, /function naechsterSatz\(karte, saetze\) \{[\s\S]*?\n    \}/),
].join('\n') + '\nreturn naechsterSatz;');
```

Drei Fallen, jede davon hat schon Zeit gekostet:

- **Node ≥ 24 hat ein eigenes `navigator` mit reinem Getter.** `globalThis.navigator = {…}`
  wirft; nötig ist `Object.defineProperty(globalThis.navigator, 'onLine', {value: true})`.
- **Hilfsfunktionen liegen oft in der anderen Datei.** `satzAusDaten()` steht in `index.js`
  und ruft `zahlFuerAnzeige()` aus `assets/app.js`. Wer nur eine Datei anzapft, bekommt
  einen `ReferenceError` — im besten Fall.
- **Und im schlechteren gar keinen:** `satzListeAusDaten()` fängt Fehler ab und liefert
  dann eine **leere Liste**. Der Test meldet ein falsches Ergebnis, das wie ein Fehler im
  Code aussieht und in Wahrheit ein Loch im Prüfstand ist. Passiert am 2026-08-17 genau so.
  Wer eine unerwartet leere Liste sieht, prüft zuerst den eigenen Aufbau.

**Der Gegenbeweis gehört dazu:** Dieselbe Prüfung gegen die Fassung aus dem letzten Commit
laufen lassen (`git show HEAD:assets/app.js > /tmp/alt.js`). Fällt sie dort durch, prüft
sie wirklich etwas.

**Eine brauchbare Test-Datenbank braucht mehr als den Erst-Admin.** Für alles rund um
Training, Verlauf und Sätze führt kein Weg an einem Plan mit Positionen vorbei:

```bash
php -r '
putenv("ADMIN_USER=tester"); putenv("ADMIN_PASSWORD=geheim12345");
require "lib/db.php"; db(); $p = db(); $n = date("Y-m-d H:i:s");
$p->prepare("UPDATE users SET must_change_password = 0, expert_mode = 1")->execute();
$g = (int)$p->query("SELECT id FROM muscle_groups LIMIT 1")->fetchColumn();
$p->prepare("INSERT INTO splits (user_id,name,sort_order,created_at) VALUES (1,?,1,?)")->execute(["Push/Pull",$n]);
$split = (int)$p->lastInsertId();
$p->prepare("UPDATE users SET active_split_id = ? WHERE id = 1")->execute([$split]);
$p->prepare("INSERT INTO plans (user_id,split_id,name,sort_order,created_at) VALUES (1,?,?,1,?)")->execute([$split,"Push",$n]);
$plan = (int)$p->lastInsertId();
foreach (["Bankdrücken","Schrägbank","Butterfly"] as $i => $name) {
    $p->prepare("INSERT INTO exercises (name_de,equipment,created_at) VALUES (?,?,?)")->execute([$name,"maschine",$n]);
    $e = (int)$p->lastInsertId();
    $p->prepare("INSERT INTO exercise_muscle_groups (exercise_id,muscle_group_id,is_primary) VALUES (?,?,1)")->execute([$e,$g]);
    $p->prepare("INSERT INTO plan_exercises (plan_id,exercise_id,sort_order) VALUES (?,?,?)")->execute([$plan,$e,$i+1]);
}
$p->prepare("INSERT INTO sessions (user_id,plan_id,started_at) VALUES (1,?,?)")->execute([$plan, date("Y-m-d H:i:s", time()-2820)]);'
```

**Ohne `plans.split_id` ist der Plan unsichtbar** — `plaene_im_split()` findet ihn nicht,
und die Trainingsansicht verweist auf `splits.php`. Ohne `users.active_split_id` sucht
sich `aktiver_split()` beim ersten Aufruf selbst einen; das ist bequem, aber wer den Wert
im Skript setzt, sieht sofort, was er gebaut hat.

Die Spalten heißen `plans.sort_order` (nicht `position`) und `plan_exercises.sort_order` —
beides schon einmal falsch geraten. Für eine **abgeschlossene** Einheit zusätzlich
`ended_at` setzen und `workout_log`-Zeilen anlegen; für Sätze `workout_sets` mit
`satz_nr`, `reps`, `weight`.

**Welche Version live läuft, wird gemessen und nicht erinnert.** Die Asset-Adressen tragen
sie seit `1.1.8` und sind ohne Anmeldung lesbar:

```bash
curl -s https://training.jadefalke.net/login.php | grep -o 'app\.js?v=[0-9.]*'
```

Am 2026-08-17 nannte `doku/stand.md` `1.1.11`, während `1.1.13` lief — nach einem Rollout
hatte niemand nachgezogen. Diese eine Zeile beendet die Frage in Sekunden; **im Zweifel
immer zuerst messen**, bevor man auf einer Versionsannahme weiterbaut.

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
| `lib/auth.php` | Sitzung, Rollen, Remember-Me, Brute-Force-Bremse, Kontosperre |
| `lib/helpers.php` | `h()`, JSON-Envelope, Eingabenormalisierung, Zeitformate, zweisprachiger Übungsname |
| `lib/csrf.php` | Token, `csrf_check()` für JSON und Formulare, `CSRF_FEHLER_CODE` — steckt in der Boilerplate **jeder** geschützten Seite |
| `lib/training.php` | Die Fachlichkeit aus §7: Rotation, Positionen, Tausch, Sätze, Verlauf, Codeliste `SATZ_VORLAGE` |
| `lib/splits.php` | Workout-Splits (§6.4): Katalog, Kopieren, aktiver Split, Inhaltssignatur, Textausgabe `split_texte()`, **die zentrale Rechteprüfung** `split_zugriff_api()` |
| `lib/geraete.php` | Codelisten `GERAETE` und `ZUSCHNITT`, `geraet_abzeichen()` |
| `lib/backup.php` | Sichern über `VACUUM INTO`, Prüfen, Wiederherstellen |
| `lib/upload.php` | Bildannahme mit MIME-Prüfung und GD-Re-Enkodierung |
| `lib/healthcheck.php` | Was der HEALTHCHECK im `Dockerfile` startet — fasst die Datenbank an und **begründet** ein „unhealthy" |
| `lib/view_header.php` / `view_footer.php` | Layout als Partial, inklusive Leisten-Stapel `#leisten` |
| `lib/view_geraet_symbole.php` | SVG-Symbolvorrat + Beschriftungen, aus dem Header eingebunden |
| `lib/view_bild_dialog.php` / `view_platzhalter.php` | Geteilte Bausteine für Übungsbilder — von `index.php` und dem Adminbereich gemeinsam benutzt |

**Die JSON-Endpunkte tragen ihren Gegenstand im Namen**, mit zwei Ausnahmen, die man
kennen muss: `api/token.php` liefert ein frisches CSRF-Token (Fallstrick 23) und ist der
**einzige** Endpunkt ohne `csrf_check()`; `api/maintenance.php` bedient die Wartungsseite
(Sicherungen anlegen, prüfen, einspielen — die Fachlichkeit steckt in `lib/backup.php`).

**`admin.php` ist der Einstieg in die Verwaltung und kann selbst nichts** (seit `1.2.2`):
vier Kacheln zu `admin_exercises.php`, `admin_muscle_groups.php`, `admin_users.php` und
`maintenance.php`, sonst nichts. Wer dort eine Funktion einbaut, hat eine fünfte
Verwaltungsoberfläche neben den vieren, die es schon gibt. Der Menüpunkt *Admin* bleibt
auch auf den Unterseiten hervorgehoben — die Liste dafür steht in `lib/view_header.php`
und **muss mitwachsen**, wenn eine fünfte Adminseite dazukommt; sonst wirkt die Kopfzeile
dort, als stünde man nirgends.

**`splits.php` und `plans.php` sind seit `1.2.0` KEINE Adminseiten mehr** — trotz des
Nachbarn `admin_exercises.php` und obwohl `plans.php` bis dahin `admin_plans.php` hieß.
Jeder Benutzer verwaltet dort seine eigenen Splits und deren Pläne; was ein Admin
zusätzlich darf (Vorlagen pflegen, fremde Splits bearbeiten), entscheidet
`split_zugriff_api()` und **nicht** ein `require_admin()` am Seitenkopf. Wer eines
ergänzt, sperrt die normalen Benutzer aus ihrem eigenen Bestand aus.

**`password.php` heißt „Konto" und trägt VIER Aufgaben** (§7.7): Passwort ändern,
Benutzername ändern, Trainingsansicht (Expertenmodus und Satz-Vorbelegung) — und seit
`1.2.3` die **Geräteverwaltung** samt **Abmelden**. Die Geräte lagen bis dahin auf einer
eigenen Seite `devices.php` mit eigenem Menüpunkt; beide sind ersatzlos entfallen. Damit
ist auch die alte Verwechslungsgefahr weg: `lib/geraete.php` sind die *Trainings*geräte
(Hantel, Maschine), etwas völlig anderes.

Zwei Dinge daran sind nicht beliebig:

- **Der Abmelden-Abschnitt steht AUSSERHALB des `!$erzwungen`-Blocks.** Wer ein
  Startpasswort hat, erreicht keine andere Seite (Fallstrick 3) — und seit der Punkt nicht
  mehr in der Kopfzeile steht, säße er ohne diesen Link fest.
- Widerrufen wird weiter über `api/auth.php → revoke_device` / `revoke_all`; die Logik
  aus `devices.js` liegt jetzt am Ende von `password.js`. Abnahmekriterium 18 („ein Gerät
  abmelden") hängt an diesem Abschnitt.

**`logout.php` ist GET und bewusst ohne `csrf_check()`.** Ein erzwungenes Abmelden ist
lästig, aber kein Schaden — ein Abmeldeweg, der an einem abgelaufenen Token scheitert, wäre
schlimmer. Es ist auch bei erzwungenem Passwortwechsel erreichbar und hängt seit `1.2.3`
**nur noch** am Abschnitt *Abmelden* auf der Kontoseite.

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

**Seite = `.php` + gleichnamige `.js`.** `index.php` lädt `index.js`, `admin_exercises.php` lädt
`admin_exercises.js` — `lib/view_footer.php` bindet sie selbsttätig ein. Geteiltes gehört in
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

  **Die Liste `$noetig` in `db_datei_pruefen()` (`lib/backup.php`) ist bewusst unvollständig**
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
  Konto löschen noch sich selbst das Adminrecht entziehen **noch sich selbst sperren** —
  jedes davon führte in einen Zustand, den er selbst nicht mehr rückgängig machen könnte.
  Das geht über §6.1 hinaus, das nur den *letzten* Admin schützt.

  **Das Sperren (`set_blocked`, seit `1.1.11`) braucht daneben KEINE Letzter-Admin-Regel**,
  und das ist kein Versehen: Sperren darf nur ein angemeldeter Admin, und sich selbst kann
  er nicht sperren — es bleibt also zwangsläufig mindestens ein aktiver Admin übrig, nämlich
  der Handelnde. Eine zusätzliche Prüfung wäre eine Regel, die nie greifen kann; solche
  Regeln sehen wie ein Schutz aus und verdecken, wo der echte sitzt.

  **Die Sperre wird an DREI Stellen durchgesetzt, und alle drei werden gebraucht:**
  `attempt_login()` (Anmeldung mit Passwort), der `JOIN` in `try_remember_login()`
  („Angemeldet bleiben") und `current_user()` (die bereits laufende Sitzung). Die dritte
  ist die wichtigste und die am ehesten vergessene: Ohne sie liefe eine offene Sitzung
  weiter, bis sie von selbst abläuft — die Sperre wirkte also erst Stunden später. Weil
  `current_user()` hinter jeder geschützten Seite und jedem Endpunkt liegt, greift sie
  dort ab dem nächsten Aufruf, ohne dass ein Aufrufer daran denken muss.

  **`attempt_login()` liefert deshalb `string` und nicht `bool`** (`LOGIN_OK`,
  `LOGIN_FALSCH`, `LOGIN_GESPERRT`): „gesperrt" ist weder Erfolg noch falsches Passwort.
  Geprüft wird **nach** der Passwortprüfung — davor verriete die Auskunft, welche
  Kontonamen es gibt. Und `api/auth.php` zählt den Fall **nicht** als Fehlversuch: Das
  Passwort war ja richtig, und die Bremse zählt pro IP — ein gesperrter Benutzer würde mit
  fünf Versuchen sonst den ganzen Haushalt für eine Viertelstunde aussperren.

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

  **Ein totes CSRF-Token repariert `apiFetch` selbst** (seit `1.1.10`, Fallstrick 23): Bei
  einem 403 mit `code === 'csrf_ungueltig'` holt es über `api/token.php` ein frisches Token,
  schreibt es in den `<meta>`-Tag und wiederholt den Aufruf **genau einmal** — ohne einen der
  `wiederholen`-Versuche zu verbrauchen, denn der Aufruf ist nicht am Netz gescheitert. Die
  Erneuerung läuft über eine geteilte Zusage (`tokenErneuerung`), damit sich zwei gleichzeitig
  gescheiterte Aufrufe nicht gegenseitig überschreiben. **`index.js` bleibt davon unberührt** —
  die Warteschlange sieht entweder Erfolg oder einen Fehler, der wirklich einer ist, und
  Fallstrick 13 („4xx muss den Eintrag entfernen") gilt unverändert.
- **JSON-Envelope serverseitig:** `json_ok(array $data = [], int $status = 200)` und
  `json_err(string $error, int $status = 400, array $fields = [], ?string $code = null)`.
  **`$code` ist maschinenlesbar und ersetzt den Text nicht** — er ist die Auskunft an das
  Skript, `error` die an den Benutzer. Es gibt bisher genau einen: `CSRF_FEHLER_CODE` aus
  `lib/csrf.php`. Wer stattdessen im Browser auf den deutschen Wortlaut prüft, baut eine
  Kopplung, die beim ersten Umformulieren einer Meldung lautlos bricht.
- **Escaping:** serverseitig `h()` aus `lib/helpers.php`, clientseitig `escapeHtml()` aus
  `assets/app.js` vor jedem `innerHTML`.
- **Zahleneingaben:** `type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"` —
  nicht `type="number"`, sonst bricht das Dezimalkomma am Handy.
- **Service Worker:** cacht **nur** `assets/*.css`, `assets/*.js`, `manifest.json`, Icons —
  mit **`stale-while-revalidate`**. **Niemals HTML oder API-Antworten** (`network-only`),
  sonst wird eingeloggter Zustand nach dem Logout ausgeliefert und veraltete CSRF-Tokens
  erzeugen 403er. Zur Cache-Falle siehe Fallstrick 12.
- **Asset-Adressen tragen die Version** (`style.css?v=…`), gesetzt in
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

Die Stellen, an denen eine naive Umsetzung falsch wird. **Fast jede steht hier, weil sie
schon einmal zugeschlagen hat** — die Ausnahmen sind 22 und 24–26, die beim Entwurf
auffielen. Die Nummern sind nach Entstehung vergeben, nicht nach Wichtigkeit.

**Die Nummern sind eine Zusage und werden nicht nachgezogen.** Sie werden aus dem Code
heraus zitiert — `schema.sql`, `assets/app.js`, `api/token.php`, `lib/training.php` und ein
Dutzend weitere Stellen nennen sie. Ein Umnummerieren bräche jeden dieser Verweise, **ohne
dass irgendwo ein Fehler entstünde**: Der Verweis zeigt dann einfach auf den falschen
Eintrag. Wird ein Fallstrick gegenstandslos, bleibt seine Nummer deshalb als Platzhalter
stehen (siehe **11**), statt die folgenden aufrücken zu lassen.

**Wo anfangen:** an Splits und Plänen arbeitet man mit **24–26**, am Training mit
**1, 2, 13, 17, 18**, an Deployment und Caching mit **12** und **23**, an allem, was einen
Übungsnamen anzeigt, mit **27**.

**Die Vorgeschichte steht in `doku/historie.md`** — wer wann was gemeldet hat, welche
Version es brachte, was vorher galt. Hier steht nur, was gilt und warum.

1. **Eine Einheit entsteht AUSSCHLIESSLICH über „Training starten"** (§7.6).
   `einheit_sicherstellen()` hat genau **einen** Aufrufer: `api/session.php → start`. Wer
   einen zweiten ergänzt, hebt die Zusicherung auf. `api/log.php` und `api/swap.php`
   („nur diese Einheit") antworten deshalb mit **409**, statt anzulegen — ein Fehlgriff
   beim bloßen Durchsehen begann sonst ein Training, das niemand wollte, und `started_at`
   hielt den Fehlgriff fest statt des Trainingsbeginns. Vor dem Knopf hielt es sogar das
   *Ende* der ersten Übung fest: Jede Auswertung der Trainingsdauer war damit systematisch
   zu kurz, und zwar ohne dass irgendetwas danach ausgesehen hätte.

   Daraus die Sperre in der Oberfläche: **Vor dem Start sind „Erledigt", „+ Satz" und das
   Gewichtsfeld deaktiviert** (`index.php` über `$laeuft`, `index.js` über `sessionId > 0`)
   — Bequemlichkeit vor der serverseitigen Regel.

   **Der dauerhafte Tausch bleibt vor dem Start möglich**: Er schreibt in `plan_exercises`
   und braucht keine `session_id`. Nur „Nur diese Einheit" braucht eine und wird vorher
   gar nicht erst angeboten (`TAUSCH_KNOEPFE` in `index.js`) — mit Hinweissatz, sonst
   sieht der fehlende Knopf wie ein Fehler aus.

2. **Eine Einheit gehört zu genau einem Plan.** Jeder Endpunkt mit `plan_exercise_id`
   prüft neben der Eigentümerschaft, dass die Position zum Plan der laufenden Einheit
   gehört (`position_passt_zur_einheit()`). Sonst ließe sich eine Position aus einem
   anderen Plan in die offene Einheit protokollieren: `workout_log.plan_id` widerspräche
   `sessions.plan_id`, und weil „x" über die Einheit, „n" aber über den Plan gezählt wird,
   stünde in der Anzeige „2/1". Über die Oberfläche nicht erreichbar — über einen zweiten
   Tab mit veraltetem Plan schon.

3. **Die API kennt dieselben Sperren wie die Seiten.** `require_login_api()` allein genügt
   nicht: `require_passwort_gesetzt_api()` gehört in **jeden** Endpunkt außer
   `api/auth.php`. Sonst benutzt jemand mit dem vom Admin vergebenen Startpasswort die
   ganze API, ohne je ein eigenes zu setzen — und `must_change_password` wäre wirkungslos.

4. **`workout_log` ist eindeutig über `(session_id, plan_exercise_id)`**, nicht über
   `exercise_id` (§4). Nach einem Tausch steht in `exercise_id` die Ersatzübung; ohne die
   Planposition ist weder „x/n" zählbar noch der Schlüssel eindeutig.

   **Daraus folgt für jeden Umbau an Planpositionen: VERSCHIEBEN, nicht löschen und neu
   anlegen.** `api/plans.php → move_exercise` (Übung in den Nachbarplan) ändert deshalb
   `plan_exercises.plan_id` per `UPDATE` — die `id` bleibt, alle `workout_log`-Zeilen
   behalten ihren Bezug. `DELETE` + `INSERT` hätte `plan_exercise_id` über
   `ON DELETE SET NULL` geleert und die Zuordnung protokollierter Sätze verloren —
   **lautlos, mit `ok:true`**, sichtbar erst Wochen später im Verlauf.

5. **Kein Auto-Ende ohne Rückfrage** (§7.6). Sind alle Positionen abgehakt, erscheint eine
   Bestätigung — die Einheit schließt sich nie von selbst, sonst wird das Ab-wählen eines
   versehentlichen Häkchens undefiniert.

6. **Eine abgehakte Position lässt sich nicht tauschen** (§7.5) — weder für die Einheit
   noch dauerhaft. Der Weg: Häkchen entfernen, tauschen, neu abhaken. Grund: Ein
   Protokolleintrag dokumentiert eine *tatsächlich ausgeführte* Übung; ihn umzuschreiben
   schlüge das erreichte Gewicht einer Übung zu, die nicht gemacht wurde. Gesperrt wird
   **serverseitig** (`position_abgehakt()` in `api/swap.php`), der deaktivierte Knopf ist
   nur die Bequemlichkeit.

   **Dasselbe gilt für Werte:** Geändert wird durch Ab-wählen, nicht durch Bearbeiten
   (§7.4). Das Gewichtsfeld ist nach dem Abhaken `readonly`, und `api/log.php` hat bewusst
   **keine** `update`-Aktion. Ein Mechanismus statt zweier.

7. **Übungen werden archiviert, nicht gelöscht** (`archived = 1`, §6.3). Hartes Löschen
   verletzt die Fremdschlüssel des `workout_log` — die Historie muss vollständig bleiben.
   Archiviert heißt **versteckt, nicht verschwunden**: Filter *Aktiv / Archiviert / Alle*
   mit sichtbaren Anzahlen, jede archivierte Zeile nennt Datum, betroffene Pläne und die
   Anzahl ihrer Log-Einträge. Endgültig löschbar ist nur, was weder in einem Plan steht
   noch Log-Einträge hat.

8. **„Letztes Gewicht" überspringt leere Werte:** `WHERE weight IS NOT NULL ORDER BY
   performed_at DESC, id DESC LIMIT 1` (§4). Sonst geht ein Gewicht verloren, nur weil es
   einmal nicht eingetragen wurde. Das `id DESC` ist Pflicht: Zeitstempel haben
   Sekundenauflösung, zwei Einträge derselben Sekunde hätten sonst keine definierte
   Reihenfolge — die Vorbelegung wäre zufällig.

   **Wiederholungen gibt es nicht** (§4): Ein Feld je Einheit kann 12/10/9 über drei Sätze
   nicht abbilden. Nicht wieder einführen, ohne satzgenau zu protokollieren.

9. **Muskelgruppen sind zweistufig, und der Tausch vergleicht die HAUPTGRUPPE** (§4, §7.5).
   Hauptgruppen haben `parent_id IS NULL`, mehr als zwei Ebenen gibt es nicht. Dadurch darf
   die Unterteilung beliebig fein werden, ohne die Vorschläge auszudünnen — eine Übung an
   `Brust (oben)` bekommt alles unter `Brust` als Ersatz.

   In der Übungsmaske sind Hauptgruppen **mit** Untergruppen deshalb nicht wählbar, sondern
   Gliederungsüberschrift; sonst gäbe es zwei Wege für dieselbe Aussage. Ein Hinweistext
   erklärt die Tauschregel — ohne ihn überrascht es, dass man `Trizeps` anhakt und
   Bizeps-Übungen vorgeschlagen bekommt.

10. **Genau eine Primärgruppe je Übung** (`exercise_muscle_groups`, §4), abgesichert durch
    den partiellen Unique-Index `idx_emg_one_primary`. Primär = die Gruppe, **wegen der**
    man die Übung macht. Beim Umsetzen erst alle auf 0, dann die neue auf 1 — **in einer
    Transaktion**, sonst schlägt der Index zwischendurch zu.

11. *Entfallen.* Sagte dasselbe wie **1** und verwies selbst darauf; die eine eigene
    Aussage — dass `started_at` vor dem Knopf das Ende der ersten Übung festhielt — steht
    jetzt dort. **Der Platzhalter bleibt stehen:** Markdown nummeriert eine geordnete Liste
    beim Rendern selbst und verwirft die geschriebenen Zahlen. Eine echte Lücke schöbe
    darum alles dahinter um eins — und damit zeigte jeder Verweis aus dem Code auf den
    falschen Eintrag.

12. **Es gibt ZWEI Caches hintereinander, und der Service Worker erreicht nur den ersten.**
    Der eingefrorene Asset-Cache hat zweimal zugeschlagen und war beide Male über mehrere
    Versionen unsichtbar (Hergang: `doku/historie.md`, `1.1.7`/`1.1.8`).

    Die Mechanik, die man kennen muss:

    - Ein Service Worker wird **nur neu installiert, wenn sich seine eigene Datei ändert**.
      Bleibt sie gleich, läuft `install()` nie wieder und `caches.match()` liefert ewig die
      Fassung vom ersten Besuch.
    - Hinter dem SW-Cache sitzt der **HTTP-Cache des Browsers**. `cache.addAll()` holt die
      Dateien mit normalem `fetch` — also durch ihn hindurch. Der frische SW-Cache füllt
      sich dann mit dem alten Stand, dauerhaft und ohne Meldung.
    - **`stale-while-revalidate` heilt das nicht, es zementiert es**: Die Revalidierung ist
      ein gewöhnlicher `fetch` durch denselben HTTP-Cache und schreibt den alten Stand
      zurück. Daran erkennt man den Fehler: Vier, fünf Neuladungen bringen keine Besserung.

    **Die Lehre über den Einzelfall hinaus: Ein Reparaturweg, der durch die kaputte Ebene
    läuft, ist keiner.**

    Was daraus folgt und zusammengehört:

    - **Die Version hängt an der Adresse** (`assets/style.css?v=…`, gesetzt in
      `lib/view_header.php` / `view_footer.php` aus `app_version()`). Eine geänderte
      Adresse kann in **keinem** der beiden Caches einen alten Eintrag treffen. **Wer eine
      neue CSS- oder JS-Datei einbindet, hängt sie dort mit an.**
    - **`CACHE` wird nicht mehr von Hand gepflegt.** `sw.js` liest die Version aus seiner
      eigenen Adresse (`self.location`), registriert wird als `assets/sw.js?v=…`. Es gibt
      keine Nummer mehr, die man vergessen kann.
    - **`cache: 'reload'`** in `install()` **und** bei der Revalidierung — sonst läuft auch
      sie durch den HTTP-Cache.
    - **`Cache-Control: no-cache`** für `assets/` und alle `*.js` (`apache-app.conf`):
      nicht „nicht speichern", sondern „vor jeder Benutzung rückfragen".
    - **Präcache-Adressen müssen exakt die sein, die der Header anfragt.** `ASSETS` in
      `sw.js` führt `style.css` und `app.js` **mit** `?v=`, Manifest und Icons **ohne** —
      genau so stehen sie im `<head>`. Sonst ist der Precache toter Ballast.

13. **Die Warteschlange fürs Abhaken hängt an `user_id` UND `session_id`** (§7.4), jeder
    Schlüssel aus eigenem Grund:

    - `localStorage` gehört der **Herkunft**, nicht der Sitzung — auf einem geteilten Gerät
      holte der nächste Angemeldete sonst die Häkchen seines Vorgängers nach.
    - Ein wartendes Häkchen gehört zu **genau einer Einheit**. Passt der Schlüssel nicht,
      wird die Ablage **verworfen**, nicht geraten.

    Daraus der Rest: **Beenden ist gesperrt, solange etwas aussteht**, **Tauschen einer
    wartenden Zeile ebenso**, und die Schlange läuft **nur innerhalb einer laufenden
    Einheit** — ohne offene Einheit gibt es keine `session_id`, an der sie hinge.

    **Eine 4xx-Antwort muss den Eintrag aus der Schlange entfernen.** Sie fällt bei jedem
    weiteren Versuch gleich aus; bliebe der Eintrag liegen, blockierte er alle folgenden
    dauerhaft. Nur `err.offline` darf ihn liegen lassen.

    **`navigator.onLine` ist als Verbindungsanzeige unbrauchbar** — es meldet nur, ob eine
    Schnittstelle da ist. Im Studio-WLAN ohne Internet steht es auf `true`, während jeder
    Aufruf ins Leere läuft. Deshalb speist `apiFetch` die Leiste aus dem, was **tatsächlich
    gescheitert** ist, und das Nachholen braucht einen **eigenen Zeitgeber**: Das
    `online`-Ereignis feuert in genau diesem Fall nie.

14. **Ein Restore ersetzt die Datenbankdatei, er mischt nicht** (`lib/backup.php`, §6.5):

    - Der Erst-Admin des leeren Systems ist danach **weg**, mitsamt den Muskelgruppen aus
      dem Seed. Richtig so: `seed_muscle_groups()` und `bootstrap_first_admin()` steigen bei
      nicht leerer Tabelle aus, es entstehen keine Dubletten.
    - **`ADMIN_USER`/`ADMIN_PASSWORD` sind keine Schlüssel zu den Daten**, sondern eine
      Starthilfe für die leere Benutzertabelle; nach dem Einspielen gelten die Passwörter
      aus der Sicherung. Ein verlorenes `APP_SECRET` ist ebenso harmlos — es hängt allein
      am Remember-Me-HMAC, Passwörter werden ohne Pepper gehasht.
    - **Die offene Sitzung überlebt den Restore und zeigt danach die falsche Person.** In
      `$_SESSION` steht nur die `user_id`. Nach dem Einspielen ab- und neu anmelden.

15. **`history.php` zeigt ausschließlich eigene Daten** (§7.8) — auch Admins sehen nichts
    Fremdes. Die `user_id` kommt in jeder Abfrage aus der Sitzung, nie aus einem Parameter;
    es gibt bewusst keine Benutzerauswahl.

16. **Das Trainingsgerät ist Anzeige und Filter — nicht Teil der Tauschlogik** (§6.3, §7.5).
    Naheliegend und falsch wäre, den Tausch darauf einzuschränken: Der häufigste Grund zu
    tauschen ist eine *besetzte Maschine*, und ein Filter auf dasselbe Gerät verböte genau
    den Ausweg. `tausch_vorschlaege()` liefert das Feld nur mit.

    Der Gerätefilter **im** Tauschdialog arbeitet auf der bereits abgerufenen Antwort, rein
    im Browser, und bietet nur an, was darin vorkommt — kein zweiter Serverabruf, weil man
    damit im Studio bei schwachem Netz steht. Beide Dialoge teilen sich dafür
    `geraetFilterFuellen()` und `geraetGefiltert()` aus `assets/app.js`.

    **Die zwei Filter der Übungsauswahl (§6.4) schränken sich gegenseitig ein, und jede
    Facette wird ohne ihren EIGENEN Filter gerechnet** (`auswahl_facetten()`). Beide Filter
    auf beide Listen anzuwenden ist der naheliegende Fehler: Dann bliebe nach „Kurzhantel"
    nur noch „Kurzhantel" im Gerätefeld, und die Einschränkung wäre eine Sackgasse. Zur
    Gruppen-Facette gehören außerdem die **Elterngruppen** der Treffer.

    **Codelisten statt Tabellen**, in `lib/geraete.php`: `GERAETE` (das Womit) und
    `ZUSCHNITT` (`links`/`mitte`/`rechts` — welche Seite eines breiten Bildes im
    quadratischen Rahmen bleibt). Klein, geschlossen, nicht am Datenbestand hängend; ein
    achter Gerätetyp soll eine Zeile PHP kosten und keine Migration. Geprüft wird in
    `api/exercises.php`, **kein `CHECK`-Constraint** — das ließe sich in SQLite nur über
    einen Tabellen-Neubau ändern.

    Vier Dinge, die daran hängen:

    - **`ZUSCHNITT` wirkt allein über `object-position` im Stylesheet**, weil
      `write_resized()` ausschließlich **skaliert und nicht beschneidet**. Wer dort je einen
      Zuschnitt einbaut, nimmt der Einstellung die Grundlage: Ein bereits quadratisch
      beschnittenes Thumbnail ist nachträglich nicht mehr anders auszurichten.
    - **Der Schlüssel steht in der Datenbank, die Beschriftung nur in `GERAETE`.** Eine
      Umbenennung ist eine Textänderung ohne Migration; wer den *Schlüssel* ändert, braucht
      ein `UPDATE`.
    - **Das Gerät ist auch beim Bearbeiten Pflicht** — der Mechanismus, über den Altbestand
      seinen Wert bekommt: Die Migration setzt nichts, die Liste mahnt „Gerät fehlt", der
      Filter `GERAET_LEER` findet genau diese Zeilen.
    - **Symbol und Beschriftung haben genau eine Quelle:** `lib/view_geraet_symbole.php`,
      eingebunden aus dem Header. `geraet_abzeichen()` (PHP) und `geraetAbzeichen()` (JS)
      referenzieren beides nur — nötig, weil Abzeichen server- **und** clientseitig
      entstehen. **Wer einen Wert in `GERAETE` ergänzt, ergänzt dort das `<symbol>`** —
      sonst bleibt das Abzeichen leer, ohne Fehlermeldung. Dasselbe Muster bei
      `bild_zuschnitt_klasse()` / `vorschlagMarkup()`; `mitte` liefert bewusst **keine**
      Klasse, das ist der Vorgabewert von `object-position`.

17. **Im Expertenmodus ist die ganze SATZLISTE die Nutzlast, nicht der einzelne Satz**
    (§7.4, `api/log.php`). `check` nimmt sie als Feld `sets` und ersetzt die Sätze der
    Position vollständig — erst `DELETE`, dann `INSERT` von 1 an, in **einer** Transaktion.
    Damit ist der Aufruf idempotent, und genau darauf verlässt sich die Warteschlange. Ein
    „Satz anlegen"-Endpunkt hätte bei jedem Wiederversuch einen weiteren Satz erzeugt.

    Drei Dinge, die zusammengehören:

    - **Ein `check` OHNE `sets` löscht vorhandene Sätze.** Die Nutzlast beschreibt die Zeile
      vollständig.
    - **Deshalb ist der Moduswechsel bei laufender Einheit gesperrt** (409). Ein wartender
      Eintrag aus dem einfachen Modus trägt keine Satzliste — das wäre stiller Datenverlust
      mitten im Training.
    - **`set_satz_vorlage` ist ausdrücklich NICHT gesperrt**, und der Warteschlangen-
      Schlüssel bleibt **`-v3`**: Beide Verfahren schicken dieselbe Satzliste, die *Form*
      eines Eintrags ist unverändert. Ein Sprung würde beim Rollout die wartenden Eingaben
      von jedem verwerfen, der gerade trainiert. Der Reflex „neue Einstellung ⇒ Sperre plus
      neue Schlüsselnummer" ist hier zweimal falsch.

    **Die Vorbelegung eines neuen Satzes ist eine persönliche Einstellung**
    (`users.satz_vorlage`, Codeliste `SATZ_VORLAGE` in `lib/training.php`): `gleicher_satz`
    (Satz k vom letzten Mal, Vorgabe) oder `letzter_satz` (der vorige Satz von heute). Der
    **erste** Satz kommt in beiden Fällen vom letzten Mal.

    **Angewendet wird sie ausschließlich in `naechsterSatz()` (`index.js`), und es gibt
    bewusst KEIN PHP-Gegenstück.** Der Server erfindet nie einen Satz; er liefert nur
    `letzte_saetze()` und `letztes_gewicht()`. Das ist die Ausnahme von den vielen Paaren
    in diesem Projekt — wer die zweite Hälfte sucht, sucht vergebens. Gelesen wird über
    `satz_vorlage_normalisieren()` (fällt auf den Standard zurück, damit eine alte
    Sicherung kein Training abbricht), **geschrieben** nur nach strenger Prüfung gegen die
    Liste.

    `workout_log.weight` bleibt gefüllt und trägt den **schwersten** Satz — keine
    Redundanz: `letztes_gewicht()`, `gewichts_verlauf()` und `uebungen_mit_verlauf()` lesen
    ausschließlich diese Spalte und funktionieren dadurch über beide Modi hinweg.
    `workout_sets` hängt an `workout_log.id` mit `ON DELETE CASCADE` — es gibt **keinen**
    eigenen Löschpfad.

    **Eine leere Satzzeile darf nicht mitgeschickt werden.** `saetze_pruefen()` weist einen
    Satz ohne Wiederholungen *und* ohne Gewicht mit 422 ab; `saetzeFuerServer()` filtert
    sie; `saetzeLesen()` liefert weiterhin **alle** Zeilen, weil die leere im DOM stehen
    bleiben muss — sie wird ja gerade ausgefüllt. Wer die beiden verwechselt, erzeugt einen
    roten Fehlerrand ohne Anlass oder eine Zeile, die beim Tippen verschwindet.

18. **„Protokolliert" und „erledigt" sind zwei Zustände** (`workout_log.done`, §7.4). Im
    einfachen Modus fallen sie zusammen; im Expertenmodus entsteht die Zeile mit dem
    **ersten Satz**, und da ist man mitten in der Übung.

    - **`done = 1` zählt „x/n"** — in `fortschritt()` *und* in `einheiten_verlauf()`. Beide,
      sonst heißt „erledigt" im Verlauf etwas anderes als im Training.
    - **Die Tauschsperre hängt an der EXISTENZ der Zeile**, nicht an `done`: Wer zwei Sätze
      Bankdrücken gemacht hat, kann die Position nicht mehr tauschen. `plan_positionen()`
      liefert dafür `hat_eintrag` neben `erledigt`.
    - **`done` fehlt in der Nutzlast ⇒ erledigt.** Vorgabe für den einfachen Modus und
      Rückfall für ältere Nutzlasten.
    - **Ab-wählen löscht im Expertenmodus keine Sätze**; gelöscht wird die Zeile erst, wenn
      kein Satz mehr übrig und kein Häkchen gesetzt ist.
    - **`done = 1` schreibt die Position fest** (`abgeschlossene_position_schuetzen()`) —
      alles abgelehnt, bis das Häkchen weg ist.

      **Die eine Ausnahme, die man nicht vergessen darf: Eine unveränderte Nutzlast muss
      durchgehen.** Die Warteschlange schickt nach einem Funkloch erneut und trifft dann auf
      die bereits abgehakte Position; ohne die Ausnahme schlüge sie mit 409 fehl, obwohl
      längst alles gespeichert ist. Verglichen wird inhaltlich (`saetze_gleich()`), und
      Gewichte nie mit `===`: 40.0 aus der Datenbank und 40.0 aus der Eingabe sind dasselbe
      Gewicht, aber nicht zwingend dasselbe Bitmuster.

19. **Fünf Regeln zur Trainingsansicht, die zusammengehören** — von der Kartenhöhe über `:hover` bis zum Selektor.

    **(a) Was sich im Sekundentakt ändert, darf nichts verschieben.** Der wartende Zustand trug
    einmal einen Hinweissatz in der Karte — sie wurde beim Speichern höher und danach wieder
    niedriger, und bei jedem Satz sprang die ganze Liste. `.zeile-wartet` ändert deshalb nur
    `border-left-style`, nicht einmal die Farbe. Wie viele Eingaben ausstehen, sagt die
    Leiste am oberen Rand — eine Anzeige genügt.

    **(b) Alle `:hover`-Regeln stehen hinter `@media (hover: hover)`.** Auf einem Touchscreen
    gibt es kein Verlassen mit dem Zeiger, `:hover` klebt am zuletzt angetippten Element —
    im Studio tauschten Satzkopf und „+ Satz" beim Tippen ihre Blautöne.

    **(c) Die Farbe des Balkens ist ein Leitsystem, keine Dekoration:** grün = hier bist du,
    blau = erledigt, **orange = übersprungen** (`#ff6600`), grau = kommt noch. Grün für
    „erledigt" ist der naheliegende Griff und falsch herum — Grün zieht den Blick, und den
    soll ziehen, was als Nächstes zu tun ist. **`.zeile-aktiv`, Orange und der aufgeklappte
    Satzblock gibt es nur bei laufender Einheit**: Alles drei ist eine Aussage über einen
    Ablauf.

    **Grün ist NICHT „die erste noch nicht erledigte Position".** Das ist falsch, sobald man
    eine Übung auslässt, weil das Gerät besetzt ist. Die Regel steht ausgeschrieben in
    `positions_zustaende()` (`lib/training.php`); kurz: grün ist die Position, an der gerade
    protokolliert wird, sonst die erste offene *nach* der letzten mit Eintrag, sonst die
    erste offene überhaupt. Orange ist jede offene Position **davor**.

    **Die Regel steht zwangsläufig zweimal:** `positions_zustaende()` in PHP für den
    Seitenaufbau, `aktiveMarkieren()` in `index.js` für den Betrieb. Beide Hälften gehören
    zusammen geändert, sonst springt die Farbe beim nächsten Neuladen. Dasselbe gilt für
    `zurAktivenSpringen()`: Es muss auf `.zeile-aktiv` zielen und darf nicht selbst „die
    erste nicht erledigte" suchen.

    **(d) Beim Scrollen gehört eingerechnet, was oben klebt.**
    `scrollIntoView({ block: 'start' })` setzt das Ziel **unter** jede `sticky`-Leiste —
    beim Abhaken wird die Verbindungsleiste sichtbar, und die nächste Übungskarte landete
    verdeckt.

    **`#leisten` (`lib/view_header.php`) ist der gemeinsame Behälter** und trägt als
    einziges Element `position: sticky; top: 0`. Darin liegen die Leisten normal im Fluss:
    die Trainingsleiste (nur `index.php` bei laufender Einheit) und darunter die
    Verbindungsleiste (auf jeder Seite, meist ausgeblendet). Zwei Elemente mit eigenem
    `top: 0` legten sich übereinander.

    **Die Reihenfolge im Stapel ist nach BESTÄNDIGKEIT sortiert, nicht nach Wichtigkeit.**
    Die Verbindungsleiste stand einmal oben, mit der plausiblen Begründung „ist das Netz
    weg, ist das die wichtigste Information" — sie erscheint aber bei **jedem** Abhaken für
    Sekundenbruchteile und schob dabei die Trainingsleiste hin und her. **Wer eine weitere
    Leiste ergänzt, sortiert sie danach ein: dauerhaft nach oben, flüchtig nach unten.**

    `zurAktivenSpringen()` misst **den Stapel** per `offsetHeight`, nicht seine Kinder — die Höhe des Behälters
    stimmt von selbst, auch wenn keine, eine oder beide Leisten sichtbar sind. **Wer eine
    weitere Leiste ergänzt, muss an der Scroll-Rechnung nichts ändern.**

    **(e) `.saetze-kopf` allein reicht als Selektor nicht.** `.summary-knopf` steht weiter unten
    in derselben Datei und hat dieselbe Spezifität, also gewinnt die spätere Regel. Deshalb
    `.saetze-block > .saetze-kopf`. Wer einen `.summary-knopf` umfärben will, braucht
    denselben Griff — sonst passiert schlicht nichts, ohne Fehlermeldung.

20. **Der verzögerte Satz-Speicher muss vor Beenden und Tauschen ausgelöst werden**
    (`satzSpeichernJetzt()` in `index.js`). Änderungen gehen erst 800 ms nach der letzten
    Eingabe raus; in diesem Fenster steht der Eintrag **noch nicht in der Warteschlange**.
    `einheitBeenden()` und `tauschOeffnen()` prüfen aber genau die Warteschlange
    (`schlange.anzahl()` bzw. `schlange.eintrag(peId)`) — ohne den vorgezogenen Aufruf sähen
    sie über einen wartenden Satz hinweg.

    Aus demselben Grund zeichnet `saetzeSetzen()` die Zeilen **nur** neu, wenn sich ihre
    Anzahl geändert hat: Beim Tippen und beim Stepper stehen die Felder schon richtig; sie
    über `innerHTML` zu ersetzen risse den Fokus mitten aus der Eingabe.

    **Die Satzzeile hat genau einen Erzeuger:** `satzZeileMarkup()` in `index.js`.
    `index.php` rendert sie *nicht*, sondern liefert die Werte als JSON in `data-saetze`.
    Das ist die begründete Ausnahme von „serverseitig gerendertes HTML": Die Zeile ist ein
    Bedienelement, das sich im Betrieb ständig ändert, JS muss sie ohnehin bauen können.

    Für die **Formatierung** einer Satzfolge gibt es dagegen zwei Paare, jeweils PHP + JS,
    beide Hälften zusammen zu ändern:

    | Funktion | Ergebnis | Wofür |
    |---|---|---|
    | `saetze_text()` / `saetzeText()` | `12×40 · 10×40 · 9×45` | die bloße Liste — Spalte „Sätze" im Verlauf |
    | `saetze_zusammenfassung()` / `saetzeZusammenfassung()` | `3 Sätze (12×40 · 10×40 · 9×45)` | eine Satzfolge für sich |

    **Die Zusammenfassung hat genau eine Schreibweise**, weil „zuletzt …" (server-gerendert)
    und der Kopf des Satzblocks (im Browser gebaut) am Handy direkt übereinander stehen.
    Zwei Schreibweisen liest man dort als Unterschied in der Sache.

21. **Die Plan-Rotation liest ihren Stand aus der HISTORIE, sie merkt ihn sich nicht**
    (`zuletzt_trainierter_plan()`, §7.6) — seit `1.2.0` **je Split getrennt**. Die
    allgemeine Form: **Was sich aus der Historie ableiten lässt, gehört nicht zusätzlich in
    eine Spalte.** Zwei Quellen laufen auseinander, sobald ein Löschpfad die eine anfasst
    und die andere nicht — und der Löschpfad wird vergessen, weil er anderswo steht.

    - **Gezählt wird JEDE Einheit, auch eine ohne Protokollzeile** (Entscheidung des
      Benutzers). Die Rotation richtet sich starr nach der Historie; wer eine leere Einheit
      nicht gezählt haben will, löscht sie. Eine zweite, stille Regel sähe man am Verlauf
      nicht.
    - **`users.last_plan_id` ist tot** — weder gelesen noch geschrieben, in `schema.sql` so
      gekennzeichnet. **Nicht wieder in Betrieb nehmen.**

22. **`api/exercises.php → update` ersetzt die ganze Übung, es ändert keine Felder.**
    `aktion_bearbeiten()` schreibt `name_de`, `name_en`, `description`, `focus`,
    `equipment` **und** über `gruppen_schreiben()` die komplette n:m-Zuordnung neu. Wer nur
    die zwei Felder schickt, die er ändern will, verliert Name, Gerät und **sämtliche
    Muskelgruppen** — **und die Antwort lautet trotzdem `ok:true`.** Die Übung steht danach
    ohne Zuordnung da und taucht im Tausch nie wieder auf.

    `eingabe_pruefen()` verlangt nur `name_de`, `equipment` und `primary_group_id` als
    Pflicht — alles andere fällt still auf `null` oder die leere Liste. Jeder Aufruf muss die
    unveränderten Felder aus einem **vorher gezogenen Abzug** wörtlich mittragen; jedes neue Feld an `exercises` erbt dieses Verhalten (so kam `image_crop`
    dazu). **Das Muster ist nicht einheitlich, Analogieschluss hilft nicht:**
    `api/plans.php → rename_plan` und `api/users.php → set_admin` fassen genau eine Spalte
    an. **Vor dem ersten Schreibzugriff auf einen unbekannten Endpunkt gehört das
    `UPDATE`-Statement gelesen.**

    **Das Bild bleibt von selbst stehen**, solange nichts mitkommt:
    `$bildSpalte = $neuesBild ?? ($entfernen ? null : $altesBild)`. Bei einer **JSON**-
    Nutzlast ist `$_FILES` zwangsläufig leer, das Bild also sicher; wer `multipart` schickt,
    muss aufpassen. `read_input()` nimmt `$_POST`, wenn es nicht leer ist, sonst den
    JSON-Body — beide Wege stehen offen, obwohl das Formular `multipart` benutzt.

23. **Eine offene Seite überlebt ihre Sitzung nicht — und merkt es zu spät.** Stirbt die
    PHP-Sitzung, während die Seite im Browser steht, meldet „Angemeldet bleiben" den
    Benutzer beim nächsten Aufruf stillschweigend wieder an. Die frische Sitzung hat aber
    **kein** CSRF-Token, und ab da scheitert **jeder** Schreibaufruf mit 403.

    Das Tückische ist die Verkleidung: Ohne Remember-Me käme ein 401 und `apiFetch` schickte
    den Benutzer sichtbar zur Anmeldung. Das Remember-Me rettet die Anmeldung und macht den
    Fehler unerklärlich — man ist angemeldet, sieht seine Daten, und nichts lässt sich
    speichern. In der Trainingsansicht sieht das aus wie ein toter Knopf.

    Die Gegenmaßnahmen stehen auf zwei Ebenen, **beide werden gebraucht**:

    - **`app.ini` im `Dockerfile`:** `gc_maxlifetime = 28800`, `lazy_write = Off`,
      `use_strict_mode = 1`. Das Basis-Image bringt **keine** `php.ini` mit; ohne diese
      Zeilen räumt PHP eine Sitzung nach 24 Minuten Ruhe weg — im Studio liegt zwischen
      zwei „Erledigt" leicht mehr. **`lazy_write = Off` gehört dazu:** Sonst schreibt PHP
      die Sitzungsdatei bei unveränderten Daten nicht neu, sondern setzt nur per `utime()`
      den Zeitstempel — und **auf einer gerade gelöschten Datei scheitert das lautlos**. **Wer die Zeilen beim Umbauen verliert, holt sich den
      Fehler zurück, und zwar erst im Studio.**
    - **Selbstheilung in `apiFetch`** über `CSRF_FEHLER_CODE` und `api/token.php`. Die
      ini-Zeile verschiebt die Klippe, sie räumt sie nicht weg: Ein Rollout mitten im
      Training (Sitzungen liegen in `/tmp` **im Container**, ohne Volume), ein langer Halt,
      ein vom Handy verworfenes Cookie — jedes davon macht die Seite sonst unbedienbar.

    **`api/token.php` ist deshalb GET ohne `csrf_check()`** — sonst wiese er die Reparatur
    genau dort ab, wo sie gebraucht wird. Keine Lücke, weil das Sitzungscookie `SameSite=Lax`
    trägt und bei einem fremden `fetch` gar nicht mitgeht. **Die allgemeine Form: Wer einen
    Zustand im Browser hält, der serverseitig ablaufen kann, braucht einen Weg zurück, der
    nicht „neu laden" heißt** — und der darf nicht durch die kaputte Ebene laufen.

24. **Ein Split ist entweder Vorlage oder persönlich — und Vorlagen werden KOPIERT, nicht
    benutzt** (§4, §6.4). `splits.user_id IS NULL` heißt Vorlage: für alle sichtbar, nur vom
    Admin bearbeitbar, **von niemandem trainierbar**. Alles andere gehört genau einem
    Benutzer, und nur darauf läuft ein Training.

    **Warum das keine Frage der Oberfläche ist:** Der dauerhafte Tausch schreibt in
    `plan_exercises`. Träfe er eine Vorlage, änderte ein einzelner Benutzer den Bestand
    aller — genau der Fall, der den Umbau ausgelöst hat. Durchgesetzt an **drei** Stellen,
    alle drei nötig: `plan_gehoert()` in `api/session.php` (kein Start), der `JOIN splits`
    in `position_laden()` von `api/log.php` **und** `api/swap.php` (kein Protokoll, kein
    Tausch).

    **Auch ein Admin trainiert nicht auf einer Vorlage.** `api/splits.php → activate` prüft
    deshalb ausdrücklich **nicht** über `split_zugriff_api()`: Bearbeiten darf er sie,
    auswählen nicht.

    **Es gibt keinen Rückkanal zwischen Vorlage und Kopie** — keine Vererbung, kein
    Abgleich. Wer den neuen Stand will, kopiert erneut; `split_name_frei()` hängt ` (2)` an.
    Wer hier je einen Verweis einbaut, nimmt der Kopie ihren einzigen Zweck.

    **`split_kopieren()` bedient vier Knöpfe** (Vorlage→Benutzer, Benutzer→Vorlage,
    Benutzer→derselbe, Vorlage→Vorlage), läuft in **einer Transaktion** und übernimmt
    `sort_order` **wörtlich** — bei den Plänen ist sie die Rotationsreihenfolge, bei den
    Positionen die Reihenfolge im Studio. Eine Kopie, die anders herum läuft, ist keine.

    **Was inhaltlich schon im Katalog steht, bietet die Oberfläche nicht zum Veröffentlichen
    an** (`benutzer_splits_ohne_vorlage()`). `split_signaturen()` bildet dafür einen
    Fingerabdruck aus der Reihenfolge der Pläne und darin der Übungen — **ohne jeden Name**.
    Wer eine Vorlage kopiert und umbenennt, hat dasselbe Training mit eigener Beschriftung;
    eine zweite Vorlage davon wäre eine Dublette. Ein ausdrückliches „Duplizieren" ist
    dagegen nie ein Versehen und deshalb erlaubt.

25. **`api/plans.php` hat kein `require_admin_api()` mehr, und das ist die folgenreichste
    Zeile der Datei.** Weil der Endpunkt admin-only war, prüften seine Ladefunktionen
    **überhaupt keinen** Besitzer — sie mussten nicht. Seit `1.2.0` bearbeitet jeder seine
    eigenen Splits, also muss es jede der elf Aktionen tun.

    Deshalb **genau eine** Stelle: `plan_zugriff()` bzw. `position_zugriff()` laden und
    prüfen in einem Zug, beide über `split_zugriff_api()` in `lib/splits.php`. Elf einzeln
    geschriebene Prüfungen wären zehn Gelegenheiten, eine zu vergessen — und eine vergessene
    öffnet fremde Pläne zum **Schreiben**. Die Regel darin: *Der Eigentümer darf, ein Admin
    darf alles, eine Vorlage darf nur ein Admin anfassen.*

    **`struktur_sperre_pruefen()` nimmt `?int`**, und `null` (= Vorlage) heißt „keine
    Sperre" — kein Schlupfloch: Auf einer Vorlage trainiert niemand, ihr `n` kann sich in
    keiner laufenden Anzeige verschieben.

26. **`plans.user_id` ist tot — aber sie bleibt stehen, weil die MIGRATION sie braucht.**
    Wem ein Plan gehört, sagt allein `splits.user_id`. Wird eine Sicherung von vor `1.2.0`
    eingespielt, stehen dort wieder Pläne ohne `split_id`, und nur `user_id` sagt dann noch,
    wem sie gehören. Ohne sie wären solche Pläne unrettbar unsichtbar.

    **Genau deshalb steht die Datenmigration in `apply_migrations()`** (`splits_nachziehen()`
    in `lib/db.php`) und nicht in einem Einmalskript: `backup_wiederherstellen()` ruft nach
    dem Einspielen `db()` auf und damit `init_schema()`. Eine Altsicherung wird auf diesem
    Weg mitgezogen und ist unmittelbar danach benutzbar — nachgemessen, nicht gefolgert. Ein
    Einmalskript ließe dort Pläne zurück, die in keiner Oberfläche auftauchen, **ohne jede
    Meldung**.

    Die Gegenprobe nach jeder Änderung an diesem Bereich — erwartet wird **genau eine**
    Fundstelle, das `UPDATE` in `splits_nachziehen()`:

    ```bash
    grep -rnE "(^|[^s])p\.user_id|plans[^;]*WHERE[^;]*user_id" --include=*.php .
    ```

    **`users.active_split_id` ist der Gegenfall und deshalb erlaubt:** Sie hält eine
    **Auswahl** fest und keine ableitbare Tatsache — anders als `last_plan_id`
    (Fallstrick 21). Die Rotation *innerhalb* des Splits wird weiterhin aus der Historie
    gelesen. Damit sie nicht veraltet, setzt `api/session.php → start` sie mit, und
    `aktiver_split()` hat einen Rückfallweg, den es gleich festschreibt.

    **`$noetig` in `db_datei_pruefen()` bleibt unverändert** — `splits` gehört dort **nicht**
    hinein, sonst würde ausgerechnet die Sicherung von vor dem Umbau abgelehnt.

27. **Der Übungsname wird NICHT von Hand ausgeschrieben** (seit `1.2.5`, §4). Er steht
    zweisprachig, und zwar an **allen** Stellen — bis `1.2.4` waren es drei von sieben, und
    ausgerechnet das Tauschfenster, wo man eine unbekannte Übung sucht, gehörte nicht dazu.

    Es gibt zwei Formen, jede mit genau einer Quelle — dasselbe Verhältnis wie zwischen
    `saetze_text()` und `saetze_zusammenfassung()` (Fallstrick 20):

    | Funktion | Ergebnis | Wofür |
    |---|---|---|
    | `uebung_name()` / `uebungName()` | `<strong>DE</strong>` + `.name-en` darunter | der Regelfall: Karten, Listen, Tabellenzellen |
    | `uebung_name_kurz()` | `Bankdrücken · Bench Press`, fertig escapt | wo der Name in eine **laufende** Zeile muss: Abzeichen „statt …", Kopf einer Verlaufskarte |

    Vier Dinge, die daran hängen:

    - **Der Umbruch kommt aus `.name-en` am Namen selbst**, nicht aus dem Elternteil. Bis
      `1.2.4` hing er an `.uebung-text > .matt` — im Tauschfenster (`.vorschlag-text`) und
      im Verlauf gibt es diesen Behälter nicht, dort stünden beide Namen wieder in einer
      Zeile und verschmölzen zu einem langen Namen.
    - **Im `<strong>` steht ausschließlich der deutsche Name.** `plans.js` und `index.js`
      lesen ihn über `.position-titel` bzw. `.uebung-text strong` per `textContent` aus
      (Dialogtitel, Rückfrage vor dem Entfernen). Wer beide Namen ins `<strong>` legt,
      bekommt „Ersatz für Bankdrücken Bench Press" — ohne Fehlermeldung. Dafür nimmt
      `uebung_name()` einen Klassenparameter, statt die Aufrufstelle eigenes Markup
      schreiben zu lassen.
    - **`uebung_name_kurz()` liefert fertig escapten Text.** Ein `h()` darüber schriebe
      `&amp;` in die Anzeige.
    - **Eine neue Anzeigestelle braucht `name_en` in der Abfrage.** Drei liefern es seit
      jeher, drei mussten in `1.2.5` nachgezogen werden (`plan_positionen()`,
      `einheit_eintraege()`, `uebungen_mit_verlauf()` in `lib/training.php`) — bei
      `uebungen_mit_verlauf()` samt `GROUP BY`. Fehlt die Spalte, bleibt der englische Name
      **lautlos** weg; es sieht aus, als hätte die Übung keinen.

    **Nicht zweisprachig sind Sätze über Übungen** — Dialogtitel („Ersatz für …"),
    Rückfragen und Fehlermeldungen aus `api/*`. Dort ist der Name Teil eines Satzes, und
    ein zweiter Name mitten darin liest sich als zweite Übung.

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

**Das gilt auch nach einer Fehlerkorrektur**, und genau dort ist es am 2026-08-17
schiefgegangen: Nach einer gemeldeten Fehlfunktion schien der Bau „nur konsequent". Er war
es nicht — der Benutzer wollte im selben Zug noch etwas ändern. **Kein Paket ohne ein
„bau", „build" oder „packen" von ihm.**

Daraus die Zählweise:

- **Solange kein Paket gebaut ist, bleibt die Nummer stehen.** Fünf Änderungswünsche
  nacheinander ergeben *eine* Version, nicht fünf.
- **Sobald `paket_bauen.sh` unter einer Nummer gelaufen ist, ist sie vergeben.** Die
  nächste Änderung an etwas, das **im Paket steckt**, hebt sofort auf die nächste Nummer —
  sonst weicht der Arbeitsstand von einem Paket ab, das denselben Namen trägt. Genau so
  ging am 2026-08-10 verloren, welche von zwei Fassungen als `1.0.16` ausgerollt worden war.
- **Ein auf Ansage gebautes Paket geht sofort live.** Der Benutzer spielt jede Version,
  die er bauen lässt, unmittelbar danach in Portainer ein (festgelegt am 2026-08-19). Es
  gibt deshalb **keine Rückfrage** „ist die Nummer schon draußen?" und keine Lücken in der
  Zählung: gebaut heißt ausgerollt.
- **Umgekehrt: Ein Paket, das den Rechner nie verlassen hat, gibt seine Nummer wieder
  frei.** Das betrifft nur den Fall, dass **ungefragt** gebaut wurde — dann wird das Paket
  gelöscht und der Arbeitsstand behält die Nummer. **Nummern werden nicht übersprungen,
  wenn dazwischen nichts ausgeliefert wurde**: Eine Lücke suggeriert eine Fassung, die es
  nie gab.
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
- **Die `session.*`-Zeilen der `app.ini` sind Fachlichkeit, keine Kosmetik.** Das Basis-Image
  bringt **keine** `php.ini` mit — ohne diese Zeilen gelten die eingebauten Vorgaben, und die
  räumen eine Sitzung nach 24 Minuten Ruhe weg. Was daraus folgt und warum `lazy_write = Off`
  dazugehört, steht in Fallstrick 23. **Wer die Zeile beim Umbauen des `Dockerfile` verliert,
  holt sich den Fehler vom 2026-08-16 zurück**, und zwar erst im Studio.
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
