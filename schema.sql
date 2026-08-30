-- Schema der Trainingsplan-App.
--
-- Wird bei JEDEM Start ausgefuehrt (init_schema() in lib/db.php), deshalb
-- ausschliesslich CREATE TABLE IF NOT EXISTS und CREATE INDEX IF NOT EXISTS.
-- Nachtraeglich hinzukommende Spalten gehoeren NICHT hierher, sondern als
-- idempotenter PRAGMA-table_info-Block in apply_migrations() -- SQLite kennt
-- kein "ADD COLUMN IF NOT EXISTS".
--
-- Zeitstempel sind TEXT im Format 'Y-m-d H:i:s' und werden IMMER in PHP
-- erzeugt. Kein CURRENT_TIMESTAMP als Default: SQLite liefert dort UTC,
-- die App laeuft auf Europe/Vienna.
--
-- Zum ON-DELETE-Verhalten siehe LASTENHEFT.md §4.1. Leitgedanke: Die Historie
-- (sessions, workout_log) ueberlebt das Loeschen von Benutzern, Plaenen und
-- Planpositionen. Wo sie das nur als Waise kann, steht SET NULL; wo Loeschen
-- die Historie zerreissen wuerde, steht RESTRICT.


-- ---------------------------------------------------------------------------
-- Stammdaten
-- ---------------------------------------------------------------------------

