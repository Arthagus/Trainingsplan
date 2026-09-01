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
 * Trainingseinheiten (§7.6).
 *
 * Eine Einheit entsteht auf GENAU EINEM Weg: ausdruecklich ueber "start" hier
 * (§7.6, Fallstrick 1). api/log.php und api/swap.php antworten seit 1.1.6 mit
 * 409, statt eine anzulegen.
 *
 * Der Knopf kam am 2026-08-07 dazu. Vorher entstand die Einheit fruehestens
 * beim Abhaken -- der Zeitstempel hielt damit das ENDE der ersten Uebung fest,
 * nicht den Trainingsbeginn. Bei drei Saetzen sind das schnell zehn Minuten,
 * und die Auswertung zeigte durchweg zu kurze Dauern. Die beiden anderen Wege
 * fielen am 2026-08-12: Ein Fehlgriff beim blossen Durchsehen begann ein
 * Training, das niemand wollte, und verstellte danach die Rotation.
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

    // Die Positionen, die nur zu DIESER Einheit gehoerten, gehen mit (§7.6).
    // Ausdruecklich und nicht ueber die Kaskade: Auf einer Bestandsdatenbank
    // traegt plan_exercises.session_id keinen Fremdschluessel -- SQLite kann
    // ueber ALTER TABLE keinen nachtragen -, und die Zeilen blieben sonst als
    // Waisen stehen. Sichtbar waeren sie nirgends mehr, aber sie waeren da.
    //
    // VOR dem Loeschen der Einheit: Danach ist die session_id weg, und es gaebe
    // nichts mehr, woran man sie erkennt. Dass dabei workout_log.plan_exercise_id
    // kurz auf NULL faellt, ist ohne Folge -- diese Zeilen verschwinden im
    // naechsten Schritt mit der Einheit.
    db()->prepare('DELETE FROM plan_exercises WHERE session_id = ?')->execute([$id]);

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
    // einen fremden Plan eroeffnen (IDOR, §5). Seit 1.2.0 entscheidet das
    // splits.user_id und nicht mehr plans.user_id (die ist tot, siehe
    // schema.sql).
    //
    // plan_gehoert() liefert fuer eine VORLAGE immer false, auch bei einem
    // Admin -- und das ist die wichtigste Sperre dieser Datei. Wer auf einer
    // Vorlage trainierte, schriebe mit dem ersten dauerhaften Tausch (§7.5) in
    // den Bestand aller Benutzer. Der fehlende Startknopf in der Oberflaeche
    // ist nur die Bequemlichkeit davor.
    if (!plan_gehoert($planId, $userId)) {
        json_err('Diesen Plan gibt es nicht.', 404);
    }

    $sessionId = einheit_sicherstellen($userId, $planId);

    // Die Auswahl nachziehen, damit sie nicht hinter der Wirklichkeit
    // zurueckbleibt: Wer in einem Split trainiert, hat ihn gewaehlt.
    $split = split_von_plan($planId);
    if ($split !== null) {
        aktiven_split_setzen($userId, (int)$split['split_id']);
    }

    json_ok(['session_id' => $sessionId]);
}

/**
 * Einheit beenden -- der einzige Weg, sie zu schliessen.
 *
 * Auch wenn alle Positionen abgehakt sind, schliesst sich nichts von selbst:
 * Die Oberflaeche fragt nach, und erst die Bestaetigung landet hier (§7.6).
 * Sonst waere das Ab-waehlen eines versehentlichen Haekchens undefiniert.
 *
 * Beim Beenden traegt einheit_beenden() fehlende Haekchen nach: Was Saetze
 * traegt, gilt als erledigt (seit 1.4.5). `nachgetragen` sagt, wie viele es
 * waren -- 0 ist der Normalfall. Die Zahl wird mitgeliefert und NICHT
 * angezeigt: Ein Netz, das im Regelfall schweigt, ist eines; eine Meldung
 * nach jedem Training waere die Sorte Anzeige, die man sich abgewoehnt
 * (Fallstrick 29). Wer wissen will, ob es gegriffen hat, sieht sie in der
 * Antwort.
 */
function aktion_beenden(): never {
    $ergebnis = einheit_beenden(current_user_id());

    if ($ergebnis === null) {
        json_err('Es läuft gerade keine Trainingseinheit.', 409);
    }

    json_ok([
        'ended'        => $ergebnis['id'],
        'nachgetragen' => $ergebnis['nachgetragen'],
    ]);
}
