# Stand des Systems

Was **gerade** gilt: laufende Version, Datenbestand, offene Punkte. Kurz zu halten ist
Teil des Zwecks — wer hier blättern muss, findet nichts.

**Die Chronik steht in `doku/historie.md`**: was wann ausgerollt wurde, welche Version was
brachte, was dabei schiefging. Dauerhaftes Wissen — Architektur, Konventionen,
Fallstricke — steht in `CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.**

*Letzte Aktualisierung: 2026-08-21*

---

## Läuft gerade

| | |
|---|---|
| **Live** | `trainingsplan:1.2.9` — gemessen am 2026-08-21: `app.js?v=1.2.9`, vom Benutzer am Gerät gegengeprüft |
| **Arbeitsstand** | `1.2.9`, deckungsgleich mit live |
| **Rollback-Ziel** | `trainingsplan:1.2.8` — dieses Image in Portainer stehen lassen |

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

1. **Studio-Erprobung der Splits** (`1.2.0` bis `1.2.4`) — **angekündigt für den
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

2. **Zwei Abnahmekriterien am Handy** — 16 (ein Gerät abmelden, jetzt auf der Kontoseite)
   und die Gerätehälfte von 19 (Expertenmodus). Die Gegenprobe der *Darstellung* deckt sie
   nicht ab; es sind einzelne, benannte Abläufe.
3. **Kriterium 17 (Restore) ist jetzt vollständig prüfbar** — seit dem 2026-08-11 liegt eine
   frische Sicherung *mit* Bildern vor, und damit erstmals eine, deren Einspielen den
   aktuellen Datenstand wiederherstellen würde statt ihn zurückzudrehen. Bisher fehlte genau
   dieses Stück. Wer es durchspielt, sollte danach ab- und neu anmelden (Fallstrick 14).
4. **`bestand_gruppen_uebungen.md` ist veraltet** — es listet die Übungen einzeln auf, samt
   Zählständen, und beides stimmt längst nicht mehr. Die Datei ist ein Überbleibsel aus der
   Zeit, als sie eine Eingabeanleitung war. Sinnvoller als Nachzählen wäre, sie auf das zu
   kürzen, was sich *nicht* täglich ändert: die Muskelgruppen-Gliederung und die
   Überlegungen zur Tauschregel. Der Übungsbestand steht in der App. **Seit dem 2026-08-16
   ist zusätzlich die Spalte *Ausführung* dort überholt** — sie trägt den Wortlaut der
   Aufbauphase, und der ist inzwischen bei jeder Übung ein anderer. Ein Grund mehr, die
   Tabelle zu streichen statt sie nachzuziehen.
5. **Sechs Fragen an die Übungsdaten** (2026-08-16, beim Überarbeiten der Texte aufgefallen).
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
