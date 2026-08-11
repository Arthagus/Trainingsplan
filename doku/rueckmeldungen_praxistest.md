# Rückmeldungen aus dem Praxistest

Fünf Runden, alle aus dem echten Studio-Einsatz. **Alles hier ist umgesetzt** — die
Beschreibungen bleiben stehen, damit später nachvollziehbar ist, *warum* etwas so gebaut
wurde.

Auffällig und der Grund, warum diese Datei geführt wird: Die Punkte werden mit jeder Runde
kleiner — erst Konstruktionsfehler, dann Entwurfsfehler, zuletzt Bedienung. Und keiner davon
wäre am Schreibtisch aufgefallen.

| Runde | Wann | Aus welcher Version | Landete in | Punkte |
|---|---|---|---|---|
| Erste | 2026-08-07 | `1.0.2` | `1.0.3` | 1–7 |
| Zweite | 2026-08-11 | `1.1.0` (Expertenmodus) | `1.1.1` | 8–10 |
| Dritte | 2026-08-11 | `1.1.1` | `1.1.2` | 11–14 |
| Vierte | 2026-08-11 | `1.1.2` | `1.1.3` | 15 |
| Fünfte | 2026-08-11 | `1.1.3` | `1.1.4` | 16 |

Reihenfolge = Nummerierung des Benutzers, nicht Priorität. Die Einschätzung „Aufwand" ist
grob und diente nur der Planung.

---

# Erste Runde: erstes Training, 2026-08-07

Gesammelt nach dem ersten echten Training im Studio mit `trainingsplan:1.0.2`.

> ## Stand: 1, 2, 3, 4, 5 und 7 sind **umgesetzt** und stecken in `1.0.3`
>
> Gebaut und gegen eine Datenbank im Zustand des Live-Systems geprüft (2026-08-07).
> Die Beschreibungen unten sind die Begründungen — sie bleiben stehen, damit später
> nachvollziehbar ist, *warum* etwas so gebaut wurde.
>
> **Punkt 6 ist inzwischen ebenfalls umgesetzt** — der Benutzer hat sich für Zuschnitt
> **a + b** entschieden (Trainingstagebuch und Verlauf je Übung), jeder sieht nur seine
> eigenen Daten. Damit stecken **alle sieben Punkte** in `1.0.3`.

---

## 1. Falsches Bild im Info-Dialog (Fehler) — **umgesetzt**

**Beobachtung:** Tippt man das Bild einer Übung an, erscheint oft zuerst das *zuletzt
angesehene* Bild und wird nach ein bis zwei Sekunden durch das richtige ersetzt.

**Ursache — bestätigt in `index.js:311`:**

```js
gross.src = bild.getAttribute('src').replace('_thumb.jpg', '.jpg');
gross.hidden = false;
```

Ein `<img>` behält sein bereits gerendertes Bild, bis das neue **vollständig geladen** ist.
Das Setzen von `src` blendet das alte nicht aus. Im Studio über Mobilfunk dauert das
Nachladen des großen Bildes (bis 1600 px Kante) genau die beschriebenen ein bis zwei
Sekunden — währenddessen steht das vorherige Motiv im Dialog.

**Vorschlag:** Erst das **Thumbnail** anzeigen (liegt bereits geladen in der Zeile, erscheint
also verzögerungsfrei), dann im Hintergrund das große Bild laden und austauschen, sobald es
da ist:

```js
const gross = qs('#info-bild');
gross.src = bild.getAttribute('src');          // Thumbnail: sofort da
const voll = new Image();
voll.onload = () => { gross.src = voll.src; };
voll.src = bild.getAttribute('src').replace('_thumb.jpg', '.jpg');
```

Damit ist ab der ersten Millisekunde das **richtige** Motiv sichtbar, nur kurz unschärfer.
Kein Leerraum, kein Flackern, kein fremdes Bild.

*Aufwand: klein. Reine `index.js`-Änderung.*

---

## 2. Eingabefelder nach „Erledigt" sperren — **umgesetzt**

**Wunsch:** Ist eine Übung abgehakt, sollen Gewicht und Wiederholungen nicht mehr
versehentlich verstellbar sein.

**Entscheidung des Benutzers (2026-08-07):** Kein zusätzlicher „Ändern"-Knopf. Stattdessen
derselbe Weg wie beim Tausch:

> Häkchen entfernen → Feld wird wieder frei → Wert ändern → erneut abhaken → Feld ist
> wieder gesperrt.

