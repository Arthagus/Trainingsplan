# Stand des Systems

Der **flüchtige** Teil der Projektdokumentation: was gerade läuft, welche Daten drin sind,
was offen ist. Dauerhaftes Wissen — Architektur, Konventionen, Fallstricke — steht in
`CLAUDE.md` und veraltet nicht.

**Diese Datei nach jedem Rollout nachziehen.** Sie ist die einzige Stelle mit
Versionsnummern und Zählständen; wenn sie hier falsch sind, sind sie nirgends sonst falsch.

*Letzte Aktualisierung: 2026-08-17*

---

## Ausgerollt

> ## Live läuft `trainingsplan:1.1.14` — im Arbeitsstand liegt `1.1.15`
>
> **Nachgemessen, nicht aus der Erinnerung:** `curl` auf `login.php` liefert
> `app.js?v=1.1.14`. Die Asset-Adressen tragen seit `1.1.8` die Version und sind ohne
> Anmeldung lesbar — das ist die verlässliche Auskunft darüber, was wirklich läuft.
>
> - `1.1.11` (2026-08-17) brachte die Sperre samt Migration `users.blocked_at` und ist
>   **am Live-System durchgeprüft** — die Belege stehen unten beim jeweiligen Punkt.
> - `1.1.13` (2026-08-17): Orange statt Rot für „gesperrt", weiße
>   Schrift im Knopf. `1.1.12` steckt darin.
> - `1.1.14` (2026-08-17) bündelte drei Dinge: die weiße Schrift auch im
>   „Gesperrt"-Abzeichen, die Trainingsleiste am oberen Rand und die wählbare
>   Satz-Vorbelegung. Damit lief auch die Migration `users.satz_vorlage`.
> - **`1.1.15` ist noch nicht gebaut** und sammelt zwei Rückmeldungen: die
>   Verbindungsleiste rutscht unter die Trainingsleiste (die obere Zeile springt beim
>   Abhaken nicht mehr), und im Verlauf steht bei den Einheiten das 1RM statt des
>   Maximalgewichts, dazu drei getrennte Erklärungen. Reine Anzeige, kein Schema.
>
> **Die Nummer `1.1.14` ist am 2026-08-17 ein zweites Mal vergeben worden**, und zwar
> bewusst: Das erste Paket unter dieser Nummer war zwar gebaut, aber **nie ausgerollt** —
> und dann ist Überschreiben richtig, genau dafür warnt `paket_bauen.sh` nur, statt
> abzubrechen. Wäre es draußen gewesen, hätte es auf `1.1.15` gehen müssen: Zwei
> verschiedene Stände unter einem Namen sind der Fehler, den die Nummer verhindern soll.
>
> **Ebenfalls am 2026-08-17: `1.1.15` wurde einmal ungefragt gepackt.** Ein Paket entsteht
> ausschließlich auf ausdrückliche Ansage — das steht in `CLAUDE.md` unter *Deployment* und
> war hier übersehen worden. Weil das Paket den Rechner nie verlassen hat, ist die Nummer
> **wieder frei**: Sie ist gelöscht und der laufende Arbeitsstand führt sie weiter.
> **Nummern werden nicht übersprungen, wenn dazwischen nichts gebaut wurde** — der Sprung
> auf `1.1.16` wäre eine Lücke ohne Gegenstück gewesen.
>
> **Diese Liste war am 2026-08-17 einmal falsch** (sie nannte `1.1.11` als live, während
> `1.1.13` lief), weil nach einem Rollout niemand nachgezogen hat. Wenn Zweifel bestehen:
> die Versionsnummer aus der Asset-Adresse holen, nicht schätzen.
>
> **`1.1.9` ist nie ausgerollt worden.** Die Nummer war durch ein gebautes Paket vergeben,
> die Sitzungs-Korrektur hat deshalb auf `1.1.10` aufgesetzt — `1.1.9` steckt vollständig
> darin, und `paket_bauen.sh` hat das ältere Paket beim Bauen selbst weggeräumt.
>
> Alles darunter ist die Vorgeschichte, neueste zuerst.

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

## Datenstand

Einzelheiten in `bestand_gruppen_uebungen.md`.

| | |
|---|---|
| Muskelgruppen | Zweistufig: Hauptgruppen mit Untergruppen darunter. Die Gliederung steht in `bestand_gruppen_uebungen.md` |
| Übungen | Wächst laufend, **alle mit Trainingsgerät**. Der Bestand steht in der App und wird hier bewusst nicht gezählt — welche Zahl gerade gilt, ist für die Entwicklung ohne Belang, und eine notierte Zahl ist am Tag danach falsch. Genutzt werden bisher Maschine, Kabelzug, Kurzhantel und Körpergewicht; Multipresse, Langhantel und Kettlebell stehen bereit, sind aber unbenutzt |
| Benutzer | `Oliver` (id 1, Admin) · `claude` (id 2, Admin) · `Nele` (id 3) — Namen ab `1.0.8` änderbar. Wer den Expertenmodus eingeschaltet hat, steht in `users.expert_mode` und wird hier nicht mitgeführt: Es ist eine persönliche Einstellung, die sich jederzeit ändert |
| Pläne | `Oliver`: Push, Pull · `Nele`: Ganzkörper A, Ganzkörper B — je 8 Positionen |
| Trainingseinheiten | 5, mit 34 Protokollzeilen (Wartungsseite, 2026-08-12) |
| Sätze (`workout_sets`) | **28** (2026-08-12) — sämtlich aus der ersten echten Expertenmodus-Einheit vom 2026-08-11 |
| Sicherungen | 3 — die jüngste vom 2026-08-12 17:03 (1,6 MB mit Bildern), davor 2026-08-11 16:13 und 2026-08-07 |

