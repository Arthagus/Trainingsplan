<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();
require_admin_api();

/**
 * Benutzerverwaltung (§6.1).
 *
 * Kein Self-Signup -- Benutzer entstehen ausschliesslich hier. Die harte
 * Regel: Der letzte verbliebene Admin darf weder geloescht noch degradiert
 * werden, sonst waere niemand mehr in der Lage, das rueckgaengig zu machen.
 *
 * Daneben die drei Selbstsperren, alle mit derselben Begruendung: Loeschen,
 * Adminrecht entziehen und Sperren gehen NICHT gegen das eigene Konto, weil
 * danach genau das Recht fehlte, das zum Rueckgaengigmachen noetig waere.
 * Umbenennen faellt ausdruecklich nicht darunter.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

// BENUTZER_NAME_MAX steht in lib/auth.php -- der Selbst-Umbenennung in
// api/auth.php gelten dieselben Regeln.
const BENUTZER_PW_MIN = 8;

$eingabe = read_json_body();

match (to_str($eingabe['action'] ?? '')) {
    'create'         => aktion_anlegen($eingabe),
    'rename'         => aktion_umbenennen($eingabe),
    'reset_password' => aktion_passwort_zuruecksetzen($eingabe),
    'set_admin'      => aktion_admin_setzen($eingabe),
    'set_blocked'    => aktion_sperren($eingabe),
    'delete'         => aktion_loeschen($eingabe),
    default          => json_err('Unbekannte Aktion', 400),
};

/**
 * Anzahl der Admins. Grundlage der Letzter-Admin-Regel.
 */
function admin_anzahl(): int {
    return (int)db()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
}

function benutzer_laden(int $id): array {
    $stmt = db()->prepare('SELECT id, name, is_admin, blocked_at FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $benutzer = $stmt->fetch();
    if ($benutzer === false) {
        json_err('Diesen Benutzer gibt es nicht (mehr).', 404);
    }
    return $benutzer;
}

function passwort_pruefen(string $passwort, string $feld = 'password'): void {
    if (str_len_utf8($passwort) < BENUTZER_PW_MIN) {
        json_err('Das Passwort ist zu kurz.', 422, [
            $feld => 'Mindestens ' . BENUTZER_PW_MIN . ' Zeichen.',
        ]);
    }
}

function aktion_anlegen(array $eingabe): never {
    $name     = benutzername_pruefen($eingabe['name'] ?? '');
    $passwort = (string)($eingabe['password'] ?? '');
    $istAdmin = !empty($eingabe['is_admin']);

    passwort_pruefen($passwort);

    // must_change_password = 1: Der Admin kennt das Startpasswort, also ist es
    // kein Geheimnis zwischen Benutzer und System, bis es gewechselt wurde.
    try {
        $stmt = db()->prepare(
            'INSERT INTO users (name, password_hash, is_admin, must_change_password, created_at)
             VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$name, password_hash_app($passwort), $istAdmin ? 1 : 0, now()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            json_err('Diesen Benutzernamen gibt es schon.', 409, [
                'name' => 'Name bereits vergeben.',
            ]);
        }
        throw $e;
    }

    json_ok(['id' => (int)db()->lastInsertId()]);
}

/**
 * Benutzer umbenennen (§6.1).
 *
 * Anders als beim Loeschen und beim Adminrecht gibt es hier KEINE Ausnahme
 * fuer das eigene Konto: Ein Admin, der sich selbst umbenennt, kennt den neuen
 * Namen und kommt damit weiter herein. Er sperrt sich nicht aus, und
 * rueckgaengig machen kann er es jederzeit selbst.
 *
 * Ebensowenig eine Regel fuer den letzten Admin -- ein Name aendert an den
 * Rechten nichts.
 */
function aktion_umbenennen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    $benutzer = benutzer_laden($id);
    $neu      = benutzername_pruefen($eingabe['name'] ?? '');

    if ($neu === (string)$benutzer['name']) {
        json_err('Der Name ist unverändert.', 422, ['name' => 'Schon dieser Name.']);
    }

    // Angemeldete Geraete bleiben angemeldet: Die Tokens haengen an der
    // user_id, nicht am Namen, und ein Umbenennen ist kein Sicherheitsvorfall.
    // Anders als beim Zuruecksetzen des Passworts gibt es hier also nichts zu
    // widerrufen.
    benutzer_umbenennen($id, $neu);

    json_ok(['id' => $id, 'name' => $neu]);
}

/**
 * Passwort zuruecksetzen (§6.1).
 */
function aktion_passwort_zuruecksetzen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    benutzer_laden($id);
    $passwort = (string)($eingabe['password'] ?? '');
    passwort_pruefen($passwort);

    db()->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?'
    )->execute([password_hash_app($passwort), $id]);

    // Entscheidend: Ein Zuruecksetzen erfolgt oft, WEIL ein Geraet abhanden
    // gekommen ist. Bleiben die Remember-Me-Tokens gueltig, ist genau dieses
    // Geraet weiterhin angemeldet und das neue Passwort nutzlos.
    revoke_all_remember_tokens($id);

    json_ok(['id' => $id]);
}

