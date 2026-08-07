<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();
require_admin_api();

/**
 * Muskelgruppen pflegen (§6.2).
 *
 * Das kontrollierte Vokabular geht dem Anlegen von Uebungen voraus -- deshalb
 * gibt es hier ein eigenes Verwaltungsbild und keine Anlage nebenbei aus der
 * Uebungsmaske heraus.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const NAME_MAX = 60;

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'create'  => aktion_anlegen($eingabe),
    'update'  => aktion_umbenennen($eingabe),
    'delete'  => aktion_loeschen($eingabe),
    'reorder' => aktion_sortieren($eingabe),
    default   => json_err('Unbekannte Aktion', 400),
};

/**
 * Prueft die Namensfelder und liefert sie normalisiert zurueck.
 *
 * @return array{0:string,1:?string}
 */
function namen_pruefen(array $eingabe): array {
    $de = to_str($eingabe['name_de'] ?? '');
    $en = to_str($eingabe['name_en'] ?? '');

    if ($de === '') {
        json_err('Bitte einen Namen eingeben.', 422, ['name_de' => 'Pflichtfeld.']);
    }
    if (str_len_utf8($de) > NAME_MAX || str_len_utf8($en) > NAME_MAX) {
        json_err('Der Name ist zu lang.', 422, [
            'name_de' => 'Höchstens ' . NAME_MAX . ' Zeichen.',
        ]);
    }

    return [$de, $en === '' ? null : $en];
}

/**
 * Prueft die gewuenschte Hauptgruppe und liefert sie als ID oder null.
 *
 * Es gibt genau zwei Ebenen. Eine Untergruppe darf deshalb nicht selbst
 * Hauptgruppe von etwas sein, und nichts darf auf sich selbst zeigen --
 * beides ergaebe eine Verschachtelung, die weder Anzeige noch Tauschsuche
 * abbilden.
 *
 * @param int|null $eigeneId Beim Bearbeiten die ID der Gruppe selbst
 */
function hauptgruppe_pruefen(array $eingabe, ?int $eigeneId = null): ?int {
    $parent = to_int_or_null($eingabe['parent_id'] ?? null);
    if ($parent === null) {
        return null;
    }

    if ($eigeneId !== null && $parent === $eigeneId) {
        json_err('Eine Gruppe kann nicht ihre eigene Hauptgruppe sein.', 422, [
            'parent_id' => 'Nicht zulässig.',
        ]);
    }

    $stmt = db()->prepare('SELECT parent_id FROM muscle_groups WHERE id = ?');
    $stmt->execute([$parent]);
    $zeile = $stmt->fetch();

    if ($zeile === false) {
        json_err('Diese Hauptgruppe gibt es nicht.', 404);
    }
    if ($zeile['parent_id'] !== null) {
        json_err(
            'Als Hauptgruppe kommen nur Gruppen der obersten Ebene infrage — '
            . 'es gibt genau zwei Ebenen.',
            422,
            ['parent_id' => 'Ist selbst eine Untergruppe.']
        );
    }

    // Beim Bearbeiten: Wer selbst Untergruppen hat, kann keine bekommen.
    if ($eigeneId !== null) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM muscle_groups WHERE parent_id = ?');
        $stmt->execute([$eigeneId]);
        if ((int)$stmt->fetchColumn() > 0) {
            json_err(
                'Diese Gruppe hat selbst Untergruppen und kann deshalb nicht '
                . 'unter eine andere gehängt werden.',
                409
            );
        }
    }

    return $parent;
}

function aktion_anlegen(array $eingabe): never {
    [$de, $en] = namen_pruefen($eingabe);
    $parent = hauptgruppe_pruefen($eingabe);

    // Ans Ende sortieren, statt die vorhandene Reihenfolge zu stoeren.
    $max = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM muscle_groups')->fetchColumn();

    try {
        $stmt = db()->prepare(
            'INSERT INTO muscle_groups (name_de, name_en, parent_id, sort_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$de, $en, $parent, $max + 10]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            json_err('Diese Muskelgruppe gibt es schon.', 409, [
                'name_de' => 'Name bereits vergeben.',
            ]);
        }
        throw $e;
    }

    json_ok(['id' => (int)db()->lastInsertId()]);
}

