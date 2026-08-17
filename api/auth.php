<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';

bootstrap_session();

/**
 * Anmeldung, Passwortwechsel und Geraeteverwaltung.
 *
 * Hier steht bewusst KEIN require_login_api() am Kopf: die Anmeldung selbst
 * muss ohne Sitzung erreichbar sein. Jede Aktion prueft ihre Rechte einzeln.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const PASSWORT_MIN_LAENGE = 8;

$eingabe = read_json_body();
$aktion  = to_str($eingabe['action'] ?? '');

match ($aktion) {
    'login'           => aktion_login($eingabe),
    'change_password' => aktion_passwort_aendern($eingabe),
    'change_name'     => aktion_name_aendern($eingabe),
    'set_expert_mode' => aktion_expertenmodus($eingabe),
    'set_satz_vorlage' => aktion_satz_vorlage($eingabe),
    'revoke_device'   => aktion_geraet_abmelden($eingabe),
    'revoke_all'      => aktion_alle_geraete_abmelden(),
    default           => json_err('Unbekannte Aktion', 400),
};

/**
 * Anmeldung mit Benutzername und Passwort.
 */
function aktion_login(array $eingabe): never {
    $name     = to_str($eingabe['name'] ?? '');
    $passwort = (string)($eingabe['password'] ?? '');
    $merken   = !empty($eingabe['remember']);

    $fehler = [];
    if ($name === '')     { $fehler['name']     = 'Bitte den Benutzernamen eingeben.'; }
    if ($passwort === '') { $fehler['password'] = 'Bitte das Passwort eingeben.'; }
    if ($fehler !== []) {
        json_err('Bitte alle Felder ausfüllen.', 422, $fehler);
    }

    // Die Bremse greift VOR der Passwortpruefung -- sonst waere sie nur eine
    // Zaehlung und keine Sperre.
    $ip = client_ip();
    $gesperrt = login_block_minutes($ip);
    if ($gesperrt > 0) {
        json_err(
            'Zu viele Fehlversuche. Bitte in ' . $gesperrt . ' Minuten erneut versuchen.',
            429
        );
    }

    $ergebnis = attempt_login($name, $passwort, $merken);

    // Gesperrt zaehlt AUSDRUECKLICH nicht als Fehlversuch (§6.1). Das Passwort
    // war ja richtig -- die Bremse ist gegen Rateversuche gedacht, nicht gegen
    // korrekte Eingaben. Und sie zaehlt pro IP: Wuerde der Fall mitzaehlen,
    // sperrte ein gesperrter Benutzer mit fuenf Versuchen den ganzen Haushalt
    // fuer eine Viertelstunde aus, denn hinter einem Anschluss sitzen alle
    // unter derselben Adresse.
    if ($ergebnis === LOGIN_GESPERRT) {
        json_err(
            'Dieses Konto ist gesperrt. Ein Administrator kann es wieder freigeben.',
            403
        );
    }

    if ($ergebnis !== LOGIN_OK) {
        record_login_failure($ip);
        // Nicht verraten, ob der Benutzername existiert.
        json_err('Benutzername oder Passwort ist falsch.', 401);
    }

    clear_login_attempts($ip);
    purge_expired_remember_tokens();

    $benutzer = current_user();
    json_ok([
        'redirect' => ((int)($benutzer['must_change_password'] ?? 0) === 1)
            ? 'password.php'
            : 'index.php',
    ]);
}

/**
 * Passwortwechsel durch den Benutzer selbst (§7.7).
 */
