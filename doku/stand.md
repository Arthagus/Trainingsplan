# Stand des Systems

Was **gerade** gilt: laufende Version, Datenbestand, offene Punkte. Kurz zu halten ist
Teil des Zwecks — wer hier blättern muss, findet nichts.

**Die Chronik steht in `doku/historie.md`**: was wann ausgerollt wurde, welche Version was
brachte, was dabei schiefging. Dauerhaftes Wissen — Architektur, Konventionen,
Fallstricke — steht in `CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.**

*Letzte Aktualisierung: 2026-08-23*

---

## Läuft gerade

| | |
|---|---|
| **Live** | `trainingsplan:1.2.12` — **gebaut am 2026-08-23 und unmittelbar danach eingespielt.** Noch nicht nachgemessen; der Befehl unten sagt in Sekunden, ob es stimmt. Die Gegenprobe am Gerät steht aus: *Offen*, Punkte 1 und 2 |
| **Arbeitsstand** | `1.2.12`, deckungsgleich mit live |
| **Rollback-Ziel** | `trainingsplan:1.2.11` — dieses Image in Portainer stehen lassen. `1.2.11` ist am Live-System durchgeprüft (Splits, Zurücksetzen, Historie) und damit ein belastbarer Stand |

**Ein gebautes Paket geht sofort live.** Der Benutzer spielt jede Version, die er bauen
lässt, unmittelbar danach ein — am 2026-08-19 ausdrücklich so festgelegt. Daraus folgt für
die Zählweise: **Sobald `paket_bauen.sh` unter einer Nummer gelaufen ist, ist sie
vergeben**, und die nächste Änderung an etwas, das im Paket steckt, hebt auf die nächste.
Die frühere Rückfrage „ist die Nummer schon draußen?" entfällt, und es entstehen keine
Lücken.

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

1. **Gegenprobe am Gerät zu `1.2.10`** — ausgerollt, aber alle drei Punkte sind rein
   optisch und deshalb **ungeprüft**, bis der Benutzer sie gesehen hat. Mit `1.2.11` sind
   sie weiterhin offen; die beiden Listen lassen sich in einem Durchgang abarbeiten.
   Gemeldet am 2026-08-23 vom iPhone: Der Balken
   „… wird gespeichert" erschien an der richtigen Stelle, schob dabei aber die ganze Seite
   eine Zeilenhöhe nach unten und beim Verschwinden wieder hinauf, also bei **jedem**
   Abhaken. Er schwebt jetzt unter dem Leisten-Stapel, statt darin Platz zu belegen
   (`.leiste-schwebt`, Fallstrick 19d).

   **Auf dem Pixel war das nie zu sehen** — dort gleicht Scroll Anchoring die
   Layoutänderung aus, WebKit kennt das nicht. **Die Gegenprobe gehört deshalb aufs
   iPhone**; ein ruhiges Bild am Pixel sagt hier nichts, es sagte auch vorher nichts:

   | Was | Was passieren muss |
   |---|---|
   | Übung abhaken | Der Balken blitzt auf, **nichts darunter bewegt sich** — Trainingsleiste, Kopfzeile und Kartenliste stehen still |
   | Weiterspringen danach | Die nächste Karte landet **unter** dem Balken, nicht dahinter — der Übungsname bleibt lesbar |
   | Flugmodus einschalten, abhaken | „Keine Verbindung …" **darf** einmal schieben und muss stehen bleiben, ohne den Inhalt darunter zu verdecken |

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
| Netz trennen, eine Übung abhaken | Häkchen **bleibt** stehen, Zeile bekommt einen gestrichelten Rand und „Noch nicht gespeichert", rote Leiste oben mit der Zahl |
| Noch zwei abhaken | Zähler in der Leiste steigt, „x/n" zählt mit |
| Netz wieder einschalten | Leiste verschwindet, gestrichelte Ränder werden grün — ohne Zutun |
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
