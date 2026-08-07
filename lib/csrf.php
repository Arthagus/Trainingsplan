<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * CSRF-Schutz. Das Token steht in der Sitzung, wird als
 * <meta name="csrf-token"> gerendert und vom Client als Header X-CSRF-Token
 * zurueckgeschickt (apiFetch() in assets/app.js macht das automatisch).
 */

/**
 * Liefert das Token der Sitzung und erzeugt es beim ersten Aufruf.
 */
function csrf_token(): string {
    bootstrap_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/**
 * Prueft das Token und beendet den Request mit 403, wenn es nicht passt.
 *
 * Gehoert an den Anfang JEDES zustandsaendernden Endpunkts -- auch dort, wo
 * ohnehin schon require_login_api() steht. Eine angemeldete Sitzung ist genau
 * das, was ein fremdes Formular ausnutzen wuerde.
 */
function csrf_check(): void {
    bootstrap_session();

    $sent   = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');

    if ($sent === '' || $stored === '' || !hash_equals($stored, $sent)) {
        json_err('Sicherheits-Token ungültig — bitte die Seite neu laden.', 403);
    }
}

/**
 * Wie csrf_check(), aber fuer klassische Formular-POSTs ohne JSON-Antwort.
 */
function csrf_check_form(): void {
    bootstrap_session();

    $sent   = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');

    if ($sent === '' || $stored === '' || !hash_equals($stored, $sent)) {
        http_response_code(403);
        exit('Sicherheits-Token ungültig — bitte die Seite neu laden.');
    }
}
