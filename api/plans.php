<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/training.php';
require_once __DIR__ . '/../lib/geraete.php';

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
    'exercise_picker'    => aktion_uebungs_auswahl($eingabe),
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

/**
 * Die Uebungsauswahl beim Hinzufuegen zu einem Plan (§6.4).
 *
 * Ersetzt das frueher hier gerenderte Pulldown mit ALLEN aktiven Uebungen. Bei
 * 17 Uebungen ging das; sobald der Bestand dreistellig wird, ist eine
 * ungefilterte Liste am Handy unbedienbar. Gefiltert wird nach Muskelgruppe
 * und Trainingsgeraet -- einzeln oder kombiniert.
 *
 * Geliefert wird genau die Form, die vorschlagMarkup() in assets/app.js
 * erwartet: Ein Treffer hier ist inhaltlich dasselbe wie ein Tauschvorschlag,
 * nur mit einem anderen Knopf darunter.
 *
 * Dazu kommen die FACETTEN: Welche Muskelgruppen und welche Geraete ueberhaupt
 * noch zu etwas fuehren. Die beiden Auswahlfelder schraenken sich damit
 * gegenseitig ein -- wer "Kurzhantel" waehlt, sieht nur noch Muskelgruppen mit
 * Kurzhantel-Uebungen, und umgekehrt. Ein Filterwert, der zuverlaessig eine
 * leere Liste erzeugt, ist schlechter als keiner.
 *
 * Massgeblich ist dabei, dass jede Facette OHNE ihren EIGENEN Filter gerechnet
 * wird: Sonst bliebe nach der Wahl von "Kurzhantel" nur noch "Kurzhantel" im
 * Geraetefeld stehen, und man kaeme nicht mehr davon weg, ohne erst die
 * Muskelgruppe zurueckzusetzen.
 */
function aktion_uebungs_auswahl(array $eingabe): never {
    $planId = to_int_or_null($eingabe['plan_id'] ?? null);
    if ($planId === null) {
        json_err('Kein Plan angegeben.', 422);
    }
    $plan = plan_laden($planId);

    $gruppe = to_int_or_null($eingabe['group_id'] ?? null);
    $geraet = to_str($eingabe['equipment'] ?? '');
    if ($geraet !== '' && $geraet !== GERAET_LEER && !geraet_gueltig($geraet)) {
        $geraet = '';
    }

    // Die beiden Bedingungen einzeln vorhalten: Die Trefferliste braucht beide,
    // jede Facette dagegen nur die jeweils ANDERE.
    $gruppeSql   = null;
    $gruppeWerte = [];
    if ($gruppe !== null) {
        // Wortgleich zum Filter in admin_exercises.php: Eine Hauptgruppe steht
        // fuer sich UND fuer alle ihre Untergruppen. Die Hierarchie bleibt damit
        // in SQL und wird nicht ein zweites Mal im JS nachgebaut.
        $gruppeSql = 'EXISTS (SELECT 1 FROM exercise_muscle_groups emg
                                JOIN muscle_groups fmg ON fmg.id = emg.muscle_group_id
                               WHERE emg.exercise_id = e.id
                                 AND (fmg.id = ? OR fmg.parent_id = ?))';
        $gruppeWerte = [$gruppe, $gruppe];
    }

    $geraetSql   = null;
    $geraetWerte = [];
    if ($geraet === GERAET_LEER) {
        $geraetSql = "(e.equipment IS NULL OR e.equipment = '')";
    } elseif ($geraet !== '') {
        $geraetSql   = 'e.equipment = ?';
        $geraetWerte = [$geraet];
    }

    // Die Reihenfolge der Platzhalter ist die Reihenfolge im SQL-Text: erst der
    // Wert aus der SELECT-Liste, dann WHERE, dann ORDER BY.
    $werte = [$planId, ...$gruppeWerte, ...$geraetWerte];
    $wo    = ['e.archived = 0'];
    if ($gruppeSql !== null) { $wo[] = $gruppeSql; }
    if ($geraetSql !== null) { $wo[] = $geraetSql; }

    // Wie in der Uebungsverwaltung: Bei gesetztem Gruppenfilter zuerst die
    // Uebungen, deren PRIMAERE Gruppe passt. Sonst stuende bei "Brust" eine
    // Trizeps-Uebung obenan, die Brust nur mittrainiert.
    $sortSql = 'e.name_de';
    if ($gruppe !== null) {
        $sortSql = 'CASE WHEN EXISTS (SELECT 1 FROM exercise_muscle_groups emg
                                        JOIN muscle_groups pmg ON pmg.id = emg.muscle_group_id
                                       WHERE emg.exercise_id = e.id AND emg.is_primary = 1
                                         AND (pmg.id = ? OR pmg.parent_id = ?))
                         THEN 0 ELSE 1 END,
                    e.name_de';
        $werte[] = $gruppe;
        $werte[] = $gruppe;
    }

    // Nur aktive Uebungen lassen sich in einen Plan aufnehmen (§6.3) -- was
    // bereits drinsteht, bleibt aber sichtbar und wird nur gekennzeichnet.
    // Herausgefiltert waere es verwirrend: Man sucht eine Uebung, findet sie
    // nicht und weiss nicht, ob sie fehlt oder schon dabei ist.
    $stmt = db()->prepare(
        'SELECT e.id, e.name_de, e.name_en, e.focus, e.equipment, e.image_path,
                EXISTS (SELECT 1 FROM plan_exercises pe
                         WHERE pe.plan_id = ? AND pe.exercise_id = e.id) AS im_plan
           FROM exercises e
          WHERE ' . implode(' AND ', $wo) . '
          ORDER BY ' . $sortSql
    );
    $stmt->execute($werte);
    $treffer = $stmt->fetchAll();

    // Die Muskelgruppen aller Treffer in EINER Abfrage -- anders als bei den
    // Tauschvorschlaegen, wo je Vorschlag nachgeschlagen wird. Dort sind es eine
    // Handvoll, hier kann es der ganze Bestand sein.
    if ($treffer !== []) {
        $ids = array_map(static fn(array $t): int => (int)$t['id'], $treffer);
        $platzhalter = implode(',', array_fill(0, count($ids), '?'));

        $gruppen = [];
        $stmt = db()->prepare(
            "SELECT emg.exercise_id, mg.name_de, emg.is_primary
               FROM exercise_muscle_groups emg
               JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
              WHERE emg.exercise_id IN ($platzhalter)
              ORDER BY emg.is_primary DESC, mg.sort_order, mg.name_de"
        );
        $stmt->execute($ids);
        foreach ($stmt as $g) {
            $gruppen[(int)$g['exercise_id']][] = [
                'name_de'    => $g['name_de'],
                'is_primary' => (int)$g['is_primary'],
            ];
        }

        foreach ($treffer as &$t) {
            $t['id']      = (int)$t['id'];
            $t['im_plan'] = (int)$t['im_plan'];
            $t['muskelgruppen'] = $gruppen[$t['id']] ?? [];
        }
        unset($t);
    }

    json_ok([
        'exercises' => $treffer,
        'facetten'  => auswahl_facetten($gruppeSql, $gruppeWerte, $geraetSql, $geraetWerte),
        // Ein zweiter Tab kann den Dialog oeffnen, nachdem der Benutzer im
        // ersten ein Training gestartet hat. add_exercise antwortet dann mit
        // 409; die Auswahl sagt es vorher.
        'gesperrt'  => hat_offene_einheit((int)$plan['user_id']),
    ]);
}

