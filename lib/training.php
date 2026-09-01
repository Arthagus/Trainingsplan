<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
// Wegen ERFASSUNG und ist_ausdauer(): Die Fachlichkeit hier unterscheidet
// durchgehend zwischen Kraft und Ausdauer, und wer training.php hat, hat damit
// auch die Codeliste -- index.php, history.php, api/log.php, api/swap.php.
require_once __DIR__ . '/geraete.php';

/**
 * Trainingslogik: Plan-Rotation, offene Einheiten, Vorbelegung von Gewichten.
 *
 * Eigene Datei und nicht in helpers.php, weil hier die Fachlichkeit aus §7
 * liegt -- sie wird sowohl von der Handy-Ansicht als auch vom Adminbereich
 * gebraucht (dort fuer die Vorschau der Rotation).
 */

/**
 * Woher die Vorbelegung eines neu hinzugefuegten Satzes kommt (§7.4).
 *
 * Eine persoenliche Einstellung, weil beide Verfahren fuer verschiedene Leute
 * das jeweils schnellere sind:
 *
 *   gleicher_satz  Satz k bekommt Satz k vom letzten Mal. Wer eine feste
 *                  Satzfolge faehrt (12/10/9), hat sie mit drei Tipps stehen.
 *   letzter_satz   Jeder neue Satz uebernimmt den vorigen von HEUTE. Wer sich
 *                  von Satz zu Satz herantastet, korrigiert einmal und traegt
 *                  die Korrektur automatisch weiter.
 *
 * Der ERSTE Satz kommt in beiden Faellen vom letzten Training -- der
 * Unterschied beginnt ab Satz 2.
 *
 * Codeliste statt Tabelle und ohne CHECK, aus demselben Grund wie bei GERAETE
 * und ZUSCHNITT (Fallstrick 16): Die Menge ist klein, geschlossen und haengt
 * nicht am Datenbestand. Eine dritte Variante soll eine Zeile hier kosten.
 *
 * Die Codeliste steht bewusst NICHT in lib/geraete.php. Dort liegt zwar schon
 * ZUSCHNITT, das mit Traningsgeraeten ebenso wenig zu tun hat -- aber eine
 * dritte fachfremde Liste wuerde den irrefuehrenden Dateinamen endgueltig
 * zementieren. Saetze sind Fachlichkeit aus §7 und gehoeren hierher.
 *
 * ANGEWENDET wird die Regel ausschliesslich im Browser, in naechsterSatz()
 * (index.js). Es gibt hier bewusst KEIN Gegenstueck: Der Server erfindet nie
 * einen neuen Satz, er liefert nur die Vorlage (letzte_saetze()) und das
 * zuletzt bekannte Gewicht. Wer nach der zweiten Haelfte sucht -- wie bei
 * positions_zustaende()/aktiveMarkieren() oder saetze_text()/saetzeText() --,
 * sucht vergebens.
 */
const SATZ_VORLAGE = [
    'gleicher_satz' => 'Wie beim letzten Training',
    'letzter_satz'  => 'Wie der Satz davor',
];

const SATZ_VORLAGE_STANDARD = 'gleicher_satz';

/**
 * Normalisiert einen gespeicherten oder eingegebenen Wert.
 *
 * Faellt auf den Standard zurueck statt zu werfen: Ein unbekannter Wert kann
 * nur aus einer aelteren Sicherung oder einem Eingriff von Hand stammen, und
 * dann ist das bisherige Verhalten die richtige Antwort -- nicht ein Fehler
 * mitten im Training. Die EINGABE prueft api/auth.php dagegen streng.
 */
function satz_vorlage_normalisieren(mixed $wert): string {
    $s = is_string($wert) ? $wert : '';
    return array_key_exists($s, SATZ_VORLAGE) ? $s : SATZ_VORLAGE_STANDARD;
}

/**
 * Alle Plaene EINES SPLITS in Rotationsreihenfolge.
 *
 * Seit 1.2.0 ist der Split und nicht der Benutzer die Klammer um eine
 * Rotation (§6.4, §7.6). Wer hier mit einer user_id einsteigt, mischt zwei
 * Splits in einer Reihenfolge -- nach Push kaeme dann irgendwann Ganzkoerper B.
 *
 * plans.user_id kommt bewusst nicht mehr vor: Sie ist tot, die Zugehoerigkeit
 * traegt splits.user_id (siehe schema.sql).
 */