-- Zwei Ebenen: Hauptgruppen (parent_id IS NULL) und ihre Untergruppen.
--
-- Der Tausch (§7.5) vergleicht auf HAUPTGRUPPEN-Ebene. Damit darf die
-- Unterteilung beliebig fein werden, ohne die Vorschlaege auszuduennen:
-- Eine Uebung haengt an "Brust (oben)", als Ersatz kommt alles unter "Brust".
--
-- ON DELETE RESTRICT: Eine Hauptgruppe mit Untergruppen laesst sich nicht
-- loeschen, ohne diese vorher umzuhaengen -- sonst entstuenden Waisen, die
-- nirgends mehr auftauchen.
CREATE TABLE IF NOT EXISTS muscle_groups (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name_de     TEXT    NOT NULL UNIQUE,
    name_en     TEXT,
    parent_id   INTEGER REFERENCES muscle_groups(id) ON DELETE RESTRICT,
    sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_muscle_groups_sort
    ON muscle_groups(sort_order, name_de);

-- Der Index auf parent_id steht bewusst NICHT hier, sondern in
-- apply_migrations(). Grund: schema.sql laeuft VOR den Migrationen. Auf einer
-- Bestandsdatenbank gibt es die Spalte an dieser Stelle noch nicht -- der
-- Index wuerde scheitern, die Ausnahme den ganzen Start abbrechen, und die
-- Migration, die die Spalte anlegt, waere nie erreicht.
--
-- REGEL: schema.sql darf nichts voraussetzen, was erst eine Migration schafft.


-- focus ("Ausfuehrung" in der Oberflaeche) traegt, was KEIN Muskel ist:
-- Bewegungsrichtung, Koerperhaltung, Geraet -- "vertikal", "stehend",
-- "in gedehnter Position". Reine Anzeige-Information, die Tauschlogik
-- ignoriert es.
--
-- Welche Muskelpartie getroffen wird, gehoert dagegen als UNTERGRUPPE in
-- muscle_groups (etwa "Brust (oben)"). Beides sauber zu trennen war die
-- Lehre aus 2026-08-07: vorher stand die Partie mal hier, mal dort.
--
-- equipment traegt das WOMIT als Schluessel aus der Codeliste GERAETE in
-- lib/geraete.php ("kurzhantel", "kabel", ...). Bewusst ohne CHECK-Constraint:
-- SQLite kann eine CHECK-Klausel nur ueber einen Tabellen-Neuaufbau aendern,
-- und ein achter Geraetetyp soll eine Zeile PHP kosten, keine Migration.
-- Geprueft wird in api/exercises.php. In der Oberflaeche ist das Feld Pflicht;
-- die Spalte laesst NULL zu, weil Uebungen aus der Zeit davor keinen Wert
-- haben und in der Liste als "Geraet fehlt" angemahnt werden.
--
-- erfassung sagt, WIE protokolliert wird: 'kraft' (Wiederholungen und Gewicht)
-- oder 'ausdauer' (Distanz und Zeit). Codeliste ERFASSUNG in lib/geraete.php,
-- geprueft in api/exercises.php, wieder ohne CHECK aus demselben Grund.
--
-- Sie haengt an der UEBUNG und nicht am Geraet -- der naheliegende Griff waere,
-- sie aus equipment abzuleiten (Laufband = Ausdauer), und der ist falsch herum:
-- Die Uebung ist die Taetigkeit und bestimmt, was gemessen wird; das Geraet
-- sagt nur, wo sie stattfindet. Ausserdem kippte eine abgeleitete Erfassungsart
-- rueckwirkend die Bedeutung bereits protokollierter Werte, sobald jemand ein
-- Geraet umtraegt. Anders als bei equipment ist der Vorgabewert hier richtig
-- und nicht geraten: Vor 1.4.0 gab es nur eine Art zu protokollieren.
CREATE TABLE IF NOT EXISTS exercises (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name_de     TEXT    NOT NULL,
    name_en     TEXT,
    description TEXT,
    focus       TEXT,
    equipment   TEXT,
    erfassung   TEXT    NOT NULL DEFAULT 'kraft',
    image_path  TEXT,
    -- Welche Seite beim quadratischen Zuschnitt wegfaellt: 'links', 'mitte'
    -- oder 'rechts'. Wirkt allein ueber object-position im Stylesheet, die
    -- Bilddatei bleibt unberuehrt. Codeliste ZUSCHNITT in lib/geraete.php.
    image_crop  TEXT    NOT NULL DEFAULT 'mitte',
    archived    INTEGER NOT NULL DEFAULT 0 CHECK (archived IN (0, 1)),
    archived_at TEXT,
    created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_exercises_archived
    ON exercises(archived, name_de);


-- Zuordnung Uebung <-> Muskelgruppen (n:m).
-- Genau eine Zuordnung je Uebung traegt is_primary = 1 -- das ist die Gruppe,
-- WEGEN der man die Uebung macht, und die einzige Grundlage der Tauschlogik
-- (§7.5). muscle_group_id mit RESTRICT: eine Muskelgruppe, auf die noch etwas
-- zeigt, darf nicht verschwinden (§4.1).
CREATE TABLE IF NOT EXISTS exercise_muscle_groups (
    exercise_id     INTEGER NOT NULL REFERENCES exercises(id)     ON DELETE CASCADE,
    muscle_group_id INTEGER NOT NULL REFERENCES muscle_groups(id) ON DELETE RESTRICT,
    is_primary      INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
    PRIMARY KEY (exercise_id, muscle_group_id)
);

-- Macht "genau eine Primaergruppe" zur Datenbankregel statt zur Konvention.
-- Beim Umsetzen deshalb erst alle auf 0, dann die neue auf 1 -- in EINER
-- Transaktion, sonst schlaegt der Index zwischendurch zu.
CREATE UNIQUE INDEX IF NOT EXISTS idx_emg_one_primary
    ON exercise_muscle_groups(exercise_id) WHERE is_primary = 1;

CREATE INDEX IF NOT EXISTS idx_emg_group
    ON exercise_muscle_groups(muscle_group_id);


-- ---------------------------------------------------------------------------
-- Benutzer und Anmeldung
-- ---------------------------------------------------------------------------

-- last_plan_id ist seit 1.1.6 UNBENUTZT und wird weder gelesen noch
-- geschrieben. Die Rotation liest ihren Ausgangspunkt in
-- zuletzt_trainierter_plan() aus der Historie, weil die Spalte nur beim
-- Beenden einer Einheit gepflegt wurde und deren Loeschen nicht mitbekam --
-- eine geloeschte Testeinheit verstellte den Vorschlag dauerhaft. Die Spalte
-- bleibt trotzdem stehen: Sie zu entfernen waere eine loeschende Migration
-- ohne Gegenwert. Nicht wieder in Betrieb nehmen.
-- Sie zeigt auf plans, das weiter unten erst angelegt wird. SQLite prueft
-- Fremdschluessel-Ziele nicht beim CREATE, sondern erst beim Schreiben -- die
-- Reihenfolge ist also unkritisch.
-- expert_mode schaltet die satzgenaue Erfassung ein (§7.4). Die Spalte steuert
-- ausschliesslich die DARSTELLUNG -- api/log.php nimmt Saetze unabhaengig davon
-- entgegen. Auf Bestandsdatenbanken kommt sie ueber apply_migrations() dazu,
-- dort allerdings ohne CHECK: ALTER TABLE kann in SQLite keines nachtragen.
CREATE TABLE IF NOT EXISTS users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    name                 TEXT    NOT NULL UNIQUE,
    password_hash        TEXT    NOT NULL,
    is_admin             INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
    must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1)),
    -- TOT seit 1.4.3: Es gibt nur noch die satzgenaue Erfassung, der
    -- einfache Modus ist ersatzlos entfallen (§7.4). Die Spalte bleibt
    -- stehen wie users.last_plan_id -- ein DROP COLUMN ist destruktiv und
    -- braechte nichts. NICHT wieder in Betrieb nehmen.
    expert_mode          INTEGER NOT NULL DEFAULT 0 CHECK (expert_mode IN (0, 1)),
    -- Woher die Vorbelegung eines neu hinzugefuegten Satzes kommt (§7.4).
    -- Codeliste SATZ_VORLAGE in lib/training.php, kein CHECK -- dieselbe
    -- Begruendung wie bei equipment und image_crop (Fallstrick 16): Eine
    -- dritte Variante soll eine Zeile PHP kosten und keinen Tabellen-Neubau.
    -- Wirkt nur im Expertenmodus; im einfachen Modus gibt es keine Saetze.
    satz_vorlage         TEXT    NOT NULL DEFAULT 'gleicher_satz',
    last_plan_id         INTEGER REFERENCES plans(id) ON DELETE SET NULL,
    -- Welcher Split gerade gewaehlt ist (§7.6). NULL = noch keiner; dann
    -- verweist die Trainingsansicht auf splits.php.
    --
    -- Das ist eine AUSWAHL und keine ableitbare Tatsache -- deshalb darf sie
    -- als Spalte stehen, anders als last_plan_id daneben. Die Rotation INNERHALB
    -- des Splits wird weiterhin aus der Historie gelesen und nirgends notiert.
    -- Damit die Auswahl nicht veraltet, setzt sie auch der Start einer Einheit,
    -- und aktiver_split() faellt bei NULL auf den Split der letzten Einheit
    -- zurueck, sonst auf den ersten eigenen.
    active_split_id      INTEGER REFERENCES splits(id) ON DELETE SET NULL,
    -- Gesperrt: NULL = aktiv, sonst der Zeitpunkt der Sperre (§6.1).
    --
    -- EINE Spalte und nicht das Paar blocked/blocked_at, wie es exercises mit
    -- archived/archived_at vorexerziert. Zwei Spalten fuer dieselbe Aussage
    -- koennen sich widersprechen (blocked = 1 bei blocked_at IS NULL), und dann
    -- haengt das Verhalten davon ab, welche der beiden zufaellig gelesen wird.
    -- Das ist dieselbe Falle wie bei users.last_plan_id (Fallstrick 21), nur
    -- eine Nummer kleiner. Das Sperrdatum will die Oberflaeche ohnehin anzeigen.
    blocked_at           TEXT,
    created_at           TEXT    NOT NULL
);