**Warum das gut ist:** Es gibt damit **einen** Mechanismus statt zweier. Genau so ist der
Übungstausch schon geregelt (§7.5: „erst das Häkchen entfernen, dann tauschen"). Wer die
App einmal verstanden hat, versteht beides.

**Was das im Code bedeutet:**

- `index.php`: Gewichtsfeld bekommt `readonly`, wenn die Position abgehakt ist.
- `index.js`: `zustandSetzen()` schaltet `readonly` mit um — dieselbe Stelle, an der schon
  der Tausch-Knopf gesperrt wird.
- **`api/log.php`: die Aktion `update` entfällt ersatzlos.** Sie war der Weg, einen Wert
  nachträglich zu ändern; den gibt es jetzt nicht mehr.
- `index.js`: der `change`-Zweig für Gewicht/Wiederholungen entfällt ebenfalls.

**§7.4 muss angepasst werden.** Der Absatz „Wird das Gewicht **nach** dem Abhaken korrigiert,
speichert die Änderung per `onchange` sofort" wird durch den neuen Weg ersetzt.

**Ein Nebeneffekt, der bewusst in Kauf genommen wird:** Das Abwählen löscht den
Protokolleintrag (§7.4). Wer abwählt, den Wert ändert und dann vergisst, wieder abzuhaken,
hat für diese Position nichts protokolliert. Das ist aber **sichtbar** — das Häkchen fehlt,
und der Zähler „x/n" steht entsprechend niedriger. Kein stiller Verlust.

*Aufwand: klein. Es fällt mehr Code weg als dazukommt.*

---

## 3. Wiederholungen sind so nicht erfassbar — **umgesetzt**

**Beobachtung:** Bei drei Sätzen macht man z. B. 12 · 10 · 9 Wiederholungen. Ein einzelnes
Feld kann das nicht abbilden — was trägt man ein?

Das ist ein echter Konstruktionsfehler, kein Schönheitsfehler. §9 nimmt satzgenaues Logging
ausdrücklich aus v1 heraus, aber das Wiederholungsfeld suggeriert eine Genauigkeit, die es
nicht liefern kann. Beim Gewicht ist das anders — das bleibt über die Sätze meist gleich.

**Entscheidung des Benutzers (2026-08-07):** Feld **und Datenbankspalte** entfernen. Keine
ungenutzten Felder mitschleppen. Die heute eingetragenen Wiederholungen dürfen verloren
gehen. Wenn später satzgenau erfasst werden soll, kommen die dafür nötigen Felder neu dazu.

**Umsetzung:**

```php
// in apply_migrations()
if (column_exists($pdo, 'workout_log', 'reps')) {
    $pdo->exec('ALTER TABLE workout_log DROP COLUMN reps');
}
```

`ALTER TABLE ... DROP COLUMN` gibt es in SQLite seit 3.35 (2021); das Container-Image
(Debian 13) bringt 3.46 mit. **Lokal verifiziert, dass es mit unserem Schema funktioniert** —
`reps` steht in keinem Index und in keiner Constraint, deshalb greift keine der
SQLite-Einschränkungen.

> ### ⚠ Diese Migration ist destruktiv
>
> Anders als alle bisherigen löscht sie Daten: die Spalte `workout_log.reps` samt Inhalt,
> unwiderruflich, auf der Live-Datenbank. Der Benutzer hat dem am 2026-08-07 ausdrücklich
> zugestimmt — zum Zeitpunkt der Zustimmung existierte **keine abgeschlossene
> Trainingseinheit**, betroffen waren nur Testeingaben.
>
> **Vor dem Ausrollen prüfen, ob das noch stimmt.** Sind bis dahin echte Einheiten
> protokolliert, muss vorher gefragt werden. Und ohne funktionierendes Backup (§6.5, ans
> Projektende verschoben) gibt es keinen Weg zurück.

**Betroffene Stellen** — vollständig ermittelt, nicht geschätzt:

| Datei | Was |
|---|---|
| `schema.sql` | Spalte `reps` aus `workout_log`, Kommentar anpassen |
| `lib/db.php` | Migration ergänzen (siehe oben) |
| `lib/training.php` | `letzter_wert()` liefert nur noch `weight`; `reps` aus Abfrage und Rückgabe |
| `api/log.php` | Prüfung, `WDH_MAX`, Insert und Upsert |
| `index.php` | Eingabefeld, Vorbelegung, Hinweiszeile „zuletzt … Wdh." |
| `index.js` | `reps` in `abhaken()` |
| `LASTENHEFT.md` | §4 (`workout_log`), §7.3, §7.4, §10 (satzgenaues Logging bleibt vorgemerkt) |

*Aufwand: mittel — viele Stellen, aber jede einzelne trivial.*

---

## 4. Bilder in der Planverwaltung — **umgesetzt**

**Wunsch:** Unter *Pläne* sollen bei den Übungen auch die Bilder erscheinen.

Zurzeit zeigt `admin_plans.php` je Position nur Name, Primärgruppe und ein
„archiviert"-Abzeichen. Das Thumbnail wäre dort dieselbe Ausgabe wie in
`admin_exercises.php` — Endpunkt und Path-Jail existieren bereits (`image.php`).

Sinnvoll wäre es auch im **Auswahlfeld** beim Hinzufügen; dort geht es aber nicht ohne
Umbau, weil ein `<select>` keine Bilder darstellen kann. Zunächst nur die Liste.

*Aufwand: klein. `admin_plans.php` plus etwas CSS.*

---

## 5. Bizeps als Ersatz für Trizeps — **umgesetzt** (Maske korrigiert, Regel unverändert)

**Beobachtung:** Beim Tausch von *Liegendes Kabeldrücken* (Trizeps) werden
*Bizeps-Maschine* und *Schrägbank-Curl* vorgeschlagen.

**Befund aus den Live-Daten:**

```
Arme  (4 Übungen — untereinander tauschbar)
    Bizeps-Maschine           primär: Bizeps
    Dip-Maschine              primär: Trizeps
    Liegendes Kabeldrücken    primär: Trizeps
    Schrägbank-Curl           primär: Bizeps
```

Der Tausch vergleicht seit `1.0.2` auf **Hauptgruppen-Ebene** (§7.5). `Bizeps` und `Trizeps`
sind beide Untergruppen von `Arme` — also gelten alle vier als austauschbar. Technisch
korrekt, und genau so am 2026-08-06 entschieden.

**Die schärfere Diagnose kam vom Benutzer (2026-08-07):**

> „Das ist dann aber unklar dargestellt, denn in den Einstellungen der Übung ist z. B. nur
> ‚Trizeps' angehakt, aber nicht ‚Arme'."

Und damit liegt der Finger auf der eigentlichen Wunde: **Die Übungsmaske behauptet etwas
anderes als die Tauschregel.** In der Maske hakt man `Trizeps` an — `Arme` bleibt leer. Wer
das sieht, erwartet zu Recht, dass `Trizeps` die maßgebliche Größe ist. Dass in Wahrheit
`Arme` entscheidet, steht nirgends.

**Erschwerend:** Die Hauptgruppen sind derzeit **selbst anklickbar**. Man kann eine Übung
direkt an `Arme` hängen, statt an `Trizeps`. Damit gibt es zwei Wege, dasselbe auszudrücken,
und die Datenlage wird uneindeutig. (Live genutzt wird das nicht — die Übungen hängen
durchweg an Untergruppen.)

**Entscheidung des Benutzers:** Hauptgruppen, die Untergruppen haben, sollen **nicht
anklickbar** sein, und die Untergruppen sollen sichtbar eingerückt darunter stehen.

**Umsetzung in `admin_exercises.php`:**

- Hat eine Hauptgruppe Untergruppen, entfallen dort Radiobutton und Checkbox. Sie wird zur
  **Gliederungsüberschrift** — größer, ohne Bedienelemente.
- Hat eine Hauptgruppe *keine* Untergruppen (z. B. eine künftige, die noch keine hat), bleibt
  sie wählbar. Sonst ließe sich für sie keine Übung anlegen.
- Die Einrückung existiert bereits (`gruppen-zeile-unter` mit `└`), wirkt aber neben
  gleichrangigen Bedienelementen zu schwach — sie sollte deutlicher werden, sobald die
  Hauptgruppe keine eigenen Felder mehr hat.
- **Der Hinweistext gehört an die Maske**, nicht nur ins Lastenheft: „Getauscht wird
  innerhalb der Hauptgruppe — für eine Übung an *Trizeps* kommt alles unter *Arme* infrage."
  Dann ist das Verhalten vorhersehbar, statt zu überraschen.

**Damit erübrigt sich vermutlich die Frage nach der Tauschregel selbst.** Falls dich das
Ergebnis im Studio trotzdem stört, bleiben die drei Wege von gestern:

| | Regel | Für *Liegendes Kabeldrücken* käme dann |
|---|---|---|
| a) Untergruppe | nur exakt dieselbe Untergruppe | nur *Dip-Maschine* |
| b) Zweistufig | erst dieselbe Untergruppe, darunter abgesetzt „Weitere Übungen für Arme" | *Dip-Maschine* oben, Bizeps darunter |
| c) Je Hauptgruppe einstellbar | neues Feld: „Untergruppen untereinander tauschbar ja/nein" | `Arme` nein, `Beine` ja |

