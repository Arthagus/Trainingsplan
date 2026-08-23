<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Datenbankzugriff. db() ist der einzige Weg zur Verbindung und stellt
 * sicher, dass Schema, Seed und Erst-Admin vorhanden sind.
 */

/**
 * Halter fuer die PDO-Singleton-Referenz. Per Referenz, damit db() und
 * db_close() denselben static-Wert teilen.
 */
function &_db_holder(): ?PDO {
    static $pdo = null;
    return $pdo;
}

/**
 * Pfad zur SQLite-Datei.
 *
 * Anders als in den Vorlagen-Repos nicht hartkodiert: der Container bekommt
 * ihn ueber DB_PATH gesetzt, weil die Datei im Volume liegt.
 */
function db_path(): string {
    $env = getenv('DB_PATH');
    return ($env !== false && $env !== '') ? $env : __DIR__ . '/../data/trainingsplan.db';
}

/**
 * Liefert die Singleton-PDO-Verbindung und richtet die Datenbank beim ersten
 * Aufruf ein.
 */
function db(): PDO {
    $pdo = &_db_holder();
    if ($pdo !== null) {
        return $pdo;
    }

    $path = db_path();
    $dir  = dirname($path);

    if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
        throw new RuntimeException('Datenverzeichnis lässt sich nicht anlegen: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException(
            'Datenverzeichnis ist nicht beschreibbar: ' . $dir
            . ' — gehört das Volume dem Webserver-Benutzer (UID 33)?'
        );
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // WAL erlaubt Lesen waehrend geschrieben wird -- und ist der Grund, warum
    // ein Backup nie per Dateikopie entstehen darf (doku/deployment.md).
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    init_schema($pdo);

    return $pdo;
}

/**
 * Gibt die Verbindung frei (PDO hat kein close()).
 * Wird vor dem Ueberschreiben der Datei beim Restore gebraucht.
 */
function db_close(): void {
    $pdo = &_db_holder();
    $pdo = null;
}

/**
 * Legt Tabellen und Indizes an, zieht Migrationen nach und sorgt fuer
 * Muskelgruppen und Erst-Admin. Laeuft bei jedem Start; alles darin ist
 * idempotent.
 */
function init_schema(PDO $pdo): void {
    $sqlFile = __DIR__ . '/../schema.sql';
    $sql = @file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('schema.sql nicht lesbar: ' . $sqlFile);
    }
    $pdo->exec($sql);

    apply_migrations($pdo);
    seed_muscle_groups($pdo);
    bootstrap_first_admin($pdo);
}

/**
 * Nachtraeglich hinzugekommene Spalten.
 *
 * SQLite kennt kein "ADD COLUMN IF NOT EXISTS", deshalb je Spalte ein Blick in
 * PRAGMA table_info. Bewusst kein Migrationsverzeichnis und kein Werkzeug --
 * neue Spalten kommen als weiterer Block hierher, Muster:
 *
 *     if (!column_exists($pdo, 'exercises', 'video_url')) {
 *         $pdo->exec('ALTER TABLE exercises ADD COLUMN video_url TEXT');
 *     }
 *
 * Der Block bleibt stehen, auch wenn die Spalte laengst ueberall existiert:
 * eine wiederhergestellte alte Sicherung braucht ihn wieder.
 */
