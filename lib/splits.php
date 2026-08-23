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
        'SELECT id, user_id, name, beschreibung, sort_order, vorlage_id, created_at
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
        'SELECT id, user_id, name, beschreibung, sort_order, vorlage_id
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
 * Mehrere Splits als reinen Text, geschluesselt nach split_id.
 *
 * Zweck ist das Einfuegen anderswo -- in einen Chat, eine Notiz, eine Mail.
 * Deshalb WIRKLICH nur Text: keine Aufzaehlungszeichen, keine Auszeichnung,
 * keine Bilder, keine Adressen. Was hier steht, soll in jedem Eingabefeld der
 * Welt gleich aussehen.
 *
 * Der Aufbau, und jede Zeile davon ist eine Entscheidung:
 *
 *     Push/Pull
 *
 *     Push
 *     1. Bankdruecken (Bench Press)
 *     2. Butterfly
 *
 *     Pull
 *     1. Klimmzuege (Pull-ups)
 *
 *   - Der SPLITNAME steht oben. Ohne ihn beginnt der Text mit einem Plannamen,
 *     und der Empfaenger sieht nicht, dass die Plaene zusammengehoeren.
 *   - LEERZEILEN trennen die Plaene, nichts sonst. Innerhalb eines Plans gibt
 *     es keine, damit die Trennung eindeutig bleibt.
 *   - Die NUMMERN tragen die Reihenfolge im Studio. Sie steht sonst nur in der
 *     Zeilenfolge, und die ueberlebt einen Umbruch beim Einfuegen nicht
 *     zuverlaessig.
 *   - Der englische Name steht in KLAMMERN und nicht hinter einem "·" wie in
 *     der Oberflaeche (uebung_name_kurz()). Das ist kein Widerspruch, sondern
 *     ein anderes Medium: In einer Liste aus Namen liest sich "Bankdruecken ·
 *     Bench Press" wie zwei Uebungen; die Klammer sagt "dasselbe, anders
 *     benannt". Fehlt der englische Name, entfaellt die Klammer ganz.
 *   - KEINE Ausfuehrung, keine Beschreibung, kein Geraet, keine Muskelgruppe.
 *     Der Text soll den AUFBAU zeigen; alles Weitere macht ihn laenger, ohne
 *     die Frage zu beantworten, die man damit stellt.
 *
 * Ein Plan ohne Uebungen und ein Split ohne Plan bekommen eine Zeile in
 * Klammern statt gar nichts: Zwei Plannamen direkt untereinander saehen sonst
 * nach einem Fehler in der Ausgabe aus.
 *
 * Drei Abfragen fuer beliebig viele Splits, aus demselben Grund wie bei
 * split_plan_namen() nebenan: Die Seite ruft das fuer JEDE Karte auf.
 *
 * @param int[] $splitIds
 * @return array<int, string> split_id => Text
 */
