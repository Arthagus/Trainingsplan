<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Anmeldung, Sitzung, Rollen, "Angemeldet bleiben" und Brute-Force-Bremse.
 * Die harten Anforderungen dazu stehen in LASTENHEFT.md §5.
 */

const SESSION_NAME        = 'trainingsplan_session';
const REMEMBER_COOKIE     = 'trainingsplan_remember';
const REMEMBER_DAYS       = 90;
const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_WINDOW_MIN    = 15;

/**
 * Vergleichshash fuer unbekannte Benutzernamen -- siehe attempt_login().
 * Gehoert zu keinem Konto und zu keinem verwendeten Passwort.
 */
const DUMMY_HASH = '$2y$12$Bf0ypDlnJb0Cf8.uONrxKeqwbzE7GNYzl4Hmq7tSOAkaK5cTcvxlK';

/**
 * Serverseitiges Secret, ausschliesslich fuer den Hash des
 * Remember-Me-Validators.
 *
 * Ausdruecklich KEIN Passwort-Pepper: die Passwoerter werden ohne dieses
 * Secret gehasht. Ginge es verloren, muessten sich sonst nicht nur alle
 * Geraete neu anmelden -- es waere kein einziges Passwort mehr pruefbar.
 */
function app_secret(): string {
    $secret = (string)(getenv('APP_SECRET') ?: '');
    if ($secret === '') {
        throw new RuntimeException('APP_SECRET ist nicht gesetzt.');
    }
    return $secret;
}

/**
 * Steht "Angemeldet bleiben" zur Verfuegung?
 *
 * Ohne APP_SECRET laesst sich kein Validator-Hash bilden. Statt die Funktion
 * still zu ueberspringen, wird sie sichtbar ausgeblendet -- ein stummes
 * Weglassen von Sicherheitsmechanik ist genau die Art Fehler, die niemandem
 * auffaellt.
 */
function remember_me_available(): bool {
    return (string)(getenv('APP_SECRET') ?: '') !== '';
}

/**
 * Startet die Sitzung mit sicheren Cookie-Optionen und versucht bei Bedarf
 * die Wiederanmeldung ueber das Remember-Me-Cookie. Mehrfachaufruf ist
 * unschaedlich.
 */
function bootstrap_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        // Bedingungslos true, auch wenn intern HTTP ankommt: oeffentlich ist
        // der Endpunkt immer HTTPS. Am Dev-Server stoert das nicht, weil
        // Browser 127.0.0.1 als sicheren Kontext behandeln.
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (empty($_SESSION['user_id'])) {
        try_remember_login();
    }
}

/**
 * Der angemeldete Benutzer als Datensatz, oder null.
 * Ergebnis wird je Request zwischengespeichert.
 */
function current_user(): ?array {
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    bootstrap_session();
    $loaded = true;

    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, is_admin, must_change_password, expert_mode,
                created_at
           FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row === false) {
        // Benutzer wurde geloescht, waehrend die Sitzung noch lief.
        logout();
        return null;
    }

    $user = $row;
    return $user;
}