*Aufwand: Maske klein bis mittel. Regeländerung nur, falls doch gewünscht.*

---

## 6. Auswertungsseite — **umgesetzt** als `history.php` (§7.8)

**Wunsch:** Eine Seite, auf der man sieht, wann man was trainiert hat.

**Status im Lastenheft:** §9 nimmt „Fortschritts-Charts/Statistik-Auswertungen" und
„Trainingshistorie/Kalenderansicht" aus v1 heraus; §10 führt beides als vorgemerkte
Erweiterung. **Die Daten liegen vollständig vor** — `sessions` und `workout_log` halten
alles fest, es fehlt nur die Ansicht. Ein neues Schema braucht es nicht.

**Vorschlag für einen ersten Zuschnitt** (neue Seite `history.php`, im Menü für alle
Benutzer, jeder sieht nur seine eigenen Einheiten — `user_id`-Prüfung wie überall):

- **Liste abgeschlossener Einheiten**, neueste zuerst: Datum, Plan, Dauer, „7/8 erledigt"
- **Aufklappbar je Einheit**: die protokollierten Übungen mit Gewicht, getauschte Positionen
  gekennzeichnet
- **Je Übung ein Verlauf**: Gewicht über die Zeit, als schlichte Tabelle oder Sparkline —
  das ist die Information, die beim Steigern wirklich hilft

