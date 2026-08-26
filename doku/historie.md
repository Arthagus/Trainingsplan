# Historie

Die **Chronik** des Projekts: was wann ausgerollt wurde, was dabei schiefging, welche
Entscheidung von welcher abgelöst wurde. Ausgelagert am 2026-08-19 aus `doku/stand.md`,
das dadurch wieder das ist, was sein Name sagt.

**Diese Datei ist keine Anweisung.** Was hier steht, beschreibt einen *vergangenen*
Zustand — teils einen, der ausdrücklich nicht mehr gilt. Verbindlich sind:

| Frage | Datei |
|---|---|
| Wie arbeite ich in diesem Repo? | `CLAUDE.md` |
| Was muss die App können? | `LASTENHEFT.md` |
| Was läuft gerade, was ist offen? | `doku/stand.md` |

Gelesen wird sie, wenn eine Regel unverständlich erscheint und man wissen will, **welcher
Fehler sie erzwungen hat** — die Kurzbegründung steht bei der Regel, die Vorgeschichte hier.

---

## Versionen im Überblick

Nur als Gedächtnisstütze; die *Begründungen* stehen dort, wo sie hingehören — im Code, in
`CLAUDE.md` unter „Fachliche Fallstricke" oder in `rueckmeldungen_praxistest.md`.