function current_user_id(): int {
    return (int)(current_user()['id'] ?? 0);
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function is_admin(): bool {
    return (int)(current_user()['is_admin'] ?? 0) === 1;
}

/**
 * Schuetzt eine Seite. Leitet auf login.php um, wenn nicht angemeldet -- und
 * auf password.php, solange ein erzwungener Passwortwechsel offen ist (§3).
 */
function require_login(): void {
    if (!is_logged_in()) {
        redirect('login.php');
    }

    if ((int)(current_user()['must_change_password'] ?? 0) === 1) {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        // Die Seite des Wechsels selbst und der Abmeldeweg bleiben erreichbar,
        // sonst sperrt sich der Benutzer in einer Schleife aus.
        if ($script !== 'password.php' && $script !== 'logout.php') {
            redirect('password.php');
        }
    }
}

/**
 * Schuetzt einen JSON-Endpunkt. 401 statt Redirect -- apiFetch() wertet das
 * aus und schickt den Browser dann selbst auf login.php.
 */
function require_login_api(): void {
    if (!is_logged_in()) {
        json_err('Nicht angemeldet', 401);
    }
}

/**
 * Verlangt, dass ein erzwungener Passwortwechsel erledigt ist.
 *
 * require_login() leitet Seiten auf password.php um -- die API kannte diese
 * Sperre bisher nicht, sodass sich alle Endpunkte benutzen liessen, ohne je
 * ein eigenes Passwort gesetzt zu haben. Genau das hebelt den Zweck des Flags
 * aus: Das Startpasswort kennt der Admin, es ist kein Geheimnis zwischen
 * Benutzer und System.
 *
 * Gehoert in JEDEN Endpunkt ausser api/auth.php -- dort liegt der
 * Passwortwechsel selbst.
 */
function require_passwort_gesetzt_api(): void {
    if ((int)(current_user()['must_change_password'] ?? 0) === 1) {
        json_err('Bitte zuerst das Passwort ändern (Menüpunkt „Konto“).', 403);
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
}

function require_admin_api(): void {
    require_login_api();
    if (!is_admin()) {
        json_err('Kein Zugriff', 403);
    }
}


// ---------------------------------------------------------------------------
// Benutzername (§6.1, §7.7)
// ---------------------------------------------------------------------------

const BENUTZER_NAME_MAX = 40;

/**
 * Prueft einen eingegebenen Benutzernamen und liefert ihn normalisiert zurueck.
 * Bei einem Verstoss antwortet die Funktion selbst und beendet den Request.
 *
 * Steht hier und nicht in einem der Endpunkte, weil es DREI Aufrufstellen gibt
 * -- anlegen, vom Admin umbenennen, selbst umbenennen. Zweimal gepflegt waeren
 * die Regeln irgendwann verschieden, und ein Name, den der eine Weg zulaesst
 * und der andere ablehnt, ist schwer zu erklaeren.
 */
function benutzername_pruefen(mixed $roh, string $feld = 'name'): string {
    $name = to_str($roh);

    if ($name === '') {
        json_err('Bitte einen Benutzernamen eingeben.', 422, [$feld => 'Pflichtfeld.']);
    }
    if (str_len_utf8($name) > BENUTZER_NAME_MAX) {
        json_err('Der Benutzername ist zu lang.', 422, [
            $feld => 'Höchstens ' . BENUTZER_NAME_MAX . ' Zeichen.',
        ]);
    }
    // Steuerzeichen stammen aus einem Einfuegefehler oder sind Absicht. Ein
    // Name mit Zeilenumbruch zerlegt jede Liste, in der er auftaucht.
    //
    // BEWUSST ohne /u: Der Ausdruck arbeitet damit auf Bytes, und genau das
    // ist hier richtig. Alle Bytes einer UTF-8-Mehrbytefolge liegen ueber
    // 0x7F, es gibt also keine Fehltreffer -- waehrend preg_match mit /u bei
    // ungueltigem UTF-8 false zurueckgibt und die Pruefung damit stillschweigend
    // durchfallen liesse.
    if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
        json_err('Der Benutzername enthält unerlaubte Zeichen.', 422, [
            $feld => 'Keine Steuerzeichen.',
        ]);
    }

    return $name;
}

/**
 * Schreibt einen neuen Benutzernamen.
 *
 * Der Name liegt ausschliesslich in users.name; alles andere -- Plaene,
 * Einheiten, Protokoll, Remember-Me-Tokens -- haengt an der user_id. Ein
 * Umbenennen ist deshalb dieses eine UPDATE und braucht weder Migration noch
 * Nacharbeit. Auch die Sitzung bleibt gueltig: In $_SESSION steht die id, und
 * current_user() laedt den Datensatz bei jedem Aufruf frisch darueber.
 *
 * Die Kollision wird ueber den UNIQUE-Index abgefangen und nicht ueber ein
 * SELECT davor: Zwischen Pruefung und Schreiben liegt sonst ein Zeitfenster,
 * in dem sich derselbe Name zweimal vergeben liesse.
 */
function benutzer_umbenennen(int $id, string $name, string $feld = 'name'): void {
    try {
        db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            json_err('Diesen Benutzernamen gibt es schon.', 409, [
                $feld => 'Name bereits vergeben.',
            ]);
        }
        throw $e;
    }
}