/**
 * Adminrecht geben oder nehmen.
 */
function aktion_admin_setzen(array $eingabe): never {
    $id       = to_int_or_null($eingabe['id'] ?? null);
    $istAdmin = !empty($eingabe['is_admin']);

    if ($id === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    $benutzer = benutzer_laden($id);

    if (!$istAdmin && (int)$benutzer['is_admin'] === 1 && admin_anzahl() <= 1) {
        json_err(
            'Das ist der letzte Administrator. Ihm das Recht zu nehmen würde die '
            . 'Verwaltung unerreichbar machen — vorher einen zweiten Admin anlegen.',
            409
        );
    }

    // Sich selbst degradieren ist eine Einbahnstrasse: Danach fehlt genau das
    // Recht, das zum Rueckgaengigmachen noetig waere. Selbst wenn es einen
    // zweiten Admin gibt, muesste der einspringen -- dieselbe Falle wie beim
    // Loeschen des eigenen Kontos, also dieselbe Sperre.
    if (!$istAdmin && $id === current_user_id()) {
        json_err(
            'Das eigene Adminrecht lässt sich hier nicht entziehen — danach wäre '
            . 'diese Seite für Sie gesperrt. Ein anderer Admin kann es tun.',
            409
        );
    }

    db()->prepare('UPDATE users SET is_admin = ? WHERE id = ?')
        ->execute([$istAdmin ? 1 : 0, $id]);

    json_ok(['id' => $id, 'is_admin' => $istAdmin]);
}

/**
 * Konto sperren oder wieder freigeben (§6.1).
 *
 * Der Unterschied zum Loeschen ist der ganze Zweck: Plaene, Verlauf, Protokoll
 * und Saetze bleiben unangetastet, und ein Entsperren stellt den Zustand
 * vollstaendig wieder her. Gedacht fuer Konten, die nur zeitweise gebraucht
 * werden -- etwa das Wartungskonto, das zwischen zwei Arbeitsrunden nichts zu
 * suchen hat.
 *
 * Zwei Regeln, und nur zwei:
 *
 *   1. Nicht das eigene Konto -- dieselbe Sackgasse wie beim Loeschen und beim
 *      Adminrecht, nur unmittelbarer: Die Sperre wirkt schon beim naechsten
 *      Seitenaufruf (current_user()), man waere also noch im selben Klick
 *      draussen und koennte es nicht zuruecknehmen.
 *
 *   2. Sonst KEINE. Insbesondere braucht es hier bewusst keine
 *      Letzter-Admin-Regel: Sperren darf nur ein angemeldeter Admin, und sich
 *      selbst kann er nicht sperren -- es bleibt also zwangslaeufig immer
 *      mindestens ein aktiver Admin uebrig, naemlich der Handelnde. Eine
 *      zusaetzliche Pruefung waere eine Regel, die nie greifen kann, und solche
 *      Regeln laedt man sich nicht ein: Sie sehen wie ein Schutz aus und
 *      verdecken, dass der echte Schutz woanders sitzt.
 */
function aktion_sperren(array $eingabe): never {
    $id        = to_int_or_null($eingabe['id'] ?? null);
    $sperren   = !empty($eingabe['blocked']);

    if ($id === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    $benutzer = benutzer_laden($id);

    if ($id === current_user_id()) {
        json_err(
            'Das eigene Konto lässt sich hier nicht sperren — Sie wären damit '
            . 'sofort ausgesperrt und könnten es nicht rückgängig machen.',
            409
        );
    }

    benutzer_sperren($id, $sperren);

    json_ok(['id' => $id, 'blocked' => $sperren, 'name' => (string)$benutzer['name']]);
}

/**
 * Benutzer loeschen (§4.1).
 *
 * remember_tokens und plans kaskadieren weg; sessions und workout_log bleiben
 * erhalten und verlieren nur ihre user_id. Die Zahl der betroffenen Einheiten
 * wird zurueckgemeldet, damit die Oberflaeche sie nennen kann.
 */
function aktion_loeschen(array $eingabe): never {
    $id = to_int_or_null($eingabe['id'] ?? null);
    if ($id === null) {
        json_err('Kein Benutzer angegeben.', 422);
    }

    $benutzer = benutzer_laden($id);

    if ($id === current_user_id()) {
        json_err(
            'Das eigene Konto lässt sich hier nicht löschen — das würde die '
            . 'laufende Sitzung mitten in der Arbeit beenden.',
            409
        );
    }

    if ((int)$benutzer['is_admin'] === 1 && admin_anzahl() <= 1) {
        json_err('Das ist der letzte Administrator und kann nicht gelöscht werden.', 409);
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM sessions WHERE user_id = ?');
    $stmt->execute([$id]);
    $einheiten = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

    json_ok(['deleted' => $id, 'sessions_kept' => $einheiten]);
}
