<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/training.php';
require_once __DIR__ . '/../lib/splits.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();

/**
 * Workout-Splits (§6.4, §7.6).
 *
 * Der Endpunkt ist NICHT admin-only: Jeder Benutzer verwaltet seine eigenen
 * Splits. Was ein Admin zusaetzlich darf, entscheidet split_zugriff_api() in
 * lib/splits.php -- Vorlagen anlegen, bearbeiten und veroeffentlichen.
 *
 * "copy" ist die fachlich wichtigste Aktion: Sie ist die Verbindung zwischen
 * Vorlage und Benutzer. Eine Kopie lebt danach ihr eigenes Leben -- es gibt
 * KEINE Vererbung und kein automatisches Nachziehen; eine geaenderte Vorlage
 * laesst bestehende Kopien unberuehrt, und ein dauerhafter Tausch beim
 * Benutzer beruehrt die Vorlage nicht.
 *
 * Seit 1.2.11 gibt es genau EINEN Weg zurueck, und er wird ausschliesslich
 * vom Benutzer ausgeloest: "reset" bringt eine Kopie auf den Stand ihrer
 * Vorlage. Voraussetzung ist, dass die Herkunft bekannt ist -- entweder weil
 * die Kopie so entstand oder weil der Benutzer sie ueber "set_vorlage"
 * zugeordnet hat. Automatisch passiert nichts, in keiner Richtung.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'create'   => aktion_anlegen($eingabe),
    'rename'   => aktion_umbenennen($eingabe),
    'delete'   => aktion_loeschen($eingabe),
    'reorder'  => aktion_sortieren($eingabe),
    'copy'     => aktion_kopieren($eingabe),
    'publish'  => aktion_veroeffentlichen($eingabe),
    'activate' => aktion_aktivieren($eingabe),
    'set_vorlage' => aktion_vorlage_zuordnen($eingabe),
    'reset'       => aktion_zuruecksetzen($eingabe),
    default    => json_err('Unbekannte Aktion', 400),
};

/**
 * Darf der Angemeldete diesen Split LESEN, also kopieren?
 *
 * Weiter als das Bearbeiten: Eine Vorlage darf jeder kopieren -- das ist ihr
 * Zweck. Einen persoenlichen Split nur sein Eigentuemer (und ein Admin).
 */
function split_lesbar_api(int $splitId): array {
    $split = split_laden($splitId);
    if ($split === null) {
        json_err('Diesen Split gibt es nicht.', 404);
    }

    if ($split['user_id'] !== null
        && (int)$split['user_id'] !== current_user_id()
        && !is_admin()
    ) {
        json_err('Kein Zugriff auf diesen Split.', 403);
    }

    return $split;
}

/**
 * Bricht ab, solange eine Einheit laeuft.
 *
 * Wechseln und Loeschen sind waehrend eines Trainings gesperrt, aus demselben
 * Grund wie der Moduswechsel in api/auth.php: Die laufende Einheit haengt an
 * einem Plan dieses Splits, und die Trainingsansicht zeigt sie unabhaengig von
 * der Auswahl weiter. Ein Wechsel darunter waere eine Aenderung, die man erst
 * nach dem Beenden zu sehen bekommt -- und beim Loeschen zoege sie der
 * offenen Einheit den Plan unter den Fuessen weg.
 */
function nicht_waehrend_training(): void {
    if (offene_einheit(current_user_id()) !== null) {
        json_err(
            'Es läuft gerade ein Training. Bitte zuerst die Einheit beenden.',
            409
        );
    }
}

function aktion_anlegen(array $eingabe): never {
    $name    = split_name_pruefen($eingabe['name'] ?? '');
    $vorlage = !empty($eingabe['vorlage']);

    if ($vorlage && !is_admin()) {
        json_err('Vorlagen legt nur ein Administrator an.', 403);
    }

    $besitzer = $vorlage ? null : current_user_id();

    $stmt = $besitzer === null
        ? db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM splits WHERE user_id IS NULL')
        : db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM splits WHERE user_id = ?');
    $stmt->execute($besitzer === null ? [] : [$besitzer]);

    db()->prepare(
        'INSERT INTO splits (user_id, name, sort_order, created_at) VALUES (?, ?, ?, ?)'
    )->execute([$besitzer, $name, (int)$stmt->fetchColumn() + 10, now()]);

    // Kein aktiven_split_setzen() noetig: Wer bisher keinen hatte, bekommt
    // diesen beim naechsten Seitenaufbau ueber den Rueckfall in
    // aktiver_split() -- und wer schon einen hat, soll durch das blosse
    // Anlegen eines weiteren nicht aus seiner Rotation geworfen werden.
    json_ok(['id' => (int)db()->lastInsertId()]);
}

