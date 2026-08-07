<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/training.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();
require_admin_api();

/**
 * Planverwaltung (§6.4).
 *
 * Die Reihenfolge der Plaene ist hier keine Anzeigesache: sie bestimmt die
 * Rotation in §7.6. Push -> Pull -> Legs ist genau diese Sortierung.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const PLAN_NAME_MAX = 80;

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'create_plan'        => aktion_plan_anlegen($eingabe),
    'rename_plan'        => aktion_plan_umbenennen($eingabe),
    'delete_plan'        => aktion_plan_loeschen($eingabe),
    'reorder_plans'      => aktion_plaene_sortieren($eingabe),
    'add_exercise'       => aktion_uebung_hinzufuegen($eingabe),
    'remove_exercise'    => aktion_uebung_entfernen($eingabe),
    'reorder_exercises'  => aktion_uebungen_sortieren($eingabe),
    'swap_suggestions'   => aktion_tausch_vorschlaege($eingabe),
    'swap_exercise'      => aktion_uebung_tauschen($eingabe),
    default              => json_err('Unbekannte Aktion', 400),
};

/**
 * Hat dieser Benutzer gerade eine offene Einheit?
 */
function hat_offene_einheit(int $userId): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ? AND ended_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Bricht ab, wenn der Benutzer gerade trainiert.
 *
 * Waehrend einer offenen Einheit darf sich die Zusammensetzung des Plans nicht
 * aendern -- sonst verschiebt sich das `n` in der Fortschrittsanzeige "x/n"
 * mitten im Training (§6.4).
 */
function struktur_sperre_pruefen(int $userId): void {
    if (hat_offene_einheit($userId)) {
        json_err(
            'Dieser Benutzer hat gerade eine offene Trainingseinheit. '
            . 'Solange lässt sich die Zusammensetzung des Plans nicht ändern.',
            409
        );
    }
}

/**
 * Laedt einen Plan samt Besitzer oder bricht mit 404 ab.
 */
function plan_laden(int $planId): array {
    $stmt = db()->prepare('SELECT id, user_id, name, sort_order FROM plans WHERE id = ?');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if ($plan === false) {
        json_err('Diesen Plan gibt es nicht (mehr).', 404);
    }
    return $plan;
}

function plan_name_pruefen(array $eingabe): string {
    $name = to_str($eingabe['name'] ?? '');
    if ($name === '') {
        json_err('Bitte einen Namen eingeben.', 422, ['name' => 'Pflichtfeld.']);
    }
    if (str_len_utf8($name) > PLAN_NAME_MAX) {
        json_err('Der Name ist zu lang.', 422, [
            'name' => 'Höchstens ' . PLAN_NAME_MAX . ' Zeichen.',
        ]);
    }
    return $name;
}

function aktion_plan_anlegen(array $eingabe): never {
    $userId = to_int_or_null($eingabe['user_id'] ?? null);
    if ($userId === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Diesen Benutzer gibt es nicht.', 404);
    }

    $name = plan_name_pruefen($eingabe);

    // Ans Ende der Rotation, nicht mittendrin einsortieren.
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM plans WHERE user_id = ?');
    $stmt->execute([$userId]);
    $max = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO plans (user_id, name, sort_order, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $name, $max + 10, now()]);

    json_ok(['id' => (int)db()->lastInsertId()]);
}

/**
 * Umbenennen ist auch waehrend einer offenen Einheit erlaubt: der Name aendert
 * nichts an der Zusammensetzung und damit nichts am laufenden "x/n".
 */
function aktion_plan_umbenennen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Plan angegeben.', 422);
    }

    plan_laden($id);
    $name = plan_name_pruefen($eingabe);

    db()->prepare('UPDATE plans SET name = ? WHERE id = ?')->execute([$name, $id]);
    json_ok(['id' => $id]);
}

function aktion_plan_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Plan angegeben.', 422);
    }

    $plan = plan_laden($id);

    // §4.1: verboten, solange eine offene Einheit auf ihn zeigt.
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM sessions WHERE plan_id = ? AND ended_at IS NULL'
    );
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_err(
            'Auf diesen Plan zeigt eine offene Trainingseinheit. '
            . 'Erst die Einheit beenden, dann löschen.',
            409
        );
    }

    // Wie viel Historie haengt daran? Der Admin soll wissen, was er tut --
    // die Eintraege selbst bleiben erhalten (§4.1).
    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE plan_id = ?');
    $stmt->execute([$id]);
    $einheiten = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM plans WHERE id = ?')->execute([$id]);

    json_ok(['deleted' => $id, 'sessions_kept' => $einheiten]);
}