-- "Angemeldet bleiben" nach Selector/Validator-Muster (§5).
-- In validator_hash steht NUR der Hash des Validators, nie der Validator selbst.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector       TEXT    NOT NULL UNIQUE,
    validator_hash TEXT    NOT NULL,
    expires_at     TEXT    NOT NULL,
    last_used_at   TEXT,
    user_agent     TEXT,
    created_at     TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_remember_tokens_user
    ON remember_tokens(user_id, created_at DESC);


-- Brute-Force-Bremse. Bewusst eine Tabelle und nicht $_SESSION: ein
-- sessionbasierter Zaehler ist durch Loeschen des Cookies zu umgehen.
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ip           TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time
    ON login_attempts(ip, attempted_at);


-- ---------------------------------------------------------------------------
-- Splits und Plaene
-- ---------------------------------------------------------------------------

-- Ein Workout-Split buendelt die Plaene, die miteinander rotieren:
-- "Push / Pull" mit Push und Pull, "Ganzkoerper" mit A und B (§6.4, §7.6).
--
-- user_id IS NULL  -> VORLAGE. Fuer alle sichtbar, nur ein Admin bearbeitet
--                     sie, und NIEMAND trainiert darauf. Sie ist ein Katalog.
-- user_id = X      -> PERSOENLICHER Split von X. Nur X (und ein Admin) sieht
--                     und bearbeitet ihn, und NUR darauf wird trainiert.
--
-- Es gibt keinen dritten Zustand und keine Verbindung zwischen beiden: Wer
-- eine Vorlage benutzen will, KOPIERT sie zu sich (split_kopieren() in
-- lib/splits.php). Danach sind beide Seiten unabhaengig -- eine spaetere
-- Aenderung an der Vorlage wandert ausdruecklich NICHT nach, und ein
-- dauerhafter Tausch beim Benutzer beruehrt die Vorlage nicht. Genau das ist
-- der Zweck der Kopie: Zwei Benutzer duerfen denselben Split fahren, ohne
-- sich gegenseitig in den Bestand zu schreiben.
--
-- Kein UNIQUE auf name: Zwei Benutzer duerfen denselben Splitnamen haben, und
-- derselbe Benutzer darf mehrere Fassungen einer Vorlage nebeneinander halten
-- ("Push / Pull" und "Push / Pull (2)"). Die Unterscheidung ist Sache des
-- Namens, nicht der Datenbank.
-- vorlage_id: aus WELCHER Vorlage diese Kopie stammt (seit 1.2.11, §6.4).
--
-- Bis 1.2.10 gab es diesen Verweis ausdruecklich NICHT -- eine Kopie war von
-- ihrer Vorlage vollstaendig geloest, und Fallstrick 24 sagte, wer ihn je
-- einbaue, nehme der Kopie ihren Zweck. Das gilt weiterhin fuer den BETRIEB:
-- Es gibt keine Vererbung, kein automatisches Nachziehen, keine Rueckwirkung
-- von der Kopie auf die Vorlage. Der Verweis ist reine Herkunftsangabe und
-- wird an genau EINER Stelle ausgewertet -- "Auf Vorlage zurücksetzen", und
-- das nur, wenn der Benutzer ihn drueckt.
--
-- ON DELETE SET NULL: Verschwindet die Vorlage, verliert die Kopie ihre
-- Herkunft und der Knopf mit ihr. Richtig so -- es gaebe nichts mehr, womit
-- man abgleichen koennte.
--
-- NULL heisst "keine Vorlage bekannt" und ist der Normalfall: selbst angelegte
-- Splits, die Migration aus 1.2.0, und jede Vorlage selbst.
CREATE TABLE IF NOT EXISTS splits (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
    name         TEXT    NOT NULL,
    beschreibung TEXT,
    sort_order   INTEGER NOT NULL DEFAULT 0,
    vorlage_id   INTEGER REFERENCES splits(id) ON DELETE SET NULL,
    created_at   TEXT    NOT NULL
);

