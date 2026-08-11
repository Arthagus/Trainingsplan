# Stand des Systems

Der **flüchtige** Teil der Projektdokumentation: was gerade läuft, welche Daten drin sind,
was offen ist. Dauerhaftes Wissen — Architektur, Konventionen, Fallstricke — steht in
`CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.** Sie ist die einzige Stelle mit
Versionsnummern und Zählständen; wenn sie hier falsch sind, sind sie nirgends sonst falsch.

*Letzte Aktualisierung: 2026-08-11*

---

## Ausgerollt

> ## Live läuft `trainingsplan:1.1.3`
>
> Ausgerollt am 2026-08-11, das Weiterspringen nach dem Abhaken sitzt jetzt richtig.
> **Der Arbeitsstand im Repo ist `1.1.4` und noch nicht gebaut.**
> Alles darunter ist die Vorgeschichte, neueste zuerst.

**`1.1.4`: Abgehakte Übungen sind festgeschrieben.** Im Expertenmodus ließen sich bei einer
als erledigt markierten Übung Wiederholungen und Gewicht nachträglich ändern und Sätze
hinzufügen oder löschen. Das war ein Überbleibsel aus `1.1.0`, als der erste Satz die Übung
noch **selbst** abhakte — damals hätte eine Sperre das Nachtragen des zweiten Satzes
verhindert. Mit dem Schalter aus `1.1.1` ist die Begründung entfallen: Wer noch einen Satz
machen will, hat schlicht noch nicht abgehakt.

Gesperrt wird **serverseitig** (`abgeschlossene_position_schuetzen()` in `api/log.php`), die
ausgegrauten Felder sind nur die Bequemlichkeit davor. Drei Ausnahmen sind nötig: keine
bestehende Zeile, `done = false` (der Weg zum Entsperren) und — am wichtigsten — eine
**unverändert durchgereichte Nutzlast**, sonst zerbräche die Idempotenz der Warteschlange.
Die Sperre gilt jetzt auch für das Gewichtsfeld im einfachen Modus, wo sie bis dahin nur eine
Regel der Oberfläche war. Service-Worker-Cache auf `v18`.

Dazu in derselben, noch ungebauten Nummer: **Die Zeile „zuletzt …" nennt im Expertenmodus die ganze Satzfolge** — `zuletzt 3 Sätze (12×45 · 10×45 · 8×50)` statt einer einzelnen Zahl, in derselben Form wie die Zusammenfassung im Satzblock darunter. Im Standardmodus bleibt es bei „zuletzt 45 kg". **Beide Zusammenfassungen benutzen dieselbe Schreibweise** — sie stehen am Handy direkt übereinander, und zwei Formen liest man dort als Unterschied in der Sache. Gebaut wird sie an einer Stelle je Seite: `saetze_zusammenfassung()` und `saetzeZusammenfassung()`.

**`1.1.3`: Beim Weiterspringen wurde die Karte von der Verbindungsleiste verdeckt.** Nach dem
Abhaken sprang die Ansicht zur nächsten Übung, deren Name aber oben abgeschnitten war. Grund:
Die Leiste hängt als erstes Element im `<body>` und ist `position: sticky; top: 0` — sie
überlagert, was darunter durchscrollt. Und sie wird **genau beim Abhaken sichtbar**, weil die
Eingabe in die Warteschlange geht. `scrollIntoView({block:'start'})` setzte die Karte damit
zuverlässig unter die Leiste. `zurAktivenSpringen()` rechnet ihre Höhe jetzt gemessen heraus
(`offsetHeight`, 0 wenn ausgeblendet) plus 8 px Luft.

**Kein Cache-Hochzählen nötig:** Betroffen ist nur `index.js`, und der Service Worker fasst
ausschließlich Dateien unter `/assets/` an — alles andere läuft `network-only`. `sw.js` bleibt
auf `v17`.

Auf dem Weg dahin sind an einem Tag `1.0.20`, `1.1.0`, `1.1.1` und `1.1.2` gelaufen; die
Sprünge kamen aus drei Runden Rückmeldung im Studio (`doku/rueckmeldungen_praxistest.md`).
`1.0.21` wurde nie gebaut und ging in `1.1.0` auf.

**Von außen bestätigt** (gilt unverändert seit `1.1.0`): HTTP leitet auf HTTPS um, das
Sitzungscookie trägt `secure; HttpOnly; SameSite=Lax`, `Service-Worker-Allowed: /` steht, und
`VERSION`, `schema.sql`, `Dockerfile`, `apache-app.conf`, `lib/` sowie `data/` antworten
mit 403.

**`1.1.2` ist auf dem Live-System durchgeprüft.**
Die App meldet selbst `1.1.2`, die Assets tragen `…-assets-v17`. Durchgespielt mit einem
Testkonto: ohne Training alles grau und zugeklappt; nach dem Start ist die erste Übung grün
und offen; Sätze ohne Häkchen lassen `x/n` stehen und sperren trotzdem schon den Tausch;
nach dem Abhaken wird die Position blau, klappt zu, und die nächste wird grün und öffnet
sich. Der Verlauf zeigt die Satzfolge, Volumen und geschätztes 1RM samt Näherungshinweis.
Umschalten des Expertenmodus bei laufender Einheit antwortet mit 409. **Testplan, Einheiten
und Protokollzeilen wurden anschließend restlos entfernt**; der Datenstand ist unverändert.

Der Feinschliff aus dem zweiten Einsatz, alles zur Bedienung am Gerät:

1. **Farbleitsystem am linken Kartenrand.** Grün = hier bist du (die erste noch nicht
   erledigte Übung), blau = erledigt, grau = kommt noch. Vorher war Grün die Farbe für
   „erledigt" — Grün zieht aber den Blick, und den soll ziehen, was als Nächstes dran ist.
2. **Aktive Markierung und aufgeklappter Satzblock nur während eines Trainings.** Wer den
   Plan bloß anschaut, sieht eine ruhige Liste. „Training starten" öffnet die erste Übung
   und scrollt dorthin (über einen Merker in `sessionStorage`, weil die Seite dazwischen neu
   lädt).
3. **Der Wartezustand sieht nicht mehr nach Fehler aus.** `.zeile-wartet` ändert nur noch
   `border-left-style: dashed` — die Farbe bleibt, was der Zustand vorgibt. Der Hinweissatz
   in der Karte ist ersatzlos weg: Er machte sie höher und danach wieder niedriger, wodurch
   bei jedem Satz die ganze Liste sprang. Die Leiste oben genügt.
4. **Fokusrahmen im Stepper freigestellt.** Er lag über dem Minus- und unter dem Plus-Knopf.
   Jetzt 3 px Abstand im Stepper plus `outline-offset: 0` am Zahlenfeld. Dazu ist das
   Breitenbudget der Satzzeile nachgerechnet und im Stylesheet dokumentiert; die Staffelung
   für schmale Geräte ist zweistufig geworden (bis 400 px entfallen „×" und „kg", bis 352 px
   zusätzlich die Satznummer).

Service-Worker-Cache auf `v17`.

---

**Was `1.1.1` gebracht hat** — drei Korrekturen aus dem ersten Einsatz von `1.1.0`:

1. **„Erledigt" ist ein Schalter geworden.** Vorher hakte sich eine Übung mit dem ersten
   Satz selbst ab; wer den zweiten Satz machen wollte, stand als fertig da. Neue Spalte
   `workout_log.done` (Vorgabe 1) trennt „protokolliert" von „fertig". Abhaken klappt den
   Satzblock zu und springt zur nächsten offenen Übung. Ab-wählen löscht die Sätze **nicht**
   mehr — es nimmt nur die Markierung zurück.
2. **Eine noch leere Satzzeile wird nicht mehr abgeschickt.** „+ Satz" ohne Vorlage erzeugte
   eine leere Zeile, der Server lehnte sie mit 422 ab, und die Zeile bekam einen roten Rand
   samt „Erneut versuchen" — obwohl niemand etwas falsch gemacht hatte.
3. **Farblogik richtiggestellt.** `.saetze-kopf` hatte dieselbe Spezifität wie
   `.summary-knopf` und stand davor, also kam die leise Fassung nie an. Dazu stehen jetzt
   **alle** `:hover`-Regeln hinter `@media (hover: hover)`: Auf dem Touchscreen blieb Hover
   am zuletzt angetippten Element kleben, wodurch Satzkopf und „+ Satz" ihre Blautöne
   tauschten. Kräftig ist jetzt „+ Satz", leise der Kopf.

Dazu: Service-Worker-Cache auf `v16`, Warteschlangen-Schlüssel auf
`trainingsplan-warteschlange-v3` (der Eintrag trägt jetzt `done`), und die Sperrmeldung beim
Tausch spricht nicht mehr vom Häkchen, sondern von „bereits protokollierten Werten" — im
Expertenmodus sperren schon die Sätze.

`1.1.0` war eine neue Minor-Nummer, weil der
**Expertenmodus** ein eigenes Kapitel der Fachlichkeit aufmacht: satzgenaues Protokollieren
mit eigener Tabelle, eigener Benutzereinstellung und einem neuen Abnahmekriterium (§11.19).
Inhalt:

- **`workout_sets`** — je Satz eine Zeile mit Wiederholungen und Gewicht, an `workout_log`
  über `ON DELETE CASCADE`. `workout_log.weight` bleibt und trägt im Expertenmodus den
  **schwersten** Satz; „letztes Gewicht" und Gewichtsverlauf laufen dadurch über beide Modi
  hinweg unverändert weiter.
- **`users.expert_mode`** — je Benutzer umschaltbar auf der Kontoseite, Vorgabe 0. Wer nichts
  tut, sieht die Oberfläche exakt wie bisher.
- **Trainingsansicht:** aufklappbarer Satzblock, aufgeklappt ist die erste noch nicht
  erledigte Position. „+ Satz" belegt Satz k mit Satz k der letzten Einheit vor;
  Wiederholungen über −/+, Gewicht als Textfeld. Änderungen gehen 800 ms gebündelt raus.
- **Verlauf:** Spalte „Sätze" bei den Einheiten; Volumen- und Epley-1RM-Kurve samt
  Näherungs-Hinweis bei den Übungen.
- **`1.0.21` wurde nie gebaut** und geht in `1.1.0` auf — ihr einziger Inhalt war das
  Entfernen der Symbolübersicht von der Wartungsseite.

**Service-Worker-Cache auf `v15`** (`assets/style.css` und `app.js` haben sich beide
geändert). Der `localStorage`-Schlüssel der Warteschlange heißt jetzt
`trainingsplan-warteschlange-v2` — ein Eintrag aus `1.0.x` liefe sonst als „check ohne
Satzliste" durch und löschte damit Sätze.

**Die Migration ist rein additiv und verlustfrei.** Sie legt `workout_sets` an und ergänzt
`users.expert_mode` mit Vorgabe 0; bestehende Protokollzeilen bleiben unberührt. Geprüft
gegen eine Datenbank ohne beides: Sie läuft durch, ist beim zweiten Start idempotent, und
die Bestandsdaten stehen danach unverändert da.

**Die Gerätesymbole sind abgenommen** (2026-08-11). Der Weg dahin, weil er sich wiederholen
kann: `1.0.18` brachte eine vorübergehende Auswahlhilfe auf die Wartungsseite — Größe,
Strichstärke und je drei Formvarianten, jeweils in einem echten Abzeichen. Nach der Wahl
wurde sie in `1.0.19` durch eine Übersicht des laufenden Satzes ersetzt, `1.0.20` brachte
die letzten beiden Korrekturen (gestreckte Langhantel, vertikal ausgerichteter Kabelzug),
und mit `1.0.21` fällt die Kachel wieder weg. Sie war ein Werkzeug für eine Entscheidung,
kein Bestandteil der App; die Symbole selbst stehen unverändert in
`lib/view_geraet_symbole.php`.

**`1.0.16` ist am selben Tag gelaufen und in zwei Fassungen gepackt worden** — der Tarball
wurde unter derselben Nummer überschrieben, als die nächste Rückmeldung kam. Welche der
beiden ausgerollt war, ließ sich hinterher nicht mehr feststellen. Mit `1.0.17` ist das
gegenstandslos; als Lehre bleibt: **korrekt weiterzählen**, jede Änderung nach dem Packen
bekommt die nächste Nummer, auch wenn nur Minuten dazwischen liegen.

**Die Datenbank hat mit `1.0.16` die Spalte `exercises.equipment` bekommen.** Die Migration
ändert und löscht nichts; Übungen ohne Gerät tragen das Abzeichen „Gerät fehlt", bis sie
nachgepflegt sind (siehe *Offen*, Punkt 6). `1.0.17` bringt keine Schemaänderung mit.

**`1.0.15` wurde nicht ausgerollt.** Die Nacharbeit kam, als das Image schon gebaut war, und
bekam deshalb eine eigene Nummer — dasselbe Muster wie bei `1.0.13`.

**`1.0.13` ist übersprungen worden:** Das Image war in Portainer bereits gebaut, als die
Nacharbeit an derselben Zeile kam. Sie trägt deshalb eine eigene Nummer, obwohl beide
dieselbe Sache betreffen.

**Seit `1.0.10` beantwortet die App diese Frage selbst:** Die Wartungsseite zeigt als erste
Kachel die Version des laufenden Images, gelesen aus der Datei `VERSION`. Bleibt sie nach
einem Stack-Update auf der alten Nummer stehen, läuft noch der alte Container. Diese Datei
hier bleibt trotzdem die Stelle, an der steht, *was* in einer Version drin ist.

**Die Versionsnummern haben nichts mit Kalendertagen zu tun.** Mal entstehen mehrere an
einem Nachmittag (`1.0.9` bis `1.0.12` am 2026-08-08, `1.0.5` bis `1.0.8` am 2026-08-07),
mal tagelang keine. Gezählt wird schlicht zur nächsten Nummer weiter, sobald ein Stand
gepackt oder gebaut ist.

**Vom Benutzer gegengeprüft** — *2026-08-10, `1.0.17`, am PC **und** am Smartphone:* keine
Fehler aufgefallen, die Darstellung passt. Damit ist der Punkt erledigt, der seit `1.0.7`
offen stand und den weder `curl` noch ein gerenderter Nachbau je abdecken konnte: die
Knopfzeile in der Planverwaltung, die Aktionszeile im Training, die Bilder in den
Tauschvorschlägen, die sieben Gerätesymbole, die Abzeichenzeile, das Auswahl-Overlay, der
Gerätefilter in beiden Tauschdialogen, der Systemdialog hinter „Dauerhaft im Plan" und die
einzeilige Filterleiste.

**Nicht** darin enthalten und weiterhin offen: die Gegenprobe bei **schwachem Netz**
(Warteschlange und Verbindungsleiste, siehe *Offen* Punkt 1) — die verlangt einen eigenen,
unten beschriebenen Ablauf mit abgeschaltetem Netz und ergibt sich nicht aus normaler
Benutzung. Ebenso die vier Abnahmekriterien aus §11.

**Gegen die laufende Instanz geprüft**, per `curl`:

- *2026-08-10, `1.0.17`:* `style.css`, `app.js`, `sw.js` und `manifest.json` byteweise
  gleich dem Repo, `sw.js` auf `v13` — damit ist `1.0.17` als laufender Stand belegt.
  Zugriffssperren stichprobenhaft, **einschließlich der beiden neuen `lib/`-Dateien**:
  `VERSION`, `schema.sql`, `Dockerfile`, `apache-app.conf`, `docker-compose.yml`,
  `lib/geraete.php`, `lib/view_geraet_symbole.php`, `data/*.db` allesamt 403; `.env` und
  `deploy/` sind gar nicht erst im Image (404), `health.php` von außen 404 (nur Loopback),
  geschützte Seiten leiten auf `login.php`.
- *2026-08-10, Fachlichkeit gegen den echten Bestand:* Der Filter greift kombiniert — eine
  Hauptgruppe zieht ihre Untergruppen und die nur sekundär betroffenen Übungen mit, und die
  Verbindung mit einem Gerät schneidet die Menge wie erwartet weiter ein. Jede Trefferliste
  gegen eine eigene `SELECT`-Zählung abgeglichen. Im Auswahl-Overlay schränken die Facetten
  richtig ein: Eine Muskelgruppe, für die es nur Maschinen- und Körpergewichtsübungen gibt,
  lässt genau diese beiden Geräte übrig; umgekehrt reduziert ein Gerät die Muskelgruppen auf
  die passenden — samt ihrer Elterngruppen. Die Filterleiste steht auf dem Desktop in
  **einer** Zeile (gegen die echte Seite mit dem vollen Bestand gerendert).

- *2026-08-08, `1.0.12`:* `VERSION` liefert jetzt **403** statt der Versionsnummer — die
  Lücke aus `1.0.10` ist zu, nachgeprüft am laufenden System. `schema.sql` und
  `Dockerfile` weiterhin 403, Startseite leitet auf `login.php`, `style.css`, `app.js`
  und `sw.js` byteweise gleich dem Repo.
- *2026-08-08, `1.0.11`:* `assets/style.css`, `app.js`, `sw.js` und `manifest.json`
  byteweise gleich dem Repo, `sw.js` auf `v8`, die Merkmale aus `1.0.10`/`1.0.11` im
  ausgelieferten CSS nachgewiesen (`counter-increment`, der 32rem-Umschaltpunkt,
  `.uebung-raster`). Zugriffssperren stichprobenhaft: `schema.sql`, `Dockerfile`,
  `apache-app.conf`, `lib/*`, `data/*.db` allesamt 403.

  **Dabei gefunden:** `VERSION` wurde **ohne Anmeldung ausgeliefert** (200 statt 403).
  Die Datei kam mit `1.0.10` neu ins Wurzelverzeichnis und fiel durch den
  `<FilesMatch>`-Filter in `apache-app.conf`, weil der auf Endungen und feste Namen
  passt — `VERSION` hat keine Endung. Behoben in `1.0.12`, wirksam erst nach dem
  Rollout. Preisgegeben wurde nur die Versionsnummer, und die ließ sich ohnehin aus
  dem öffentlichen CSS ablesen; trotzdem hat die Datei im Web nichts verloren.
- *2026-08-08, `1.0.10`:* nicht einzeln gegen die Instanz geprüft — reine
  Darstellungsversion, vom Benutzer als laufend bestätigt, vor dem Rollout lokal
  per `curl` gegen den Dev-Server und im Browser gerendert.
- *2026-08-08, `1.0.9`:* Anmeldung als `CLAUDE` statt `claude` funktioniert — `COLLATE
  NOCASE` greift. Umbenennen auf `oliver` und auf `NELE` wird mit 409 abgelehnt — der
  Index `idx_users_name_nocase` ist da. Dass der Container überhaupt hochkam, belegt
  zugleich, dass die Migration keine Dubletten vorfand.
- *2026-08-07, `1.0.8`:* ausgelieferte Assets byteweise gleich dem Repo, `sw.js` auf `v6`,
  alle Zugriffssperren, beide Wege der Umbenennung samt sämtlicher Ablehnungen,
  `must_change_password` sperrt die neuen Aktionen.

**Noch nicht gegengeprüft** — alles nur am Handy prüfbar, Einzelheiten unter *Offen*:
Warteschlange und Verbindungsleiste aus `1.0.8` (das Wichtigste), dazu die Darstellung
aus `1.0.10` bis `1.0.17`.

## Datenstand

Einzelheiten in `bestand_gruppen_uebungen.md`.

| | |
|---|---|
| Muskelgruppen | Zweistufig: Hauptgruppen mit Untergruppen darunter. Die Gliederung steht in `bestand_gruppen_uebungen.md` |
| Übungen | Wächst laufend, **alle mit Trainingsgerät**. Der Bestand steht in der App und wird hier bewusst nicht gezählt — welche Zahl gerade gilt, ist für die Entwicklung ohne Belang, und eine notierte Zahl ist am Tag danach falsch. Genutzt werden bisher Maschine, Kabelzug, Kurzhantel und Körpergewicht; Multipresse, Langhantel und Kettlebell stehen bereit, sind aber unbenutzt |
| Benutzer | `Oliver` (id 1, Admin) · `claude` (id 2, Admin) · `Nele` (id 3) — Namen ab `1.0.8` änderbar. **Alle auf `expert_mode = 0`**, solange niemand umschaltet |
| Pläne | `Oliver`: Push, Pull · `Nele`: Ganzkörper A, Ganzkörper B — je 8 Positionen |
| Trainingseinheiten | 4, mit 25 Protokollzeilen (Wartungsseite, 2026-08-11) |
| Sätze (`workout_sets`) | 0 — die Tabelle entsteht mit `1.1.0` leer und füllt sich erst, wenn jemand den Expertenmodus einschaltet und damit trainiert |
| Sicherungen | 1 (ZIP mit Bildern, 774 KB, 2026-08-07) — **inzwischen deutlich älter als der Datenstand**, siehe *Offen* |

Zahlen vom 2026-08-11, abgelesen auf der Wartungsseite: 3 Benutzer, 27 Muskelgruppen,
31 Übungen, 4 Pläne, 4 Einheiten, 25 Protokollzeilen, 62 Bilder (1,6 MB), Datenbank 164 KB.
Sie veralten schnell und stehen hier nur als Größenordnung.

## Offen

1. **Vier Abnahmekriterien am Handy**, siehe unten. Die Gegenprobe der *Darstellung* deckt
   sie nicht ab — die Kriterien sind einzelne, benannte Abläufe.
2. **`bestand_gruppen_uebungen.md` ist veraltet** — es listet die Übungen einzeln auf, samt
   Zählständen, und beides stimmt längst nicht mehr. Die Datei ist ein Überbleibsel aus der
   Zeit, als sie eine Eingabeanleitung war. Sinnvoller als Nachzählen wäre, sie auf das zu
   kürzen, was sich *nicht* täglich ändert: die Muskelgruppen-Gliederung und die
   Überlegungen zur Tauschregel. Der Übungsbestand steht in der App.

**Nicht mehr auf dieser Liste, auf Entscheidung des Benutzers (2026-08-11):**

- **Die Gegenprobe bei schwachem Netz** wird nicht als eigener Versuch durchgeführt. Sie
  bleibt damit **ungeprüft** — das ist eine bewusste Entscheidung, kein bestandener Test.
  Rückmeldung kommt aus dem laufenden Betrieb, wenn im Studio etwas klemmt. Woran man dann
  erkennt, ob Warteschlange und Verbindungsleiste ihre Arbeit tun, steht unten unter
  *Gegenprobe schwaches Netz*; der Abschnitt bleibt als Nachschlagestelle stehen.
- **Die Sicherung außer Haus** liegt beim Benutzer und wird hier nicht weiter verfolgt.

**Erledigt mit `1.0.19`:** Die Gerätesymbole waren bei ihrer tatsächlichen Größe zu
undeutlich — bei `1.05em` rendern sie mit rund 13px, und dort verloren sie ihre Form;
`Multipresse` und `Langhantel` waren nicht zu unterscheiden, `Maschine` wurde zu einem
Fleck. Nach einer Auswahlrunde über die Wartungsseite (`1.0.18`) steht der Satz jetzt auf
**1.5em bei Strichstärke 1.8**, der Kabelzug zeigt eine Latzugstange, die Langhantel große
Scheiben innen und kleinere außen an einer durchlaufenden Stange, und die Maschine eine
einzelne Diagonale aus dem Gewichtsblock statt des abgewinkelten Rahmens.

**Satzgenaues Protokollieren ist mit `1.1.0` umgesetzt** (2026-08-11) — die Frage nach den
Wiederholungen hat sich also erneut gestellt und ist beantwortet, ohne die Begründung von
2026-08-07 umzustoßen: keine Spalte, sondern die Tabelle `workout_sets`, und nur für die, die
den Expertenmodus einschalten. Weitergehende Wünsche stehen weiter in `LASTENHEFT.md` §10.

## Prüfstand der 19 Abnahmekriterien (`LASTENHEFT.md` §11)

**Bestanden:** 1, 4–15. Die Kriterien 4–13 gegen den Dev-Server durchgespielt, 1 und 15 auf
dem Live-System.

**Kriterium 14 (Upload-Sicherheit), live bestanden am 2026-08-07:** Eine als `.jpg` getarnte
PHP-Datei wird abgelehnt — der Typ kommt aus dem Inhalt, nicht aus der Endung. Ein *gültiges*
PNG mit angehängtem Schadcode wird angenommen, die GD-Re-Enkodierung zeichnet es aber als
JPEG neu; am gespeicherten Bild nachgeprüft, dass kein Code übrig ist.

**Zusätzlich live bestätigt:** `Secure`/`HttpOnly`/`SameSite`-Cookies, HTTPS-Erzwingung,
`Service-Worker-Allowed`, die Sperren für `lib/`, `data/`, `schema.sql` und `Dockerfile`.

**Offen — nur mit echtem Handy prüfbar:**

| # | Was |
|---|---|
| 2, 3 | PWA installieren, Browser schließen, App vom Startbildschirm öffnen → kein erneuter Login |
| 6 | Handy während des Trainings sperren, App neu öffnen → Häkchen und Fortschritt erhalten |
| 16 | Ein Gerät unter *Geräte* abmelden → dieses verlangt wieder das Passwort, die anderen nicht |

**Kriterium 17 (Restore) — teilweise:** Der gesamte *Prüfpfad* ist auf dem Live-System
bestätigt (ZIP öffnen, Datenbank finden, entpacken, `integrity_check`, Tabellenabgleich) —
über den Upload, der dieselben Schritte durchläuft. Das eigentliche Überschreiben wurde dort
bewusst **nicht** ausgeführt; lokal ist es getestet, inklusive Rückfall bei Fehlern.

**Kriterium 18 (Container-Neustart):** bei jedem Update implizit passiert, nie ausdrücklich
geprüft.

**Kriterium 19 (Expertenmodus, neu mit `1.1.0`) — serverseitig bestanden, am Gerät offen.**
Gegen den Dev-Server durchgespielt: drei Sätze schreiben und das Leitgewicht (schwerster
Satz) prüfen, denselben Aufruf wiederholen (keine Dubletten), die Liste kürzen, `uncheck`
und Einheit-Löschen räumen die Sätze über CASCADE mit weg, alle sechs Grenzwerte werden mit
422 abgelehnt ohne etwas zu schreiben, Dezimalkomma kommt an, IDOR auf eine fremde
Planposition ergibt 403, der einfache Modus verhält sich unverändert, und `set_expert_mode`
antwortet bei offener Einheit mit 409. Zusätzlich geprüft: Die Vorbelegung zeigt auf die
**vorige** Einheit und nicht auf die laufende.

**Am Handy offen:** Bedienbarkeit der Stepper zwischen zwei Sätzen, das Verhalten im
Flugmodus (gestrichelter Rand, gesperrtes Beenden, Nachholen) und die Darstellung des
Satzblocks auf einem schmalen Gerät.

## Gegenprobe schwaches Netz (`1.0.8`)

**Nachschlagestelle, keine offene Aufgabe** (siehe *Offen*): Der Versuch wird nicht eigens
durchgeführt. Was hier steht, ist die Beschreibung des erwarteten Verhaltens — für den Fall,
dass im Studio etwas klemmt und man wissen muss, was eigentlich passieren sollte.

Am Handy oder in den Entwicklerwerkzeugen unter *Network → Offline* bzw. *Slow 3G*. Die
Warteschlange greift **nur bei laufender Einheit** — vorher „Training starten" drücken.

| Was tun | Was passieren muss |
|---|---|
| Netz trennen, eine Übung abhaken | Häkchen **bleibt** stehen, Zeile bekommt einen gestrichelten Rand und „Noch nicht gespeichert", rote Leiste oben mit der Zahl |
| Noch zwei abhaken | Zähler in der Leiste steigt, „x/n" zählt mit |
| Netz wieder einschalten | Leiste verschwindet, gestrichelte Ränder werden grün — ohne Zutun |
| Danach neu laden | Alle drei Häkchen sind da |
| Netz trennen, abhaken, App **schließen**, öffnen, Netz an | Das Häkchen wird nachgeholt |
| Mit wartenden Eingaben „Training beendet" drücken | Wird abgelehnt: „Es sind noch Eingaben nicht gespeichert" |
| Mit wartender Zeile „Tauschen" drücken | Wird abgelehnt: „Diese Übung wird gerade gespeichert" |
| Auf *Slow 3G* abhaken | Nach spätestens 12 s entweder Erfolg oder ein sichtbarer Fehler — **nie** ein Häkchen, das minutenlang tot dasteht |

Der letzte Punkt ist der eigentliche Anlass: Genau dieser Zustand war vorher unsichtbar.

## Zugang für Claude

Auf dem Live-System existiert ein Admin-Konto **`claude`** (id 2), vom Benutzer eingerichtet.
**Der erzwungene Passwortwechsel greift auch hier** (Fallstrick 3): Nach der Anmeldung mit
einem frisch zurückgesetzten Startpasswort ist **jede** Seite und **jeder** Endpunkt außer
`api/auth.php` gesperrt, bis ein eigenes Passwort gesetzt ist — und dasselbe noch einmal zu
setzen wird abgelehnt. Wer das Konto benutzt, muss also ein neues wählen und es dem Benutzer
nennen; sonst kennt es niemand mehr.

**Das Passwort steht bewusst nicht hier** — in einer neuen Sitzung beim Benutzer erfragen oder
von ihm zurücksetzen lassen (*Benutzer → Passwort zurücksetzen*). Das Konto darf jederzeit
gelöscht werden.

Verwendungszweck: Anlegen von Stammdaten über `api/*` und Prüfungen von außen.
**Dateneingabe über die Oberfläche macht bewusst der Benutzer** — sonst bleibt
Abnahmekriterium 1 ungeprüft.

## Änderungen der letzten Versionen

Nur als Gedächtnisstütze; die *Begründungen* stehen dort, wo sie hingehören — im Code, in
`CLAUDE.md` unter „Fachliche Fallstricke" oder in `rueckmeldungen_praxistest.md`.

| Version | Was |
|---|---|
| `1.1.4` | **Abgehakte Übungen sind festgeschrieben.** Im Expertenmodus ließen sich Wiederholungen und Gewicht einer erledigten Übung nachträglich ändern und Sätze hinzufügen oder löschen — ein Überbleibsel aus `1.1.0`, als der erste Satz die Übung noch selbst abhakte. Mit dem Schalter aus `1.1.1` ist die Begründung entfallen. Gesperrt wird **serverseitig** (`abgeschlossene_position_schuetzen()`), die ausgegrauten Felder sind nur die Bequemlichkeit davor; **eine unveränderte Nutzlast geht ausdrücklich durch**, sonst zerbräche die Idempotenz der Warteschlange. Gilt jetzt auch für das Gewichtsfeld im einfachen Modus. Dazu nennt die Zeile „zuletzt …" im Expertenmodus die ganze Satzfolge (`zuletzt 3 Sätze (12×45 · 10×45 · 8×50)`) — in **derselben Schreibweise** wie der Kopf des Satzblocks darunter, gebaut von `saetze_zusammenfassung()` bzw. `saetzeZusammenfassung()`. Cache `v18` *(noch nicht gebaut)* |
| `1.1.3` | Beim Weiterspringen nach dem Abhaken landete die nächste Übungskarte **unter der Verbindungsleiste** — sie ist `position: sticky; top: 0` und wird genau in diesem Moment sichtbar, weil die Eingabe in die Warteschlange geht; `scrollIntoView({block:'start'})` setzt das Ziel exakt an den Viewport-Rand und damit darunter. `zurAktivenSpringen()` zieht ihre **gemessene** Höhe ab (`offsetHeight`, 0 wenn ausgeblendet — der Text kann auf schmalen Geräten zweizeilig werden) und lässt 8 px Luft. Nur `index.js` betroffen, deshalb **kein** Cache-Hochzählen: Der Service Worker fasst ausschließlich `/assets/` an *(live seit 2026-08-11)* |
| `1.1.2` | Feinschliff aus dem zweiten Einsatz, alles zur Bedienung am Gerät. **Farbleitsystem** am linken Kartenrand: grün = hier bist du, blau = erledigt, grau = kommt noch — vorher war Grün „erledigt", aber Grün zieht den Blick, und den soll ziehen, was als Nächstes dran ist. **Aktive Markierung und aufgeklappter Satzblock nur während eines Trainings**; „Training starten" öffnet die erste Übung und scrollt dorthin (Merker in `sessionStorage`, weil die Seite dazwischen neu lädt). **Der Wartezustand strichelt die vorhandene Balkenfarbe**, statt orange zu werden — das sah nach Fehler aus; der Hinweissatz in der Karte entfällt ersatzlos, weil er die Kartenhöhe änderte und die Liste bei jedem Satz springen ließ. **Fokusrahmen im Stepper freigestellt** (3 px Abstand plus `outline-offset: 0`), dazu das Breitenbudget der Satzzeile nachgerechnet und die Staffelung für schmale Geräte zweistufig gemacht. Cache `v17` *(live seit 2026-08-11)* |
| `1.1.1` | Drei Korrekturen aus dem ersten Einsatz von `1.1.0`. **„Erledigt" ist ein Schalter** statt eines Nebeneffekts der Sätze — neue Spalte `workout_log.done` (Vorgabe 1) trennt „protokolliert" von „fertig"; „x/n" zählt `done = 1`, die **Tauschsperre** dagegen die bloße Existenz der Zeile (zwei Sätze Bankdrücken sind zwei Sätze Bankdrücken). Abhaken klappt den Satzblock zu und springt zur nächsten offenen Übung; Ab-wählen löscht die Sätze nicht mehr. **Eine noch leere Satzzeile wird nicht abgeschickt** — „+ Satz" ohne Vorlage lief sonst in ein 422 und markierte die Zeile als fehlerhaft. **Farblogik**: `.saetze-block > .saetze-kopf` schlägt jetzt `.summary-knopf` (gleiche Spezifität, aber später in der Datei — die leise Fassung kam nie an), und **alle** `:hover`-Regeln stehen hinter `@media (hover: hover)`, weil Hover auf dem Touchscreen am zuletzt angetippten Element klebt und die Knöpfe ihre Blautöne tauschen ließ. Cache `v16`, Warteschlange `v3` *(live seit 2026-08-11)* |
| `1.1.0` | **Expertenmodus: satzgenaues Protokollieren**, je Benutzer abschaltbar über `users.expert_mode` (Kontoseite, gesperrt bei laufender Einheit). Die Sätze stehen in der neuen Tabelle `workout_sets`, die über `ON DELETE CASCADE` an `workout_log` hängt — Ab-wählen und Einheit-Löschen räumen sie ohne eigenen Löschpfad mit weg. `workout_log.weight` bleibt und trägt im Expertenmodus den **schwersten** Satz, damit „letztes Gewicht" und Gewichtsverlauf über beide Modi hinweg durchgehen. Die **ganze Satzliste reist als Feld `sets` in der bestehenden `check`-Nutzlast**; der Aufruf ersetzt die Sätze vollständig und bleibt damit idempotent — genau das braucht die Warteschlange, deren `localStorage`-Schlüssel deshalb auf `-v2` geht. In der Trainingsansicht ein aufklappbarer Satzblock (offen ist die erste noch nicht erledigte Position), „+ Satz" belegt Satz k mit Satz k der letzten Einheit vor, Wiederholungen über −/+, Änderungen gehen 800 ms gebündelt raus. Im Verlauf: Spalte „Sätze" bei den Einheiten, Volumen- und Epley-1RM-Kurve mit Näherungs-Hinweis bei den Übungen. Enthält auch den nie gebauten Stand `1.0.21` *(live seit 2026-08-11)* |
| `1.0.21` | Die Symbolübersicht ist wieder von der Wartungsseite verschwunden — sie war ein Werkzeug für die Symbolwahl, kein Bestandteil der App *(nie gebaut, aufgegangen in `1.1.0`)* |
| `1.0.20` | Letzte Korrekturen am Symbolsatz: Die **Langhantel** liegt gestreckt — Scheiben bündig aneinander, weit außen, nur ein Rest Stange darüber hinaus, viel blanke Stange in der Mitte. Der **Kabelzug** war vertikal versetzt: Seine Zeichnung endete auf halber Höhe des viewBox, ihre Mitte lag bei 8.75 statt 12, das Abzeichen zentriert aber den Kasten und nicht die Zeichnung darin — 2,6px zu hoch. Jetzt füllt sie die Höhe aus. Alle sieben Symbole nachgemessen: Abweichung von der Mitte höchstens 0,8px, also unter einem Pixel |
| `1.0.19` | Überarbeiteter Symbolsatz nach der Auswahlrunde: **1.5em statt 1.05em** bei unveränderter Strichstärke 1.8, Kabelzug als Latzugstange, Langhantel mit gestaffelten Scheiben, Maschine mit einer Diagonale aus dem Gewichtsblock statt des abgewinkelten Rahmens. Dazu die Symbolübersicht auf der Wartungsseite und im Training der Hinweis **„Noch kein Gewicht gespeichert"** dort, wo sonst „zuletzt xy kg" steht — vorher blieb die Stelle leer, und leer ist zweideutig: kein Wert vorhanden, oder Wert vergessen? |
| `1.0.18` | Vorübergehende Auswahlhilfe für die Gerätesymbole auf der Wartungsseite: Größe, Strichstärke und je drei Formvarianten pro Gerät, jeweils in einem echten Abzeichen. Nur Entscheidungsgrundlage, ohne Wirkung auf die App |
| `1.0.17` | Vier Punkte aus dem ersten Blick auf `1.0.16`. In der Übungsliste ist der Filterknopf **„Alle" entfallen**: Mit ihm passte die Leiste nicht in eine Zeile (715px von 688px verfügbar), ohne ihn bleiben 63px Luft — gegen breitere Schriften, 18px Schriftgröße und dreistellige Zählstände headless nachgemessen. `?filter=alle` greift weiterhin. Im **Auswahl-Overlay der Planverwaltung** schränken sich die beiden Filter jetzt **gegenseitig** ein: Nach der Wahl einer Muskelgruppe stehen nur noch die Geräte zur Verfügung, für die es dort auch eine Übung gibt, und umgekehrt. Jede der beiden Listen wird ohne ihren eigenen Filter gerechnet, sonst wäre die Einschränkung eine Sackgasse; wird eine Wahl durch die andere ungültig, springt sie auf „alle" zurück und die Liste kommt neu. Der Gerätefilter im Tauschdialog gilt jetzt auch in der **Planverwaltung**, geteilt mit dem Training über `geraetFilterFuellen()`/`geraetGefiltert()` in `assets/app.js`. Und der Knopf **„Dauerhaft im Plan"** im Training verlangt eine **Rückfrage** mit beiden Übungsnamen — er steht neben „Nur diese Einheit", und ein Fehlgriff im Studio fiele erst Wochen später auf; „Nur diese Einheit" fragt bewusst weiterhin nicht |
| `1.0.16` | Nacharbeit zu `1.0.15` nach dem ersten Blick auf den PC: Die **Filterleiste der Übungsverwaltung** stand in drei Zeilen statt einer — die beiden `<select>` erbten das `width: 100%` aus der allgemeinen Formularregel; `.filter-form` ist jetzt selbst ein Flex-Container mit `width: auto` für die Auswahlfelder. „Kabel" heißt **„Kabelzug"** (nur die Beschriftung, der Schlüssel `kabel` bleibt — keine Migration). Der Filtereintrag **„ohne Gerät" ist raus**, in beiden Auswahlfeldern: Seit der Nachpflege kann er keine Treffer mehr bekommen, weil das Feld beim Anlegen *und* beim Bearbeiten Pflicht ist. Serverseitig bleibt `_leer` gültig, damit `?equipment=_leer` von Hand noch greift, falls eine alte Sicherung Lücken mitbringt. Neu im **Tauschdialog des Trainings**: ein Gerätefilter, der rein im Browser auf der bereits geladenen Vorschlagsliste arbeitet — die Auswahl enthält nur Geräte, die auch vorkommen, und entfällt bei weniger als zweien |
| `1.0.15` | **Trainingsgerät** als Pflichtfeld je Übung — sieben Werte als Codeliste in `lib/geraete.php` (Maschine, Multipresse, Kabel, Langhantel, Kurzhantel, Kettlebell, Körpergewicht), angezeigt als Abzeichen mit Symbol in Übungs- und Planverwaltung, im Training und in beiden Dialogen. Die Symbole stehen als SVG-Sprite in `lib/view_geraet_symbole.php`, damit PHP und JS dieselbe Quelle nutzen. Die Übungsliste filtert jetzt nach Muskelgruppe **und** Gerät (kombinierbar, plus „ohne Gerät" für die Nachpflege). In der Planverwaltung ersetzt ein Auswahl-Overlay mit denselben zwei Filtern das Pulldown mit allen aktiven Übungen; die Treffer kommen über die neue Aktion `exercise_picker` und werden mit `vorschlagMarkup()` gerendert — dieselbe Darstellung wie beim Tausch. **Die Tauschlogik selbst ist unverändert:** Vorschläge weiter allein über die primäre Hauptgruppe, weil man meist ausweicht, *weil* ein Gerät besetzt ist |
| `1.0.14` | Nacharbeit an derselben Zeile: In der Planposition hängt die Knopfzeile jetzt am `<li>` statt am Raster darin. Sonst begänne sie erst hinter der Positionsnummer — „Tauschen" stand sichtbar eingerückt, während „Bearbeiten" in der Übungsverwaltung am Rand steht. Die Zeile ist dadurch 26px breiter und bleibt auch auf einem 360px-Telefon einzeilig; der Umschaltpunkt konnte von 25rem auf 24rem herunter, denselben Wert wie im Training |
| `1.0.13` | Übungs- und Planverwaltung bauen die Übungskarte jetzt genauso auf wie das Training: oben Bild und Text nebeneinander, darunter die Knopfzeile über die **ganze** Breite. Vorher trug das Bild beide Zeilen, die Knöpfe begannen also erst hinter ihm — am Handy fehlten der Zeile dessen 72–80px und sie brach um. Dazu schmalere Knöpfe in der Planposition |
| `1.0.12` | Planpositionen zeigen denselben Textblock wie Training und Übungsverwaltung — Name, englischer Name, **alle** Muskelgruppen (primär vorn) und die Ausführung, über die geteilte Klasse `.uebung-text` statt einer eigenen Regel; `VERSION` wird nicht mehr über HTTP ausgeliefert — die Datei hat keine Endung und fiel deshalb durch den `<FilesMatch>`-Filter in `apache-app.conf` (offen seit `1.0.10`, gefunden beim Abgleich gegen die laufende Instanz) |
| `1.0.11` | Positionsnummern in der Planverwaltung vertikal zentriert — CSS-Zähler statt Listenpunkt, weil der Marker an der Textgrundlinie hängt und deshalb am unteren Rand stand; Knopfzeile der Position bricht am Handy um, statt „Entfernen" rechts aus der Karte laufen zu lassen (Fehler seit `1.0.7`, am Desktop unsichtbar) |
| `1.0.10` | Bild groß ansehen auch in Übungs- und Planverwaltung (Dialog als Partial `lib/view_bild_dialog.php` + `bildGrossZeigen()`); Übungszeile als Raster — Bild über beide Zeilen, „Bearbeiten" in der Textspalte, „Archivieren" rot und rechtsbündig; Versionsnummer in der Datei `VERSION`, sichtbar auf der Wartungsseite |
| `1.0.9` | Benutzernamen unabhängig von der Groß-/Kleinschreibung (Index `idx_users_name_nocase` + `COLLATE NOCASE` in der Anmeldung); `.gitignore` gehärtet; Quelltext auf GitHub gesichert |
| `1.0.8` | Zeitlimit und Wiederversuche in `apiFetch`, Warteschlange fürs Abhaken, Verbindungsleiste (§7.4); Benutzername änderbar — selbst und durch Admins, Menüpunkt „Konto" (§6.1, §7.7) |
| `1.0.7` | Planposition mit großem Bild über beide Zeilen, Pfeile mittig, Bilder in den Tauschvorschlägen, Abstand in der Kopfzeile, großes Bild schließt den Dialog |
| `1.0.6` | Ausführung eigene Zeile, kompakte Aktionszeile, Gruppenfilter über Untergruppen und primär-zuerst sortiert, Tauschvorschläge sortiert, Name in der Kopfzeile, Tauschen auch in der Planverwaltung |
| `1.0.5` | Service-Worker-Falle behoben (siehe Fallstrick 12), Baumlinien in der Übungsmaske |
| `1.0.4` | Wartung & Sicherung (§6.5), Einheiten löschbar, PHP-Erweiterung `zip` |
| `1.0.3` | Sieben Punkte aus dem ersten Studio-Training, Wiederholungen entfernt, `[hidden]`-Regel |
| `1.0.2` | Muskelgruppen zweistufig, WebP-Upload, Feld „Ausführung" |
| `1.0.1` | Healthcheck mit Begründung, `ServerName` |
| `1.0.0` | Erstinstallation |
