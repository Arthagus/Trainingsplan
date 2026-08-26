<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/backup.php';
require_once __DIR__ . '/../lib/upload.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();
require_admin_api();

/**
 * Wartung und Sicherung (§6.5).
 *
 * Die Endpunkte nehmen Formdaten entgegen, nicht JSON: Das Hochladen einer
 * Sicherung geht nur als multipart.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

$eingabe = read_input();

match (to_str($eingabe['action'] ?? '')) {
    'backup'        => aktion_backup($eingabe),
    'restore'       => aktion_restore($eingabe),
    'upload'        => aktion_upload(),
    'delete_backup' => aktion_backup_loeschen($eingabe),
    'vacuum'        => aktion_vacuum(),
    'integrity'     => aktion_integrity(),
    'optimize'      => aktion_optimize(),
    'checkpoint'    => aktion_checkpoint(),
    'images_orphans' => aktion_bilder_suchen(),
    'images_cleanup' => aktion_bilder_aufraeumen(),
    'images_recut_check' => aktion_bilder_nachschnitt_pruefen(),
    'images_recut'       => aktion_bilder_nachschneiden(),
    default         => json_err('Unbekannte Aktion', 400),
};

function aktion_backup(array $eingabe): never {
    $mitBildern = !empty($eingabe['with_images']);

    try {
        $name = backup_erstellen($mitBildern);
    } catch (RuntimeException $e) {
        json_err($e->getMessage(), 500);
    }

    $weg = backups_aufraeumen();

    json_ok([
        'name'    => $name,
        'meldung' => 'Sicherung „' . $name . '" erstellt.'
            . ($weg > 0 ? ' ' . $weg . ' alte entfernt.' : ''),
    ]);
}

/**
 * Eine Sicherung einspielen. **Ueberschreibt den kompletten Datenbestand.**
 * Die Pruefungen stecken in backup_wiederherstellen().
 */
function aktion_restore(array $eingabe): never {
    $name = to_str($eingabe['backup'] ?? '');
    if ($name === '') {
        json_err('Keine Sicherung angegeben.', 422);
    }

    try {
        $meldung = backup_wiederherstellen($name);
    } catch (RuntimeException $e) {
        json_err($e->getMessage(), 409);
    }

    json_ok(['meldung' => $meldung]);
}

function aktion_upload(): never {
    if (!isset($_FILES['datei'])) {
        json_err('Keine Datei übermittelt.', 422);
    }

    try {
        $name = backup_hochladen($_FILES['datei']);
    } catch (RuntimeException $e) {
        json_err($e->getMessage(), 422);
    }

    json_ok([
        'name'    => $name,
        'meldung' => 'Sicherung „' . $name . '" hochgeladen und geprüft. '
            . 'Eingespielt wurde sie noch nicht.',
    ]);
}

function aktion_backup_loeschen(array $eingabe): never {
    $name = to_str($eingabe['backup'] ?? '');
    if ($name === '') {
        json_err('Keine Sicherung angegeben.', 422);
    }

    try {
        backup_loeschen($name);
    } catch (RuntimeException $e) {
        json_err($e->getMessage(), 404);
    }

    json_ok(['meldung' => 'Sicherung „' . $name . '" gelöscht.']);
}

/**
 * VACUUM kompaktiert die Datei und gibt freigewordene Seiten zurueck.
 */
function aktion_vacuum(): never {
    $vorher = @filesize(db_path()) ?: 0;
    db()->exec('VACUUM');
    clearstatcache(true, db_path());
    $nachher = @filesize(db_path()) ?: 0;

    $gespart = $vorher - $nachher;
    json_ok([
        'meldung' => 'Datenbank kompaktiert. '
            . ($gespart > 0
                ? bytes_lesbar($gespart) . ' freigegeben.'
                : 'Es war nichts freizugeben.'),
    ]);
}

function aktion_integrity(): never {
    $ergebnis = (string)db()->query('PRAGMA integrity_check')->fetchColumn();

    if ($ergebnis !== 'ok') {
        json_err('Die Datenbank meldet einen Schaden: ' . $ergebnis, 500);
    }

    // Fremdschluessel gesondert -- integrity_check prueft sie nicht mit.
    $verwaist = db()->query('PRAGMA foreign_key_check')->fetchAll();
    if ($verwaist !== []) {
        json_err(
            'Struktur in Ordnung, aber ' . count($verwaist)
            . ' Zeile(n) verweisen ins Leere (Fremdschlüssel).',
            500
        );
    }

    json_ok(['meldung' => 'Datenbank in Ordnung — Struktur und Fremdschlüssel geprüft.']);
}

function aktion_optimize(): never {
    db()->exec('PRAGMA optimize');
    json_ok(['meldung' => 'Statistiken für den Abfrageplaner aufgefrischt.']);
}

/**
 * Schreibt das Write-Ahead-Log in die Hauptdatei zurueck.
 */
