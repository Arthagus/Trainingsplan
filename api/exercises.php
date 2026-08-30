<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/upload.php';
require_once __DIR__ . '/../lib/geraete.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();
require_admin_api();

/**
 * Uebungsverwaltung (§6.3).
 *
 * Die Endpunkte nehmen Formdaten entgegen, nicht JSON: Anlegen und Bearbeiten
 * koennen ein Bild mitbringen, und ein Upload geht nur als multipart.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const EX_NAME_MAX = 120;
const EX_BESCHREIBUNG_MAX = 2000;
const EX_FOKUS_MAX = 60;

$eingabe = read_input();

match (to_str($eingabe['action'] ?? '')) {
    'create'    => aktion_anlegen($eingabe),
    'update'    => aktion_bearbeiten($eingabe),
    'archive'   => aktion_archivieren($eingabe),
    'unarchive' => aktion_reaktivieren($eingabe),
    'delete'    => aktion_loeschen($eingabe),
    default     => json_err('Unbekannte Aktion', 400),
};

/**
 * Prueft Name, Beschreibung, Trainingsgeraet, Erfassungsart und
 * Muskelgruppen-Auswahl.
 *
 * @return array{name_de:string,name_en:?string,description:?string,focus:?string,
 *               equipment:string,erfassung:string,image_crop:string,
 *               groups:int[],primary:int}
 */