function apply_migrations(PDO $pdo): void {
    // 2026-08-06: Schwerpunkt innerhalb der Primaergruppe ("oben", "stehend").
    // Rein additiv -- bestehende Uebungen bekommen NULL und verhalten sich
    // unveraendert. Wird auf der Live-Datenbank beim ersten Start des neuen
    // Images ausgefuehrt.
    if (!column_exists($pdo, 'exercises', 'focus')) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN focus TEXT');
    }

    // 2026-08-06: Muskelgruppen bekommen zwei Ebenen. Bestehende Gruppen
    // werden zu Hauptgruppen (parent_id bleibt NULL) und verhalten sich
    // unveraendert -- die Zuordnung zu Hauptgruppen erfolgt von Hand.
    //
    // ALTER TABLE ADD COLUMN kann in SQLite keinen Fremdschluessel nachtragen;
    // die Beziehung wird deshalb in der Anwendung geprueft (api/muscle_groups.php).
    // Bei einer frisch angelegten Datenbank steht der Fremdschluessel aus
    // schema.sql dagegen von Anfang an.
    if (!column_exists($pdo, 'muscle_groups', 'parent_id')) {
        $pdo->exec('ALTER TABLE muscle_groups ADD COLUMN parent_id INTEGER REFERENCES muscle_groups(id)');
    }

    // 2026-08-07: Wiederholungen werden nicht mehr erfasst -- ein Feld je
    // Einheit kann 12/10/9 ueber drei Saetze nicht abbilden (§7.4).
    //
    // ACHTUNG, DESTRUKTIV: Diese Migration loescht Daten. Sie wurde am
    // 2026-08-07 ausdruecklich freigegeben, als noch keine abgeschlossene
    // Trainingseinheit existierte. DROP COLUMN gibt es in SQLite seit 3.35;
    // die Spalte steht in keinem Index, deshalb greift keine Einschraenkung.
    if (column_exists($pdo, 'workout_log', 'reps')) {
        $pdo->exec('ALTER TABLE workout_log DROP COLUMN reps');
    }

    // 2026-08-10: Trainingsgeraet je Uebung (§6.3). Rein additiv.
    //
    // Bestehende Uebungen bekommen NULL und bleiben damit uneingeschraenkt
    // nutzbar -- weder Plan noch Protokoll noch Tausch fragen nach dem Geraet.
    // Sie tragen in der Uebungsliste aber ein sichtbares "Geraet fehlt" und
    // sind ueber den Filter "ohne Gerät" auffindbar. Ein Vorgabewert waere
    // bequemer und fuer die meisten schlicht falsch gewesen: ob eine Uebung an
    // der Maschine oder mit Kurzhanteln laeuft, weiss die Migration nicht.
    if (!column_exists($pdo, 'exercises', 'equipment')) {
        $pdo->exec('ALTER TABLE exercises ADD COLUMN equipment TEXT');
    }

    // 2026-08-11: Expertenmodus je Benutzer (§7.4). Rein additiv.
    //
    // Vorgabe 0 -- bestehende Konten sehen die Trainingsansicht unveraendert.
    // Ohne CHECK-Constraint: ALTER TABLE kann in SQLite keines nachtragen, und
    // ein Tabellen-Neuaufbau nur dafuer waere unverhaeltnismaessig. Der Wert
    // kommt ausschliesslich aus api/auth.php und ist dort auf 0/1 gebunden;
    // bei frisch angelegten Datenbanken steht das CHECK aus schema.sql ohnehin.
    //
    // Die neue Tabelle workout_sets braucht hier NICHTS: Sie steht als CREATE
    // TABLE IF NOT EXISTS in schema.sql, das bei jedem Start laeuft und sie
    // damit auch auf einer Bestandsdatenbank anlegt. Nur SPALTEN muessen den
    // Umweg ueber PRAGMA table_info nehmen.
    if (!column_exists($pdo, 'users', 'expert_mode')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN expert_mode INTEGER NOT NULL DEFAULT 0');
    }

    // 2026-08-11: "Erledigt" ist im Expertenmodus ein eigener Zustand (§7.4).
    //
    // Rein additiv, und die Vorgabe 1 ist der ganze Trick: Jede bestehende
    // Protokollzeile gilt damit als erledigt -- genau das war sie bisher auch,
    // denn "Zeile existiert" HIESS erledigt. Der einfache Modus schreibt
    // weiterhin nur Einsen; nur der Expertenmodus setzt 0, solange die Uebung
    // noch laeuft.
    if (!column_exists($pdo, 'workout_log', 'done')) {
        $pdo->exec('ALTER TABLE workout_log ADD COLUMN done INTEGER NOT NULL DEFAULT 1');
    }

    // 2026-08-17: Welche Seite eines breiten Bildes beim Zuschnitt wegfaellt.
    //
    // Die Vorschaubilder stehen in einem quadratischen Rahmen mit
    // `object-fit: cover`. Bei einem Motiv, das breiter als hoch ist, schneidet
    // der Browser links UND rechts gleich viel weg -- und traf damit regelmaessig
    // neben das Geraet, wenn es nicht mittig im Bild stand. Gemeldet aus dem
    // Training am 2026-08-17.
    //
    // Rein additiv, und die Vorgabe 'mitte' ist genau das bisherige Verhalten:
    // Jede bestehende Uebung sieht nach der Migration unveraendert aus. Der Wert
    // wirkt ALLEIN ueber `object-position` im Stylesheet -- die Bilddateien
    // bleiben unangetastet, ein spaeterer Wechsel kostet deshalb nichts und
    // laesst sich beliebig oft aendern. Das geht nur, weil write_resized() in
    // lib/upload.php ausschliesslich skaliert und NICHT beschneidet; das
    // Vorschaubild traegt also noch das volle Seitenverhaeltnis.
    //
    // TEXT und nicht INTEGER, weil der Wert in der Datenbank lesbar sein soll:
    // 'links' / 'mitte' / 'rechts'. Geprueft wird gegen die Codeliste
    // ZUSCHNITT in lib/geraete.php, nicht ueber ein CHECK -- dieselbe
    // Begruendung wie beim Trainingsgeraet (Fallstrick 16).
    if (!column_exists($pdo, 'exercises', 'image_crop')) {
        $pdo->exec("ALTER TABLE exercises ADD COLUMN image_crop TEXT NOT NULL DEFAULT 'mitte'");
    }

    // 2026-08-17: Ein Konto sperren, ohne seine Daten anzufassen (§6.1).
    //
    // Rein additiv, und NULL ist die richtige Vorgabe: Nach der Migration ist
    // niemand gesperrt, der Bestand verhaelt sich also unveraendert.
    //
    // Ein TEXT-Zeitstempel statt eines 0/1-Flags -- die Begruendung steht bei
    // der Spalte in schema.sql. Kurz: Zwei Spalten fuer dieselbe Aussage koennen
    // auseinanderlaufen, und das Sperrdatum wird in der Benutzerliste ohnehin
    // angezeigt.
    if (!column_exists($pdo, 'users', 'blocked_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN blocked_at TEXT');
    }

    // 2026-08-17: Jeder waehlt selbst, woher die Vorbelegung eines neuen
    // Satzes kommt (§7.4).
    //
    // Rein additiv, und die Vorgabe ist entscheidend: 'gleicher_satz' ist
    // exakt das Verhalten von vor dieser Migration. Kein Bestandsbenutzer
    // merkt vom Rollout etwas, bis er selbst umstellt -- dieselbe Ueberlegung
    // wie bei workout_log.done DEFAULT 1 und exercises.image_crop 'mitte'.
    if (!column_exists($pdo, 'users', 'satz_vorlage')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN satz_vorlage TEXT NOT NULL DEFAULT 'gleicher_satz'");
    }

    // 2026-08-18: Workout-Splits als Ebene ueber den Plaenen (§6.4, §7.6).
    //
    // Die Tabelle splits selbst kommt aus schema.sql -- CREATE TABLE IF NOT
    // EXISTS legt sie auch auf der Bestandsdatenbank an, so wie seinerzeit
    // workout_sets. Nur die beiden Spalten brauchen den Umweg hierher, weil
    // ALTER TABLE kein IF NOT EXISTS kennt.
    //
    // Beide sind nullbar und ohne Vorgabe -- das ist bei ADD COLUMN mit
    // REFERENCES auch die einzige zulaessige Form, solange Fremdschluessel
    // eingeschaltet sind. Gefuellt wird split_id gleich darunter von
    // splits_nachziehen().
    if (!column_exists($pdo, 'plans', 'split_id')) {
        $pdo->exec('ALTER TABLE plans ADD COLUMN split_id INTEGER
                        REFERENCES splits(id) ON DELETE CASCADE');
    }

    if (!column_exists($pdo, 'users', 'active_split_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN active_split_id INTEGER
                        REFERENCES splits(id) ON DELETE SET NULL');
    }

    // 2026-08-23: Herkunft einer Kopie (§6.4). Traegt die Vorlage, aus der ein
    // persoenlicher Split kopiert wurde -- Grundlage fuer "Auf Vorlage
    // zurücksetzen". Rein additiv: Jeder bestehende Split bekommt NULL und
    // verhaelt sich unveraendert; der Knopf erscheint dort erst, wenn der
    // Benutzer die Vorlage von Hand zuordnet oder frisch kopiert.
    //
    // ALTER TABLE ADD COLUMN kann in SQLite keinen Fremdschluessel nachtragen
    // -- dieselbe Lage wie bei muscle_groups.parent_id oben. Auf einer
    // Bestandsdatenbank ist vorlage_id deshalb eine gewoehnliche Spalte, und
    // das ON DELETE SET NULL aus schema.sql greift dort NICHT. Aufgefangen
    // wird das in der Anwendung: vorlage_stand() prueft, ob die Vorlage
    // ueberhaupt noch existiert, und behandelt eine verwaiste Herkunft wie
    // keine. Verlassen darf man sich auf den Fremdschluessel hier also nicht.
    if (!column_exists($pdo, 'splits', 'vorlage_id')) {
        $pdo->exec('ALTER TABLE splits ADD COLUMN vorlage_id INTEGER');
    }

    // Die Indizes gehoeren hierher und nicht in schema.sql: Dort liefen sie vor
    // den ALTER oben und scheiterten auf einer Bestandsdatenbank an der noch
    // fehlenden Spalte -- was den gesamten Start abbraeche. Hier steht die
    // Spalte in jedem Fall bereits, bei frischer wie bei bestehender Datenbank.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_muscle_groups_parent
                    ON muscle_groups(parent_id, sort_order)');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_plans_split
                    ON plans(split_id, sort_order)');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_splits_user
                    ON splits(user_id, sort_order)');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_exercises_equipment
                    ON exercises(equipment, name_de)');

    // 2026-08-07: Benutzernamen unterscheiden nicht mehr nach Schreibweise.
    //
    // users.name traegt aus schema.sql ein UNIQUE, und das vergleicht SQLite
    // binaer. Beim Live-Test liess sich deshalb ein zweites Konto "oliver"
    // anlegen, obwohl es "Oliver" schon gab -- zwei Zeilen, die in keiner
    // Liste auseinanderzuhalten sind. Zusammen mit dem COLLATE NOCASE in
    // attempt_login() ist der Name jetzt durchgaengig schreibweisenunabhaengig.
    //
    // GRENZE: SQLites NOCASE faltet ausschliesslich ASCII A-Z. "Mueller" und
    // "mueller" fallen zusammen, "Müller" und "müller" nicht.
    index_name_nocase($pdo);

    // Zum Schluss, weil sie die Spalten von oben braucht.
    splits_nachziehen($pdo);
}

/**
 * Haengt Plaene ohne Split an einen persoenlichen Split ihres Besitzers.
 *
 * Das ist die Datenmigration zu 1.2.0 -- und sie steht bewusst HIER und nicht
 * in einem Skript, das einmal von Hand lief. backup_wiederherstellen() ruft
 * nach dem Einspielen db() auf und damit init_schema(); eine Sicherung aus der
 * Zeit vor den Splits wird auf diesem Weg mitgezogen und ist unmittelbar
 * danach wieder benutzbar. Ein Einmalskript liesse genau dort Plaene zurueck,
 * die keinem Split angehoeren -- und die waeren in der Oberflaeche unsichtbar,
 * ohne dass irgendetwas eine Meldung erzeugte.
 *
 * plans.user_id ist der einzige Anker, an dem sich das entscheiden laesst, und
 * der einzige Grund, warum die Spalte stehenbleibt (siehe schema.sql).
 *
 * Idempotent in beide Richtungen: Der zweite Lauf findet nichts mehr vor und
 * legt deshalb auch keinen zweiten Split an. Der Name "Meine Plaene" ist
 * bewusst nichtssagend -- welches Konzept dahintersteckt, weiss nur der
 * Benutzer, und Umbenennen ist ein Handgriff.
 */
function splits_nachziehen(PDO $pdo): void {
    $offen = $pdo->query(
        'SELECT DISTINCT user_id FROM plans WHERE split_id IS NULL'
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($offen as $userId) {
        $userId = (int)$userId;

        // Einen vorhandenen persoenlichen Split wiederverwenden, statt bei
        // jedem Lauf einen weiteren anzulegen. Der aelteste gewinnt.
        $stmt = $pdo->prepare(
            'SELECT id FROM splits WHERE user_id = ? ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute([$userId]);
        $splitId = to_int_or_null($stmt->fetchColumn());

        if ($splitId === null) {
            $pdo->prepare(
                'INSERT INTO splits (user_id, name, sort_order, created_at)
                 VALUES (?, ?, 1, ?)'
            )->execute([$userId, 'Meine Pläne', now()]);
            $splitId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE plans SET split_id = ? WHERE user_id = ? AND split_id IS NULL')
            ->execute([$splitId, $userId]);
    }

    // Und die Auswahl setzen, damit niemand nach dem Rollout vor einer leeren
    // Trainingsansicht steht. Der Split der zuletzt begonnenen Einheit ist die
    // richtige Antwort -- er ist der, in dem man zuletzt trainiert hat.
    $pdo->exec(
        'UPDATE users
            SET active_split_id = COALESCE(
                (SELECT p.split_id
                   FROM sessions s
                   JOIN plans p ON p.id = s.plan_id
                  WHERE s.user_id = users.id AND p.split_id IS NOT NULL
                  ORDER BY s.started_at DESC, s.id DESC
                  LIMIT 1),
                (SELECT sp.id
                   FROM splits sp
                  WHERE sp.user_id = users.id
                  ORDER BY sp.sort_order, sp.id
                  LIMIT 1))
          WHERE active_split_id IS NULL'
    );
}

/**
 * Legt den schreibweisenunabhaengigen Index auf users.name an.
 *
 * Eigene Funktion wegen der Vorpruefung: Gaebe es bereits zwei Namen, die sich
 * nur in der Schreibweise unterscheiden, scheiterte CREATE UNIQUE INDEX -- und
 * weil das den Start abbricht, stuende die App, ohne dass die Meldung sagt,
 * WELCHE Namen schuld sind. Denkbar ist der Fall nur beim Zurueckspielen einer
 * alten Sicherung; die Meldung nennt dann die Paare, damit sich einer davon
 * gezielt umbenennen laesst.
 *
 * Bewusst KEIN stilles Ueberspringen: Der uebrige Code verlaesst sich darauf,
 * dass der Index die Kollision abfaengt (benutzer_umbenennen() in lib/auth.php).
 * Ein nicht angelegter Index waere eine Zusage, die niemand mehr einloest.
 */
function index_name_nocase(PDO $pdo): void {
    $doppelt = $pdo->query(
        'SELECT group_concat(name, " / ") AS namen
           FROM users
          GROUP BY name COLLATE NOCASE
         HAVING COUNT(*) > 1'
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($doppelt !== []) {
        throw new RuntimeException(
            'Benutzernamen sind ab Version 1.0.9 unabhaengig von der Schreibweise '
            . 'eindeutig. Diese Namen stehen dem entgegen und muessen zuerst '
            . 'bereinigt werden: ' . implode('; ', $doppelt)
        );
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_name_nocase
                    ON users(name COLLATE NOCASE)');
}

/**
 * Prueft, ob eine Spalte existiert. Hilfsmittel fuer apply_migrations().
 */
function column_exists(PDO $pdo, string $table, string $column): bool {
    // Tabellenname kommt ausschliesslich aus dem Code, nie aus einer Eingabe --
    // PRAGMA erlaubt keine Platzhalter.
    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($rows as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

/**
 * Legt die Standard-Muskelgruppen an -- aber nur, solange die Tabelle leer ist.
 *
 * Bewusst nicht "INSERT OR IGNORE" je Zeile: eine vom Admin geloeschte Gruppe
 * kaeme sonst bei jedem Neustart zurueck.
 */
function seed_muscle_groups(PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM muscle_groups')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        ['Brust',     'Chest'],
        ['Rücken',    'Back'],
        ['Schultern', 'Shoulders'],
        ['Bizeps',    'Biceps'],
        ['Trizeps',   'Triceps'],
        ['Beine',     'Legs'],
        ['Waden',     'Calves'],
        ['Bauch',     'Abs'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO muscle_groups (name_de, name_en, sort_order) VALUES (?, ?, ?)'
    );
    $pdo->beginTransaction();
    try {
        foreach ($defaults as $i => [$de, $en]) {
            $stmt->execute([$de, $en, ($i + 1) * 10]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Erzeugt den ersten Admin aus ADMIN_USER/ADMIN_PASSWORD (§3).
 *
 * Loest das Henne-Ei-Problem: es gibt kein Self-Signup, also muss der erste
 * Benutzer aus der Umgebung kommen. Greift ausschliesslich, solange ueberhaupt
 * kein Benutzer existiert -- danach sind die beiden Variablen wirkungslos, und
 * eine Aenderung wirkt sich auf niemanden mehr aus.
 *
 * must_change_password = 1, weil ADMIN_PASSWORD in der Stack-Definition bzw.
 * in Portainer dauerhaft im Klartext sichtbar bleibt.
 */
function bootstrap_first_admin(PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $name     = trim((string)(getenv('ADMIN_USER') ?: ''));
    $password = (string)(getenv('ADMIN_PASSWORD') ?: '');

    if ($name === '' || $password === '') {
        // Ohne Zugangsdaten laesst sich niemand anlegen. Kein Abbruch: die
        // Startseite soll eine verstaendliche Meldung zeigen koennen, statt
        // dass der Container in einer Neustartschleife haengt.
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (name, password_hash, is_admin, must_change_password, created_at)
         VALUES (?, ?, 1, 1, ?)'
    );
    try {
        $stmt->execute([$name, password_hash_app($password), now()]);
    } catch (PDOException $e) {
        // Zwei gleichzeitige erste Requests: der zweite laeuft in den
        // UNIQUE-Index auf users.name. Das ist der gewuenschte Ausgang.
        if (!str_contains($e->getMessage(), 'UNIQUE')) {
            throw $e;
        }
    }
}

/**
 * Fuehrt eine Closure in einer Transaktion aus und gibt deren Rueckgabe weiter.
 * Verschachtelte Aufrufe laufen in der aeusseren Transaktion mit.
 */
function db_transaction(callable $fn): mixed {
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn($pdo);
    }

    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