// ---------------------------------------------------------------------------
// Brute-Force-Bremse (§5)
// ---------------------------------------------------------------------------

/**
 * Ist diese IP gerade gesperrt? Liefert die Zahl der verbleibenden Minuten,
 * oder 0 wenn nicht gesperrt.
 */
function login_block_minutes(string $ip): int {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS anzahl, MIN(attempted_at) AS aeltester
           FROM login_attempts
          WHERE ip = ? AND attempted_at > ?'
    );
    $stmt->execute([$ip, ts('-' . LOGIN_WINDOW_MIN . ' minutes')]);
    $row = $stmt->fetch();

    if ((int)($row['anzahl'] ?? 0) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }

    $frei = strtotime((string)$row['aeltester']) + LOGIN_WINDOW_MIN * 60;
    return max(1, (int)ceil(($frei - time()) / 60));
}

function record_login_failure(string $ip): void {
    $stmt = db()->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)');
    $stmt->execute([$ip, now()]);

    // Aufraeumen bei Gelegenheit: aelter als 24 h interessiert niemanden mehr.
    db()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([ts('-24 hours')]);
}

function clear_login_attempts(string $ip): void {
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}


// ---------------------------------------------------------------------------
// Anmelden und Abmelden
// ---------------------------------------------------------------------------

/**
 * Prueft Benutzername und Passwort und meldet bei Erfolg an.
 *
 * Gibt bei Misserfolg bewusst nicht preis, ob der Benutzername existiert.
 *
 * COLLATE NOCASE: Die Anmeldung fragt nicht nach der Schreibweise. Ohne das
 * kaeme "Oliver" mit der Eingabe "oliver" nicht herein -- am Handy die
 * naheliegendste Fehlerquelle ueberhaupt, weil die Tastatur den ersten
 * Buchstaben von sich aus gross macht. Das Gegenstueck ist der
 * schreibweisenunabhaengige UNIQUE-Index aus apply_migrations(): Ohne ihn
 * koennte diese Abfrage mehrere Zeilen treffen und die Anmeldung haenge davon
 * ab, welche SQLite zuerst liefert.
 */
function attempt_login(string $name, string $password, bool $remember = false): bool {
    bootstrap_session();

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE name = ? COLLATE NOCASE');
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    if ($row === false) {
        // Trotzdem eine Hash-Pruefung rechnen, damit ein unbekannter Name
        // nicht messbar schneller beantwortet wird als ein falsches Passwort.
        //
        // Der Hash muss ein FORMAL GUELTIGER bcrypt-Hash sein (Cost 12, wie
        // die echten): Bei einem kaputten Wert darf password_verify() sofort
        // abbrechen, und der Zeitunterschied verriete, dass es den Benutzer
        // nicht gibt. Es ist der Hash einer Zeichenkette, die kein Passwort ist.
        password_verify($password, DUMMY_HASH);
        return false;
    }

    if (!password_verify($password, (string)$row['password_hash'])) {
        return false;
    }

    // Gegen Session-Fixation: die ID vor dem Anmelden wegwerfen.
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$row['id'];
    $_SESSION['login_time'] = time();

    if ($remember && remember_me_available()) {
        issue_remember_token((int)$row['id']);
    }

    return true;
}

/**
 * Meldet ab: Sitzung raeumen, Cookie loeschen, Remember-Token widerrufen.
 */
function logout(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(SESSION_NAME);
        session_start();
    }

    // Nur das Token dieses Geraets widerrufen -- die uebrigen Geraete des
    // Benutzers bleiben angemeldet.
    revoke_current_remember_token();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}


