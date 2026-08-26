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

**Auch ein Ereignis-Handler lässt sich ausführen**, etwa der aus `assets/sw.js`: Die
Registrierungszeile beim Herausschneiden in einen Funktionskopf umschreiben, dann mit
einem gefälschten `event` aufrufen und mitschreiben, ob `respondWith()` kam.

```js
const handler = hol(src, /self\.addEventListener\('fetch', \(event\) => \{[\s\S]*\n\}\);/)
    .replace("self.addEventListener('fetch', (event) => {", 'function behandeln(event) {')
    .replace(/\}\);\s*$/, '}');
```

**Und dabei die Falle, die dieselbe Sorte ist wie die leere Satzliste oben:** Der Handler
steigt ganz oben bei fremder Herkunft aus. Ein `self`-Ersatz mit bloß `location.href`
lässt `location.origin` `undefined` — dann greift **keine** Regel mehr, und die Prüfung
meldet für jede Adresse „geht ans Netz". Das sieht nach einem Befund aus und ist ein Loch
im Prüfstand. Am 2026-08-23 genau so passiert, erkennbar daran, dass **alle** positiven
Fälle auf einmal durchfielen und alle negativen bestanden.

**Der Gegenbeweis gehört dazu**, und zwar in der schärferen Form: Nicht nur gegen den
letzten Commit laufen lassen (`git show HEAD:assets/app.js > /tmp/alt.js`), sondern
**gezielt die eine Zeile entfernen**, um die es geht, und nachsehen, ob genau die
erwarteten Fälle durchfallen — und nur die. Beim Tastatur-Anker war das die Klammer nach
oben, beim Service Worker der neue Zweig im Filter. Fällt dabei mehr durch als gedacht,
prüft der Test etwas anderes als angenommen.

**Und die dritte Falle derselben Sorte, die allgemeinste von allen: Eine Attrappe muss die
Frage BEANTWORTEN, nicht die erwartete Antwort liefern.** Wer eine DOM-Attrappe baut, deren
`qs`/`qsa` nur die Selektoren kennt, die der Code heute benutzt, und für alles andere `null`
oder `[]` zurückgibt, hat einen Test gebaut, der sich selbst bestätigt: Die Gegenprobe
ändert den Selektor, die Attrappe antwortet „nichts gefunden", der Fehler fällt nicht auf.
Am 2026-08-25 genau so passiert — eine nachweislich falsch zählende Fassung kam durch, weil
die Attrappe `.zeile-offen` nicht kannte. Der Selektor gehört **geparst**:

```js
const qsa = (sel) => {
    const treffer = sel.match(/^\.position-karte\.zeile-([a-z]+)$/);
    return treffer ? karten.filter((k) => k.zustand === treffer[1]) : [];
};
```

**Das Erkennungszeichen ist bei allen dreien dasselbe und steht auf dem Kopf: Die
Gegenprobe fällt ZU FREUNDLICH aus.** Bei der leeren Satzliste und beim Service Worker
fiel auf einen Schlag zu viel durch, hier zu wenig. Wer eine Gegenprobe sieht, deren
Ergebnis „zu glatt" ist — alles grün, obwohl die Zeile entfernt wurde —, prüft zuerst den
eigenen Aufbau.

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
`ended_at` setzen.

**Protokollzeilen von Hand anzulegen ist der zweite Schritt, und dort steht die Spaltenliste,
die man sonst im Schema nachschlägt.** `workout_log` trägt die Einheit, den Plan UND die
Position — die drei zusammen, weil `plan_exercise_id` der eigentliche Schlüssel ist
(Fallstrick 4) und `plan_id` die Zählung „n" trägt:

```php
$ins = $p->prepare("INSERT INTO workout_log
    (session_id,plan_exercise_id,user_id,exercise_id,plan_id,weight,done,performed_at)
    VALUES (?,?,1,?,?,?,?,?)");
$ins->execute([$sid, $peId, $uebungId, $plan, 40, 1, $n]);   // fertig
$p->prepare("INSERT INTO workout_sets (workout_log_id,satz_nr,reps,weight)
             VALUES (?,1,12,40)")->execute([(int)$p->lastInsertId()]);
```

**Für alles, was Zustände ANZEIGT — Leiste, Balkenfarben, „x/n" —, braucht der Bestand
gemischte Positionen, sonst sieht jede Zählung richtig aus.** Drei Sorten, und keine davon
ist entbehrlich:

| Bestand der Position | Ergibt |
|---|---|
| `done = 1` | beendet, blauer Balken |
| Zeile mit `done = 0` (plus Satz) | angefangen, grüner Balken |
| gar keine Zeile, aber eine spätere Position hat eine | **übersprungen**, oranger Balken |

Die dritte entsteht nur durch die *Lücke*: Positionen 1 und 3 protokollieren, 2 auslassen.
Wer der Reihe nach abhakt, bekommt nie einen orangen Balken zu sehen — und prüft damit
genau die Regel nicht, die schwierig ist (§7.3, `positions_zustaende()`).

**Ein Benutzer und ein Split beweisen die halbe Fachlichkeit nicht.** Zwei Fragen
beantwortet dieser Bestand systematisch falsch, weil es nichts zu verwechseln gibt:

- **Wählt die Seite den RICHTIGEN Split?** Bei einem einzigen ist jede Auswahl richtig.
  Für `plans.php` und `index.php` braucht es **drei** Splits, und der aktive darf
  **nicht der erste** sein — sonst sieht ein Rückfall auf `[0]` wie ein Treffer aus.
- **Greift der IDOR-Schutz?** Ohne zweiten Benutzer gibt es keinen fremden Bestand, an
  dem er sich zeigen könnte. Der Zweite gehört **ohne** Adminrecht angelegt: Ein Admin
  darf vieles absichtlich, und dann prüft man die Ausnahme statt der Regel.

```php
$p->prepare("INSERT INTO users (name,password_hash,is_admin,must_change_password,created_at)
             VALUES (?,?,0,0,?)")->execute(["nele", password_hash("geheim12345", PASSWORD_DEFAULT), $n]);
```

