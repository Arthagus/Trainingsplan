<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();

/**
 * Liefert das CSRF-Token der laufenden Sitzung.
 *
 * Der Zweck ist die Selbstheilung einer offenen Seite (Fallstrick 23): Stirbt
 * die serverseitige Sitzung unter einer Seite, die im Browser stehen bleibt,
 * dann meldet "Angemeldet bleiben" den Benutzer beim naechsten Aufruf
 * stillschweigend wieder an -- die frische Sitzung hat aber kein Token, und
 * jeder Schreibaufruf scheitert von da an mit 403, bis jemand neu laedt. Genau
 * das ist am 2026-08-16 im Studio passiert. Hier holt sich apiFetch() ein
 * neues Token und wiederholt den Aufruf, ohne dass jemand etwas merkt.
 *
 * GET und nicht POST: Ein POST braeuchte ein gueltiges Token, und genau das
 * fehlt ja. Deshalb steht hier auch kein csrf_check() -- er wuerde die
 * Reparatur an derselben Stelle abweisen, an der sie gebraucht wird.
 *
 * Das ist keine Luecke: Das Sitzungscookie ist SameSite=Lax und geht bei einem
 * fremden fetch() gar nicht erst mit; ohne Cookie antwortet dieser Endpunkt mit
 * 401. Und selbst mit Cookie duerfte eine fremde Seite die Antwort wegen CORS
 * nicht lesen. Wer hier ein Token bekommt, hatte die Sitzung ohnehin schon.
 *
 * require_passwort_gesetzt_api() steht bewusst da, obwohl der Endpunkt fuer
 * sich genommen nichts oeffnet (Fallstrick 3 gilt fuer JEDEN Endpunkt ausser
 * api/auth.php, und eine Ausnahme "schadet ja nicht" ist der Anfang vom Ende
 * dieser Regel). Der Preis ist klein und benannt: Auf password.php greift die
 * Selbstheilung damit nicht, dort bleibt es beim Hinweis "bitte neu laden".
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_err('Nur GET', 405);
}

// Ohne das darf der Browser die Antwort heuristisch cachen -- apache-app.conf
// setzt Cache-Control nur fuer assets/ und *.js, ein .php bekommt gar keine
// Angabe zur Haltbarkeit. Ein gecachtes Token waere genau der Zustand, gegen
// den dieser Endpunkt antritt.
if (!headers_sent()) {
    header('Cache-Control: no-store');
}

json_ok(['token' => csrf_token()]);