Ohne Diagramm-Bibliothek: eine Tabelle und ggf. ein Inline-SVG genügen und passen zur Regel
„kein Build-Step, keine Abhängigkeiten".

**Anmerkung:** Sinnvoll wird das erst mit ein paar Wochen Daten. Zurzeit ist noch **keine
einzige Einheit** abgeschlossen. Ich würde es deshalb *nach* den Punkten 1–5 bauen, aber
**vor** Wartung/Backup.

**Entscheidung des Benutzers (2026-08-07):** Zuschnitt **a + b**, jeder sieht ausschließlich
seine eigenen Daten — auch Admins. Umgesetzt als `history.php` mit zwei umschaltbaren
Ansichten; §9 und §10 wurden entsprechend angepasst, neu ist §7.8.

Die Verlaufskurve ist Inline-SVG ohne Bibliothek. Zuschnitt **c** (Wochenübersicht,
vernachlässigte Muskelgruppen) blieb bewusst außen vor: Bei zwei Plänen in fester Rotation
weiß man das ohnehin.

*Aufwand: mittel — wie geschätzt.*

---

## 7. Startzeitpunkt der Einheit ist zu spät — **umgesetzt** (Knopf)

**Beobachtung des Benutzers (2026-08-07):**

> „Es ist etwas unsauber, dass im Training die Session inkl. der Uhrzeit als Startmoment den
> Abschluss der ersten Übung nimmt. Man beginnt ja mit der ersten Übung und nicht nach der
> ersten Übung."

**Das stimmt.** `einheit_sicherstellen()` in `lib/training.php` setzt
`started_at = now()` — aufgerufen wird sie aber erst, wenn die erste Übung **abgehakt**
wird. Der gespeicherte Zeitpunkt ist also das *Ende* der ersten Übung, nicht der Beginn des
Trainings. Bei einer Übung mit drei Sätzen sind das schnell fünf bis zehn Minuten
Unterschied.

Zurzeit ist das folgenlos, weil die Startzeit nur im Banner „Einheit läuft seit …" auftaucht.
**Mit der Auswertungsseite (Punkt 6) wird daraus ein echter Fehler**, denn dort soll die
Trainingsdauer stehen — und die wäre systematisch zu kurz.

**Zwei Wege:**

### a) Zeitpunkt des Seitenaufrufs merken

Wird `index.php` ohne offene Einheit geladen, merkt sich der Server den Zeitpunkt in der
PHP-Sitzung. Beim ersten Abhaken wird dieser als `started_at` verwendet statt `now()`.

- **Dafür:** kein zusätzlicher Klick, der Benutzer merkt nichts.
- **Dagegen:** Wer die App morgens öffnet und abends trainiert, bekäme eine absurde
  Startzeit. Es braucht eine Obergrenze (etwa: älter als 3 Stunden → `now()`), und die ist
  eine willkürliche Zahl. Außerdem geht der gemerkte Zeitpunkt verloren, wenn die Sitzung
  abläuft oder das Gerät wechselt.

### b) Ausdrücklicher Knopf „Training starten" *(Empfehlung)*

Auf der Vorschlagsseite steht neben dem Plan ein Knopf. Wer ihn drückt, legt die Einheit an —
mit exaktem Zeitstempel.

