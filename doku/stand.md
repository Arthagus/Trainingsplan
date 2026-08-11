# Stand des Systems

Der **flüchtige** Teil der Projektdokumentation: was gerade läuft, welche Daten drin sind,
was offen ist. Dauerhaftes Wissen — Architektur, Konventionen, Fallstricke — steht in
`CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.** Sie ist die einzige Stelle mit
Versionsnummern und Zählständen; wenn sie hier falsch sind, sind sie nirgends sonst falsch.

*Letzte Aktualisierung: 2026-08-10*

---

## Ausgerollt

**`trainingsplan:1.0.20`** ist der zuletzt gebaute Stand (2026-08-11). Damit ist das
**Trainingsgerät** vollständig live — Pflichtfeld je Übung, kombinierbare Filter,
Auswahl-Overlay in der Planverwaltung, Gerätefilter in beiden Tauschdialogen — und der
Symbolsatz ist abgenommen.

**Der Arbeitsstand im Repo ist `1.0.21` und noch nicht gebaut.** Inhalt bislang nur: die
Symbolübersicht ist wieder von der Wartungsseite verschwunden. Der Service-Worker-Cache
bleibt auf `v14`; `assets/style.css` und `app.js` sind gegenüber `1.0.19` byteweise gleich.

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
| Benutzer | `Oliver` (id 1, Admin) · `claude` (id 2, Admin) · `Nele` (id 3) — Namen ab `1.0.8` änderbar |
| Pläne | `Oliver`: Push, Pull · `Nele`: Ganzkörper A, Ganzkörper B — je 8 Positionen |
| Trainingseinheiten | 1 (Oliver, Pull, 2026-08-06) |
| Sicherungen | 1 (ZIP mit Bildern, 774 KB, 2026-08-07) |

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

Weitergehende Wünsche stehen als Erweiterungen in `LASTENHEFT.md` §10 — allen voran
satzgenaues Protokollieren, falls sich die Frage nach den Wiederholungen erneut stellt.

## Prüfstand der 18 Abnahmekriterien (`LASTENHEFT.md` §11)

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
| `1.0.21` | Die Symbolübersicht ist wieder von der Wartungsseite verschwunden — sie war ein Werkzeug für die Symbolwahl, kein Bestandteil der App *(noch nicht gebaut)* |
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
