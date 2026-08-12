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
 * Der Plan der zuletzt begonnenen Einheit (§7.6).
 *
 * Die Rotation liest ihren Ausgangspunkt hier aus der Historie und nicht mehr
 * aus users.last_plan_id. Der Unterschied ist kein Schoenheitsfehler: Die
 * Spalte wurde nur beim BEENDEN geschrieben und beim Loeschen einer Einheit
 * nie zurueckgenommen. Wer eine Einheit zum Ausprobieren startete, beendete
 * und wieder loeschte, hatte danach dauerhaft den falschen Vorschlag stehen --
 * die Einheit war weg, ihre Wirkung auf die Rotation blieb. Genau so ist am
 * 2026-08-12 nach einer Pull-Einheit wieder Pull vorgeschlagen worden.
 *
 * Aus der Historie gelesen heilt sich der Fall von selbst: Was geloescht ist,
 * zaehlt nicht mehr mit.
 *
 * Gezaehlt wird JEDE Einheit, auch eine ohne einzige Protokollzeile. Das ist
 * eine bewusste Entscheidung des Benutzers (2026-08-12) und nicht die
 * naheliegende: Die Rotation richtet sich starr nach der Historie, und eine
 * leere Einheit STEHT in der Historie. Wer sie nicht gezaehlt haben will,
 * loescht sie -- die Historie sauber zu halten ist Sache des Benutzers. Der
 * Gegenentwurf (nur Einheiten mit Protokollzeile) waere eine zweite, stille
 * Regel gewesen, die man beim Blick auf den Verlauf nicht sieht.
 *
 * Sortiert wird nach started_at UND id absteigend, aus demselben Grund wie in
 * letztes_gewicht(): Zeitstempel haben Sekundenaufloesung.
 *
 * @return int|null Plan-ID oder null, wenn es noch keine Einheit gibt
 */
function zuletzt_trainierter_plan(int $userId): ?int {
    $stmt = db()->prepare(
        'SELECT s.plan_id
           FROM sessions s
          WHERE s.user_id = ?
            AND s.plan_id IS NOT NULL
          ORDER BY s.started_at DESC, s.id DESC
          LIMIT 1'
    );
    $stmt->execute([$userId]);

    return to_int_or_null($stmt->fetchColumn());
}

/**
 * Der Plan, der als naechstes vorgeschlagen wird (§7.6).
 *
 * Die Regel ist eine Rotation entlang der Sortierreihenfolge: vorgeschlagen
 * wird der Plan NACH dem zuletzt trainierten, zyklisch. Bei zwei Plaenen
 * ergibt das genau die fruehere Alternation ("der jeweils andere"), bei
 * Push/Pull/Legs laeuft sie der Reihe nach durch.
 *
 * Kein Rotationszaehler noetig: die Position des zuletzt trainierten Plans in
 * der Reihenfolge bestimmt den Nachfolger eindeutig. Ist noch nichts
 * protokolliert oder zeigt der Eintrag auf einen geloeschten Plan, faengt die
 * Rotation vorne an.
 *
 * @param array|null $plaene Bereits geladene Plaene, spart eine Abfrage
 * @return array|null Der vorgeschlagene Plan oder null, wenn keiner existiert
 */