function aktion_passwort_aendern(array $eingabe): never {
    require_login_api();

    $alt   = (string)($eingabe['current'] ?? '');
    $neu   = (string)($eingabe['new'] ?? '');
    $neu2  = (string)($eingabe['new_repeat'] ?? '');
    $id    = current_user_id();

    $fehler = [];
    if ($alt === '') {
        $fehler['current'] = 'Bitte das aktuelle Passwort eingeben.';
    }
    if (str_len_utf8($neu) < PASSWORT_MIN_LAENGE) {
        $fehler['new'] = 'Mindestens ' . PASSWORT_MIN_LAENGE . ' Zeichen.';
    }
    if ($neu !== $neu2) {
        $fehler['new_repeat'] = 'Die beiden Eingaben stimmen nicht überein.';
    }
    if ($neu !== '' && $neu === $alt) {
        $fehler['new'] = 'Das neue Passwort muss sich vom alten unterscheiden.';
    }
    if ($fehler !== []) {
        json_err('Bitte die Eingaben prüfen.', 422, $fehler);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($alt, $hash)) {
        json_err('Das aktuelle Passwort ist falsch.', 403, [
            'current' => 'Passwort stimmt nicht.',
        ]);
    }

    db()->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
    )->execute([password_hash_app($neu), $id]);

    // Wer sein Passwort wechselt, tut das oft, WEIL etwas nicht stimmt.
    // Deshalb verlieren alle Geraete ihre Anmeldung -- und nur das gerade
    // benutzte bekommt sofort ein frisches Token, damit man sich nicht selbst
    // aussperrt.
    $hatteToken = isset($_COOKIE[REMEMBER_COOKIE]);
    revoke_all_remember_tokens($id);
    session_regenerate_id(true);
    if ($hatteToken && remember_me_available()) {
        issue_remember_token($id);
    } else {
        clear_remember_cookie();
    }

    json_ok(['redirect' => 'index.php']);
}

/**
 * Benutzername durch den Benutzer selbst aendern (§7.7).
 *
 * ZWEI Dinge, die hier leicht untergehen:
 *
 * 1. require_passwort_gesetzt_api() gehoert AUSDRUECKLICH hierher. api/auth.php
 *    ist der einzige Endpunkt ohne die Sperre am Dateikopf -- die Anmeldung und
 *    der erzwungene Passwortwechsel muessen ja erreichbar bleiben. Ohne diese
 *    Zeile koennte jemand mit dem vom Admin vergebenen Startpasswort seinen
 *    Namen aendern, ohne je ein eigenes Passwort gesetzt zu haben.
 *
 * 2. Das aktuelle Passwort wird verlangt, obwohl der Benutzer angemeldet ist.
 *    Der Benutzername IST die Anmeldekennung: Wer ein kurz unbeaufsichtigtes,
 *    entsperrtes Handy in die Finger bekaeme, koennte den Besitzer sonst mit
 *    zwei Tipps von seinem eigenen Konto aussperren -- der weiss den neuen
 *    Namen nicht. Dieselbe Ueberlegung wie beim Passwortwechsel.
 */
function aktion_name_aendern(array $eingabe): never {
    require_login_api();
    require_passwort_gesetzt_api();

    $id       = current_user_id();
    $benutzer = current_user();
    // Feldname 'new_name', damit die Meldungen auf der Kontoseite an der
    // richtigen Eingabe landen -- 'name' waere dort nicht eindeutig.
    $neu      = benutzername_pruefen($eingabe['name'] ?? '', 'new_name');
    $passwort = (string)($eingabe['password'] ?? '');

    if ($passwort === '') {
        json_err('Bitte das Passwort eingeben.', 422, [
            'name_password' => 'Zur Bestätigung nötig.',
        ]);
    }
    if ($neu === (string)($benutzer['name'] ?? '')) {
        json_err('Das ist bereits Ihr Benutzername.', 422, [
            'new_name' => 'Unverändert.',
        ]);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($passwort, $hash)) {
        json_err('Das Passwort ist falsch.', 403, [
            'name_password' => 'Passwort stimmt nicht.',
        ]);
    }

    // Kein Abmelden anderer Geraete und kein session_regenerate_id(): Es
    // aendert sich keine Berechtigung, und die Sitzung haengt ohnehin an der
    // user_id. Der Name ist ab dem naechsten Seitenaufbau ueberall neu.
    benutzer_umbenennen($id, $neu, 'new_name');

    json_ok(['name' => $neu]);
}

/**
 * Den Expertenmodus ein- oder ausschalten (§7.4, §7.7).
 *
 * OHNE Passwortabfrage -- anders als beim Benutzernamen. Der ist die
 * Anmeldekennung, und wer ihn aendert, kann den Besitzer aussperren. Dieser
 * Schalter aendert nur die Darstellung der eigenen Daten, nimmt niemandem
 * etwas weg und ist mit einem Tipp zurueckgedreht.
 *
 * GESPERRT, SOLANGE EINE EINHEIT LAEUFT, und der Grund ist handfest: Die
 * Warteschlange im localStorage (§7.4) ist auf user_id und session_id
 * geschluesselt und ueberlebt einen Moduswechsel damit unbeschadet. Ein
 * wartender Eintrag aus dem einfachen Modus traegt keine Satzliste -- und ein
 * "check" ohne Satzliste loescht die Saetze der Position (api/log.php,
 * saetze_schreiben()). Das waere stiller Datenverlust mitten im Training. Die
 * Sperre kostet nichts und raeumt die ganze Fallgruppe ab.
 */
