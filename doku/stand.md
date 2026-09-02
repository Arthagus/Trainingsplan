# Stand des Systems

Was **gerade** gilt: laufende Version, Datenbestand, offene Punkte. Kurz zu halten ist
Teil des Zwecks — wer hier blättern muss, findet nichts.

**Die Chronik steht in `doku/historie.md`**: was wann ausgerollt wurde, welche Version was
brachte, was dabei schiefging. Dauerhaftes Wissen — Architektur, Konventionen,
Fallstricke — steht in `CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.**

*Letzte Aktualisierung: 2026-09-02 (nach dem Rollout von `1.4.6`)*

---

## Läuft gerade

| | |
|---|---|
| **Live** | **`trainingsplan:1.4.6`** — am 2026-09-02 eingespielt, **nachgemessen** von außen (`app.js?v=1.4.6`, `style.css?v=1.4.6`); die ausgelieferte `login.php` trägt kein `autofocus` mehr, `login.js` enthält die Fokusabfrage. Einzige Änderung: der Fokus auf `login.php` und `password.php` wird nur noch am Zeigegerät gesetzt, damit der Passwortmanager am Handy überhaupt gefragt wird (`CLAUDE.md`, *Frontend*). **Am Gerät bestätigt:** In Firefox auf dem Pixel 10 schlägt Proton die Zugangsdaten jetzt vor. **In Chrome lag es nicht an der App:** Chrome auf Android reicht Webseiten ab Werk **nicht** an Androids Autofill-Dienst weiter, sondern nur an den Google Passwortmanager — Fremdmanager kommen auf keiner Seite zum Zug. Nach dem Umstellen unter *Einstellungen → Autofill-Dienste → anderen Dienst verwenden* schlägt Proton auch dort vor (bestätigt 2026-09-02). Gilt genauso für die Startbildschirm-Verknüpfung, die auf Chromes Engine läuft. **Die ursprüngliche Meldung vom 2026-09-02 nannte Firefox, meinte aber teils die mit Chrome angelegte Startbildschirm-Verknüpfung** — dass der `autofocus`-Ausbau die Firefox-Seite gelöst hat, ist damit plausibel, aber nicht bewiesen. Richtig ist er unabhängig davon. Davor lief `trainingsplan:1.4.5` — seit dem 2026-09-01, ebenfalls nachgemessen; **nachgemessen** von außen (`app.js?v=1.4.5`, `style.css?v=1.4.5`) **und** von innen: Die Wartungsseite meldet `1.4.5`, Image-Tag und `VERSION` stimmen also überein. Bringt beide Hälften gegen das verschwundene Häkchen — den Warteschlangen-Fix (`eintragAbschliessen()`) und die Sicherheitsstufe beim Beenden. **Am selben Tag als `claude` am echten Container gegengeprüft** (Konto danach wieder zu sperren): Vorlage „Ganzkörper - A / B“ zu mir kopiert, Einheit gestartet, an einer Position zwei Sätze mit `done:false` protokolliert — also genau der Zustand, den das Rennen hinterließ —, an einer zweiten normal abgehakt, eine dritte unberührt gelassen. Die Leiste stand danach auf **1/8**. Das Beenden antwortete `{"ended":57,"nachgetragen":1}`, und der Verlauf zeigt die Einheit mit **2/8**: Die Position mit Sätzen wurde nachgetragen, die ohne Eingaben blieb offen (sonst stünde dort 3/8). **Testeinheit und Testsplit sind gelöscht**, das Konto steht wieder auf null Einheiten und ohne Split. Davor lief `trainingsplan:1.4.4` — seit dem 2026-08-30, ebenfalls nachgemessen. Enthält den Häkchen-Fix aus Fallstrick 13, den Ausbau des einfachen Modus, den Stairmaster, „Erledigt" wieder rechtsbündig und das `MAX()` gegen „10/8". Hergang je Nummer in `historie.md`. Die korrigierte Datenbank vom 30.08. ist **eingespielt** — am 2026-09-01 an der Live-Sicherung nachgemessen: Die Einheiten 50 und 52 tragen keine offene Zeile mehr. **Am 2026-09-01 wurde eine zweite Korrektur eingespielt** (`test/trainingsplan_korrigiert_2026-09-01.db`, zwei Häkchen in den Einheiten 55 und 56 vom 31.08.). Danach von außen geprüft: Anmeldung geht (die Passwort-Hashes aus der Sicherung sind also da), `PRAGMA integrity_check` über die Wartungsseite meldet *„Datenbank in Ordnung — Struktur und Fremdschlüssel geprüft“*, Benutzerliste unverändert `Oliver`/`claude`/`Nele`. **Die zwei korrigierten Zeilen selbst sind von außen NICHT prüfbar** — `history.php` zeigt nur eigene Daten (Fallstrick 15); das müssen Oliver und Nele in ihrem eigenen Verlauf sehen (31.08. → 8/8). Einzelheiten in *Offen*, Punkt 0w. |

**Was auch damit NICHT geprüft ist: die Darstellung** — vor allem zwei Knöpfe nebeneinander in einer Vorschlagskarte und die Intervallzeile am Handy (*Offen*, Punkte 0x und 0y). Davor lief `trainingsplan:1.4.1` — am 2026-08-30 gebaut, danach kam die Rückmeldung zum Anlege-Formular. Davor `1.4.0`, ebenfalls am 2026-08-30 auf Ansage gebaut, und nach der Regel „gebaut heißt ausgerollt" damit draußen. **Nicht nachgemessen**, weil die Rückmeldungen des Benutzers zum Anlege-Formular nur von der laufenden Instanz stammen können. Wer die Nummer *benutzt* — als Rollback-Ziel oder für einen Vergleich —, misst sie vorher mit dem `curl`-Einzeiler unten. Davor lief **`trainingsplan:1.3.2`** — am 2026-08-29 eingespielt und **nachgemessen**: `app.js?v=1.3.2` und `style.css?v=1.3.2`. Damit stimmen Image-Tag und die von der App gemeldete Nummer erstmals seit `1.3.1` wieder überein. Von außen geprüft: `/VERSION`, `/schema.sql`, `/Dockerfile`, `/apache-app.conf`, `/lib/*` (auch die drei neuen Partials), `/data/*` und `/uploads/` liefern **403**, `health.php` von außen 404; `no-cache` auf `assets/*` **und** den Seiten-Skripten (`admin_splits.js` mitgeprüft — die neue Seite fällt unter dieselbe Regel); Sitzungscookie `secure; HttpOnly; SameSite=Lax`; `splits.php` und `admin_splits.php` leiten ohne Anmeldung auf `login.php`. **Am 2026-08-29 angemeldet als `claude` am echten Bestand nachgesehen** (Konto danach wieder zu sperren): Kachelreihenfolge stimmt; `admin_splits.php` zeigt die vier Vorlagen mit **einer** offenen Karte und Pulldown, *Aus einem Benutzer-Split* steht im Leerzustand (jeder Benutzer-Split entspricht inhaltlich schon einer Vorlage), *Splits anderer Benutzer* listet drei, gruppiert nach Nele und Oliver — **genau der Unterschied zwischen gefilterter und ungefilterter Liste, den §6.4 verlangt**. Auf `splits.php` mit einem eigenen Split kein Pulldown sondern `.split-titel`, mit zwei das Pulldown; `?split=` wählt, ein unbekannter Wert **und** die ID einer fremden Vorlage fallen beide auf die erste eigene Karte zurück. `plans.php` führte im Auswahlfeld ausschließlich meine zwei eigenen Splits. **Der Kern der Änderung an einer echten Kopie der Vorlage „Push / Pull“ (16 Positionen): Nur den Plannamen geändert → KEIN Zurücksetzen-Knopf. Danach eine Übung entfernt → Knopf da, `data-namen-ab=1`.** Zurücksetzen ohne Namen holte die Übung zurück und ließ „Mein Tag A“ stehen (`hinzugefuegt:1, umbenannt:0`), mit Namen wurde daraus wieder „Push“ (`umbenannt:1`); danach standen Kopie und Vorlage mit je 16 Positionen deckungsgleich da. Die beiden Testsplits sind gelöscht, der Katalog ist unverändert. **Was auch damit NICHT geprüft ist und offen bleibt: die Darstellung** — ob das Pulldown als Titel gelesen wird und ob Umbenennen- und Reset-Dialog am Handy gut sitzen (*Offen*, Punkt 0z). **Vorgeschichte der Nummer:** `1.3.1` war dieselbe Auslieferung wie das Paket `1.2.23`, nur unter neuem Tag — die `VERSION`-Datei darin sagte weiterhin `1.2.23`, gemessen am 2026-08-28. `1.3.1` ist damit eine ausgelieferte Nummer, die nie in `VERSION` stand; die Lücke `1.2.23` → `1.3.2` in der Datei ist genau dieser Fall und keine übersprungene Version. Wer die Nummer weiterverwendet, misst trotzdem erneut — diese Zeile ist die Notiz und nicht die Quelle |
| **Arbeitsstand** | `1.4.6` — **gebaut am 2026-09-02 auf Ansage**, `deploy/trainingsplan-build-1.4.6.tar.gz`. Nach der Regel *gebaut heißt ausgerollt* damit draußen; **die Live-Nummer ist nicht nachgemessen** — wer sie benutzt, misst vorher mit dem `curl`-Einzeiler unten. **Noch nicht committet.** Eine Änderung: kein `autofocus` mehr auf `login.php` und `password.php`; der Fokus wird in `login.js` / `password.js` gesetzt und nur am Zeigegerät (`matchMedia('(pointer: coarse)')`). Grund ist eine Rückmeldung vom 2026-09-02 — auf einem Pixel 10 bot Proton Pass in Firefox die Zugangsdaten nicht an, und zwar ohne dass auch nur das Symbol über der Tastatur erschien: Firefox auf Android meldet ein beim Laden schon fokussiertes Feld nicht an Androids Autofill-Dienst. Die Regel steht in `CLAUDE.md` unter *Frontend*. **Die Wirkung ist NICHT nachgewiesen** und nur am Gerät prüfbar; belegt ist allein, dass der Fokus am Zeigegerät gesetzt wird und am Touchgerät nicht (unter Node ausgeführt, samt Gegenprobe). Zuletzt committet: `4583b14` mit `1.4.5`. |
| **Rollback-Ziel** | **`trainingsplan:1.4.4`**, falls das Image noch in Portainer steht — ein Schritt zurück, **dasselbe Schema**, und es lief zwei Tage im Studio. Es verliert allein die beiden Korrekturen aus `1.4.5`: Das Rennen in der Warteschlange kommt zurück (ein Häkchen kann wieder verlorengehen), und beim Beenden wird nichts mehr nachgetragen. **Beides sind Datenfehler und keine Anzeigefehler** — anders als bei früheren Rollback-Zielen wäre dieser Schritt zurück also nicht folgenlos. **Tiefer zurück nur im Notfall:** `1.2.11` war lange das Ziel und ist durchgeprüft, kostet heute aber die Ausdauergeräte — die Spalten sind additiv, die Daten überleben, aber `1.2.11` kennt `erfassung` nicht und zeigte eine Laufband-Übung als Kraftübung. Der alte Vorbehalt gegen `1.2.15`–`1.2.21` ist hinfällig: Diese Stände sind seit Wochen in täglicher Benutzung, die offenen Punkte 0/0b/0c betreffen nur die Beurteilung am Gerät. |

**Ein gebautes Paket geht sofort live.** Der Benutzer spielt jede Version, die er bauen
lässt, unmittelbar danach ein — am 2026-08-19 ausdrücklich so festgelegt. Daraus folgt für
die Zählweise: **Sobald `paket_bauen.sh` unter einer Nummer gelaufen ist, ist sie
vergeben**, und die nächste Änderung an etwas, das im Paket steckt, hebt auf die nächste.
Die frühere Rückfrage „ist die Nummer schon draußen?" entfällt, und es entstehen keine
Lücken.

**Die Ausnahme ist, dass der Benutzer es SAGT** — am 2026-08-25 dreimal hintereinander:
`1.2.15` war gebaut, aber noch nicht eingespielt, und es kamen weitere Änderungswünsche.
Die Nummer blieb auf seine ausdrückliche Ansage stehen, das Paket wurde jedes Mal unter
derselben Nummer neu gebaut („ein Paket, das den Rechner nie verlassen hat, gibt seine
Nummer wieder frei").

**Und dann kippte es genauso ausdrücklich in die andere Richtung:** Beim nächsten
Änderungswunsch war `1.2.15` inzwischen doch ausgerollt, und die Nummer war damit vergeben
— `1.2.16`. Die Lehre für beide Fälle ist dieselbe: **Die Regel „gebaut heißt ausgerollt"
ist eine Vorgabe für den Normalfall und keine Tatsachenbehauptung.** Sie ersetzt keine
Auskunft, die der Benutzer von sich aus gibt, und der Arbeitsstand hier ist nur so aktuell
wie die letzte davon — deshalb der Messbefehl darunter.

**Die Version wird trotzdem gemessen, nicht erinnert.** Diese Datei ist die Notiz, nicht
die Quelle — sie stand schon dreimal falsch da, zuletzt am 2026-08-19 mit `1.2.3`, während
`1.2.4` lief. Wer eine Versionsnummer **benutzt** — als Rollback-Ziel, für einen Vergleich,
für eine Aussage darüber, was live steht —, misst sie vorher:

```bash
curl -s https://training.jadefalke.net/login.php | grep -o 'app\.js?v=[0-9.]*'
```

---

## Datenstand

**Hier stehen keine Zählstände.** Wie viele Übungen, Einheiten oder Sätze gerade in der
Datenbank liegen, ändert sich täglich, ist für die Entwicklung ohne Belang und wäre am Tag
nach dem Aufschreiben falsch — die Wartungsseite sagt es jederzeit selbst. Notiert ist nur,
was **strukturell** gilt und worauf man sich beim Bauen und Prüfen bezieht:

| | |
|---|---|
| Benutzer | `Oliver` (id 1, Admin) · `claude` (id 2, Admin, Wartungskonto — siehe unten) · `Nele` (id 3, kein Admin) |
| Splits | Jeder der beiden trainierenden Benutzer hat **einen** persönlichen Split aus der Migration zu `1.2.0`; im Katalog stehen die daraus veröffentlichten Vorlagen |
| Muskelgruppen | Zweistufig — die Gliederung steht in `bestand_gruppen_uebungen.md` |
| Trainingsgeräte | Benutzt werden Maschine, Kabelzug, Kurzhantel und Körpergewicht. **Multipresse, Langhantel und Kettlebell sind bisher unbenutzt** — ein Filter darauf läuft leer, das ist kein Fehler. Seit `1.4.0` gibt es zusätzlich **Laufband, Crosstrainer und Rudergerät** (am 2026-08-30 hat der Benutzer *Laufen* und *Crosstraining* angelegt, das Rudergerät ist noch unbenutzt), seit `1.4.3` **Stairmaster** |
| Erfassungsart | Seit `1.4.0` trägt jede Übung `kraft` oder `ausdauer` (§4). Die Migration setzt **jede** Bestandsübung auf `kraft` — im Live-Bestand ist damit vorerst alles Kraft, bis der Benutzer die ersten Ausdauerübungen anlegt |

Für IDOR-Prüfungen ist die Rollenverteilung das Entscheidende: zwei Admins, ein normaler
Benutzer, und `Nele` ist die einzige, die weder Vorlagen noch fremde Splits anfassen darf.

**Wie der Bestand entstanden ist**, steht in `doku/historie.md`.

---

## Offen

0v. **19 Codekommentare sprechen noch vom „Expertenmodus".**
   Gemessen am 2026-08-30 beim `/init`-Durchlauf: In `index.php`, `index.js`, `api/log.php`,
   `api/swap.php`, `api/auth.php`, `password.php`, `maintenance.php`, `lib/db.php`,
   `lib/training.php` und `assets/app.js` steht der Modus in Kommentaren, als gäbe es ihn
   noch. **Lebende Logik ist keine dabei** — nachgeprüft, alle 21 Fundstellen sind
   Kommentare plus die Migration in `lib/db.php`, die die tote Spalte weiterhin anlegt (das
   ist richtig so, SQLite kann sie nicht fallenlassen).

   Es ist deshalb kein Fehler, sondern eine Falle für den nächsten Leser: Er sucht einen
   Modus, den es seit `1.4.3` nicht mehr gibt. Der frühere Vorbehalt („nicht anfassen, das
   erzwänge `1.4.5`") ist **hinfällig** — `1.4.5` ist seit dem 2026-09-01 ohnehin offen und
   ungebaut. Beim nächsten Eingriff in die jeweilige Datei mitnehmen; ein eigener Durchgang
   nur dafür lohnt weiterhin nicht.

0w. **Den Altbestand auf weitere verlorene Häkchen absuchen.**
   Der Fehler aus Fallstrick 13 steckte seit `1.0.8` im Code und schlug bei jeder fachlichen
   Ablehnung zu. **Ein Fall ist gefunden und repariert** (Einheit 52, 29.08.); ob es weitere
   gibt, hat noch niemand nachgesehen.

   **Am 2026-08-31 ist es erneut passiert** — Neles letzte Einheit steht auf „7/8". Die
   Ursache war eine ZWEITE, unabhängige: Ein Tipp auf *Erledigt*, während der Satz-Aufruf
   derselben Position noch unterwegs war, landete in der Warteschlange und wurde von der
   Antwort des ersten Aufrufs weggeräumt. Behoben in `1.4.5` (`eintragAbschliessen()`,
   Fallstrick 13). **Der Code allein repariert den Bestand nicht** — die betroffene Zeile
   trägt weiterhin `done = 0` und muss wie Einheit 52 von Hand nachgetragen werden
   (`deploy/werkzeuge/`). Solange `1.4.5` nicht ausgerollt ist, kann der Fall erneut
   auftreten.

   **Seit `1.4.5` (live seit 2026-09-01) gibt es eine Stufe darunter:** Beim Beenden trägt
   der Server fehlende Häkchen selbst nach, sobald eine Position Sätze hat (§7.6) — am
   Live-Container nachgemessen, siehe *Läuft gerade*. Das macht die Suche im Altbestand
   nicht überflüssig, es verhindert nur, dass **neue** Fälle entstehen. Und es verschiebt
   die Signatur: Eine Zeile mit `done = 0` **und** Sätzen in einer **beendeten** Einheit ist
   ab jetzt ein Befund aus der Zeit davor, kein neuer.

   **Am 2026-09-01 abgearbeitet — der Punkt ist erledigt.** Der Benutzer hat eine Sicherung
   der Live-Datenbank bereitgestellt (`test/trainingsplan_2026-09-01_21-23-53.db`); geprüft
   wurde damit der **gesamte** Bestand, nicht nur der gemeldete Tag. Ergebnis:

   | | |
   |---|---|
   | 21 Einheiten durchsucht | **zwei** Zeilen mit `done = 0` und Werten |
   | Einheit 55, Oliver, 31.08. | Position 434 *Bizeps-Maschine*, 35 kg, **3 Sätze** → war 7/8 |
   | Einheit 56, Nele, 31.08. | Position 420 *Crunch Kabel*, 20 kg, **3 Sätze** → war 7/8 |
   | 41 Waisenzeilen (`plan_exercise_id IS NULL`) | **alle bereits `done = 1`** — keine anzufassen |
   | Einheiten 50 und 52 (die Fälle vom 27./29.08.) | **null offene Zeilen** |

   Die letzte Zeile beantwortet nebenbei die Frage, die oben unter *Läuft gerade* offenstand:
   **Die korrigierte Datenbank vom 30.08. IST eingespielt worden.**

   Beide Zeilen tragen drei Sätze wie jede andere Übung derselben Einheit — das Muster einer
   abgebrochenen Übung (ein Satz, dann Schluss) liegt nicht vor. Korrigiert in
   `test/trainingsplan_korrigiert_2026-09-01.db`; der logische Dump-Vergleich gegen die
   Sicherung zeigt **genau zwei geänderte Zeilen, darin nur `done` von 0 auf 1** — Gewichte,
   Sätze, Zeitstempel und alle übrigen Tabellen unverändert. Die Datei besteht
   `db_datei_pruefen()`.

   **Über das Web wäre das nicht gegangen** — auch nicht als Admin: `history.php` zeigt
   ausschließlich eigene Daten (Fallstrick 15), und `api/log.php` schreibt nur in eine
   laufende Einheit des Eigentümers (Fallstrick 1). Bleibt also der Weg über die
   Container-Konsole (`deploy/werkzeuge/LIESMICH.md`) oder — wie hier — über
   Sicherung, Korrektur, Wiederherstellen.

   Der Diagnose-Einzeiler in `deploy/werkzeuge/LIESMICH.md` nimmt ein Datumspräfix — mit
   `2026-08` durchsucht er einen ganzen Monat. **Verdächtig ist nur die Kombination
   `done = 0` mit Gewicht oder Sätzen**, und auch die nicht immer: Eine bewusst angefangene
   und nicht beendete Übung sieht genauso aus. Entscheiden kann das nur der Benutzer.

   **Es geht dabei allein um die Zahl „x/n" in der Kopfzeile einer Einheit** — Sätze,
   Gewichte, Verlauf und Vorbelegung waren nie betroffen.

0x. **Das spontane Hinzufügen gegenprüfen** (`1.4.2`, am Gerät zu beurteilen).
   **Die Fachlichkeit ist am 2026-08-30 am Live-System durchgespielt** — siehe *Läuft
   gerade*: anhängen, protokollieren, Gegenprobe auf der Planseite, Verlauf, Aufräumen. Was
   dort nachweislich stimmt, steht unten **durchgestrichen**. Offen bleibt allein die
   Bedienung — und die Auswahlmaske ist im Training zum ersten Mal an einer Stelle, an der
   man mit feuchten Fingern steht.

   | Was | Was passieren muss |
   |---|---|
   | Kasten *Noch eine Übung?* unter der Liste | Steht nach der letzten Übung und vor *Fertig für heute?* — man findet ihn, ohne zu suchen |
   | Auswahl bei laufendem Training | **Zwei** Knöpfe je Vorschlag; sie passen nebeneinander in die Karte und sind auseinanderzuhalten, ohne den Text zu lesen. **Der eigentlich offene Punkt** |
   | *Nur diese Einheit* drücken | Die Ansicht springt nach dem Neuladen nicht an eine unerwartete Stelle. ~~Übung am Ende, Leiste zählt mit~~ — live nachgemessen |
   | Auf der neuen Position protokollieren | Gestrichelter Rand bei schwachem Netz. ~~Werte und Häkchen~~ — live nachgemessen |
   | ~~Danach *Pläne* öffnen~~ | ~~Die Übung steht dort **nicht**, und es erscheint **kein** neues „Auf Vorlage zurücksetzen"~~ — live nachgemessen |
   | ~~Training beenden, neu starten~~ | ~~Im Verlauf trägt die beendete Einheit sie und zählt sie mit~~ — live nachgemessen (1/9) |
   | *Dauerhaft im Plan* | Steht danach auf der Planseite und im nächsten Training, ganz normal umsortierbar — **live noch nicht durchgespielt**, nur der Modus „nur diese Einheit" |
   | ~~Ohne laufendes Training~~ | ~~Nur ein Knopf, und der Text sagt, dass es dauerhaft ist~~ — live nachgemessen |

   **Die Planverwaltung ist mitbetroffen**, auch wenn sich dort fachlich nichts geändert
   hat: Ihre Auswahlmaske ist jetzt dieselbe geteilte. Einmal durchspielen — Filter,
   gegenseitige Einschränkung, Hinzufügen, „Bereits im Plan".

0y. **Die Ausdauergeräte gegenprüfen** (`1.4.0`, am Gerät zu beurteilen).
   Logik, Datenmodell und Antworten sind durchgemessen (siehe *Läuft gerade*); was `curl`
   nicht sieht, ist die Darstellung — und hier sind es **drei neue Zeilen** in der
   Übungskarte plus eine Tabelle mit fünf Spalten.

   | Was | Was passieren muss |
   |---|---|
   | ~~Eine Ausdauerübung anlegen~~ | ~~Reihenfolge der Felder, Abzeichen in der Liste~~ — der Benutzer hat am 2026-08-30 selbst *Laufen* und *Crosstraining* angelegt, beide ohne Muskelgruppe; live nachgesehen |
   | Im Formular auf „Ausdauer" umschalten | Der Muskelgruppen-Block **verschwindet** — ohne Ruckeln, und der Rest des Formulars rückt sauber nach. Zurück auf „Kraft" → er ist wieder da |
   | Eine Ausdauerübung **ohne** Gruppe speichern | Geht durch. Die Zeile in der Liste mahnt danach **nicht** „keine Muskelgruppe zugeordnet" |
   | Eine bestehende Kraftübung auf „Ausdauer" umstellen | Der Block verschwindet sichtbar, und nach dem Speichern sind ihre Muskelgruppen weg — das ist gewollt, muss aber beim Zusehen nachvollziehbar sein |
   | Die drei neuen Gerätesymbole ansehen | Laufband, Crosstrainer und Rudergerät sind auf einen Blick auseinanderzuhalten und sitzen auf derselben Höhe wie die sieben anderen — der Schwerpunkt der Striche gehört auf `y=12` |
   | Trainingsansicht, **einfacher** Modus | Distanz und Zeit stehen in **einer** Zeile nebeneinander, darunter *Pace*. Bei 390 px darf nichts umbrechen und nichts über den Kartenrand ragen. Dass die Zeile mit beiden Feldern und die Pace **entstehen**, ist live nachgemessen (12 km/h · 5:00 /km) — offen ist, wie sie **aussehen** |
   | In das Zeitfeld tippen | Die Pace ändert sich mit — und die Karte wird dabei **weder höher noch niedriger**, die Liste darunter steht still (Fallstrick 19a) |
   | Trainingsansicht, **Expertenmodus** | Die Intervallzeile hat zwei Felder und **kein** −/+. Auf einem schmalen Gerät (unter 400 px) muss sie in eine Zeile passen; „in" und „m" dürfen dabei wegfallen |
   | „+ Intervall" | Der Knopf nennt den Vorschlag (`+ Intervall (5000 m / 26:00)`) und ist nicht zu lang für die Kartenbreite |
   | Verlauf → **Einheiten**, gemischte Einheit | Spaltenkopf „Kennzahl"; die Pace steht in **einer** Zeile und drückt die Tabelle nicht in die Breite |
   | Verlauf → **Übungen**, Ausdauerübung | Fünf Spalten (Datum, Intervalle, Distanz, Zeit, Pace), die Pace-Zelle zweizeilig. Rollt die Tabelle seitwärts, ist das hinnehmbar — aber die Seite selbst darf es nicht |
   | Die drei Kurven | Distanz oben in der Kopfzeile, *Geschwindigkeit* und *Dauer* aufgeklappt darunter — lesbar und nicht zu flach |
   | Tausch an einer Ausdauerposition | Die Liste führt die **anderen Ausdauerübungen**, alphabetisch, ohne Gruppenzeile — und keine Kraftübung |
   | Gegenprobe Kraft | Eine reine Krafteinheit sieht überall **unverändert** aus: Satzzeile mit Stepper, Spalten Volumen/1RM/Gewicht, keine Pace-Zeile; das Anlege-Formular verlangt weiterhin eine primäre Gruppe |

   **Der Zeitanker am Handy ist mitbetroffen:** Das Zeitfeld ist ein Textfeld und fällt
   damit unter `TASTATUR_TYPEN` (Fallstrick 19g). Wer im Satzblock einer Ausdauerposition
   tippt, darf die Seite genauso wenig springen sehen wie bei einer Kraftübung.

0z. **Die aufgeräumte Splits-Seite gegenprüfen** (`1.2.23`, am Gerät zu beurteilen).
   Der Umbau ist an Logik und HTML durchgemessen; was `curl` nicht sieht, ist die
   Darstellung — und drei der Punkte sind neue Kästen, die es vorher nicht gab.

   | Was | Was passieren muss |
   |---|---|
   | *Splits* als Admin **und** als normaler Benutzer öffnen | Beide sehen dasselbe: nur die eigenen Karten, darunter *Vorlage übernehmen*, darunter *Split anlegen*. Kein Katalog, kein „User Splits" |
   | Im Kasten eine Vorlage wählen | Die Planvorschau darunter wechselt mit; *Als Text* zeigt **deren** Text |
   | *Zu mir kopieren* | Die Kopie steht anschließend oben in *Meine Splits* |
   | *Admin → Vorlagen* | Katalog als Karten, darunter *Vorlage anlegen*, *Aus einem Benutzer-Split*, *Splits anderer Benutzer*. Menüpunkt *Admin* hervorgehoben |
   | *Vorlage bearbeiten* an einer Karte | Führt auf `plans.php`; das Auswahlfeld zeigt die Vorlage in eigener Gruppe, darunter die Hinweiskarte mit dem Rückweg |
   | *Splits anderer Benutzer* → *Pläne bearbeiten* | Dasselbe mit der Karte „Das ist der Split eines anderen Benutzers" |
   | *Pläne* über die Kopfzeile | Auswahlfeld nur mit den eigenen Splits, vorausgewählt der **aktive** |
   | *Splits* öffnen (`1.3.2`) | **Eine** Karte, die des aktiven Splits; im Kopf ein Pulldown über die eigenen |
   | Im Pulldown wechseln | Die andere Karte steht sofort da, ohne Nachladen; die Adresse trägt `?split=…` |
   | Danach etwas an dieser Karte tun (z. B. *Duplizieren*) | Nach dem Neuladen steht **dieselbe** Karte offen, nicht wieder die aktive |
   | *Umbenennen* | Überblendeter Dialog mit dem aktuellen Namen, Enter speichert; ein zu langer Name meldet sich **im Dialog** |
   | Ein Benutzer mit nur einem Split | Kein Pulldown, der Name steht als Überschrift |
   | *Admin → Vorlagen* | Dasselbe Bild, ohne „aktiv“ — die erste Vorlage steht offen |
   | *Admin* | Kachelreihenfolge Vorlagen – Übungen – Muskelgruppen – Benutzer – Wartung |
   | Eine Vorlage übernehmen, **nur die Plannamen** ändern | An der Karte erscheint **kein** *Auf Vorlage zurücksetzen* |
   | Danach eine Übung entfernen | Der Knopf erscheint; die Rückfrage ist ein Dialog mit dem Kästchen *Auch die Namen der Pläne …*, leer vorbelegt |
   | Ohne Häkchen zurücksetzen | Übungen kommen zurück, die **eigenen Plannamen bleiben** |
   | Mit Häkchen zurücksetzen | Die Plannamen der Vorlage stehen da |

0. **Die Verbindungsleiste am Gerät gegenprüfen** (`1.2.15`, sobald die Version läuft).
   Rückmeldung vom 2026-08-25: Das kurze Aufpoppen beim Speichern nervt
   und ist unnötig. Es ist ersatzlos weg; die Leiste meldet nur noch, dass der Server
   **wirklich** nicht erreichbar ist, ist dann **rot** und bleibt stehen, bis es wieder geht.

   | Was | Was passieren muss |
   |---|---|
   | Normal trainieren, abhaken, Sätze eintragen | **Oben passiert gar nichts.** Die wartende Zeile erkennt man am gestrichelten Rand, sonst nichts (die Trainingsleiste zählt weiter, sie kommt und geht ja nicht) |
   | Kurzer Aussetzer (WLAN aus und gleich wieder an) | Ebenfalls **keine** Leiste, solange der Wiederversuch durchkommt |
   | Flugmodus an, abhaken | Rote Leiste: „Keine Verbindung zum Server — 1 Eingabe wartet …". Bleibt stehen |
   | Flugmodus aus, nichts anfassen | Die Leiste verschwindet **von selbst**, spätestens nach 15 s |
   | Auf einer anderen Seite (z. B. `splits.php`) Netz kappen, etwas speichern | Rote Leiste, bleibt stehen; nach dem Wiedereinschalten verschwindet sie von selbst |

0b. **Die neuen Zahlen und die Nummer in der Ecke** (`1.2.15`, am Gerät zu beurteilen).
   Die Leiste zählt „x/n beendet · n übersprungen"; jede Karte trägt oben links ihre
   Nummer, eingefasst von zwei Linien.

   | Was | Was passieren muss |
   |---|---|
   | Training starten, nichts eintragen | „0/n beendet · 0 übersprungen", die zweite Null in gedämpftem Grau |
   | Erste Übung abhaken | „1/n beendet" |
   | Eine Übung auslassen und die nächste beginnen | Die ausgelassene bekommt den **orangen** Balken, und die Leiste zählt „1 übersprungen" — in **derselben** Farbe |
   | Zur ausgelassenen zurückgehen und abhaken | Der Zähler geht auf 0 zurück und wird wieder grau |
   | Immer | Die Zahl der übersprungenen stimmt mit der Zahl der orangen Balken überein |
   | Nummer in der Ecke | Bündig in der Ecke, zwei Linien: rechts der Zahl herunter, dann nach links bis an den farbigen Balken — auf **jeder** Karte, in **beiden** Modi, auch unter dem Schleier der erledigten Karte lesbar. Die Ecke, an der die Linie umbiegt, ist **abgerundet** (ab `1.2.17`) |
   | Bild antippen | Öffnet weiterhin Beschreibung und großes Bild, **auch direkt neben der Nummer** — sie nimmt dem Knopf keine Fläche weg |
   | Karte ohne Bild | Die Nummer steht an derselben Stelle |
   | Aktionszeile | Wieder wie zuvor: Tauschen links, Gewichtsfeld mittig (nur Standardmodus), Erledigt rechts |


0c. **Nach dem Beenden** (`1.2.15`). Der Knopf am Ende der Liste ist der Fall,
   um den es geht — vom oberen Knopf aus steht man ohnehin schon oben.

   | Was | Was passieren muss |
   |---|---|
   | Ganz unten „Training beendet" → bestätigen | Die neue Seite steht **ganz oben**, kein Nachrutschen, kein kurzes Aufblitzen des Listenendes |
   | Vorher einen Plan über die Knopfreihe gewählt haben | Die Adresse trägt danach **kein** `?plan=` mehr, und blau markiert ist der **nächste** Plan der Rotation — nicht der eben beendete |
   | Rückfrage „Alle Übungen erledigt — Training beenden?" → *Beenden* | Dasselbe Verhalten; es ist derselbe Weg |
   | Seite mitten im Training von Hand neu laden | Die Scrollposition bleibt, wo sie war — der Sprung gilt nur nach dem Beenden |

1. **Gegenprobe am Gerät zu `1.2.10`** — ausgerollt, aber alle drei Punkte sind rein
   optisch und deshalb **ungeprüft**, bis der Benutzer sie gesehen hat. Mit `1.2.11` sind
   sie weiterhin offen; die beiden Listen lassen sich in einem Durchgang abarbeiten.
   **Der Balken „… wird gespeichert" hat sich mit `1.2.15` erledigt** — er ist ersatzlos
   entfallen (siehe Punkt 0), und damit auch die Frage, ob er beim Aufblitzen etwas
   verschiebt. Zu prüfen bleibt davon nur der Fall, in dem die Leiste noch erscheint:

   | Was | Was passieren muss |
   |---|---|
   | Flugmodus einschalten, abhaken | Die **rote** Leiste erscheint, **darf** dabei einmal schieben und bleibt dann stehen |
   | Weiterspringen bei sichtbarer Leiste | Die nächste Karte landet **unter** der Leiste, nicht dahinter — der Übungsname bleibt lesbar |

   **Dazu der Schleier auf erledigten Karten** (12 %, blendet in 150 ms ein). Zu beurteilen
   ist beides am Gerät, im Studiolicht:

   | Was | Worauf zu achten ist |
   |---|---|
   | Übung abhaken | Die Karte tritt sichtbar zurück, **Name, Gruppen und „zuletzt …" bleiben gut lesbar** — sonst geht der Schleier eine Stufe herunter |
   | Erledigte Karte | „Erledigt" mit blauem Häkchen bleibt klar erkennbar. Reicht das nicht, wird daraus eine weiße Insel (zwei Zeilen CSS, am 2026-08-23 bewusst verworfen) |
   | Häkchen wieder entfernen | Der Schleier verschwindet vollständig, die Karte ist wieder weiß |
   | Expertenmodus, erster Satz ohne Häkchen | **Kein** Schleier — protokolliert ist nicht erledigt (Fallstrick 18) |

   **Und der entdoppelte Kopf der Trainingsseite** (§7.2): Der Planname stand dreimal
   übereinander — als Überschrift, als „Vorgeschlagen: …" und als blauer Knopf. Die
   Überschrift nennt jetzt fest den **Split**, die mittlere Zeile ist weg, und die Zeile
   unter den Knöpfen heißt nur noch „Aktuellen Split wechseln". Am Gerät zu beurteilen:

   | Was | Worauf zu achten ist |
   |---|---|
   | Auswahlzustand | Überschrift = Splitname; darunter Knopfreihe, Erklärsatz, „Training starten", „Aktuellen Split wechseln" — in dieser Reihenfolge |
   | Plan umschalten | Die Überschrift bleibt stehen, nur der blaue Knopf wandert |
   | Laufende Einheit | Der Kasten nennt den Plan („Pull A" läuft seit …) — sonst stünde er nirgends |
   | Split mit nur einem Plan | Die Knopfreihe steht trotzdem, sonst fehlt der Planname |

2. **Gegenprobe zu `1.2.11`/`1.2.12`** (beide am 2026-08-23 gebaut) — der **Tastatur-Anker** (Fallstrick 19g). Gemeldet
   am 2026-08-23: Beim Tippen in ein Gewichtsfeld scrollt die Seite jedes Mal ein Stück
   nach oben, sobald die Tastatur erscheint; am Pixel nicht. Das ist WebKits eigenes
   „Feld über die Tastatur holen" — es lässt sich nicht abschalten, nur aufhalten.

   **Die Bauweise ist auf die Rückfrage des Benutzers hin geändert worden** („ich will
   nicht, dass die Felder wirr hoch und runter springen"): Statt Safaris Bewegung
   *nachträglich* zurückzunehmen — was genau dieses Springen ergeben hätte —, hält der
   Anker die Seite fest, solange die Tastatur hochfährt, und entscheidet danach **ein**
   Mal. Normalfall: gar keine Bewegung. Ausnahmefall: eine statt hin und her.

   **Und wenn schon bewegt wird, dann weit genug** (zweite Rückmeldung vom selben Tag):
   nicht nur 8 px Luft zur Tastaturkante, sondern so weit, dass „+ Satz" und die nächsten
   **drei** Sätze darunter sichtbar bleiben. Nachgerechnet für ein kleines Gerät
   (300 px Sicht, 44 px Zeilenhöhe): geht sich aus, das Feld landet dann 82 px unter dem
   Rand mit 176 px freiem Platz darunter.

   Die Prüfung gehört aufs **iPhone**. Der erste Punkt ist der eigentliche, der zweite die
   Probe darauf, dass die Sicherung greift:

   | Was | Was passieren muss |
   |---|---|
   | Gewichtsfeld der aktiven Karte antippen | Tastatur kommt hoch, **die Seite bleibt stehen** — kein Ruck, auch kein kurzer |
   | Feld ganz unten in der Liste antippen | **Eine** Bewegung — und weit genug: Unter dem Feld müssen „+ Satz" **und Platz für drei weitere Sätze** sichtbar bleiben. Ein Hin und Her wäre der Fehler |
   | Drei Sätze hintereinander eintragen | „+ Satz", ausfüllen, „+ Satz", ausfüllen … **ohne dazwischen von Hand zu scrollen** |
   | Von Feld zu Feld (Wdh. → Gewicht → nächster Satz) | Kein Zucken, die Eingabe bleibt lesbar |
   | Übung abhaken | Der Sprung zur nächsten Übung läuft **wie bisher** — der Anker fasst Kästchen nicht an |
   | Bei offener Tastatur bewusst scrollen | Der Griff bleibt stehen |

   **In derselben Version stecken zwei Kleinigkeiten zum Trainingsstart** (gemeldet am
   2026-08-23: „manchmal passiert zwei Sekunden nichts"). Ursache war nicht das Netz,
   sondern die feste Wiederholpause von 2 s in `apiFetch` — jetzt `[400, 2000, 2000]`, also
   ein Umlauf mehr bei kürzerem Einstieg. Dazu heißt der Knopf während des Startens
   **„Startet …"**. Zu prüfen:

   | Was | Was passieren muss |
   |---|---|
   | Training starten | Der Knopf sagt „Startet …", bis die Seite neu kommt |
   | Der bekannte Aussetzer | Falls er wieder auftritt: spürbar kürzer. Der rote Balken „Keine Verbindung zum Server" darf dabei kurz aufblitzen — er ist der Beleg, dass es der Wiederholweg war |
   | Start ohne Netz (Flugmodus) | Nach ~4,4 s eine Fehlermeldung, und der Knopf heißt wieder „Training starten" |

   **Dazu cacht der Service Worker jetzt auch die Seiten-Skripte** (`index.js` &co.,
   Punkt 1 aus der Trägheits-Analyse vom 2026-08-23). Das spart auf **jeder** Seite eine
   Netzrunde. Zu prüfen ist vor allem, dass ein Rollout weiterhin ankommt:

   | Was | Was passieren muss |
   |---|---|
   | Nach dem Einspielen von `1.2.11` die App öffnen | Alles Neue ist da — Tastatur-Anker, „Startet …". Wenn nicht: **einmal neu laden**, der erste Aufruf holt das Skript noch vom Netz |
   | Danach zwischen Seiten wechseln | Spürbar zügiger, vor allem bei schwachem Empfang |
   | Abmelden | Danach ist **keine** Seite mehr zu sehen — HTML wird weiterhin nie gecacht |

   **Wenn nach einem künftigen Rollout etwas alt aussieht**, ist das die erste Adresse:
   Fallstrick 12, und die Frage lautet, ob die Adresse das `?v=` trägt.

   **Und die Splitauswahl auf `plans.php`** (gemeldet am 2026-08-23 vom Pixel): Chrome auf
   Android zeigt ein `<select>` als Dialog mit Auswahlknöpfen; bei 16 px brachen längere
   Splitnamen dort zweizeilig um. Die Größe steht jetzt am `<option>` und nicht am
   `<select>` — das Feld selbst muss bei 16 px bleiben, sonst zoomt iOS beim Hineintippen.
   Dazu heißt das Feld nicht mehr „Pläne im Split", sondern **„Angezeigter Split"**.

   **Der Schriftgrößen-Teil ist erledigt und zwar als NICHT machbar** (Gegenprobe am Pixel,
   2026-08-23): Der Auswahldialog von Chrome auf Android nimmt die **Android-Systemschrift**
   und ist von CSS nicht erreichbar. Die Regel `option { font-size }` aus `1.2.11` war
   wirkungslos und ist wieder entfernt; Einzelheiten stehen bei den Frontend-Regeln in
   `CLAUDE.md`. **Der Hebel sind die Namen:** Im Dialog passen rund 32 Zeichen in eine
   Zeile, und zwei der vier Splits liegen mit 39 und 41 Zeichen darüber.

   | Was | Was passieren muss |
   |---|---|
   | iPhone: Auswahl öffnen | Unverändert das Systemrad — und beim Antippen **kein Zoom** der Seite dahinter |

   **Und der neue Knopf „Auf Vorlage zurücksetzen"** (§6.4, Fallstrick 24) — die
   folgenreichste Änderung dieser Version, weil sie eine bis dahin festgeschriebene
   Entscheidung umdreht und **Daten verändert**:

   | Was | Was passieren muss |
   |---|---|
   | Eigener Split ohne zugeordnete Vorlage | Auswahlfeld **Vorlage** steht da, Knopf **nicht** |
   | Vorlage zuordnen, die dem Split entspricht | Kein Knopf — es gibt nichts abzugleichen |
   | Eine Übung im eigenen Split ändern | Knopf erscheint |
   | Zurücksetzen | Rückfrage nennt Vorlage, künftige Pläne und beide Folgen. Danach: Pläne wie die Vorlage, **Splitname unverändert**, Knopf weg |
   | Verlauf danach | Die Einheiten stehen **alle** noch da. Übungen, die es in der Vorlage weiter gibt, behalten ihre Zuordnung — auch wenn die Vorlage sie in einen anderen Plan verschoben hat |
   | Während eines Trainings | Knopf gesperrt, Hinweis „Erst die laufende Einheit beenden" |
   | Als anderer Benutzer | An fremden Splits gibt es weder Feld noch Knopf |

   **Vorher eine Sicherung ziehen.** Das Zurücksetzen löscht Planpositionen; bei Übungen,
   die aus der Vorlage verschwunden sind, verlieren bereits protokollierte Sätze ihren
   Bezug zur Position. Die Sätze selbst bleiben stehen — der Weg zurück führt trotzdem nur
   über die Sicherung.

   **Bei Ihrem Datenstand ist zuerst die Zuordnung nötig:** Die persönlichen Splits
   stammen aus der Migration zu `1.2.0`, die Vorlagen wurden **daraus** veröffentlicht —
   es gibt also keine Kopie-Beziehung, die das System kennen könnte. Erst nach dem Setzen
   des Feldes **Vorlage** taucht der Knopf überhaupt auf.

   **Ab `1.2.13`/`1.2.14` steht der Kasten woanders und sieht anders aus** (Rückmeldung
   2026-08-23: „dezenter und unter die Knöpfe", danach „Löschen soll die unterste Zeile
   bleiben"): nicht mehr als volle Zeile über den Knopfreihen, sondern als gerahmter
   Kasten **zwischen** der Verwaltungsreihe und der Abschlusszeile, mit *Vorlage* als
   kleiner Überschrift darüber und dem Auswahlfeld über die ganze Breite.
   Das Feld selbst bleibt bei 16 px — darunter zoomt iOS beim Antippen (Frontend-Regeln
   in `CLAUDE.md`). Am Gerät zu beurteilen, ob es jetzt leise genug ist:

   | Was | Worauf zu achten ist |
   |---|---|
   | Eigene Splitkarte | Reihenfolge von oben: Verwaltungsknöpfe, Kasten *Vorlage*, und als **letzte Zeile** „Löschen". Der Kasten liest sich als eigene Funktion und drängt sich nicht auf |
   | Auswahl antippen (iPhone) | **Kein Zoom** der Seite |
   | Bei Abweichung | „Auf Vorlage zurücksetzen" steht im Kasten unter dem Feld, über die ganze Breite |

   **Neu in `1.2.12`, am Pixel wie am iPhone zu sehen:** *Pläne* über die Kopfzeile
   aufrufen — das Feld *Angezeigter Split* muss auf dem Split stehen, mit dem gerade
   trainiert wird, nicht auf dem ersten der Liste. Und von `splits.php` aus über
   *Pläne bearbeiten* muss weiterhin der **angeklickte** Split erscheinen.

   **Am Pixel dieselben drei Punkte** — dort soll sich nichts verschlechtern: sichtbares
   Feld antippen (keine Bewegung), Feld ganz unten antippen (eine Bewegung), abhaken (wie
   vorher). Die Regel ist auf beiden Geräten dieselbe und hängt am Zustand, nicht am
   Browser: **sichtbar ⇒ nicht scrollen, verdeckt ⇒ einmal scrollen.**

3. **Studio-Erprobung der Splits** (`1.2.0` bis `1.2.4`) — **angekündigt für den
   2026-08-19**. Die `1.1.x`-Reihe ist im Studio erprobt; alles seit `1.2.0` noch nicht.
   Was `curl` nicht sehen kann:

   | Was | Was passieren muss |
   |---|---|
   | `splits.php` am Handy | Drei Abschnitte lesbar, die Vorlagen-Karte mit fünf Knöpfen bricht sauber um |
   | Kontoseite | Fünf Abschnitte (Passwort, Name, Trainingsansicht, Geräte, Abmelden) noch handlich |
   | Kopfzeile | Sechs Punkte ohne die frühere Lücke; *Admin* bleibt auf den Unterseiten hervorgehoben |
   | Plan umbenennen | Die Rotationsvorschau oben zieht **sofort** nach, ohne Neuladen |
   | ⇈ / ⇊ an einer Übung | Vier Pfeile in einer Zeile noch treffsicher |
   | **Vorlagenkarte OHNE Adminrecht** (`1.2.6`) | Eine Zeile: „Zu mir kopieren" links, „Als Text" rechts. **Als Einziges aus `1.2.5`/`1.2.6` noch ungesehen** — der Benutzer ist Admin und bekommt an derselben Karte die volle Verwaltungsreihe. Es braucht ein Konto ohne Adminrecht |

4. **Zwei Abnahmekriterien am Handy** — 16 (ein Gerät abmelden, jetzt auf der Kontoseite)
   und die Gerätehälfte von 19 (Expertenmodus). Die Gegenprobe der *Darstellung* deckt sie
   nicht ab; es sind einzelne, benannte Abläufe.
5. **Kriterium 17 (Restore) ist jetzt vollständig prüfbar** — seit dem 2026-08-11 liegt eine
   frische Sicherung *mit* Bildern vor, und damit erstmals eine, deren Einspielen den
   aktuellen Datenstand wiederherstellen würde statt ihn zurückzudrehen. Bisher fehlte genau
   dieses Stück. Wer es durchspielt, sollte danach ab- und neu anmelden (Fallstrick 14).
6. **`bestand_gruppen_uebungen.md` ist veraltet** — es listet die Übungen einzeln auf, samt
   Zählständen, und beides stimmt längst nicht mehr. Die Datei ist ein Überbleibsel aus der
   Zeit, als sie eine Eingabeanleitung war. Sinnvoller als Nachzählen wäre, sie auf das zu
   kürzen, was sich *nicht* täglich ändert: die Muskelgruppen-Gliederung und die
   Überlegungen zur Tauschregel. Der Übungsbestand steht in der App. **Seit dem 2026-08-16
   ist zusätzlich die Spalte *Ausführung* dort überholt** — sie trägt den Wortlaut der
   Aufbauphase, und der ist inzwischen bei jeder Übung ein anderer. Ein Grund mehr, die
   Tabelle zu streichen statt sie nachzuziehen.
7. **Sechs Fragen an die Übungsdaten** (2026-08-16, beim Überarbeiten der Texte aufgefallen).
   Alle sind Sachentscheidungen, keine Textfragen, und deshalb **unverändert gelassen**:

   | Übung | Was nicht zusammenpasst |
   |---|---|
   | `Hängendes Beinbeugen` | Heißt englisch *Hanging Leg Curl* — eine Kniebeugung. Beschrieben wird aber, wie der **Oberkörper** aus dem Hängen in die Waagerechte gezogen wird, also eine Hüftstreckung. Einer der beiden gehört korrigiert |
   | `45° Rückenstrecker` | Trägt `Gesäß` (primär) und `Beinbeuger (unten)` — die Gruppe `Rückenstrecker` ist **nicht** zugeordnet, obwohl es sie gibt und sie im Namen steht |
   | `Latzug Maschine` vs. `Latzug Kabel` | Dieselbe Bewegung, aber einmal `Bizeps` als Nebengruppe und einmal gar keine |
   | `Beinpresse` | Nebengruppe `Beinbeuger (unten)` an einer Streckbewegung |
   | `Nackenheben Kurzhanteln` | Englischer Name `Shrugs Dumbell` — zwei Fehler auf einmal |
   | `Kabelrudern (sitzend)` | Der alte Satz „möglichst leicht von oben nach unten" war mehrdeutig. Beim Neuschreiben als **Zugrichtung** gelesen; war „leichtes Gewicht" gemeint, stimmt der neue Text an dieser Stelle nicht |

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

---

## Prüfstand der 23 Abnahmekriterien (`LASTENHEFT.md` §11)

**Bestanden: 1–15.** Die Belege — welches Kriterium wann und wogegen geprüft wurde —
stehen in `doku/historie.md`.

**Offen, nur mit echtem Handy prüfbar:**

| # | Was |
|---|---|
| 16 | Ein Gerät unter *Geräte* (Kontoseite) abmelden → dieses verlangt wieder das Passwort, die anderen nicht |
| 18 | Container-Neustart — bei jedem Update implizit passiert, nie ausdrücklich geprüft |
| 19 | Stepper zwischen zwei Sätzen treffen; Flugmodus: gestrichelter Balken, gesperrtes Beenden, Nachholen |
| 21 | Expertenmodus am Gerät — serverseitig vollständig bestanden, die Bedienbarkeit ist offen |

**17 (Restore) teilweise:** Der Prüfpfad ist live bestätigt (ZIP öffnen, Datenbank finden,
`integrity_check`, Tabellenabgleich). Das eigentliche Überschreiben wurde dort bewusst
**nicht** ausgeführt; lokal ist es getestet, inklusive Rückfall bei Fehlern.

**Neu mit `1.2.0` und noch ohne Handy-Beleg: 13 und 14** (Splits, Kopie-Unabhängigkeit,
Migration und Restore). Serverseitig und am Live-System geprüft — siehe Historie.

---

## Gegenprobe schwaches Netz (`1.0.8`)

**Nachschlagestelle, keine offene Aufgabe** (siehe *Offen*): Der Versuch wird nicht eigens
durchgeführt. Was hier steht, ist die Beschreibung des erwarteten Verhaltens — für den Fall,
dass im Studio etwas klemmt und man wissen muss, was eigentlich passieren sollte.

Am Handy oder in den Entwicklerwerkzeugen unter *Network → Offline* bzw. *Slow 3G*. Die
Warteschlange greift **nur bei laufender Einheit** — vorher „Training starten" drücken.

| Was tun | Was passieren muss |
|---|---|
| Netz trennen, eine Übung abhaken | Häkchen **bleibt** stehen, Zeile bekommt einen gestrichelten Rand; nach den Wiederversuchen (gut 4 s) erscheint die **rote** Leiste oben mit der Zahl |
| Noch zwei abhaken | Zähler in der Leiste steigt, „beendet" zählt mit |
| Netz wieder einschalten | Leiste verschwindet, gestrichelte Ränder werden grün — ohne Zutun. Spätestens nach 15 s, auch wenn man gar nichts anfasst |
| Danach neu laden | Alle drei Häkchen sind da |
| Netz trennen, abhaken, App **schließen**, öffnen, Netz an | Das Häkchen wird nachgeholt |
| Mit wartenden Eingaben „Training beendet" drücken | Wird abgelehnt: „Es sind noch Eingaben nicht gespeichert" |
| Mit wartender Zeile „Tauschen" drücken | Wird abgelehnt: „Diese Übung wird gerade gespeichert" |
| Auf *Slow 3G* abhaken | Nach spätestens 12 s entweder Erfolg oder ein sichtbarer Fehler — **nie** ein Häkchen, das minutenlang tot dasteht |

Der letzte Punkt ist der eigentliche Anlass: Genau dieser Zustand war vorher unsichtbar.

---

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

**Seit `1.1.11` ist Sperren der Weg, nicht mehr das Zurücksetzen** (§6.1): Der Benutzer sperrt
das Konto nach einer Arbeitsrunde und gibt es zur nächsten wieder frei. Das Passwort bleibt
dabei konstant — solange die Sperre steht, öffnet es nichts, weder über die Anmeldung noch
über ein angemeldetes Gerät. Damit entfällt die Kette aus Zurücksetzen, erzwungenem Wechsel
und Weitergeben des neuen Passworts, bei der zuletzt niemand mehr wusste, welches gilt.

**So eingerichtet am 2026-08-17:** Der Benutzer hat das Passwort einmal zurückgesetzt, Claude
hat es sofort auf einen 28-stelligen Zufallswert geändert (`must_change_password` steht damit
auf 0) und ihn im Merkspeicher unter
`~/.claude/projects/-home-rezeption-Projekte-Trainingsplan/memory/live-zugang-claude.md`
abgelegt — außerhalb des Repos, Dateirechte `600`. **Im Repo steht es weiterhin nicht** und
soll dort auch nicht landen (siehe „Repo enthält keine Daten").

**Vor einer Anmeldung in einer neuen Sitzung gilt: erst beim Benutzer nachfragen, ob er das
Konto freigibt.** Ist es gesperrt, antwortet die Anmeldung mit HTTP 403 und „Dieses Konto ist
gesperrt" — das ist dann kein Fehler, sondern der Normalzustand.

Verwendungszweck: Anlegen von Stammdaten über `api/*` und Prüfungen von außen.
**Dateneingabe über die Oberfläche macht bewusst der Benutzer** — sonst bleibt
Abnahmekriterium 1 ungeprüft.