function naechster_plan(int $userId, ?array $plaene = null): ?array {
    $plaene ??= plaene_von($userId);

    if ($plaene === []) {
        return null;
    }

    $zuletzt = zuletzt_trainierter_plan($userId);
    if ($zuletzt === null) {
        return $plaene[0];
    }

    foreach ($plaene as $i => $plan) {
        if ((int)$plan['id'] === $zuletzt) {
            return $plaene[($i + 1) % count($plaene)];
        }
    }

    // Der zuletzt trainierte Plan existiert nicht mehr -- vorne anfangen.
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
 * Bringt eine Satzzeile aus der Datenbank in die Form, die die App benutzt.
 *
 * PDO liefert alles als Text; ohne diese Stelle stuenden in den JSON-Antworten
 * "12" und "40" statt 12 und 40, und der Vergleich im Browser ginge schief.
 */
function satz_zeile(array $z): array {
    return [
        'satz_nr' => (int)$z['satz_nr'],
        'reps'    => $z['reps']   === null ? null : (int)$z['reps'],
        'weight'  => $z['weight'] === null ? null : (float)$z['weight'],
    ];
}

/**
 * Alle Saetze einer Einheit, geschluesselt nach Planposition.
 *
 * Bewusst EINE Abfrage fuer die ganze Einheit statt einer je Position:
 * plan_positionen() laeuft ohnehin schon mit einer N+1-Schleife fuer
 * Muskelgruppen und "letztes Gewicht"; eine dritte kaeme bei acht Positionen
 * auf acht weitere Abfragen, ohne dass es dafuer einen Grund gaebe.
 */
function saetze_der_einheit(int $sessionId): array {
    $stmt = db()->prepare(
        'SELECT wl.plan_exercise_id, ws.satz_nr, ws.reps, ws.weight
           FROM workout_sets ws
           JOIN workout_log wl ON wl.id = ws.workout_log_id
          WHERE wl.session_id = ?
          ORDER BY wl.plan_exercise_id, ws.satz_nr'
    );
    $stmt->execute([$sessionId]);

    $nach = [];
    foreach ($stmt->fetchAll() as $z) {
        // Eine Protokollzeile ohne Planposition gibt es nur historisch (§4.1);
        // fuer die laufende Einheit ist sie bedeutungslos.
        if ($z['plan_exercise_id'] === null) {
            continue;
        }
        $nach[(int)$z['plan_exercise_id']][] = satz_zeile($z);
    }

    return $nach;
}

/**
 * Die Saetze aus der letzten Einheit, in der diese Uebung satzgenau
 * protokolliert wurde -- Grundlage fuer "letztes Mal" und die Vorbelegung
 * beim Hinzufuegen eines Satzes (§7.4).
 *
 * $ausserSessionId schliesst die LAUFENDE Einheit aus, und das ist nicht
 * optional gemeint: Ohne diesen Ausschluss lieferte die Abfrage waehrend des
 * Trainings die Saetze, die man gerade selbst eingetragen hat -- "letztes Mal"
 * zeigte dann auf das aktuelle Mal.
 *
 * Uebersprungen werden Einheiten OHNE Saetze: Wer zwischendurch im einfachen
 * Modus trainiert hat, verliert seine Satzvorlage dadurch nicht.
 */
function letzte_saetze(int $userId, int $exerciseId, ?int $ausserSessionId = null): array {
    $stmt = db()->prepare(
        'SELECT ws.satz_nr, ws.reps, ws.weight
           FROM workout_sets ws
          WHERE ws.workout_log_id = (
                SELECT wl.id
                  FROM workout_log wl
                 WHERE wl.user_id = ? AND wl.exercise_id = ?
                   AND (? IS NULL OR wl.session_id <> CAST(? AS INTEGER))
                   AND EXISTS (SELECT 1 FROM workout_sets w2
                                WHERE w2.workout_log_id = wl.id)
                 ORDER BY wl.performed_at DESC, wl.id DESC
                 LIMIT 1)
          ORDER BY ws.satz_nr'
    );
    $stmt->execute([$userId, $exerciseId, $ausserSessionId, $ausserSessionId]);

    return array_map('satz_zeile', $stmt->fetchAll());
}

/**
 * Die Saetze zu mehreren Protokollzeilen auf einmal, geschluesselt nach
 * workout_log.id -- fuer den Verlauf, der sonst je Zeile eine Abfrage braeuchte.
 *
 * Die IDs stammen ausschliesslich aus vorherigen Abfragen, nie aus einer
 * Eingabe. Trotzdem Platzhalter statt Interpolation: Die Regel "ausschliesslich
 * Prepared Statements" kennt keine Ausnahme fuer Faelle, die heute harmlos sind.
 */
function saetze_zu_logs(array $logIds): array {
    if ($logIds === []) {
        return [];
    }

    $ids = array_map('intval', array_values($logIds));
    $stmt = db()->prepare(
        'SELECT workout_log_id, satz_nr, reps, weight
           FROM workout_sets
          WHERE workout_log_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          ORDER BY workout_log_id, satz_nr'
    );
    $stmt->execute($ids);

    $nach = [];
    foreach ($stmt->fetchAll() as $z) {
        $nach[(int)$z['workout_log_id']][] = satz_zeile($z);
    }

    return $nach;
}

/**
 * Eine Satzfolge als Text: "12×40 · 10×40 · 9×45" -- die blosse Liste, ohne
 * Anzahl davor.
 *
 * So gebraucht im Verlauf (history.php): Dort steht sie in einer Tabellenspalte,
 * deren Kopf bereits "Saetze" heisst -- eine Anzahl daneben waere Doppelung, und
 * die Spalte ist ohnehin die breiteste. Wer eine Satzfolge FUER SICH zeigt,
 * nimmt saetze_zusammenfassung().
 *
 * Das Gegenstueck heisst saetzeText() in index.js. Zwei Fassungen sind hier
 * unvermeidlich -- die Liste entsteht server-gerendert UND im Browser --,
 * deshalb stehen beide bewusst nebeneinander und muessen zusammen geaendert
 * werden. Mehr als diese Formatierung teilen sie nicht.
 */
function saetze_text(array $saetze): string {
    if ($saetze === []) {
        return '';
    }

    $teile = [];
    foreach ($saetze as $s) {
        $wdh = $s['reps']   === null ? '?' : (string)$s['reps'];
        $kg  = $s['weight'] === null ? '—' : format_decimal($s['weight']);
        $teile[] = $wdh . '×' . $kg;
    }

    return implode(' · ', $teile);
}

/**
 * Eine Satzfolge als Zusammenfassung: "3 Sätze (12×45 · 10×45 · 8×50)".
 *
 * DIE EINE Schreibweise fuer beide Stellen, an denen eine Satzfolge zusammen-
 * gefasst wird: die Zeile "zuletzt ..." und der Kopf des Satzblocks. Sie stehen
 * am Handy direkt uebereinander -- oben was letztes Mal war, darunter was gerade
 * entsteht --, und zwei verschiedene Schreibweisen macht man dort unwillkuerlich
 * zu einem Unterschied in der Sache.
 *
 * Klammer und nicht "3 Sätze · 12×45": Der Mittelpunkt trennt schon die Saetze
 * untereinander. Als Trenner zwischen Anzahl und Liste gelesen, sieht "3 Sätze"
 * aus wie ein weiterer Listeneintrag.
 *
 * Gegenstueck: saetzeZusammenfassung() in index.js.
 */
function saetze_zusammenfassung(array $saetze): string {
    if ($saetze === []) {
        return 'Noch kein Satz';
    }

    $anzahl = count($saetze) . (count($saetze) === 1 ? ' Satz' : ' Sätze');

    return $anzahl . ' (' . saetze_text($saetze) . ')';
}

/**
 * Das Trainingsvolumen einer Satzfolge: Summe aus Wiederholungen mal Gewicht.
 *
 * null, sobald kein einziger Satz beide Werte traegt -- eine 0 waere gelogen,
 * sie stuende fuer "nichts bewegt" statt fuer "nicht berechenbar" und riss in
 * der Kurve einen Einbruch, den es nie gab.
 */
function saetze_volumen(array $saetze): ?float {
    $summe = 0.0;
    $zaehlt = false;

    foreach ($saetze as $s) {
        if ($s['reps'] !== null && $s['weight'] !== null) {
            $summe += $s['reps'] * $s['weight'];
            $zaehlt = true;
        }
    }

    return $zaehlt ? $summe : null;
}

/**
 * Geschaetztes Einwiederholungsmaximum nach Epley: kg × (1 + Wdh/30), das
 * Maximum ueber die Saetze.
 *
 * Eine NAEHERUNG, keine Messung -- die Anzeige sagt das ausdruecklich dazu.
 * Ohne diesen Hinweis waere die Zahl genau der Fehler, wegen dem 2026-08-07
 * das Wiederholungsfeld geflogen ist: vorgetaeuschte Genauigkeit.
 */
function saetze_e1rm(array $saetze): ?float {
    $best = null;

    foreach ($saetze as $s) {
        if ($s['reps'] === null || $s['weight'] === null || $s['weight'] <= 0.0) {
            continue;
        }
        $wert = $s['weight'] * (1 + $s['reps'] / 30);
        if ($best === null || $wert > $best) {
            $best = $wert;
        }
    }

    return $best;
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
 * @param int|null $sessionId  Offene Einheit, oder null wenn noch keine laeuft
 * @param bool     $mitSaetzen Expertenmodus: Satzliste und Satzvorlage mitladen
 */
function plan_positionen(
    int $userId,
    int $planId,
    ?int $sessionId,
    bool $mitSaetzen = false
): array {
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
                wl.weight, wl.done, wl.performed_at
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

    // Einmal fuer die ganze Einheit, nicht je Position. Ohne laufende Einheit
    // und ausserhalb des Expertenmodus gibt es nichts zu holen.
    $saetzeDerEinheit = ($mitSaetzen && $sessionId !== null)
        ? saetze_der_einheit($sessionId)
        : [];

    $ergebnis = [];
    foreach ($zeilen as $z) {
        $exerciseId = (int)$z['exercise_id'];
        $gruppen->execute([$exerciseId]);

        // ZWEI Zustaende, die im einfachen Modus zusammenfallen und im
        // Expertenmodus auseinandergehen:
        //   hat_eintrag -- es ist etwas protokolliert (und sei es ein Satz)
        //   erledigt    -- die Uebung ist als fertig markiert
        // Am ersten haengt die Tauschsperre (§7.5), am zweiten "x/n" und das
        // Haekchen.
        $hatEintrag = $z['log_id'] !== null;
        $erledigt   = $hatEintrag && (int)$z['done'] === 1;
        $letztes    = letztes_gewicht($userId, $exerciseId);
        $peId       = (int)$z['plan_exercise_id'];

        $ergebnis[] = [
            'plan_exercise_id' => $peId,
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
            'hat_eintrag'      => $hatEintrag,
            // Vorbelegung: in der Einheit protokollierter Wert, sonst der
            // letzte bekannte. Ein bewusst geleertes Feld einer bereits
            // protokollierten Position bleibt leer und wird nicht wieder
            // aufgefuellt.
            'weight'           => $hatEintrag ? to_decimal_or_null($z['weight']) : $letztes,
            'letztes_gewicht'  => $letztes,
            // Die Saetze DIESER Einheit -- die Eingabe von jetzt.
            'saetze'           => $saetzeDerEinheit[$peId] ?? [],
            // Die Saetze vom LETZTEN Mal -- Anzeige und Vorbelegung. Die
            // laufende Einheit ist ausgeschlossen, sonst zeigte "letztes Mal"
            // auf das, was man gerade selbst eingetragen hat.
            'letzte_saetze'    => $mitSaetzen
                ? letzte_saetze($userId, $exerciseId, $sessionId)
                : [],
        ];
    }

    return $ergebnis;
}

/**
 * Welche Position gruen ist und welche orange (§7.3).
 *
 * Das Leitsystem am linken Kartenrand hat vier Zustaende. Blau (erledigt) und
 * grau (kommt noch) ergeben sich von selbst; die beiden anderen brauchen einen
 * Vergleich ueber die ganze Liste und stehen deshalb hier:
 *
 *   gruen  = die Uebung, an der man GERADE steht.
 *   orange = uebersprungen: noch offen, obwohl man schon weiter ist.
 *
 * Bis 1.1.5 war gruen schlicht "die erste noch nicht erledigte Position". Das
 * ist falsch, sobald man eine Uebung auslaesst, weil das Geraet belegt ist: Die
 * Markierung blieb auf der ausgelassenen Uebung stehen, waehrend man laengst
 * zwei Geraete weiter war -- und dass die ausgelassene noch aussteht, war von
 * "kommt noch" nicht zu unterscheiden.
 *
 * Die Regel jetzt, in dieser Reihenfolge:
 *
 *   1. Gibt es eine Position MIT Eintrag, die noch nicht erledigt ist, ist das
 *      die aktive -- dort wird gerade protokolliert. Bei mehreren gewinnt die
 *      spaetere: Man arbeitet sich nach unten durch.
 *   2. Sonst die erste offene Position NACH der letzten mit Eintrag. Ohne
 *      Auslassung ist das genau die alte Regel; nach einer Auslassung ist es
 *      die naechste, statt zurueckzuspringen.
 *   3. Ist dahinter alles erledigt, die erste offene ueberhaupt -- der
 *      Rueckweg zu dem, was man ausgelassen hat.
 *
 * Orange ist danach jede offene Position VOR der aktiven. "Vor" ist dabei die
 * Planreihenfolge, nicht die Uhrzeit: Der Balken beantwortet die Frage "wo bin
 * ich in der Liste", und die stellt man sich beim Scrollen.
 *
 * Ohne laufende Einheit gibt es beides nicht (seit 1.1.2): Wer den Plan bloss
 * anschaut, sieht eine ruhige Liste -- eine Aussage ueber einen Ablauf, der
 * nicht laeuft, waere geraten.
 *
 * ACHTUNG: aktiveMarkieren() in index.js zieht dieselbe Regel im Betrieb nach.
 * Beide Haelften gehoeren zusammen geaendert, sonst springt die Farbe beim
 * naechsten Neuladen.
 *
 * @param array $positionen Ergebnis von plan_positionen(), in Planreihenfolge
 * @return array{aktiv:int|null,uebersprungen:int[]} plan_exercise_id-Werte
 */
function positions_zustaende(array $positionen, bool $laeuft): array {
    $leer = ['aktiv' => null, 'uebersprungen' => []];
    if (!$laeuft || $positionen === []) {
        return $leer;
    }

    $letzterEintrag = -1;
    $inArbeit       = null;
    foreach ($positionen as $i => $z) {
        if ($z['hat_eintrag']) {
            $letzterEintrag = $i;
            if (!$z['erledigt']) {
                $inArbeit = $i;
            }
        }
    }

    $aktiv = $inArbeit;

    if ($aktiv === null) {
        for ($i = $letzterEintrag + 1; $i < count($positionen); $i++) {
            if (!$positionen[$i]['erledigt']) {
                $aktiv = $i;
                break;
            }
        }
    }

    if ($aktiv === null) {
        foreach ($positionen as $i => $z) {
            if (!$z['erledigt']) {
                $aktiv = $i;
                break;
            }
        }
    }

    if ($aktiv === null) {
        // Alles erledigt -- die Rueckfrage zum Beenden steht ohnehin schon da.
        return $leer;
    }

    $uebersprungen = [];
    foreach ($positionen as $i => $z) {
        if ($i < $aktiv && !$z['erledigt']) {
            $uebersprungen[] = (int)$z['plan_exercise_id'];
        }
    }

    return [
        'aktiv'         => (int)$positionen[$aktiv]['plan_exercise_id'],
        'uebersprungen' => $uebersprungen,
    ];
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
 * GENAU EIN AUFRUFER, und das ist die Aussage dieser Funktion: api/session.php
 * → start, also der Knopf "Training starten" (§7.6). Nichts sonst darf eine
 * Einheit anlegen.
 *
 * Bis 1.1.5 war das anders: Auch das erste "Erledigt" (api/log.php) und ein
 * Tausch "nur diese Einheit" (api/swap.php) riefen hier herein und begannen
 * damit stillschweigend ein Training. Die Begruendung war der reale Ablauf im
 * Studio -- Plan oeffnen, Geraet besetzt vorfinden, tauschen, DANN trainieren.
 * In der Praxis ueberwog der andere Fall: Ein Fehlgriff beim blossen
 * Durchsehen des Plans begann eine Einheit, die niemand wollte, und started_at
 * hielt dann nicht den Trainingsbeginn fest, sondern den Fehlgriff. Beide
 * Aufrufer lehnen seit 1.1.6 mit 409 ab, statt anzulegen.
 *
 * Wer einen dritten Aufrufer ergaenzt, hebt die Zusicherung auf.
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

    // Nur noch ein einziger Schreibvorgang, deshalb ohne Transaktion: Die
    // Rotation merkt sich nichts mehr, sie liest ihren Stand in
    // zuletzt_trainierter_plan() aus der Historie. users.last_plan_id wird
    // damit weder gelesen noch geschrieben.
    db()->prepare('UPDATE sessions SET ended_at = ? WHERE id = ?')
        ->execute([now(), $offen['id']]);

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
                (SELECT COUNT(*) FROM workout_log wl
                  WHERE wl.session_id = s.id AND wl.done = 1) AS erledigt,
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
        'SELECT wl.id AS log_id, wl.exercise_id, wl.weight, wl.performed_at,
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
 *
 * wl.id kommt mit, damit sich ueber saetze_zu_logs() die Saetze je Punkt
 * zuordnen lassen -- daraus entstehen Volumen und geschaetztes 1RM (§7.8).
 */
function gewichts_verlauf(int $userId, int $exerciseId, int $limit = 60): array {
    $stmt = db()->prepare(
        'SELECT wl.id AS log_id, wl.weight, wl.performed_at
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