/**
 * Neue Reihenfolge der Plaene -- und damit der Rotation.
 */
function aktion_plaene_sortieren(array $eingabe): never {
    $userId = to_int_or_null($eingabe['user_id'] ?? null);
    $ids    = to_id_list($eingabe['ids'] ?? []);

    if ($userId === null || $ids === []) {
        json_err('Keine Reihenfolge übermittelt.', 422);
    }

    // Alle IDs muessen diesem Benutzer gehoeren -- sonst liesse sich ueber
    // eine fremde Plan-ID die Sortierung eines anderen Kontos verbiegen.
    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM plans WHERE id IN ($platzhalter) AND user_id = ?"
    );
    $stmt->execute([...$ids, $userId]);
    if ((int)$stmt->fetchColumn() !== count($ids)) {
        json_err('Ungültige Reihenfolge.', 422);
    }

    db_transaction(function (PDO $pdo) use ($ids): void {
        $stmt = $pdo->prepare('UPDATE plans SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $stmt->execute([($i + 1) * 10, $id]);
        }
    });

    json_ok(['count' => count($ids)]);
}

function aktion_uebung_hinzufuegen(array $eingabe): never {
    $planId     = to_int_or_null($eingabe['plan_id'] ?? null);
    $exerciseId = to_int_or_null($eingabe['exercise_id'] ?? null);

    if ($planId === null || $exerciseId === null) {
        json_err('Plan oder Übung fehlt.', 422);
    }

    $plan = plan_laden($planId);
    struktur_sperre_pruefen((int)$plan['user_id']);

    $stmt = db()->prepare('SELECT name_de, archived FROM exercises WHERE id = ?');
    $stmt->execute([$exerciseId]);
    $uebung = $stmt->fetch();

    if ($uebung === false) {
        json_err('Diese Übung gibt es nicht.', 404);
    }
    if ((int)$uebung['archived'] === 1) {
        json_err('Archivierte Übungen lassen sich nicht in einen Plan aufnehmen.', 409);
    }

    // Dieselbe Uebung zweimal im selben Plan waere in v1 nicht darstellbar:
    // der Fortschritt zaehlt Planpositionen, und zwei identische Positionen
    // waeren in der Ansicht nicht auseinanderzuhalten.
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM plan_exercises WHERE plan_id = ? AND exercise_id = ?'
    );
    $stmt->execute([$planId, $exerciseId]);
    if ((int)$stmt->fetchColumn() > 0) {
        json_err('„' . $uebung['name_de'] . '“ steht bereits in diesem Plan.', 409);
    }

    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM plan_exercises WHERE plan_id = ?');
    $stmt->execute([$planId]);
    $max = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO plan_exercises (plan_id, exercise_id, sort_order) VALUES (?, ?, ?)'
    );
    $stmt->execute([$planId, $exerciseId, $max + 10]);

    json_ok(['id' => (int)db()->lastInsertId()]);
}

/**
 * Laedt eine Planposition samt Uebung und Besitzer oder bricht mit 404 ab.
 */
function position_laden(int $peId): array {
    $stmt = db()->prepare(
        'SELECT pe.id, pe.plan_id, pe.exercise_id, p.user_id
           FROM plan_exercises pe
           JOIN plans p ON p.id = pe.plan_id
          WHERE pe.id = ?'
    );
    $stmt->execute([$peId]);
    $position = $stmt->fetch();

    if ($position === false) {
        json_err('Diese Planposition gibt es nicht (mehr).', 404);
    }
    return $position;
}

function aktion_uebung_entfernen(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    struktur_sperre_pruefen((int)$position['user_id']);

    // Der workout_log bleibt erhalten -- plan_exercise_id wird per
    // ON DELETE SET NULL geleert, die Historie zeigt weiter auf exercise_id (§4.1).
    db()->prepare('DELETE FROM plan_exercises WHERE id = ?')->execute([$peId]);

    json_ok(['deleted' => $peId]);
}

/**
 * Die Uebungen, die als Ersatz fuer diese Position ausscheiden.
 *
 * Anders als im Training gibt es hier keine Einheit und damit keine Taeusche:
 * Es zaehlt der Plan, wie er in der Datenbank steht.
 *
 * @return int[]
 */