function eingabe_pruefen(array $eingabe): array {
    $fehler = [];

    $de = to_str($eingabe['name_de'] ?? '');
    $en = to_str($eingabe['name_en'] ?? '');
    $beschreibung = to_str($eingabe['description'] ?? '');
    $fokus = to_str($eingabe['focus'] ?? '');

    if ($de === '') {
        $fehler['name_de'] = 'Pflichtfeld.';
    } elseif (str_len_utf8($de) > EX_NAME_MAX) {
        $fehler['name_de'] = 'Höchstens ' . EX_NAME_MAX . ' Zeichen.';
    }
    if (str_len_utf8($en) > EX_NAME_MAX) {
        $fehler['name_en'] = 'Höchstens ' . EX_NAME_MAX . ' Zeichen.';
    }
    if (str_len_utf8($beschreibung) > EX_BESCHREIBUNG_MAX) {
        $fehler['description'] = 'Höchstens ' . EX_BESCHREIBUNG_MAX . ' Zeichen.';
    }
    if (str_len_utf8($fokus) > EX_FOKUS_MAX) {
        $fehler['focus'] = 'Höchstens ' . EX_FOKUS_MAX . ' Zeichen.';
    }

    // Das Trainingsgeraet ist Pflicht -- auch beim Bearbeiten, und das ist
    // Absicht: Uebungen aus der Zeit vor diesem Feld tragen keinen Wert, und die
    // Pflicht am Bearbeiten-Formular ist der Weg, auf dem sie nachgepflegt
    // werden. Geprueft wird gegen die Codeliste in lib/geraete.php, nicht gegen
    // ein CHECK-Constraint (Begruendung dort).
    $geraet = to_str($eingabe['equipment'] ?? '');
    if ($geraet === '') {
        $fehler['equipment'] = 'Bitte ein Trainingsgerät wählen.';
    } elseif (!geraet_gueltig($geraet)) {
        $fehler['equipment'] = 'Unbekanntes Trainingsgerät.';
    }

    // Die Erfassungsart ist Pflicht, und zwar SERVERSEITIG -- obwohl das
    // Formular ein Auswahlfeld ohne Leereintrag zeigt, in dem immer etwas
    // gewaehlt ist. Der Grund steht in Fallstrick 22: `update` ersetzt die
    // ganze Uebung. Ein Aufruf, der das Feld weglaesst, fiele sonst still auf
    // 'kraft' zurueck -- und aus einer Laufbanduebung waere lautlos wieder eine
    // Kraftuebung geworden, mit `ok:true`. Ein 422 ist dort das freundlichere
    // Verhalten. Deshalb hier KEIN Rueckfall auf ERFASSUNG_VORGABE wie beim
    // Bildzuschnitt darunter: Der Vorgabewert gilt fuer die MIGRATION, nicht
    // fuer eine unvollstaendige Nutzlast.
    $erfassung = to_str($eingabe['erfassung'] ?? '');
    if ($erfassung === '') {
        $fehler['erfassung'] = 'Bitte eine Erfassungsart wählen.';
    } elseif (!erfassung_gueltig($erfassung)) {
        $fehler['erfassung'] = 'Unbekannte Erfassungsart.';
    }

    // Der Bildzuschnitt ist -- anders als das Geraet -- KEIN Pflichtfeld: Er
    // hat einen sinnvollen Vorgabewert ('mitte'), und der ist genau das
    // Verhalten von vorher. Ein fehlendes Feld faellt deshalb still darauf
    // zurueck, ein gesetztes aber falsches wird abgewiesen -- sonst landete ein
    // Tippfehler als toter Wert in der Spalte.
    $zuschnitt = to_str($eingabe['image_crop'] ?? '');
    if ($zuschnitt === '') {
        $zuschnitt = ZUSCHNITT_VORGABE;
    } elseif (!zuschnitt_gueltig($zuschnitt)) {
        $fehler['image_crop'] = 'Unbekannte Ausrichtung.';
    }

    // Zwei getrennte Spalten im Formular: genau eine Primaergruppe (Radiobutton)
    // und beliebig viele Sekundaergruppen (Checkboxen). Die Primaergruppe ist
    // Pflicht -- ohne sie findet der Tausch (§7.5) die Uebung nie.
    $primaer = to_int_or_null($eingabe['primary_group_id'] ?? null);
    $sekundaer = to_id_list($eingabe['secondary_group_ids'] ?? []);

    // Bei AUSDAUER spielt die Muskelgruppe ueberhaupt keine Rolle -- sie ist
    // weder Pflicht noch wird sie gespeichert (§6.3, seit 1.4.1).
    //
    // "Welchen Muskel trainiert Laufen?" hat keine Antwort, die man ankreuzen
    // koennte, und die Tauschlogik braucht sie hier auch nicht: Bei Ausdauer
    // wird ueber die Trainingsart getauscht, nicht ueber die Gruppe
    // (tausch_vorschlaege() in lib/training.php).
    //
    // Mitgeschickte Gruppen werden VERWORFEN und nicht etwa gespeichert --
    // dieselbe Linie wie bei den Wertfeldern in api/log.php: Was zur
    // Trainingsart nicht passt, kommt gar nicht erst in die Datenbank. Wer eine
    // Kraftuebung auf Ausdauer umstellt, verliert damit ihre Zuordnung; das
    // Formular blendet den Block dann aus, es ist also sichtbar.
    if (ist_ausdauer($erfassung)) {
        $primaer   = null;
        $sekundaer = [];
    } elseif ($primaer === null) {
        $fehler['muscle_groups'] = 'Bitte eine Gruppe als primär markieren.';
    }

    // Die Primaergruppe kann nicht zugleich sekundaer sein. Im Formular ist das
    // Haekchen dann gesperrt; ein manipulierter Request koennte es trotzdem
    // mitschicken -- hier faellt es einfach heraus.
    $sekundaer = array_values(array_filter(
        $sekundaer,
        static fn(int $id): bool => $id !== $primaer
    ));

    $gruppen = $primaer === null ? $sekundaer : array_merge([$primaer], $sekundaer);

    if ($gruppen !== []) {
        $platzhalter = implode(',', array_fill(0, count($gruppen), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) FROM muscle_groups WHERE id IN ($platzhalter)");
        $stmt->execute($gruppen);
        if ((int)$stmt->fetchColumn() !== count($gruppen)) {
            $fehler['muscle_groups'] = 'Unbekannte Muskelgruppe.';
        }
    }

    if ($fehler !== []) {
        json_err('Bitte die Eingaben prüfen.', 422, $fehler);
    }

    return [
        'name_de'     => $de,
        'name_en'     => $en === '' ? null : $en,
        'description' => $beschreibung === '' ? null : $beschreibung,
        'focus'       => $fokus === '' ? null : $fokus,
        'equipment'   => $geraet,
        'erfassung'   => $erfassung,
        'image_crop'  => $zuschnitt,
        'groups'      => $gruppen,
        'primary'     => (int)$primaer,
    ];
}

/**
 * Schreibt die Muskelgruppen-Zuordnung neu.
 *
 * Erst alles loeschen, dann neu setzen -- und zwar in EINER Transaktion.
 * Der partielle Unique-Index idx_emg_one_primary schlaegt sonst zu, sobald
 * kurzzeitig zwei Zeilen mit is_primary = 1 existieren.
 */
function gruppen_schreiben(PDO $pdo, int $exerciseId, array $gruppen, int $primaer): void {
    $pdo->prepare('DELETE FROM exercise_muscle_groups WHERE exercise_id = ?')
        ->execute([$exerciseId]);

    $stmt = $pdo->prepare(
        'INSERT INTO exercise_muscle_groups (exercise_id, muscle_group_id, is_primary)
         VALUES (?, ?, ?)'
    );
    foreach ($gruppen as $gid) {
        $stmt->execute([$exerciseId, $gid, $gid === $primaer ? 1 : 0]);
    }
}

/**
 * Nimmt ein optional mitgeschicktes Bild entgegen.
 * Liefert den Dateinamen oder null, wenn keines dabei war.
 */
function bild_uebernehmen(): ?string {
    if (!isset($_FILES['image']) || (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    try {
        return save_exercise_image($_FILES['image']);
    } catch (RuntimeException $e) {
        json_err($e->getMessage(), 422, ['image' => $e->getMessage()]);
    }
}

function aktion_anlegen(array $eingabe): never {
    $daten = eingabe_pruefen($eingabe);
    $bild  = bild_uebernehmen();

    try {
        $id = db_transaction(function (PDO $pdo) use ($daten, $bild): int {
            $stmt = $pdo->prepare(
                'INSERT INTO exercises
                     (name_de, name_en, description, focus, equipment, erfassung,
                      image_path, image_crop, archived, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
            );
            $stmt->execute([
                $daten['name_de'], $daten['name_en'], $daten['description'],
                $daten['focus'], $daten['equipment'], $daten['erfassung'], $bild,
                $daten['image_crop'], now(),
            ]);
            $neu = (int)$pdo->lastInsertId();
            gruppen_schreiben($pdo, $neu, $daten['groups'], $daten['primary']);
            return $neu;
        });
    } catch (Throwable $e) {
        // Der Datensatz kam nicht zustande -- dann darf auch kein verwaistes
        // Bild im Volume zurueckbleiben.
        delete_exercise_image($bild);
        throw $e;
    }

    json_ok(['id' => $id]);
}

function aktion_bearbeiten(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Übung angegeben.', 422);
    }

    $stmt = db()->prepare('SELECT image_path FROM exercises WHERE id = ?');
    $stmt->execute([$id]);
    $vorhanden = $stmt->fetch();
    if ($vorhanden === false) {
        json_err('Diese Übung gibt es nicht (mehr).', 404);
    }

    $daten    = eingabe_pruefen($eingabe);
    $altesBild = $vorhanden['image_path'];
    $neuesBild = bild_uebernehmen();
    $entfernen = !empty($eingabe['image_remove']) && $neuesBild === null;

    $bildSpalte = $neuesBild ?? ($entfernen ? null : $altesBild);

    try {
        db_transaction(function (PDO $pdo) use ($id, $daten, $bildSpalte): void {
            $stmt = $pdo->prepare(
                'UPDATE exercises
                    SET name_de = ?, name_en = ?, description = ?, focus = ?,
                        equipment = ?, erfassung = ?, image_path = ?,
                        image_crop = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                $daten['name_de'], $daten['name_en'], $daten['description'],
                $daten['focus'], $daten['equipment'], $daten['erfassung'],
                $bildSpalte, $daten['image_crop'], $id,
            ]);
            gruppen_schreiben($pdo, $id, $daten['groups'], $daten['primary']);
        });
    } catch (Throwable $e) {
        delete_exercise_image($neuesBild);
        throw $e;
    }

    // Erst nach dem erfolgreichen Schreiben: solange die Transaktion scheitern
    // kann, waere die alte Datei noch in Gebrauch.
    if (($neuesBild !== null || $entfernen) && $altesBild !== null) {
        delete_exercise_image($altesBild);
    }

    json_ok(['id' => $id, 'image' => $bildSpalte]);
}

/**
 * Archivieren statt Loeschen (§6.3). Die Historie bleibt vollstaendig.
 */
function aktion_archivieren(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Übung angegeben.', 422);
    }

    $stmt = db()->prepare('UPDATE exercises SET archived = 1, archived_at = ? WHERE id = ? AND archived = 0');
    $stmt->execute([now(), $id]);

    if ($stmt->rowCount() === 0) {
        json_err('Diese Übung ist bereits archiviert oder existiert nicht.', 409);
    }

    json_ok(['id' => $id]);
}

function aktion_reaktivieren(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Übung angegeben.', 422);
    }

    $stmt = db()->prepare('UPDATE exercises SET archived = 0, archived_at = NULL WHERE id = ? AND archived = 1');
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        json_err('Diese Übung ist nicht archiviert.', 409);
    }

    json_ok(['id' => $id]);
}