-- Der Index steht auch in apply_migrations(), damit eine Bestandsdatenbank
-- ihn ebenfalls bekommt. Hier ist er unkritisch: splits entsteht als ganze
-- Tabelle, die Spalte gibt es also in jedem Fall.
CREATE INDEX IF NOT EXISTS idx_splits_user
    ON splits(user_id, sort_order);


-- split_id traegt die Zugehoerigkeit. Es steht hier UND als ALTER TABLE in
-- apply_migrations() -- dieselbe Doppelung wie bei exercises.image_crop: die
-- eine Haelfte fuer die frische Datenbank, die andere fuer den Bestand.
--
-- user_id ist seit 1.2.0 TOT und wird ausserhalb der Migration weder gelesen
-- noch fuer Zugriffsentscheidungen benutzt; wem ein Plan gehoert, sagt allein
-- splits.user_id. Sie bleibt aus einem einzigen, aber zwingenden Grund stehen:
-- Wird eine Sicherung von VOR 1.2.0 eingespielt, stehen dort wieder Plaene
-- ohne split_id, und nur user_id sagt dann noch, wem sie gehoeren. Ohne die
-- Spalte waeren solche Plaene unrettbar unsichtbar -- die Migration in
-- apply_migrations() ist der einzige Leser. Neu angelegte Plaene fuellen sie
-- weiterhin (NOT NULL), bei Vorlagen mit der ID des handelnden Admins.
-- Nicht wieder in Betrieb nehmen; dasselbe gilt fuer users.last_plan_id.
CREATE TABLE IF NOT EXISTS plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    split_id   INTEGER          REFERENCES splits(id) ON DELETE CASCADE,
    name       TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_plans_user
    ON plans(user_id, sort_order);