Danach `active_split_id` **ausdrücklich** auf einen Split setzen, der nicht der erste
ist — sonst sucht sich `aktiver_split()` selbst einen, und die Prüfung misst den Zufall.

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
| `lib/splits.php` | Workout-Splits (§6.4): Katalog, Kopieren, aktiver Split, Textausgabe `split_texte()`, **die zentrale Rechteprüfung** `split_zugriff_api()` — dazu der Vorlagenabgleich aus `1.2.11` (`vorlage_stand()`, `split_zuruecksetzen()`) und **zwei** Fingerabdrücke mit verschiedenem Zweck, siehe Fallstrick 24 |
| `lib/geraete.php` | Codelisten `GERAETE` und `ZUSCHNITT`, `geraet_abzeichen()` |
| `lib/backup.php` | Sichern über `VACUUM INTO`, Prüfen, Wiederherstellen |
| `lib/upload.php` | Bildannahme mit MIME-Prüfung und GD-Re-Enkodierung — dazu `verwaiste_bilder()`/`verwaiste_bilder_loeschen()`, die einzige Stelle, die Dateien ohne Übung findet |
| `lib/healthcheck.php` | Was der HEALTHCHECK im `Dockerfile` startet — fasst die Datenbank an und **begründet** ein „unhealthy" |
| `lib/view_header.php` / `view_footer.php` | Layout als Partial, inklusive Leisten-Stapel `#leisten` |
| `lib/view_geraet_symbole.php` | SVG-Symbolvorrat + Beschriftungen, aus dem Header eingebunden |
| `lib/view_bild_dialog.php` / `view_platzhalter.php` | Geteilte Bausteine für Übungsbilder — von `index.php` und dem Adminbereich gemeinsam benutzt |

**Die JSON-Endpunkte tragen ihren Gegenstand im Namen**, mit zwei Ausnahmen, die man
kennen muss: `api/token.php` liefert ein frisches CSRF-Token (Fallstrick 23) und ist der
**einzige** Endpunkt ohne `csrf_check()`; `api/maintenance.php` bedient die Wartungsseite
(Sicherungen anlegen, prüfen, einspielen — die Fachlichkeit steckt in `lib/backup.php`).

**Welcher Endpunkt was kann** — die Karte spart das Suchen, ersetzt aber nicht den Blick
ins `UPDATE`-Statement, bevor man das erste Mal darauf schreibt (Fallstrick 22):

| Endpunkt | Aktionen |
|---|---|
| `auth.php` | `login`, `change_password`, `change_name`, `set_expert_mode`, `set_satz_vorlage`, `revoke_device`, `revoke_all` |
| `exercises.php` | `create`, `update`, `archive`, `unarchive`, `delete` |
| `log.php` | `check`, `uncheck` |
| `maintenance.php` | `backup`, `restore`, `upload`, `delete_backup`, `vacuum`, `integrity`, `optimize`, `checkpoint`, `images_orphans`, `images_cleanup` |
| `muscle_groups.php` | `create`, `update`, `delete`, `reorder` |
| `plans.php` | `create_plan`, `rename_plan`, `delete_plan`, `reorder_plans`, `exercise_picker`, `add_exercise`, `remove_exercise`, `move_exercise`, `reorder_exercises`, `swap_suggestions`, `swap_exercise` |
| `session.php` | `start`, `end`, `delete` |
| `splits.php` | `create`, `rename`, `delete`, `reorder`, `copy`, `publish`, `activate`, `set_vorlage`, `reset` |
| `swap.php` | `suggestions`, `apply` |
| `users.php` | `create`, `rename`, `reset_password`, `set_admin`, `set_blocked`, `delete` |

**Die Parameternamen sind NICHT einheitlich**, und das kostet beim ersten Aufruf Zeit:
`plans.php → rename_plan` nimmt `id`, `move_exercise` dagegen `plan_exercise_id` **und**
`direction` (`up`/`down`), nicht `plan_id`. Im Zweifel die ersten Zeilen der
`aktion_*`-Funktion lesen — sie prüfen die Eingabe und stehen damit gleich am Anfang.

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

  **Die erste Wiederholpause ist kurz, und das ist Fachlichkeit, keine Zahlenkosmetik**
  (`API_PAUSEN_MS = [400, 2000, 2000]`, seit `1.2.11`). Bis dahin stand dort `[2000, 5000]`:
  Scheiterte der erste Versuch *sofort* — ein Aussetzer im WLAN, eine abgelaufene
  Verbindung —, wartete der Code stur zwei Sekunden. Bei „Training starten" sah das aus wie
  ein toter Knopf, und genau so wurde es am 2026-08-23 gemeldet. Ein Aussetzer, der beim
  zweiten Versuch weg ist, ist nach 400 ms genauso weg. Dafür ein Umlauf mehr: vier Versuche
  statt drei, und der schlimmste Fall sinkt trotzdem von 7 s auf 4,4 s.

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
- **Das aufgeklappte `<select>` am Handy ist mit CSS NICHT gestaltbar** — nachgemessen am
  2026-08-23, nicht gefolgert. Chrome auf Android zeigt es als Dialog mit Auswahlknöpfen,
  und dessen Schrift kommt aus den **Android-Systemeinstellungen**; sie ist deutlich größer
  als der Seitentext und ändert sich weder über `option { font-size }` (in `1.2.11`
  ausgeliefert, wirkungslos) noch über die Größe des `<select>`. iOS zeigt ohnehin ein
  Systemrad.

  **Daraus folgt: Zu lange Einträge löst man über den Inhalt, nicht über das Stylesheet.**
  Im Dialog passen rund 32 Zeichen in eine Zeile; was darüber liegt, bricht um, und daran
  ist nichts zu machen. Wer eine Auswahl baut, deren Einträge lang werden können, muss den
  Umbruch hinnehmen — oder dafür sorgen, dass die Texte kurz bleiben.

  **`font-size: 16px` am `<select>` bleibt trotzdem stehen**, aus dem Grund direkt darüber
  im Stylesheet: Darunter zoomt iOS beim Antippen die Seite. Die 16 px sind für das
  geschlossene Feld da und nicht für die Liste — die sieht sie nie.
