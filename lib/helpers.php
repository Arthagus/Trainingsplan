<?php
declare(strict_types=1);

/**
 * Kleine Bausteine ohne Abhaengigkeiten: Escaping, JSON-Envelope, Eingabe-
 * normalisierung, Zeit. Wird von allen anderen lib-Dateien eingebunden.
 */

// Die App rechnet durchgehend in Ortszeit. TZ kommt aus der Container-Umgebung,
// der Fallback haelt den Dev-Server ohne gesetzte Variable auf derselben Zone.
date_default_timezone_set(getenv('TZ') ?: 'Europe/Vienna');

/**
 * Auffangnetz fuer nicht behandelte Ausnahmen.
 *
 * Im Container steht display_errors auf Off (richtig so -- ein Stacktrace im
 * Browser verraet Pfade und Code). Ohne diesen Handler bekaeme der Benutzer
 * dann eine voellig leere Seite mit Status 500 und keinerlei Hinweis, und im
 * Frontend liefe apiFetch() in "Unerwarteter Serverfehler".
 *
 * Der eigentliche Grund landet im Containerlog (docker logs / Portainer), die
 * Antwort enthaelt ihn bewusst nicht.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('Unbehandelte Ausnahme: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    // In api/* erwartet der Aufrufer JSON -- eine HTML-Seite liesse
    // apiFetch() an der Stelle scheitern, an der es die Meldung anzeigen will.
    if (str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Serverfehler. Bitte die Logs des Containers prüfen.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="de"><meta charset="utf-8">'
       . '<title>Serverfehler</title>'
       . '<body style="font:16px/1.5 system-ui;margin:2rem;max-width:34rem">'
       . '<h1>Da ist etwas schiefgelaufen</h1>'
       . '<p>Die Seite konnte nicht geladen werden. Die Ursache steht im '
       . 'Log des Containers.</p>'
       . '<p>Häufigster Grund nach einem Neuaufsetzen: Das Datenverzeichnis '
       . 'ist nicht beschreibbar.</p>'
       . '</body></html>';
});

/**
 * HTML-Escape fuer die Ausgabe in Templates: <?= h($wert) ?>
 */
