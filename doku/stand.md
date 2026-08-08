# Stand des Systems

Der **flüchtige** Teil der Projektdokumentation: was gerade läuft, welche Daten drin sind,
was offen ist. Dauerhaftes Wissen — Architektur, Konventionen, Fallstricke — steht in
`CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.** Sie ist die einzige Stelle mit
Versionsnummern und Zählständen; wenn sie hier falsch sind, sind sie nirgends sonst falsch.

*Letzte Aktualisierung: 2026-08-08*

---

## Ausgerollt

**`trainingsplan:1.0.12`** auf `training.jadefalke.net`, eingespielt am 2026-08-08.
Der Arbeitsstand im Repo entspricht dem laufenden Image.

**Seit `1.0.10` beantwortet die App diese Frage selbst:** Die Wartungsseite zeigt als erste
Kachel die Version des laufenden Images, gelesen aus der Datei `VERSION`. Bleibt sie nach
einem Stack-Update auf der alten Nummer stehen, läuft noch der alte Container. Diese Datei
hier bleibt trotzdem die Stelle, an der steht, *was* in einer Version drin ist.

`1.0.9` bis `1.0.12` liefen am 2026-08-08, `1.0.5` bis `1.0.8` am 2026-08-07 — jeweils
mehrere Nummern an einem Tag. Das ist kein Versehen: Ein bereits gebauter Tag wird nie
erneut gebaut, sonst trügen zwei verschiedene Stände denselben Namen.

**Gegen die laufende Instanz geprüft**, per `curl`:

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
aus `1.0.10` bis `1.0.12`.

## Datenstand

Einzelheiten in `bestand_gruppen_uebungen.md`.

| | |
|---|---|
| Muskelgruppen | 27 — zweistufig, 6 Hauptgruppen mit 21 Untergruppen |
| Übungen | 17, alle mit Bild |
| Benutzer | `Oliver` (id 1, Admin) · `claude` (id 2, Admin) · `Nele` (id 3) — Namen ab `1.0.8` änderbar |
| Pläne | `Oliver`: Push, Pull · `Nele`: Ganzkörper A, Ganzkörper B — je 8 Positionen |
| Trainingseinheiten | 1 (Oliver, Pull, 2026-08-06) |
| Sicherungen | 1 (ZIP mit Bildern, 774 KB, 2026-08-07) |

## Offen

1. **Warteschlange und Verbindungsleiste am Handy gegenprüfen** — der wichtigste offene
   Punkt, und gegen den Dev-Server grundsätzlich nicht prüfbar: `curl` hat weder
   Funkloch noch `localStorage`. Was zu sehen sein muss, steht unten unter *Gegenprobe
   schwaches Netz*.
2. **Menüpunkt heißt jetzt „Konto"** statt „Passwort" — beim nächsten Blick aufs Handy
   mitprüfen, ob die Kopfzeile damit noch umbricht wie gewollt.
3. **Darstellung am Handy gegenprüfen** — gegen den Dev-Server ist sie nicht
   prüfbar: Aktionszeile *Tauschen — Gewicht — Erledigt* mit mittigem Feld, größere Bilder
   im Training, Planpositionen mit Bild über beide Zeilen, Pfeile mittig, Kopfzeile mit
   Namen, großes Bild schließt den Dialog. Ob die **Bilder in den Tauschvorschlägen**
   tatsächlich ankommen, ließ sich lokal gar nicht prüfen: Dem CLI-PHP hier fehlt GD, es gab
   kein echtes Bild zum Hochladen — nur der erzeugte Pfad ist geprüft.

   Dazu aus `1.0.10` bis `1.0.12`: das **große Bild** durch Antippen in Übungs- und
   Planverwaltung, die **Übungszeile** mit Bild über beide Zeilen und rotem „Archivieren"
   rechts, die **Positionsnummern** in der Planverwaltung und deren **Textblock** mit
   Muskelgruppen und Ausführung.

   Der wichtigste Punkt davon ist der **Umbruch der Knopfzeile** in der Planverwaltung:
   Er greift erst unterhalb von 32rem, am Desktop schaltet die Zeile ins alte Raster
   zurück. Genau deshalb lief „Entfernen" seit `1.0.7` unbemerkt aus der Karte heraus —
   ein Fehler, den nur der Blick aufs Handy findet.
4. **Sicherung außer Haus schaffen.** Sie liegt im Datenvolume, also *neben* dem Original —
   bei einem Volume-Verlust wäre beides weg. Einmal über *Wartung → Herunterladen* holen und
   anderswo ablegen.
5. **Vier Abnahmekriterien am Handy**, siehe unten.

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