function aktion_checkpoint(): never {
    $wal = db_path() . '-wal';
    $vorher = is_file($wal) ? (int)filesize($wal) : 0;

    db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    clearstatcache(true, $wal);

    json_ok([
        'meldung' => 'WAL zurückgeschrieben.'
            . ($vorher > 0 ? ' Vorher ' . bytes_lesbar($vorher) . '.' : ''),
    ]);
}

/**
 * Sucht Bilddateien ohne Uebung -- und loescht dabei NICHTS (§6.5).
 *
 * Getrennt vom Aufraeumen, weil beides verschiedene Fragen sind: Erst will
 * man sehen, was da liegt, und dann entscheiden. Ein Knopf, der beides in
 * einem tut, laesst sich nicht mehr zurueckdrehen -- die Dateien sind weg,
 * und die einzige Kopie steckt in einer Sicherung MIT Bildern.
 */
function aktion_bilder_suchen(): never {
    $treffer = verwaiste_bilder();

    if ($treffer === []) {
        json_ok([
            'dateien' => [],
            'meldung' => 'Keine verwaisten Bilder — jede Datei in uploads/ gehört zu einer Übung.',
        ]);
    }

    $bytes = array_sum(array_column($treffer, 'groesse'));

    json_ok([
        'dateien' => $treffer,
        'bytes'   => $bytes,
        'meldung' => count($treffer) . ' verwaiste Datei(en) gefunden, zusammen '
            . bytes_lesbar($bytes) . '. Es wurde noch nichts gelöscht.',
    ]);
}

/**
 * Loescht die verwaisten Bilder. Die Liste ermittelt lib/upload.php selbst --
 * es geht KEIN Dateiname ueber die Leitung, siehe verwaiste_bilder_loeschen().
 */
function aktion_bilder_aufraeumen(): never {
    $ergebnis = verwaiste_bilder_loeschen();

    if ($ergebnis['anzahl'] === 0) {
        json_ok(['meldung' => 'Nichts zu löschen — es gibt keine verwaisten Bilder.']);
    }

    json_ok([
        'meldung' => $ergebnis['anzahl'] . ' verwaiste Datei(en) gelöscht, '
            . bytes_lesbar($ergebnis['bytes']) . ' frei.',
    ]);
}

/**
 * Was ein Nachschnitt der Bestandsbilder aendern wuerde -- ohne zu aendern.
 *
 * Getrennt vom Lauf aus demselben Grund wie beim Aufraeumen der Waisen: Erst
 * sehen, dann entscheiden. Hier kommt dazu, dass der Lauf nicht umkehrbar ist
 * -- das alte Bild ist danach weg, und die einzige Kopie steckt in einer
 * Sicherung MIT Bildern.
 */
function aktion_bilder_nachschnitt_pruefen(): never {
    $stand = bestandsbilder_pruefen();

    if ($stand['betroffen'] === 0) {
        json_ok([
            'liste'   => [],
            'meldung' => $stand['gesamt'] . ' Bild(er) geprüft — keines hat einen Rand, '
                . 'der sich abschneiden ließe.',
        ]);
    }

    json_ok([
        'liste'   => $stand['liste'],
        'meldung' => $stand['betroffen'] . ' von ' . $stand['gesamt']
            . ' Bild(ern) haben einen Rand, der abgeschnitten werden kann.'
            . ($stand['fehlend'] > 0
                ? ' ' . $stand['fehlend'] . ' Datei(en) fehlen im Verzeichnis.'
                : '')
            . ' Es wurde noch nichts verändert.',
    ]);
}

/**
 * Schneidet die Bestandsbilder nach. Vergibt dabei neue Dateinamen -- warum,
 * steht bei bestandsbilder_nachschneiden() in lib/upload.php.
 */
function aktion_bilder_nachschneiden(): never {
    $ergebnis = bestandsbilder_nachschneiden();

    if ($ergebnis['geaendert'] === 0 && $ergebnis['fehlgeschlagen'] === 0) {
        json_ok(['meldung' => 'Nichts zu tun — kein Bild hat einen abschneidbaren Rand.']);
    }

    $teile = [$ergebnis['geaendert'] . ' Bild(er) nachgeschnitten'];
    // Der Platzbedarf kann in beide Richtungen gehen -- siehe die Begruendung
    // bei bestandsbilder_nachschneiden(). Gemeldet wird, was wirklich passiert
    // ist, und nicht nur die angenehme Haelfte davon.
    if ($ergebnis['bytes'] > 0) {
        $teile[] = bytes_lesbar($ergebnis['bytes']) . ' frei geworden';
    } elseif ($ergebnis['bytes'] < 0) {
        $teile[] = bytes_lesbar(-$ergebnis['bytes']) . ' mehr belegt';
    }
    if ($ergebnis['uebersprungen'] > 0) {
        $teile[] = $ergebnis['uebersprungen'] . ' ohne Rand übersprungen';
    }
    if ($ergebnis['fehlgeschlagen'] > 0) {
        $teile[] = $ergebnis['fehlgeschlagen'] . ' fehlgeschlagen (unverändert geblieben)';
    }

    json_ok(['meldung' => implode(', ', $teile) . '.']);
}
