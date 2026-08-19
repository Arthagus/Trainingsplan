<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Workout-Splits: der Katalog der Vorlagen und die persoenlichen Kopien.
 *
 * Ein Split buendelt die Plaene, die miteinander rotieren -- "Push / Pull",
 * "Ganzkoerper", "Upper / Lower / Push / Pull / Legs" (§6.4, §7.6). Die
 * Rotation laeuft je Split getrennt; welcher gerade gilt, steht in
 * users.active_split_id.
 *
 * ZWEI ARTEN, kein dritter Zustand (siehe schema.sql):
 *
 *   user_id IS NULL   VORLAGE     Katalog. Alle sehen sie, nur Admins
 *                                 bearbeiten sie, NIEMAND trainiert darauf.
 *   user_id = X       PERSOENLICH Nur X (und Admins) sehen und bearbeiten
 *                                 ihn, und NUR darauf wird trainiert.
 *
 * Dazwischen gibt es genau eine Verbindung, und die ist eine Kopie:
 * split_kopieren(). Kein Verweis, keine Vererbung, kein Abgleich. Das ist die
 * fachliche Kernzusage -- zwei Benutzer duerfen denselben Split fahren, ohne
 * sich gegenseitig in den Bestand zu schreiben, und eine spaetere Aenderung
 * an der Vorlage laesst bestehende Kopien ausdruecklich unberuehrt. Wer den
 * neuen Stand will, zieht eine zweite Kopie und unterscheidet sie am Namen.
 *
 * Dass NIEMAND auf einer Vorlage trainiert, ist keine Frage der Oberflaeche:
 * Der dauerhafte Tausch schreibt in plan_exercises (§7.5), und das waere sonst
 * ein Schreibzugriff auf fremden Bestand. Durchgesetzt wird es serverseitig in
 * api/session.php, api/log.php und api/swap.php -- ueber split_von_plan().
 */

const SPLIT_NAME_MAX = 80;

/**
 * Ein Split samt seiner Art. Null, wenn es ihn nicht gibt.
 */
function split_laden(int $splitId): ?array {
    $stmt = db()->prepare(
        'SELECT id, user_id, name, beschreibung, sort_order, created_at
           FROM splits WHERE id = ?'
    );
    $stmt->execute([$splitId]);
    $zeile = $stmt->fetch();

    return $zeile === false ? null : $zeile;
}

/**
 * Die persoenlichen Splits eines Benutzers, in seiner Sortierung.
 */
