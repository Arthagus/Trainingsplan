<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/backup.php';

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