- **Dafür:** Die Zeit stimmt genau, ohne Schätzung und ohne Obergrenze. Symmetrisch zum
  bereits vorhandenen „Training beendet". Der Zustand „Einheit läuft" wird sichtbar, statt
  als Nebenwirkung des ersten Häkchens aufzutauchen.
- **Dagegen:** ein Klick mehr.
- **§7.6 muss erweitert werden:** Eine Einheit entsteht dann durch **drei** Auslöser statt
  zwei — Abhaken, Tausch *oder* den Knopf. Die bisherigen beiden bleiben, damit niemand
  festhängt, der den Knopf übersieht. „Bloßes Anschauen startet keine Einheit" gilt
  weiterhin: Der Knopf ist eine bewusste Handlung.

**Entscheidung des Benutzers (2026-08-07): Weg (b), der Knopf.** Umgesetzt als
`api/session.php` → `start`, Knopf auf der Vorschlagsseite. §7.6 kennt jetzt drei
Startwege; Abhaken und Tausch bleiben, damit niemand feststeckt, der den Knopf übersieht.

---

## Was noch aussteht

**Aus diesem Praxistest: nichts.** Alle sieben Punkte stecken in `1.0.3`.

**Als Nächstes:** Wartung/Backup (§6.5), bewusst ans Projektende gestellt — und inzwischen
dringlicher, weil echte Trainingsdaten entstanden sind.

---

# Zweite Runde: Expertenmodus, 2026-08-11

Gesammelt nach dem ersten Einsatz von `trainingsplan:1.1.0` auf dem Live-System.
**Alle drei Punkte sind umgesetzt und stecken in `1.1.1`.**

## 8. „+ Satz" markiert die Zeile als fehlerhaft (Fehler) — **umgesetzt**

**Beobachtung:** Beim ersten Satz einer Übung erscheint nach dem Antippen von „+ Satz" ein
roter Strich links an der Übung und der Knopf „Erneut versuchen".

**Ursache:** „+ Satz" belegt die neue Zeile mit dem passenden Satz der letzten Einheit. Gibt
es für diese Übung noch gar keine Vorgeschichte, bleibt die Zeile **leer** — und genau die
lehnt `saetze_pruefen()` in `api/log.php` zu Recht mit 422 ab: Ein Satz ohne Wiederholungen
*und* ohne Gewicht sagt nichts aus. Der Fehler saß also nicht in der Prüfung, sondern darin,
dass die Zeile überhaupt abgeschickt wurde.

**Umsetzung:** `saetzeFuerServer()` in `index.js` filtert leere Zeilen heraus.
`saetzeLesen()` liefert weiterhin **alle** Zeilen — die leere muss im DOM stehen bleiben,
sie wird ja gerade ausgefüllt. Die beiden auseinanderzuhalten ist der ganze Trick; wer sie
verwechselt, bekommt entweder den roten Rand zurück oder eine Zeile, die beim Tippen
verschwindet.

## 9. „Erledigt" hakt sich beim zweiten Satz selbst an — **umgesetzt**

**Beobachtung des Benutzers, wörtlich sinngemäß:** *„Erledigt steht für mich dafür, dass man
mit der Übung fertig ist. Aber ich will ja evtl. noch einen dritten oder vierten Satz machen
— erst wenn ich wirklich fertig bin, will ich das Feld anklicken, und dann sollte sich die
Übung zusammenklappen und zur nächsten weiterscrollen."*

**Das war kein Fehler, sondern ein Entwurfsfehler.** Die Fassung `1.1.0` koppelte „erledigt"
an die Existenz der `workout_log`-Zeile — dieselbe Regel wie im einfachen Modus, wo Abhaken
und Protokollieren tatsächlich dasselbe sind. Im Expertenmodus stimmt sie nicht: Dort
entsteht die Zeile mit dem **ersten Satz**, und da steht man mitten in der Übung am Gerät.
Der Entwurf war beim Planen als „Erledigt folgt den Sätzen" ausdrücklich zur Wahl gestellt
und bewusst so entschieden worden; die Nutzung hat ihn widerlegt.

**Umsetzung:** Neue Spalte `workout_log.done` (0/1, **Vorgabe 1**). Die Vorgabe ist der
ganze Trick bei der Migration: Jede Bestandszeile gilt damit als erledigt — genau das war
sie bisher auch. Daraus folgt der Rest:

- **„x/n" zählt `done = 1`**, in `fortschritt()` *und* in `einheiten_verlauf()`. Beide,
  sonst hieße „erledigt" im Verlauf etwas anderes als im Training.