function aktion_expertenmodus(array $eingabe): never {
    require_login_api();
    require_passwort_gesetzt_api();

    if (!array_key_exists('expert_mode', $eingabe)) {
        json_err('Kein Wert angegeben.', 422);
    }
    $an = !empty($eingabe['expert_mode']) ? 1 : 0;

    require_once __DIR__ . '/../lib/training.php';
    if (offene_einheit(current_user_id()) !== null) {
        json_err(
            'Das geht nur, wenn gerade kein Training läuft. '
            . 'Bitte zuerst die laufende Einheit beenden.',
            409
        );
    }

    db()->prepare('UPDATE users SET expert_mode = ? WHERE id = ?')
        ->execute([$an, current_user_id()]);

    json_ok(['expert_mode' => $an]);
}

/**
 * Woher die Vorbelegung eines neuen Satzes kommt (§7.4).
 *
 * Anders als beim Expertenmodus gibt es hier ABSICHTLICH keine Sperre waehrend
 * eines laufenden Trainings. Der Grund fuer die Sperre dort ist die Form der
 * Nutzlast: Ein wartender Eintrag aus dem einfachen Modus traegt keine
 * Satzliste, ein Wechsel mittendrin waere stiller Datenverlust (Fallstrick 17).
 * Hier aendert sich an der Nutzlast NICHTS -- beide Verfahren schicken dieselbe
 * Satzliste, sie unterscheiden sich allein darin, was beim naechsten Tippen auf
 * "+ Satz" schon in den Feldern steht. Eine Sperre schuetzte hier nichts, und
 * Sperren, die nichts schuetzen, gewoehnen einem an, sie zu umgehen.
 *
 * Ebenso wenig wird geprueft, ob der Expertenmodus ueberhaupt an ist. Der Wert
 * richtet im einfachen Modus keinen Schaden an -- dort gibt es keine Saetze --,
 * und eine zweite Bedingung muesste man mit der ersten synchron halten. Die
 * Oberflaeche stellt die Auswahl dort lediglich abgeblendet dar.
 */
function aktion_satz_vorlage(array $eingabe): never {
    require_login_api();
    require_passwort_gesetzt_api();

    require_once __DIR__ . '/../lib/training.php';

    $wert = to_str($eingabe['satz_vorlage'] ?? '');

    // Streng gegen die Codeliste, nicht ueber satz_vorlage_normalisieren():
    // Beim SCHREIBEN ist ein unbekannter Wert ein Fehler des Aufrufers und
    // gehoert gemeldet. Beim LESEN ist er ein Altbestand und wird still auf
    // den Standard gezogen. Zwei Richtungen, zwei Haltungen.
    if (!array_key_exists($wert, SATZ_VORLAGE)) {
        json_err('Unbekannte Vorbelegung.', 422, ['satz_vorlage' => 'Bitte eine der Möglichkeiten wählen.']);
    }

    db()->prepare('UPDATE users SET satz_vorlage = ? WHERE id = ?')
        ->execute([$wert, current_user_id()]);

    json_ok(['satz_vorlage' => $wert]);
}

/**
 * Ein einzelnes Geraet abmelden (§7.7).
 */
function aktion_geraet_abmelden(array $eingabe): never {
    require_login_api();

    $tokenId = to_int_or_null($eingabe['token_id'] ?? null);
    if ($tokenId === null) {
        json_err('Kein Gerät angegeben.', 422);
    }

    // Die user_id gehoert in die WHERE-Klausel, nicht in eine Pruefung davor:
    // sonst liesse sich ueber eine fremde Token-ID ein anderes Konto abmelden.
    $stmt = db()->prepare('DELETE FROM remember_tokens WHERE id = ? AND user_id = ?');
    $stmt->execute([$tokenId, current_user_id()]);

    if ($stmt->rowCount() === 0) {
        json_err('Dieses Gerät gibt es nicht (mehr).', 404);
    }

    json_ok(['removed' => $tokenId]);
}

/**
 * Alle Geraete abmelden, das eigene eingeschlossen.
 */
function aktion_alle_geraete_abmelden(): never {
    require_login_api();

    revoke_all_remember_tokens(current_user_id());
    clear_remember_cookie();

    json_ok(['removed_all' => true]);
}
