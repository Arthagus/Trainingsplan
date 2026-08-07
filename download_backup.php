<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/backup.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Ausliefern einer Sicherung (§6.5).
 *
 * Eigener Endpunkt statt statischer Auslieferung, weil im Archiv der komplette
 * Datenbestand steckt -- samt Passwort-Hashes. Der Zugriff haengt deshalb an
 * der Anmeldung UND am Adminrecht.
 *
 * Der Dateiname kommt aus der URL und gilt als feindlich: backup_pfad() zieht
 * ihn durch basename(), prueft ihn gegen eine Whitelist der Endungen und
 * vergleicht den aufgeloesten Pfad mit dem Sicherungsverzeichnis (Path-Jail,
 * wie in image.php).
 */

try {
    $pfad = backup_pfad(to_str($_GET['f'] ?? ''));
} catch (RuntimeException $e) {
    http_response_code(404);
    exit('Diese Sicherung gibt es nicht.');
}

$name   = basename($pfad);
$endung = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));

// Kein Ausliefern aus dem Zwischenspeicher: Eine Sicherung wird einmal geholt.
header('Content-Type: ' . ($endung === 'zip' ? 'application/zip' : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (string)filesize($pfad));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

// Ausgabepuffer leeren, sonst liegt bei grossen Archiven alles im Speicher.
while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($pfad);
