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
 * Uebungstausch (§7.5).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'suggestions' => aktion_vorschlaege($eingabe),
    'apply'       => aktion_tauschen($eingabe),
    default       => json_err('Unbekannte Aktion', 400),
};

/**
 * Planposition laden und Eigentuemerschaft pruefen (IDOR-Schutz, §5).
 */
function position_laden(int $peId): array {
    $stmt = db()->prepare(
        'SELECT pe.id, pe.plan_id, pe.exercise_id, p.split_id, sp.user_id
           FROM plan_exercises pe
           JOIN plans  p  ON p.id  = pe.plan_id
           JOIN splits sp ON sp.id = p.split_id
          WHERE pe.id = ?'
    );
    $stmt->execute([$peId]);
    $position = $stmt->fetch();

    if ($position === false) {
        json_err('Diese Planposition gibt es nicht (mehr).', 404);
    }
    // splits.user_id und NICHT plans.user_id -- letztere ist seit 1.2.0 tot
    // (siehe schema.sql). Fuer eine VORLAGE ist sie NULL, der Vergleich schlaegt
    // also fehl, und damit laesst sich in den Katalog nicht hineinprotokolliert
    // und nicht hineingetauscht werden. Genau so soll es sein: Trainiert wird
    // ausschliesslich auf der eigenen Kopie.
    if ($position['user_id'] === null || (int)$position['user_id'] !== current_user_id()) {
        json_err('Kein Zugriff auf diesen Plan.', 403);
    }

    // Siehe api/log.php: Ein Tausch haengt an der Einheit, also muss die
    // Position zum Plan dieser Einheit gehoeren.
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
 * Ist diese Position in der laufenden Einheit schon abgehakt?
 *
 * Solange sie es ist, ist JEDER Tausch gesperrt (§7.5). Ein Protokolleintrag
 * dokumentiert eine tatsaechlich ausgefuehrte Uebung; ihn auf die Ersatzuebung
 * umzuschreiben wuerde das erreichte Gewicht einer Uebung zuschlagen, die gar
 * nicht gemacht wurde. Ihn stehen zu lassen, waehrend die Ansicht etwas
 * anderes zeigt, ist ebenso falsch. Also: erst Haekchen weg, dann tauschen.
 */
function position_abgehakt(int $peId): bool {
    $offen = offene_einheit(current_user_id());
    if ($offen === null) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$offen['id'], $peId]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Welche Uebung steht gerade an dieser Position -- im Plan oder als Tausch?
 */
function aktuelle_uebung(array $position): int {
    $offen = offene_einheit(current_user_id());
    if ($offen === null) {
        return (int)$position['exercise_id'];
    }

    $stmt = db()->prepare(
        'SELECT replacement_exercise_id FROM exercise_swaps
          WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$offen['id'], $position['id']]);
    $ersatz = $stmt->fetchColumn();

    return $ersatz === false ? (int)$position['exercise_id'] : (int)$ersatz;
}

/**
 * Die Uebungen, die in dieser Einheit ohnehin anstehen -- sie scheiden als
 * Ersatz aus (§7.5).
 *
 * @return int[]
 */
function ausschluss_liste(array $position): array {
    $offen = offene_einheit(current_user_id());

    return uebungen_im_plan(
        (int)$position['plan_id'],
        $offen === null ? null : (int)$offen['id']
    );
}

function aktion_vorschlaege(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    $uebungId = aktuelle_uebung($position);

    $ausschluss  = ausschluss_liste($position);

    // Wo steht der Vorschlag im Split sonst noch? Dieselbe Auskunft wie in der
    // Uebungsauswahl und im Tauschfenster der Planverwaltung -- hier zaehlt sie
    // besonders: Wer ausweicht, weil eine Maschine besetzt ist, will nicht
    // ausgerechnet die Uebung nehmen, die uebermorgen im naechsten Plan ohnehin
    // ansteht. Der eigene Plan ist ausgenommen; was dort steht, hat
    // ausschluss_liste() schon aussortiert.
    $vorschlaege = andere_plaene_eintragen(
        tausch_vorschlaege($uebungId, $ausschluss),
        (int)$position['split_id'],
        (int)$position['plan_id']
    );

    // Wie viele Alternativen fielen weg, weil sie schon im Plan stehen? Ohne
    // diese Zahl wirkt eine kurze Liste wie ein Fehler.
    $alle = tausch_vorschlaege($uebungId);

    json_ok([
        'suggestions'    => $vorschlaege,
        'abgehakt'       => position_abgehakt($peId),
        'im_plan'        => count($alle) - count($vorschlaege),
    ]);
}