/**
 * Endgueltiges Loeschen -- nur fuer nie benutzte Uebungen (§6.3).
 *
 * Die Bedingungen werden hier geprueft, obwohl die Fremdschluessel sie
 * ohnehin durchsetzen wuerden: Ein RESTRICT-Fehler waere fuer den Benutzer
 * eine unverstaendliche Datenbankmeldung.
 */
function aktion_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Übung angegeben.', 422);
    }

    $stmt = db()->prepare('SELECT image_path FROM exercises WHERE id = ?');
    $stmt->execute([$id]);
    $uebung = $stmt->fetch();
    if ($uebung === false) {
        json_err('Diese Übung gibt es nicht (mehr).', 404);
    }

    // Bewusst OHNE Filter auf session_id (§7.6): Auch eine Position, die nur
    // zu einer Einheit gehoert, ist ein Verweis auf diese Uebung -- der
    // Fremdschluessel steht auf RESTRICT, das Loeschen schluege sonst mit einer
    // nackten SQL-Meldung fehl statt mit einem verstaendlichen Satz.
    $stmt = db()->prepare('SELECT COUNT(*) FROM plan_exercises WHERE exercise_id = ?');
    $stmt->execute([$id]);
    $inPlaenen = (int)$stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM workout_log WHERE exercise_id = ?');
    $stmt->execute([$id]);
    $imLog = (int)$stmt->fetchColumn();

    if ($inPlaenen > 0 || $imLog > 0) {
        json_err(
            'Endgültiges Löschen ist nicht möglich: die Übung steht in '
            . $inPlaenen . ' Plan/Plänen und hat ' . $imLog . ' Protokolleinträge. '
            . 'Sie kann stattdessen archiviert werden.',
            409
        );
    }

    db()->prepare('DELETE FROM exercises WHERE id = ?')->execute([$id]);
    delete_exercise_image($uebung['image_path']);

    json_ok(['deleted' => $id]);
}
