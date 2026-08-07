<?php
declare(strict_types=1);

/**
 * Betriebspruefung fuer den Docker-HEALTHCHECK.
 *
 * Laeuft ausschliesslich auf der Kommandozeile im Container und ruft
 * health.php ueber HTTP auf -- damit wird die ganze Kette geprueft: Apache,
 * PHP als www-data, Datenbankzugriff im Volume.
 *
 * Warum ein eigenes Skript und kein Einzeiler im Dockerfile:
 *
 *  1. get_headers() haengt an einer Keep-Alive-Verbindung, bis der
 *     default_socket_timeout (60 s) greift. Der HEALTHCHECK bricht nach 10 s
 *     ab und meldet "unhealthy", obwohl die App laengst geantwortet hat --
 *     genau dieser Fall trat beim ersten Rollout auf. Hier wird deshalb
 *     ausdruecklich HTTP/1.0 mit "Connection: close" gesprochen und mit
 *     kurzen, eigenen Zeitlimits gearbeitet.
 *  2. Ein Skript laesst sich von Hand aufrufen und sagt, WAS nicht stimmt:
 *
 *         docker exec trainingsplan php /var/www/html/lib/healthcheck.php
 *         echo $?          # 0 = gesund, 1 = nicht gesund
 *
 * Die Datei liegt in lib/ und ist damit ueber HTTP gesperrt (.htaccess und
 * apache-app.conf).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const HC_HOST    = '127.0.0.1';
const HC_PFAD    = '/health.php';
const HC_TIMEOUT = 3;

// Im Container immer 80. Ueber HEALTHCHECK_PORT umstellbar, damit sich die
// Pruefung auch am Dev-Server (Port 8100) ausfuehren laesst -- sonst waere
// ausgerechnet das Skript ungetestet, das Stoerungen melden soll.
$hcPort = (int)(getenv('HEALTHCHECK_PORT') ?: 80);

/** Beendet mit Meldung und Rueckgabewert. */
function hc_ende(string $text, int $code): never {
    echo $text, "\n";
    exit($code);
}

$fehler = 0;
$meldung = '';
$verbindung = @fsockopen(HC_HOST, $hcPort, $fehler, $meldung, HC_TIMEOUT);

if ($verbindung === false) {
    hc_ende('Apache auf Port ' . $hcPort . ' nicht erreichbar: '
        . $meldung . ' (' . $fehler . ')', 1);
}

stream_set_timeout($verbindung, HC_TIMEOUT);

// HTTP/1.0 plus Connection: close -- so schliesst Apache die Verbindung nach
// der Antwort und stream_get_contents() kehrt sofort zurueck.
fwrite($verbindung, "GET " . HC_PFAD . " HTTP/1.0\r\n"
    . "Host: " . HC_HOST . ':' . $hcPort . "\r\n"
    . "Connection: close\r\n\r\n");

/** Bricht ab, wenn der Lesevorgang in ein Zeitlimit gelaufen ist. */
function hc_timeout_pruefen($verbindung): void {
    $zustand = stream_get_meta_data($verbindung);
    if (!empty($zustand['timed_out'])) {
        hc_ende('Zeitüberschreitung nach ' . HC_TIMEOUT . ' s', 1);
    }
}

// Zeilenweise lesen, NICHT stream_get_contents(): Das wartet auf das Ende der
// Verbindung. Haelt der Server sie offen -- Apache tut das bei KeepAlive, wenn
// er unser "Connection: close" ignoriert oder eine Zwischenstelle es
// verschluckt -- laeuft die Pruefung in ihr Zeitlimit und meldet "unhealthy",
// obwohl die Antwort laengst vollstaendig da ist.
$statuszeile = fgets($verbindung, 512);
hc_timeout_pruefen($verbindung);

if ($statuszeile === false || trim($statuszeile) === '') {
    fclose($verbindung);
    hc_ende('Keine Antwort von ' . HC_PFAD, 1);
}
$statuszeile = trim($statuszeile);

// Kopfzeilen bis zur Leerzeile lesen und dabei die Rumpflaenge merken.
$laenge = null;
while (($zeile = fgets($verbindung, 1024)) !== false) {
    hc_timeout_pruefen($verbindung);
    if (trim($zeile) === '') {
        break;
    }
    if (stripos($zeile, 'Content-Length:') === 0) {
        $laenge = (int)trim(substr($zeile, 15));
    }
}

// Nur so viele Bytes lesen, wie angekuendigt sind -- damit endet das Lesen
// zuverlaessig, ohne auf das Schliessen der Verbindung zu warten.
$rumpf = '';
if ($laenge !== null && $laenge > 0) {
    $rumpf = (string)fread($verbindung, min($laenge, 512));
    hc_timeout_pruefen($verbindung);
} else {
    // Ohne Content-Length (etwa am PHP-Dev-Server) ist nicht bekannt, wie viel
    // kommt. Ein Versuch mit kurzem Zeitlimit: Der Rumpf liegt bereits im
    // Puffer und kommt sofort; bleibt er aus, kostet das hoechstens eine
    // Sekunde und aendert am Urteil nichts -- das faellt ueber die Statuszeile.
    stream_set_timeout($verbindung, 1);
    $rumpf = (string)@fread($verbindung, 512);
}
fclose($verbindung);

if (!str_contains($statuszeile, ' 200')) {
    // health.php meldet 503, wenn die Datenbank nicht erreichbar ist.
    hc_ende('Unerwarteter Status: ' . $statuszeile, 1);
}

// Ohne Content-Length wird der Rumpf nicht gelesen; die Statuszeile allein
// genuegt dann als Nachweis.
if ($rumpf !== '' && !str_starts_with(ltrim($rumpf), 'ok')) {
    hc_ende('Unerwartete Antwort: ' . trim(substr($rumpf, 0, 100)), 1);
}

hc_ende('ok — ' . trim(str_replace("\n", ' ', $rumpf !== '' ? $rumpf : $statuszeile)), 0);
