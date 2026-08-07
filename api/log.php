<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/training.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();

/**
 * Protokollieren von "Erledigt", Gewicht und Wiederholungen (§7.4).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const GEWICHT_MAX = 1000.0;

$eingabe = read_json_body();

// Es gibt bewusst KEIN "update": Ein Wert wird geaendert, indem man das
// Haekchen entfernt, den Wert korrigiert und neu abhakt (§7.4). Damit gibt es
// einen Mechanismus statt zweier -- genau wie beim Tausch (§7.5).
match (to_str($eingabe['action'] ?? '')) {
    'check'   => aktion_abhaken($eingabe),
    'uncheck' => aktion_abwaehlen($eingabe),
    default   => json_err('Unbekannte Aktion', 400),
};

/**
 * Laedt eine Planposition und stellt sicher, dass sie dem angemeldeten
 * Benutzer gehoert.
 *
 * Das ist der IDOR-Schutz aus §5 an der Stelle, an der er am ehesten vergessen
 * wird: Die plan_exercise_id kommt vom Client, und ohne diese Pruefung liesse
 * sich in den Plan eines anderen Benutzers hineinprotokollieren.
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
    if ((int)$position['user_id'] !== current_user_id()) {
        json_err('Kein Zugriff auf diesen Plan.', 403);
    }

    // Eine Einheit gehoert zu genau einem Plan. Eine Position aus einem anderen
    // Plan hier zuzulassen wuerde workout_log.plan_id gegen sessions.plan_id
    // laufen lassen und den Zaehler "x/n" ueber n treiben.
    if (!position_passt_zur_einheit(current_user_id(), (int)$position['plan_id'])) {
        json_err(
            'Diese Übung gehört zu einem anderen Plan als die laufende Einheit. '
            . 'Bitte die Seite neu laden.',
            409
        );
    }

    return $position;
}

/**
 * Prueft die Gewichtseingabe. Sie darf leer sein -- "Erledigt" funktioniert
 * auch ohne Gewichtsangabe, etwa bei Bauch- oder Koerpergewichtsuebungen (§7.4).
 */
function gewicht_pruefen(array $eingabe): ?float {
    $roh = $eingabe['weight'] ?? null;
    $gewicht = ($roh === null || $roh === '') ? null : to_decimal_or_null($roh);

    if ($roh !== null && $roh !== '' && $gewicht === null) {
        json_err('Bitte die Eingabe prüfen.', 422, ['weight' => 'Bitte eine Zahl eingeben.']);
    }
    if ($gewicht !== null && ($gewicht < 0 || $gewicht > GEWICHT_MAX)) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'weight' => 'Zwischen 0 und ' . (int)GEWICHT_MAX . ' kg.',
        ]);
    }

    return $gewicht;
}

/**
 * Die tatsaechlich angezeigte Uebung dieser Position -- nach einem Tausch die
 * Ersatzuebung, sonst die des Plans (§7.5).
 */
function angezeigte_uebung(int $peId, int $planUebungId, int $sessionId): int {
    $stmt = db()->prepare(
        'SELECT replacement_exercise_id FROM exercise_swaps
          WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$sessionId, $peId]);
    $ersatz = $stmt->fetchColumn();

    return $ersatz === false ? $planUebungId : (int)$ersatz;
}

/**
 * Abhaken. Startet die Einheit, falls noch keine laeuft (§7.6).
 */
function aktion_abhaken(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    $gewicht  = gewicht_pruefen($eingabe);

    $userId    = current_user_id();
    $sessionId = einheit_sicherstellen($userId, (int)$position['plan_id']);
    $uebungId  = angezeigte_uebung($peId, (int)$position['exercise_id'], $sessionId);

    // Ein Eintrag je Einheit UND Planposition -- nicht je Uebung. Der
    // Konflikt-Zielschluessel ist deshalb (session_id, plan_exercise_id).
    $stmt = db()->prepare(
        'INSERT INTO workout_log
             (session_id, plan_exercise_id, user_id, exercise_id, plan_id,
              weight, performed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT (session_id, plan_exercise_id) DO UPDATE SET
             exercise_id  = excluded.exercise_id,
             weight       = excluded.weight,
             performed_at = excluded.performed_at'
    );
    $stmt->execute([
        $sessionId, $peId, $userId, $uebungId, (int)$position['plan_id'],
        $gewicht, now(),
    ]);

    json_ok(fortschritt($sessionId, (int)$position['plan_id']) + [
        'session_id' => $sessionId,
        'erledigt'   => true,
    ]);
}

/**
 * Haekchen wieder abwaehlen: der Eintrag dieser Einheit verschwindet (§7.4).
 *
 * Die Einheit selbst bleibt offen -- auch wenn es der letzte Eintrag war.
 * Sie zu schliessen ist eine bewusste Handlung, kein Nebeneffekt.
 */
function aktion_abwaehlen(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    $offen = offene_einheit(current_user_id());

    if ($offen === null) {
        json_err('Es läuft gerade keine Trainingseinheit.', 409);
    }

    db()->prepare(
        'DELETE FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    )->execute([$offen['id'], $peId]);

    json_ok(fortschritt((int)$offen['id'], (int)$position['plan_id']) + [
        'erledigt' => false,
    ]);
}

/**
 * Der Zaehler "x/n": n sind die Planpositionen, x die Positionen mit Eintrag
 * in dieser Einheit (§7.3).
 */
function fortschritt(int $sessionId, int $planId): array {
    $stmt = db()->prepare('SELECT COUNT(*) FROM plan_exercises WHERE plan_id = ?');
    $stmt->execute([$planId]);
    $n = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM workout_log WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $x = (int)$stmt->fetchColumn();

    return ['erledigt_anzahl' => $x, 'gesamt' => $n, 'alle_erledigt' => $n > 0 && $x >= $n];
}