-- exercise_id mit RESTRICT: Eine Uebung, die in einem Plan steht, laesst sich
-- nicht hart loeschen -- sie wird archiviert (§6.3). Der Fremdschluessel setzt
-- das durch, statt sich auf die Anwendungslogik zu verlassen.
-- session_id traegt die Ausnahme: eine Position, die NUR zu einer Einheit
-- gehoert (§7.6, seit 1.4.2). NULL heisst "gehoert zum Plan" -- das ist der
-- Regelfall und der Zustand jeder Zeile vor 1.4.2.
--
-- Warum die Zeile hier steht und nicht in einer eigenen Tabelle: workout_log
-- haengt ueber plan_exercise_id an genau dieser id (§4). Eine Uebung, die nur
-- heute dazukommt, braucht deshalb eine echte Planposition -- sonst waere ihr
-- Protokolleintrag nicht zuzuordnen, "x/n" nicht zaehlbar und die Tauschsperre
-- ohne Anker.
--
-- Und warum sie nach der Einheit STEHEN BLEIBT statt geloescht zu werden:
-- Loeschen setzt workout_log.plan_exercise_id ueber ON DELETE SET NULL auf
-- NULL, und damit verloere der Verlauf die Zuordnung -- lautlos. Die Zeile
-- bleibt und wird ueberall dort ausgeblendet, wo der PLAN gemeint ist.
CREATE TABLE IF NOT EXISTS plan_exercises (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    plan_id     INTEGER NOT NULL REFERENCES plans(id)     ON DELETE CASCADE,
    exercise_id INTEGER NOT NULL REFERENCES exercises(id) ON DELETE RESTRICT,
    session_id  INTEGER          REFERENCES sessions(id)  ON DELETE CASCADE,
    sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_plan_exercises_plan
    ON plan_exercises(plan_id, sort_order);


-- ---------------------------------------------------------------------------
-- Trainingseinheiten und Protokoll
-- ---------------------------------------------------------------------------

-- Offene Einheit = ended_at IS NULL. Die Einheit ist die Einheit der
-- Trainingslogik, nicht der Kalendertag -- sie laeuft ueber Mitternacht weiter.
--
-- user_id und plan_id sind NULLBAR, obwohl eine laufende Einheit immer beides
-- hat. Grund: §4.1 verlangt, dass sessions und workout_log das Loeschen eines
-- Benutzers bzw. eines Plans ueberleben. Mit NOT NULL bliebe nur RESTRICT, und
-- damit waere kein Benutzer mit Trainingshistorie mehr loeschbar.
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    plan_id    INTEGER REFERENCES plans(id) ON DELETE SET NULL,
    started_at TEXT    NOT NULL,
    ended_at   TEXT
);