function split_texte(array $splitIds): array {
    $ids = array_values(array_unique(array_map('intval', $splitIds)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare("SELECT id, name FROM splits WHERE id IN ($platzhalter)");
    $stmt->execute($ids);
    $namen = [];
    foreach ($stmt->fetchAll() as $z) {
        $namen[(int)$z['id']] = (string)$z['name'];
    }

    $stmt = db()->prepare(
        "SELECT id, split_id, name FROM plans
          WHERE split_id IN ($platzhalter)
          ORDER BY split_id, sort_order, id"
    );
    $stmt->execute($ids);
    $plaene = $stmt->fetchAll();

    // Die Uebungen ALLER Plaene in einem Zug. Ueber die plan_id sortiert, damit
    // die Zuordnung unten ohne zweite Schleife auskommt.
    $uebungen = [];
    if ($plaene !== []) {
        $planIds = array_map(static fn(array $p): int => (int)$p['id'], $plaene);
        $pl      = implode(',', array_fill(0, count($planIds), '?'));
        $stmt = db()->prepare(
            "SELECT pe.plan_id, e.name_de, e.name_en
               FROM plan_exercises pe
               JOIN exercises e ON e.id = pe.exercise_id
              WHERE pe.plan_id IN ($pl)
              ORDER BY pe.plan_id, pe.sort_order, pe.id"
        );
        $stmt->execute($planIds);
        foreach ($stmt->fetchAll() as $z) {
            $uebungen[(int)$z['plan_id']][] = $z;
        }
    }

    $nachSplit = [];
    foreach ($plaene as $plan) {
        $nachSplit[(int)$plan['split_id']][] = $plan;
    }

    $ergebnis = [];
    foreach ($ids as $sid) {
        $abschnitte = [$namen[$sid] ?? ''];

        foreach ($nachSplit[$sid] ?? [] as $plan) {
            $zeilen = [(string)$plan['name']];
            $liste  = $uebungen[(int)$plan['id']] ?? [];

            if ($liste === []) {
                $zeilen[] = '(noch keine Übung)';
            } else {
                foreach ($liste as $i => $u) {
                    $name = (string)$u['name_de'];
                    $en   = (string)($u['name_en'] ?? '');
                    if (trim($en) !== '') {
                        $name .= ' (' . trim($en) . ')';
                    }
                    $zeilen[] = ($i + 1) . '. ' . $name;
                }
            }

            $abschnitte[] = implode("\n", $zeilen);
        }

        if (count($abschnitte) === 1) {
            $abschnitte[] = '(noch kein Plan)';
        }

        $ergebnis[$sid] = implode("\n\n", $abschnitte);
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
    return signaturen_bauen($splitIds, false);
}

/**
 * Fingerabdruck EINSCHLIESSLICH der Plannamen -- fuer den Vorlagenabgleich.
 *
 * Zwei Fingerabdruecke, zwei Fragen, und sie duerfen nicht vermischt werden:
 *
 *   split_signaturen()          "Ist das inhaltlich dasselbe Training?"
 *                               Steuert, was sich noch veroeffentlichen laesst
 *                               (benutzer_splits_ohne_vorlage()). Namen sind
 *                               dort bewusst DRAUSSEN: Wer eine Vorlage kopiert
 *                               und umbenennt, hat kein neues Training.
 *
 *   split_abgleich_signaturen() "Sieht meine Kopie noch aus wie die Vorlage?"
 *                               Steuert den Knopf "Auf Vorlage zurücksetzen".
 *                               Hier zaehlen die Plannamen MIT -- benennt der
 *                               Admin "Tag A" in "Push" um, ist das ein
 *                               Unterschied, den man uebernehmen koennen soll.
 *
 * Der Name des SPLITS bleibt in beiden draussen: Er gehoert dem Benutzer, der
 * ihn jederzeit aendern darf, und ein Knopf, der nur wegen einer eigenen
 * Umbenennung erscheint, waere eine Falschmeldung.
 *
 * @param int[] $splitIds
 * @return array<int, string> split_id => Fingerabdruck
 */
function split_abgleich_signaturen(array $splitIds): array {
    return signaturen_bauen($splitIds, true);
}

/**
 * Der gemeinsame Bau beider Fingerabdruecke.
 *
 * EINE Abfrage und eine Schleife fuer beide: Zwei getrennte Fassungen liefen
 * beim naechsten Eingriff auseinander, und dann meldete der eine Abgleich
 * "gleich", waehrend der andere "verschieden" sagt.
 *
 * @param int[] $splitIds
 * @return array<int, string>
 */
function signaturen_bauen(array $splitIds, bool $mitNamen): array {
    $ids = array_values(array_unique(array_map('intval', $splitIds)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));

    // LEFT JOIN, damit ein Plan OHNE Uebungen sein Segment behaelt.
    $stmt = db()->prepare(
        "SELECT p.split_id, p.id AS plan_id, p.name AS plan_name, pe.exercise_id
           FROM plans p
           LEFT JOIN plan_exercises pe ON pe.plan_id = p.id
          WHERE p.split_id IN ($platzhalter)
          ORDER BY p.split_id, p.sort_order, p.id, pe.sort_order, pe.id"
    );
    $stmt->execute($ids);

    $segmente = [];
    $namen    = [];
    foreach ($stmt->fetchAll() as $z) {
        $sid = (int)$z['split_id'];
        $pid = (int)$z['plan_id'];
        $segmente[$sid][$pid] ??= [];
        $namen[$sid][$pid]    ??= (string)$z['plan_name'];
        if ($z['exercise_id'] !== null) {
            $segmente[$sid][$pid][] = (int)$z['exercise_id'];
        }
    }

    $ergebnis = [];
    foreach ($ids as $sid) {
        $plaene = $segmente[$sid] ?? [];
        $teile  = [];
        foreach ($plaene as $pid => $uebungen) {
            // rawurlencode und nicht der nackte Name: Ein Plan, der '|' oder
            // '#' im Namen traegt, koennte sonst die Trennzeichen faelschen
            // und zwei verschiedene Splits gleich aussehen lassen.
            $teile[] = ($mitNamen ? rawurlencode($namen[$sid][$pid] ?? '') . '#' : '')
                . implode(',', $uebungen);
        }
        $ergebnis[$sid] = implode('|', $teile);
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

        // Woher stammt die neue Kopie? Drei Faelle, und der dritte ist der,
        // den man vergisst:
        //
        //   Ziel ist eine VORLAGE      -> keine Herkunft. Eine Vorlage hat
        //                                 keine Vorlage; das gilt fuer
        //                                 "Veroeffentlichen" wie fuer
        //                                 "Duplizieren" im Katalog.
        //   Quelle ist eine Vorlage    -> genau diese. Der Regelfall,
        //                                 "Zu mir kopieren".
        //   Quelle ist eine KOPIE      -> deren Herkunft wird geerbt. Wer
        //                                 seinen aus einer Vorlage gezogenen
        //                                 Split dupliziert, hat zwei Kopien
        //                                 derselben Vorlage -- beide duerfen
        //                                 sich zuruecksetzen lassen.
        $herkunft = null;
        if ($zielUserId !== null) {
            $herkunft = $quelle['user_id'] === null
                ? $quelleId
                : to_int_or_null($quelle['vorlage_id'] ?? null);
        }

        db()->prepare(
            'INSERT INTO splits (user_id, name, beschreibung, sort_order, vorlage_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$zielUserId, $zielName, $quelle['beschreibung'], $position, $herkunft, $zeit]);

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
 * Herkunft und Abweichung persoenlicher Splits (§6.4, seit 1.2.11).
 *
 * Liefert nur Zeilen fuer Splits, die eine Vorlage haben UND deren Vorlage es
 * noch gibt. Der JOIN ist zugleich die Absicherung gegen eine verwaiste
 * Herkunft: Auf einer Bestandsdatenbank ist vorlage_id eine gewoehnliche
 * Spalte ohne Fremdschluessel (SQLite kann keinen nachtragen, siehe
 * apply_migrations()) -- eine geloeschte Vorlage laesst dort eine tote ID
 * zurueck. Ohne Treffer im JOIN gilt sie als "keine Herkunft", und der Knopf
 * erscheint nicht.
 *
 * @param int[] $splitIds
 * @return array<int, array{vorlage_id:int, vorlage_name:string, weicht_ab:bool}>
 */
function vorlage_stand(array $splitIds): array {
    $ids = array_values(array_unique(array_map('intval', $splitIds)));
    if ($ids === []) {
        return [];
    }

    $platzhalter = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT s.id, s.vorlage_id, v.name AS vorlage_name
           FROM splits s
           JOIN splits v ON v.id = s.vorlage_id AND v.user_id IS NULL
          WHERE s.id IN ($platzhalter) AND s.user_id IS NOT NULL"
    );
    $stmt->execute($ids);
    $zeilen = $stmt->fetchAll();

    if ($zeilen === []) {
        return [];
    }

    // Beide Seiten in EINEM Aufruf: Der Fingerabdruck kostet eine Abfrage,
    // und die Splitseite zeigt bis zu einem Dutzend Karten.
    $signaturen = split_abgleich_signaturen(array_merge(
        array_column($zeilen, 'id'),
        array_column($zeilen, 'vorlage_id')
    ));

    $ergebnis = [];
    foreach ($zeilen as $z) {
        $id  = (int)$z['id'];
        $vid = (int)$z['vorlage_id'];

        $ergebnis[$id] = [
            'vorlage_id'   => $vid,
            'vorlage_name' => (string)$z['vorlage_name'],
            'weicht_ab'    => ($signaturen[$id] ?? '') !== ($signaturen[$vid] ?? ''),
        ];
    }

    return $ergebnis;
}

/**
 * Ordnet einem persoenlichen Split seine Vorlage zu -- oder loest die Zuordnung.
 *
 * Gebraucht wird das, weil die Herkunft erst seit 1.2.11 mitgeschrieben wird:
 * Jeder Split, der vorher entstand, kennt seine Vorlage nicht, und gerade die
 * bestehenden sind die, an denen der Abgleich nuetzt. Raten waere hier falsch
 * -- ein Fingerabdruck passt nur, solange nichts geaendert wurde, und wer
 * nichts geaendert hat, braucht den Knopf nicht.
 *
 * NUR VORLAGEN sind zulaessig. Ein persoenlicher Split als "Vorlage" eines
 * anderen waere eine zweite Art von Beziehung mit eigenen Fragen (Wer darf sie
 * lesen? Was passiert beim Loeschen des Besitzers?), und §6.4 kennt genau eine.
 */
function split_vorlage_setzen(int $splitId, ?int $vorlageId): void {
    $split = split_laden($splitId);
    if ($split === null) {
        throw new RuntimeException('Diesen Split gibt es nicht.');
    }
    if ($split['user_id'] === null) {
        throw new RuntimeException('Eine Vorlage hat selbst keine Vorlage.');
    }

    if ($vorlageId !== null) {
        $vorlage = split_laden($vorlageId);
        if ($vorlage === null || $vorlage['user_id'] !== null) {
            throw new RuntimeException('Diese Vorlage gibt es nicht.');
        }
    }

    db()->prepare('UPDATE splits SET vorlage_id = ? WHERE id = ?')
        ->execute([$vorlageId, $splitId]);
}

/**
 * Bringt einen persoenlichen Split auf den Stand seiner Vorlage (§6.4).
 *
 * DIE EINE STELLE, an der die Vorlage auf eine Kopie zurueckwirkt -- und nur,
 * weil der Benutzer den Knopf drueckt. Es gibt weiterhin keine Vererbung und
 * kein automatisches Nachziehen (Fallstrick 24).
 *
 * ABGEGLICHEN WIRD, NICHT NEU ANGELEGT, und das ist der wichtigste Teil dieser
 * Funktion. plan_exercises.id haengt an workout_log.plan_exercise_id mit
 * ON DELETE SET NULL: Ein "alles loeschen und aus der Vorlage neu schreiben"
 * loeste JEDE protokollierte Uebung dieses Splits von ihrer Position --
 * lautlos, mit ok:true, sichtbar erst Wochen spaeter im Verlauf. Das ist
 * genau der Fehler, vor dem Fallstrick 4 warnt.
 *
 * Vorhandene Zeilen werden deshalb WIEDERVERWENDET, und zwar splitweit: Hat
 * die Vorlage eine Uebung von "Tag A" nach "Tag B" verschoben, wandert die
 * bestehende Zeile mit (UPDATE plan_id), statt zu verschwinden und neu zu
 * entstehen. Nur was in der Vorlage wirklich nicht mehr vorkommt, wird
 * geloescht -- und dessen Protokollzeilen verlieren ihren Bezug. Das ist
 * unvermeidbar und steht deshalb in der Rueckfrage der Oberflaeche.
 *
 * Der NAME DES SPLITS bleibt unberuehrt: Er gehoert dem Benutzer. Plannamen,
 * Reihenfolge, Uebungen und die Beschreibung kommen von der Vorlage.
 *
 * @return array{plaene: string[], hinzugefuegt: int, entfernt: int, verschoben: int}
 */
function split_zuruecksetzen(int $splitId): array {
    $split = split_laden($splitId);
    if ($split === null || $split['user_id'] === null) {
        throw new RuntimeException('Diesen Split gibt es nicht.');
    }

    $vorlageId = to_int_or_null($split['vorlage_id'] ?? null);
    $vorlage   = $vorlageId === null ? null : split_laden($vorlageId);
    if ($vorlage === null || $vorlage['user_id'] !== null) {
        throw new RuntimeException('Zu diesem Split ist keine Vorlage hinterlegt.');
    }

    $ziel  = split_aufbau($vorlageId);
    $istPlaene = db()->prepare(
        'SELECT id, name, sort_order FROM plans WHERE split_id = ? ORDER BY sort_order, id'
    );
    $istPlaene->execute([$splitId]);
    $ist = $istPlaene->fetchAll();

    $userId = (int)$split['user_id'];
    $zeit   = now();

    $hinzugefuegt = 0;
    $entfernt     = 0;
    $verschoben   = 0;

    db()->beginTransaction();
    try {
        // --- 1. Plaene paaren: nach Reihenfolge, nicht nach Namen ---------
        // Ein umbenannter Plan ist derselbe Plan an derselben Stelle. Ueber
        // die Namen zu paaren hiesse, ihn zu loeschen und neu anzulegen --
        // und damit die Historie seiner Positionen zu kappen.
        $planIds   = [];
        $ueberzaehlig = [];
        foreach ($ist as $i => $alt) {
            if (isset($ziel[$i])) {
                $planIds[$i] = (int)$alt['id'];
            } else {
                $ueberzaehlig[] = (int)$alt['id'];
            }
        }

        $planAnlegen = db()->prepare(
            'INSERT INTO plans (user_id, split_id, name, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $planSchreiben = db()->prepare(
            'UPDATE plans SET name = ?, sort_order = ? WHERE id = ?'
        );

        foreach ($ziel as $i => $z) {
            if (isset($planIds[$i])) {
                $planSchreiben->execute([$z['name'], $z['sort_order'], $planIds[$i]]);
            } else {
                $planAnlegen->execute([
                    $userId, $splitId, $z['name'], $z['sort_order'], $zeit,
                ]);
                $planIds[$i] = (int)db()->lastInsertId();
            }
        }

        // --- 2. Der Vorrat vorhandener Positionen, splitweit ---------------
        // Geschluesselt nach Uebung, damit eine Zeile auch dann wiederverwendet
        // wird, wenn die Vorlage sie in einen anderen Plan verschoben hat.
        $vorratLesen = db()->prepare(
            'SELECT pe.id, pe.plan_id, pe.exercise_id
               FROM plan_exercises pe
               JOIN plans p ON p.id = pe.plan_id
              WHERE p.split_id = ?
              ORDER BY pe.sort_order, pe.id'
        );
        $vorratLesen->execute([$splitId]);

        $vorrat = [];
        foreach ($vorratLesen->fetchAll() as $z) {
            $vorrat[(int)$z['exercise_id']][] = [
                'id'      => (int)$z['id'],
                'plan_id' => (int)$z['plan_id'],
            ];
        }

        $positionSchreiben = db()->prepare(
            'UPDATE plan_exercises SET plan_id = ?, sort_order = ? WHERE id = ?'
        );
        $positionAnlegen = db()->prepare(
            'INSERT INTO plan_exercises (plan_id, exercise_id, sort_order) VALUES (?, ?, ?)'
        );

        foreach ($ziel as $i => $z) {
            $planId = $planIds[$i];
            foreach ($z['uebungen'] as $j => $exerciseId) {
                $nr = $j + 1;

                // Eine Zeile aus demselben Plan bevorzugen -- sie bleibt dann
                // ganz stehen und aendert nur ihre Nummer.
                $wahl = null;
                foreach ($vorrat[$exerciseId] ?? [] as $k => $kandidat) {
                    if ($wahl === null || $kandidat['plan_id'] === $planId) {
                        $wahl = $k;
                    }
                    if ($kandidat['plan_id'] === $planId) {
                        break;
                    }
                }

                if ($wahl === null) {
                    $positionAnlegen->execute([$planId, $exerciseId, $nr]);
                    $hinzugefuegt++;
                    continue;
                }

                $zeile = $vorrat[$exerciseId][$wahl];
                unset($vorrat[$exerciseId][$wahl]);

                if ($zeile['plan_id'] !== $planId) {
                    $verschoben++;
                }
                $positionSchreiben->execute([$planId, $nr, $zeile['id']]);
            }
        }

        // --- 3. Was uebrig blieb, kommt in der Vorlage nicht mehr vor ------
        $positionLoeschen = db()->prepare('DELETE FROM plan_exercises WHERE id = ?');
        foreach ($vorrat as $zeilen) {
            foreach ($zeilen as $zeile) {
                $positionLoeschen->execute([$zeile['id']]);
                $entfernt++;
            }
        }

        // --- 4. Erst jetzt die ueberzaehligen Plaene ----------------------
        // Nach dem Vorrat, damit eine Uebung aus einem wegfallenden Plan
        // vorher in einen bleibenden umziehen konnte (Fallstrick 4).
        $planLoeschen = db()->prepare('DELETE FROM plans WHERE id = ? AND split_id = ?');
        foreach ($ueberzaehlig as $planId) {
            $planLoeschen->execute([$planId, $splitId]);
        }

        db()->prepare('UPDATE splits SET beschreibung = ? WHERE id = ?')
            ->execute([$vorlage['beschreibung'], $splitId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return [
        'plaene'       => array_column($ziel, 'name'),
        'hinzugefuegt' => $hinzugefuegt,
        'entfernt'     => $entfernt,
        'verschoben'   => $verschoben,
    ];
}

/**
 * Der Aufbau eines Splits als einfache Liste -- Plaene in Reihenfolge, je Plan
 * die Uebungs-IDs in Reihenfolge.
 *
 * @return list<array{name:string, sort_order:int, uebungen:int[]}>
 */
function split_aufbau(int $splitId): array {
    $stmt = db()->prepare(
        'SELECT p.id, p.name, p.sort_order, pe.exercise_id
           FROM plans p
           LEFT JOIN plan_exercises pe ON pe.plan_id = p.id
          WHERE p.split_id = ?
          ORDER BY p.sort_order, p.id, pe.sort_order, pe.id'
    );
    $stmt->execute([$splitId]);

    $plaene = [];
    foreach ($stmt->fetchAll() as $z) {
        $pid = (int)$z['id'];
        $plaene[$pid] ??= [
            'name'       => (string)$z['name'],
            'sort_order' => (int)$z['sort_order'],
            'uebungen'   => [],
        ];
        if ($z['exercise_id'] !== null) {
            $plaene[$pid]['uebungen'][] = (int)$z['exercise_id'];
        }
    }

    return array_values($plaene);
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

/**
 * Traegt in jede Zeile ein, in welchen ANDEREN Plaenen desselben Splits die
 * Uebung schon steht (§6.4).
 *
 * Es ist eine Auskunft und kein Verbot: Dieselbe Uebung darf bewusst in
 * mehreren Plaenen stehen. Wer aber "Ganzkoerper B" fuellt und nicht zweimal
 * dasselbe trainieren will, sieht damit ohne Umschalten, was schon in
 * "Ganzkoerper A" steht.
 *
 * HIER und nicht in den Endpunkten, weil drei Listen dieselbe Frage stellen:
 * die Uebungsauswahl beim Hinzufuegen und BEIDE Tauschfenster -- das der
 * Planverwaltung (api/plans.php) und das im Training (api/swap.php). Dreimal
 * geschrieben waere es dreimal zu pflegen, und die dritte Stelle wuerde
 * vergessen; genau das ist mit dem zweisprachigen Uebungsnamen passiert
 * (Fallstrick 27).
 *
 * Abgefragt wird der ganze Split und nicht die uebergebenen Zeilen: Ein Split
 * hat eine Handvoll Plaene mit ein paar Dutzend Positionen, die Trefferliste
 * der Uebungsauswahl dagegen kann der gesamte Uebungsbestand sein.
 *
 * @param array<int,array> $zeilen     Zeilen mit 'id' = exercise_id
 * @param int              $ohnePlanId Der Plan, um den es gerade geht -- ueber
 *                                     ihn sagt schon der Knopf alles
 * @return array<int,array> dieselben Zeilen mit zusaetzlichem 'andere_plaene'
 */
function andere_plaene_eintragen(array $zeilen, int $splitId, int $ohnePlanId): array {
    if ($zeilen === []) {
        return $zeilen;
    }

    // GROUP BY statt DISTINCT: Steht eine Uebung zweimal im selben Plan, soll
    // der Planname trotzdem nur einmal erscheinen -- und DISTINCT vertruege
    // sich nicht mit dem ORDER BY ueber Spalten, die nicht in der
    // Ergebnismenge stehen.
    $stmt = db()->prepare(
        'SELECT pe.exercise_id, p.name
           FROM plan_exercises pe
           JOIN plans p ON p.id = pe.plan_id
          WHERE p.split_id = ? AND p.id != ?
          GROUP BY pe.exercise_id, p.id
          ORDER BY p.sort_order, p.id'
    );
    $stmt->execute([$splitId, $ohnePlanId]);

    $namen = [];
    foreach ($stmt as $z) {
        $namen[(int)$z['exercise_id']][] = $z['name'];
    }

    foreach ($zeilen as &$zeile) {
        $zeile['andere_plaene'] = $namen[(int)$zeile['id']] ?? [];
    }
    unset($zeile);

    return $zeilen;
}