/**
 * Was in den beiden Auswahlfeldern der Uebungsauswahl noch zu etwas fuehrt.
 *
 * Jede Facette wird OHNE ihren eigenen Filter gerechnet, mit dem des anderen
 * Feldes: Die Geraeteliste gilt fuer die gewaehlte Muskelgruppe, die
 * Gruppenliste fuer das gewaehlte Geraet. Andersherum -- beide Filter auf beide
 * Facetten -- bliebe nach jeder Wahl nur der gewaehlte Wert selbst uebrig, und
 * man kaeme aus der Einschraenkung nicht mehr heraus.
 *
 * @return array{geraete:string[],gruppen:int[]}
 */
function auswahl_facetten(?string $gruppeSql, array $gruppeWerte,
                          ?string $geraetSql, array $geraetWerte): array {
    // --- Welche Geraete? Gilt der Muskelgruppen-Filter. ---
    $wo = ['e.archived = 0', "e.equipment IS NOT NULL", "e.equipment != ''"];
    if ($gruppeSql !== null) { $wo[] = $gruppeSql; }

    $stmt = db()->prepare(
        'SELECT DISTINCT e.equipment FROM exercises e WHERE ' . implode(' AND ', $wo)
    );
    $stmt->execute($gruppeWerte);
    $vorhanden = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // In der Reihenfolge der Codeliste ausgeben, nicht in der von SQLite --
    // das Auswahlfeld soll immer dieselbe Ordnung haben.
    $geraete = array_values(array_filter(
        array_keys(GERAETE),
        static fn(string $code): bool => in_array($code, $vorhanden, true)
    ));

    // --- Welche Muskelgruppen? Gilt der Geraete-Filter. ---
    $wo = ['e.archived = 0'];
    if ($geraetSql !== null) { $wo[] = $geraetSql; }

    $stmt = db()->prepare(
        'SELECT DISTINCT mg.id, mg.parent_id
           FROM exercises e
           JOIN exercise_muscle_groups emg ON emg.exercise_id = e.id
           JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
          WHERE ' . implode(' AND ', $wo)
    );
    $stmt->execute($geraetWerte);

    // Die Elterngruppe gehoert dazu: Wer auf "Arme" filtert, bekommt die
    // Bizeps-Uebungen mit (die Hauptgruppe schliesst ihre Untergruppen ein).
    // Ohne diesen Schritt verschwaende "Arme" aus der Liste, obwohl die Wahl
    // sehr wohl Treffer haette.
    $gruppen = [];
    foreach ($stmt as $g) {
        $gruppen[(int)$g['id']] = true;
        if ($g['parent_id'] !== null) {
            $gruppen[(int)$g['parent_id']] = true;
        }
    }

    return ['geraete' => $geraete, 'gruppen' => array_keys($gruppen)];
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
