<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';

/**
 * Betriebspruefung fuer den Docker-HEALTHCHECK.
 *
 * Warum nicht einfach die Startseite abfragen: Die leitet ohne einen einzigen
 * Datenbankzugriff auf login.php um. Ein nicht beschreibbares Volume faellt
 * dabei gar nicht auf -- der Container gilt als "healthy" und scheitert erst
 * beim ersten Anmeldeversuch, dann aber als blanker 500er. Diese Seite fasst
 * die Datenbank deshalb wirklich an.
 *
 * Nur ueber das Loopback erreichbar. Der Healthcheck laeuft IM Container, also
 * kommt er von 127.0.0.1; von aussen ueber den Proxy steht dort die Adresse
 * des Clients (mod_remoteip), und die Seite verhaelt sich, als gaebe es sie
 * nicht. So verraet sie niemandem den Betriebszustand.
 */

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

try {
    // Ein echter Lesezugriff -- legt beim allerersten Aufruf auch Schema,
    // Muskelgruppen und Erst-Admin an.
    $anzahl = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(503);
    // Die Ursache steht im Containerlog, nicht in der Antwort.
    error_log('Healthcheck fehlgeschlagen: ' . $e->getMessage());
    echo "nicht bereit\n";
    exit;
}

echo "ok\n";
echo 'benutzer=', $anzahl, "\n";
