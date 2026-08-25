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
 * Protokollieren von "Erledigt", Gewicht und Saetzen (§7.4).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const GEWICHT_MAX = 1000.0;
const WDH_MAX     = 200;
const SAETZE_MAX  = 20;

$eingabe = read_json_body();

// Es gibt bewusst KEIN "update": Ein Wert wird geaendert, indem man das
// Haekchen entfernt, den Wert korrigiert und neu abhakt (§7.4). Damit gibt es
// einen Mechanismus statt zweier -- genau wie beim Tausch (§7.5).
//
// Auch die Saetze des Expertenmodus kommen ohne eigene Aktion aus: Sie reisen
// als vollstaendige Liste im "check" mit. Siehe saetze_pruefen().
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
        'SELECT pe.id, pe.plan_id, pe.exercise_id, sp.user_id
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
 * Prueft die Satzliste des Expertenmodus (§7.4).
 *
 * DIE GANZE LISTE IST DIE NUTZLAST, nicht der einzelne Satz. Das ist die
 * tragende Entscheidung dieses Endpunkts: Der Aufruf ersetzt die Saetze einer
 * Position vollstaendig und ist damit idempotent -- beliebig oft wiederholbar,
 * ohne dass Dubletten entstehen. Genau darauf verlaesst sich die
 * Warteschlange (§7.4), die einen Eintrag je Planposition haelt, ihn bei jeder
 * Aenderung ueberschreibt und ihn nach einem Funkloch erneut abschickt. Ein
 * "Satz anlegen"-Endpunkt haette dagegen bei jedem Wiederversuch einen vierten
 * Satz erzeugt.
 *
 * Rueckgabe null heisst "keine Satzliste dabei" -- der einfache Modus. Eine
 * mitgeschickte LEERE Liste zaehlt genauso: eine Position ohne Satz ist eine
 * Position ohne Satz, und zwei Schreibweisen fuer dieselbe Aussage brauchen
 * niemanden zu beschaeftigen.
 *
 * @return list<array{satz_nr:int, reps:?int, weight:?float}>|null
 */
function saetze_pruefen(array $eingabe): ?array {
    $roh = $eingabe['sets'] ?? null;

    if ($roh === null) {
        return null;
    }
    if (!is_array($roh)) {
        json_err('Bitte die Eingabe prüfen.', 422, ['sets' => 'Ungültige Satzliste.']);
    }
    if ($roh === []) {
        return null;
    }
    if (count($roh) > SAETZE_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Höchstens ' . SAETZE_MAX . ' Sätze je Übung.',
        ]);
    }

    $saetze = [];
    $nr = 0;

    foreach ($roh as $eintrag) {
        $nr++;
        if (!is_array($eintrag)) {
            json_err('Bitte die Eingabe prüfen.', 422, [
                'sets' => 'Satz ' . $nr . ': ungültig.',
            ]);
        }

        $reps   = satz_wdh_pruefen($eintrag['reps'] ?? null, $nr);
        $gewicht = satz_gewicht_pruefen($eintrag['weight'] ?? null, $nr);

        // Ein Satz ohne beides sagt nichts aus -- er waere eine Zeile, die
        // Platz braucht und keine Frage beantwortet.
        if ($reps === null && $gewicht === null) {
            json_err('Bitte die Eingabe prüfen.', 422, [
                'sets' => 'Satz ' . $nr . ': Wiederholungen oder Gewicht angeben.',
            ]);
        }

        // satz_nr wird hier vergeben und nicht vom Client uebernommen: Die
        // Reihenfolge der Liste IST die Reihenfolge der Saetze, und damit kann
        // es keine Luecken und keine doppelten Nummern geben.
        $saetze[] = ['satz_nr' => $nr, 'reps' => $reps, 'weight' => $gewicht];
    }

    return $saetze;
}

/**
 * Wiederholungen eines Satzes: leer erlaubt (Halten auf Zeit), sonst eine
 * ganze Zahl zwischen 1 und WDH_MAX.
 */
function satz_wdh_pruefen(mixed $roh, int $nr): ?int {
    if ($roh === null || $roh === '') {
        return null;
    }

    $reps = to_int_or_null($roh);
    if ($reps === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': Wiederholungen als ganze Zahl angeben.',
        ]);
    }
    if ($reps < 1 || $reps > WDH_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': zwischen 1 und ' . WDH_MAX . ' Wiederholungen.',
        ]);
    }

    return $reps;
}