function plaene_im_split(int $splitId): array {
    $stmt = db()->prepare(
        'SELECT id, name, sort_order FROM plans WHERE split_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$splitId]);
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
 * Seit 1.2.0 zaehlt nur, was IN DIESEM SPLIT trainiert wurde. Genau daran
 * haengt, dass ein Splitwechsel nichts vergisst: Wer von Push/Pull auf
 * Ganzkoerper wechselt und spaeter zurueck, bekommt wieder Pull vorgeschlagen
 * und nicht Push. Ein Zaehler je Split waere dafuer nicht noetig -- und waere
 * genau die zweite Quelle, die Fallstrick 21 beschreibt.
 *
 * Der JOIN ersetzt zugleich das fruehere "AND s.plan_id IS NOT NULL": Eine
 * Einheit auf einem inzwischen geloeschten Plan (ON DELETE SET NULL) faellt
 * heraus, und die Rotation faengt vorne an -- unveraendertes Verhalten.
 *
 * @return int|null Plan-ID oder null, wenn in diesem Split noch nichts lief
 */
function zuletzt_trainierter_plan(int $userId, int $splitId): ?int {
    $stmt = db()->prepare(
        'SELECT s.plan_id
           FROM sessions s
           JOIN plans p ON p.id = s.plan_id
          WHERE s.user_id = ?
            AND p.split_id = ?
          ORDER BY s.started_at DESC, s.id DESC
          LIMIT 1'
    );
    $stmt->execute([$userId, $splitId]);

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
 * Die Rotation laeuft INNERHALB eines Splits (§6.4, §7.6) -- der Split ist
 * die Klammer, nicht der Benutzer.
 *
 * @param array|null $plaene Bereits geladene Plaene, spart eine Abfrage
 * @return array|null Der vorgeschlagene Plan oder null, wenn keiner existiert
 */
function naechster_plan(int $userId, int $splitId, ?array $plaene = null): ?array {
    $plaene ??= plaene_im_split($splitId);

    if ($plaene === []) {
        return null;
    }

    $zuletzt = zuletzt_trainierter_plan($userId, $splitId);
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
 * Das Gegenstueck zu letztes_gewicht() fuer Ausdaueruebungen: Distanz und Zeit
 * der zuletzt protokollierten Einheit, als Vorbelegung des naechsten Mals.
 *
 * EINE Abfrage und nicht zwei, weil beide Werte aus DERSELBEN Zeile stammen
 * muessen: 5000 m vom Dienstag mit der Zeit vom Freitag ergaeben eine Pace, die
 * es nie gab. Deshalb reicht hier auch nicht das Muster von letztes_gewicht(),
 * das jede Spalte fuer sich sucht.
 *
 * Verlangt wird, dass MINDESTENS einer der beiden Werte gefuellt ist -- eine
 * Zeile ganz ohne Werte (abgehakt, nichts eingetragen) taugt nicht als Vorlage.
 *
 * Das id DESC im ORDER BY ist Pflicht, aus demselben Grund wie bei
 * letztes_gewicht(): Zeitstempel haben Sekundenaufloesung.
 */
function letzte_ausdauerwerte(int $userId, int $exerciseId): array {
    $stmt = db()->prepare(
        'SELECT distanz_m, dauer_s FROM workout_log
          WHERE user_id = ? AND exercise_id = ?
            AND (distanz_m IS NOT NULL OR dauer_s IS NOT NULL)
          ORDER BY performed_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $exerciseId]);
    $z = $stmt->fetch();

    if ($z === false) {
        return ['distanz_m' => null, 'dauer_s' => null];
    }

    return [
        'distanz_m' => $z['distanz_m'] === null ? null : (int)$z['distanz_m'],
        'dauer_s'   => $z['dauer_s']   === null ? null : (int)$z['dauer_s'],
    ];
}

/**
 * Bringt eine Satzzeile aus der Datenbank in die Form, die die App benutzt.
 *
 * PDO liefert alles als Text; ohne diese Stelle stuenden in den JSON-Antworten
 * "12" und "40" statt 12 und 40, und der Vergleich im Browser ginge schief.
 */
function satz_zeile(array $z): array {
    return [
        'satz_nr'   => (int)$z['satz_nr'],
        'reps'      => $z['reps']      === null ? null : (int)$z['reps'],
        'weight'    => $z['weight']    === null ? null : (float)$z['weight'],
        // Das zweite Feldpaar, fuer Ausdaueruebungen (§7.4). Eine Zeile traegt
        // immer nur EINES der beiden; welches, entscheidet exercises.erfassung.
        // Beide stehen trotzdem in jeder Zeile: Der Browser bekaeme sonst je
        // nach Uebung verschieden geformte Objekte, und satzAusDaten() muesste
        // raten, was fehlt.
        'distanz_m' => $z['distanz_m'] === null ? null : (int)$z['distanz_m'],
        'dauer_s'   => $z['dauer_s']   === null ? null : (int)$z['dauer_s'],
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
        'SELECT wl.plan_exercise_id, ws.satz_nr, ws.reps, ws.weight,
                ws.distanz_m, ws.dauer_s
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
        'SELECT ws.satz_nr, ws.reps, ws.weight, ws.distanz_m, ws.dauer_s
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
        'SELECT workout_log_id, satz_nr, reps, weight, distanz_m, dauer_s
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
function saetze_text(array $saetze, string $erfassung): string {
    if ($saetze === []) {
        return '';
    }

    $teile = [];
    foreach ($saetze as $s) {
        if (ist_ausdauer($erfassung)) {
            $m   = $s['distanz_m'] === null ? '—' : $s['distanz_m'] . ' m';
            $zeit = $s['dauer_s']  === null ? '—' : dauer_mmss($s['dauer_s']);
            // Schraegstrich zwischen den beiden Werten EINES Intervalls, damit
            // der Mittelpunkt weiter allein die Intervalle voneinander trennt.
            // Mit "·" an beiden Stellen liest sich "1000 m · 5:30 · 500 m" wie
            // drei Angaben statt wie zwei Intervalle.
            $teile[] = $m . '/' . $zeit;
            continue;
        }

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
function saetze_zusammenfassung(array $saetze, string $erfassung): string {
    $ausdauer = ist_ausdauer($erfassung);

    if ($saetze === []) {
        return $ausdauer ? 'Noch kein Intervall' : 'Noch kein Satz';
    }

    // "Intervall" statt "Satz" bei Ausdauer -- am Laufband macht man keine
    // Saetze. Nur Beschriftung: Im Datenmodell heissen die Zeilen weiterhin
    // workout_sets.satz_nr, und das bleibt auch so, sonst braeuchte dieselbe
    // Sache zwei Namen in der Datenbank.
    $wort = $ausdauer
        ? (count($saetze) === 1 ? ' Intervall' : ' Intervalle')
        : (count($saetze) === 1 ? ' Satz' : ' Sätze');

    return count($saetze) . $wort . ' (' . saetze_text($saetze, $erfassung) . ')';
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
 * Die Gesamtdistanz einer Intervallfolge in Metern -- der Leitwert, der in
 * workout_log.distanz_m landet.
 *
 * SUMME und nicht Maximum, und das ist der Unterschied zu leitgewicht(): Zwei
 * Intervalle zu 1000 m sind 2000 gelaufene Meter. Beim Gewicht waere dieselbe
 * Rechnung Unsinn -- zwei Saetze zu 40 kg sind keine 80 kg.
 *
 * null, wenn kein einziges Intervall eine Distanz traegt: Eine 0 stuende fuer
 * "null Meter gelaufen" und liesse sich von "nichts eingetragen" nicht mehr
 * unterscheiden -- dieselbe Ueberlegung wie bei saetze_volumen().
 */
function saetze_distanz(array $saetze): ?int {
    $summe = 0;
    $zaehlt = false;

    foreach ($saetze as $s) {
        if (($s['distanz_m'] ?? null) !== null) {
            $summe += (int)$s['distanz_m'];
            $zaehlt = true;
        }
    }

    return $zaehlt ? $summe : null;
}

/** Die Gesamtzeit einer Intervallfolge in Sekunden. Wie saetze_distanz(). */
function saetze_dauer(array $saetze): ?int {
    $summe = 0;
    $zaehlt = false;

    foreach ($saetze as $s) {
        if (($s['dauer_s'] ?? null) !== null) {
            $summe += (int)$s['dauer_s'];
            $zaehlt = true;
        }
    }

    return $zaehlt ? $summe : null;
}

/**
 * Die Durchschnittsgeschwindigkeit in km/h.
 *
 * null, sobald einer der beiden Werte fehlt oder die Zeit 0 ist -- gerechnet
 * wird nicht mit Annahmen. Gerechnet wird in PHP und nicht in SQL, aus dem
 * Grund, aus dem auch Volumen und 1RM hier stehen: Die Datenmenge ist winzig,
 * und die Formel gehoert dorthin, wo man sie lesen kann.
 */
function tempo_kmh(?int $meter, ?int $sekunden): ?float {
    if ($meter === null || $sekunden === null || $sekunden <= 0 || $meter <= 0) {
        return null;
    }
    return $meter * 3.6 / $sekunden;
}

/** Die Zeit fuer einen Kilometer, in Sekunden. Gegenrechnung zu tempo_kmh(). */
function sekunden_je_km(?int $meter, ?int $sekunden): ?int {
    if ($meter === null || $sekunden === null || $meter <= 0 || $sekunden <= 0) {
        return null;
    }
    return (int)round($sekunden * 1000 / $meter);
}

/**
 * Die Pace als fertiger Text: "10,9 km/h · 5:30 /km".
 *
 * BEIDE Angaben, weil sie verschiedene Fragen beantworten: Die
 * Geschwindigkeit steht am Geraet und laesst sich dort vergleichen, die Zeit
 * je Kilometer ist die Zahl, in der Laeufer denken. Eine von beiden allein
 * zwaenge jedes Mal zum Kopfrechnen.
 *
 * Eine Nachkommastelle bei km/h: Die zweite waere vorgetaeuschte Genauigkeit
 * -- die Distanzanzeige eines Laufbands ist selbst auf 10 m gerundet.
 *
 * Das Gegenstueck heisst paceText() in index.js und muss zeichengleich
 * antworten; die Pace steht in der Trainingsansicht server-gerendert und wird
 * beim Tippen im Browser nachgezogen.
 */
function pace_text(?int $meter, ?int $sekunden): string {
    $kmh = tempo_kmh($meter, $sekunden);
    $jeKm = sekunden_je_km($meter, $sekunden);

    if ($kmh === null || $jeKm === null) {
        return '—';
    }

    return format_decimal(round($kmh, 1)) . ' km/h · ' . dauer_mmss($jeKm) . ' /km';
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
                e.erfassung,
                e.image_path, e.image_crop, e.archived,
                orig.name_de     AS plan_uebung_name,
                orig.name_en     AS plan_uebung_name_en,
                wl.id            AS log_id,
                wl.weight, wl.distanz_m, wl.dauer_s, wl.done, wl.performed_at
           FROM plan_exercises pe
           LEFT JOIN exercise_swaps sw
                  ON sw.plan_exercise_id = pe.id AND sw.session_id = :sid
           JOIN exercises e
                  ON e.id = COALESCE(sw.replacement_exercise_id, pe.exercise_id)
           JOIN exercises orig
                  ON orig.id = pe.exercise_id
           LEFT JOIN workout_log wl
                  ON wl.plan_exercise_id = pe.id AND wl.session_id = :sid
          -- Der Plan PLUS die Positionen, die nur zu dieser Einheit gehoeren
          -- (§7.6). Ohne die zweite Haelfte fehlte die spontan hinzugefuegte
          -- Uebung genau da, wo man sie eingetragen hat; ohne die erste stuende
          -- sie in JEDER kuenftigen Einheit.
          WHERE pe.plan_id = :pid
            AND (pe.session_id IS NULL OR pe.session_id = :sid)
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
    // gibt es nichts zu holen.
    $saetzeDerEinheit = $sessionId !== null ? saetze_der_einheit($sessionId) : [];

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
        $peId       = (int)$z['plan_exercise_id'];
        $ausdauer   = ist_ausdauer($z['erfassung']);

        // Je Erfassungsart nur die Vorbelegung holen, die auch gebraucht wird
        // -- beide waeren eine zusaetzliche Abfrage je Position, und die Werte
        // der jeweils anderen Art zeigt die Seite ohnehin nirgends an.
        $letztes  = $ausdauer ? null : letztes_gewicht($userId, $exerciseId);
        $letzteAd = $ausdauer
            ? letzte_ausdauerwerte($userId, $exerciseId)
            : ['distanz_m' => null, 'dauer_s' => null];

        $ergebnis[] = [
            'plan_exercise_id' => $peId,
            'exercise_id'      => $exerciseId,
            'name_de'          => (string)$z['name_de'],
            'name_en'          => $z['name_en'],
            'description'      => $z['description'],
            'focus'            => $z['focus'],
            'equipment'        => $z['equipment'],
            'erfassung'        => $ausdauer ? 'ausdauer' : 'kraft',
            'image_path'       => $z['image_path'],
            'image_crop'       => $z['image_crop'],
            'archived'         => (int)$z['archived'] === 1,
            'getauscht'        => (int)$z['getauscht'] === 1,
            'plan_uebung_name' => (string)$z['plan_uebung_name'],
            'plan_uebung_name_en' => $z['plan_uebung_name_en'],
            'muskelgruppen'    => $gruppen->fetchAll(),
            'erledigt'         => $erledigt,
            'hat_eintrag'      => $hatEintrag,
            // Vorbelegung: in der Einheit protokollierter Wert, sonst der
            // letzte bekannte. Ein bewusst geleertes Feld einer bereits
            // protokollierten Position bleibt leer und wird nicht wieder
            // aufgefuellt.
            'weight'           => $hatEintrag ? to_decimal_or_null($z['weight']) : $letztes,
            'letztes_gewicht'  => $letztes,
            // Dasselbe Spiel fuer Ausdauer: in der Einheit protokollierte Werte,
            // sonst die vom letzten Mal.
            'distanz_m'        => $hatEintrag
                ? ($z['distanz_m'] === null ? null : (int)$z['distanz_m'])
                : $letzteAd['distanz_m'],
            'dauer_s'          => $hatEintrag
                ? ($z['dauer_s'] === null ? null : (int)$z['dauer_s'])
                : $letzteAd['dauer_s'],
            'letzte_distanz_m' => $letzteAd['distanz_m'],
            'letzte_dauer_s'   => $letzteAd['dauer_s'],
            // Die Saetze DIESER Einheit -- die Eingabe von jetzt.
            'saetze'           => $saetzeDerEinheit[$peId] ?? [],
            // Die Saetze vom LETZTEN Mal -- Anzeige und Vorbelegung. Die
            // laufende Einheit ist ausgeschlossen, sonst zeigte "letztes Mal"
            // auf das, was man gerade selbst eingetragen hat.
            'letzte_saetze'    => letzte_saetze($userId, $exerciseId, $sessionId),
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
          -- Dasselbe Fenster wie in plan_positionen(): Was heute im Programm
          -- steht, ist kein Tauschvorschlag -- auch das spontan Hinzugefuegte.
          WHERE pe.plan_id = :pid
            AND (pe.session_id IS NULL OR pe.session_id = :sid)'
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
 * Beendet die offene Einheit und traegt fehlende Haekchen nach (§7.6).
 *
 * ZWEI Schreibvorgaenge, und deshalb seit 1.4.5 in einer Transaktion: erst das
 * Sicherheitsnetz aus protokollierte_positionen_abschliessen(), dann das
 * ended_at. In dieser Reihenfolge, weil eine Einheit, die schon beendet ist,
 * fachlich nicht mehr angefasst werden darf -- und ein Abbruch dazwischen
 * duerfte keine geschlossene Einheit mit halb nachgetragenen Haekchen
 * hinterlassen.
 *
 * Die Rotation merkt sich weiterhin nichts: Sie liest ihren Stand in
 * zuletzt_trainierter_plan() aus der Historie, users.last_plan_id wird weder
 * gelesen noch geschrieben (Fallstrick 21).
 *
 * @return array{id:int,nachgetragen:int}|null null, wenn keine Einheit laeuft.
 */
function einheit_beenden(int $userId): ?array {
    $offen = offene_einheit($userId);
    if ($offen === null) {
        return null;
    }

    $sessionId = (int)$offen['id'];

    return db_transaction(static function () use ($sessionId): array {
        $nachgetragen = protokollierte_positionen_abschliessen($sessionId);

        db()->prepare('UPDATE sessions SET ended_at = ? WHERE id = ?')
            ->execute([now(), $sessionId]);

        return ['id' => $sessionId, 'nachgetragen' => $nachgetragen];
    });
}

/**
 * Das Sicherheitsnetz beim Beenden: Wer Saetze eingetragen hat, hat die Uebung
 * gemacht (§7.6, Vorgabe des Benutzers vom 2026-09-01).
 *
 * Jede Position dieser Einheit mit mindestens EINEM Satz bekommt beim Beenden
 * `done = 1`. Offen bleibt allein, wozu gar nichts eingetragen wurde -- eine
 * Uebung, die man ausgelassen hat.
 *
 * **Warum es das braucht.** "Protokolliert" und "erledigt" sind zwei Zustaende
 * (Fallstrick 18), und genau diese Trennung hat zweimal ein Haekchen gekostet:
 * Die Zeile stand mit Saetzen und `done = 0` in der Datenbank, waehrend auf dem
 * Bildschirm das Haekchen sass -- einmal, weil eine Ablehnung es zurueckdrehte
 * (behoben in 1.4.3), einmal, weil die Warteschlange den noch nicht
 * verschickten Eintrag wegloeschte (behoben in 1.4.5). Beide Male entstand aus
 * einer Anzeige ein falscher Zaehlstand im Verlauf ("7/8").
 *
 * **Warum hier und nicht laufend.** Waehrend der Einheit muss die Trennung
 * bleiben: Mit dem ersten Satz ist man mitten in der Uebung und nicht fertig
 * damit. Wuerde `done` schon dort mitlaufen, faerbte sich die Karte sofort
 * blau, "x/n" zaehlte zu frueh, und die Rueckfrage "Alle Uebungen erledigt --
 * Training beenden?" kaeme, waehrend man noch am Geraet steht. Beim BEENDEN
 * ist die Frage dagegen beantwortet: Was Saetze traegt, ist gemacht.
 *
 * **Warum serverseitig.** Der Browser ist die Schicht, in der beide Fehler
 * sassen. Ein Netz, das durch die kaputte Ebene laeuft, ist keines
 * (Fallstrick 12). Hier greift es unabhaengig davon, was die Oberflaeche
 * gerade glaubt -- und auch dann, wenn ein Aufruf nie angekommen ist.
 *
 * **Die Folge, die man kennen muss:** Eine bewusst abgebrochene Uebung -- zwei
 * Saetze gemacht, dann aufgehoert -- gilt danach als erledigt. Wer sie offen
 * halten will, loescht ihre Saetze, bevor er beendet. Das ist die ausdrueckliche
 * Entscheidung des Benutzers: Ein vergessenes Haekchen ist der haeufigere Fall
 * und der teurere, weil er den Verlauf dauerhaft falsch zaehlt.
 *
 * **Zeilen ohne plan_exercise_id sind ausdruecklich MIT dabei.** Waehrend einer
 * laufenden Einheit kann keine entstehen -- die Struktursperre verbietet das
 * Entfernen von Positionen (Fallstrick 31) --, und faende sich doch eine, waere
 * sie eine Uebung, die damals im Plan stand. Der Zaehler laeuft dadurch nicht
 * ueber: einheiten_verlauf() nimmt das GROESSERE von Protokollzeilen und
 * Planpositionen (Fallstrick 33).
 *
 * `done = 0` steht in der WHERE-Klausel, damit rowCount() nur die wirklich
 * nachgetragenen zaehlt und nicht jede ohnehin abgehakte mitrechnet.
 *
 * Laeuft ausschliesslich innerhalb der Transaktion aus einheit_beenden().
 *
 * @return int Wie viele Haekchen nachgetragen wurden. 0 ist der Normalfall.
 */
function protokollierte_positionen_abschliessen(int $sessionId): int {
    $stmt = db()->prepare(
        'UPDATE workout_log
            SET done = 1
          WHERE session_id = ?
            AND done = 0
            AND EXISTS (SELECT 1 FROM workout_sets ws
                         WHERE ws.workout_log_id = workout_log.id)'
    );
    $stmt->execute([$sessionId]);

    return $stmt->rowCount();
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

/**
 * Tauschvorschlaege fuer eine AUSDAUERuebung: alle anderen Ausdaueruebungen.
 *
 * Ohne Muskelgruppe und ohne Rangfolge -- es gibt keine "naechstliegende"
 * Ausdauerform, und alphabetisch findet man am Geraet, was man sucht. Die
 * Nachbarfunktion sortiert dagegen erst nach Untergruppe, weil dort ein Ersatz
 * naeher oder ferner liegen kann.
 *
 * Das Feld `gruppe` ist hier null -- die Vorschlagskarte zeigt dann keine
 * Gruppenzeile, was richtig ist: Es gibt keine.
 */
function ausdauer_vorschlaege(array $ausschluss, string $platzhalter): array {
    $stmt = db()->prepare(
        "SELECT e.id, e.name_de, e.name_en, e.equipment, e.erfassung,
                e.image_path, e.image_crop, NULL AS gruppe
           FROM exercises e
          WHERE COALESCE(e.erfassung, 'kraft') = 'ausdauer'
            AND e.id NOT IN ($platzhalter)
            AND e.archived = 0
          ORDER BY e.name_de"
    );
    $stmt->execute($ausschluss);

    $vorschlaege = $stmt->fetchAll();
    foreach ($vorschlaege as &$v) {
        $v['muskelgruppen'] = [];
    }

    return $vorschlaege;
}

function tausch_vorschlaege(int $exerciseId, array $ausschluss = []): array {
    // Uebungen, die ohnehin im laufenden Plan stehen, sind kein Ersatz --
    // man macht sie an diesem Tag sowieso. Ohne diesen Ausschluss bestuenden
    // die Vorschlaege groesstenteils aus Zeilen, die zwei Positionen weiter
    // unten schon warten.
    // Vorgeschlagen wird nur, was sich GENAUSO protokollieren laesst (1.4.0).
    // Das ist kein Widerspruch zur Regel darunter, dass das Geraet keine Rolle
    // spielt, sondern eine Frage anderer Art: Beim Geraet geht es um den
    // Ausweg bei besetzter Maschine, hier darum, ob die bereits sichtbaren
    // Felder ueberhaupt noch passen. Ohne diesen Filter bekaeme man fuer die
    // Beinpresse ein Laufband angeboten -- beide haengen an "Beine" --, und
    // die Position stuende danach mit einem Gewichtsfeld an einer Uebung, die
    // in Metern protokolliert wird.
    $stmtE = db()->prepare('SELECT erfassung FROM exercises WHERE id = ?');
    $stmtE->execute([$exerciseId]);
    $ausdauer = ist_ausdauer((string)($stmtE->fetchColumn() ?: ''));

    $ausschluss = array_values(array_unique(array_merge([$exerciseId], $ausschluss)));
    $platzhalter = implode(',', array_fill(0, count($ausschluss), '?'));

    // Bei AUSDAUER entscheidet allein die Trainingsart -- die Muskelgruppe
    // spielt keine Rolle und ist dort seit 1.4.1 gar nicht mehr gesetzt (§6.3).
    //
    // Das ist kein Notbehelf, sondern genau der Fall, fuer den es den Tausch
    // gibt (§7.5): Das Laufband ist besetzt, also nimmt man den Crosstrainer.
    // Wuerde hier weiter ueber die Primaergruppe gesucht, faende eine
    // Ausdaueruebung ueberhaupt keinen Partner mehr -- primaergruppe_von_uebung()
    // liefert fuer sie null.
    if ($ausdauer) {
        return ausdauer_vorschlaege($ausschluss, $platzhalter);
    }

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
        "SELECT e.id, e.name_de, e.name_en, e.equipment, e.erfassung,
                e.image_path, e.image_crop, mg.name_de AS gruppe
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
            -- COALESCE, weil eine Sicherung von vor 1.4.0 die Spalte zwar
            -- mitbringt, ein von Hand eingetragener NULL-Wert aber denkbar
            -- bleibt; unbekannt gilt als Kraft, wie ueberall sonst.
            AND COALESCE(e.erfassung, 'kraft') = 'kraft'
          -- Naechstliegender Ersatz zuerst: erst die Uebungen DERSELBEN
          -- Untergruppe, danach der Rest der Hauptgruppe. Wer Ersatz fuer eine
          -- Trizeps-Uebung sucht, bekommt sonst Bizeps-Uebungen zwischen die
          -- eigentlichen Alternativen sortiert, nur weil ihr Name frueher im
          -- Alphabet steht. Die uebrigen Untergruppen bleiben durch
          -- mg.sort_order jeweils beieinander.
          ORDER BY CASE WHEN mg.id = CAST(? AS INTEGER) THEN 0 ELSE 1 END,
                   mg.sort_order, mg.name_de, e.name_de"
    );
    $primaer = primaergruppe_von_uebung($exerciseId);
    if ($primaer === null) {
        // Eine Kraftuebung ohne Primaergruppe hat keine Tauschklasse. Das ist
        // seit jeher so und bleibt: In der Oberflaeche ist die Gruppe fuer
        // Kraft Pflicht, denkbar ist der Fall nur nach einer alten Sicherung --
        // und die Uebungsliste mahnt ihn dort sichtbar an.
        return [];
    }

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
                p.name  AS plan_name,
                sp.name AS split_name,
                (SELECT COUNT(*) FROM workout_log wl
                  WHERE wl.session_id = s.id AND wl.done = 1) AS erledigt,
                -- Das "n" der EINHEIT, nicht das des heutigen Plans -- und
                -- das ist schwieriger, als es aussieht: Wie viele Positionen
                -- der Plan DAMALS hatte, steht nirgends. Wird eine Uebung
                -- spaeter aus dem Plan genommen, setzt ON DELETE SET NULL ihre
                -- plan_exercise_id auf NULL (§4.1); die Protokollzeile bleibt
                -- und zaehlt in "x" mit, waehrend der Zaehler darunter nur die
                -- verbliebenen Positionen sieht. Genau so entstand "10/8".
                --
                -- Deshalb das GROESSERE von beidem: die Zahl der
                -- Protokollzeilen dieser Einheit und die der Planpositionen.
                --
                --   Alles protokolliert, Plan seither geschrumpft -> 10/10
                --   Zwei ausgelassen, Plan unveraendert           ->  6/8
                --
                -- Damit kann "x" nie ueber "n" laufen -- jede erledigte Zeile
                -- ist eine Protokollzeile, und die zaehlen mit. Der Fall, den
                -- auch das nicht loest: Ein Plan, der seither GEWACHSEN ist,
                -- laesst eine alte Einheit zu niedrig aussehen. Dafuer muesste
                -- "n" beim Beenden in der Einheit gespeichert werden, und das
                -- huelfe der Vergangenheit ohnehin nicht.
                --
                -- Die zweite Haelfte des ODER traegt weiterhin die Positionen,
                -- die nur zu DIESER Einheit gehoerten (§7.6).
                MAX(
                    (SELECT COUNT(*) FROM workout_log wl2 WHERE wl2.session_id = s.id),
                    (SELECT COUNT(*) FROM plan_exercises pe
                      WHERE pe.plan_id = s.plan_id
                        AND (pe.session_id IS NULL OR pe.session_id = s.id))
                ) AS gesamt
           FROM sessions s
           LEFT JOIN plans  p  ON p.id  = s.plan_id
           LEFT JOIN splits sp ON sp.id = p.split_id
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
                wl.distanz_m, wl.dauer_s,
                e.name_de, e.name_en, e.erfassung,
                pe.sort_order,
                pe.exercise_id AS plan_uebung_id,
                orig.name_de   AS plan_uebung_name,
                orig.name_en   AS plan_uebung_name_en
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
 * Alle Uebungen, fuer die dieser Benutzer je einen Wert protokolliert hat --
 * mit Anzahl, letztem Wert und Bestwert.
 *
 * Grundlage der Verlaufsansicht. Uebungen ganz ohne Werte tauchen nicht auf:
 * Fuer sie gaebe es nichts zu zeigen.
 *
 * "Wert" hiess bis 1.4.0 ausschliesslich `weight`, und genau das war der Punkt,
 * an dem eine Ausdaueruebung aus dem Verlauf fiel -- vollstaendig und ohne
 * Meldung, sie stand einfach nicht in der Liste. Der Filter fragt jetzt nach
 * IRGENDEINEM der drei Werte.
 *
 * bestwert bedeutet je nach Erfassungsart etwas anderes: bei Kraft das
 * schwerste Gewicht, bei Ausdauer die weiteste Distanz. Beide werden geholt --
 * welcher gilt, entscheidet die Anzeige anhand von erfassung. Eine einzige
 * Spalte mit einem CASE waere kuerzer und in der Antwort nicht mehr deutbar.
 */
function uebungen_mit_verlauf(int $userId): array {
    $stmt = db()->prepare(
        'SELECT wl.exercise_id, e.name_de, e.name_en, e.image_path, e.erfassung,
                COUNT(*)          AS anzahl,
                MAX(wl.weight)    AS bestwert,
                MAX(wl.distanz_m) AS bestdistanz,
                MAX(wl.performed_at) AS zuletzt
           FROM workout_log wl
           JOIN exercises e ON e.id = wl.exercise_id
          WHERE wl.user_id = ?
            AND (wl.weight IS NOT NULL
                 OR wl.distanz_m IS NOT NULL
                 OR wl.dauer_s IS NOT NULL)
          GROUP BY wl.exercise_id, e.name_de, e.name_en, e.image_path, e.erfassung
          ORDER BY zuletzt DESC, e.name_de'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Der Wertverlauf einer Uebung, aelteste zuerst (fuer die Kurve).
 *
 * wl.id kommt mit, damit sich ueber saetze_zu_logs() die Saetze je Punkt
 * zuordnen lassen -- daraus entstehen Volumen und geschaetztes 1RM (§7.8).
 *
 * Der Name bleibt trotz der Ausdauerwerte: Er wird aus einem halben Dutzend
 * Stellen zitiert, und die Funktion tut weiterhin dasselbe -- sie liefert die
 * Messreihe einer Uebung. Welche Spalte davon die Kurve traegt, entscheidet
 * die Aufrufstelle ueber den $feld-Parameter von verlauf_kurve().
 *
 * Der IS-NOT-NULL-Filter haengt an der Erfassungsart: Bei Kraft zaehlt ein
 * Punkt ohne Gewicht nicht (er traegt keine Aussage), bei Ausdauer einer ohne
 * Distanz UND ohne Zeit. Wuerde hier pauschal auf alle drei geprueft, stuenden
 * in der Kraftkurve Punkte ohne Gewicht -- als Luecken sichtbar, aber in der
 * Tabelle darunter als leere Zeilen.
 */
function gewichts_verlauf(
    int $userId,
    int $exerciseId,
    string $erfassung = ERFASSUNG_VORGABE,
    int $limit = 60
): array {
    $filter = ist_ausdauer($erfassung)
        ? '(wl.distanz_m IS NOT NULL OR wl.dauer_s IS NOT NULL)'
        : 'wl.weight IS NOT NULL';

    $stmt = db()->prepare(
        'SELECT wl.id AS log_id, wl.weight, wl.distanz_m, wl.dauer_s,
                wl.performed_at
           FROM workout_log wl
          WHERE wl.user_id = ? AND wl.exercise_id = ? AND ' . $filter . '
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