Zahlen vom 2026-08-12, abgelesen auf der Wartungsseite: 3 Benutzer, 27 Muskelgruppen,
31 Übungen, 4 Pläne, 5 Einheiten, 34 Protokollzeilen, 28 Sätze, 62 Bilder (1,6 MB),
Datenbank 164 KB. Sie veralten schnell und stehen hier nur als Größenordnung.

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

## Offen

0. **Veralteter Kopfkommentar in `api/session.php`** — gefunden am 2026-08-17 beim
   Durchsehen von `CLAUDE.md`. Dort steht noch:

   > Eine Einheit entsteht auf **DREI** Wegen … 2. beim Abhaken der ersten Übung
   > (`api/log.php`), 3. beim Tausch „nur diese Einheit" (`api/swap.php`).

   Das gilt **seit `1.1.6` nicht mehr**: `einheit_sicherstellen()` hat genau einen Aufrufer,
   die beiden anderen Endpunkte antworten mit 409 (Fallstrick 1). Ein zweiter Absatz
   derselben Datei nennt die Wege 2 und 3 sogar noch als gewollt.

   **Warum es noch dasteht:** `api/session.php` steckt im Deployment-Paket, und `1.1.15` ist
   bereits gebaut. Eine Änderung daran hübe sofort auf `1.1.16` und machte das frische Paket
   überholt — für einen Kommentar zu teuer. **Beim nächsten ohnehin anstehenden
   Codewechsel mitnehmen**, oder auf Ansage sofort.

   Das ist die gefährlichste Sorte veralteter Doku: Sie steht direkt neben dem Code, den sie
   falsch beschreibt, und wer nur die Datei liest, glaubt ihr.

1. **`1.1.6` im Studio erproben** — angekündigt für 2026-08-13, das nächste Training. Worauf
   zu achten ist, weil `curl` es nicht sehen kann:

   | Was | Was passieren muss |
   |---|---|
   | Plan öffnen, ohne zu starten | „Erledigt", „+ Satz" und das Gewichtsfeld sind grau und reagieren nicht; „Tauschen" geht |
   | Vor dem Start „Tauschen" antippen | Nur **„Dauerhaft im Plan"** steht da, dazu der Hinweissatz — und danach läuft **kein** Training |
   | Eine besetzte Maschine auslassen, an der nächsten den ersten Satz eintragen | Die nächste wird **grün**, die ausgelassene **orange** (`#ff6600`) — und die Ansicht springt nach vorn, nicht zurück |
   | Zur ausgelassenen zurückgehen | Sie wird grün, das Orange verschwindet |
   | Nach dem Training | Der Vorschlag ist der **andere** Plan |

   Der orange Ton neben Blau und Grün ist dabei die einzige reine Geschmacksfrage — alles
   andere ist überprüfbares Verhalten.
2. **Zwei Abnahmekriterien am Handy** — 16 (ein Gerät abmelden) und die Gerätehälfte von 19
   (Expertenmodus). Die Gegenprobe der *Darstellung* deckt sie nicht ab; es sind einzelne,
   benannte Abläufe. **2, 3 und 6 sind am 2026-08-11 bestanden.**
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

## Prüfstand der 19 Abnahmekriterien (`LASTENHEFT.md` §11)

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

**Ein vollständiges Training im Expertenmodus hat inzwischen stattgefunden** (2026-08-11,
9 Positionen, 28 Sätze über knapp zwei Stunden), und die dabei entstandenen Daten sind
fehlerfrei — siehe *Datenstand*. Damit ist der **Datenpfad** am echten Gerät belegt. Ob
Stepper und Satzblock sich dabei gut bedienen ließen, ist eine Aussage, die nur der
Benutzer treffen kann; der **Flugmodus** blieb ungeprüft. Das Kriterium bleibt deshalb
offen, steht aber nicht mehr ganz am Anfang.

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

## Änderungen der letzten Versionen

Nur als Gedächtnisstütze; die *Begründungen* stehen dort, wo sie hingehören — im Code, in
`CLAUDE.md` unter „Fachliche Fallstricke" oder in `rueckmeldungen_praxistest.md`.

| Version | Was |
|---|---|
| `1.1.6` | Drei Punkte aus der Rückmeldung vom 2026-08-12. **Die Plan-Rotation liest ihren Ausgangspunkt aus der Historie** statt aus `users.last_plan_id` — die Spalte wurde nur beim *Beenden* geschrieben und beim *Löschen* nie zurückgenommen, eine gelöschte Testeinheit verstellte den Vorschlag also dauerhaft. Gezählt wird **jede** Einheit, auch eine leere: Die Rotation richtet sich starr nach der Historie, sauber halten ist Sache des Benutzers. **Ein Training beginnt ausschließlich mit „Training starten"** — `einheit_sicherstellen()` hat genau einen Aufrufer, `api/log.php` und `api/swap.php` antworten mit 409, und vor dem Start sind „Erledigt", „+ Satz" und das Gewichtsfeld gesperrt; dauerhaft tauschen bleibt möglich, „nur diese Einheit" nicht. **Übersprungene Übungen sind orange** (`#ff6600`), und „aktiv" heißt jetzt „wo gerade protokolliert wird" statt „die erste offene" — `positions_zustaende()` in PHP, `aktiveMarkieren()` in JS. Cache `v19` *(live seit 2026-08-12)* |
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