/**
 * Gewicht eines Satzes: leer erlaubt (Koerpergewicht), sonst 0 bis GEWICHT_MAX.
 */
function satz_gewicht_pruefen(mixed $roh, int $nr): ?float {
    if ($roh === null || $roh === '') {
        return null;
    }

    $gewicht = to_decimal_or_null($roh);
    if ($gewicht === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': Gewicht als Zahl angeben.',
        ]);
    }
    if ($gewicht < 0 || $gewicht > GEWICHT_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': zwischen 0 und ' . (int)GEWICHT_MAX . ' kg.',
        ]);
    }

    return $gewicht;
}

/**
 * Das Leitgewicht einer Position: der SCHWERSTE Satz.
 *
 * Es landet in workout_log.weight und haelt damit alles am Laufen, was es
 * heute schon gibt -- "letztes Gewicht" (§4), den Gewichtsverlauf und den
 * Bestwert (§7.8). Deshalb der schwerste und nicht der letzte Satz: Der
 * Bestwert ist die Zahl, an der man den Fortschritt misst, und wer 12×40,
 * 10×40, 9×45 macht, hat 45 kg erreicht und nicht 45 kg "zuletzt zufaellig".
 *
 * null, wenn kein einziger Satz ein Gewicht traegt -- eine 0 stuende fuer
 * "ohne Gewicht bewegt" und liesse sich von "nichts eingetragen" nicht mehr
 * unterscheiden.
 */
function leitgewicht(array $saetze): ?float {
    $best = null;
    foreach ($saetze as $s) {
        if ($s['weight'] !== null && ($best === null || $s['weight'] > $best)) {
            $best = $s['weight'];
        }
    }
    return $best;
}

/**
 * Eine als ERLEDIGT markierte Position ist festgeschrieben (§7.4).
 *
 * Geaendert wird ueber Haekchen entfernen, korrigieren, neu abhaken -- derselbe
 * eine Mechanismus wie beim Gewichtsfeld im einfachen Modus und beim Tausch
 * (§7.5). Die deaktivierten Felder in der Oberflaeche sind nur die Bequemlichkeit
 * davor; verboten wird es hier.
 *
 * DREI Ausnahmen, und jede ist noetig:
 *
 * 1. Ohne laufende Einheit oder ohne bestehende Zeile gibt es nichts zu
 *    schuetzen.
 * 2. `done = false` ist genau der Weg, die Sperre aufzuheben -- er muss
 *    durchgehen.
 * 3. **Eine unveraendert durchgereichte Nutzlast muss durchgehen.** Sonst
 *    zerbricht die Idempotenz, auf der die Warteschlange steht (§7.4): Sie
 *    schickt einen Eintrag nach einem Funkloch erneut, und der zweite Aufruf
 *    traefe dann auf die bereits abgehakte Position und schluege mit 409 fehl --
 *    fuer den Benutzer ein Fehler, obwohl alles laengst gespeichert ist.
 */
function abgeschlossene_position_schuetzen(
    int $peId,
    ?array $saetze,
    ?float $gewicht,
    bool $erledigt
): void {
    $offen = offene_einheit(current_user_id());
    if ($offen === null || !$erledigt) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id, weight, done FROM workout_log
          WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$offen['id'], $peId]);
    $zeile = $stmt->fetch();

    if ($zeile === false || (int)$zeile['done'] !== 1) {
        return;
    }

    $logId   = (int)$zeile['id'];
    $bestand = saetze_zu_logs([$logId])[$logId] ?? [];

    if (saetze_gleich($bestand, $saetze ?? [])
        && zahl_gleich(to_decimal_or_null($zeile['weight']), $gewicht)) {
        return;
    }

    json_err(
        'Diese Übung ist als erledigt markiert. Zum Ändern erst das Häkchen '
        . 'entfernen, dann korrigieren und neu abhaken.',
        409
    );
}

/**
 * Vergleicht zwei Satzfolgen inhaltlich -- Anzahl, Wiederholungen, Gewicht.
 */