function aktion_umbenennen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    split_zugriff_api($id);
    $name = split_name_pruefen($eingabe['name'] ?? '');

    db()->prepare('UPDATE splits SET name = ? WHERE id = ?')->execute([$name, $id]);

    json_ok(['id' => $id]);
}

/**
 * Einen Split loeschen -- mitsamt seinen Plaenen und deren Positionen.
 *
 * Die Historie bleibt: sessions.plan_id und workout_log.plan_id fallen per
 * ON DELETE SET NULL auf NULL, die Eintraege selbst und ihre Saetze stehen
 * weiter (§4.1). Im Verlauf steht danach "gelöschter Plan" -- die Gewichte
 * bleiben lesbar, weil sie an der UEBUNG haengen und nicht am Plan.
 */
function aktion_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    $split = split_zugriff_api($id);

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM sessions s
           JOIN plans p ON p.id = s.plan_id
          WHERE p.split_id = ? AND s.ended_at IS NULL'
    );
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_err(
            'Auf einen Plan dieses Splits zeigt eine offene Trainingseinheit. '
            . 'Erst die Einheit beenden, dann löschen.',
            409
        );
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM sessions s
           JOIN plans p ON p.id = s.plan_id
          WHERE p.split_id = ?'
    );
    $stmt->execute([$id]);
    $einheiten = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM splits WHERE id = ?')->execute([$id]);

    // Der gewaehlte Split ist jetzt womoeglich weg (ON DELETE SET NULL);
    // aktiver_split() sucht sich beim naechsten Aufruf einen neuen.
    json_ok(['deleted' => $id, 'sessions_kept' => $einheiten]);
}

/**
 * Reihenfolge der eigenen Splits -- reine Anzeigesache.
 *
 * Anders als bei den PLAENEN innerhalb eines Splits: Dort ist die Sortierung
 * die Rotation (§7.6), hier ist sie nur die Liste auf splits.php.
 */
function aktion_sortieren(array $eingabe): never {
    $ids = to_id_list($eingabe['ids'] ?? []);
    if ($ids === []) {
        json_err('Keine Reihenfolge übermittelt.', 422);
    }

    foreach ($ids as $id) {
        split_zugriff_api($id);
    }

    db_transaction(function (PDO $pdo) use ($ids): void {
        $stmt = $pdo->prepare('UPDATE splits SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $stmt->execute([($i + 1) * 10, $id]);
        }
    });

    json_ok(['count' => count($ids)]);
}

/**
 * Einen Split zu sich kopieren -- der Weg aus dem Katalog in den eigenen
 * Bestand, und zugleich das Duplizieren eines eigenen Splits.
 */
function aktion_kopieren(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    $quelle = split_lesbar_api($id);
    $name   = trim(to_str($eingabe['name'] ?? ''));
    if ($name !== '') {
        $name = split_name_pruefen($name);
    }

    $userId = current_user_id();

    try {
        $neu = split_kopieren($id, $userId, $userId, $name === '' ? null : $name);
    } catch (Throwable $e) {
        json_err('Der Split ließ sich nicht kopieren: ' . $e->getMessage(), 500);
    }

    // Man kopiert einen Split, um ihn zu benutzen. Ohne offene Einheit wird er
    // deshalb gleich der gewaehlte; laeuft gerade ein Training, bleibt die
    // Auswahl stehen und der Benutzer wechselt spaeter selbst.
    if (offene_einheit($userId) === null) {
        aktiven_split_setzen($userId, $neu);
    }

    json_ok(['id' => $neu, 'name' => (string)(split_laden($neu)['name'] ?? $quelle['name'])]);
}