function splits_von(int $userId): array {
    $stmt = db()->prepare(
        'SELECT id, user_id, name, beschreibung, sort_order
           FROM splits WHERE user_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

/**
 * Der Vorlagen-Katalog -- fuer jeden Benutzer derselbe (§6.4).
 */
function vorlagen(): array {
    return db()->query(
        'SELECT id, user_id, name, beschreibung, sort_order
           FROM splits WHERE user_id IS NULL ORDER BY sort_order, id'
    )->fetchAll();
}

/**
 * Die Plan-Namen mehrerer Splits auf einmal, geschluesselt nach split_id.
 *
 * Fuer die Vorschau im Katalog: Ohne die Plaene ist ein Splitname eine leere
 * Behauptung -- man waehlt ihn danach aus, was drinsteht.
 */
function split_plan_namen(array $splitIds): array {
    $ids = array_values(array_unique(array_map('intval', $splitIds)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT split_id, name FROM plans
          WHERE split_id IN ($platzhalter)
          ORDER BY sort_order, id"
    );
    $stmt->execute($ids);

    $ergebnis = [];
    foreach ($stmt->fetchAll() as $zeile) {
        $ergebnis[(int)$zeile['split_id']][] = (string)$zeile['name'];
    }

    return $ergebnis;
}

/**
 * Die inhaltliche Signatur mehrerer Splits -- OHNE jeden Namen.
 *
 * Sie beantwortet genau eine Frage: Beschreiben zwei Splits dasselbe Training?
 * Verglichen wird die Reihenfolge der Plaene und darin die Reihenfolge der
 * Uebungen, sonst nichts. Namen bleiben ausdruecklich aussen vor -- weder der
 * des Splits noch die der Plaene.
 *
 * Das ist eine Entscheidung und kein Versehen: Wer eine Vorlage kopiert und
 * sie "Mein Push/Pull" nennt, hat kein neues Trainingskonzept, sondern
 * dasselbe mit eigener Beschriftung. Waere der Name Teil des Vergleichs,
 * boete der Katalog ihn zum Veroeffentlichen an und fuellte sich mit
 * Dubletten. Sobald jemand eine Uebung tauscht, faellt die Signatur
 * auseinander und der Split ist wieder da -- dann ist es eine echte Variante.
 *
 * Das Trennzeichen ist bewusst zweistufig: "|" zwischen den Plaenen, ","
 * zwischen den Uebungen. Ein Plan ohne Uebungen ergibt ein leeres Segment und
 * bleibt damit zaehlbar -- zwei leere Plaene ("|") sind etwas anderes als
 * gar kein Plan ("").
 *
 * @param int[] $splitIds
 * @return array<int, string> split_id => Signatur
 */
function split_signaturen(array $splitIds): array {
    $ids = array_values(array_unique(array_map('intval', $splitIds)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));

    // LEFT JOIN, damit ein Plan OHNE Uebungen sein Segment behaelt.
    $stmt = db()->prepare(
        "SELECT p.split_id, p.id AS plan_id, pe.exercise_id
           FROM plans p
           LEFT JOIN plan_exercises pe ON pe.plan_id = p.id
          WHERE p.split_id IN ($platzhalter)
          ORDER BY p.split_id, p.sort_order, p.id, pe.sort_order, pe.id"
    );
    $stmt->execute($ids);

    $segmente = [];
    foreach ($stmt->fetchAll() as $z) {
        $sid = (int)$z['split_id'];
        $pid = (int)$z['plan_id'];
        $segmente[$sid][$pid] ??= [];
        if ($z['exercise_id'] !== null) {
            $segmente[$sid][$pid][] = (int)$z['exercise_id'];
        }
    }

    $ergebnis = [];
    foreach ($ids as $sid) {
        $plaene = $segmente[$sid] ?? [];
        $ergebnis[$sid] = implode('|', array_map(
            static fn(array $uebungen): string => implode(',', $uebungen),
            $plaene
        ));
    }

    return $ergebnis;
}

/**
 * Alle persoenlichen Splits ALLER Benutzer, die noch keiner Vorlage entsprechen.
 *
 * Das ist die Liste, aus der ein Admin auf splits.php eine Vorlage macht
 * (§6.4). Sie enthaelt bewusst auch die eigenen Splits des Admins: Das
 * Veroeffentlichen liegt damit an genau EINER Stelle, statt einmal hier und
 * einmal als Knopf an der eigenen Karte.
 *
 * Zwei Dinge fallen heraus:
 *
 *   - Was inhaltlich schon im Katalog steht (split_signaturen()). Es ein
 *     zweites Mal zu veroeffentlichen erzeugte nur eine Dublette, zwischen
 *     der niemand mehr waehlen kann.
 *   - Splits OHNE jeden Plan. An ihnen ist nichts zu veroeffentlichen; sie
 *     stuenden nur im Weg. (Ein Split mit leeren Plaenen bleibt dagegen
 *     drin -- die Plaene und ihre Reihenfolge sind bereits eine Aussage.)
 *
 * @return array<int, array> Split-Zeilen samt 'besitzer' und 'plan_anzahl'
 */
function benutzer_splits_ohne_vorlage(): array {
    $kandidaten = db()->query(
        'SELECT sp.id, sp.user_id, sp.name, sp.beschreibung, sp.sort_order,
                u.name AS besitzer,
                (SELECT COUNT(*) FROM plans p WHERE p.split_id = sp.id) AS plan_anzahl
           FROM splits sp
           JOIN users u ON u.id = sp.user_id
          WHERE sp.user_id IS NOT NULL
          ORDER BY u.name COLLATE NOCASE, sp.sort_order, sp.id'
    )->fetchAll();

    $vorlagenIds = array_map(
        static fn(array $v): int => (int)$v['id'],
        vorlagen()
    );

    $bekannt = array_flip(array_values(split_signaturen($vorlagenIds)));
    $eigene  = split_signaturen(array_column($kandidaten, 'id'));

    $ergebnis = [];
    foreach ($kandidaten as $sp) {
        if ((int)$sp['plan_anzahl'] === 0) {
            continue;
        }
        if (isset($bekannt[$eigene[(int)$sp['id']] ?? ''])) {
            continue;
        }
        $ergebnis[] = $sp;
    }

    return $ergebnis;
}

/**
 * Der Split, zu dem ein Plan gehoert -- der Traeger jeder Besitzpruefung.
 *
 * Seit 1.2.0 entscheidet allein splits.user_id, wem ein Plan gehoert;
 * plans.user_id ist tot (siehe schema.sql). Wer hier vorbeigeht und weiterhin
 * plans.user_id vergleicht, laesst eine Vorlage wie einen fremden Plan
 * aussehen -- oder, schlimmer, wie einen eigenen.
 *
 * @return array|null {split_id, split_user_id, plan_name} oder null
 */
function split_von_plan(int $planId): ?array {
    $stmt = db()->prepare(
        'SELECT sp.id AS split_id, sp.user_id AS split_user_id, p.name AS plan_name
           FROM plans p
           JOIN splits sp ON sp.id = p.split_id
          WHERE p.id = ?'
    );
    $stmt->execute([$planId]);
    $zeile = $stmt->fetch();

    return $zeile === false ? null : $zeile;
}

/**
 * Gehoert dieser Plan dem Benutzer -- darf er also darauf trainieren?
 *
 * Eine Vorlage (split_user_id IS NULL) ergibt hier IMMER false, auch fuer
 * einen Admin. Das ist der Unterschied zwischen bearbeiten und trainieren:
 * Der Admin pflegt den Katalog, aber seine Einheiten laufen auf seiner
 * eigenen Kopie -- sonst schriebe sein Training in den Bestand aller.
 */
function plan_gehoert(int $planId, int $userId): bool {
    $split = split_von_plan($planId);

    return $split !== null
        && $split['split_user_id'] !== null
        && (int)$split['split_user_id'] === $userId;
}

/**
 * Der gerade gewaehlte Split (§7.6), oder null, wenn der Benutzer noch keinen hat.
 *
 * users.active_split_id ist eine AUSWAHL und keine ableitbare Tatsache --
 * deshalb darf sie als Spalte stehen, anders als users.last_plan_id
 * (Fallstrick 21). Sie bekommt trotzdem einen Rueckfallweg: Nach dem Loeschen
 * des gewaehlten Splits raeumt ON DELETE SET NULL auf, und dann ist der Split
 * der zuletzt begonnenen Einheit die richtige Antwort, sonst der erste eigene.
 *
 * Der Rueckfall wird gleich FESTGESCHRIEBEN. Ohne das haenge die Anzeige an
 * einer Ableitung, die sich beim naechsten Training von selbst verschoebe --
 * man waehlte nie etwas aus und bekaeme trotzdem einen Wechsel.
 */
function aktiver_split(int $userId): ?array {
    $stmt = db()->prepare(
        'SELECT sp.id, sp.user_id, sp.name, sp.beschreibung, sp.sort_order
           FROM users u
           JOIN splits sp ON sp.id = u.active_split_id
          WHERE u.id = ? AND sp.user_id = u.id'
    );
    $stmt->execute([$userId]);
    $zeile = $stmt->fetch();
    if ($zeile !== false) {
        return $zeile;
    }

    $stmt = db()->prepare(
        'SELECT p.split_id
           FROM sessions s
           JOIN plans p ON p.id = s.plan_id
           JOIN splits sp ON sp.id = p.split_id
          WHERE s.user_id = ? AND sp.user_id = ?
          ORDER BY s.started_at DESC, s.id DESC
          LIMIT 1'
    );
    $stmt->execute([$userId, $userId]);
    $splitId = to_int_or_null($stmt->fetchColumn());

    if ($splitId === null) {
        $eigene = splits_von($userId);
        if ($eigene === []) {
            return null;
        }
        $splitId = (int)$eigene[0]['id'];
    }

    aktiven_split_setzen($userId, $splitId);

    return split_laden($splitId);
}

/**
 * Schreibt die Auswahl fest.
 *
 * Aufrufer: der Wechsel in api/splits.php, das Kopieren einer Vorlage (man
 * holt sie sich, um sie zu benutzen) und der Start einer Einheit -- letzteres,
 * damit die Auswahl nicht hinter der Wirklichkeit zurueckbleibt.
 */
function aktiven_split_setzen(int $userId, ?int $splitId): void {
    db()->prepare('UPDATE users SET active_split_id = ? WHERE id = ?')
        ->execute([$splitId, $userId]);
}

/**
 * Macht einen Namen beim Ziel eindeutig, indem er " (2)", " (3)" anhaengt.
 *
 * Es gibt bewusst KEIN UNIQUE auf splits(name): Zwei Benutzer duerfen denselben
 * Splitnamen haben, und derselbe Benutzer soll mehrere Fassungen einer Vorlage
 * nebeneinander halten koennen -- genau dafuer ist das Kopieren da, wenn sich
 * die Vorlage inzwischen geaendert hat. Ein automatisch angehaengter Zaehler
 * ist deshalb Bequemlichkeit und keine Regel; umbenennen darf man sofort.
 */
function split_name_frei(?int $zielUserId, string $name): string {
    $stmt = $zielUserId === null
        ? db()->prepare('SELECT COUNT(*) FROM splits WHERE user_id IS NULL AND name = ?')
        : db()->prepare('SELECT COUNT(*) FROM splits WHERE user_id = ? AND name = ?');

    $frage = static function (string $kandidat) use ($stmt, $zielUserId): bool {
        $stmt->execute($zielUserId === null ? [$kandidat] : [$zielUserId, $kandidat]);
        return (int)$stmt->fetchColumn() === 0;
    };

    if ($frage($name)) {
        return $name;
    }

    // Der Anhaenger darf den Namen nicht ueber die Laenge schieben.
    $stamm = $name;
    for ($n = 2; $n < 100; $n++) {
        $anhang = ' (' . $n . ')';
        $kandidat = str_cut($stamm, SPLIT_NAME_MAX - str_len_utf8($anhang)) . $anhang;
        if ($frage($kandidat)) {
            return $kandidat;
        }
    }

    return str_cut($stamm, SPLIT_NAME_MAX - 12) . ' ' . substr((string)time(), -6);
}

/**
 * Kopiert einen Split samt Plaenen und Positionen.
 *
 * EINE Funktion fuer vier Knoepfe, und das ist Absicht -- die Faelle
 * unterscheiden sich allein im Ziel:
 *
 *   Vorlage  -> Benutzer   "Zu mir kopieren"
 *   Benutzer -> Vorlage    "Als Vorlage uebernehmen" (nur Admin)
 *   Benutzer -> derselbe   "Duplizieren"
 *   Vorlage  -> Vorlage    "Duplizieren" im Katalog (nur Admin, seit 1.2.3)
 *
 * In EINER Transaktion, sonst bliebe bei einem Abbruch ein halber Split
 * stehen -- ein Split mit zwei von drei Plaenen sieht aus wie ein Bestand und
 * ist keiner.
 *
 * sort_order wird WOERTLICH uebernommen und nicht neu vergeben: Bei den
 * Plaenen ist sie die Rotationsreihenfolge (§6.4), bei den Positionen die
 * Reihenfolge im Studio. Eine Kopie, die anders herum laeuft, waere keine.
 *
 * plans.user_id wird mitgeschrieben, weil die Spalte NOT NULL ist -- sie ist
 * tot und entscheidet nichts (siehe schema.sql). Bei einer Vorlage steht dort
 * der handelnde Admin.
 *
 * @param int|null $zielUserId null = als Vorlage anlegen
 * @param int      $handelnder Fuer die tote Spalte plans.user_id
 */
function split_kopieren(
    int $quelleId,
    ?int $zielUserId,
    int $handelnder,
    ?string $name = null
): int {
    $quelle = split_laden($quelleId);
    if ($quelle === null) {
        throw new RuntimeException('Diesen Split gibt es nicht.');
    }

    $zielName = split_name_frei($zielUserId, $name ?? (string)$quelle['name']);
    $zeit     = now();

    db()->beginTransaction();
    try {
        $stmt = $zielUserId === null
            ? db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM splits WHERE user_id IS NULL')
            : db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM splits WHERE user_id = ?');
        $stmt->execute($zielUserId === null ? [] : [$zielUserId]);
        $position = (int)$stmt->fetchColumn() + 1;

        db()->prepare(
            'INSERT INTO splits (user_id, name, beschreibung, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$zielUserId, $zielName, $quelle['beschreibung'], $position, $zeit]);

        $neuerSplit = (int)db()->lastInsertId();

        $plaene = db()->prepare(
            'SELECT id, name, sort_order FROM plans WHERE split_id = ? ORDER BY sort_order, id'
        );
        $plaene->execute([$quelleId]);

        $planEinfuegen = db()->prepare(
            'INSERT INTO plans (user_id, split_id, name, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $positionenLesen = db()->prepare(
            'SELECT exercise_id, sort_order FROM plan_exercises
              WHERE plan_id = ? ORDER BY sort_order, id'
        );
        $positionSchreiben = db()->prepare(
            'INSERT INTO plan_exercises (plan_id, exercise_id, sort_order) VALUES (?, ?, ?)'
        );

        foreach ($plaene->fetchAll() as $plan) {
            $planEinfuegen->execute([
                $handelnder, $neuerSplit, $plan['name'], (int)$plan['sort_order'], $zeit,
            ]);
            $neuerPlan = (int)db()->lastInsertId();

            $positionenLesen->execute([(int)$plan['id']]);
            foreach ($positionenLesen->fetchAll() as $pos) {
                $positionSchreiben->execute([
                    $neuerPlan, (int)$pos['exercise_id'], (int)$pos['sort_order'],
                ]);
            }
        }

        db()->commit();

        return $neuerSplit;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/**
 * Prueft den Splitnamen. Eine Stelle fuer alle Aufrufer, aus demselben Grund
 * wie benutzername_pruefen() in lib/auth.php: Ein Name, den der eine Weg
 * zulaesst und der andere ablehnt, ist nicht erklaerbar.
 */
function split_name_pruefen(mixed $wert): string {
    $name = trim(to_str($wert));

    if ($name === '') {
        json_err('Bitte einen Namen eingeben.', 422, ['name' => 'Pflichtfeld.']);
    }
    if (str_len_utf8($name) > SPLIT_NAME_MAX) {
        json_err('Der Name ist zu lang.', 422, [
            'name' => 'Höchstens ' . SPLIT_NAME_MAX . ' Zeichen.',
        ]);
    }

    return $name;
}

/**
 * Laedt einen Split und stellt sicher, dass der Angemeldete ihn BEARBEITEN darf.
 *
 * EINE Funktion, und das ist der Kern der Rechtepruefung seit 1.2.0: Bis dahin
 * war api/plans.php vollstaendig admin-only und pruefte deshalb ueberhaupt
 * keinen Besitzer. Jetzt duerfen Benutzer ihre eigenen Splits bearbeiten, und
 * damit braucht JEDE Aktion eine Pruefung. Zehn einzeln geschriebene waeren
 * neun Gelegenheiten, eine zu vergessen.
 *
 * Die Regel in einem Satz: Der Eigentuemer darf, ein Admin darf alles, und
 * eine Vorlage darf nur ein Admin anfassen.
 */
function split_zugriff_api(int $splitId): array {
    $split = split_laden($splitId);
    if ($split === null) {
        json_err('Diesen Split gibt es nicht.', 404);
    }

    if ($split['user_id'] === null) {
        if (!is_admin()) {
            json_err('Vorlagen bearbeitet nur ein Administrator.', 403);
        }
        return $split;
    }

    if ((int)$split['user_id'] !== current_user_id() && !is_admin()) {
        json_err('Kein Zugriff auf diesen Split.', 403);
    }

    return $split;
}

/**
 * Dasselbe fuer eine Planposition: erst der Plan, dann sein Split.
 */
function split_zugriff_ueber_plan_api(int $planId): array {
    $split = split_von_plan($planId);
    if ($split === null) {
        json_err('Diesen Plan gibt es nicht.', 404);
    }

    return split_zugriff_api((int)$split['split_id']);
}