function saetze_gleich(array $a, array $b): bool {
    if (count($a) !== count($b)) {
        return false;
    }

    foreach (array_values($a) as $i => $satz) {
        $andere = array_values($b)[$i];
        if (($satz['reps'] ?? null) !== ($andere['reps'] ?? null)) {
            return false;
        }
        if (!zahl_gleich($satz['weight'] ?? null, $andere['weight'] ?? null)) {
            return false;
        }
    }

    return true;
}

/**
 * Gleichheit zweier Gewichte. Fliesskomma wird nie auf `===` verglichen:
 * 40.0 aus der Datenbank und 40.0 aus der Eingabe sind dasselbe Gewicht,
 * muessen aber nicht dasselbe Bitmuster sein.
 */
function zahl_gleich(?float $a, ?float $b): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.0001;
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

    // Geprueft wird VOR der Transaktion. json_err() beendet die Anfrage
    // sofort; innerhalb einer offenen Transaktion haenge die Entscheidung
    // ueber das Rollback sonst daran, wann PHP die Verbindung abraeumt.
    $saetze = saetze_pruefen($eingabe);

    // Mit Satzliste bestimmt der schwerste Satz das Gewicht der Position, ohne
    // sie das gesendete Feld. Die Nutzlast beschreibt die Zeile jeweils
    // vollstaendig -- deshalb kann Leitgewicht und Satzliste nichts
    // auseinanderbringen.
    $gewicht = $saetze === null ? gewicht_pruefen($eingabe) : leitgewicht($saetze);

    // "Erledigt" ist ein eigener Zustand, nicht die blosse Existenz der Zeile.
    //
    // FEHLT das Feld, gilt die Position als erledigt. Das ist die Vorgabe fuer
    // den einfachen Modus, in dem Abhaken und Protokollieren dasselbe sind --
    // und zugleich der Rueckfall fuer eine Nutzlast aus einer aelteren Fassung.
    $erledigt = !array_key_exists('done', $eingabe) || !empty($eingabe['done']);

    // Ebenfalls vor der Transaktion, aus demselben Grund wie die Pruefungen
    // darueber: json_err() beendet die Anfrage sofort.
    abgeschlossene_position_schuetzen($peId, $saetze, $gewicht, $erledigt);

    $userId = current_user_id();
    $planId = (int)$position['plan_id'];
    $planUebungId = (int)$position['exercise_id'];

    // Protokollieren setzt eine LAUFENDE Einheit voraus (§7.6). Bis 1.1.5 legte
    // einheit_sicherstellen() hier stillschweigend eine an -- ein einziger
    // Fehlgriff auf ein Haekchen begann damit ein Training, und started_at hielt
    // nicht den Trainingsbeginn fest, sondern den Fehlgriff. Eine Einheit
    // beginnt jetzt ausschliesslich mit "Training starten".
    //
    // 409 und nicht 403: Der Aufruf ist erlaubt, nur der Zustand passt nicht.
    // index.js zeigt die Meldung unveraendert an -- sie sagt, was zu tun ist.
    $laufende = offene_einheit($userId);
    if ($laufende === null) {
        json_err('Es läuft kein Training — bitte zuerst „Training starten" drücken.', 409);
    }
    $sessionIdOffen = (int)$laufende['id'];

    $sessionId = db_transaction(
        static function () use (
            $userId, $planId, $peId, $planUebungId, $gewicht, $saetze, $erledigt,
            $sessionIdOffen
        ): int {
            $sessionId = $sessionIdOffen;
            $uebungId  = angezeigte_uebung($peId, $planUebungId, $sessionId);

            // Ein Eintrag je Einheit UND Planposition -- nicht je Uebung. Der
            // Konflikt-Zielschluessel ist deshalb (session_id, plan_exercise_id).
            $stmt = db()->prepare(
                'INSERT INTO workout_log
                     (session_id, plan_exercise_id, user_id, exercise_id, plan_id,
                      weight, done, performed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (session_id, plan_exercise_id) DO UPDATE SET
                     exercise_id  = excluded.exercise_id,
                     weight       = excluded.weight,
                     done         = excluded.done,
                     performed_at = excluded.performed_at'
            );
            $stmt->execute([
                $sessionId, $peId, $userId, $uebungId, $planId, $gewicht,
                $erledigt ? 1 : 0, now(),
            ]);

            saetze_schreiben($sessionId, $peId, $saetze);

            return $sessionId;
        }
    );

    json_ok(fortschritt($sessionId, $planId) + [
        'session_id' => $sessionId,
        'erledigt'   => $erledigt,
        'saetze'     => $saetze ?? [],
    ]);
}