- **Die Tauschsperre hängt weiter an der bloßen Existenz der Zeile.** Wer zwei Sätze
  Bankdrücken gemacht hat, kann die Position nicht mehr tauschen, auch ohne Häkchen — die
  zwei Sätze *waren* Bankdrücken. `plan_positionen()` liefert dafür `hat_eintrag` neben
  `erledigt`; die Sperrmeldung in `api/swap.php` spricht deshalb nicht mehr vom Häkchen,
  sondern von „bereits protokollierten Werten".
- **Ab-wählen löscht die Sätze nicht mehr.** Es setzt nur `done = 0`. Sie zu löschen, weil
  jemand eine Fertig-Markierung zurücknimmt, wäre dieselbe Sorte Fehler wie ein Tausch auf
  eine protokollierte Position. Weg sind sie über das ✕ der einzelnen Zeile.
- **Abhaken klappt den Satzblock zu und springt zur nächsten offenen Übung**, die sich dabei
  aufklappt — der ausdrücklich gewünschte Teil.
- Der Warteschlangen-Schlüssel geht auf `-v3`: Ein Eintrag aus `1.1.0` trägt kein `done`,
  und ein fehlendes `done` bedeutet serverseitig „erledigt". Beim Nachholen hakte er die
  Übung sonst ab.

## 10. Die Farblogik ist verkehrt herum — **umgesetzt**

**Beobachtung:** Der Kopf mit der Satz-Zusammenfassung ist dunkelblau; tippt man „+ Satz"
an, wird der Kopf plötzlich hellblau und „+ Satz" dunkelblau.

**Zwei Ursachen, beide unabhängig voneinander:**

1. **Spezifität.** `.saetze-kopf` sollte den Kopf leise machen (hellgrau mit Rahmen), aber
   `.summary-knopf` hat dieselbe Spezifität und steht **weiter unten** in derselben Datei —
   also gewann die spätere Regel und der Kopf blieb blau. Jetzt `.saetze-block >
   .saetze-kopf`, das gewinnt unabhängig von der Reihenfolge.
