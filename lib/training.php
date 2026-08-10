<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Trainingslogik: Plan-Rotation, offene Einheiten, Vorbelegung von Gewichten.
 *
 * Eigene Datei und nicht in helpers.php, weil hier die Fachlichkeit aus §7
 * liegt -- sie wird sowohl von der Handy-Ansicht als auch vom Adminbereich
 * gebraucht (dort fuer die Vorschau der Rotation).
 */

/**
 * Alle Plaene eines Benutzers in Rotationsreihenfolge.
 */
function plaene_von(int $userId): array {
    $stmt = db()->prepare(
        'SELECT id, name, sort_order FROM plans WHERE user_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Der Plan, der als naechstes vorgeschlagen wird (§7.6).
 *
 * Die Regel ist eine Rotation entlang der Sortierreihenfolge: vorgeschlagen
 * wird der Plan NACH dem zuletzt trainierten, zyklisch. Bei zwei Plaenen
 * ergibt das genau die fruehere Alternation ("der jeweils andere"), bei
 * Push/Pull/Legs laeuft sie der Reihe nach durch.
 *
 * Kein Rotationszaehler noetig: die Position von last_plan_id in der
 * Reihenfolge bestimmt den Nachfolger eindeutig. Ist sie leer oder zeigt auf
 * einen geloeschten Plan, faengt die Rotation vorne an.
 *
 * @param array|null $plaene Bereits geladene Plaene, spart eine Abfrage
 * @return array|null Der vorgeschlagene Plan oder null, wenn keiner existiert
 */
function naechster_plan(int $userId, ?int $lastPlanId, ?array $plaene = null): ?array {
    $plaene ??= plaene_von($userId);

    if ($plaene === []) {
        return null;
    }
    if ($lastPlanId === null) {
        return $plaene[0];
    }

    foreach ($plaene as $i => $plan) {
        if ((int)$plan['id'] === $lastPlanId) {
            return $plaene[($i + 1) % count($plaene)];
        }
    }

    // last_plan_id zeigt ins Leere (Plan geloescht) -- vorne anfangen.
    return $plaene[0];
}

/**
 * Das zuletzt protokollierte Gewicht dieses Benutzers fuer diese Uebung (§4).
 *
 * Leere Werte werden UEBERSPRUNGEN: Wer einmal ohne Gewichtsangabe abhakt,
 * soll beim naechsten Mal trotzdem sein letztes Gewicht vorgeschlagen
 * bekommen.
 *
 * Ueber ALLE Einheiten hinweg, nicht nur die letzte.
 *
 * Sortiert wird nach performed_at UND id absteigend. Das zweite Kriterium ist
 * nicht kosmetisch: Zeitstempel haben Sekundenaufloesung, und zwei Eintraege
 * derselben Sekunde haetten sonst keine definierte Reihenfolge -- die Abfrage
 * lieferte dann mal den einen, mal den anderen Wert. Die id waechst monoton,
 * der juengste Eintrag gewinnt damit zuverlaessig.
 *
 */
function letztes_gewicht(int $userId, int $exerciseId): ?float {
    $stmt = db()->prepare(
        'SELECT weight FROM workout_log
          WHERE user_id = ? AND exercise_id = ? AND weight IS NOT NULL
          ORDER BY performed_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $exerciseId]);
    $gewicht = $stmt->fetchColumn();

    return $gewicht === false ? null : (float)$gewicht;
}

/**
 * Die Positionen eines Plans, wie sie in dieser Einheit anzuzeigen sind.
 *
 * Zwei Dinge stecken hier drin, die eine naive Umsetzung falsch macht:
 *
 * 1. Die angezeigte Uebung ist NICHT zwingend die im Plan hinterlegte. Liegt
 *    fuer diese Einheit ein Tausch vor, gewinnt er (§7.5) -- das erledigt das
 *    COALESCE, und zwar auch dann, wenn der Plan zwischenzeitlich dauerhaft
 *    geaendert wurde.
 * 2. Schluessel ist die PLANPOSITION, nicht die Uebung. Nach einem Tausch
 *    steht in workout_log.exercise_id die Ersatzuebung; ohne
 *    plan_exercise_id waere "x/n" nicht zaehlbar (§4).
 *
 * @param int|null $sessionId Offene Einheit, oder null wenn noch keine laeuft
 */
function plan_positionen(int $userId, int $planId, ?int $sessionId): array {
    $stmt = db()->prepare(
        'SELECT pe.id            AS plan_exercise_id,
                pe.sort_order,
                pe.exercise_id   AS plan_uebung_id,
                COALESCE(sw.replacement_exercise_id, pe.exercise_id) AS exercise_id,
                sw.replacement_exercise_id IS NOT NULL AS getauscht,
                e.name_de, e.name_en, e.description, e.focus, e.equipment,
                e.image_path, e.archived,
                orig.name_de     AS plan_uebung_name,
                wl.id            AS log_id,
                wl.weight, wl.performed_at
           FROM plan_exercises pe
           LEFT JOIN exercise_swaps sw
                  ON sw.plan_exercise_id = pe.id AND sw.session_id = :sid
           JOIN exercises e
                  ON e.id = COALESCE(sw.replacement_exercise_id, pe.exercise_id)
           JOIN exercises orig
                  ON orig.id = pe.exercise_id
           LEFT JOIN workout_log wl
                  ON wl.plan_exercise_id = pe.id AND wl.session_id = :sid
          WHERE pe.plan_id = :pid
          ORDER BY pe.sort_order, pe.id'
    );
    $stmt->execute([':sid' => $sessionId, ':pid' => $planId]);
    $zeilen = $stmt->fetchAll();

    $gruppen = db()->prepare(
        'SELECT mg.name_de, emg.is_primary
           FROM exercise_muscle_groups emg
           JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
          WHERE emg.exercise_id = ?
          ORDER BY emg.is_primary DESC, mg.sort_order, mg.name_de'
    );

    $ergebnis = [];
    foreach ($zeilen as $z) {
        $exerciseId = (int)$z['exercise_id'];
        $gruppen->execute([$exerciseId]);

        $erledigt = $z['log_id'] !== null;
        $letztes  = letztes_gewicht($userId, $exerciseId);

        $ergebnis[] = [
            'plan_exercise_id' => (int)$z['plan_exercise_id'],
            'exercise_id'      => $exerciseId,
            'name_de'          => (string)$z['name_de'],
            'name_en'          => $z['name_en'],
            'description'      => $z['description'],
            'focus'            => $z['focus'],
            'equipment'        => $z['equipment'],
            'image_path'       => $z['image_path'],
            'archived'         => (int)$z['archived'] === 1,
            'getauscht'        => (int)$z['getauscht'] === 1,
            'plan_uebung_name' => (string)$z['plan_uebung_name'],
            'muskelgruppen'    => $gruppen->fetchAll(),
            'erledigt'         => $erledigt,
            // Vorbelegung: in der Einheit protokollierter Wert, sonst der
            // letzte bekannte. Ein bewusst geleertes Feld einer erledigten
            // Position bleibt leer und wird nicht wieder aufgefuellt.
            'weight'           => $erledigt ? to_decimal_or_null($z['weight']) : $letztes,
            'letztes_gewicht'  => $letztes,
        ];
    }

    return $ergebnis;
}

/**
 * Die Uebungen, die im Plan gerade tatsaechlich angezeigt werden.
 *
 * Beruecksichtigt die Taeusche der laufenden Einheit: Steht an einer Position
 * ein Ersatz, zaehlt der Ersatz -- die urspruengliche Uebung ist heute nicht
 * im Programm und darf als Alternative auftauchen.
 *
 * @return int[]
 */
function uebungen_im_plan(int $planId, ?int $sessionId): array {
    $stmt = db()->prepare(
        'SELECT DISTINCT COALESCE(sw.replacement_exercise_id, pe.exercise_id) AS exercise_id
           FROM plan_exercises pe
           LEFT JOIN exercise_swaps sw
                  ON sw.plan_exercise_id = pe.id AND sw.session_id = :sid
          WHERE pe.plan_id = :pid'
    );
    $stmt->execute([':sid' => $sessionId, ':pid' => $planId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Passt diese Planposition zur laufenden Einheit?
 *
 * Eine Einheit gehoert zu genau EINEM Plan. Ohne diese Pruefung liesse sich
 * eine Position aus einem anderen Plan in die laufende Einheit
 * hineinprotokollieren: workout_log.plan_id widerspraeche dann
 * sessions.plan_id, und der Zaehler "x/n" zaehlt x ueber die Einheit, n aber
 * ueber den Plan -- Ergebnis waren Anzeigen wie "2/1".
 *
 * Ueber die Oberflaeche ist das nicht erreichbar; ein zweiter Tab mit einem
 * veralteten Plan oder ein von Hand gebauter Request schon.
 *
 * Ohne offene Einheit ist alles zulaessig -- die Einheit entsteht dann erst
 * mit dem Plan dieser Position.
 */
function position_passt_zur_einheit(int $userId, int $planId): bool {
    $offen = offene_einheit($userId);
    if ($offen === null) {
        return true;
    }
    return (int)$offen['plan_id'] === $planId;
}

/**
 * Sorgt dafuer, dass eine offene Einheit existiert, und liefert ihre ID.
 *
 * Eine Einheit entsteht durch die erste zustandsaendernde Trainingsaktion --
 * das ist entweder ein "Erledigt" ODER ein Tausch "nur diese Einheit" (§7.6).
 * Der zweite Fall ist keine Spitzfindigkeit: Im Studio lautet der reale Ablauf
 * Plan oeffnen, Geraet besetzt vorfinden, tauschen, DANN trainieren. Und ein
 * exercise_swaps-Eintrag braucht zwingend eine session_id.
 *
 * Blosses Anschauen startet keine Einheit.
 */
function einheit_sicherstellen(int $userId, int $planId): int {
    $offen = offene_einheit($userId);
    if ($offen !== null) {
        return (int)$offen['id'];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO sessions (user_id, plan_id, started_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $planId, now()]);
        return (int)db()->lastInsertId();
    } catch (PDOException $e) {
        // Zwei gleichzeitige Requests aus demselben Studio-Browser: der
        // partielle Unique-Index idx_sessions_one_open laesst nur eine durch.
        // Der Verlierer nimmt die Einheit des Gewinners.
        $offen = offene_einheit($userId);
        if ($offen !== null) {
            return (int)$offen['id'];
        }
        throw $e;
    }
}

/**
 * Beendet die offene Einheit und merkt den Plan fuer die Rotation (§7.6).
 */
function einheit_beenden(int $userId): ?int {
    $offen = offene_einheit($userId);
    if ($offen === null) {
        return null;
    }

    db_transaction(function (PDO $pdo) use ($offen, $userId): void {
        $pdo->prepare('UPDATE sessions SET ended_at = ? WHERE id = ?')
            ->execute([now(), $offen['id']]);

        // last_plan_id traegt die Rotation. Ohne diesen Schritt bekaeme der
        // Benutzer beim naechsten Start wieder denselben Plan vorgeschlagen.
        if ($offen['plan_id'] !== null) {
            $pdo->prepare('UPDATE users SET last_plan_id = ? WHERE id = ?')
                ->execute([$offen['plan_id'], $userId]);
        }
    });

    return (int)$offen['id'];
}

/**
 * Tauschvorschlaege fuer eine Uebung (§7.5).
 *
 * Verglichen wird ausschliesslich PRIMAER gegen PRIMAER. Sekundaergruppen
 * werden gar nicht herangezogen -- sonst kaemen fuer Bankdruecken reine
 * Trizeps-Uebungen, und fuer Trizepsdruecken kaeme Bankdruecken. Beide
 * Fehlrichtungen sind damit ausgeschlossen.
 */
/**
 * Die Primaergruppe einer Uebung samt ihrer Hauptgruppe.
 *
 * Untergruppen zeigen ueber parent_id auf ihre Hauptgruppe; bei Hauptgruppen
 * ist parent_id leer. COALESCE liefert damit in beiden Faellen die Wurzel.
 *
 * Beide Werte werden gebraucht: die Wurzel bestimmt, WAS als Ersatz in Frage
 * kommt, die genaue Gruppe, in welcher REIHENFOLGE es angeboten wird.
 *
 * @return array{gruppe:int,wurzel:int}|null
 */
function primaergruppe_von_uebung(int $exerciseId): ?array {
    $stmt = db()->prepare(
        'SELECT mg.id AS gruppe, COALESCE(mg.parent_id, mg.id) AS wurzel
           FROM exercise_muscle_groups emg
           JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
          WHERE emg.exercise_id = ? AND emg.is_primary = 1'
    );
    $stmt->execute([$exerciseId]);
    $zeile = $stmt->fetch();

    return $zeile === false
        ? null
        : ['gruppe' => (int)$zeile['gruppe'], 'wurzel' => (int)$zeile['wurzel']];
}

function tausch_vorschlaege(int $exerciseId, array $ausschluss = []): array {
    // Uebungen, die ohnehin im laufenden Plan stehen, sind kein Ersatz --
    // man macht sie an diesem Tag sowieso. Ohne diesen Ausschluss bestuenden
    // die Vorschlaege groesstenteils aus Zeilen, die zwei Positionen weiter
    // unten schon warten.
    $primaer = primaergruppe_von_uebung($exerciseId);
    if ($primaer === null) {
        return [];
    }

    $ausschluss = array_values(array_unique(array_merge([$exerciseId], $ausschluss)));
    $platzhalter = implode(',', array_fill(0, count($ausschluss), '?'));

    // Verglichen wird die HAUPTGRUPPE, nicht die genaue Untergruppe: Eine
    // Uebung fuer "Brust (oben)" darf durch eine fuer "Brust (unten)" ersetzt
    // werden -- man will an dem Tag die Brust trainieren, nicht exakt diese
    // Fasern. Genau deshalb darf die Unterteilung beliebig fein sein, ohne
    // dass die Vorschlagsliste leer laeuft.
    //
    // Das Trainingsgeraet spielt hier ausdruecklich KEINE Rolle -- es wird nur
    // mitgeliefert und angezeigt. Der haeufigste Grund zu tauschen ist eine
    // besetzte Maschine; ein Filter auf dasselbe Geraet verhinderte genau den
    // Ausweg, den man in dem Moment sucht.
    $stmt = db()->prepare(
        "SELECT e.id, e.name_de, e.name_en, e.focus, e.equipment, e.image_path,
                mg.name_de AS gruppe
           FROM exercises e
           JOIN exercise_muscle_groups emg
                ON emg.exercise_id = e.id AND emg.is_primary = 1
           JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
          -- CAST ist hier Pflicht, nicht Zierde: COALESCE() liefert einen Wert
          -- OHNE Spaltenaffinitaet, und PDO bindet die Werte aus execute([...])
          -- grundsaetzlich als Text. SQLite vergleicht dann Integer gegen Text,
          -- was nie zutrifft -- die Vorschlagsliste bliebe stumm leer.
          WHERE COALESCE(mg.parent_id, mg.id) = CAST(? AS INTEGER)
            AND e.id NOT IN ($platzhalter)
            AND e.archived = 0
          -- Naechstliegender Ersatz zuerst: erst die Uebungen DERSELBEN
          -- Untergruppe, danach der Rest der Hauptgruppe. Wer Ersatz fuer eine
          -- Trizeps-Uebung sucht, bekommt sonst Bizeps-Uebungen zwischen die
          -- eigentlichen Alternativen sortiert, nur weil ihr Name frueher im
          -- Alphabet steht. Die uebrigen Untergruppen bleiben durch
          -- mg.sort_order jeweils beieinander.
          ORDER BY CASE WHEN mg.id = CAST(? AS INTEGER) THEN 0 ELSE 1 END,
                   mg.sort_order, mg.name_de, e.name_de"
    );
    $stmt->execute([$primaer['wurzel'], ...$ausschluss, $primaer['gruppe']]);
    $vorschlaege = $stmt->fetchAll();

    // Zu jeder Alternative die weiteren Gruppen nennen -- damit erkennbar ist,
    // was man sich zusaetzlich einhandelt (§7.5).
    $gruppen = db()->prepare(
        'SELECT mg.name_de, emg.is_primary
           FROM exercise_muscle_groups emg
           JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
          WHERE emg.exercise_id = ?
          ORDER BY emg.is_primary DESC, mg.sort_order, mg.name_de'
    );
    foreach ($vorschlaege as &$v) {
        $gruppen->execute([(int)$v['id']]);
        $v['muskelgruppen'] = $gruppen->fetchAll();
    }

    return $vorschlaege;
}

/**
 * Die offene Einheit eines Benutzers, oder null.
 *
 * Offen heisst ended_at IS NULL -- unabhaengig vom Datum. Eine Einheit laeuft
 * ueber Mitternacht weiter (§7.6).
 */
function offene_einheit(int $userId): ?array {
    $stmt = db()->prepare(
        'SELECT id, plan_id, started_at FROM sessions
          WHERE user_id = ? AND ended_at IS NULL
          ORDER BY started_at DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $zeile = $stmt->fetch();
    return $zeile === false ? null : $zeile;
}

/**
 * Abgeschlossene Trainingseinheiten eines Benutzers, neueste zuerst (§10).
 *
 * Ausschliesslich die EIGENEN: Der user_id-Vergleich steht in der WHERE-Klausel,
 * nicht in einer Pruefung davor -- Trainingsdaten sind persoenlich, und ein
 * Admin hat hier nichts zu suchen, was ihm nicht selbst gehoert.
 *
 * Offene Einheiten bleiben aussen vor: Sie haben keine Dauer und stehen ohnehin
 * auf der Startseite.
 */
function einheiten_verlauf(int $userId, int $limit = 50): array {
    $stmt = db()->prepare(
        'SELECT s.id, s.started_at, s.ended_at, s.plan_id,
                p.name AS plan_name,
                (SELECT COUNT(*) FROM workout_log wl WHERE wl.session_id = s.id) AS erledigt,
                (SELECT COUNT(*) FROM plan_exercises pe WHERE pe.plan_id = s.plan_id) AS gesamt
           FROM sessions s
           LEFT JOIN plans p ON p.id = s.plan_id
          WHERE s.user_id = ? AND s.ended_at IS NOT NULL
          ORDER BY s.started_at DESC, s.id DESC
          LIMIT ' . (int)$limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Die protokollierten Uebungen einer Einheit, in Planreihenfolge.
 *
 * plan_exercise_id kann NULL sein, wenn die Planposition spaeter entfernt
 * wurde (§4.1) -- die Historie bleibt trotzdem lesbar, sie haengt dann nur
 * hinten an.
 */
function einheit_eintraege(int $sessionId, int $userId): array {
    $stmt = db()->prepare(
        'SELECT wl.exercise_id, wl.weight, wl.performed_at,
                e.name_de,
                pe.sort_order,
                pe.exercise_id AS plan_uebung_id,
                orig.name_de   AS plan_uebung_name
           FROM workout_log wl
           JOIN exercises e ON e.id = wl.exercise_id
           LEFT JOIN plan_exercises pe   ON pe.id = wl.plan_exercise_id
           LEFT JOIN exercises orig      ON orig.id = pe.exercise_id
          WHERE wl.session_id = ? AND wl.user_id = ?
          ORDER BY pe.sort_order IS NULL, pe.sort_order, wl.id'
    );
    $stmt->execute([$sessionId, $userId]);
    return $stmt->fetchAll();
}

/**
 * Alle Uebungen, fuer die dieser Benutzer je ein Gewicht protokolliert hat --
 * mit Anzahl, letztem Wert und Bestwert.
 *
 * Grundlage der Verlaufsansicht. Uebungen ohne Gewichtsangabe tauchen nicht
 * auf: Fuer sie gaebe es nichts zu zeigen.
 */
function uebungen_mit_verlauf(int $userId): array {
    $stmt = db()->prepare(
        'SELECT wl.exercise_id, e.name_de, e.image_path,
                COUNT(*)      AS anzahl,
                MAX(wl.weight) AS bestwert,
                MAX(wl.performed_at) AS zuletzt
           FROM workout_log wl
           JOIN exercises e ON e.id = wl.exercise_id
          WHERE wl.user_id = ? AND wl.weight IS NOT NULL
          GROUP BY wl.exercise_id, e.name_de, e.image_path
          ORDER BY zuletzt DESC, e.name_de'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Der Gewichtsverlauf einer Uebung, aelteste zuerst (fuer die Kurve).
 */
function gewichts_verlauf(int $userId, int $exerciseId, int $limit = 60): array {
    $stmt = db()->prepare(
        'SELECT wl.weight, wl.performed_at
           FROM workout_log wl
          WHERE wl.user_id = ? AND wl.exercise_id = ? AND wl.weight IS NOT NULL
          ORDER BY wl.performed_at DESC, wl.id DESC
          LIMIT ' . (int)$limit
    );
    $stmt->execute([$userId, $exerciseId]);

    // Absteigend geholt (damit LIMIT die JUENGSTEN nimmt), aufsteigend
    // zurueckgegeben -- die Kurve laeuft von links nach rechts durch die Zeit.
    return array_reverse($stmt->fetchAll());
}

/**
 * Formatiert eine Dauer in Minuten als "1 h 05 min" oder "45 min".
 */
function dauer_text(?string $von, ?string $bis): string {
    if ($von === null || $bis === null) {
        return '—';
    }
    $min = (int)round((strtotime($bis) - strtotime($von)) / 60);
    if ($min < 1) {
        return 'unter 1 min';
    }
    if ($min < 60) {
        return $min . ' min';
    }
    return intdiv($min, 60) . ' h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . ' min';
}
