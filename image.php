<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/upload.php';

/**
 * Ausliefern der Uebungsbilder (§5).
 *
 * Warum ueberhaupt ein PHP-Endpunkt, wo die Dateien statisch ausgeliefert
 * werden koennten: So haengt der Zugriff an der Anmeldung, und der Pfad wird
 * an genau einer Stelle geprueft. Die Auslieferung laeuft ueber eine
 * Path-Jail -- der aufgeloeste Pfad MUSS unterhalb des Upload-Verzeichnisses
 * liegen, sonst wird abgewiesen.
 */

bootstrap_session();
require_login();

$name = basename(to_str($_GET['f'] ?? ''));

// Zufallsname aus lib/upload.php: 32 Hex-Zeichen, optional _thumb, immer .jpg.
// Alles andere ist keine von uns erzeugte Datei und wird gar nicht erst gesucht.
if (preg_match('/^[0-9a-f]{32}(_thumb)?\.jpg$/', $name) !== 1) {
    http_response_code(404);
    exit;
}

$verzeichnis = realpath(uploads_path());
$datei       = realpath($verzeichnis . '/' . $name);

// Die Jail: basename() allein genuegt nicht, wenn im Upload-Verzeichnis ein
// Symlink nach aussen liegt. realpath() loest den auf, der Praefixvergleich
// faengt ihn.
if ($verzeichnis === false || $datei === false
    || !str_starts_with($datei, $verzeichnis . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$groesse = filesize($datei);
$zeit    = filemtime($datei);
$etag    = '"' . md5($name . '|' . $zeit . '|' . $groesse) . '"';

// Die Bilder sind unveraenderlich -- der Dateiname ist zufaellig und wird bei
// einem neuen Bild neu vergeben. Deshalb darf der Browser sie lange behalten.
// private, weil die Auslieferung an der Anmeldung haengt.
header('Content-Type: image/jpeg');
header('Content-Length: ' . $groesse);
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

readfile($datei);