| Version | Was |
|---|---|
| `1.2.22` | **Der Randschnitt aus `1.2.21` ging zu tief — und die Korrektur zeigt, wie man so etwas misst.** Gemeldet aus dem Betrieb: „Bei einigen Bildern wurden von den Personen oben ein Teil vom Kopf abgeschnitten oder ganz am Rand links oder rechts Teile vom Trainingsgerät, z. B. von einer Kurzhantel." Drei Ursachen, die sich addierten. **(1) Die Toleranz von 14 war zu grob:** Die Katalogbilder zeichnen Maschinen in sehr hellem Grau, das damit als Weiß durchging — an einem Bild 219 px Motiv. **(2) Die Suchkopie mit 200 px Kante mittelte dünne Konturen weg:** Ein Kopfscheitel ist dort ein Bruchteil eines Pixels. **(3) Die Zugabe beim Zurückrechnen war fest 1 px**, obwohl ein Pixel der Suchkopie je nach Bild fünf oder mehr Originalpixel abdeckt. Jetzt: Toleranz 8, Suchkopie erst ab 1000 px, Zugabe `ceil($faktor) + 1`. Dazu wird auf **weißem Grund** gesucht — bei PNG und WebP mit Transparenz liefert GD Schwarz, und eine dunkle Hantel vor transparentem Grund wäre als Hintergrund durchgegangen. **Der lehrreiche Teil ist die Messung:** „Sieht besser aus" erkennt einen fehlenden Kopfscheitel nicht. Gemessen wurde der **dunkelste Pixel im weggeschnittenen Rand** — vorher fiel bei 6 von 17 Testbildern echte Zeichnung (bis Helligkeit 35, fast schwarz), danach bei keinem mehr, schlechtester Rest 240 und damit ein blasser Schatten. Bis dahin brauchte es **vier Anläufe am Prüfstand**: Der erste kopierte auf schwarzen Grund und verschluckte dadurch selbst Inhalt, der zweite verglich gegen einen Maßstab, der bei strenger Toleranz auf „nicht schneiden" zurückfiel, der dritte nahm einen zentrierten Rahmen an, den es nicht gibt. Erst der vierte rechnete den Schnittrahmen nach und **prüfte die Rekonstruktion gegen die echte Ausgabe** — bei allen 17 deckungsgleich. **Auf dem Live-System** war der Schaden nicht durch einen erneuten Lauf zu heilen (das gespeicherte Vollbild *ist* der beschnittene Stand): erst `1.2.22` einspielen, dann die Sicherung mit Bildern von vor dem Lauf, dann neu nachschneiden. Dabei fiel auf, dass ein **Restore das Bildverzeichnis nicht leert** — die 114 Dateien des ersten Laufs blieben als Karteileichen liegen und wurden über *Verwaiste Bilder suchen* entfernt *(gebaut und ausgerollt 2026-08-26)* |
| `1.2.21` | **Die Übungsbilder: größer, ohne Rand, und der Bestand lässt sich nachziehen.** Ausgelöst von einer Beobachtung des Benutzers — das Thumbnail auf der Planseite füllte die Zeile nicht — und einer Frage: Was passiert beim Bildwechsel mit dem alten Bild? **(1) 112 px statt 72.** Der Textblock daneben hat vier Zeilen, 72 px sind knapp drei; das Bild stand mittig in einer Zeile, die es nicht ausfüllte. Der Wert stammte aus `1.0.12`, als das Bild noch beide Zeilen der Position trug. **(2) Der einfarbige Rand wird beim Hochladen abgeschnitten.** Gemessen am Live-Bestand lagen bei einem Bild 82 px weiße Fläche links und 132 px rechts, bei einem anderen 109 px links und 7 px rechts — im quadratischen Rahmen sieht man davon vor allem die Leere. Erkannt wird über die vier Ecken: Passen sie farblich nicht zusammen (ein Foto), bleibt das Bild unberührt. Ein hochkantes Motiv wird **im Thumbnail** auf quadratisch aufgefüllt statt beschnitten — Vorgabe des Benutzers: oben und unten nie schneiden, links und rechts gern. **(3) Ein Wartungspunkt holt das für Bestandsbilder nach**, mit neuen Dateinamen: `image.php` liefert mit `Cache-Control: immutable` und einem Jahr Haltbarkeit aus, ein Überschreiben derselben Datei bliebe also unsichtbar (Fallstrick 12, dieselbe Mechanik). **Der eigentliche Ertrag dieser Version steckt aber im Prüfstand:** Der Benutzer hat `php8-gd`, `php8-zip` und `php8-fileinfo` nachinstalliert und einen Ordner mit 19 echten Upload-Bildern angelegt. Bild-Upload war hier **nie** prüfbar, und genau das flog sofort auf: Zwei Fehler in frisch geschriebenem Code kamen erst durch den echten Lauf ans Licht. Die Erfolgsmeldung zählte nur Ersparnisse und meldete „5,7 KB gespart", während das Verzeichnis wuchs (weiße Fläche komprimiert sich fast zu nichts; fällt sie weg, steht mehr Motiv auf derselben Kantenlänge). Und die quadratische Füllung stand im **gespeicherten Bild** statt nur im Thumbnail — dadurch erkannte der nächste Nachschnitt sie als Rand, schnitt sie ab, füllte wieder auf, und fräste sich mit jedem Lauf weiter ins Motiv (229×229 → 227×227, bei jedem Durchgang). Beides behoben; die Lehre — was für die Anzeige gedacht ist, gehört nicht in die Vorlage, aus der man später ableitet — steht bei Fallstrick 16. Geprüft ist seither der ganze Weg: echter Upload über HTTP mit allen 19 Bildern (AVIF und GIF werden nach §5 abgewiesen), Nachschnitt über 17 ungeschnittene Bilder ohne toten Verweis und ohne Karteileiche, und die Zusicherung gemessen statt gefolgert: alle 17 Thumbnails quadratisch oder quer *(gebaut 2026-08-26)* |
| `1.2.20` | **Drei Punkte zur Wartungsseite, alle aus einer Frage des Benutzers entstanden: „Wenn man für eine Übung ein neues Bild hochlädt, was passiert dann mit dem zuvor verwendeten?“** Die Antwort war beruhigend — `api/exercises.php` löscht Bild und Thumbnail, und zwar erst NACH dem erfolgreichen `UPDATE`, solange die Transaktion noch scheitern kann. Die Rückfrage traf trotzdem etwas: Beim **Einspielen einer Sicherung** geht die Datenbank auf einen älteren Stand zurück, die Dateien in `uploads/` nicht. **(1) Verwaiste Bilder** lassen sich deshalb jetzt suchen und entfernen. Vier Entscheidungen dabei: Suchen und Löschen sind **zwei** Knöpfe (ein Knopf, der beides tut, lässt sich nicht zurückdrehen, und die einzige Kopie steckt in einer Sicherung MIT Bildern); gefunden wird **nur das eigene Namensmuster**, Fremdes in `uploads/` wird weder gemeldet noch angefasst; **Dateien unter einer Stunde bleiben tabu**, weil `save_exercise_image()` die Datei schreibt, bevor die Übung in der Datenbank steht — genau dort löschte ein gleichzeitiges Aufräumen ein Bild, das gerade entsteht; und die **Liste kommt nicht vom Client**, der Löschlauf ermittelt sie selbst neu, es geht kein Dateiname über die Leitung. **(2) Die Seite ist umsortiert:** Datenbankpflege und Übungsbilder stehen zwischen *Zustand* und *Sicherung erstellen*. Die Pflegepunkte ändern nichts und schaut man im Vorbeigehen an; das Einspielen ist der folgenreichste Vorgang der App und gehört nicht zwischen sie. **(3) Die Pflege-Knöpfe stehen unter ihrem Text**, nicht mehr je nach Textlänge mal daneben — ein `<button>` ist inline-flex und floss hinter den letzten Satz, bei „WAL zurückschreiben“ war dort noch Platz. Der lehrreiche Teil ist der erste, verworfene Griff: Eine Flex-Spalte am `<dd>` löst das und reißt dabei **jedes** Element auf eine eigene Zeile, auch ein `<code>` mitten im Satz — „wenn die `-wal`-Datei groß ist“ stand danach dreizeilig da. Gelöst im Markup, mit einem eigenen `<p>` je Knopf. Geprüft gegen einen gebauten Bestand aus benutztem Paar, zwei Waisen, einer frischen und einer fremden Datei: gefunden und gelöscht wurden genau die Waisen, der zweite Lauf ging leer aus. Anzeige und Escaping in Node ausgeführt, jede Zusicherung mit Gegenprobe *(gebaut 2026-08-26)* |
| `1.2.19` | **Die Positionsnummer wandert in die Ecke — und ein Durchgang durch den Code fördert zwei Fehler zutage, die niemand gemeldet hatte.** **(1) Die Nummer einer Planposition** stand als eigene Spalte links neben dem Bild, mittig zur Zeile. Sie sitzt jetzt als Kästchen mit abgerundeten Ecken in der oberen linken Ecke, auf einer Höhe mit dem deutschen Namen — dieselbe Anordnung wie im Training seit `1.2.15`, nur mit vollem Rahmen und eigener Fläche: Dort trifft das Kästchen auf den Kartenrand und kommt mit zwei Linien aus, hier steht es frei über dem Thumbnail, wo eine Zahl ohne Grund auf einem dunklen Motiv unlesbar wäre. Der Gewinn sind rund 26 px Breite, und die zählen: Am Handy passen Gerät und Ausführung dadurch in **eine** Zeile, jede Position wird kürzer. Der CSS-Zähler bleibt die Quelle der Zahl — beim Umsortieren zählt der Browser von selbst neu, eine gerenderte Zahl müsste `plans.js` mitpflegen. Erstmals **am gerenderten Bild** geprüft (Firefox headless, 390 px, vorher/nachher) und nicht nur am HTML. **(2) Eine Regression aus `1.2.18`.** Scheitert das Umsortieren der *Pläne*, blieb die Ansicht verschoben — während die Pfeilsperren aus dem Server-Rendering zur alten Reihenfolge gehörten. Bei genau zwei Plänen war danach **kein** Pfeil mehr benutzbar: einer gesperrt, der andere ohne Nachbarn an seiner neuen Stelle. Der zweite Versuch fiel damit aus, und zwar erst, seit die Sperren da sind — vorher trug die Fassung ihren Rückweg mit. `plans.js` nimmt die Verschiebung jetzt zurück. **Zurücknehmen** und nicht nachziehen, weil der Erfolgsfall hier neu lädt: Die verschobene Ansicht ist nur im Fehlerfall zu sehen, und dort ist „nichts bewegt“ die Wahrheit. Innerhalb eines Plans ist es umgekehrt (`posPfeileNachziehen()`), weil dort auch der Erfolg nicht neu lädt. **(3) Zwei schnelle Tipps auf einen Pfeil** schickten zwei `reorder_*` gleichzeitig los. Jeder Aufruf schreibt die **ganze** Reihenfolge, also gewinnt die zuletzt eingetroffene Antwort und nicht die zuletzt gestellte Frage — innerhalb eines Plans unsichtbar, weil dieser Weg nicht neu lädt: Die Datenbank stünde anders sortiert da als der Bildschirm, beide Aufrufe mit `ok`. Jetzt sind die Pfeile der **ganzen Liste** gesperrt, solange gespeichert wird (der zweite Tipp landet sonst auf dem Nachbarn). Sperren statt Warteschlange auf Ansage des Benutzers: „mir ist es lieber wenn schnelle klicks gesperrt werden und man so nicht versehentlich zwei aktionen auslösen kann.“ Punkt 2 und 3 kamen aus einem Review-Durchgang auf Wunsch des Benutzers, nicht aus dem Betrieb. Beide durch Ausführen des echten Klick-Verteilers geprüft, jeder mit Gegenprobe — und zweimal schlug dabei der Prüfstand selbst zu: erst schnitt ein Muster den `else`-Zweig ab (sechs Fälle fielen scheinbar durch), dann wertete die Gegenprobe einen Absturz als Bestehen. Beides genau die Sorte, vor der `CLAUDE.md` warnt *(gebaut 2026-08-26)* |
| `1.2.18` | **Drei Punkte zur Planseite, zwei davon aus dem Betrieb gemeldet (2026-08-26).** **(1) Die Bildausrichtung fehlte in der Übungsauswahl.** Beim Hinzufügen einer Übung zu einem Plan standen alle Vorschaubilder mittig zugeschnitten da, gleich was in `exercises.image_crop` steht. Die Ursache lag nicht am Zuschnitt, sondern eine Ebene davor: `api/plans.php → exercise_picker` holte `e.image_path`, aber nicht `e.image_crop`. `vorschlagMarkup()` wertet das Feld seit `1.1.7` aus — es bekam nur nie eines, und ein fehlender Wert ergibt dieselbe (leere) Klasse wie der Vorgabewert `mitte`. Genau deshalb fiel es über ein Dutzend Versionen nicht auf; beide Tauschfenster waren nie betroffen, weil `tausch_vorschlaege()` die Spalte mitliefert. Dieselbe stille Sorte wie die fehlende Spalte `name_en` in `1.2.5` — die Lehre steht jetzt bei Fallstrick 16. **(2) Die Planseite ist aufgeräumt und gliedert sich wie `splits.php`.** Gemeldet wurde eine „Leerzeile“ über *Rotation* und *Angezeigter Split*; es war keine, sondern der obere Abstand, den `h2` und `label` mitbringen, sobald sie **im** Kasten stehen. Die Überschriften sind deshalb heraus- und darübergerückt — und damit war die Gliederung ohnehin die von `splits.php`: Überschrift, Bestand, Kasten zum Anlegen, weshalb *Plan hinzufügen* unter die Liste wanderte. Ersatzlos weg ist der **Rotationsvorschlag**: der blau hervorgehobene nächste Plan und der Satz „Als Nächstes wird … vorgeschlagen“. Die Begründung des Benutzers trägt weiter als der Einzelfall: „Weil man auf der Seite nur den Trainingsplan modifiziert, da interessiert mich nicht welches Training als nächstes kommt.“ Eine Auskunft gehört dorthin, wo man sie braucht; hier stand sie im Weg. **(3) Die einfachen Pfeile am Rand der Liste sind tot.** Oberste Übung kein ↑, unterste kein ↓, erster Plan kein ↑, letzter kein ↓ — wie es die Doppelpfeile seit `1.2.4` hielten. Das steht **zweimal**, und das ist der lehrreiche Teil: `plans.php` rendert den Zustand, `posPfeileNachziehen()` in `plans.js` zieht ihn nach. Das Umsortieren innerhalb eines Plans ist der einzige Vorgang dieser Seite ohne Neuladen (sonst spränge die Liste unter dem Finger weg) — ohne die zweite Hälfte behielte die alte erste Zeile ihren toten Pfeil, und die neue erste böte einen an, der ins Leere greift. Nachgemessen: Endpunkt per `curl`, gerendertes HTML (auch der Plan mit genau einer Übung, wo beide Pfeile tot sind) und beide JS-Funktionen ausgeführt, jede mit Gegenprobe *(gebaut 2026-08-26)* |
| `1.2.17` | **Zwei optische Punkte.** (1) **Besuchte Links waren lila.** Im Stylesheet stand überhaupt keine Angabe zur Linkfarbe — damit galt das Standard-Stylesheet des Browsers: blau, nach dem ersten Klick lila. Aufgefallen an „Aktuellen Split wechseln" in der Trainingsansicht, betroffen war jeder Link im Fließtext (auch „Zum Training" im Verlauf, „Muskelgruppen" in der Übungsverwaltung und der Hinweis in der leeren Übungsauswahl). Eine Zeile `a { color: var(--akzent) }` genügt: Ein zweiter Selektor für `:visited` ist nicht nötig, weil die Regel des Autors die des Browsers schlägt, gleich welcher Spezifität — Herkunft geht in der Kaskade vor Spezifität. Jeder Link mit eigener Klasse (Kopfzeile, Knöpfe, Filter, Planwahl, Adminkacheln) setzt seine Farbe selbst und bleibt unberührt; alle durchgesehen, nicht angenommen. (2) **Die Ecke an der Übungsnummer ist abgerundet** statt spitz — `--radius`, dieselbe Rundung wie jede andere Box, jeder Knopf und jede Auswahl. Es ist die einzige Ecke des Kästchens, die überhaupt aus zwei gezeichneten Linien entsteht: dort, wo die Linie rechts der Zahl nach links umbiegt. Oben links rundet die Karte selbst; die beiden übrigen sind gar keine Ecken, sondern Stellen, an denen eine Linie auf den Kartenrand bzw. den farbigen Balken trifft. Eine Zeile CSS, aber sie schließt die letzte harte 90-Grad-Ecke der Oberfläche *(gebaut 2026-08-25)* |
| `1.2.16` | **Die Reihenfolge in der Trainingsleiste getauscht**: „2/6 beendet · 1 übersprungen" statt umgekehrt. Auf Ansage des Benutzers, und die Begründung ist dieselbe wie bei jeder Leiste, die man im Sekundentakt anschaut: Der Fortschritt ist das, was man dauernd abliest, und gehört an die Stelle, auf die der Blick zuerst fällt; die übersprungenen sind die Ausnahme. Reine Darstellung — gezählt und markiert wird unverändert. Mitgenommen: ein **Leerzeichen** zwischen den beiden Gruppen. Der Trennpunkt kommt aus einem `::before` im Stylesheet und der sichtbare Abstand aus `gap`, im Markup standen sie dadurch fugenlos aneinander, und ein Screenreader las „beendet1 übersprungen" in einem Wort. Sichtbar ändert es nichts: Leerraum ZWISCHEN Flex-Kindern wird nicht zum eigenen Kind. Am gerenderten HTML nachgesehen, nicht gefolgert. **Eigene Nummer**, weil `1.2.15` zwischen den beiden Runden ausgerollt wurde — bis dahin war dreimal unter derselben Nummer gebaut worden *(gebaut 2026-08-25)* |
| `1.2.15` | **Drei Rückmeldungen vom 2026-08-25, alle zur Trainingsansicht.** **(1) Die Verbindungsleiste meldet nur noch echte Störungen.** Rückmeldung vom 2026-08-25: „Dieses kurze Aufpoppen dieser Zeile nervt mich und ist unnötig." Gemeint war der flüchtige Zustand „n Eingaben werden gespeichert …", der bei **jedem** Abhaken für einen Sekundenbruchteil erschien. Er ist **ersatzlos** weg — er wiederholte nur, was zwei bessere Anzeigen schon sagen (der gestrichelte Rand an der wartenden Zeile, der Wiederholen-Knopf bei einem endgültigen Fehler), und trainierte einem dabei das Wegsehen von genau der Leiste an, die im Ernstfall zählt. Übrig bleibt **ein** Zustand: Server nicht erreichbar, **rot**, und die Leiste bleibt stehen, bis das Problem weg ist. Drei Dinge waren dafür nötig. (1) `verbindung.erreichbar(false)` steht in `apiFetch` jetzt erst im Zweig, der wirklich wirft — vorher meldete **jeder** einzelne Fehlversuch, und ein Aussetzer, den der Wiederversuch nach 400 ms auffängt, hätte die Leiste für ebenjene 400 ms rot aufblitzen lassen: derselbe Ärger, nur in einer schlimmeren Farbe. (2) Wer stehen bleibt, muss das **Ende** der Störung selbst bemerken — `_nachfassenPlanen()` fragt alle 15 s über einen rohen `fetch` auf `api/token.php` nach; roh und nicht `apiFetch`, weil jede Antwort die Erreichbarkeit beweist, auch ein 401, bei dem `apiFetch` mitten im Training zur Anmeldung umleiten würde. In der Trainingsansicht täte das auch die Warteschlange, auf jeder anderen Seite passiert von selbst gar nichts, und auf `online` ist kein Verlass. (3) **`.leiste-schwebt` aus `1.2.10` ist entfallen** — der ganze Schwebe-Kniff war dafür da, dass das Aufblitzen die Seite nicht verschiebt; was nur bei einem echten Zustandswechsel erscheint, darf einmal schieben. Nachgemessen durch Ausführen beider Hälften (Leiste und `apiFetch`) samt Gegenprobe: den alten Zweig wieder eingesetzt, und genau die erwarteten Fälle fielen durch. Siehe Fallstrick 29 **(2) Die Trainingsleiste zählt „x/n beendet · n übersprungen"** statt „x/n erledigt · y offen". In zwei Runden entstanden, und die erste ist der lehrreiche Teil: Zuerst waren es drei Gruppen — „aktiv · offen · beendet" —, weil ein Bruch zwar „wie weit bin ich" beantwortet, aber nicht „wo stehe ich gerade". Der Benutzer hat das noch vor dem Rollout umgestellt, und die Fassung ist besser: **aktiv** war die Übung, an der man ohnehin gerade steht und die man sowieso vor sich hat — **übersprungen** dagegen ist das, was man vergisst. Es sind die offenen Positionen VOR der aktiven, also genau die orangen Balken; die Leiste ist damit eine Merkliste. Gezählt wird deshalb an den Balken selbst (`.zeile-uebersprungen` in `zahlenSchreiben()`) und nicht neu gerechnet: Eine zweite Rechnung liefe auseinander, und oben stünde eine Zahl, die man unten nicht wiederfindet. Ab eins trägt die Zahl das Signalorange der Balken (5,0:1 auf dem dunklen Grund), bei null steht sie gedämpft da. `fortschritt()` in `api/log.php` bleibt deshalb unverändert — „übersprungen" hängt nicht am Datenbestand, sondern an der Reihenfolge der Positionen. Der Bruch „x/n" spart die Breite, die die dritte Gruppe gekostet hätte; „offen" ist ohnehin der Rest. **(3) Jede Übungskarte trägt ihre Nummer in der oberen linken Ecke** — nur die Ziffer, ohne Wort, eingefasst von **zwei** Linien: rechts der Zahl von oben nach unten, dann unter ihr nach links bis an den farbigen Balken. Die anderen beiden Seiten hat die Karte schon (ihr oberer Rand, der Balken links); ein volles Kästchen zöge dort eine zweite Linie neben eine vorhandene. Auch das in zwei Runden: Zuerst ein Kreis mittig in der Aktionszeile zwischen „Tauschen" und „Erledigt". Im Expertenmodus war die Mitte frei (das Gewicht steht in den Sätzen), im **Standardmodus** sitzt dort das Gewichtsfeld — zwei Dinge in einer Mitte sind keine Mitte mehr, und der Benutzer hat es vor dem Rollout in die Ecke verlegen lassen. Die Ecke ist in beiden Modi dieselbe Stelle, und die Aktionszeile ist damit wieder genau die von vorher. `top: 0; left: 0` bezieht sich auf die Padding-Box, also auf die Innenkante genau dieser beiden Ränder — kein Nachbilden der Randstärken im Stylesheet. Kein eigener Grund (die Zahl steht im Innenabstand und nie über dem Bild, nur die Linien streifen dessen abgerundete Ecke — so bleibt es auch auf der roten Fehlerkarte richtig), `pointer-events: none` (das Bild darunter ist ein Knopf, und die Ecke ist die Stelle, die man beim Zielen zuerst trifft) und **kein** `z-index`: So bleibt die Nummer unter dem Schleier der erledigten Karte und tritt mit ihr zurück. **(4) Nach „Training beendet" steht die Seite ganz oben — und ohne `?plan=`.** Den Knopf gibt es auch am ENDE der Liste, man steht also unten, während die neue Seite oben beginnt: Startkasten, Planwahl, Vorschlag. Der Browser stellt beim Neuladen die alte Position wieder her, deshalb `history.scrollRestoration = 'manual'` vor der Navigation und **zweimal** gescrollt auf der neuen Seite (sofort und beim `load`-Ereignis) — ob eine Wiederherstellung vor oder nach dem Skript liegt, ist nicht zugesichert. Der zweite Teil kam beim Nachmessen dazu und war nicht gemeldet: `?plan=` stammt aus der Planwahl **vor** dem Training und ist während der Einheit wirkungslos (der Plan kommt aus `sessions.plan_id`) — danach greift er wieder, und die Seite schlug denselben Plan vor, den man gerade fertig trainiert hatte. An zwei Plänen nachgemessen: ohne Query steht der **nächste** blau, mit `?plan=` der eben beendete. Neu geladen wird deshalb auf `location.pathname` statt über `reload()` (Fallstrick 21). Punkt 2 bis 4 nachgemessen an der laufenden Seite (server-gerendertes HTML und `api/log.php` per curl) **und** durch Ausführen in Node, samt Gegenprobe: das `else` in `fortschrittLokal()` entfernt, und genau die erwarteten Fälle fielen durch (bei der zweiten Fassung von Punkt 2: eine falsche Zustandsklasse gezählt, 6 von 10 Zusicherungen fielen). **Dreimal unter derselben Nummer gebaut** — der Rollout stand noch aus, und der Benutzer hat die Nummer ausdrücklich stehen lassen *(live seit 2026-08-25)* |
| `1.2.13`/`1.2.14` | **Der Kasten *Vorlage* auf der Splitkarte** (nachgetragen). Die Zeile mit dem Auswahlfeld stand über den Knopfreihen und las sich wie eine **Angabe zum Split**, obwohl sie eine selten angefasste Einstellung ist. Sie ist jetzt ein gerahmter Kasten mit eigenem Grund: *Vorlage* als kleine Überschrift, das Feld über die ganze Breite, darunter „Auf Vorlage zurücksetzen". Halbiert ist die **Überschrift**, nicht das Feld — ein `<select>` unter 16 px lässt iOS beim Antippen in die Seite zoomen. Zur Lage in zwei Schritten: `1.2.13` setzte den Kasten ans Ende der Karte, `1.2.14` rückt ihn zwischen Verwaltungsknöpfe und Abschlusszeile. „Löschen" bleibt damit die unterste Zeile — der rote Knopf ist nur so lange unverwechselbar, wie nichts darunter kommt *(gebaut 2026-08-23)* |
| `1.2.12` | **Zwei kleine Punkte, einer davon eine zurückgenommene Annahme.** (1) Die Regel `select option { font-size }` aus `1.2.11` ist wieder **entfernt**: Die Gegenprobe am Pixel zeigte, dass Chrome auf Android den Auswahldialog mit der **Systemschrift** zeichnet — CSS erreicht ihn überhaupt nicht, weder über das `<option>` noch über das `<select>`. Die Regel war damit nicht nur wirkungslos, sondern hatte auf dem Desktop einen Effekt, den niemand bestellt hatte (dort *wirkt* `option`-Styling). Der Befund steht jetzt bei den Frontend-Regeln in `CLAUDE.md` — zu lange Einträge löst man über den Inhalt, nicht über das Stylesheet; im Dialog passen rund 32 Zeichen in eine Zeile. `font-size: 16px` am `<select>` bleibt, es gehört dem geschlossenen Feld (iOS-Zoom). (2) **`plans.php` steht ohne `?split=` jetzt auf dem AKTIVEN Split** des Aufrufers statt auf dem ersten seiner Liste. Wer über die Kopfzeile auf *Pläne* geht, will den bearbeiten, mit dem er trainiert. `aktiver_split()` liefert ausschließlich eigene Splits, der IDOR-Schutz über die Auswahlliste bleibt also unberührt — mit einem zweiten Benutzer ohne Adminrecht nachgemessen. Ein ausdrückliches `?split=` schlägt es weiterhin, darüber führt *Pläne bearbeiten* von `splits.php`. Nebeneffekt: Auch `plans.php` schreibt damit `users.active_split_id` fest, wenn der Wert noch leer war — denselben, den dieselbe Funktion beim nächsten Aufruf ohnehin gewählt hätte *(gebaut 2026-08-23)* |
| `1.2.11` | **Fünf Punkte aus dem Betrieb am Gerät, dazu die erste bewusste Kehrtwende bei einer Fallstrick-Regel.** (1) **Tastatur-Anker** (Fallstrick 19g): WebKit scrollt beim Fokussieren eines Eingabefelds die Seite, damit das Feld über der Tastatur steht — auch wenn es dort schon stünde; am iPhone rutschte deshalb bei jedem Tipp ins Gewichtsfeld die Trainingsansicht ein Stück, am Pixel nicht. Abschalten geht nicht, also wird festgehalten: Phase 1 nimmt jede fremde Scrollbewegung noch im `scroll`-Ereignis zurück (vor dem Bildaufbau, der Zwischenzustand wird nie gezeichnet), Phase 2 entscheidet **einmal**, wenn die Tastatur steht. Auf Rückfrage des Benutzers so gebaut — der naheliegende Weg „warten, dann zurückscrollen" hätte genau das Springen erzeugt, das er nicht wollte. Wird gescrollt, dann großzügig: `ankerReserveMelden()` lässt `index.js` melden, dass „+ Satz" und drei weitere Sätze darunter sichtbar bleiben sollen. (2) **Trägheit beim Trainingsstart** nachgemessen statt geraten: Der Server war es nicht (2 ms bei 40 Einheiten Historie, live so schnell wie ein statisches PNG). Die gemeldeten „manchmal zwei Sekunden nichts" waren die feste erste Wiederholpause in `apiFetch` — `[2000, 5000]` wurde zu `[400, 2000, 2000]`, ein Umlauf mehr bei kürzerem Einstieg, schlimmster Fall 4,4 s statt 7 s. Dazu heißt der Knopf während des Startens „Startet …". (3) **Der Service Worker cacht jetzt auch die Seiten-Skripte**: `index.js` ist so groß wie `app.js` und trägt dieselbe `?v=`-Nummer, lag aber im Wurzelverzeichnis und ging bei **jedem** Seitenaufruf ans Netz — eine volle Runde, bevor die Seite bedienbar war. Bewusst ohne Präcache. (4) **Splitauswahl auf `plans.php`**: Chrome auf Android zeigt ein `<select>` als Dialog mit Auswahlknöpfen; bei 16 px brachen längere Splitnamen zweizeilig um. Die Größe steht jetzt am `<option>`, das Feld selbst bleibt bei 16 px — darunter zoomt iOS. Das Feld heißt nicht mehr „Pläne im Split", sondern „Angezeigter Split". (5) **„Auf Vorlage zurücksetzen"** (§6.4) — und damit die Umkehr von Fallstrick 24, der bis dahin sagte, wer je einen Verweis zwischen Kopie und Vorlage einbaue, nehme der Kopie ihren Zweck. Als Weg, eine verbesserte Vorlage zu übernehmen, taugte „kopier einfach erneut" nicht: `… (2)`, alter Split bleibt stehen, Auswahl umgeworfen. Neue Spalte `splits.vorlage_id` als reine Herkunftsangabe, ausgewertet an **einer** Stelle und nur auf Knopfdruck des Eigentümers; der Knopf erscheint bei Abweichung, gleich von welcher Seite sie kommt. Zurückgesetzt wird durch **Abgleich statt Neuanlage** — sonst hätte `ON DELETE SET NULL` jede protokollierte Übung des Splits von ihrer Position gelöst (Fallstrick 4): Vorhandene Positionen werden splitweit wiederverwendet und wandern per `UPDATE plan_id` mit, wenn die Vorlage sie in einen anderen Plan verschoben hat. Weil die Herkunft erst ab hier mitgeschrieben wird, lässt sie sich an der Karte auch von Hand zuordnen — ohne das bliebe die Funktion für jeden älteren Split wirkungslos *(gebaut 2026-08-23)* |
| `1.2.10` | **Drei optische Punkte aus dem Betrieb, alle am Handy gemeldet.** (1) Der Balken „… wird gespeichert" schob beim Auftauchen die ganze Seite eine Zeilenhöhe nach unten und beim Verschwinden wieder hinauf — bei **jedem** Abhaken. Gemeldet nur vom iPhone, und das ist der lehrreiche Teil: Chromium gleicht solche Layoutänderungen per **Scroll Anchoring** aus, WebKit nicht — am Pixel war derselbe Fehler unsichtbar. Der flüchtige Zustand der Verbindungsleiste belegt im Leisten-Stapel deshalb gar keinen Platz mehr, sondern schwebt unter dessen Unterkante (`.leiste-schwebt`); „Keine Verbindung" läuft weiter im Fluss mit, sonst verdeckte es dauerhaft eine Zeile. `zurAktivenSpringen()` misst dafür die unterste Kante statt `offsetHeight`. (2) **Erledigte Karten liegen unter einem Schleier** (12 % von `--schrift`), damit das Abgehakte im Studio zurücktritt; `opacity` oder ein heller Schleier wären der naheliegende und falsche Griff — sie lassen den **Text** verblassen (14,8:1 → 3,5:1), während die Fläche fast weiß bleibt. Über dem Schleier liegt allein „Erledigt", der Weg zurück. (3) **Der Kopf der Trainingsseite war dreifach:** Der Planname stand als Überschrift, als „Vorgeschlagen: …" und als blauer Knopf. Die Überschrift nennt jetzt fest den **Split**, die mittlere Zeile ist weg, unter den Knöpfen steht nur noch „Aktuellen Split wechseln"; die laufende Einheit nennt dafür ihren Plan, und die Knopfreihe erscheint auch bei nur einem Plan. Reihenfolge im Kasten auf Ansage des Benutzers: Planwahl, Erklärung, Start, Wechsel *(gebaut 2026-08-23)* |
| `1.2.9` | **Der Hinweis steht jetzt in ALLEN drei Vorschlagslisten.** In `1.2.7` hatte ihn nur die Übungsauswahl — der Benutzer klickte im Studio auf *Tauschen* und bekam nichts zu sehen, obwohl die vorgeschlagene Übung im Nachbarplan stand. Ursache war die Bauweise, nicht die Logik: Der Hinweis wurde an der **Aufrufstelle** vor die Knöpfe gesetzt, und es gibt drei davon. Serverseitig trägt ihn jetzt `andere_plaene_eintragen()` (`lib/splits.php`) ein — eine Funktion für `exercise_picker`, `swap_suggestions` und `api/swap.php → suggestions`, wofür `position_laden()` die `split_id` mitladen musste; clientseitig rendert ihn `vorschlagMarkup()` selbst. Damit hängt die Auskunft an der **Übung** und nicht an den Knöpfen darunter, und die nächste Liste bekommt sie ohne Zutun *(live seit 2026-08-21)* |
| `1.2.8` | **Zwei Fenster, in denen eine Liste einen Zustand zeigt, den niemand mehr angefragt hat.** Erstens überholten sich die Abrufe der Übungsauswahl: Es gewann die zuletzt *eingetroffene* Antwort und nicht die zuletzt *gestellte* Frage — Dialog für Plan A öffnen, schließen, gleich darauf Plan B öffnen genügt, und dann stand „Bereits im Plan" und „Schon in …" zum falschen Plan da, bis jemand neu lud. Jeder Ladevorgang trägt seither eine Nummer (`waehlenLauf`), geprüft vor dem Zeichnen **und** vor dem Melden eines Fehlers; das Schließen zählt am `'close'`-Ereignis mit, damit die Escape-Taste denselben Weg nimmt. Zweitens ist zwischen einer gespeicherten Änderung und dem Neuladen die alte Seite voll bedienbar — am Handy leicht eine Sekunde, in der sich ein frischer Dialog über einer alten Seite öffnen lässt. `neuLaden()` sperrt deshalb alle Knöpfe, sobald das Neuladen angestoßen ist; `window.location.reload()` steht in `plans.js` nicht mehr direkt in einer Aktion. Siehe Fallstrick 28 *(live seit 2026-08-21)* |
| `1.2.7` | **„Schon in Ganzkörper A" in der Übungsauswahl** (§6.4). Der gesperrte Knopf sagte nur, dass eine Übung im *bearbeiteten* Plan steht; wer den zweiten Plan eines Splits füllt, will aber wissen, was schon im ersten steht — sonst trainiert er dieselbe Übung zweimal, ohne es zu merken. Ein zurückgenommener Hinweis direkt links neben dem Knopf nennt die betroffenen Pläne in Rotationsreihenfolge. **Verboten wird nichts:** Dieselbe Übung darf bewusst in mehreren Plänen stehen *(live seit 2026-08-21)* |
| `1.2.6` | **Ausrichtung, zwei Stellen.** Die Knopfzeile der Vorschlagskarten (`.vorschlag-knoepfe`) steht **rechtsbündig** — sie gehört zu `vorschlagMarkup()` und gilt damit für beide Tauschdialoge und die Übungsauswahl; am Handy liegt der Daumen rechts, und unter linksbündigem Text verschwimmt eine linksbündige Knopfzeile mit ihm zu einem Block. Auf der **Splitkarte** bekommen die folgenreichen Knöpfe eine **eigene Abschlusszeile** unter der Verwaltungsreihe: links das Aneignen („Zu mir kopieren"), rechts das Abschließende („Löschen"), dazwischen die ganze Kartenbreite als Sicherung gegen den Fehlgriff. Ein eigener Behälter und nicht das Ende der Reihe darüber — nur so bleibt die Lage gleich, egal wie die anderen Knöpfe umbrechen. Ausgerichtet über `.split-abschluss > :last-child { margin-left: auto }` statt `space-between`: Steht nur „Löschen" da, muss es trotzdem rechts stehen. **Hat ein Benutzer an einer Karte nichts zu verwalten** — der Normalfall an einer fremden Vorlage —, entfällt die obere Reihe ganz, und „Zu mir kopieren" und „Als Text" teilen sich eine Zeile *(live seit 2026-08-20)* |
| `1.2.5` | **Der Übungsname steht überall zweisprachig** (§4). Bis `1.2.4` waren es drei von sieben Anzeigestellen — ausgerechnet das Tauschfenster, wo man eine unbekannte Übung sucht, gehörte nicht dazu. Neu ist ein Paar `uebung_name()` / `uebungName()` samt einzeiliger Form `uebung_name_kurz()` für Abzeichen und Verlaufsköpfe; der Umbruch hängt jetzt an der Klasse `.name-en` am Namen selbst statt an `.uebung-text > .matt` — im Tauschfenster und im Verlauf gibt es diesen Behälter nicht. Drei Abfragen in `lib/training.php` mussten `name_en` nachliefern. Im Tauschfenster **entfällt dafür die Ausführung** (`exercises.focus`), auf Wunsch des Benutzers: Dort zählen Name, Muskelgruppe und Gerät, alles Weitere macht die Liste länger, ohne die Wahl zu erleichtern — das Feld reist auch nicht mehr in der Antwort mit. Dazu **„Als Text“** auf `splits.php`: der Split als reiner Text zum Kopieren in einen Chat oder eine Notiz (`split_texte()`), Pläne durch Leerzeilen getrennt, nur die Übungsnamen, der englische in Klammern. Der Text steht **fertig in der Seite** statt nachgeladen zu werden — das Schreiben in die Zwischenablage muss in derselben Benutzeraktion geschehen wie der Klick, sonst verweigern strengere Browser den Zugriff. Nebenbei: „2 Plane“ → „2 Pläne“ in der Planvorschau, und „Löschen“ steht auf der Splitkarte wieder am Ende der Reihe *(live seit 2026-08-20)* |
| `1.2.0` | **Workout-Splits als Ebene über den Plänen** (§4, §6.4). Neue Tabelle `splits`; `splits.user_id IS NULL` = **Vorlage** (Katalog, für alle sichtbar, nur Admin bearbeitet, **niemand trainiert darauf**), sonst persönlicher Split. Zwischen beiden gibt es genau **eine** Verbindung, und die ist eine **Kopie**: „Zu mir kopieren" legt Split, Pläne und Positionen neu an, danach ist jede Seite unabhängig — eine spätere Änderung an der Vorlage wandert ausdrücklich **nicht** nach, und der dauerhafte Tausch eines Benutzers berührt niemanden sonst. Dieselbe Operation dient in beide Richtungen („Als Vorlage veröffentlichen", nur Admin) und für die zweite Fassung („Duplizieren", Name bekommt ` (2)`). **Die Rotation läuft je Split getrennt** und wird weiterhin aus der Historie gelesen: Wer von Push/Pull auf Ganzkörper wechselt und zurückkommt, bekommt wieder *Pull* und nicht *Push*. Neue Seiten `splits.php`/`splits.js` und `api/splits.php`; `admin_plans.php` heißt jetzt **`plans.php`** und ist nicht mehr admin-only — `api/plans.php` verliert sein `require_admin_api()` und prüft stattdessen zentral über `split_zugriff_api()` (`lib/splits.php`). `users.active_split_id` hält die Auswahl. **Datenmigration** in `apply_migrations()`: Jeder Plan ohne `split_id` kommt in einen Split „Meine Pläne" seines Besitzers — idempotent und im Restore-Pfad, damit auch eine eingespielte Altsicherung mitgezogen wird. Setzt auf `1.1.15` auf *(live seit 2026-08-18)* |
| `1.1.6` | Drei Punkte aus der Rückmeldung vom 2026-08-12. **Die Plan-Rotation liest ihren Ausgangspunkt aus der Historie** statt aus `users.last_plan_id` — die Spalte wurde nur beim *Beenden* geschrieben und beim *Löschen* nie zurückgenommen, eine gelöschte Testeinheit verstellte den Vorschlag also dauerhaft. Gezählt wird **jede** Einheit, auch eine leere: Die Rotation richtet sich starr nach der Historie, sauber halten ist Sache des Benutzers. **Ein Training beginnt ausschließlich mit „Training starten"** — vorher hielt `started_at` das *Ende* der ersten Übung fest, und jede Auswertung der Trainingsdauer war dadurch systematisch zu kurz, ohne dass es irgendwo danach ausgesehen hätte. `einheit_sicherstellen()` hat genau einen Aufrufer, `api/log.php` und `api/swap.php` antworten mit 409, und vor dem Start sind „Erledigt", „+ Satz" und das Gewichtsfeld gesperrt; dauerhaft tauschen bleibt möglich, „nur diese Einheit" nicht. **Übersprungene Übungen sind orange** (`#ff6600`), und „aktiv" heißt jetzt „wo gerade protokolliert wird" statt „die erste offene" — `positions_zustaende()` in PHP, `aktiveMarkieren()` in JS. Cache `v19` *(live seit 2026-08-12)* |
| `1.1.5` | Die Wartungsseite zählt zusätzlich die **Sätze** (`workout_sets`) — seit `1.1.0` liegt dort im Expertenmodus das eigentliche Volumen, eine Protokollzeile kann einen Satz tragen oder sechs. Dabei nachgemessen, dass Sätze vollständig gesichert und wiederhergestellt werden: `VACUUM INTO` kopiert die ganze Datei, und ein Restore stellt fehlende Strukturen über `init_schema()` selbst wieder her *(live seit 2026-08-11)* |
| `1.1.4` | **Abgehakte Übungen sind festgeschrieben.** Im Expertenmodus ließen sich Wiederholungen und Gewicht einer erledigten Übung nachträglich ändern und Sätze hinzufügen oder löschen — ein Überbleibsel aus `1.1.0`, als der erste Satz die Übung noch selbst abhakte. Mit dem Schalter aus `1.1.1` ist die Begründung entfallen. Gesperrt wird **serverseitig** (`abgeschlossene_position_schuetzen()`), die ausgegrauten Felder sind nur die Bequemlichkeit davor; **eine unveränderte Nutzlast geht ausdrücklich durch**, sonst zerbräche die Idempotenz der Warteschlange. Gilt jetzt auch für das Gewichtsfeld im einfachen Modus. Dazu nennt die Zeile „zuletzt …" im Expertenmodus die ganze Satzfolge (`zuletzt 3 Sätze (12×45 · 10×45 · 8×50)`) — in **derselben Schreibweise** wie der Kopf des Satzblocks darunter, gebaut von `saetze_zusammenfassung()` bzw. `saetzeZusammenfassung()`. Cache `v18` *(live seit 2026-08-11)* |
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


---

## Rollouts und Versionsnummern bis `1.2.3`

**`1.2.5` und `1.2.6` sind am 2026-08-20 am Gerät gegengeprüft** — der Benutzer meldete
„scheint alles zu passen". Damit sind die Darstellungsfragen erledigt, die `curl` nicht
beantworten konnte: der Umbruch des englischen Namens, der Textdialog samt Zwischenablage
und die neue Ausrichtung der Knopfzeilen. **Offen bleibt genau ein Fall**, und zwar nicht
aus Nachlässigkeit: Die zusammengelegte Zeile an einer Vorlagenkarte erscheint nur **ohne**
Adminrecht, und der Benutzer ist Admin — an derselben Karte bekommt er die volle
Verwaltungsreihe zu sehen. Dafür braucht es ein Konto ohne Adminrecht.

**`1.2.6` ist am 2026-08-20 gebaut und unmittelbar danach ausgerollt worden** — zwei
Ausrichtungswünsche aus demselben Durchgang, beide rein am Stylesheet und am Markup der
Splitkarte. Kein Datenmodell, keine API, keine Migration.

Die zweite Runde entstand aus der ersten: Nachdem „Zu mir kopieren" in die Abschlusszeile
gewandert war, blieb an einer Vorlagenkarte für einen normalen Benutzer eine obere Reihe
mit einem einzigen leisen Knopf darin stehen. Der Benutzer hat das gesehen und die beiden
zusammengelegt — ein Fall, den man am Quelltext nicht bemerkt, weil er nur bei fehlenden
Adminrechten auftritt.

**`1.2.5` ist am 2026-08-20 gebaut und unmittelbar danach ausgerollt worden.** Es sammelte
drei Wünsche aus einem Durchgang durch die Splitseite: den zweisprachigen Übungsnamen an
allen Anzeigestellen, den Textexport eines Splits, und die Pluralkorrektur in der
Planvorschau. Die Korrektur allein hätte **kein** Paket bekommen — der Benutzer hat sie
ausdrücklich für die nächste größere Änderung zurückgestellt, und genau die wurde es dann.

Was `curl` daran nicht prüfen konnte und deshalb in `doku/stand.md` unter *Offen* steht:
wie der zweizeilige Name im Tauschfenster neben dem 72px-Bild umbricht, und ob
`navigator.clipboard` auf dem Gerät des Benutzers greift.

**`1.2.2` ist am 2026-08-18 ausgerollt**, gemessen: `app.js?v=1.2.2`, und am Live-System
durchgeprüft (siehe *Prüfungen am Live-System*).

**`1.2.3` ist am 2026-08-19 ausgerollt**, gemessen: `app.js?v=1.2.3`. Es sammelte
Kleinigkeiten aus dem Betrieb:

- **Geräte und Abmelden sind aus der Kopfzeile verschwunden** und stehen jetzt auf der
  Kontoseite. `devices.php` und `devices.js` sind ersatzlos entfallen; ihre Logik liegt
  am Ende von `password.js`.
- Damit ist auch der `.konto`-Block weg — und mit ihm die **ungleiche Lücke** nach
  *Admin*: Sie stammte aus dem `column-gap: 1rem` von `.kopf`, während die Punkte
  untereinander nur `0.15rem` haben.
- Die Kopfzeile trägt jetzt: Training · Verlauf · Splits · Pläne · Admin · Konto.
- **Vorlagen lassen sich umbenennen und duplizieren.** Beides fehlte in der Oberfläche:
  Der Name stand als fester Text statt als Eingabefeld, und ein Duplikat im Katalog gab
  es gar nicht — `publish` lehnte eine Vorlage als Quelle mit 409 ab. **Duplizieren
  fragt nichts** und legt `… (Kopie)` an; der Weg zu einer Variante ist damit:
  duplizieren, umbenennen, *Vorlage bearbeiten*.


**`1.2.2` ist gebaut** (`deploy/trainingsplan-build-1.2.2.tar.gz`, 2026-08-18) und
wartet auf das Ausrollen. Es räumt die Kopfzeile auf: Die vier Adminpunkte
(Übungen, Muskelgruppen, Benutzer, Wartung) liegen hinter dem neuen Menüpunkt **Admin**
und der Seite `admin.php`, die nur Kacheln zeigt. Training, Verlauf, Splits und Pläne
bleiben oben.

**Zur Nummer `1.2.1`:** Sie ist gebaut **und ausgerollt** worden wie jede andere. Hier
stand zeitweise die Vermutung, sie könnte eine Lücke ohne Gegenstück sein — die stammte aus
der damals noch offenen Frage, ob ein gebautes Paket auch eingespielt wird. Der Benutzer hat
das am 2026-08-19 geklärt: **Ein Paket, das er bauen lässt, spielt er unmittelbar danach
ein.** Damit ist die Reihe `1.2.0` bis `1.2.4` lückenlos, und die Rückfrage entfällt
künftig.

**`1.2.0` ist am 2026-08-18 ausgerollt**, gemessen: `app.js?v=1.2.0`. Die Migration ist
auf dem Live-System durchgeprüft (siehe unten, *Prüfung von 1.2.0 am Live-System*).

**`1.2.1`** enthielt zwei Dinge, die jetzt in `1.2.2` stecken:

- die irreführende Meldung beim Aktivieren eines fremden Splits (getrennt in zwei
  Meldungen);
- den Abschnitt **User Splits** auf `splits.php`: Ein Admin macht aus dem Split
  **jedes** Benutzers eine Vorlage, über ein Pulldown statt über Knöpfe an fremden
  Karten. Bis `1.2.0` war das nur für die eigenen Splits möglich — Neles Ganzkörper
  ließ sich von niemandem veröffentlichen. Der Knopf „Als Vorlage" an der eigenen
  Karte entfällt dafür; Veröffentlichen liegt jetzt an genau einer Stelle.

**`1.2.0` (2026-08-18) ist der Umbau auf Workout-Splits.** Es ist die erste Änderung seit `1.0.0`, die das Datenmodell erweitert
und eine Datenmigration mitbringt — deshalb der Sprung auf die zweite Stelle. Was er
tut, steht unten unter *Änderungen der letzten Versionen*; was er auf der Live-Datenbank
bewirkt, stand im Abschnitt *Was `1.2.0` auf der Live-Datenbank tut* — er bleibt stehen,
weil er zugleich beschreibt, was beim Einspielen einer Altsicherung erneut passiert.

**Das Rollback-Ziel ist `trainingsplan:1.1.15`.** Dieses Image in Portainer stehen
lassen, bis `1.2.0` sich bewährt hat — zusammen mit der Sicherung von vor dem Rollout.

**Nachgemessen, nicht aus der Erinnerung:** `curl` auf `login.php` liefert
`app.js?v=1.2.0` (2026-08-18). Die Asset-Adressen tragen seit `1.1.8` die Version und
sind ohne Anmeldung lesbar — das ist die verlässliche Auskunft darüber, was wirklich
läuft.

- `1.1.11` (2026-08-17) brachte die Sperre samt Migration `users.blocked_at` und ist
  **am Live-System durchgeprüft** — die Belege stehen unten beim jeweiligen Punkt.
- `1.1.13` (2026-08-17): Orange statt Rot für „gesperrt", weiße
  Schrift im Knopf. `1.1.12` steckt darin.
- `1.1.14` (2026-08-17) bündelte drei Dinge: die weiße Schrift auch im
  „Gesperrt"-Abzeichen, die Trainingsleiste am oberen Rand und die wählbare
  Satz-Vorbelegung. Damit lief auch die Migration `users.satz_vorlage`.
- `1.1.15` (2026-08-17) bündelte zwei Rückmeldungen: die Verbindungsleiste rutscht
  unter die Trainingsleiste (die obere Zeile springt beim Abhaken nicht mehr), und im
  Verlauf steht bei den Einheiten das 1RM statt des Maximalgewichts, dazu drei
  getrennte Erklärungen. Reine Anzeige, kein Schema. **Ausgerollt** — bis 2026-08-18
  stand hier fälschlich, sie sei nicht einmal gebaut.

**Die Nummer `1.1.14` ist am 2026-08-17 ein zweites Mal vergeben worden**, und zwar
bewusst: Das erste Paket unter dieser Nummer war zwar gebaut, aber **nie ausgerollt** —
und dann ist Überschreiben richtig, genau dafür warnt `paket_bauen.sh` nur, statt
abzubrechen. Wäre es draußen gewesen, hätte es auf `1.1.15` gehen müssen: Zwei
verschiedene Stände unter einem Namen sind der Fehler, den die Nummer verhindern soll.

**Ebenfalls am 2026-08-17: `1.1.15` wurde einmal ungefragt gepackt.** Ein Paket entsteht
ausschließlich auf ausdrückliche Ansage — das steht in `CLAUDE.md` unter *Deployment* und
war hier übersehen worden. Der damals gezogene Schluss, die Nummer sei deshalb „wieder
frei", hat sich **überholt**: `1.1.15` ist am selben Tag doch noch ausgerollt worden und
lief bis zum 2026-08-18. Der Grundsatz dahinter gilt weiter — maßgeblich ist nicht, ob
`paket_bauen.sh` gelaufen ist, sondern **ob der Tarball in Portainer gelandet ist**.

**Diese Liste war inzwischen ZWEIMAL falsch, und beide Male auf dieselbe Weise.**
Am 2026-08-17 nannte sie `1.1.11` als live, während `1.1.13` lief. Am 2026-08-18 nannte
sie `1.1.14` und behauptete obendrein, `1.1.15` sei gar nicht gebaut — dabei lief genau
die. Beide Male hat nach einem Rollout niemand nachgezogen.

**Die zweite Runde ist die lehrreichere**, weil die Datei fast einen Rückbau verursacht
hätte: Auf ihre Angabe gestützt war `1.1.14` als Rollback-Ziel genannt worden — damit
wären die Korrekturen aus `1.1.15` stillschweigend wieder verschwunden. Aufgefallen ist
es dem Benutzer, nicht der Prüfung.

**Daraus die Regel, härter als vorher:** Wer eine Versionsnummer *benutzt* — für ein
Rollback-Ziel, für einen Vergleich, für eine Aussage darüber, was live steht —, misst
sie vorher. Diese Datei ist die Notiz, nicht die Quelle:

```bash
curl -s https://training.jadefalke.net/login.php | grep -o 'app\.js?v=[0-9.]*'
```

**`1.1.9` ist nie ausgerollt worden.** Die Nummer war durch ein gebautes Paket vergeben,
die Sitzungs-Korrektur hat deshalb auf `1.1.10` aufgesetzt — `1.1.9` steckt vollständig
darin, und `paket_bauen.sh` hat das ältere Paket beim Bauen selbst weggeräumt.

Alles darunter ist die Vorgeschichte, neueste zuerst.

---

## Prüfungen am Live-System

### `1.2.2` (2026-08-18)

Nach dem Rollout mit dem Konto `claude` geprüft. **Alle Schreibzugriffe liefen auf einer
eigenen Kopie und sind wieder entfernt** — der Bestand steht unverändert bei 2 Vorlagen,
2 Splits, 8 Plänen, 9 Einheiten, 77 Protokollzeilen, 135 Sätzen.

| Geprüft | Ergebnis |
|---|---|
| Version | `app.js?v=1.2.2` |
| Alle Seiten | `index`, `splits`, `plans`, `history`, `admin`, `admin_*`, `maintenance`, `devices`, `password` → je 200 |
| Kopfzeile | Training · Verlauf · Splits · Pläne · Admin — die vier Adminpunkte liegen hinter `admin.php`, dessen vier Kacheln stehen |
| Katalog | **Der Benutzer hat beide Splits veröffentlicht:** *Push / Pull* und *Ganzkörper - A / B* stehen als Vorlagen mit je zwei Plänen |
| **Signaturlogik im Ernstfall** | *User Splits* zeigt jetzt den Leerzustand „jeder vorhandene entspricht inhaltlich bereits einer Vorlage" — genau richtig, denn beide Benutzer-Splits sind die Originale der frisch veröffentlichten Vorlagen. Ohne diese Regel stünden sie als Dubletten zum Veröffentlichen bereit |
| Korrigierte Meldungen (neu in `1.2.2`) | Vorlage aktivieren → „Auf einer Vorlage wird nicht trainiert…"; **fremden Split aktivieren → „Das ist der Split eines anderen Benutzers."** Beide 403 |
| Katalog benutzen | Vorlage *Push / Pull* zu `claude` kopiert → Trainingsansicht zeigt „Vorgeschlagen: Push", „Split: Push / Pull". Kopie danach gelöscht, Bestand unverändert |

**Kein Befund.** Die Kleinigkeiten, die daraus wurden, kamen aus dem Blick des Benutzers
auf die Kopfzeile, nicht aus dieser Prüfung — siehe `1.2.3` oben.

---

### `1.2.0` (2026-08-18)

Nach dem Rollout mit dem Konto `claude` von außen geprüft. **Alle Schreibzugriffe liefen
ausschließlich auf einem eigenen Testsplit und sind restlos wieder entfernt** — der
Bestand steht danach unverändert bei 2 Splits, 4 Plänen, 9 Einheiten, 77 Protokollzeilen,
135 Sätzen.

| Geprüft | Ergebnis |
|---|---|
| Version | `app.js?v=1.2.0` |
| Migration | **0 Vorlagen, 2 Splits, 4 Pläne** — je ein Split „Meine Pläne" für Oliver und Nele; `claude` hat keine Pläne und daher keinen Split |
| Bestand | Oliver: *Push* (9 Positionen) → *Pull* (8), Vorschlag *Pull*. Nele: *Ganzkörper A* (8) → *B* (8), Vorschlag *Ganzkörper B*. Reihenfolge und Positionszahl unverändert |
| Historie | 9 Einheiten, 77 Protokollzeilen, 135 Sätze — unangetastet |
| Alle Seiten | `index`, `splits`, `plans`, `history`, `admin_*`, `maintenance`, `devices`, `password` → je 200 |
| Leerzustand | `claude` ohne Split sieht „Noch kein Workout-Split gewählt" samt Knopf zu `splits.php` |
| Kopie | Split dupliziert → neue Plan- und Positions-IDs, gleiche Reihenfolge. Danach in der **Kopie** getauscht und eine Position entfernt → **das Original blieb unverändert** |
| Rückfall | Nach dem Löschen des aktiven Splits (`ON DELETE SET NULL`) fällt die Trainingsansicht sauber auf den Leerzustand zurück |
| Randseiten | Übungsliste nennt Planreferenzen jetzt als `Plan (Benutzer: Split)`; Navigation zeigt *Splits* und *Pläne* |

**IDOR gegen echte fremde Daten**, je einzeln:

| Versuch | Antwort |
|---|---|
| Einheit auf Olivers Plan starten | 404 „Diesen Plan gibt es nicht." |
| In Olivers Plan protokollieren | 403 „Kein Zugriff auf diesen Plan." |
| In Olivers Plan dauerhaft tauschen | 403 „Kein Zugriff auf diesen Plan." |
| Olivers Split als aktiven wählen | 403 — **aber mit falschem Text**, siehe unten |

**Der einzige Befund: eine irreführende Meldung.** `api/splits.php → activate` warf für
*zwei* verschiedene Fälle dieselbe Auskunft — „Auf einer Vorlage wird nicht trainiert —
bitte zuerst *Zu mir kopieren*". Für den Split eines **anderen Benutzers** ist das
doppelt falsch: Er ist keine Vorlage, und kopieren darf man ihn auch nicht. Der
Statuscode war immer richtig; falsch war nur der Satz, den der Benutzer liest. Getrennt
in zwei Meldungen — das ist der Inhalt von `1.2.1`.

Über die Oberfläche war der Fall ohnehin nicht erreichbar (`splits.php` zeigt nur eigene
Splits und Vorlagen); er trifft einen selbstgebauten Aufruf oder eine veraltete Seite.

**Was diese Prüfung NICHT abdeckt** und deshalb beim Benutzer liegt: die Darstellung von
`splits.php` am Handy, der Verlauf mit echten Daten (`history.php` zeigt ausschließlich
eigene — `claude` hat keine), ein echtes Training auf einem Split, und die Sicht eines
**Nicht**-Admins auf `splits.php`/`plans.php`.

---


---

## Was `1.2.0` auf der Live-Datenbank tat

*Beim Einspielen einer Sicherung von vor `1.2.0` läuft dasselbe erneut — siehe
`CLAUDE.md`, Fallstrick 26.*

**Vor dem Rollout lesen.** Der Umbau auf Splits bringt die erste Datenmigration seit
`1.0.0` mit. Beim ersten Start des neuen Images passiert genau das und nichts sonst:

| Schritt | Wirkung auf `training.jadefalke.net` |
|---|---|
| `schema.sql` | legt die Tabelle `splits` an (`CREATE TABLE IF NOT EXISTS`) |
| `apply_migrations()` | `ALTER TABLE plans ADD COLUMN split_id`, `ALTER TABLE users ADD COLUMN active_split_id`, zwei Indizes |
| `splits_nachziehen()` | **3 INSERTs** in `splits` (je ein „Meine Pläne" für Oliver, claude, Nele — sofern der Benutzer Pläne hat), **4 UPDATEs** auf `plans.split_id`, bis zu **3 UPDATEs** auf `users.active_split_id` |

**Kein DELETE, kein DROP, keine ID ändert sich.** Pläne, Positionen, Einheiten,
Protokollzeilen und Sätze bleiben Zeile für Zeile stehen; `workout_log.plan_exercise_id`
bleibt gültig, und die Rotation schlägt unmittelbar danach denselben Plan vor wie vorher —
alle Pläne eines Benutzers liegen in *einem* Split, die Reihenfolge bleibt.

**Trotzdem vorher eine vollständige Sicherung mit Bildern** über `maintenance.php` anlegen
**und herunterladen**. Rückweg im Fehlerfall: altes Image im Stack, Sicherung einspielen —
die neuen Spalten stören eine ältere Fassung nicht, weil sie sie nicht liest.

**Nach dem Rollout, von Hand (je ein Klick):**

1. Die migrierten Splits umbenennen — Oliver: „Meine Pläne" → *Push / Pull*, Nele →
   *Ganzkörper*. Auf `splits.php`, Feld überschreiben, „Umbenennen".
2. Wer sie allen zur Verfügung stellen will, drückt bei seinem Split **„Als Vorlage"** und
   vergibt den Katalognamen. Das ist eine **Kopie** — der eigene Split bleibt, wie er ist,
   und wird von späteren Änderungen an der Vorlage nicht berührt.

Erst damit ist der ursprüngliche Wunsch („die bestehenden Pläne in globale Splits
überführen") vollständig eingelöst. Automatisch geschieht es nicht, und zwar mit Absicht:
Auf einer Vorlage trainiert niemand. Würden Olivers Pläne direkt zur Vorlage, zeigte seine
ganze Historie auf Pläne, die er nicht mehr benutzen darf, und seine Rotation finge in der
Kopie bei null an — nach Push käme wieder Push.

---


---

## Belege zu den Abnahmekriterien


**Bestanden:** 1–15. Die Kriterien 4–13 gegen den Dev-Server durchgespielt, 1 und 15 auf
dem Live-System.

**Kriterien 2, 3 und 6 am Handy bestanden (2026-08-11).** Damit ist der ganze Weg belegt, der
sich nur mit echter Hardware prüfen ließ: PWA über „Zum Startbildschirm hinzufügen"
installiert, Browser geschlossen, App vom Startbildschirm geöffnet — **kein erneuter Login**
(Remember-Me mit Selector/Validator und Rotation, §5). Und das Handy mitten im Training
gesperrt, App neu geöffnet — Häkchen und Fortschritt standen noch (§7.4).

**Kriterium 14 (Upload-Sicherheit), live bestanden am 2026-08-07:** Eine als `.jpg` getarnte
PHP-Datei wird abgelehnt — der Typ kommt aus dem Inhalt, nicht aus der Endung. Ein *gültiges*
PNG mit angehängtem Schadcode wird angenommen, die GD-Re-Enkodierung zeichnet es aber als
JPEG neu; am gespeicherten Bild nachgeprüft, dass kein Code übrig ist.

**Zusätzlich live bestätigt:** `Secure`/`HttpOnly`/`SameSite`-Cookies, HTTPS-Erzwingung,
`Service-Worker-Allowed`, die Sperren für `lib/`, `data/`, `schema.sql` und `Dockerfile`.

**Offen — nur mit echtem Handy prüfbar:**

| # | Was |
|---|---|
| 16 | Ein Gerät unter *Geräte* abmelden → dieses verlangt wieder das Passwort, die anderen nicht |
| 19 | Die Gerätehälfte des Expertenmodus: Stepper zwischen zwei Sätzen treffen, Verhalten im Flugmodus (gestrichelter Balken, gesperrtes Beenden, Nachholen) |

**Kriterium 17 (Restore) — teilweise:** Der gesamte *Prüfpfad* ist auf dem Live-System
bestätigt (ZIP öffnen, Datenbank finden, entpacken, `integrity_check`, Tabellenabgleich) —
über den Upload, der dieselben Schritte durchläuft. Das eigentliche Überschreiben wurde dort
bewusst **nicht** ausgeführt; lokal ist es getestet, inklusive Rückfall bei Fehlern.

**Kriterium 18 (Container-Neustart):** bei jedem Update implizit passiert, nie ausdrücklich
geprüft.

**Kriterium 21 (Expertenmodus, neu mit `1.1.0`) — serverseitig bestanden, am Gerät offen.**
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

**Ein vollständiges Training im Expertenmodus hat inzwischen stattgefunden** (2026-08-11,
9 Positionen, 28 Sätze über knapp zwei Stunden), und die dabei entstandenen Daten sind
fehlerfrei — siehe *Datenstand*. Damit ist der **Datenpfad** am echten Gerät belegt. Ob
Stepper und Satzblock sich dabei gut bedienen ließen, ist eine Aussage, die nur der
Benutzer treffen kann; der **Flugmodus** blieb ungeprüft. Das Kriterium bleibt deshalb
offen, steht aber nicht mehr ganz am Anfang.


---

## Abgeschlossene Punkte

- ~~**Veralteter Kopfkommentar in `api/session.php`**~~ — **erledigt mit `1.2.0`**
   (2026-08-18). Er behauptete noch drei Entstehungswege für eine Einheit, obwohl seit
   `1.1.6` nur „Training starten" übrig ist. Mitgenommen beim ohnehin anstehenden
   Codewechsel, wie hier vorgesehen. Ebenso berichtigt: der Hinweissatz unter dem
   Startknopf in `index.php`, der dem Benutzer dasselbe Falsche erzählte („beginnt ohnehin
   automatisch, sobald die erste Übung abgehakt … wird").

- **`1.1.6` im Studio erprobt** (2026-08-13 und danach). Die Prüfliste dazu — deaktivierte
  Bedienelemente vor dem Start, nur „Dauerhaft im Plan" beim Tauschen, grün/orange beim
  Auslassen einer Übung, der andere Plan als Vorschlag danach — hat sich im Betrieb
  bestätigt; die Rückmeldungen daraus stehen in `rueckmeldungen_praxistest.md` und haben
  zu `1.1.7` und später geführt.

---

## Wie das Lastenheft gewachsen ist

Der Stapel der Nachträge, wie er bis zum 2026-08-19 im Kopf von `LASTENHEFT.md` stand.
Jeder davon hat eine Annahme der Erstfassung abgelöst; **verbindlich ist ausschließlich der
laufende Text des Lastenhefts**, nicht diese Liste.

**Stand:** überarbeitete Fassung vom 2026-08-05. Gegenüber der Erstfassung wurden fünf
Widersprüche aufgelöst (§4 `plan_exercise_id`, §6.3 Soft-Delete, §7.5 Tausch vor Sessionstart,
§7.6 Auto-Ende, §7.3 Gewichts-Fallback) und vier Funktionen ergänzt (§6.5 Wartung, §7.7
Passwortwechsel und Geräteverwaltung, §7.6 Hinweis bei alter Einheit). Die Erstfassung liegt
im Git-Verlauf (Commit „Lastenheft (Originalfassung)").

**Nachtrag:** Muskelgruppen hängen nicht mehr als einzelner Fremdschlüssel an der Übung,
sondern als n:m-Zuordnung mit Primär-Kennzeichnung (§4 `exercise_muscle_groups`,
§6.3 Checkbox-Auswahl, §7.5 Tauschlogik).

**Nachtrag 2026-08-11 (`1.1.3` und `1.1.4`):** Zwei weitere Punkte aus dem Einsatz —
(1) Beim Weiterspringen nach dem Abhaken landete die nächste Karte **unter der
Verbindungsleiste** (sticky, wird genau in dem Moment sichtbar); ihre Höhe wird jetzt
abgezogen (§7.3). (2) **Abgehakte Übungen sind festgeschrieben**: Im Expertenmodus ließen
sich Wiederholungen und Gewicht nachträglich ändern und Sätze hinzufügen oder löschen. Das
war ein Überbleibsel aus `1.1.0`, als der erste Satz die Übung noch selbst abhakte; mit dem
Schalter aus `1.1.1` ist die Begründung entfallen. Gesperrt wird **serverseitig**, mit einer
ausdrücklichen Ausnahme für unveränderte Nutzlasten — sonst zerbräche die Idempotenz der
Warteschlange (§7.4).

**Nachtrag 2026-08-11 (Feinschliff aus dem zweiten Einsatz, `1.1.2`):** Vier Punkte, alle
zur Bedienung am Gerät — (1) **Farbleitsystem am linken Kartenrand**: grün = hier bist du,
blau = erledigt, grau = kommt noch. Grün zieht den Blick, und den soll ziehen, was als
Nächstes zu tun ist (§7.3). (2) **Aktive Position und aufgeklappter Satzblock nur während
eines Trainings**; „Training starten" öffnet die erste Übung und scrollt dorthin (§7.3).
(3) Der **Wartezustand** strichelt die vorhandene Balkenfarbe, statt orange zu werden, und
der Hinweissatz in der Karte entfällt — er veränderte die Kartenhöhe und ließ die Liste bei
jedem Satz springen (§7.4). (4) Der **Fokusrahmen** des Wiederholungsfeldes lag über den
Steppern; die Satzzeile hat jetzt ein nachgerechnetes Breitenbudget.

**Nachtrag 2026-08-11 (Korrekturen aus dem ersten Einsatz, `1.1.1`):** Drei Punkte aus dem
Praxistest von `1.1.0` — (1) **„Erledigt" ist ein Schalter**, kein Nebeneffekt der Sätze:
Wer den ersten Satz einträgt, ist nicht fertig mit der Übung. Dafür trennt die neue Spalte
`workout_log.done` „protokolliert" von „fertig" (§4, §7.4). Abhaken klappt den Satzblock zu
und springt zur nächsten offenen Übung. (2) Eine **noch leere Satzzeile wird nicht
abgeschickt** — sie lief sonst in ein 422 und markierte die Zeile als fehlerhaft, obwohl
nichts falsch war (§7.4). (3) **Farbhierarchie**: Der Satzkopf ist leise, kräftig ist
„+ Satz". Dazu stehen alle `:hover`-Regeln hinter `@media (hover: hover)` — auf einem
Touchscreen blieb Hover am zuletzt angetippten Element kleben und ließ die Knöpfe ihre
Blautöne tauschen.

**Nachtrag 2026-08-11 (Expertenmodus, `1.1.0`):** Satzgenaues Protokollieren war in §9 aus
v1 ausgenommen und in §10 vorgemerkt; es kommt auf Wunsch des Benutzers hinein — als
**je Benutzer abschaltbarer Expertenmodus**, der den einfachen Weg unangetastet lässt.
Damit wird die Zusage von 2026-08-07 eingelöst: Die Wiederholungen kehren **nicht** als
Spalte zurück (die Begründung dagegen gilt unverändert), sondern als eigene Tabelle
`workout_sets` — genau so, wie §4 es angekündigt hatte. `workout_log.weight` bleibt als
**Leitgewicht** bestehen und trägt im Expertenmodus den schwersten Satz; dadurch bleiben
„letztes Gewicht", Gewichtsverlauf und Bestwert über beide Modi hinweg durchgehend. Neu
oder geändert: §4 (`workout_sets`, `users.expert_mode`), §7.3, §7.4, §7.7, §7.8 (Sätze,
Volumen, geschätztes 1RM) und Abnahmekriterium 19.

**Nachtrag 2026-08-07 (Trainingshistorie):** Die Auswertung war in §9 ausdrücklich aus v1
ausgenommen und in §10 vorgemerkt. Sie kommt auf Wunsch des Benutzers doch hinein — die
Daten lagen ohnehin vollständig vor, es fehlte nur die Ansicht. Neu: §7.8, `history.php`.

**Nachtrag 2026-08-07 (nach dem ersten Studio-Training):** Vier Änderungen aus dem
Praxistest — (1) **Wiederholungen entfallen ersatzlos**, Feld und Spalte
`workout_log.reps`: Ein Wert je Einheit kann 12/10/9 über drei Sätze nicht abbilden (§4,
§7.3, §7.4). (2) Das Gewichtsfeld ist nach dem Abhaken **schreibgeschützt**; geändert wird
über Häkchen entfernen → korrigieren → neu abhaken (§7.4). (3) Eine Einheit lässt sich
**ausdrücklich starten** — vorher hielt der Zeitstempel das Ende der ersten Übung fest
statt des Trainingsbeginns (§7.6). (4) Hauptgruppen mit Untergruppen sind in der
Übungsmaske **nicht mehr wählbar** (§6.3). Einzelheiten in
`doku/rueckmeldungen_praxistest.md`.

**Nachtrag 2026-08-06 (Pläne):** Die Obergrenze von zwei Plänen je Benutzer entfällt
(§6.4). Aus der Plan-*Alternation* wird damit eine Plan-*Rotation* entlang der
Sortierreihenfolge (§7.6); bei zwei Plänen verhält sie sich unverändert. Anlass ist eine
mögliche Umstellung auf Push/Pull/Legs. Die Planpflege bleibt ausdrücklich Adminsache —
Benutzer stellen sich keine eigenen Pläne zusammen.

**Nachtrag 2026-08-06:** Die Zielumgebung steht und ist eingerichtet — Subdomain,
Proxy-Ziel und Volume-Pfade sind in §3.1 als verbindliche Werte nachgetragen. Die
Erstfassung ging von einem allgemeinen Setting aus; wo ihre Annahmen dem widersprechen,
wurden sie gestrichen. Betroffen: das Port-Binding (`127.0.0.1` → LXC-IP `10.10.10.2:8066`)
und die Beschreibung der internen Strecke (nicht `localhost`, sondern Proxmox-Internetz).

---

## Wie der Datenbestand entstand

**Die erste Expertenmodus-Einheit ist gegen die Datenbank geprüft** (2026-08-12, aus der
Sicherung von 17:03): Einheit 19, `Oliver`, Plan *Pull*, 2026-08-11 21:45–23:43, alle
**9 von 9** Planpositionen protokolliert, `done = 1` durchgehend, 28 Sätze. Nachgemessen und
ohne Befund: `integrity_check` und `foreign_key_check` sauber, keine verwaisten Sätze, keine
Satzzeile ohne Wiederholungen *und* Gewicht, Satznummern lückenlos `1..n`, **`workout_log.weight`
trägt in jeder Zeile den schwersten Satz**, `plan_id`/`user_id` jeder Zeile stimmen mit der
Einheit überein, keine Position aus einem fremden Plan, kein Tausch. Die Reihenfolge der
`performed_at` weicht von der Planreihenfolge ab (Position 10 nach Position 20) — das ist
Bedienung, kein Datenfehler.

**Nebenbefund:** Einheit 8 (`Nele`, *Ganzkörper B*, gestartet 2026-08-10 23:54, beendet
2026-08-11 11:20) hat **null** Protokollzeilen — gestartet und ohne Eintrag beendet, mit
hoher Wahrscheinlichkeit ein Fehlgriff der Sorte, die `1.1.6` künftig verhindert. Sie zählt
für die Rotation **mit** (siehe dort), Neles Vorschlag bleibt damit *Ganzkörper A*. Der
Benutzer hat angekündigt, Nele zu bitten, die Einheit vor ihrem nächsten Training zu
löschen — dann steht dort wieder *Ganzkörper B*. **Bis dahin absichtlich nicht angefasst:**
Fremde Trainingsdaten stillschweigend aufzuräumen ist nicht Sache der Entwicklung.

### Übungstexte überarbeitet (2026-08-16)

**`focus` und `description` aller 37 Übungen sind neu geschrieben** — auf Ansage des
Benutzers direkt auf der Live-Datenbank, über `api/exercises.php → update`. Der Bestand
zählt damit 37 Übungen, alle aktiv, keine archiviert. Vorher waren **10 Felder leer**
(neunmal die Beschreibung, bei `Nackenheben Kurzhanteln` beide); jetzt keines mehr.

Wonach geändert wurde, kurz: kein Muskel und kein Gerätename in der Ausführung (beides steht
schon daneben), Satzvorgaben wie „hohe Wiederholungen" gehören in die Beschreibung, und die
Beschreibung wiederholt weder Muskelgruppe noch Ausführung. Dazu sieben Übungen mit
Schreibfehlern („Elbogen", „Seit" statt „Seil", „Desto … desto").

**Der Fallstrick dabei, für das nächste Mal:** `aktion_bearbeiten()` in `api/exercises.php`
ist ein **Voll-Ersatz**, kein Feld-Update — es schreibt `name_de`, `name_en`, `description`,
`focus`, `equipment` *und* die Gruppenzuordnung neu. Wer nur die beiden Textfelder schickt,
verliert Name, Gerät und sämtliche Muskelgruppen, und die Antwort lautet trotzdem `ok:true`.
Jeder Aufruf muss die übrigen Felder aus einem vorher gezogenen Abzug wörtlich mittragen.
Das Bild bleibt dagegen von selbst stehen: ohne `$_FILES` und ohne `image_remove` fällt
`$bildSpalte` auf `$altesBild` zurück — bei JSON-Nutzlast ist `$_FILES` zwangsläufig leer.

Nachgemessen statt gefolgert: 37 von 37 Texten wie vorgesehen, **null** Abweichungen bei
Namen, Geräten und Muskelgruppen, Bilder an allen 37, längste Ausführung 54 von 60 erlaubten
Bytes (`EX_FOKUS_MAX`). Der vollständige Wortlaut *vorher und nachher* samt Begründung je
Übung liegt beim Benutzer als veröffentlichte Seite; ein feldgenauer Rückweg existiert.

**Nachtrag 2026-08-17: Querverweise wieder entfernt.** Elf der 37 Beschreibungen
verwiesen auf andere Übungen oder auf Pläne — „Gegenspieler zur Beinpresse und deshalb
in derselben Einheit", „fester Bestandteil des Push-Plans", „die schwerste
Trizepsübung im Bestand". Das war falsch: **Die Beschreibung einer Übung darf nur von
dieser Übung handeln.** Ob zwei Übungen im selben Plan stehen, ist nicht zugesichert
und ändert sich, sobald jemand einen Plan umbaut; ein Satz, der das voraussetzt, wird
dann stillschweigend falsch. Vom Benutzer im Training bemerkt.

Neu formuliert und live eingetragen; der frei gewordene Platz ging an konkrete
Ausführungshinweise. Gegengeprüft mit einer Suche über **alle** 37 Beschreibungen nach
jedem Übungsnamen und nach Plan-/Bestandsbegriffen: keine Treffer mehr.

**Kein Voll-Backup ausgelöst**, obwohl es naheläge: `aktion_backup()` ruft
`backups_aufraeumen()`, das ältere Sicherungen entfernt — ein Nebeneffekt, den ein
Textupdate nicht rechtfertigt. Gesichert wurde stattdessen der Abzug der betroffenen Felder.

---

---

## Versionen im Einzelnen

Neueste zuerst.

**`1.1.15`: 1RM statt Maximalgewicht im Verlauf, und drei getrennte Erklärungen.**
Rückmeldungen vom 2026-08-17.

**Bei den Einheiten** steht am Zeilenende jetzt das geschätzte **1RM** statt des schwersten
Gewichts. Der Grund ist keine Vorliebe: Das schwerste Gewicht steht bei satzgenauen
Einheiten schon in der Satz-Spalte daneben — die Zahl war doppelt. Das 1RM ist die einzige
Kennzahl, die Gewicht **und** Wiederholungen zusammenfasst und sich damit über verschiedene
Wiederholungszahlen hinweg vergleichen lässt.

**Die Spalte hängt am selben Schalter wie die Satz-Spalte** (`$mitSaetzen`): Wo Sätze
protokolliert sind, steht dort `1RM`, sonst weiterhin `Gewicht`. Ohne Wiederholungen lässt
sich kein 1RM schätzen, und eine Spalte, die über eine ganze im einfachen Modus
protokollierte Einheit hinweg nur Striche zeigt, wäre schlechter als die Zahl, die es gibt.

**Bei den Übungen** sind aus einem Fließtext drei Absätze geworden — es sind drei Spalten
und drei Begriffe, und untereinander findet man den gesuchten, ohne den ganzen Block zu
lesen. Dazu zwei Sprachkorrekturen des Benutzers: „Volumen … **es** steigt" (nicht „sie"),
und die Erklärung zum Gewicht heißt jetzt „**der Höchstwert dieser Einheit** — also das
Gewicht des schwersten Satzes" statt der schiefen Gleichsetzung „das Gewicht ist der
schwerste Satz".

*Abweichung vom Wortlaut des Benutzers:* Er schlug „Höchstwert in diesem Tag" vor; die
Tabelle führt aber **eine Zeile je Einheit** samt Uhrzeit. Zwei Trainings an einem Tag
wären zwei Zeilen mit zwei verschiedenen Werten — „dieser Einheit" ist deshalb genauer und
ebenso natürlich.

**Nachgemessen** am Dev-Server mit zwei Einheiten derselben Übung, einer mit Sätzen
(12×40 · 10×40 · 9×45) und einer ohne (50 kg):

| Ansicht | Kopf | Zeile |
|---|---|---|
| Einheiten, ohne Sätze | Übung \| Gewicht | Bankdrücken \| 50 kg |
| Einheiten, mit Sätzen | Übung \| Sätze \| **1RM** | Bankdrücken \| 12×40 10×40 9×45 \| **58,5 kg** |
| Übungen | Datum \| Sätze \| Volumen \| 1RM \| Gewicht | … \| 1285 kg \| 58,5 kg \| 45 kg |

Beide Zahlen von Hand gegengerechnet: 1RM = 45 × (1 + 9/30) = **58,5**, Volumen =
480 + 400 + 405 = **1285**. Die drei Erklärungen rendern als drei getrennte Absätze.

---

**`1.1.15` bringt außerdem: die Verbindungsleiste rutscht unter die Trainingsleiste.** Rückmeldung aus dem
Betrieb, unmittelbar nach dem Rollout von `1.1.14`: Beim Abhaken einer Übung blitzt die
Verbindungsleiste kurz auf — und schob dabei die Trainingsleiste nach unten und gleich
wieder hinauf. Ausgerechnet die Zeile, die man ständig abliest, zappelte bei jeder Eingabe.

Ursache war meine Sortierung in `1.1.14`: Ich hatte die Verbindungsleiste **oben**
eingehängt, mit der Begründung „ist das Netz weg, ist das die wichtigste Information auf
dem Bildschirm". Das ist als Priorität richtig und als Reihenfolge falsch.

**Die Reihenfolge im Stapel geht nach Beständigkeit, nicht nach Wichtigkeit** — oben, was
dauerhaft dasteht, unten, was kommt und geht. Das ist dieselbe Regel, die schon
`.zeile-wartet` auf einen gestrichelten Rand reduziert hat: Was sich von selbst ändert,
darf nichts verschieben. Sichtbar und sticky bleibt die Verbindungsleiste unverändert, sie
sitzt nur eine Zeile tiefer. Geändert hat sich genau ein Aufruf in `assets/app.js`
(`insertBefore` → `appendChild`); `zurAktivenSpringen()` misst weiterhin den ganzen Stapel
und braucht nichts.

**Geprüft** mit der echten `verbindung._element()` aus `assets/app.js`, ausgeführt gegen
einen nachgebildeten Stapel, in dem die Trainingsleiste schon steht: Ergebnis
`training-leiste → verbindungs-leiste`. Nicht am Quelltext abgelesen, sondern ausgeführt.

**Offen:** die Gegenprobe im Studio — beim Abhaken darf die obere Zeile jetzt stillstehen.

---

**`1.1.14`: Eine Leiste, die während des Trainings oben stehen bleibt.** Rückmeldung vom
2026-08-17: „5/8 erledigt" stand ganz oben in der Karte — man musste an allen Übungen vorbei
hochscrollen, um zu sehen, wie weit man ist. Jetzt klebt am oberen Rand eine dunkle Leiste
mit **`5/8 erledigt · 3 offen`** links und der **Trainingsdauer** rechts.

Vier Entscheidungen des Benutzers dazu:

- **Minuten und Stunden, keine Sekunden** (`seit 47 min`, `seit 1 h 05 min`). Eine Uhr im
  Sekundentakt wäre nicht nur unruhig — sie ändert bei `59:59 → 1:00:00` ihre Breite und
  schöbe die halbe Zeile mit.
- **Das alte „x/n erledigt" in der Karte ist entfallen.** Zwei Anzeigen derselben Zahl sind
  doppelte Pflege ohne Gegenwert; die id `#fortschritt-text` ist einfach mitgewandert, die
  beiden Funktionen in `index.js` schreiben unverändert dorthin.
- **Kein Beenden-Knopf in der Leiste** — etwas Rotes, das dauerhaft unter dem Daumen klebt,
  wird irgendwann versehentlich getroffen.
- **Stattdessen ein Kasten unter der letzten Übung** mit Hinweissatz und Knopf. Nach der
  letzten Übung steht man ganz unten; bis `1.1.13` musste man zum Beenden wieder hoch. Der
  Kasten oben bleibt, wer mittendrin abbricht, sucht ihn dort. Beide Knöpfe hängen an
  derselben Funktion, ergänzt um den bestehenden Notfall-Knopf sind es drei Auslöser und
  eine Logik.

**Die Leisten teilen sich einen `sticky`-Behälter** (`#leisten` in `lib/view_header.php`) —
Einzelheiten in Fallstrick 19. Kurz: Zwei Elemente mit eigenem `top: 0` legten sich
übereinander, und `zurAktivenSpringen()` misst jetzt den Behälter statt einer Liste
einzelner Leisten, die man beim Ergänzen vergessen würde.

**Die Dauer hängt nicht an der Uhr des Handys.** Der Server rendert die bereits
verstrichenen Sekunden (`data-sekunden`), der Browser rechnet nur noch die Zeit **seit dem
Laden** dazu — also eine Differenz. Ein absoluter Zeitstempel wäre bei falsch gestellter
Geräteuhr oder abweichender Zeitzone um Stunden daneben. Aus demselben Grund wird bei jedem
Durchlauf neu aus der Uhr gerechnet statt hochgezählt: Schläft das Handy in der Tasche,
drosselt der Browser den Zeitgeber, und ein Zähler bliebe zurück.

**Geprüft am Dev-Server** mit einem Plan aus 8 Übungen und einer seit 47 Minuten laufenden
Einheit: Der Stapel ist das erste Element im `<body>`, die Trainingsleiste hängt darin,
`data-sekunden` steht auf 2841, der Text lautet `5/8 erledigt · 3 offen`, das alte
`<p class="fortschritt">` kommt null Mal vor, der Abschluss-Kasten ist da und es gibt genau
die zwei erwarteten Knopf-IDs. Ohne laufende Einheit sind Leiste und Kasten weg, der leere
Stapel bleibt — auf `index.php` wie auf `history.php`. Die Formatierung der Dauer ist mit
der **echten** Funktion aus `index.js` gegen zehn Werte geprüft (0 s bis 12 h).

**Offen:** wie es aussieht — Höhe, Kontrast, Verhalten am Notch im PWA-Vollbild.

---

**`1.1.14` bringt außerdem: jeder wählt, woher die Vorbelegung eines Satzes kommt.** Bisher
galt für alle „Satz k bekommt Satz k vom letzten Mal". Das ist schnell für eine feste
Satzfolge, aber nicht für jeden — wer sich von Satz zu Satz herantastet, will lieber den
vorigen Satz von heute übernehmen. Jetzt entscheidet das jeder für sich auf der Kontoseite,
direkt unter dem Expertenmodus.

| | Satz 1 | Satz 2 | Satz 3 |
|---|---|---|---|
| `gleicher_satz` — „Wie beim letzten Training" *(Vorgabe)* | 12×40 | 10×40 | 9×45 |
| `letzter_satz` — „Wie der Satz davor" | 12×40 | 12×40 | 12×40 |

*(Letztes Training dieser Übung war 12×40 · 10×40 · 9×45.)*

**Schema:** `users.satz_vorlage TEXT NOT NULL DEFAULT 'gleicher_satz'`. Rein additiv, und der
Vorgabewert ist exakt das bisherige Verhalten — beim Rollout ändert sich für niemanden etwas,
bis er selbst umstellt. **Codeliste** `SATZ_VORLAGE` in `lib/training.php` statt eines 0/1-
Schalters, damit eine dritte Variante eine Zeile PHP kostet und keine Migration; dasselbe
Muster wie bei `equipment` und `image_crop`.

**Drei Entscheidungen, die man leicht falsch trifft:**

- **Keine 409-Sperre bei laufendem Training**, anders als beim Expertenmodus. Dort ist die
  Sperre nötig, weil sich die *Form der Nutzlast* ändert; hier schicken beide Verfahren
  dieselbe Satzliste.
- **Der Warteschlangen-Schlüssel bleibt `-v3`.** Die Nummer steht für die Form eines
  Eintrags. Ein Sprung würde beim Rollout die wartenden Eingaben von jedem verwerfen, der
  gerade trainiert.
- **Kein PHP-Gegenstück.** Die Regel lebt allein in `naechsterSatz()`; der Server erfindet
  nie einen Satz.

**Nebenbefund, mitkorrigiert:** `naechsterSatz()` hatte eine vierte Stufe („sonst der letzte
Satz vom letzten Mal"), die **nicht erreichbar** war — `nr` ist immer `saetze.length + 1`,
also greift ab Satz 2 stets die Stufe davor, und für Satz 1 widersprach ihre Bedingung der
ersten Stufe. Harmlos, aber entfernt, damit die neue Logik nicht um einen toten Zweig herum
gebaut wird.

**Nachgemessen.** Serverseitig per `curl`: Vorgabewert nach der Migration `gleicher_satz`,
Attribut `data-satz-vorlage` in `index.php` folgt dem Umstellen, ein unbekannter Wert wird
mit `422` abgelehnt und die Datenbank bleibt unverändert. Die Kontoseite rendert das
`fieldset` bei ausgeschaltetem Expertenmodus als `disabled`, bei eingeschaltetem bedienbar,
mit den richtigen Beispielwerten. Und die Auswahllogik selbst mit der **echten**
`naechsterSatz()` aus `index.js` gegen acht Fälle — beide Verfahren, mehr Sätze als Vorlage,
Übung ohne Vorlage, und die Probe, dass `letzter_satz` eine Korrektur weiterträgt
(`10×40` statt `9×45`), `gleicher_satz` dagegen nicht.

**Offen:** die Darstellung der Auswahl auf der Kontoseite am Gerät.

---

**`1.1.12` bis `1.1.14`: Gesperrt ist orange, nicht rot.** Rückmeldung vom 2026-08-17, nachdem
der Benutzer die Sperre erstmals gesehen hat. Sperren und Löschen standen beide in Rot
nebeneinander, obwohl sie **gegensätzlich** sind: Das eine nimmt die Daten mit, das andere
lässt sie ausdrücklich stehen. Zwei rote Knöpfe laden dazu ein, sie für gleich gefährlich
zu halten.

Gesperrt trägt jetzt durchgehend denselben Ton wie die **übersprungene Übung** im Training —
Knopf, Abzeichen und Balken am linken Rand. Rot bleibt allein dem Löschen. Der Wert steht
dafür nicht mehr zweimal im Stylesheet, sondern als `--signal` in `:root`.

**Die Schrift auf dem Orange ist weiß — Knopf und Abzeichen.** Nachgerechnet kommt Weiß auf
`#ff6600` nur auf **2,94:1**, dunkle Schrift auf **5,03:1**; beim roten Löschen-Knopf
funktioniert Weiß, weil `#b3261e` auf **6,54:1** kommt — die Farbe ist dunkler, als sie
wirkt. Erst gebaut wurde deshalb die dunkle Fassung.

Der Benutzer hat sie am eigenen Gerät angesehen und **Weiß verlangt** (2026-08-17), zuerst
für den Knopf (`1.1.13`), dann für das Abzeichen (`1.1.14`) — zwei gleichfarbige Elemente
nebeneinander sollen nicht unterschiedlich beschriftet sein. Das liegt unter WCAG AA; die
Messwerte stehen im Stylesheet bei `--signal`, damit die Entscheidung beim nächsten
Aufräumen nicht stillschweigend zurückgedreht wird. Die App hat genau einen Benutzerkreis,
und der hat es am eigenen Bildschirm beurteilt.

Beim eigenen Konto braucht der deaktivierte Knopf **keine** eigene Regel: `button:disabled`
dämpft ihn schon auf 55 %. Und keinen Hover-Sonderfall, weil `button.sperr-knopf` dieselbe
Spezifität wie `button:hover` hat, aber später in der Datei steht — genau wie `button.gefahr`
sich heute schon verhält.

Dazu ersatzlos entfernt: der **Pläne**-Knopf in jeder Benutzerzeile. Die Pläne eines
Benutzers sind über die Seite *Pläne* und deren eigenes Auswahlfeld erreichbar; der
Abkürzungslink war ein zweiter Weg zum selben Ort und hat die Knopfzeile am Handy nur
verbreitert.

**Geprüft am Dev-Server** mit einem von Hand gesperrten Benutzer, weil sich die gesperrte
Zeile live nicht ansehen lässt (wer gesperrt ist, kann die Seite nicht laden): Die Karte
trägt `karte benutzer ist-gesperrt`, das Abzeichen `abzeichen abzeichen-gesperrt`, der Knopf
`sperr-knopf entsperren`, und das Sperrdatum steht darunter. Beim eigenen Konto steht
`sperr-knopf sperren` mit `disabled`. Der Pläne-Link kommt im ganzen Dokument null Mal vor.
**Wie es aussieht, muss weiterhin der Benutzer beurteilen.**

---

**`1.1.11`: Konten sperren statt löschen.** Ein Admin kann jedes andere Konto sperren —
normale Benutzer wie Admins. Ein gesperrtes Konto kommt weder über das Passwort noch über
ein angemeldetes Gerät herein, und eine **laufende Sitzung endet beim nächsten
Seitenaufruf**. Pläne, Einheiten, Protokoll und Sätze bleiben vollständig; Entsperren
stellt den Zustand wieder her, nur anmelden muss sich der Benutzer neu.

Anlass ist das Wartungskonto `claude`: Statt sein Passwort vor jeder Arbeitsrunde
zurückzusetzen und danach neu zu vergeben, wird es künftig gesperrt und bei Bedarf wieder
freigegeben. Das Passwort darf dann konstant bleiben, weil es allein nichts öffnet.

**Schema:** eine Spalte `users.blocked_at TEXT` — `NULL` heißt aktiv, sonst steht dort der
Zeitpunkt der Sperre. Rein additiv; nach der Migration ist niemand gesperrt. Ein
Zeitstempel und kein Flag-Paar wie `exercises.archived`/`archived_at`, weil zwei Spalten
für dieselbe Aussage auseinanderlaufen können.

**Durchgesetzt wird an drei Stellen**, und alle drei werden gebraucht: `attempt_login()`,
der `JOIN` in `try_remember_login()` und `current_user()`. Die dritte ist die wichtigste —
ohne sie liefe eine offene Sitzung weiter, bis sie von selbst abläuft.

`attempt_login()` liefert deshalb jetzt `string` statt `bool` (`LOGIN_OK`, `LOGIN_FALSCH`,
`LOGIN_GESPERRT`). Geprüft wird **nach** der Passwortprüfung, sonst verriete die Auskunft,
welche Kontonamen es gibt. Und der Fall zählt **nicht** als Fehlversuch: Das Passwort war
richtig, und die Bremse zählt pro IP — sonst sperrte ein gesperrter Benutzer mit fünf
Versuchen den ganzen Haushalt für eine Viertelstunde aus.

**Nachgemessen am Dev-Server**, 18 Prüfungen, alle bestanden: Selbstsperre abgelehnt (409),
Sperre gesetzt und Geräte abgemeldet, laufende Sitzung sofort auf `login.php`, Anmeldung
mit 403 und eigener Meldung, kein Eintrag in `login_attempts`, ein von Hand
stehengelassenes Remember-Token kommt trotzdem nicht herein (der `JOIN` greift),
Entsperren stellt alles wieder her — **und der Plan des gesperrten Benutzers steht danach
unverändert da**. Ein falsches Passwort zählt weiterhin normal für die Bremse.

**Am Live-System nachgeprüft (2026-08-17)**, mit dem Konto `claude` als Versuchsobjekt. Der
Benutzer hat gesperrt, danach wieder freigegeben; geprüft wurde mit einer **bereits offenen**
Sitzung, also ohne neue Anmeldung:

| Geprüft | Ergebnis |
|---|---|
| `index`, `history`, `admin_users`, `maintenance`, `devices` | alle `302 → login.php` |
| API: `api/token.php`, `api/log.php`, `api/session.php` | alle `401 „Nicht angemeldet"` |
| Sitzung serverseitig | zerstört — die Antwort liefert eine neue, leere Sitzungs-ID |
| Neuanmeldung mit gültigem Passwort | `403 „Dieses Konto ist gesperrt …"` |
| dabei `remember: true` mitgeschickt | **kein** Remember-Cookie ausgestellt |
| `remember_tokens` für den Benutzer | 0 |
| `login_attempts` | unverändert — der 403 zählt nicht mit |
| Entsperren | Anmeldung wieder `200`, alle Adminseiten `200`, kein Passwortwechsel-Zwang |

**Der wichtigste Befund ist die zweite Zeile.** Dass die Oberfläche weiterleitet, sagt für
sich genommen wenig — eine Weiterleitung sieht ordentlich aus und ließe die API trotzdem
offen. Beides zusammen belegt, dass die Prüfung in `current_user()` sitzt und nicht in den
einzelnen Seiten.

Das Markup der Benutzerliste ist ebenfalls geprüft (`DOMXPath` über
`admin_users.php`): Beim eigenen Konto sind „Sperren", „Löschen" und „Adminrecht entziehen"
deaktiviert und tragen die erklärenden `title`-Texte, bei den übrigen sind sie aktiv.

Die Darstellung der gesperrten Zeile hat der Benutzer anschließend selbst begutachtet — sie
war richtig, aber **rot**, und das führte zu `1.1.12` bis `1.1.14` oben.

---


**`1.1.10` repariert den Sitzungsverlust vom 2026-08-16.** Nele konnte im Studio am
iPhone die letzte Übung nicht mehr speichern; das Häkchen sprang zurück, als wäre der
Knopf tot. Ein Neuladen über den Menüpunkt „Training" hat es sofort geheilt.

**Was wirklich passiert ist**, aus `sessions`, `workout_log` und `remember_tokens`
rekonstruiert:

| Zeit | Ereignis |
|---|---|
| 22:54:03 | letzter erfolgreicher Eintrag (Position 33) |
| — | Pause von **24:13** für die 85-kg-Übung, kein einziger Aufruf vom Handy |
| 23:18:16 | Position 32 wird **erfolgreich** geschrieben |
| 23:18:24 | Remember-Token rotiert — die Sitzung ist **acht Sekunden später** weg |
| 23:18–23:19 | jeder Tipp auf „Erledigt" endet in 403, Position 48 lässt sich nicht speichern |
| 23:19:57 | nach dem Neuladen gespeichert, 23:20:07 Einheit beendet |

`session.gc_maxlifetime` stand auf dem PHP-Vorgabewert **1440 s = 24 Minuten** — ihre
Pause hat ihn um 13 Sekunden gerissen. Zur Gegenprobe: Olivers parallele Einheit 23 hatte
als größte Pausen 19:37 und 19:21 und blieb ungestört. Die vollständige Mechanik samt der
Rolle von `gc_divisor = 100` und `lazy_write = On` steht als **Fallstrick 23** in
`CLAUDE.md`; sie gehört zum dauerhaften Wissen, nicht hierher.

**Geändert wurde auf zwei Ebenen.** In der `app.ini` des `Dockerfile`:
`session.gc_maxlifetime = 28800` (8 Stunden), `session.lazy_write = Off`,
`session.use_strict_mode = 1`. Und in der App die Selbstheilung: `api/token.php` liefert
zur laufenden Sitzung ein frisches CSRF-Token, `csrf_check()` kennzeichnet ein totes Token
mit `code: "csrf_ungueltig"` (dafür hat `json_err()` einen vierten Parameter bekommen),
und `apiFetch()` holt daraufhin ein neues Token und wiederholt den Aufruf einmal.
`index.js` ist **unverändert** geblieben.

**Nachgemessen am Dev-Server**, nicht gefolgert. Zuerst per `curl`: angemeldet mit
„Angemeldet bleiben", Sitzungsdatei von Hand gelöscht, Schreibaufruf → `403` mit
`code: "csrf_ungueltig"`, `api/token.php` → frisches Token, derselbe Aufruf wiederholt →
CSRF akzeptiert. Ohne Sitzung antwortet `api/token.php` mit `401`.

Und dann die Stelle, die `curl` nicht erreicht — der Wiederholversuch in `apiFetch`
selbst. Dafür liegt in der Sitzungsablage ein Node-Skript, das **das echte
`assets/app.js`** lädt (nicht eine Nachbildung seiner Logik) und gegen den Dev-Server
laufen lässt: anmelden, Sitzungsdatei löschen, `apiFetch('api/log.php', …)`. Ergebnis mit
`1.1.10`: kein 403 mehr, der Aufruf endet mit dem fachlichen `404` „Planposition gibt es
nicht" — die CSRF-Prüfung war also bestanden —, und das Token im `<meta>` ist
ausgetauscht. **Gegenprobe mit dem `app.js` aus `1.1.9`: derselbe Ablauf endet in
`403 Sicherheits-Token ungültig`, und der Folgeaufruf ebenso.** Der gemeldete Fehler ist
damit nachgestellt und die Reparatur an derselben Stelle belegt.

**Offen: die Gegenprobe am Gerät.** Der eigentliche Beweis ist eine Trainingseinheit mit
einer Pause über 24 Minuten, die nichts mehr merkt. Ebenfalls offen: nachsehen, ob die
`session.*`-Werte im laufenden Container wirklich angekommen sind — das prüft
`docker exec -u www-data trainingsplan php -r 'echo ini_get("session.gc_maxlifetime");'`
und muss `28800` sagen.

---

**`1.1.9`: Uhrzeit unter das Datum.** Im Verlauf bei den Übungen stand die Uhrzeit am
Handy neben dem Datum; die Spalte war damit die breiteste der fünf und drückte die
Sätze zusammen. Jetzt stehen Tag und Uhrzeit untereinander — **ab 40rem wieder
nebeneinander**, sonst würde jede Tabellenzeile auf breiten Schirmen ohne Grund doppelt
so hoch.

`format_datetime_kurz()` ist dafür durch **zwei** Funktionen ersetzt worden,
`format_datum_kurz()` und `format_zeit()`. Der Grund ist keine Förmlichkeit: Ob die
Uhrzeit neben oder unter dem Datum steht, hängt am verfügbaren Platz und gehört damit
ins Stylesheet. Mit einem festen Trennzeichen im String wäre beides nicht zu haben
gewesen. Das volle Jahr gilt weiterhin überall sonst (`format_datetime()`).

---

**`1.1.8` behebt den Cache-Fehler aus `1.1.7`.** Am PC sah alles richtig aus, am Handy
stand der englische Name weiter neben dem deutschen, das Gerät in einer dritten Spalte
und die Bilder immer zentriert — alles drei CSS-Regeln, die nicht ankamen. Der Server
lieferte nachweislich das richtige Stylesheet.

**Ursache: zwei Caches hintereinander, und der Reparaturweg lief durch den kaputten.**
`CACHE` in `sw.js` war ordentlich hochgezählt, erreicht aber nur den Service-Worker-Cache.
Dahinter sitzt der HTTP-Cache des Browsers, und Apache sendete für Assets kein
`Cache-Control` — ohne das darf der Browser heuristisch cachen.

**Der Zustand hat sich selbst erhalten**, und das ist der eigentliche Befund: Der Benutzer
hat die Seite vier- bis fünfmal neu geladen, ohne Besserung. `stale-while-revalidate` holt
die frische Fassung mit einem gewöhnlichen `fetch` — also durch genau den HTTP-Cache, der
die alte Datei für gültig hält. Die „Revalidierung" schrieb den alten Stand bei jedem
Aufruf zurück in den Service-Worker-Cache. Das Netz, das den Fehler hätte heilen sollen,
hat ihn stattdessen jedes Mal neu bestätigt.

**Was gemessen ist und was nicht.** Gemessen: `1.1.7` läuft live, der Server liefert
nachweislich das richtige Stylesheet (`bild-links` ist drin), es kommt kein
`Cache-Control`, und der Nginx davor cacht nicht (`proxy_pass` ohne `proxy_cache`). Daraus
folgt zwingend, dass ein Cache **auf dem Gerät** die alte Fassung hielt. Nicht gemessen —
mangels Zugriff auf den Cache-Storage des Handys — ist, **welcher**: Entweder wurde der
frische Cache über `cache.addAll()` mit der alten Datei befüllt, oder `sw.js` selbst kam
aus dem HTTP-Cache und der alte Worker lief weiter. Beide Varianten brauchen denselben
Mittäter, den fehlenden `Cache-Control`-Header, und beide sind mit derselben Korrektur
erledigt.

Am PC fiel nichts auf, weil dort hart neu geladen worden war.

**Nachtrag: Die Behebung ist bestätigt.** Nach dem Rollout von `1.1.8` sieht es am
Smartphone richtig aus, ohne dass jemand einen Cache geleert hätte — genau wie
vorhergesagt, weil `?v=1.1.8` eine Adresse ist, die in keinem Cache liegen kann. Damit
ist belegt, dass die Ursache clientseitig im Cache lag und dass die Korrektur greift.
**Welche** der beiden Cache-Ebenen es genau war, bleibt weiterhin unbestimmt und ist
ohne Zugriff auf den Cache-Storage des Geräts auch nicht mehr feststellbar — der
Zustand existiert nicht mehr.

Behoben an der Wurzel: **Die Version hängt jetzt an der Adresse** (`style.css?v=1.1.8`,
aus `app_version()`). Dazu liest `sw.js` seine Version aus der eigenen Adresse — die von
Hand gepflegte Cache-Nummer entfällt ersatzlos —, `cache: reload` umgeht den HTTP-Cache
beim Befüllen und Revalidieren, und `apache-app.conf` setzt `Cache-Control: no-cache`
für `assets/` und alle `*.js`. Vier Ebenen, weil derselbe Fehler jetzt zweimal
zugeschlagen hat.

---

**`1.1.7` bringt drei Dinge**, alle aus der Rückmeldung nach dem Training am 2026-08-17.

**1. Bildausschnitt je Übung wählbar** (`exercises.image_crop`, `links`/`mitte`/`rechts`).
Die Vorschaubilder stehen in einem quadratischen Rahmen mit `object-fit: cover`; bei einem
Motiv, das breiter als hoch ist, schnitt der Browser links und rechts gleich viel weg und
traf damit neben das Gerät. Der Wähler steht in der Übungsmaske direkt unter dem Bildfeld.

**Der Wert ändert keine Datei** — er wirkt allein über `object-position`. Das geht nur,
weil `write_resized()` (`lib/upload.php`) ausschließlich skaliert und **nicht** beschneidet;
das Thumbnail trägt also noch das volle Seitenverhältnis. Deshalb wirkt die Einstellung
sofort auf alle bestehenden Bilder und lässt sich beliebig oft ändern.

**2. Mehr Breite für die Bilder, neues Kartenlayout.** Gerät und Ausführung stehen jetzt
**unter** dem Bild über die volle Kartenbreite statt rechts daneben in der Textspalte; der
englische Name steht unter dem deutschen statt daneben. Dadurch konnten die Bilder wachsen:
Übungsliste 80 → 112 px, Trainingsansicht 120 → 150 px. `.uebung-kopf` musste dafür von
Flex auf Grid umgestellt werden — als drittes Flex-Kind hätte sich die Schwerpunktzeile
rechts neben den Text gestellt statt darunter.

**3. Die Verlaufstabelle passt aufs Handy.** Sie rollte auf einem Pixel 10 Pro XL seitwärts.
Zwei Änderungen: Das Datum trägt in den Verlaufstabellen ein **zweistelliges Jahr**
(`format_datetime_kurz()`, überall sonst bleibt das volle), und die Sätze stehen nicht mehr
als eine Zeile mit `nowrap`, sondern als **umbrechendes Gitter** (`satz_gitter()` in
`history.php`, zwei nebeneinander, ab 40rem vier). `saetze_text()` blieb dabei bewusst
unangetastet — es bildet mit `saetzeText()` ein PHP/JS-Paar, und `saetze_zusammenfassung()`
baut darauf auf.

Der rollende Kasten (`.tabelle-rollt`) bleibt als Netz für sehr viele Sätze bestehen.

---

**`1.1.6` bringt drei Dinge**, alle aus derselben Rückmeldung vom 2026-08-12.

**1. Die Plan-Rotation merkt sich nichts mehr, sie sieht nach.** Vorgeschlagen wird der Plan
nach dem zuletzt trainierten — den Ausgangspunkt las bisher `users.last_plan_id`, eine
Spalte, die **nur beim Beenden** einer Einheit geschrieben und beim **Löschen** nie
zurückgenommen wurde. Wer eine Einheit zum Ausprobieren startete, beendete und wieder
löschte, hatte danach dauerhaft den falschen Vorschlag stehen: die Einheit war weg, ihre
Wirkung auf die Rotation blieb. Genau so kam am 2026-08-12 nach einer Pull-Einheit wieder
*Pull*.

`zuletzt_trainierter_plan()` (`lib/training.php`) fragt stattdessen die **jüngste Einheit in
der Historie** ab — jede, auch eine ohne einzige Protokollzeile. Das ist ausdrücklich so
entschieden: Die Rotation richtet sich **starr** nach der Historie, und eine leere Einheit
steht in der Historie. Wer sie nicht gezählt haben will, löscht sie; die Historie sauber zu
halten ist Sache des Benutzers. `users.last_plan_id` wird **weder gelesen noch geschrieben**;
die Spalte bleibt stehen, weil ihr Entfernen eine löschende Migration ohne Gegenwert wäre,
und ist in `schema.sql` als tot gekennzeichnet.

**2. Ein Training beginnt ausschließlich mit „Training starten".** Bis `1.1.5` legten auch
das erste „Erledigt" und ein Tausch „nur diese Einheit" stillschweigend eine Einheit an —
gedacht als Auffangnetz, praktisch fing es das Falsche: Ein Fehlgriff beim bloßen Durchsehen
des Plans begann ein Training, das niemand wollte, und die versehentliche Einheit stand
danach im Verlauf und verstellte die Rotation. `einheit_sicherstellen()` hat jetzt **genau
einen Aufrufer** (`api/session.php → start`); `api/log.php` und `api/swap.php` antworten mit
409. In der Ansicht sind vor dem Start **„Erledigt", „+ Satz" und das Gewichtsfeld
deaktiviert**. **Tauschen bleibt vorher möglich, aber nur dauerhaft im Plan** — das braucht
keine `session_id`; „Nur diese Einheit" wird gar nicht erst angeboten, mit Hinweissatz im
Dialog.

**3. Übersprungene Übungen sind orange** (`#ff6600`). Wer die Beinpresse besetzt vorfindet
und mit dem Beinstrecker weitermacht, sieht den Beinstrecker grün, sobald dort der erste Satz
steht — und die Beinpresse orange statt grau. Dafür ist „aktiv" neu definiert: nicht mehr
„die erste noch nicht erledigte", sondern die Position, an der gerade protokolliert wird,
sonst die erste offene *nach* der letzten mit Eintrag, sonst die erste offene überhaupt. Die
Regel steht in `positions_zustaende()` (`lib/training.php`) und wird von `aktiveMarkieren()`
(`index.js`) im Betrieb nachgezogen; `zurAktivenSpringen()` zielt jetzt auf `.zeile-aktiv`,
sonst spränge die Ansicht nach dem Auslassen zurück auf das besetzte Gerät.
Service-Worker-Cache auf `v19` (`assets/style.css` geändert).

**Der Expertenmodus ist zum ersten Mal im echten Training benutzt worden** (2026-08-11,
`Oliver`, Plan *Pull*, 21:45–23:43): 9 Positionen, 28 Sätze. Damit sagt die Satz-Kachel
nicht mehr `0`. Die Daten sind nachgeprüft und in Ordnung — Einzelheiten unter *Datenstand*.

**`1.1.5`: Die Wartungsseite zählt jetzt auch die Sätze.** Seit `1.1.0` liegt im
Expertenmodus das eigentliche Trainingsvolumen in `workout_sets` und nicht in
`workout_log` — eine Protokollzeile kann einen Satz tragen oder sechs. Die Übersicht sagte
darüber bisher nichts, und genau das will man wissen, bevor man eine Sicherung beurteilt.

**Dabei nachgemessen, ob die Sätze überhaupt in der Sicherung landen** — die Frage lag
nahe, weil `lib/backup.php` älter ist als der Expertenmodus. Ergebnis: ja, und zwar ohne
dass daran etwas zu tun war. `backup_erstellen()` kopiert über `VACUUM INTO` die **ganze**
Datei; es gibt keine Tabellenliste, die beim Erweitern des Schemas veralten könnte.
Durchgespielt: drei Sätze angelegt, gesichert, in der Arbeitsdatenbank zerstört, wieder
eingespielt — Sätze, Leitgewicht und `done` kamen unverändert zurück. Ebenso der
umgekehrte Fall: Eine nachgestellte Sicherung **ohne** `workout_sets`, `expert_mode` und
`done` (also aus der Zeit vor `1.1.0`, wie die vom 2026-08-07) lässt sich einspielen, und
`backup_wiederherstellen()` stellt das Schema im selben Zug wieder her — es ruft `db()`
und damit `init_schema()`.

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

Dazu in derselben Nummer: **Die Zeile „zuletzt …" nennt im Expertenmodus die ganze Satzfolge** — `zuletzt 3 Sätze (12×45 · 10×45 · 8×50)` statt einer einzelnen Zahl, in derselben Form wie die Zusammenfassung im Satzblock darunter. Im Standardmodus bleibt es bei „zuletzt 45 kg". **Beide Zusammenfassungen benutzen dieselbe Schreibweise** — sie stehen am Handy direkt übereinander, und zwei Formen liest man dort als Unterschied in der Sache. Gebaut wird sie an einer Stelle je Seite: `saetze_zusammenfassung()` und `saetzeZusammenfassung()`.

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

- *2026-08-12, `1.1.6`:* Die Wartungsseite meldet **`1.1.6`** — damit ist der neue Container
  belegt und nicht bloß das Stack-Update. `style.css`, `app.js`, `sw.js` und `manifest.json`
  byteweise gleich dem Repo, `sw.js` auf `v19`, und `.zeile-uebersprungen { border-left: 4px
  solid #ff6600; }` steht im ausgelieferten CSS. Zugriffssperren stichprobenhaft:
  `VERSION`, `schema.sql`, `Dockerfile`, `lib/training.php` und `data/trainingsplan.db`
  allesamt 403. Zählstände unverändert (5 Einheiten, 34 Protokollzeilen, 28 Sätze) — seit
  dem Rollout ist nicht trainiert worden.
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
Warteschlange und Verbindungsleiste aus `1.0.8` (das Wichtigste), die Darstellung
aus `1.0.10` bis `1.0.17` und **die drei Änderungen aus `1.1.6`**.