function h(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Aktueller Zeitstempel im Speicherformat.
 *
 * Alle Zeitstempel entstehen hier und nirgends in SQL: SQLites
 * CURRENT_TIMESTAMP liefert UTC, die App laeuft auf Europe/Vienna. Beides
 * gemischt in derselben Spalte waere nicht mehr zu reparieren.
 */
function now(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Zeitstempel mit Versatz, etwa fuer Ablaufdaten: ts('+90 days').
 */
function ts(string $modify): string {
    return (new DateTimeImmutable($modify))->format('Y-m-d H:i:s');
}

/**
 * Zeichenlaenge UTF-8-bewusst, mit Byte-Laenge als sichere Obergrenze,
 * falls mbstring fehlt.
 */
function str_len_utf8(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/**
 * Kuerzt einen String auf hoechstens $max Zeichen, ohne ein UTF-8-Zeichen zu
 * zerschneiden.
 *
 * Ein blosses substr() koennte mitten in einer Mehrbyte-Sequenz trennen; der
 * Rest waere kein gueltiges UTF-8 mehr und json_encode() wuerde spaeter
 * kommentarlos false liefern. mbstring wird nicht vorausgesetzt -- die
 * Vorlagen-Repos kommen auch ohne aus.
 */
function str_cut(string $s, int $max): string {
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    $treffer = [];
    preg_match('/^.{0,' . $max . '}/us', $s, $treffer);
    // Bei ungueltigem UTF-8 greift das Muster nicht; dann hart auf Bytes kuerzen.
    return $treffer[0] ?? substr($s, 0, $max);
}

/**
 * Laeuft der oeffentliche Endpunkt ueber HTTPS?
 *
 * Nicht $_SERVER['HTTPS'] auswerten: TLS terminiert am Host-Nginx, intern
 * kommt reines HTTP an. Wer auf HTTPS prueft, baut eine Redirect-Schleife.
 * Dem Header darf vertraut werden, weil der Container-Port nur ueber den
 * vorgelagerten Proxy erreichbar ist (LASTENHEFT.md §3).
 */
function is_https(): bool {
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/**
 * IP des Clients.
 *
 * X-Forwarded-For wird bewusst NICHT hier ausgewertet, sondern von
 * mod_remoteip im Apache (siehe apache-app.conf) -- der kennt die Liste der
 * vertrauenswuerdigen Proxys, dieses Skript nicht. Steht hier die Adresse des
 * Nginx statt die des Clients, ist das Modul nicht aktiv; dann zaehlt die
 * Brute-Force-Bremse alle Benutzer auf denselben Eimer.
 */
function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Basis-URL der Anwendung, abgeleitet aus dem laufenden Skript.
 * Liefert '' wenn die App im Wurzelverzeichnis liegt (der Normalfall).
 */
function base_path(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    // In api/* liegt das Skript eine Ebene tiefer.
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    return rtrim($dir, '/');
}

/**
 * Die Version dieses Anwendungsstands, aus der Datei VERSION im Wurzelverzeichnis.
 *
 * Die Datei ist die EINZIGE Stelle, an der die Nummer gepflegt wird; sie wandert
 * mit "COPY . /var/www/html" ins Image und faehrt damit im Container mit. Was
 * die Wartungsseite anzeigt, ist deshalb die Version des laufenden Images und
 * nicht die des Arbeitsstands auf irgendeinem Rechner.
 *
 * deploy/paket_bauen.sh prueft vor dem Packen, dass deploy/stack.yml und
 * deploy/ANLEITUNG.md dieselbe Nummer nennen, und bricht sonst ab. Ohne diese
 * Pruefung wuerde die Anzeige irgendwann eine Version behaupten, die gar nicht
 * laeuft -- und eine falsche Zahl ist schlechter als gar keine, weil man sich
 * auf sie verlaesst.
 *
 * Faellt die Datei aus, steht dort 'unbekannt': Ein fehlender Versionshinweis
 * darf die Wartungsseite nicht lahmlegen -- sie ist die Seite, auf der man im
 * Fehlerfall landet.
 */
function app_version(): string {
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $datei = dirname(__DIR__) . '/VERSION';
    $inhalt = is_file($datei) ? (string)file_get_contents($datei) : '';
    $inhalt = trim($inhalt);

    $version = $inhalt === '' ? 'unbekannt' : $inhalt;
    return $version;
}

/**
 * Leitet auf eine Seite der App um und beendet das Skript.
 */
function redirect(string $page): never {
    header('Location: ' . base_path() . '/' . ltrim($page, '/'));
    exit;
}

/**
 * Sendet eine JSON-Erfolgsantwort und beendet das Skript.
 */
function json_ok(array $data = [], int $status = 200): never {
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sendet eine JSON-Fehlerantwort und beendet das Skript.
 *
 * @param array<string,string> $fields Feldbezogene Meldungen fuer das Formular
 */
function json_err(string $error, int $status = 400, array $fields = []): never {
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $resp = ['ok' => false, 'error' => $error];
    if ($fields !== []) {
        $resp['fields'] = $fields;
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Liest den JSON-Body eines Requests. Leerer oder unlesbarer Body ergibt [].
 */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Liefert die Eingabewerte eines Requests, egal ob als JSON oder als
 * Formdaten geschickt.
 *
 * Endpunkte mit Datei-Upload bekommen zwangslaeufig multipart/form-data --
 * dort ist php://input leer, weil PHP den Body schon in $_POST und $_FILES
 * zerlegt hat. Ohne diese Weiche muesste jeder solche Endpunkt zwei Wege
 * kennen.
 */
function read_input(): array {
    return $_POST !== [] ? $_POST : read_json_body();
}

/**
 * Liest eine Liste von IDs aus der Eingabe.
 *
 * Formdaten liefern Wiederholungsfelder als Array von Strings, JSON als Array
 * von Zahlen -- beides landet hier als Liste von int. Ungueltige Werte fliegen
 * raus, statt still zu 0 zu werden.
 *
 * @return int[]
 */
function to_id_list(mixed $v): array {
    if (!is_array($v)) {
        return [];
    }
    $ids = [];
    foreach ($v as $roh) {
        $id = to_int_or_null($roh);
        if ($id !== null && $id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Wandelt eine Zahleneingabe in float oder null.
 *
 * Akzeptiert das Dezimalkomma: am Handy liefert die deutsche Tastatur "62,5",
 * und (float)"62,5" waere 62. Deshalb sind die Eingabefelder auch
 * type="text" inputmode="decimal" und nicht type="number".
 */
function to_decimal_or_null(mixed $v): ?float {
    if ($v === null || $v === '' || is_array($v)) {
        return null;
    }
    $s = str_replace(',', '.', trim((string)$v));
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Wandelt eine Eingabe in eine positive Ganzzahl oder null.
 */
function to_int_or_null(mixed $v): ?int {
    if ($v === null || $v === '' || is_array($v)) {
        return null;
    }
    $s = trim((string)$v);
    return preg_match('/^\d+$/', $s) === 1 ? (int)$s : null;
}

/**
 * Trimmt einen Eingabewert zu einem String. Arrays ergeben ''.
 */
function to_str(mixed $v): string {
    return is_array($v) ? '' : trim((string)($v ?? ''));
}

/**
 * Formatiert ein Gewicht fuer die Anzeige: 62.5 -> "62,5", 60.0 -> "60".
 */
function format_decimal(?float $v): string {
    if ($v === null) {
        return '';
    }
    $s = rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    return $s === '' ? '0' : $s;
}

/**
 * Formatiert einen gespeicherten Zeitstempel fuer die Anzeige.
 */
function format_datetime(?string $ts): string {
    if ($ts === null || $ts === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts);
    return $dt === false ? $ts : $dt->format('d.m.Y H:i');
}

/**
 * Macht aus einem User-Agent eine lesbare Geraetebezeichnung fuer die
 * Geraeteliste (§7.7), etwa "iPhone · Safari".
 *
 * Bewusst grob: Es geht nur darum, dass der Benutzer sein eigenes Handy
 * wiedererkennt, wenn er ein Gerät abmelden will. Eine genaue Erkennung wäre
 * nicht zuverlässiger, nur länger.
 */
function geraete_bezeichnung(?string $userAgent): string {
    $ua = (string)$userAgent;
    if (trim($ua) === '') {
        return 'Unbekanntes Gerät';
    }

    $system = match (true) {
        str_contains($ua, 'iPhone')                        => 'iPhone',
        str_contains($ua, 'iPad')                          => 'iPad',
        str_contains($ua, 'Android')                       => 'Android',
        str_contains($ua, 'Windows')                       => 'Windows',
        str_contains($ua, 'Macintosh'), str_contains($ua, 'Mac OS') => 'Mac',
        str_contains($ua, 'Linux')                         => 'Linux',
        default                                            => 'Unbekanntes System',
    };

    // Reihenfolge zaehlt: Edge und Chrome nennen sich beide "Safari",
    // Edge nennt sich zusaetzlich "Chrome".
    $browser = match (true) {
        str_contains($ua, 'Edg/')     => 'Edge',
        str_contains($ua, 'OPR/')     => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Safari/')  => 'Safari',
        default                       => '',
    };

    return $browser === '' ? $system : $system . ' · ' . $browser;
}

/**
 * Hasht ein Passwort. Argon2id wo verfuegbar, sonst bcrypt (§5).
 * Kein Pepper: ein Verlust von APP_SECRET wuerde sonst alle Passwoerter
 * unbrauchbar machen.
 */
function password_hash_app(string $password): string {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    return password_hash($password, $algo);
}
