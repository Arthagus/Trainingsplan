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
| `LASTENHEFT.md` | Fachlichkeit, Datenmodell, die 18 Abnahmekriterien |
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
        db()->prepare("UPDATE users SET must_change_password = 0")->execute();'
```

Das `UPDATE` ist kein Schönheitsfehler, sondern nötig: Ohne es sperrt
`require_passwort_gesetzt_api()` **jeden** Endpunkt außer `api/auth.php` (Fallstrick 3),
und der erste `curl`-Test läuft in ein 403, das wie ein Fehler aussieht.

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
Antwort und Datenbankzustand vergleichen. Die Abnahme läuft über die 18 manuellen Kriterien
in `LASTENHEFT.md` §11.

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
| `lib/training.php` | Die Fachlichkeit aus §7: Rotation, Positionen, Tausch, Verlauf |
| `lib/backup.php` | Sichern über `VACUUM INTO`, Prüfen, Wiederherstellen |
| `lib/upload.php` | Bildannahme mit MIME-Prüfung und GD-Re-Enkodierung |
| `lib/view_header.php` / `view_footer.php` | Layout als Partial |

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
- **`[hidden]` braucht eine eigene Regel** ganz oben im Stylesheet:
  `[hidden] { display: none !important; }`. Browser blenden `[hidden]` nur über ihr
  *Standard*-Stylesheet aus — jede Autorenregel schlägt das, unabhängig von der Spezifität.
  Wegen `button { display: inline-flex }` stand der Wiederholen-Knopf sonst dauerhaft
  sichtbar in jeder Übungszeile.
- **Mobile-first.** Die Handy-Ansicht ist der Hauptfall, nicht der Sonderfall.
- **Fehler nie stillschweigend verschlucken:** Schlägt ein Speichern fehl, bleibt das Häkchen
  sichtbar unbestätigt und ein Wiederholen-Knopf erscheint.

## Fachliche Fallstricke

Die Stellen, an denen eine naive Umsetzung falsch wird. Jede steht hier, weil sie schon
einmal zugeschlagen hat.

1. **Eine Einheit startet auch durch einen Tausch**, nicht nur durch das erste „Erledigt"
   (§7.6). `exercise_swaps` braucht eine `session_id`, und im Studio wird oft getauscht,
   bevor die erste Übung gemacht ist. Seit `1.0.3` gibt es zusätzlich den ausdrücklichen
   Knopf „Training starten" — siehe Fallstrick 11.

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

11. **Eine Einheit kann ausdrücklich gestartet werden** (§7.6, `api/session.php` → `start`).
    Das ist der Regelfall; Abhaken und Tausch bleiben als Auslöser bestehen. Ohne den Knopf
    hielt `started_at` das *Ende* der ersten Übung fest — für jede Auswertung der
    Trainingsdauer systematisch zu kurz.

12. **Der Service-Worker-Cache friert Assets ein, wenn `sw.js` unverändert bleibt.** Ein
    Service Worker wird **nur neu installiert, wenn sich seine eigene Datei ändert**. Bleibt
    sie gleich, läuft `install()` nie wieder, `cache.addAll()` ebenso wenig — und
    `caches.match()` liefert bis in alle Ewigkeit die Fassung vom ersten Besuch. `style.css`
    und `app.js` waren dadurch über mehrere Versionen in jedem Browser eingefroren; keine
    einzige Stiländerung kam an, obwohl Server und HTML korrekt waren.

    **Beim Ändern von `assets/style.css` oder `assets/app.js`: `CACHE` in `assets/sw.js`
    hochzählen.** Vergisst man es, greift `stale-while-revalidate` als Netz — die Änderung
    ist dann einen Seitenaufruf später da statt sofort.

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

## Deployment

Docker-Container (`php:8.3-apache`) im LXC `10.10.10.2` auf einem Hetzner-Rootserver mit
Proxmox, davor der Host-Nginx mit Let's-Encrypt für `training.jadefalke.net`.
Ablauf in `deploy/ANLEITUNG.md`, Topologie in `doku/deployment.md`.

```bash
bash deploy/paket_bauen.sh    # Positivliste packen, lintet vorher
```

- **Verwaltung über Portainer**, wie die übrigen Container auf diesem LXC (u. a.
  `solarwatch`, `/home/rezeption/Projekte/Solarwatch` — dort steht die Vorlage für
  `deploy/stack.yml`, `deploy/env-vorlage.txt` und `deploy/paket_bauen.sh`). Portainer sieht
  den Quelltext nicht: erst Image aus einem hochgeladenen Tarball bauen, dann Stack **ohne**
  `build:` und mit fester Versionsnummer statt `:latest`.
- **Immer eine neue Versionsnummer**, nie einen Tag erneut bauen — sonst tragen zwei
  verschiedene Stände denselben Namen.
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
- **`COPY . /var/www/html` legt auch `Dockerfile`, `apache-app.conf` und `schema.sql` ins
  Webroot.** Ein `<FilesMatch>` in `apache-app.conf` sperrt sie für HTTP; PHP liest
  `schema.sql` weiterhin über das Dateisystem. Nicht entfernen — `schema.sql` verrät sonst
  die komplette Datenstruktur.
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
- **Version anheben heißt: an zwei Stellen.** `deploy/stack.yml` (`image:`) und
  `deploy/ANLEITUNG.md` Schritt 2, wo der Name wörtlich zum Eintippen steht. Stimmen sie
  nicht überein, findet der Stack sein Image nicht.

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