- **Service Worker:** cacht **nur** `assets/*.css`, `assets/*.js`, `manifest.json`, Icons
  und die **Seiten-Skripte im Wurzelverzeichnis** (`istSeitenSkript()` in `sw.js`, seit
  `1.2.11`) — mit **`stale-while-revalidate`**. **Niemals HTML oder API-Antworten**
  (`network-only`), sonst wird eingeloggter Zustand nach dem Logout ausgeliefert und
  veraltete CSRF-Tokens erzeugen 403er. Zur Cache-Falle siehe Fallstrick 12.

  **Die Seiten-Skripte sind der Nachzügler, nicht die Ausnahme:** `index.js` ist so groß wie
  `app.js` und trägt dieselbe `?v=`-Nummer, lag aber im Wurzelverzeichnis — und ging damit
  bei jedem Seitenaufruf ans Netz, eine volle Runde, bevor die Seite bedienbar war. Erfasst
  wird **nur**, was direkt im Wurzelverzeichnis liegt und auf `.js` endet; die Wurzel wird
  aus `self.location` **gerechnet** und nicht als `/` geraten. **Nicht vorab geladen** — der
  erste Aufruf nach einem Rollout geht ans Netz, jeder weitere kommt aus dem Cache; ein
  Präcache aller sieben Skripte wäre Verkehr auf genau der Verbindung, die das entlasten
  soll.
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
- **Was das Stylesheet nicht angibt, gibt der Browser vor — und zwar nach SEINEM
  Geschmack.** Dieselbe Mechanik wie bei `[hidden]` direkt darüber, nur umgekehrt herum:
  Dort schlägt eine eigene Regel versehentlich das Standard-Stylesheet, hier fehlte
  überhaupt eine. Links hatten bis `1.2.17` keine Farbangabe, also galt die des Browsers —
  blau, nach dem ersten Klick **lila**, was in dieser Oberfläche sonst nirgends vorkommt.
  Seither steht ganz oben `a { color: var(--akzent) }`.

  **Ein zweiter Selektor für `:visited` ist dabei NICHT nötig:** In der Kaskade geht die
  Herkunft vor der Spezifität — eine Autorenregel für `a` schlägt das `:visited` des
  Browsers. (`:visited` erlaubt aus Datenschutzgründen ohnehin fast nur Farbangaben.)
- **Eine Abschnittsüberschrift steht ÜBER dem Kasten, nicht darin.** `<h2>` trägt
  `margin-top: 1.5rem` — innerhalb einer `.karte` sieht das aus wie eine Leerzeile über der
  Überschrift, und genau so wurde es am 2026-08-26 gemeldet. Dasselbe gilt für ein `<label>`
  als erstes Element (`margin: 0.75rem 0 0.25rem`): Wo es eine Überschrift ist, gehört es
  nach oben heraus und bleibt als `class="nur-lesbar"` stehen — ein `<h2>` beschriftet kein
  Formularfeld, und ohne `for`/`id` verliert das Feld seinen zugänglichen Namen. Die
  Gliederung ist auf allen Seiten dieselbe: **Überschrift, dann Bestand, dann der Kasten zum
  Anlegen** (`splits.php`, `plans.php`).
- **Ein Knopf, der unter seinem Text stehen soll, bekommt ein eigenes `<p>` — keine
  Flex-Spalte am Elternteil.** Ein `<button>` ist `inline-flex` und fließt hinter den
  letzten Satz; ob er dort noch Platz findet, entscheidet die Länge des Textes, und damit
  steht er mal daneben und mal darunter (2026-08-26 an der Wartungsseite gemeldet). Der
  naheliegende Griff `display: flex; flex-direction: column` am `<dd>` löst das und bricht
  dabei etwas anderes: In einem Flex-Container wird **jedes Element** ein eigenes Element
  der Spalte, auch ein `<code>` mitten im Satz — „wenn die `-wal`-Datei groß ist" stand
  danach auf drei Zeilen. Anonyme Textteile werden zusammengefasst, echte Elemente nicht.
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
Übungsnamen anzeigt, mit **27**, an Leisten und Meldungen mit **19** und **29**.

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

6. **Eine Position mit EINTRAG lässt sich nicht tauschen** (§7.5) — weder für die Einheit
   noch dauerhaft, und zwar **ab dem ersten Satz, nicht erst ab dem Häkchen**. Wer zwei
   Sätze Bankdrücken gemacht hat, kann die Position nicht mehr tauschen; die zwei Sätze
   *waren* Bankdrücken. Der Weg: Werte entfernen — Häkchen weg **bzw. Sätze löschen** —,
   tauschen, neu eintragen. Grund: Ein Protokolleintrag dokumentiert eine *tatsächlich
   ausgeführte* Übung; ihn umzuschreiben schlüge das erreichte Gewicht einer Übung zu, die
   nicht gemacht wurde.

   Gesperrt wird **serverseitig**, der deaktivierte Knopf ist nur die Bequemlichkeit.
   **Der Name der Prüffunktion führt in die Irre:** `position_abgehakt()` in `api/swap.php`
   zählt die `workout_log`-Zeilen der Position und sieht `done` überhaupt nicht an. Im
   einfachen Modus fällt beides zusammen, im Expertenmodus nicht — Einzelheiten in
   Fallstrick 18. Wer sich auf den Namen verlässt, sucht die Sperre am falschen Zustand.

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

   **In `workout_log` gibt es keine Wiederholungen** (§4): Ein Feld je Einheit kann 12/10/9
   über drei Sätze nicht abbilden. Satzgenau **gibt** es sie seit `1.1.0` — in
   `workout_sets.reps`, siehe Fallstrick 17. Eine Spalte an `workout_log` bleibt trotzdem
   falsch: Sie wäre die zweite, gröbere Quelle neben der genauen.

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
    - **Die Seiten-Skripte stehen ausdrücklich NICHT in `ASSETS`** (seit `1.2.11`): Sie
      werden gecacht, wenn sie zum ersten Mal gebraucht werden, nicht auf Vorrat. Ein
      Präcache holte bei jeder Installation alle sieben, auch die für Seiten, die niemand
      öffnet. Der Schutz gegen den eingefrorenen Stand hängt hier ohnehin nicht am
      Precache, sondern am `?v=` in der Adresse — und das tragen sie.

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
      **Und daraus die Falle, die am 2026-08-26 zuschlug: Wer ein Bild anzeigt, muss
      `image_crop` MITLIEFERN.** Fehlt die Spalte in der Abfrage, steht jedes Bild mittig —
      **ohne Fehlermeldung**, es sieht nach einer falsch gepflegten Einstellung aus.
      `api/plans.php → exercise_picker` lieferte sie seit `1.1.7` nicht, während
      beide Tauschfenster (`tausch_vorschlaege()`) sie hatten. Dieselbe Sorte wie die
      fehlende Spalte `name_en` in Fallstrick 27 — und der Prüfgriff ist derselbe: an der
      **Antwort** nachsehen, nicht am Markup.
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

      **Die zweite Zahl der Trainingsleiste („n übersprungen", seit `1.2.15`, hinter dem
      Bruch) steht ausdrücklich NICHT in `fortschritt()`.** Sie hängt nicht am Datenbestand, sondern an
      der **Reihenfolge** der Positionen — offen und vor der aktiven. Das ist dieselbe
      Rechnung, die den orangen Balken setzt (`positions_zustaende()` bzw.
      `aktiveMarkieren()`), und sie gehört genau einmal dorthin: `zahlenSchreiben()`
      (`index.js`) zählt schlicht `.zeile-uebersprungen` in der Liste. Damit nennt die
      Leiste genau die Übungen, die man unten auch orange sieht; eine eigene Rechnung
      daneben liefe auseinander, und oben stünde eine Zahl, die man unten nicht
      wiederfindet.

      **Daraus eine Reihenfolge, die man kennen muss:** `aktiveMarkieren()` setzt die
      Klasse und muss vor `zahlenSchreiben()` gelaufen sein. Alle Aufrufer erfüllen das über
      `zustandSetzen()`, das beides in dieser Reihenfolge tut — wer einen neuen Aufrufer
      ergänzt, muss es ebenfalls.
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