2. **Klebender Hover.** Auf einem Touchscreen gibt es kein Verlassen mit dem Zeiger, deshalb
   bleibt `:hover` am zuletzt angetippten Element hängen. `--akzent` (#1f6feb) gegen
   `--akzent-tief` (#16509f) — genau das beobachtete Tauschen der Blautöne. **Alle**
   `:hover`-Regeln des Stylesheets stehen jetzt hinter `@media (hover: hover)`; am
   Zeigegerät ändert das nichts.

**Entscheidung des Benutzers (2026-08-11):** kräftig ist **„+ Satz"**, leise der Kopf. Der
Kopf ist nur eine Statuszeile zum Auf- und Zuklappen; kräftig gehört der Knopf, den man
zwischen zwei Sätzen ohne Hinsehen trifft. Nebenbei bleibt die Seite dadurch ruhig —
zugeklappt trägt keine der acht Karten Farbe.

---

# Dritte Runde: Feinschliff, 2026-08-11

Gesammelt nach dem zweiten Einsatz, mit `trainingsplan:1.1.1`. Diesmal keine Fehler im
engeren Sinn, sondern **vier Beobachtungen zur Bedienung am Gerät** — die Sorte
Rückmeldung, die man nur im Studio bekommt und nie am Schreibtisch.
**Alle vier sind umgesetzt und stecken in `1.1.2`.**

## 11. Satzblock ist schon vor dem Training offen — **umgesetzt**

**Beobachtung:** Ruft man die Trainingsseite auf, ohne ein Training gestartet zu haben, ist
der Bereich „− Noch kein Satz" mit dem „+ Satz"-Knopf darunter bereits aufgeklappt.

**Warum das falsch ist:** Der aufgeklappte Block sagt „hier bist du gerade". Ohne laufende
Einheit stimmt das nicht — es gibt keinen Ablauf, in dem man irgendwo wäre. Dieselbe
Überlegung gilt für die grüne Markierung aus Punkt 12.

**Umsetzung:** `$aktivePosition` in `index.php` bleibt `null`, solange keine Einheit offen
ist; daran hängen sowohl das `open` am `<details>` als auch die Zustandsklasse. **„Training
starten" öffnet den Block der ersten Übung und scrollt dorthin** — und weil die Seite
dazwischen neu lädt, geht der Wunsch über einen Merker in `sessionStorage` (`sessionStorage`
und nicht `localStorage`: Er gilt für diesen Tab und diesen Moment, sonst spränge die Seite
beim nächsten Öffnen grundlos).

## 12. Wo bin ich gerade? — **umgesetzt**

**Wunsch des Benutzers:** *„Wenn man im Training ist, sollte die jeweils aktive Box links
grün sein — damit man weiß, wo man gerade ist, wenn man scrollt. Erledigte Blöcke dafür
evtl. mit blauem Streifen."*

**Das ist eine Umkehrung, und sie ist richtig.** Bis `1.1.1` war Grün die Farbe für
„erledigt" — der naheliegende Griff, aber falsch herum: **Grün zieht den Blick**, und den
soll ziehen, was als Nächstes zu tun ist, nicht was schon abgehakt wurde. Bei acht Karten
und einem Handy in der Hand ist „wo bin ich" die Frage, nicht „was war".

**Umsetzung:** Drei Zustände am linken Rand — `.zeile-aktiv` grün (`--gut`),
`.zeile-erledigt` blau (`--akzent`), `.zeile-offen` grau (`--linie`). Serverseitig entscheidet
`$aktivePosition`, im Betrieb zieht `aktiveMarkieren()` in `index.js` die Markierung mit,
sobald ein Häkchen fällt.

## 13. Fokusrahmen liegt über den Steppern — **umgesetzt**

**Beobachtung:** Klickt man in das Wiederholungsfeld, liegt der blaue Fokusrahmen links
*über* dem Minus- und rechts *unter* dem Plus-Knopf.

**Ursache:** Der Stepper hatte keinen Abstand zwischen Knöpfen und Feld, und der Rahmen wird
mit 2 px Versatz **außerhalb** des Feldes gezeichnet. Was dabei über wem liegt, entscheidet
die Malreihenfolge und nicht die Absicht.

**Umsetzung:** 3 px Abstand im Stepper, dazu `outline-offset: 0` am Zahlenfeld, damit die
3 px reichen. Das kostet 6 px Breite — deshalb ist bei der Gelegenheit das **Breitenbudget
der Satzzeile nachgerechnet und im Stylesheet dokumentiert**, und die Staffelung für schmale
Geräte ist zweistufig geworden: bis 400 px entfallen „×" und „kg", bis 352 px zusätzlich die
Satznummer. Die Knöpfe behalten in jeder Stufe ihre volle Größe — sie sind der Grund für die
ganze Rechnung.

## 14. Das Speichern sieht nach Fehler aus — **umgesetzt**

**Beobachtung:** *„Beim Speichern eines Satzes wird der linke Balken kurz orange gestrichelt.
Das sieht irgendwie nach Fehler aus."* Dazu: Der Zustand wird **zweimal** angezeigt — oben
als Leiste und noch einmal als Satz in der Übungskarte —, *„und das verlängert die Box kurz
und macht sie dann wieder kleiner, das macht das Ganze ziemlich unruhig."*

**Beides trifft zu, und der zweite Punkt wiegt schwerer.** Orange (`--warnung`) ist im
übrigen System die Farbe für „Achtung", hier ging aber gerade alles seinen Gang. Und eine
Anzeige, die im Sekundentakt erscheint und verschwindet, darf **nichts verschieben** — sonst
springt bei jedem Satz die ganze Liste darunter.

**Umsetzung:** `.zeile-wartet` ändert nur noch `border-left-style: dashed`. Die **Farbe
bleibt**, was der Zustand vorgibt — bei der aktiven Übung also grün, wie vom Benutzer
vorgeschlagen. Der Hinweissatz in der Karte entfällt **ersatzlos**; die `sticky` Leiste am
oberen Rand nennt die Anzahl und ist immer im Blick. Die Regel aus §2 („Fehler nie
stillschweigend verschlucken") bleibt damit gewahrt: Der Vorbehalt ist weiterhin sichtbar,
nur ohne Nebenwirkung auf das Layout.

---

# Vierte und fünfte Runde: 2026-08-11

Mit `trainingsplan:1.1.2` bzw. `1.1.3` im Einsatz. **Umgesetzt in `1.1.3` und `1.1.4`.**

## 15. Nach dem Abhaken scrollt er zu weit — **umgesetzt**

**Beobachtung:** Hakt man eine Übung als erledigt ab, springt die Ansicht zur nächsten — aber
zu weit. Oben ist einiges abgeschnitten, der Name der Übung ist nicht mehr zu sehen.

**Ursache — und sie ist ein hübsches Zusammenspiel zweier für sich richtiger Dinge:**

1. Die **Verbindungsleiste** hängt als *erstes Element im `<body>`*
   (`assets/app.js`, `verbindung._element()`) und ist `position: sticky; top: 0; z-index: 20`.
   Genau so soll sie sein — bei Netzproblemen ist sie die wichtigste Information auf dem
   Bildschirm und muss über allem stehen. Sticky heißt aber auch: Sie **überlagert**, was
   darunter durchscrollt.
2. `scrollIntoView({ block: 'start' })` setzt das Ziel **exakt** an den oberen
   Viewport-Rand — per Definition also unter die Leiste.

Der Grund, warum es *zuverlässig* auftrat und nicht nur manchmal: Die Leiste wird **in
genau diesem Moment sichtbar**. Das Abhaken legt einen Eintrag in die Warteschlange, und
`verbindung.wartend(...)` blendet sie ein — unmittelbar bevor gescrollt wird. Verdeckt waren
damit rund 38 px, und dort steht der Übungsname.

**Umsetzung:** `zurAktivenSpringen()` scrollt nicht mehr über `scrollIntoView`, sondern
rechnet die Zielposition selbst und zieht die Höhe der Leiste ab — **gemessen** über
`offsetHeight` und 0, wenn sie ausgeblendet ist. Eine feste Zahl wäre falsch gewesen: Der
Text der Leiste kann auf schmalen Geräten zweizeilig werden. Dazu 8 px Luft, damit die Karte
nicht bündig am Rand klebt.

**Kein Cache-Hochzählen:** Betroffen ist nur `index.js`, und der Service Worker fasst
ausschließlich Dateien unter `/assets/` an (`assets/sw.js`, `istAsset`). Alles andere läuft
`network-only`, kommt also ohnehin frisch.

## 16. Abgehakte Übungen ließen sich noch ändern — **umgesetzt**

**Beobachtung:** Bei einer als *erledigt* markierten Übung ließen sich die Wiederholungen und
das Gewicht eines Satzes nachträglich ändern; ebenso konnte man Sätze hinzufügen und löschen.

**Das war ein Überbleibsel, kein Versehen — und die Begründung hatte sich selbst überholt.**
`1.1.0` hielt in §7.4 ausdrücklich fest:

> *Die Sätze bleiben änderbar, solange die Einheit läuft. Das `readonly` nach dem Abhaken
> lässt sich hier nicht halten: Nachtragen weiterer Sätze ist der Normalfall und nicht die
> Korrektur.*

Das stimmte, **solange der erste Satz die Übung selbst abhakte**. Eine Sperre hätte damals
verhindert, den zweiten Satz einzutragen. Seit „Erledigt" mit `1.1.1` ein **Schalter** ist
(Punkt 9), trägt das Argument nicht mehr: Wer noch einen Satz machen will, hat schlicht noch
nicht abgehakt. Damit gilt wieder die ursprüngliche Regel aus §7.4 — Häkchen entfernen,
korrigieren, neu abhaken —, und zwar für Sätze genauso wie für das Gewichtsfeld im
Standardmodus und für den Übungstausch (§7.5). **Ein Mechanismus statt dreier.**

**Umsetzung, zweistufig wie bei der Tauschsperre (Fallstrick 6):**

- *Oberfläche:* `saetzeSperren()` in `index.js` setzt bei abgehakter Übung alle Satzfelder auf
  `readonly` und deaktiviert Stepper, ✕ und „+ Satz". Aufgerufen aus `zustandSetzen()` **und**
  am Ende von `saetzeZeichnen()` — die Zeilen entstehen über `innerHTML` neu und wüssten
  sonst nichts vom Häkchen.
- *Server:* `abgeschlossene_position_schuetzen()` in `api/log.php` weist eine Änderung an
  einer Position mit `done = 1` mit **409** ab. Das ist die eigentliche Regel; die
  ausgegrauten Felder sind nur die Bequemlichkeit davor.

**Die Ausnahme, die man nicht vergessen darf:** Eine **unverändert** durchgereichte Nutzlast
muss durchgehen. Die Warteschlange schickt einen Eintrag nach einem Funkloch erneut, und der
zweite Aufruf trifft dann auf die bereits abgehakte Position — ohne diese Ausnahme schlüge er
mit 409 fehl und zeigte dem Benutzer einen Fehler, obwohl längst alles gespeichert ist.
Verglichen wird deshalb inhaltlich (`saetze_gleich()`), Gewichte nie mit `===`: 40.0 aus der
Datenbank und 40.0 aus der Eingabe sind dasselbe Gewicht, aber nicht zwingend dasselbe
Bitmuster.

**Nebenbei mitgenommen:** Die Sperre gilt jetzt auch für das **Gewichtsfeld im
Standardmodus**. §7.4 verlangte sie dort seit `1.0.3`, durchgesetzt hatte sie aber nur die
Oberfläche — über einen zweiten Tab war sie zu umgehen.