function plan_ausschluss(array $position): array {
    return uebungen_im_plan((int)$position['plan_id'], null);
}

/**
 * Tauschvorschlaege fuer eine Planposition (§7.5).
 *
 * Bewusst dieselbe Quelle wie im Training -- tausch_vorschlaege() in
 * lib/training.php: gleiche Hauptgruppe, nicht archiviert, nicht ohnehin im
 * Plan, und in derselben Reihenfolge (zuerst dieselbe Untergruppe). Eine
 * zweite Vorschlagslogik wuerde frueher oder spaeter abweichen, und dann
 * schluege der Adminbereich etwas vor, das im Training gar nicht erlaubt ist.
 */
function aktion_tausch_vorschlaege(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    $uebungId = (int)$position['exercise_id'];

    $vorschlaege = tausch_vorschlaege($uebungId, plan_ausschluss($position));
    $alle        = tausch_vorschlaege($uebungId);

    json_ok([
        'suggestions' => $vorschlaege,
        // Wie viele Alternativen fielen weg, weil sie schon im Plan stehen?
        // Ohne diese Zahl wirkt eine kurze Liste wie ein Fehler.
        'im_plan'     => count($alle) - count($vorschlaege),
        'gesperrt'    => hat_offene_einheit((int)$position['user_id']),
    ]);
}

/**
 * Eine Planposition dauerhaft auf eine andere Uebung umstellen.
 *
 * Das ist derselbe Vorgang wie "Dauerhaft im Plan" im Training, nur ohne
 * Einheit -- und deshalb hier und nicht in api/swap.php: Dort haengt jede
 * Pruefung an der eigenen Sitzung, ein Admin bearbeitet aber die Plaene
 * ANDERER Benutzer.
 */
function aktion_uebung_tauschen(array $eingabe): never {
    $peId   = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    $neueId = to_int_or_null($eingabe['exercise_id'] ?? null);

    if ($peId === null || $neueId === null) {
        json_err('Planposition oder Übung fehlt.', 422);
    }

    $position = position_laden($peId);
    struktur_sperre_pruefen((int)$position['user_id']);

    if ($neueId === (int)$position['exercise_id']) {
        json_err('Diese Übung steht bereits an dieser Position.', 409);
    }

    // Der Vorschlag kam vom Server -- ein von Hand gebauter Request koennte
    // aber jede beliebige ID schicken. Deshalb gegen DIESELBE Liste pruefen,
    // die der Vorschlag geliefert hat.
    $erlaubt = array_map(
        static fn(array $v): int => (int)$v['id'],
        tausch_vorschlaege((int)$position['exercise_id'], plan_ausschluss($position))
    );
    if (!in_array($neueId, $erlaubt, true)) {
        json_err(
            'Diese Übung ist kein gültiger Ersatz — sie trainiert eine andere '
            . 'primäre Muskelgruppe, ist archiviert, oder steht bereits in '
            . 'diesem Plan.',
            409
        );
    }

    db()->prepare('UPDATE plan_exercises SET exercise_id = ? WHERE id = ?')
        ->execute([$neueId, $peId]);

    json_ok(['plan_exercise_id' => $peId, 'exercise_id' => $neueId]);
}

function aktion_uebungen_sortieren(array $eingabe): never {
    $planId = to_int_or_null($eingabe['plan_id'] ?? null);
    $ids    = to_id_list($eingabe['ids'] ?? []);

    if ($planId === null || $ids === []) {
        json_err('Keine Reihenfolge übermittelt.', 422);
    }

    $plan = plan_laden($planId);
    struktur_sperre_pruefen((int)$plan['user_id']);

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM plan_exercises WHERE id IN ($platzhalter) AND plan_id = ?"
    );
    $stmt->execute([...$ids, $planId]);
    if ((int)$stmt->fetchColumn() !== count($ids)) {
        json_err('Ungültige Reihenfolge.', 422);
    }

    db_transaction(function (PDO $pdo) use ($ids): void {
        $stmt = $pdo->prepare('UPDATE plan_exercises SET sort_order = ? WHERE id = ?');
        foreach ($ids as $i => $id) {
            $stmt->execute([($i + 1) * 10, $id]);
        }
    });

    json_ok(['count' => count($ids)]);
}