19. **Sieben Regeln zur Trainingsansicht, die zusammengehören** — von der Kartenhöhe über `:hover` bis zum Schleier der erledigten Karte und zur Tastatur am Handy.

    Der längste Eintrag dieser Liste, und der Code adressiert ihn buchstabengenau
    (`Fallstrick 19g`). Deshalb vorab, wo was steht:

    | | Worum es geht |
    |---|---|
    | **(a)** | Flüchtiges darf nichts verschieben |
    | **(b)** | `:hover` nur hinter `@media (hover: hover)` |
    | **(c)** | Die vier Balkenfarben und wer grün ist |
    | **(d)** | Der Leisten-Stapel und die Scroll-Rechnung |
    | **(e)** | `.saetze-kopf` braucht den Kindselektor |
    | **(f)** | Zurücktreten heißt dunkler, nicht blasser |
    | **(g)** | Der Tastatur-Anker in zwei Phasen |

    **(a) Was sich im Sekundentakt ändert, darf nichts verschieben.** Der wartende Zustand trug
    einmal einen Hinweissatz in der Karte — sie wurde beim Speichern höher und danach wieder
    niedriger, und bei jedem Satz sprang die ganze Liste. `.zeile-wartet` ändert deshalb nur
    `border-left-style`, nicht einmal die Farbe. **Eine zweite Anzeige daneben gibt es seit
    `1.2.15` nicht mehr:** Der gestrichelte Rand sagt alles, was über ein laufendes
    Speichern zu sagen ist, und die Leiste am oberen Rand meldet nur noch echte Störungen
    (Fallstrick 29).

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
    bei laufender Einheit klebt dort immer die Trainingsleiste, und die nächste Übungskarte
    landete verdeckt.

    **`#leisten` (`lib/view_header.php`) ist der gemeinsame Behälter** und trägt als
    einziges Element `position: sticky; top: 0`. Darin liegen die Trainingsleiste (nur
    `index.php` bei laufender Einheit) und darunter die Verbindungsleiste (auf jeder Seite,
    meist ausgeblendet). Zwei Elemente mit eigenem `top: 0` legten sich übereinander.

    **Die Reihenfolge im Stapel ist nach BESTÄNDIGKEIT sortiert, nicht nach Wichtigkeit.**
    Die Verbindungsleiste stand einmal oben, mit der plausiblen Begründung „ist das Netz
    weg, ist das die wichtigste Information" — sie erschien damals aber bei **jedem**
    Abhaken für Sekundenbruchteile und schob dabei die Trainingsleiste hin und her. **Wer
    eine weitere Leiste ergänzt, sortiert sie danach ein: dauerhaft nach oben, flüchtig
    nach unten.**

    **Die bessere Lösung war am Ende, das Flüchtige ganz wegzulassen** (`1.2.15`, siehe
    Fallstrick 29). Der Weg dorthin ging über zwei Zwischenstufen, und beide sind lehrreich
    genug, um hier stehen zu bleiben:

    **Die Sortierung allein genügte nicht — eine flüchtige Leiste darf im Stapel gar keinen
    Platz belegen.** Sie hielt zwar die Trainingsleiste ruhig, aber nicht die Seite
    darunter: Der ganze Inhalt wanderte bei jedem Abhaken eine Zeilenhöhe hinunter und
    gleich wieder hinauf. Der Griff dagegen war `.leiste-schwebt` (`position: absolute;
    top: 100%`, unter der Unterkante des Stapels, ohne dessen Höhe zu ändern) — **seit
    `1.2.15` entfallen**, weil es nichts Flüchtiges mehr gibt, das schweben müsste.

    **Gemeldet wurde das nur vom iPhone, und daraus folgt eine allgemeine Regel: „Auf
    meinem Gerät ruhig" belegt nicht, dass keine Layoutänderung stattfindet.** Chromium und
    Firefox haben **Scroll Anchoring** (`overflow-anchor`) — wächst oberhalb des
    Sichtbereichs Layout nach, ziehen sie die Scrollposition mit, und das Sichtbare steht
    still; es sieht aus wie ein sauberes Überblenden. WebKit setzt das nicht um. Am Pixel
    war der Fehler also unsichtbar, am iPhone offensichtlich, und die Ursache dieselbe.
    Der Ausgleich hat zudem eine Lücke: Bei Scrollposition 0 kann er nicht nach oben
    korrigieren — oben in der Seite schob es auch in Chrome.

    `zurAktivenSpringen()` misst die **unterste Kante** des Stapels und seiner Kinder
    (`getBoundingClientRect().bottom`), nicht `offsetHeight`. Heute liegt beides gleichauf,
    weil wieder jede Leiste im Fluss mitläuft; die Rechnung bleibt trotzdem, weil sie auch
    für eine aus dem Fluss genommene Leiste stimmt. Sie steht als `stapelUnterkante()` in
    `assets/app.js`, weil der Tastatur-Anker (g) dieselbe braucht. **Wer eine weitere Leiste
    ergänzt, muss an der Scroll-Rechnung nichts ändern.**

    **(e) `.saetze-kopf` allein reicht als Selektor nicht.** `.summary-knopf` steht weiter unten
    in derselben Datei und hat dieselbe Spezifität, also gewinnt die spätere Regel. Deshalb
    `.saetze-block > .saetze-kopf`. Wer einen `.summary-knopf` umfärben will, braucht
    denselben Griff — sonst passiert schlicht nichts, ohne Fehlermeldung.

    **(f) Zurücktreten heißt DUNKLER, nicht blasser.** Die erledigte Karte liegt seit
    `1.2.10` unter einem Schleier (`.position-karte::after`, `--schrift` bei 12 %). Der
    naheliegende Griff — `opacity` auf die Karte oder ein heller Schleier — bewirkt das
    Gegenteil des Gewollten: Gegen den weißen Grund verblasst dabei der **Text**
    (14,8:1 → 3,5:1), während die Fläche fast weiß bleibt. Ein dunkler Schleier dunkelt die
    Fläche ab und lässt den Text in Ruhe (11,7:1), weil beide fast dieselbe Farbe haben.
    Nach oben begrenzt der **matte** Text: Bei 14 % steht er auf 4,50:1, also exakt auf der
    AA-Grenze. Die ganze Rechnung steht bei der Regel im Stylesheet.

    Drei Dinge hängen daran: Der Schleier liegt auf **jeder** Positionskarte und ist nur
    unsichtbar — sonst gäbe es beim Abhaken nichts zu überblenden. `inset: 0` deckt die
    Padding-Box ab, der farbige Balken aus (c) bleibt also in voller Kraft. Und
    `.zeile-fehler` **schlägt** ihn: Beides zugleich gibt es wirklich, und ein fehlgeschlagenes
    Speichern sichtbar zu halten ist wichtiger. Über dem Schleier liegt allein `.erledigt-wahl`
    — der Weg zurück muss sichtbar bleiben; „Tauschen" ist dort ohnehin gesperrt (§7.5).

    **Getönt wird `done = 1`, nicht die bloße Existenz einer Protokollzeile** (Fallstrick 18):
    Im Expertenmodus tritt die Karte erst mit dem Häkchen zurück, nicht schon mit dem ersten
    Satz — am gerenderten HTML nachgesehen, nicht gefolgert.

    **(g) Die Tastatur darf die Seite nicht verschieben** — dieselbe Regel wie (a), nur
    kommt die Bewegung diesmal vom Browser. WebKit scrollt beim Fokussieren eines
    Eingabefelds die Seite, damit das Feld über der Tastatur steht, **auch wenn es dort
    ohnehin schon stünde**; am iPhone rutschte deshalb bei jedem Tipp ins Gewichtsfeld die
    ganze Ansicht ein Stück. Chromium tut es nicht — wieder ein Fall, in dem „auf meinem
    Gerät ruhig" nichts belegt (siehe (d)). Abschalten lässt es sich nicht: keine
    CSS-Eigenschaft, kein Viewport-Schalter. `interactive-widget` kennt nur Chromium und
    würde ausgerechnet das Gerät umstellen, das sich richtig verhält.

    Der **Tastatur-Anker** in `assets/app.js` (seit `1.2.11`) läuft deshalb in **zwei
    Phasen, und das ist der ganze Witz.** Der naheliegende Weg — warten, bis die Tastatur
    oben ist, dann zurückscrollen — erzeugt genau das, was niemand will: Der Browser bewegt
    sichtbar, das Skript bewegt sichtbar zurück, das Feld hüpft. Stattdessen:

    1. **Festhalten.** Jede fremde Scrollbewegung wird sofort im `scroll`-Ereignis
       zurückgenommen, **ohne jede Vorbedingung**. Das läuft noch vor dem nächsten
       Bildaufbau, der Zwischenzustand wird also gar nicht erst gezeichnet — die Seite
       steht still.
    2. **Einmal entscheiden.** Sobald der Sichtbereich zur Ruhe gekommen ist, wird **ein**
       Mal nachgesehen: Ist das Feld sichtbar, war es das. Ist es verdeckt, folgt genau
       **eine** Bewegung, um das Nötigste.

    Im Normalfall also gar keine Bewegung, im Ausnahmefall eine statt hin und her. **Wer
    hier je die Sichtbarkeitsprüfung nach vorn in Phase 1 zieht, hat wieder den Hüpfer**:
    Solange die Tastatur fährt, ist „ist das Feld sichtbar" nicht beantwortbar, und die
    Korrektur fällt auf das späte `resize` zurück — also hinter den Bildaufbau.

    **Gemessen wird gegen `visualViewport`, nie gegen eine eigene Annahme über die Höhe
    einer Tastatur.** Damit hängt die Entscheidung am Zustand und nicht am Browser, und
    beide Geräte beantworten sie gleich — das ist die ganze Zusage:

    | Lage bei offener Tastatur | Was passiert |
    |---|---|
    | Feld sichtbar | **nicht** gescrollt |
    | Feld verdeckt | gescrollt, **einmal** |

    Sie gilt in beide Richtungen: Der Anker nimmt dem iPhone die überflüssige Bewegung —
    und er **ergänzt** eine, wo der Browser von sich aus keine macht, das Feld aber
    verdeckt wäre. „Sichtbar" schließt den Leisten-Stapel ein: Was unter der klebenden
    Leiste liegt, ist so wenig zu sehen wie das hinter der Tastatur.

    **WANN gescrollt wird und WIE WEIT sind zwei getrennte Fragen** — hier liegt der
    häufigste Denkfehler. Das *Ob* entscheidet allein das Feld (Tabelle oben). Das *Wie
    weit* entscheidet die **Reserve**, die die Seite über `ankerReserveMelden()` anmeldet:
    Wenn ohnehin gescrollt wird, dann gleich so, dass darunter noch etwas hinpasst. Wer
    beides vermischt und die Reserve schon ins *Ob* rechnet, scrollt bei **jedem** Fokus —
    die Reserve ist groß, und damit wäre die Zusage „sichtbar heißt stehenbleiben" wieder
    hinfällig.

    Die Trainingsansicht meldet dort ihren Bedarf an (`index.js`, `SAETZE_IN_SICHT = 3`):
    Wer im Satzblock tippt, drückt als Nächstes *„+ Satz"* und füllt die neue Zeile aus.
    Gerechnet wird **vom Ende des Blocks**, nicht vom Feld — dort sitzt der Knopf, und dort
    wachsen die neuen Zeilen hinein; steht der Cursor in Satz 1 von fünf, zählen die vier
    darunter mit. Die Zeilenhöhe wird **gemessen**, ein fester Pixelwert wäre bei der
    nächsten Schriftgröße falsch.

    **Ein Melder und keine feste Rechnung**, weil `assets/app.js` von Satzzeilen nichts
    wissen soll: Klassennamen aus `index.js` dort hineinzuschreiben hieße, für die nächste
    Seite mit ähnlichem Bedürfnis einen zweiten Sonderfall danebenzusetzen. **Zu viel
    Reserve ist ungefährlich** — die Klammer nach oben schiebt das Feld nie unter den
    Stapel; im Zweifel landet es ganz oben, und das ist der Fall mit dem meisten Platz
    darunter.

    Vier Grenzen, jede aus eigenem Grund:

    - **Nur nach einer Berührung** (`pointerdown`/`touchstart`). Ein Mauszeiger holt keine
      Tastatur hoch; ohne diese Bedingung würde am Schreibtisch das völlig berechtigte
      Reveal-Scrollen des Browsers festgehalten.
    - **Nur Textfelder, nach einer Positivliste** (`TASTATUR_TYPEN`). Die Umkehrung wäre
      kürzer und falsch herum. Kästchen fehlen nicht aus Ordnungsliebe: Das Häkchen
      *Erledigt* ist ein `<input>`, und `index.js` springt unmittelbar danach zur nächsten
      Übung — der Anker würde diesen Sprung **verschlucken**.
    - **Nur ein knappes Zeitfenster bei gehaltenem Fokus**, und `wheel`/`touchmove` lösen
      sofort. Wer während des Tippens scrollt, soll gescrollt haben.
    - **Nur begrenzt oft**, als Notbremse.

    **Die Höhe des Stapels rechnet `stapelUnterkante()` in `assets/app.js`** — dieselbe
    Rechnung, die `zurAktivenSpringen()` braucht (d). Sie stand bis `1.2.10` nur in
    `index.js`; zwei Fassungen davon liefen irgendwann auseinander.

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

    **`?plan=` schlägt die Rotation, und genau deshalb wirft das Beenden die Adresse ab**
    (`neuLadenNachEnde()` in `index.js`, seit `1.2.15`). Der Parameter kommt aus der
    Planwahl **vor** dem Training. Während der Einheit ist er wirkungslos — der Plan kommt
    aus `sessions.plan_id` —, und deshalb fällt nicht auf, dass er noch in der Adresse
    steht. Nach dem Beenden greift er wieder: Die Seite schlüge denselben Plan vor, den man
    gerade fertig trainiert hat. Neu geladen wird deshalb auf `location.pathname`, nicht
    über `reload()`. Nachgemessen an zwei Plänen: ohne Query steht der **nächste** blau, mit
    `?plan=` der eben beendete.

    Daran hängt der zweite Teil derselben Zeile: **Der Sprung nach oben.** „Training
    beendet" steht auch am Ende der Liste, und das Ziel ist eine Seite, die oben beginnt.
    Der Browser stellt beim Neuladen die alte Scrollposition wieder her, also wird
    `history.scrollRestoration` vorher auf `'manual'` gestellt — und auf der neuen Seite
    **zweimal** gescrollt (sofort und beim `load`-Ereignis), weil nicht zugesichert ist, ob
    eine Wiederherstellung vor oder nach dem Skript liegt. Zurückgestellt wird
    `scrollRestoration` **immer**, auch ohne Sprung: Der Wert gehört dem History-Eintrag und
    überlebte das Neuladen, und ein verlorener Merker (privater Modus) ließe den Tab sonst
    dauerhaft ohne Wiederherstellung.

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

    **Es gibt keine Vererbung und kein automatisches Nachziehen.** Ändert der Admin die
    Vorlage, bleibt jede bestehende Kopie unberührt; ändert ein Benutzer seine Kopie,
    berührt das die Vorlage nicht. Das ist der Kern und gilt unverändert.

    **Seit `1.2.11` gibt es genau EINEN Weg zurück, und ihn geht der Benutzer selbst**
    (§6.4). Bis `1.2.10` stand hier „kein Rückkanal, wer den neuen Stand will, kopiert
    erneut" — das war als Weg gedacht, eine verbesserte Vorlage zu übernehmen, und taugte
    dafür nicht: Erneut kopieren erzeugt `… (2)`, lässt den alten Split stehen und wirft die
    Auswahl „Diesen trainieren" um. Am 2026-08-23 wurde es deshalb umgedreht.

    Was daran nicht beliebig ist:

    - **`splits.vorlage_id` ist reine Herkunftsangabe.** Ausgewertet wird sie an **einer**
      Stelle — `split_zuruecksetzen()` —, und nur auf Knopfdruck. Wer daraus ein
      automatisches Nachziehen macht, ist wieder bei der Vererbung, die es nicht geben soll.
    - **Ausgelöst wird ausschließlich vom Eigentümer.** `api/splits.php` prüft über
      `eigener_split_api()` und **nicht** über `split_zugriff_api()`: Ein Admin darf fremde
      Splits umbenennen und löschen, aber nicht entscheiden, ob jemandes eigene Anpassungen
      verworfen werden.
    - **Abgeglichen wird, nicht neu angelegt** — siehe Fallstrick 4. `plan_exercises.id`
      hängt an `workout_log.plan_exercise_id`; ein „löschen und aus der Vorlage neu
      schreiben" löste **jede** protokollierte Übung dieses Splits von ihrer Position,
      lautlos und mit `ok:true`. Vorhandene Zeilen werden deshalb **splitweit**
      wiederverwendet: Hat die Vorlage eine Übung in einen anderen Plan verschoben, wandert
      die bestehende Zeile per `UPDATE plan_id` mit. Nur was in der Vorlage wirklich nicht
      mehr vorkommt, wird gelöscht — und dessen Protokollzeilen verlieren ihren Bezug. Das
      ist unvermeidbar und steht deshalb in der Rückfrage der Oberfläche.
    - **Pläne werden nach REIHENFOLGE gepaart, nicht nach Namen.** Ein umbenannter Plan ist
      derselbe Plan an derselben Stelle; über die Namen zu paaren hieße, ihn zu löschen und
      neu anzulegen — und damit die Historie seiner Positionen zu kappen.
    - **Zwei Fingerabdrücke, zwei Fragen.** `split_signaturen()` (**ohne** Namen) beantwortet
      „ist das inhaltlich dasselbe Training" und steuert das Veröffentlichen-Angebot;
      `split_abgleich_signaturen()` (**mit** Plannamen) beantwortet „sieht meine Kopie noch
      aus wie die Vorlage" und steuert den Knopf. Der Name des **Splits** bleibt in beiden
      draußen: Er gehört dem Benutzer, und ein Knopf, der nach einer eigenen Umbenennung
      erschiene, wäre eine Falschmeldung.
    - **Gesperrt bei laufender Einheit** — derselbe Grund wie beim Umsortieren, nur schärfer:
      Hier könnte der Plan, auf dem gerade trainiert wird, ganz verschwinden.
    - **Die Herkunft lässt sich von Hand zuordnen** (`set_vorlage`). Ohne das bliebe die
      Funktion für jeden vor `1.2.11` entstandenen Split wirkungslos — und das sind
      ausgerechnet die, an denen sie nützt. **Geraten wird nicht:** Ein Fingerabdruck passt
      nur, solange nichts geändert wurde, und wer nichts geändert hat, braucht den Knopf
      nicht.
    - **Auf einer Bestandsdatenbank hat `vorlage_id` KEINEN Fremdschlüssel.** SQLites
      `ALTER TABLE ADD COLUMN` kann keinen nachtragen (dieselbe Lage wie bei
      `muscle_groups.parent_id`), das `ON DELETE SET NULL` aus `schema.sql` greift dort also
      nicht. Aufgefangen wird das im `JOIN` von `vorlage_stand()`: Eine tote ID findet keine
      Vorlage und gilt als „keine Herkunft".

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

    **Nach demselben Muster gebaut ist der Hinweis „Schon in …"** (§6.4, seit `1.2.7`, an
    allen drei Stellen seit `1.2.9`): Er sagt, in welchen **anderen** Plänen desselben
    Splits die Übung schon steht. Serverseitig trägt ihn `andere_plaene_eintragen()`
    (`lib/splits.php`) in die Zeilen ein — **eine** Funktion für die Übungsauswahl und
    **beide** Tauschfenster (`api/plans.php`, `api/swap.php`); clientseitig rendert ihn
    `vorschlagMarkup()` selbst und nicht die Aufrufstelle. Wer die Auskunft an den Knöpfen
    zusammensetzt, hat sie beim nächsten Dialog wieder vergessen — genau so fehlte sie in
    `1.2.7`/`1.2.8` in beiden Tauschfenstern.

    **Nicht zweisprachig sind Sätze über Übungen** — Dialogtitel („Ersatz für …"),
    Rückfragen und Fehlermeldungen aus `api/*`. Dort ist der Name Teil eines Satzes, und
    ein zweiter Name mitten darin liest sich als zweite Übung.

28. **Zwei Abrufe desselben Dialogs können sich überholen — dann gewinnt die zuletzt
    eingetroffene Antwort und nicht die zuletzt gestellte Frage.** Die Übungsauswahl in
    `plans.js` lädt bei jedem Öffnen und bei jeder Filteränderung neu; Dialog für Plan A
    öffnen, schließen, gleich darauf Plan B öffnen genügt. Trifft die ältere Antwort später
    ein, überschreibt sie die neuere: Die Liste beschreibt dann einen Zustand, den niemand
    mehr angefragt hat — samt „Bereits im Plan" und „Schon in …" zum **falschen** Plan.
    Nachgewiesen am ausgeführten Code, nicht gefolgert.

    Deshalb bekommt jeder Ladevorgang eine Nummer (`waehlenLauf`), und **vor dem Zeichnen
    UND vor dem Melden eines Fehlers** wird geprüft, ob sie noch die jüngste ist. Das
    Schließen des Dialogs zählt weiter (`'close'`-Ereignis, nicht der Schließen-Knopf — die
    Escape-Taste nimmt denselben Weg). Wer einen weiteren Dialog baut, der seine Liste aus
    einem Abruf zeichnet, braucht dasselbe.

    **Die zweite Hälfte desselben Problems: Zwischen einer gespeicherten Änderung und dem
    Neuladen ist die alte Seite voll bedienbar.** Am Handy ist das leicht eine Sekunde. Wer
    in diesem Fenster eine Auswahl öffnet, sieht deren frischen Stand über einer Seite, die
    noch den alten zeigt — zwei Generationen auf einem Bildschirm, und es sieht aus, als
    stimme die Anzeige nicht. `neuLaden()` in `plans.js` sperrt deshalb **alle** Knöpfe,
    sobald das Neuladen angestoßen ist; der Klick-Verteiler steigt bei deaktivierten Knöpfen
    ohnehin aus, damit greift es auch für die Dialoge. **`window.location.reload()` gehört
    dort nicht mehr direkt in eine Aktion.**

    **Die dritte Hälfte, gefunden beim Durchsehen am 2026-08-26: Wer im DOM VORGREIFT, muss
    bei Misserfolg zurücknehmen — sobald serverseitig gerenderte Zustände an der Reihenfolge
    hängen.** `plans.js` schiebt den Plan schon vor dem Speichern an seine neue Stelle. Seit
    die Randpfeile gesperrt sind (`1.2.18`), trägt aber jede Zeile eine Sperre, die zu ihrer
    **alten** Stelle gehört. Bleibt die Ansicht nach einem Fehlschlag verschoben, gehört jede
    Sperre zur falschen Zeile — bei genau zwei Plänen ist danach kein Pfeil mehr benutzbar,
    und damit auch der zweite Versuch nicht. Zwei Auswege, und welcher richtig ist, entscheidet
    **nicht** der Geschmack, sondern ob der Erfolgsfall neu lädt:

    | Erfolgsfall | Fehlerfall |
    |---|---|
    | lädt neu (Pläne) | **zurücknehmen** — die verschobene Ansicht ist nur im Fehlerfall zu sehen, und dort ist „nichts bewegt" die Wahrheit |
    | lädt nicht neu (Positionen) | **nachziehen** (`posPfeileNachziehen()`) — die Ansicht ist der Arbeitsstand und muss vorgreifen |

    **Und davor die einfachere Hälfte: Solange gespeichert wird, sind die Pfeile gesperrt.**
    Zwei schnelle Tipps schickten sonst zwei `reorder_*` gleichzeitig los; weil jeder Aufruf
    die **ganze** Reihenfolge schreibt, gewinnt die zuletzt eingetroffene Antwort und nicht
    die zuletzt gestellte Frage — dieselbe Falle wie bei der Übungsauswahl, nur beim
    Schreiben. Innerhalb eines Plans wäre das Ergebnis unsichtbar: Dieser eine Weg lädt auch
    im Erfolgsfall nicht neu, die Datenbank stünde also anders sortiert da als der
    Bildschirm, **beide Aufrufe mit `ok`**. Gesperrt wird die **ganze** Liste und nicht nur
    die angetippte Karte — der zweite Tipp landet sonst einfach auf dem Nachbarn. Sperren und
    nicht in eine Warteschlange legen ist eine Entscheidung des Benutzers (2026-08-26): Ein
    Pfeil, der sich kurz nicht drücken lässt, ist ehrlicher als einer, der Tipps sammelt, die
    man nicht mehr sieht.

29. **Eine Anzeige, die im Sekundentakt kommt und geht, sagt nichts — sie kostet nur
    Aufmerksamkeit.** Die Verbindungsleiste meldete bis `1.2.14` auch den flüchtigen
    Zustand „n Eingaben werden gespeichert …". Der erschien bei **jedem** Abhaken für einen
    Sekundenbruchteil, und der Benutzer hat ihn am 2026-08-25 als schlicht störend
    gemeldet: „nervt mich und ist unnötig".

    Er war es auch. Dass eine Zeile noch aussteht, sagt ihr gestrichelter Rand
    (`.zeile-wartet`); dass ein Speichern endgültig gescheitert ist, meldet die Zeile selbst
    mit Wiederholen-Knopf. Die Leiste wiederholte also eine Auskunft, die zweimal daneben
    stand, und trainierte einem dabei das Wegsehen an — genau von der Leiste, die im
    Ernstfall die Störung meldet.

    **Seit `1.2.15` kennt sie genau einen Zustand:** Der Server ist nicht erreichbar. Dann
    steht sie **rot** da (`--fehler`, keine zweite Farbe mehr) und **bleibt stehen**, bis das
    Problem weg ist. Vier Dinge hängen daran:

    - **„Gescheitert" heißt ENDGÜLTIG gescheitert.** `verbindung.erreichbar(false)` steht in
      `apiFetch` erst im Zweig, der wirklich wirft — nicht mehr bei jedem einzelnen
      Fehlversuch. Sonst blitzte die Leiste bei einem Aussetzer, den der Wiederversuch nach
      400 ms auffängt, für ebenjene 400 ms auf, und der Ärger wäre derselbe wie vorher, nur
      in Rot.
    - **Wartende Eingaben lösen sie NICHT aus.** `verbindung.wartend(n)` schreibt die Zahl
      nur fort; sie steht in der Leiste, wenn das Netz ohnehin weg ist, und sonst nirgends.
    - **Wer stehen bleibt, muss das Ende der Störung selbst bemerken.** Die Leiste fragt
      deshalb alle 15 s nach (`_nachfassenPlanen()`). In der Trainingsansicht täte das auch
      die Warteschlange, auf jeder anderen Seite passiert von selbst gar nichts — und auf
      `online` ist kein Verlass (Fallstrick 13). Bewusst ein **roher `fetch`** auf
      `api/token.php` und kein `apiFetch`: Gefragt ist allein, ob überhaupt eine Antwort
      kommt; **jede** beweist das, auch ein 401 — das `apiFetch` zur Anmeldung umleiten
      würde, aus einer Hintergrundabfrage heraus mitten im Training.
    - **`.leiste-schwebt` ist damit ersatzlos entfallen** (Fallstrick 19d). Der ganze
      Schwebe-Kniff aus `1.2.10` war dafür da, dass das Aufblitzen die Seite nicht
      verschiebt. Was nur bei einem echten Zustandswechsel erscheint, darf einmal schieben —
      und darf den Inhalt darunter nicht dauerhaft verdecken, läuft also im Fluss mit.

    **Die allgemeine Form: Eine Statusanzeige gehört an einen Zustand, den jemand ändern
    kann, nicht an jeden Vorgang, den es gibt.** Wo der Normalfall ohnehin gutgeht, ist
    Schweigen die richtige Meldung.

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

  **Das ist eine VORGABE für den Normalfall und keine Tatsachenbehauptung.** Eine Auskunft
  des Benutzers schlägt sie, und zwar in beide Richtungen — am 2026-08-25 an einem Tag
  zweimal: Erst blieb die Nummer trotz dreier Bauläufe stehen („noch nicht eingespielt"),
  dann war eine gebaute Version doch schon live und die nächste Änderung brauchte eine neue
  Nummer. Daraus zwei Dinge:

  - **Ohne gegenteilige Ansage nach der Vorgabe handeln** — nicht nachfragen, das war der
    Sinn der Regel.
  - **Aber nichts darauf stützen, was man messen kann.** Wer `doku/stand.md` auf „live"
    zieht oder ein Rollback-Ziel benennt, misst vorher (der `curl`-Einzeiler unter
    „Lokale Entwicklung"). Genau diese Zeile stand am 2026-08-25 falsch da, weil die Vorgabe
    für einen Befund gehalten wurde.
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

**Der Push muss OHNE Sandbox laufen** (`dangerouslyDisableSandbox`). Der Schlüssel
`~/.ssh/github_rezeption` ist gültig, bei GitHub hinterlegt und **nicht** passphrasegeschützt
— aus der Sandbox heraus kommt `ssh` nur nicht an das Schlüsselmaterial, und dann entsteht
gar keine Signatur.

**Die Meldung führt dabei in die Irre**, und zwar auf die teure Art: Es kommt ein schlichtes
`git@github.com: Permission denied (publickey)`, das nach falschem oder gesperrtem Schlüssel
aussieht. Der erste Versuch hängt außerdem, bis das Zeitlimit greift. Am 2026-08-26 wurde
daraus die falsche Schlussfolgerung „Schlüssel hat eine Passphrase, es läuft kein
ssh-agent" — und der Benutzer bekam eine Anleitung für ein Problem, das er nicht hatte.

Wer die Ursache sehen will, fragt `ssh` selbst; die Stelle steht in der ausführlichen
Ausgabe, nicht in der Fehlermeldung:

```bash
ssh -vvv -o BatchMode=yes -T git@github.com 2>&1 | sed -n '/Offering public key/,/denied/p'
```

`Server accepts key` gefolgt von `signing using …` und `we did not send a packet` heißt:
Der Schlüssel ist richtig, die Sandbox ist das Hindernis. **Dann den Push wiederholen und
die Sandbox ausdrücklich abschalten** — der Benutzer hat ihn ja verlangt.

Die `.gitignore` ist eine **Positivliste-Denkweise**: Was neu dazukommt und nicht in ein
öffentliches Verzeichnis gehört, wird dort ergänzt. Schon einmal durchgerutscht wäre
`.claude/settings.local.json.tmp.<pid>.<hash>` — die Regel traf nur den exakten Dateinamen,
nicht die Tempdatei daneben. Deshalb steht dort jetzt ein Stern.

**Git ersetzt die Datensicherung nicht.** Die Daten liegen im Docker-Volume und werden
über die Wartungsseite gesichert (§6.5, ZIP mit Bildern). Aus dem Repo allein entsteht ein
lauffähiges, leeres System — nicht mehr.
