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
 * Trainingseinheiten (§7.6).
 *
 * Eine Einheit entsteht auf DREI Wegen (§7.6):
 *   1. ausdruecklich ueber "start" hier,
 *   2. beim Abhaken der ersten Uebung (api/log.php),
 *   3. beim Tausch "nur diese Einheit" (api/swap.php).
 *
 * Weg 1 kam am 2026-08-07 dazu. Vorher entstand die Einheit fruehestens beim
 * Abhaken -- der Zeitstempel hielt damit das ENDE der ersten Uebung fest, nicht
 * den Trainingsbeginn. Bei drei Saetzen sind das schnell zehn Minuten, und die
 * Auswertung zeigte durchweg zu kurze Dauern.
 *
 * Die Wege 2 und 3 bleiben, damit niemand feststeckt, der den Knopf uebersieht.
 * Blosses Anschauen startet weiterhin nichts.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'start'  => aktion_starten($eingabe),
    'end'    => aktion_beenden(),
    'delete' => aktion_loeschen($eingabe),
    default  => json_err('Unbekannte Aktion', 400),
};

/**
 * Eine abgeschlossene Einheit samt Protokoll loeschen (§7.8).
 *
 * Braucht man fuer Fehleingaben -- eine versehentlich gestartete Einheit, ein
 * Training, das man doch abgebrochen hat, oder Testdaten. Ohne diesen Weg
 * blieben solche Zeilen dauerhaft stehen und blockierten ueber ihre
 * workout_log-Eintraege sogar das endgueltige Loeschen von Uebungen (§6.3).
 *
 * Ausschliesslich die EIGENEN Einheiten, auch fuer Admins: Die user_id steht
 * in der WHERE-Klausel, nicht in einer Pruefung davor.
 *
 * Die offene Einheit ist ausgenommen -- die wird beendet, nicht geloescht.
 */
function aktion_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['session_id'] ?? null);
    if ($id === null) {
        json_err('Keine Einheit angegeben.', 422);
    }

    $stmt = db()->prepare(
        'SELECT id, ended_at FROM sessions WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$id, current_user_id()]);
    $einheit = $stmt->fetch();

    if ($einheit === false) {
        json_err('Diese Einheit gibt es nicht (mehr).', 404);
    }
    if ($einheit['ended_at'] === null) {
        json_err(
            'Diese Einheit läuft noch. Sie lässt sich beenden, aber nicht löschen.',
            409
        );
    }

    // workout_log und exercise_swaps haengen mit ON DELETE CASCADE daran und
    // verschwinden mit -- genau das ist hier gewollt.
    $stmt = db()->prepare('SELECT COUNT(*) FROM workout_log WHERE session_id = ?');
    $stmt->execute([$id]);
    $eintraege = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM sessions WHERE id = ? AND user_id = ?')
        ->execute([$id, current_user_id()]);

    json_ok([
        'deleted' => $id,
        'meldung' => 'Einheit gelöscht' . ($eintraege > 0
            ? ' — ' . $eintraege . ' Protokolleintrag/-einträge entfernt.'
            : '.'),
    ]);
}

/**
 * Einheit ausdruecklich starten.
 *
 * Laeuft bereits eine, wird deren ID geliefert statt ein Fehler geworfen: Zwei
 * Geraete oder ein doppelter Tipp duerfen nicht in einer Fehlermeldung enden,
 * wenn das gewuenschte Ergebnis ohnehin schon eingetreten ist.
 */
function aktion_starten(array $eingabe): never {
    $planId = to_int_or_null($eingabe['plan_id'] ?? null);
    if ($planId === null) {
        json_err('Kein Plan angegeben.', 422);
    }

    $userId = current_user_id();

    // Der Plan muss dem Benutzer gehoeren -- sonst liesse sich eine Einheit auf
    // einen fremden Plan eroeffnen (IDOR, §5).
    $stmt = db()->prepare('SELECT COUNT(*) FROM plans WHERE id = ? AND user_id = ?');
    $stmt->execute([$planId, $userId]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_err('Diesen Plan gibt es nicht.', 404);
    }

    json_ok(['session_id' => einheit_sicherstellen($userId, $planId)]);
}

/**
 * Einheit beenden -- der einzige Weg, sie zu schliessen.
 *
 * Auch wenn alle Positionen abgehakt sind, schliesst sich nichts von selbst:
 * Die Oberflaeche fragt nach, und erst die Bestaetigung landet hier (§7.6).
 * Sonst waere das Ab-waehlen eines versehentlichen Haekchens undefiniert.
 */
function aktion_beenden(): never {
    $sessionId = einheit_beenden(current_user_id());

    if ($sessionId === null) {
        json_err('Es läuft gerade keine Trainingseinheit.', 409);
    }

    json_ok(['ended' => $sessionId]);
}