-- Hoechstens eine offene Einheit je Benutzer. Partieller Unique-Index, damit
-- das nicht bloss in der Anwendungslogik steht: zwei parallele Requests aus dem
-- Studio wuerden sonst zwei Einheiten oeffnen.
CREATE UNIQUE INDEX IF NOT EXISTS idx_sessions_one_open
    ON sessions(user_id) WHERE ended_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_sessions_user_time
    ON sessions(user_id, started_at DESC);


-- Ein Eintrag je Einheit und PLANPOSITION -- nicht je Uebung.
-- Nach einem Tausch (§7.5) steht in exercise_id die Ersatzuebung; ohne
-- plan_exercise_id waere weder "x/n" zaehlbar noch der Schluessel eindeutig,
-- sobald die Ersatzuebung ohnehin schon im Plan steht.
--
-- weight ist nullbar: Abhaken ohne Gewichtsangabe ist erlaubt (Bauch, Dips
-- ohne Zusatzgewicht). Genau deshalb ueberspringt die Abfrage fuer "letztes
-- Gewicht" leere Werte.
--
-- Hier steht KEINE Wiederholungsspalte, und das ist dieselbe Begruendung wie
-- 2026-08-07: Bei drei Saetzen macht man z. B. 12, dann 10, dann 9 -- ein
-- einzelnes Feld je Einheit kann das nicht abbilden und taeuscht eine
-- Genauigkeit vor, die es nicht gibt. Wer satzgenau protokolliert, bekommt
-- deshalb Zeilen in workout_sets (siehe unten) und nicht Spalten hier.
--
-- weight bleibt auch dann gefuellt: Es traegt das LEITGEWICHT der Position --
-- im Expertenmodus den schwersten Satz. Nur so bleiben "letztes Gewicht" und
-- der Gewichtsverlauf ueber beide Modi hinweg eine durchgehende Reihe.
-- done trennt "hier steht etwas protokolliert" von "die Uebung ist fertig".
-- Im einfachen Modus fallen beide zusammen, deshalb die Vorgabe 1. Im
-- Expertenmodus nicht: Wer den ersten Satz eintraegt, ist noch lange nicht
-- fertig -- er will gleich den zweiten und dritten machen. Ohne diese Spalte
-- haekte sich die Uebung mit dem ersten Satz selbst ab (§7.4).
--
-- distanz_m und dauer_s sind das Gegenstueck fuer Ausdaueruebungen (1.4.0) und
-- stehen aus genau dem Grund hier, aus dem weight hier steht: Im einfachen
-- Modus gibt es ueberhaupt keine workout_sets-Zeilen, und der Verlauf
-- (uebungen_mit_verlauf(), gewichts_verlauf()) liest ausschliesslich diese
-- Tabelle. Sie tragen den Leitwert der Position.
--
-- ABER als SUMME und nicht als Maximum -- das ist der Unterschied zu weight:
-- Zwei Intervalle zu 1000 m sind 2000 gelaufene Meter, zwei Saetze zu 40 kg
-- sind keine 80 kg. Gespeichert wird in Sekunden; mm:ss ist reine Ein- und
-- Ausgabe (dauer_mmss() / dauer_aus_eingabe() in lib/helpers.php).
CREATE TABLE IF NOT EXISTS workout_log (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id       INTEGER NOT NULL REFERENCES sessions(id)       ON DELETE CASCADE,
    plan_exercise_id INTEGER          REFERENCES plan_exercises(id) ON DELETE SET NULL,
    user_id          INTEGER          REFERENCES users(id)          ON DELETE SET NULL,
    exercise_id      INTEGER NOT NULL REFERENCES exercises(id)      ON DELETE RESTRICT,
    plan_id          INTEGER          REFERENCES plans(id)          ON DELETE SET NULL,
    weight           REAL,
    distanz_m        INTEGER,
    dauer_s          INTEGER,
    done             INTEGER NOT NULL DEFAULT 1 CHECK (done IN (0, 1)),
    performed_at     TEXT    NOT NULL
);