function aktion_tauschen(array $eingabe): never {
    $peId    = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    $neueId  = to_int_or_null($eingabe['exercise_id'] ?? null);
    $modus   = to_str($eingabe['mode'] ?? '');

    if ($peId === null || $neueId === null) {
        json_err('Planposition oder Übung fehlt.', 422);
    }
    if (!in_array($modus, ['session', 'permanent'], true)) {
        json_err('Unbekannter Tauschmodus.', 422);
    }

    $position = position_laden($peId);
    $aktuell  = aktuelle_uebung($position);

    if ($neueId === $aktuell) {
        json_err('Diese Übung steht bereits an dieser Position.', 409);
    }

    // Gilt fuer BEIDE Modi. Die Alternative -- den Protokolleintrag auf die
    // Ersatzuebung umzuschreiben -- wuerde stillschweigend Historie faelschen.
    //
    // Gesperrt wird, sobald ETWAS protokolliert ist -- im Expertenmodus also
    // schon ab dem ersten Satz und nicht erst ab dem Haekchen (§7.4). Wer zwei
    // Saetze Bankdruecken gemacht hat, kann die Position nicht mehr gegen eine
    // andere Uebung tauschen; die zwei Saetze waren Bankdruecken.
    if (position_abgehakt($peId)) {
        json_err(
            'Für diese Übung sind bereits Werte protokolliert. Zum Tauschen erst '
            . 'die Werte entfernen — Häkchen weg bzw. Sätze löschen —, dann '
            . 'tauschen und neu eintragen.',
            409
        );
    }

    // Der Vorschlag kam vom Server -- aber ein manipulierter Request koennte
    // jede beliebige ID schicken. Deshalb hier noch einmal pruefen, und zwar
    // gegen DIESELBE Liste, die der Vorschlag geliefert hat: gleiche
    // Primaergruppe, nicht archiviert, nicht ohnehin im Plan.
    $erlaubt = array_map(
        static fn(array $v): int => (int)$v['id'],
        tausch_vorschlaege($aktuell, ausschluss_liste($position))
    );
    if (!in_array($neueId, $erlaubt, true)) {
        json_err(
            'Diese Übung ist kein gültiger Ersatz — sie trainiert eine andere '
            . 'primäre Muskelgruppe, ist archiviert, oder steht bereits in '
            . 'diesem Plan.',
            409
        );
    }

    $userId = current_user_id();

    if ($modus === 'permanent') {
        db()->prepare('UPDATE plan_exercises SET exercise_id = ? WHERE id = ?')
            ->execute([$neueId, $peId]);

        json_ok(['mode' => 'permanent', 'exercise_id' => $neueId]);
    }

    // "Nur diese Einheit" braucht eine session_id -- und bis 1.1.5 legte es
    // dafuer stillschweigend eine Einheit an (§7.6, Fallstrick 1). Das ist
    // seit 1.1.6 vorbei: Eine Einheit beginnt ausschliesslich mit "Training
    // starten", sonst haelt started_at nicht den Trainingsbeginn fest.
    //
    // Der DAUERHAFTE Tausch oben braucht keine Einheit und bleibt deshalb auch
    // vor dem Start moeglich -- er aendert den Plan, nicht das Protokoll.
    //
    // Ein Umschreiben vorhandener Log-Eintraege gibt es hier bewusst nicht:
    // Eine abgehakte Position kommt gar nicht bis hierher.
    $offen = offene_einheit($userId);
    if ($offen === null) {
        json_err(
            'Für heute tauschen geht erst, wenn das Training läuft — bitte zuerst '
            . '„Training starten" drücken. Dauerhaft im Plan tauschen geht auch vorher.',
            409
        );
    }
    $sessionId = (int)$offen['id'];

    db()->prepare(
        'INSERT INTO exercise_swaps
             (session_id, plan_exercise_id, replacement_exercise_id, created_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT (session_id, plan_exercise_id) DO UPDATE SET
             replacement_exercise_id = excluded.replacement_exercise_id,
             created_at              = excluded.created_at'
    )->execute([$sessionId, $peId, $neueId, now()]);

    json_ok([
        'mode'       => 'session',
        'session_id' => $sessionId,
        'exercise_id' => $neueId,
    ]);
}