/**
 * Nur der EIGENE Split -- ohne Admin-Ausnahme.
 *
 * split_zugriff_api() liesse einen Admin auch fremde Splits anfassen, und fuer
 * Umbenennen oder Loeschen ist das richtig. Herkunft und Zuruecksetzen sind
 * etwas anderes: Beides sind Entscheidungen ueber das Training eines
 * Menschen -- welche Vorlage er benutzt, und ob seine eigenen Anpassungen
 * verworfen werden. Das darf niemand fuer ihn treffen.
 */
function eigener_split_api(int $splitId): array {
    $split = split_laden($splitId);
    if ($split === null) {
        json_err('Diesen Split gibt es nicht.', 404);
    }
    if ($split['user_id'] === null || (int)$split['user_id'] !== current_user_id()) {
        json_err('Das geht nur mit einem eigenen Split.', 403);
    }

    return $split;
}

/**
 * Die Vorlage eines eigenen Splits festlegen oder loesen (§6.4).
 *
 * Gebraucht wird das fuer alles, was vor 1.2.11 entstanden ist: Diese Splits
 * kennen ihre Herkunft nicht, und ohne Zuordnung von Hand bekaemen sie den
 * Knopf nie zu sehen.
 */
function aktion_vorlage_zuordnen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    eigener_split_api($id);

    // 0 und "" heissen "keine Vorlage" -- das Auswahlfeld schickt beim
    // Loesen einen leeren Wert, und der darf nicht als fehlende Angabe
    // durchgehen.
    $roh      = $eingabe['vorlage_id'] ?? null;
    $vorlagen = ($roh === null || $roh === '' || (int)$roh === 0)
        ? null
        : to_int_or_null($roh);

    try {
        split_vorlage_setzen($id, $vorlagen);
    } catch (Throwable $e) {
        json_err($e->getMessage(), 422);
    }

    $stand = vorlage_stand([$id])[$id] ?? null;

    json_ok([
        'id'         => $id,
        'vorlage_id' => $stand === null ? 0 : $stand['vorlage_id'],
        'weicht_ab'  => $stand !== null && $stand['weicht_ab'],
    ]);
}

/**
 * Einen eigenen Split auf den Stand seiner Vorlage bringen (§6.4).
 *
 * Waehrend eines Trainings gesperrt, und zwar aus demselben Grund wie das
 * Umsortieren von Uebungen (struktur_sperre_pruefen() in api/plans.php): Die
 * Fortschrittsanzeige "x/n" zaehlt ueber den Plan, und der veraenderte sich
 * hier mitten in der Einheit. Hier waere es sogar schlimmer -- der Plan, auf
 * dem gerade trainiert wird, koennte ganz verschwinden.
 *
 * 'namen' entscheidet, ob auch die PLANNAMEN von der Vorlage kommen (seit
 * 1.2.23). FEHLT ES, WIRD NICHT UMBENANNT: Das Angleichen der Beschriftung ist
 * der zusaetzliche Wunsch neben dem eigentlichen Zurueckholen, und ein nicht
 * geaeusserter Wunsch ist keiner. Die Oberflaeche fragt ausdruecklich danach.
 */
function aktion_zuruecksetzen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    $namen = (bool)($eingabe['namen'] ?? false);

    eigener_split_api($id);
    nicht_waehrend_training();

    try {
        $ergebnis = split_zuruecksetzen($id, $namen);
    } catch (Throwable $e) {
        json_err($e->getMessage(), 409);
    }

    json_ok($ergebnis + ['id' => $id]);
}