/**
 * Ersetzt die Saetze einer Position vollstaendig: erst alle weg, dann die
 * neuen von 1 an.
 *
 * OHNE Satzliste werden vorhandene Saetze GELOESCHT. Das ist kein Versehen: Die
 * Nutzlast beschreibt die Zeile vollstaendig, und ein Aufruf aus dem einfachen
 * Modus sagt "diese Position hat ein Gewicht und keine Saetze". Liesse man die
 * alten stehen, zeigte die Position ein Leitgewicht aus einer Satzfolge, die
 * niemand mehr sieht. Praktisch tritt der Fall nicht ein -- api/auth.php sperrt
 * den Moduswechsel bei laufender Einheit --, aber die Regel gehoert
 * festgeschrieben und nicht dem Zufall ueberlassen.
 *
 * Laeuft ausschliesslich innerhalb der Transaktion aus aktion_abhaken(): Der
 * Zwischenzustand "Saetze geloescht, neue noch nicht da" darf nach aussen nie
 * sichtbar werden.
 */
function saetze_schreiben(int $sessionId, int $peId, ?array $saetze): void {
    $stmt = db()->prepare(
        'SELECT id FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$sessionId, $peId]);
    $logId = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM workout_sets WHERE workout_log_id = ?')
        ->execute([$logId]);

    if ($saetze === null) {
        return;
    }

    $einfuegen = db()->prepare(
        'INSERT INTO workout_sets (workout_log_id, satz_nr, reps, weight)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($saetze as $s) {
        $einfuegen->execute([$logId, $s['satz_nr'], $s['reps'], $s['weight']]);
    }
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

    // Die Saetze dieser Position gehen mit: workout_sets.workout_log_id traegt
    // ON DELETE CASCADE. Deshalb steht hier kein zweites DELETE -- eines, das
    // man beim naechsten Loeschpfad wieder vergessen koennte.
    db()->prepare(
        'DELETE FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    )->execute([$offen['id'], $peId]);

    json_ok(fortschritt((int)$offen['id'], (int)$position['plan_id']) + [
        'erledigt' => false,
    ]);
}

/**
 * Der Zaehler "x/n" der Trainingsleiste: n sind die Planpositionen, x die als
 * ERLEDIGT markierten Positionen dieser Einheit (§7.3).
 *
 * `done = 1` und nicht blosse Existenz der Zeile: Im Expertenmodus entsteht die
 * Zeile schon mit dem ersten Satz, und dann ist man mitten in der Uebung und
 * nicht fertig damit. Im einfachen Modus fallen beide zusammen -- dort schreibt
 * jeder Aufruf `done = 1`.
 *
 * Die zweite Zahl der Leiste ("n uebersprungen") steht hier bewusst NICHT: Sie
 * haengt nicht am Datenbestand, sondern an der REIHENFOLGE der Positionen --
 * offen und vor der aktiven. Das ist dieselbe Rechnung, die den orangen Balken
 * setzt (positions_zustaende() bzw. aktiveMarkieren()), und sie gehoert genau
 * einmal dorthin.
 *
 * `alle_erledigt` haengt allein an `done` -- angefangene Uebungen sind nicht
 * fertig, und die Rueckfrage "Training beenden?" darf nicht kommen, solange
 * irgendwo noch ein Haekchen fehlt.
 */
function fortschritt(int $sessionId, int $planId): array {
    $stmt = db()->prepare('SELECT COUNT(*) FROM plan_exercises WHERE plan_id = ?');
    $stmt->execute([$planId]);
    $n = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM workout_log WHERE session_id = ? AND done = 1'
    );
    $stmt->execute([$sessionId]);
    $x = (int)$stmt->fetchColumn();

    return ['erledigt_anzahl' => $x, 'gesamt' => $n, 'alle_erledigt' => $n > 0 && $x >= $n];
}