function aktion_umbenennen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Muskelgruppe angegeben.', 422);
    }

    [$de, $en] = namen_pruefen($eingabe);
    $parent = hauptgruppe_pruefen($eingabe, $id);

    try {
        $stmt = db()->prepare(
            'UPDATE muscle_groups SET name_de = ?, name_en = ?, parent_id = ? WHERE id = ?'
        );
        $stmt->execute([$de, $en, $parent, $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            json_err('Diese Muskelgruppe gibt es schon.', 409, [
                'name_de' => 'Name bereits vergeben.',
            ]);
        }
        throw $e;
    }

    json_ok(['id' => $id]);
}

/**
 * Loeschen -- nur, wenn keine Zuordnung darauf zeigt (§4.1).
 *
 * Archivierte Uebungen zaehlen ausdruecklich mit: ihre Historie bleibt
 * referenzierbar, also darf ihnen die Muskelgruppe nicht unter den Fuessen
 * weggezogen werden.
 */
function aktion_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Keine Muskelgruppe angegeben.', 422);
    }

    $stmt = db()->prepare(
        'SELECT e.name_de, e.archived
           FROM exercise_muscle_groups emg
           JOIN exercises e ON e.id = emg.exercise_id
          WHERE emg.muscle_group_id = ?
          ORDER BY e.archived, e.name_de'
    );
    $stmt->execute([$id]);
    $betroffen = $stmt->fetchAll();

    // Eine Hauptgruppe mit Untergruppen darf nicht verschwinden -- die
    // Untergruppen haetten sonst keine Wurzel mehr, und die Tauschsuche
    // faende fuer sie nichts.
    $stmt = db()->prepare('SELECT name_de FROM muscle_groups WHERE parent_id = ? ORDER BY sort_order');
    $stmt->execute([$id]);
    $kinder = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($kinder !== []) {
        json_err(
            'Diese Hauptgruppe hat noch Untergruppen: ' . implode(', ', $kinder)
            . '. Erst dort eine andere Hauptgruppe zuweisen oder sie löschen.',
            409
        );
    }

    if ($betroffen !== []) {
        $namen = array_map(
            fn(array $e): string => $e['name_de'] . ((int)$e['archived'] === 1 ? ' (archiviert)' : ''),
            array_slice($betroffen, 0, 10)
        );
        $text = implode(', ', $namen);
        if (count($betroffen) > 10) {
            $text .= ' und ' . (count($betroffen) - 10) . ' weitere';
        }

        json_err(
            'Diese Muskelgruppe ist noch zugeordnet: ' . $text
            . '. Erst dort entfernen, dann löschen.',
            409
        );
    }

    db()->prepare('DELETE FROM muscle_groups WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => $id]);
}

/**
 * Neue Reihenfolge uebernehmen. Erwartet die vollstaendige Liste der IDs in
 * der gewuenschten Reihenfolge -- das ist robuster als einzelne Tauschbefehle,
 * die bei zwei offenen Browserfenstern durcheinandergeraten.
 */
function aktion_sortieren(array $eingabe): never {
    $ids = $eingabe['ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        json_err('Keine Reihenfolge übermittelt.', 422);
    }

    $sauber = [];
    foreach ($ids as $roh) {
        $id = to_int_or_null($roh);
        if ($id === null) {
            json_err('Ungültige Reihenfolge.', 422);
        }
        $sauber[] = $id;
    }

    db_transaction(function (PDO $pdo) use ($sauber): void {
        $stmt = $pdo->prepare('UPDATE muscle_groups SET sort_order = ? WHERE id = ?');
        foreach ($sauber as $i => $id) {
            $stmt->execute([($i + 1) * 10, $id]);
        }
    });

    json_ok(['count' => count($sauber)]);
}