// ---------------------------------------------------------------------------
// "Angemeldet bleiben" nach Selector/Validator-Muster (§5)
// ---------------------------------------------------------------------------
//
// Das Cookie enthaelt "selector:validator". In der Datenbank steht nur der
// HASH des Validators -- wer die Datenbank liest, kann sich damit nicht
// anmelden. Der Selector dient allein dem Nachschlagen; verglichen wird der
// Validator in konstanter Zeit. Bei jeder Nutzung wird das Token rotiert, ein
// abgefangenes Cookie ist damit nur bis zum naechsten Aufruf des Besitzers
// brauchbar.

function remember_validator_hash(string $validator): string {
    return hash_hmac('sha256', $validator, app_secret());
}

/**
 * Legt ein neues Remember-Token an und setzt das Cookie.
 */
function issue_remember_token(int $userId): void {
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires   = ts('+' . REMEMBER_DAYS . ' days');

    $stmt = db()->prepare(
        'INSERT INTO remember_tokens
             (user_id, selector, validator_hash, expires_at, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $selector,
        remember_validator_hash($validator),
        $expires,
        str_cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
        now(),
    ]);

    set_remember_cookie($selector . ':' . $validator, strtotime($expires));
}

/**
 * Versucht die Wiederanmeldung ueber das Cookie. Wird aus bootstrap_session()
 * heraus aufgerufen, greift also auf jeder Seite.
 */
function try_remember_login(): void {
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie === '' || !remember_me_available()) {
        return;
    }

    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        clear_remember_cookie();
        return;
    }
    [$selector, $validator] = $parts;

    $stmt = db()->prepare(
        'SELECT id, user_id, validator_hash, expires_at
           FROM remember_tokens WHERE selector = ?'
    );
    $stmt->execute([$selector]);
    $row = $stmt->fetch();

    if ($row === false) {
        clear_remember_cookie();
        return;
    }

    if ((string)$row['expires_at'] < now()) {
        db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
        clear_remember_cookie();
        return;
    }

    // Konstantzeit-Vergleich: ein zeichenweiser Abbruch waere messbar.
    if (!hash_equals((string)$row['validator_hash'], remember_validator_hash($validator))) {
        // Gueltiger Selector, falscher Validator -- das ist kein Vertipper,
        // sondern ein Rateversuch. Token verbrennen.
        db()->prepare('DELETE FROM remember_tokens WHERE id = ?')->execute([$row['id']]);
        clear_remember_cookie();
        return;
    }

    rotate_remember_token((int)$row['id']);

    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$row['user_id'];
    $_SESSION['login_time'] = time();
}

/**
 * Ersetzt Selector und Validator eines Tokens und schreibt das Cookie neu.
 */
function rotate_remember_token(int $tokenId): void {
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires   = ts('+' . REMEMBER_DAYS . ' days');

    $stmt = db()->prepare(
        'UPDATE remember_tokens
            SET selector = ?, validator_hash = ?, expires_at = ?, last_used_at = ?,
                user_agent = ?
          WHERE id = ?'
    );
    $stmt->execute([
        $selector,
        remember_validator_hash($validator),
        $expires,
        now(),
        str_cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
        $tokenId,
    ]);

    set_remember_cookie($selector . ':' . $validator, strtotime($expires));
}

/**
 * Widerruft das Token dieses Geraets (beim Abmelden).
 */
function revoke_current_remember_token(): void {
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie !== '') {
        $selector = explode(':', $cookie, 2)[0];
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }
    clear_remember_cookie();
}

/**
 * Widerruft alle Tokens eines Benutzers -- meldet also jedes Geraet ab.
 * Wird nach einem Passwortwechsel gebraucht.
 */
function revoke_all_remember_tokens(int $userId): void {
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
}

/**
 * Raeumt abgelaufene Tokens weg.
 */
function purge_expired_remember_tokens(): void {
    db()->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')->execute([now()]);
}

function set_remember_cookie(string $value, int $expires): void {
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[REMEMBER_COOKIE] = $value;
}

function clear_remember_cookie(): void {
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}