/**
 * Eine Vorlage anlegen, ausgehend von einem beliebigen Split (nur Admin).
 *
 * ZWEI Faelle, eine Aktion, weil das Ergebnis dasselbe ist -- eine neue
 * Vorlage im Katalog:
 *
 *   persoenlicher Split -> Vorlage   "Als Vorlage übernehmen" (User Splits)
 *   Vorlage             -> Vorlage   "Duplizieren" (Vorlagen-Abschnitt)
 *
 * Der zweite Fall lief bis 1.2.2 in ein 409 ("Das ist bereits eine Vorlage").
 * Das war als Schutz vor versehentlichen Dubletten gedacht und stand dem
 * eigentlichen Zweck im Weg: Wer eine Variante einer Vorlage bauen will --
 * dieselben Plaene, eine Uebung anders --, braucht eine Kopie IM Katalog und
 * nicht den Umweg ueber einen persoenlichen Split. Vor Dubletten schuetzt die
 * Oberflaeche an der richtigen Stelle: Der Abschnitt "User Splits" blendet
 * aus, was inhaltlich schon im Katalog steht; ein ausdrueckliches
 * "Duplizieren" dagegen ist nie ein Versehen.
 *
 * Auch das ist eine KOPIE und kein Umhaengen: Der Benutzer trainiert auf
 * seinem Stand weiter, und was er danach daran aendert, geht die Vorlage
 * nichts mehr an. Waere es ein Umhaengen, verloere er seinen Split an den
 * Katalog -- und duerfte ihn anschliessend nicht mehr benutzen, weil auf
 * Vorlagen niemand trainiert.
 */
function aktion_veroeffentlichen(array $eingabe): never {
    if (!is_admin()) {
        json_err('Vorlagen veröffentlicht nur ein Administrator.', 403);
    }

    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    $quelle = split_lesbar_api($id);

    $name = trim(to_str($eingabe['name'] ?? ''));
    if ($name !== '') {
        $name = split_name_pruefen($name);
    } elseif ($quelle['user_id'] === null) {
        // Duplikat IM Katalog: Ohne Zusatz hiessen beide gleich, und zwei
        // gleichnamige Vorlagen sind in der Auswahl nicht auseinanderzuhalten.
        // Bewusst "(Kopie)" und keine Rueckfrage nach dem Namen: Beim
        // Duplizieren weiss man noch nicht, wie die Variante heissen soll --
        // das ergibt sich erst beim Bearbeiten. Umbenannt wird danach, an der
        // Karte selbst.
        //
        // split_name_frei() haengt zusaetzlich " (2)" an, wenn es die Kopie
        // schon gibt; str_cut() haelt die Laenge ein, ohne ein UTF-8-Zeichen
        // zu zerschneiden.
        $name = str_cut((string)$quelle['name'] . ' (Kopie)', SPLIT_NAME_MAX);
    }

    try {
        $neu = split_kopieren($id, null, current_user_id(), $name === '' ? null : $name);
    } catch (Throwable $e) {
        json_err('Die Vorlage ließ sich nicht anlegen: ' . $e->getMessage(), 500);
    }

    json_ok(['id' => $neu]);
}

/**
 * Den aktiven Split wechseln (§7.6).
 */
function aktion_aktivieren(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Split angegeben.', 422);
    }

    nicht_waehrend_training();

    $split = split_laden($id);
    if ($split === null) {
        json_err('Diesen Split gibt es nicht.', 404);
    }

    // Ausdruecklich NICHT split_zugriff_api(): Ein Admin darf eine Vorlage
    // BEARBEITEN, aber niemand waehlt sie zum Training aus -- auch er nicht.
    // Er kopiert sie sich wie jeder andere.
    //
    // ZWEI Faelle, zwei Meldungen. Bis 1.2.0 teilten sie sich eine, und die
    // war fuer den zweiten schlicht falsch: Der Split eines anderen Benutzers
    // ist keine Vorlage, und "bitte zuerst Zu mir kopieren" ist dort auch kein
    // gangbarer Rat -- kopieren darf man ihn ja gerade nicht. Aufgefallen beim
    // Pruefen am Live-System am 2026-08-18. Der Statuscode war immer richtig;
    // falsch war nur die Auskunft an den Benutzer, und die ist der Teil, den
    // er liest.
    if ($split['user_id'] === null) {
        json_err(
            'Auf einer Vorlage wird nicht trainiert — bitte zuerst „Zu mir kopieren“.',
            403
        );
    }

    if ((int)$split['user_id'] !== current_user_id()) {
        json_err('Das ist der Split eines anderen Benutzers.', 403);
    }

    aktiven_split_setzen(current_user_id(), $id);

    json_ok(['id' => $id]);
}
