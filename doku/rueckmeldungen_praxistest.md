# Rückmeldungen aus dem Praxistest

Gesammelt am **2026-08-07** nach dem ersten echten Training im Studio mit
`trainingsplan:1.0.2`. Alles hier ist **noch nicht umgesetzt**.

Reihenfolge = Nummerierung des Benutzers, nicht Priorität. Die Einschätzung „Aufwand" ist
grob und dient nur der Planung.

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
und die Datenlage wird uneindeutig. (Live genutzt wird das nicht — alle 17 Übungen hängen
an Untergruppen.)

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
