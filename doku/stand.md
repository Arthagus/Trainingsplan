# Stand des Systems

Was **gerade** gilt: laufende Version, Datenbestand, offene Punkte. Kurz zu halten ist
Teil des Zwecks — wer hier blättern muss, findet nichts.

**Die Chronik steht in `doku/historie.md`**: was wann ausgerollt wurde, welche Version was
brachte, was dabei schiefging. Dauerhaftes Wissen — Architektur, Konventionen,
Fallstricke — steht in `CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.**

*Letzte Aktualisierung: 2026-08-29*

---

## Läuft gerade

| | |
|---|---|
| **Live** | **`trainingsplan:1.3.2`** — am 2026-08-29 eingespielt und **nachgemessen**: `app.js?v=1.3.2` und `style.css?v=1.3.2`. Damit stimmen Image-Tag und die von der App gemeldete Nummer erstmals seit `1.3.1` wieder überein. Von außen geprüft: `/VERSION`, `/schema.sql`, `/Dockerfile`, `/apache-app.conf`, `/lib/*` (auch die drei neuen Partials), `/data/*` und `/uploads/` liefern **403**, `health.php` von außen 404; `no-cache` auf `assets/*` **und** den Seiten-Skripten (`admin_splits.js` mitgeprüft — die neue Seite fällt unter dieselbe Regel); Sitzungscookie `secure; HttpOnly; SameSite=Lax`; `splits.php` und `admin_splits.php` leiten ohne Anmeldung auf `login.php`. **Am 2026-08-29 angemeldet als `claude` am echten Bestand nachgesehen** (Konto danach wieder zu sperren): Kachelreihenfolge stimmt; `admin_splits.php` zeigt die vier Vorlagen mit **einer** offenen Karte und Pulldown, *Aus einem Benutzer-Split* steht im Leerzustand (jeder Benutzer-Split entspricht inhaltlich schon einer Vorlage), *Splits anderer Benutzer* listet drei, gruppiert nach Nele und Oliver — **genau der Unterschied zwischen gefilterter und ungefilterter Liste, den §6.4 verlangt**. Auf `splits.php` mit einem eigenen Split kein Pulldown sondern `.split-titel`, mit zwei das Pulldown; `?split=` wählt, ein unbekannter Wert **und** die ID einer fremden Vorlage fallen beide auf die erste eigene Karte zurück. `plans.php` führte im Auswahlfeld ausschließlich meine zwei eigenen Splits. **Der Kern der Änderung an einer echten Kopie der Vorlage „Push / Pull“ (16 Positionen): Nur den Plannamen geändert → KEIN Zurücksetzen-Knopf. Danach eine Übung entfernt → Knopf da, `data-namen-ab=1`.** Zurücksetzen ohne Namen holte die Übung zurück und ließ „Mein Tag A“ stehen (`hinzugefuegt:1, umbenannt:0`), mit Namen wurde daraus wieder „Push“ (`umbenannt:1`); danach standen Kopie und Vorlage mit je 16 Positionen deckungsgleich da. Die beiden Testsplits sind gelöscht, der Katalog ist unverändert. **Was auch damit NICHT geprüft ist und offen bleibt: die Darstellung** — ob das Pulldown als Titel gelesen wird und ob Umbenennen- und Reset-Dialog am Handy gut sitzen (*Offen*, Punkt 0z). **Vorgeschichte der Nummer:** `1.3.1` war dieselbe Auslieferung wie das Paket `1.2.23`, nur unter neuem Tag — die `VERSION`-Datei darin sagte weiterhin `1.2.23`, gemessen am 2026-08-28. `1.3.1` ist damit eine ausgelieferte Nummer, die nie in `VERSION` stand; die Lücke `1.2.23` → `1.3.2` in der Datei ist genau dieser Fall und keine übersprungene Version. Wer die Nummer weiterverwendet, misst trotzdem erneut — diese Zeile ist die Notiz und nicht die Quelle |
| **Arbeitsstand** | `1.3.2`, **gebaut** am 2026-08-28 und am 2026-08-29 eingespielt — deckungsgleich mit live. Inhaltlich: drei Ansagen des Benutzers, nachdem `1.3.1` live war. **(1) Die Reihenfolge der Admin-Kacheln** ist jetzt Vorlagen – Übungen – Muskelgruppen – Benutzer – Wartung. **(2) Auf `splits.php` steht nur noch EINE Splitkarte offen** — ohne Weiteres die des aktiven Splits —, und im Kartenkopf sitzt statt des Namensfelds ein **Auswahlfeld**, mit dem man zwischen den eigenen Splits wechselt. Gerendert werden weiterhin alle Karten, die übrigen mit `[hidden]`: Der Wechsel ist damit ein Umschalten im Browser, ohne Netzaufruf und ohne Seitenaufbau. Die gewählte Karte wandert als `?split=` per `replaceState` in die Adresse, sonst stünde man nach jeder Aktion wieder beim aktiven statt bei dem, den man bearbeitet. Bei genau einem Split steht dort der Name und kein Auswahlfeld. **(3) Umbenannt wird im überblendeten Dialog** hinter *Umbenennen* — nötig, weil der Name im Kopf keinen Platz mehr hat; der Fehler eines zu langen Namens landet im Dialog und nicht an der Karte dahinter. **Dasselbe Schema auf `admin_splits.php`**, dort ohne „aktiv“: Ohne Parameter steht die erste Vorlage offen. Geprüft: Lint, alle Seiten, Sichtbarkeit für drei Fälle (aktiv / `?split=` / unbekannter Wert → erste Karte), ein Benutzer mit nur einem Split (kein Auswahlfeld, `.split-titel`), Umbenennen über den Endpunkt samt beider 422-Fälle, dazu `splitWechselVerdrahten()` und `splitUmbenennenFragen()` **ausgeführt** samt Gegenproben (zwei offene Karten werden aufgeräumt; ein werfendes `replaceState` verhindert den Wechsel nicht; ein 422 bleibt im Dialog). **Die Darstellung ist noch nicht beurteilt.** **Die Splits-Seite ist aufgeräumt:** `splits.php` zeigt jetzt für **jeden** nur den eigenen Bestand — ein Admin sieht dort dasselbe wie jeder andere. Der Vorlagenkatalog samt *User Splits* liegt auf der neuen Adminseite `admin_splits.php` („Vorlagen“, fünfte Kachel). An die Stelle der Vorlagenliste tritt auf `splits.php` der Kasten *Vorlage übernehmen*: Auswahlfeld, Planvorschau, *Zu mir kopieren* und *Als Text*. Das Auswahlfeld in `plans.php` führt nur noch eigene Splits; ein `?split=` darüber hinaus wird gegen `split_darf_bearbeiten()` geprüft und als eigener Eintrag angehängt — dazu eine Hinweiskarte mit Rückweg in den Adminbereich. Der einzige verbliebene Weg zum Split eines anderen Benutzers ist der dritte Abschnitt auf `admin_splits.php`. Karte und Text-Dialog sind Partials (`lib/view_split_karte.php`, `lib/view_split_text_dialog.php`), `splitAktion()` und `splitTextZeigen()` stehen in `assets/app.js`. **`api/splits.php` ist unverändert** — es wanderte nur Oberfläche, keine Fachlichkeit, und keine Schema- oder Datenänderung. Geprüft: PHP- und JS-Lint, alle elf Seiten für Admin **und** normalen Benutzer (403 wo erwartet), die Rechte am Endpunkt (publish/create-vorlage/rename-Vorlage/fremd-löschen jeweils abgewiesen), Kopieren, Veröffentlichen, Duplizieren, Umbenennen, Zurücksetzen, dazu `splits.js`, `admin_splits.js` und `splitTextZeigen()` **ausgeführt** samt Gegenproben (entferntes `data-plaene`, falsche `data-id` — jeweils fiel genau das Erwartete durch). Prüfbestand mit zwei Benutzern, je drei Splits, aktiv nicht der erste, zwei Vorlagen. **Dazu ein zweiter Änderungswunsch vom selben Tag: Der Vorlagenabgleich sieht die Plannamen nicht mehr an.** Bis dahin zählten sie im Fingerabdruck mit — wer seine Kopie „Tag A“/„Tag B“ nannte, bekam „Auf Vorlage zurücksetzen“ angeboten, obwohl sich am Training nichts geändert hatte, und der Knopf hätte ihm genau diese Beschriftung wieder weggenommen. Jetzt entscheidet allein der Inhalt (Anzahl und Reihenfolge der Pläne, darin die Übungen); die Namen sind eine **zweite Frage** und werden beim Zurücksetzen einzeln erfragt — `window.confirm` ist dafür einem `<dialog>` mit Kästchen gewichen, **unangekreuzt vorbelegt**. Das Kästchen erscheint nur, wenn die Namen wirklich auseinandergehen (`namen_weichen_ab`), und ein verborgenes Häkchen zählt nicht. `split_abgleich_signaturen()` ist damit entfallen, `signaturen_bauen()` liefert `inhalt` und `namen` getrennt. **Folge, die man kennen muss:** Weichen *nur* die Plannamen ab, gibt es keinen Knopf — eine verbesserte Beschriftung aus der Vorlage lässt sich dann nicht holen; ausdrückliche Entscheidung des Benutzers. Nachgemessen an vier Kopien derselben Vorlage (unverändert / nur umbenannt / Inhalt **und** Namen anders / nur Inhalt anders): Knopf nur bei den beiden letzten, Kästchen nur beim vorletzten. Zurücksetzen mit und ohne Namen sowie ganz ohne das Feld durchgespielt, dazu der Fall „Vorlage hat einen Plan mehr“ — der neue kommt zwangsläufig unter dem Namen der Vorlage, die gepaarten behalten ihren. **Die Darstellung ist noch nicht beurteilt** — dafür die Gegenprobe am Gerät, siehe *Offen*. Davor: `1.2.22`, **gebaut** am 2026-08-26 (`deploy/trainingsplan-build-1.2.22.tar.gz`). **Der Randschnitt aus `1.2.21` ging zu tief** — aus dem Betrieb gemeldet: oben fehlten Teile von Köpfen, seitlich Enden von Kurzhanteln. Drei Ursachen, alle behoben: die Toleranz (14 → 8; Maschinen sind sehr hell gezeichnet), die Kante der Suchkopie (200 → 1000; ein Kopfscheitel verschwindet dort im Mittelwert) und die Zugabe beim Zurückrechnen (1 px → `ceil($faktor) + 1`). Gemessen am **dunkelsten Pixel im weggeschnittenen Rand**: vorher fiel bei 6 von 17 Testbildern echte Zeichnung (bis Helligkeit 35), danach bei keinem mehr. **Wichtig für den Bestand:** Die live bereits nachgeschnittenen Bilder werden dadurch nicht wieder heil — das gespeicherte Vollbild ist schon beschnitten. Der Weg zurück war die Sicherung **mit Bildern**: Am 2026-08-26 wurde in dieser Reihenfolge vorgegangen — erst `1.2.22` einspielen (damit der scharfe Knopf gar nicht mehr dasteht), dann die Sicherung von vor dem Lauf, dann neu nachschneiden. Der Benutzer hat das Ergebnis abgenommen. **Dabei ein Nebenbefund, den man kennen sollte: Ein Restore LEERT das Bildverzeichnis nicht**, er legt die Bilder aus der Sicherung dazu. Nach Restore und erneutem Nachschnitt lagen deshalb 228 Dateien für 114 gebrauchte da; die 114 aus dem ersten Lauf hat *Verwaiste Bilder suchen* gefunden und entfernt — die Funktion aus `1.2.20` hatte damit unerwartet früh ihren ersten echten Einsatz. `1.2.21` brachte davor: Zwei Punkte zu den Übungsbildern vom 2026-08-26. **(1) Das Thumbnail auf der Planseite ist 112 px statt 72** — es stand mittig in einer Zeile, die es nicht ausfüllte (der Textblock daneben hat vier Zeilen, 72 px sind knapp drei). Dieselbe Größe wie in der Übungsverwaltung; möglich wurde sie durch `1.2.19`, seit die Positionsnummer keine eigene Spalte mehr braucht. **(2) Beim Hochladen wird der einfarbige Rand abgeschnitten**, bei Vollbild und Thumbnail. Grund war die Messung am Live-Bestand: Bei einem Bild lagen 82 px weiße Fläche links und 132 px rechts, bei einem anderen 109 px links und 7 px rechts — im quadratischen Rahmen sieht man davon vor allem die Leere. **Ein hochkantes Motiv wird auf quadratisch aufgefüllt** statt beschnitten: Vorgabe des Benutzers — oben und unten nie schneiden, links und rechts gern. Damit ist `image_crop` für solche Bilder wirkungslos, im Regelfall Querformat behält es seinen Sinn. Ohne einfarbigen Rand (Foto) passiert nichts, und jeder Zweifel gibt das unveränderte Bild zurück. **(3) Ein Wartungspunkt holt den Schnitt für Bestandsbilder nach** — sie bekommen dabei **neue Dateinamen**, weil `image.php` mit `Cache-Control: immutable` und einem Jahr Haltbarkeit ausliefert: Wer dieselbe Datei überschreibt, sieht ein Jahr lang die alte. Prüfen und Ausführen sind zwei Knöpfe, `exercises.image_path` wandert in derselben Reihenfolge mit wie beim Bildwechsel (schreiben, Datenbank, dann löschen). **Geprüft, und diesmal vollständig:** Am 2026-08-26 wurden `php8-gd`, `php8-zip` und `php8-fileinfo` lokal nachinstalliert — Bild-Upload ist damit hier prüfbar, was er nie war. Gelaufen sind: die reine Logik (14 Fälle samt Gegenprobe), der **echte Upload über HTTP** mit 19 Testbildern des Benutzers (AVIF und GIF werden abgewiesen, wie §5 es vorsieht), und der Nachschnitt über einen Bestand von 17 ungeschnittenen Bildern: 17 nachgeschnitten, keine Karteileiche, kein toter Verweis, und **alle 17 Thumbnails quadratisch oder quer** — die Zusicherung „oben und unten wird nie geschnitten" ist damit gemessen. Dabei fielen zwei eigene Fehler auf: Die Byte-Meldung zählte nur Ersparnisse (meldete „5,7 KB gespart", während das Verzeichnis wuchs), und die Füllung stand im gespeicherten Bild statt nur im Thumbnail — dadurch schnitt jeder weitere Lauf sie wieder ab und fräste sich ins Motiv. Beides behoben. `1.2.20` brachte davor drei Punkte zur Wartungsseite vom 2026-08-26, alle vom Benutzer angestoßen. **(1) Verwaiste Übungsbilder** lassen sich jetzt suchen und entfernen — Dateien in `uploads/`, zu denen es keine Übung mehr gibt. Im Normalbetrieb entstehen keine (`api/exercises.php` räumt beim Ersetzen, Entfernen und Löschen selbst auf); übrig bleiben kann etwas nach dem Einspielen einer Sicherung, weil die Datenbank zurückgeht und die Dateien nicht. Suchen und Löschen sind zwei Knöpfe, gefunden wird nur das eigene Namensmuster, Dateien unter einer Stunde bleiben tabu (ein Upload schreibt die Datei, bevor die Übung in der Datenbank steht), und der Löschlauf ermittelt die Liste selbst neu — es geht kein Dateiname über die Leitung. **(2) Die Seite ist umsortiert:** Datenbankpflege und Übungsbilder stehen jetzt zwischen *Zustand* und *Sicherung erstellen*. **(3) Die Pflege-Knöpfe stehen alle unter ihrem Text** statt je nach Textlänge mal daneben. Gelöst über ein eigenes `<p>` je Knopf; der naheliegende Griff — Flex-Spalte am `<dd>` — war eingebaut und wieder falsch, weil er jedes `<code>` mitten im Satz auf eine eigene Zeile riss (siehe *Frontend* in `CLAUDE.md`). Endpunkt und Anzeige geprüft: gegen einen Bestand aus benutztem Paar, zwei Waisen, einer frischen Datei und einer fremden — gefunden und gelöscht wurden genau die Waisen. `1.2.19` brachte davor zwei Punkte gegenüber `1.2.18`. **(a) Eine Regression aus `1.2.18` behoben** — gefunden beim Durchsehen am 2026-08-26, nicht gemeldet: Scheitert das Umsortieren der **Pläne**, blieb die Ansicht verschoben, während die Pfeilsperren zur alten Reihenfolge gehörten. Bei genau zwei Plänen war danach **kein** Pfeil mehr benutzbar (einer gesperrt, der andere zeigt ins Leere) — der Benutzer kam ohne Neuladen nicht einmal mehr zum zweiten Versuch. `plans.js` nimmt die Verschiebung jetzt zurück, wenn das Speichern fehlschlägt. **Steckt in der live laufenden `1.2.18` noch drin**, betrifft aber nur den Fehlerfall (kein Netz, totes Token). Dazu — auf Ansage des Benutzers — die **Sperre gegen schnelle Doppeltipps**: Solange gespeichert wird, sind die Pfeile der ganzen Liste tot. Vorher gingen zwei Tipps als zwei Aufrufe raus, jeder schreibt die ganze Reihenfolge, und innerhalb eines Plans (der einzige Weg ohne Neuladen) konnte die Datenbank still anders sortiert enden als der Bildschirm. **(b)** Die **Nummer einer Planposition** steht nicht mehr als eigene Spalte links neben dem Bild, sondern als Kästchen mit abgerundeten Ecken in der oberen linken Ecke — auf einer Höhe mit dem deutschen Namen, wie die Nummer auf der Trainingsseite. Das Thumbnail rückt dadurch an den linken Rand und die Textspalte gewinnt rund 26px; am Handy passen „Maschine" und die Ausführung dadurch in **eine** Zeile, die Positionen werden merklich kürzer. Der CSS-Zähler bleibt die Quelle der Zahl (`.position::before`) — beim Umsortieren zählt der Browser neu, ohne dass `plans.js` etwas anfassen müsste. Am gerenderten Bild geprüft (Firefox headless, 390 px, vorher/nachher), nicht nur am HTML. `1.2.18` brachte davor zwei Punkte vom 2026-08-26. **(a) `plans.php` aufgeräumt und an `splits.php` angeglichen:** Die Überschriften stehen jetzt **über** den Kästen statt darin — das war zugleich die gemeldete „Leerzeile" über „Rotation" und „Angezeigter Split" (`h2` bzw. `label` bringen ihren oberen Abstand mit). Der **blau hervorgehobene nächste Plan und der Satz „Als Nächstes wird … vorgeschlagen" sind ersatzlos weg**: Auf der Planseite baut man den Plan um, wo man in der Rotation steht, sagt die Trainingsansicht. Die Kette zeigt nur noch die Reihenfolge, `naechster_plan()`/`zuletzt_trainierter_plan()` werden dort nicht mehr gerufen, `.rotation-naechster` ist entfallen. Gliederung jetzt wie auf `splits.php`: Überschrift → Bestand → Kasten zum Anlegen (das Formular „Plan hinzufügen" sitzt deshalb **unter** der Planliste), ohne Plan fällt der ganze Rotationsabschnitt weg und darunter steht ein leerer Zustand. Dazu die **einfachen Pfeile am Rand der Liste**: Die oberste Übung hat kein „↑", die unterste kein „↓", ebenso der erste und letzte Plan — wie es die Doppelpfeile schon hielten. Das steht **zweimal**, weil das Umsortieren innerhalb eines Plans der einzige Weg ist, der die Seite nicht neu lädt: `plans.php` rendert den Zustand, `posPfeileNachziehen()` in `plans.js` zieht ihn nach jedem Tausch nach. Nachgemessen am gerenderten HTML (auch: Plan mit genau einer Übung — beide Pfeile tot) und durch Ausführen der Funktion samt Gegenproben. Die Gliederung ist am 2026-08-26 **am Live-System nachgesehen** (angemeldet als `claude`, Vorlage „Push / Pull“ mit 2×8 echten Übungen, in Handybreite gerendert): Überschriften über den Kästen, Rotationskette ohne Hervorhebung, und die Pfeilsperren sitzen richtig — erster Plan kein ↑, letzter kein ↓, in **beiden** Plänen die erste Übung ohne ↑ und die achte ohne ↓. **Offen bleibt allein das Urteil am eigenen Gerät.** Dabei sah es am Prüfrechner so aus, als ragte „Entfernen“ aus der Knopfzeile einer Position über den rechten Kartenrand — **das war ein Artefakt des Prüfstands und kein Fehler**: gerendert bei 390 px und in der Linux-Systemschrift, die breiter läuft als Roboto. Bei **448 px** (Pixel 10 Pro XL im Hochformat) steht die Zeile vollständig da, mit Luft — nachgemessen an derselben Live-Seite; der Benutzer hat es auf dem Gerät ebenfalls nie gesehen. Als Randbedingung bleibt: Ab `24rem` ist die Zeile ein **Raster** und bricht nicht mehr um, darunter rutscht „Entfernen“ in eine zweite Zeile. Nicht angefasst. **(b) Fehlerkorrektur:** In der Übungsauswahl beim Hinzufügen zu einem Plan wurden alle Vorschaubilder mittig zugeschnitten gezeigt — die Ausrichtung aus `exercises.image_crop` (links/mitte/rechts) blieb unberücksichtigt. Ursache war allein die fehlende Spalte in der Abfrage von `api/plans.php → exercise_picker`; `vorschlagMarkup()` wertet sie seit jeher aus, bekam aber nichts. Beide Tauschfenster waren nie betroffen (`tausch_vorschlaege()` liefert die Spalte). Nachgemessen: Endpunkt per `curl` (liefert jetzt `image_crop`) und `vorschlagMarkup()` ausgeführt — `links`/`rechts` ergeben `bild-links`/`bild-rechts`, ein fehlender Wert wie zuvor gar keine Klasse. `1.2.17` brachte davor zwei optische Punkte gegenüber `1.2.16`: (a) die Ecke, an der die Linie unter der Übungsnummer nach links umbiegt, ist abgerundet statt spitz (`--radius`, wie jede andere Box); (b) **besuchte Links sind nicht mehr lila** — es gab überhaupt keine Angabe zur Linkfarbe, also galt das Standard-Stylesheet des Browsers. Betrifft alle Links im Fließtext, aufgefallen an „Aktuellen Split wechseln". `1.2.16` brachte davor: In der Trainingsleiste steht der Fortschritt vorn — „2/6 beendet · 1 übersprungen" statt umgekehrt. Dazu ein Leerzeichen zwischen den beiden Gruppen, damit ein Screenreader nicht „beendet1 übersprungen" liest; sichtbar ändert das nichts. Was `1.2.15` gebracht hat, steht weiter unten in dieser Zeile — die Gegenproben dazu sind unverändert offen. Drei Rückmeldungen vom 2026-08-25. (1) Die Verbindungsleiste meldet nur noch echte Störungen: Der Balken „n Eingaben werden gespeichert …" ist ersatzlos weg, übrig bleibt eine **rote** Leiste bei tatsächlich nicht erreichbarem Server, die stehen bleibt, bis das Problem behoben ist (Fallstrick 29). (2) Die Trainingsleiste zählt „2/6 beendet · 1 übersprungen" statt „2/6 erledigt · 4 offen", und jede Übungskarte trägt **oben links in der Ecke ihre Nummer**, eingefasst von zwei Linien. Beides in zwei Runden entstanden: erst „aktiv · offen · beendet" und ein Kreis mittig in der Aktionszeile, dann auf Ansage umgestellt — „aktiv" sagt weniger als „übersprungen", und die Mitte der Aktionszeile ist im Standardmodus vom Gewichtsfeld besetzt. (3) Nach „Training beendet" springt die Seite **nach oben** und wirft dabei `?plan=` aus der Adresse — sonst schlug sie denselben Plan wieder vor, den man gerade beendet hat. Alle drei sind **optisch bzw. am Gerät zu beurteilen** und deshalb bis zur Gegenprobe ungeprüft |
| **Rollback-Ziel** | `trainingsplan:1.2.11` — dieses Image in Portainer stehen lassen. `1.2.11` ist am Live-System durchgeprüft (Splits, Zurücksetzen, Historie) und damit ein belastbarer Stand. **`1.2.15` bis `1.2.21` taugen dafür noch nicht**: Sie sind ausgerollt, aber die Gegenproben am Gerät stehen aus (*Offen*, Punkte 0 bis 0c) |

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
| Trainingsgeräte | Benutzt werden Maschine, Kabelzug, Kurzhantel und Körpergewicht. **Multipresse, Langhantel und Kettlebell sind bisher unbenutzt** — ein Filter darauf läuft leer, das ist kein Fehler |

Für IDOR-Prüfungen ist die Rollenverteilung das Entscheidende: zwei Admins, ein normaler
Benutzer, und `Nele` ist die einzige, die weder Vorlagen noch fremde Splits anfassen darf.

**Wie der Bestand entstanden ist**, steht in `doku/historie.md`.

---

## Offen

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

## Prüfstand der 21 Abnahmekriterien (`LASTENHEFT.md` §11)

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