-- Traegt den Upsert beim Abhaken. NULL-Werte gelten in SQLite als voneinander
-- verschieden -- historische Zeilen, deren Planposition spaeter entfernt wurde,
-- kollidieren hier also nicht miteinander.
CREATE UNIQUE INDEX IF NOT EXISTS idx_workout_log_session_position
    ON workout_log(session_id, plan_exercise_id);

-- Traegt "letztes Gewicht":
-- WHERE user_id = ? AND exercise_id = ? AND weight IS NOT NULL
-- ORDER BY performed_at DESC LIMIT 1
CREATE INDEX IF NOT EXISTS idx_workout_log_last_value
    ON workout_log(user_id, exercise_id, performed_at DESC);

CREATE INDEX IF NOT EXISTS idx_workout_log_session
    ON workout_log(session_id);


-- Die einzelnen Saetze einer Planposition im Expertenmodus (§7.4).
--
-- Haengt an workout_log und NICHT direkt an (session_id, plan_exercise_id):
-- Damit erledigt ON DELETE CASCADE das gesamte Aufraeumen von selbst. Ein
-- Ab-waehlen loescht die workout_log-Zeile -> Saetze weg; das Loeschen einer
-- Einheit loescht sessions -> workout_log -> Saetze. Kein einziger eigener
-- Loeschpfad, der vergessen werden koennte.
--
-- reps UND weight sind nullbar: Koerpergewichtsuebungen haben kein Gewicht,
-- Halte-Uebungen keine Wiederholungszahl. Ein Satz, in dem BEIDES leer ist,
-- wird von api/log.php abgelehnt -- er saegte nichts aus.
--
-- distanz_m und dauer_s sind dasselbe Feldpaar fuer Ausdaueruebungen (1.4.0);
-- welches von beiden Paaren gilt, entscheidet exercises.erfassung, und zwar
-- serverseitig -- nie die Nutzlast. Eine Zeile traegt immer nur EIN Paar, das
-- jeweils andere bleibt NULL. Die Ablehnungsregel gilt dort genauso: ohne
-- Distanz und ohne Zeit sagt ein Intervall nichts.
--
-- satz_nr wird beim Speichern immer neu von 1 an vergeben. Die Nutzlast
-- beschreibt die vollstaendige Satzliste, der Server ersetzt sie als Ganzes;
-- es gibt deshalb keine Luecken und kein Umnummerieren.
CREATE TABLE IF NOT EXISTS workout_sets (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    workout_log_id INTEGER NOT NULL REFERENCES workout_log(id) ON DELETE CASCADE,
    satz_nr        INTEGER NOT NULL,
    reps           INTEGER,
    weight         REAL,
    distanz_m      INTEGER,
    dauer_s        INTEGER
);

-- Traegt das Lesen in Satzreihenfolge und sichert zugleich, dass eine
-- Satznummer je Protokollzeile nur einmal vorkommt.
CREATE UNIQUE INDEX IF NOT EXISTS idx_workout_sets_nr
    ON workout_sets(workout_log_id, satz_nr);


-- Einmaliger Uebungstausch, an die EINHEIT gebunden -- nicht an den Plan.
-- Der Plan selbst bleibt unveraendert; mit dem Ende der Einheit verfaellt der
-- Tausch. Ein Tausch STARTET eine Einheit (§7.6): im Studio wird oft getauscht,
-- bevor die erste Uebung abgehakt ist.
CREATE TABLE IF NOT EXISTS exercise_swaps (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id              INTEGER NOT NULL REFERENCES sessions(id)       ON DELETE CASCADE,
    plan_exercise_id        INTEGER NOT NULL REFERENCES plan_exercises(id) ON DELETE CASCADE,
    replacement_exercise_id INTEGER NOT NULL REFERENCES exercises(id)      ON DELETE RESTRICT,
    created_at              TEXT    NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_exercise_swaps_session_position
    ON exercise_swaps(session_id, plan_exercise_id);
