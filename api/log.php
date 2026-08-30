<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/training.php';
require_once __DIR__ . '/../lib/splits.php';

bootstrap_session();
require_login_api();
require_passwort_gesetzt_api();

/**
 * Protokollieren von "Erledigt", Gewicht und Saetzen (§7.4).
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_err('Nur POST', 405);
}

csrf_check();

const GEWICHT_MAX = 1000.0;
const WDH_MAX     = 200;
const SAETZE_MAX  = 20;

// Grenzen fuer Ausdauer (§7.4). 100 km und 6 Stunden sind so gewaehlt, dass
// niemand sie im Studio je erreicht -- sie fangen den Vertipper ab (500000
// statt 5000) und nicht den Ultramarathon. Die Zeit steht in Sekunden, weil
// genau so gespeichert wird; mm:ss ist reine Ein- und Ausgabe.
const DISTANZ_MAX = 100000;
const DAUER_MAX   = 21600;

$eingabe = read_json_body();

// Es gibt bewusst KEIN "update": Ein Wert wird geaendert, indem man das
// Haekchen entfernt, den Wert korrigiert und neu abhakt (§7.4). Damit gibt es
// einen Mechanismus statt zweier -- genau wie beim Tausch (§7.5).
//
// Auch die Saetze des Expertenmodus kommen ohne eigene Aktion aus: Sie reisen
// als vollstaendige Liste im "check" mit. Siehe saetze_pruefen().
match (to_str($eingabe['action'] ?? '')) {
    'check'   => aktion_abhaken($eingabe),
    'uncheck' => aktion_abwaehlen($eingabe),
    default   => json_err('Unbekannte Aktion', 400),
};

/**
 * Laedt eine Planposition und stellt sicher, dass sie dem angemeldeten
 * Benutzer gehoert.
 *
 * Das ist der IDOR-Schutz aus §5 an der Stelle, an der er am ehesten vergessen
 * wird: Die plan_exercise_id kommt vom Client, und ohne diese Pruefung liesse
 * sich in den Plan eines anderen Benutzers hineinprotokollieren.
 */
function position_laden(int $peId): array {
    $stmt = db()->prepare(
        // e.erfassung entscheidet ueber das ganze Feldpaar dieser Anfrage --
        // und sie kommt AUS DER DATENBANK und nie aus der Nutzlast. Sonst
        // schriebe ein manipulierter Aufruf Meter in eine Kraftuebung.
        //
        // Gelesen wird die Erfassungsart der PLANUEBUNG, nicht die der
        // moeglicherweise eingetauschten. Das ist zulaessig, weil beide
        // zwangslaeufig uebereinstimmen: Sowohl api/swap.php als auch
        // api/plans.php lassen einen Tausch nur innerhalb derselben
        // Erfassungsart zu (§7.5). Faellt diese Regel je, gehoert hier ein
        // COALESCE ueber exercise_swaps her.
        'SELECT pe.id, pe.plan_id, pe.exercise_id, sp.user_id, e.erfassung
           FROM plan_exercises pe
           JOIN plans  p  ON p.id  = pe.plan_id
           JOIN splits sp ON sp.id = p.split_id
           JOIN exercises e ON e.id = pe.exercise_id
          WHERE pe.id = ?'
    );
    $stmt->execute([$peId]);
    $position = $stmt->fetch();

    if ($position === false) {
        json_err('Diese Planposition gibt es nicht (mehr).', 404);
    }
    // splits.user_id und NICHT plans.user_id -- letztere ist seit 1.2.0 tot
    // (siehe schema.sql). Fuer eine VORLAGE ist sie NULL, der Vergleich schlaegt
    // also fehl, und damit laesst sich in den Katalog nicht hineinprotokolliert
    // und nicht hineingetauscht werden. Genau so soll es sein: Trainiert wird
    // ausschliesslich auf der eigenen Kopie.
    if ($position['user_id'] === null || (int)$position['user_id'] !== current_user_id()) {
        json_err('Kein Zugriff auf diesen Plan.', 403);
    }

    // Eine Einheit gehoert zu genau einem Plan. Eine Position aus einem anderen
    // Plan hier zuzulassen wuerde workout_log.plan_id gegen sessions.plan_id
    // laufen lassen und den Zaehler "x/n" ueber n treiben.
    if (!position_passt_zur_einheit(current_user_id(), (int)$position['plan_id'])) {
        json_err(
            'Diese Übung gehört zu einem anderen Plan als die laufende Einheit. '
            . 'Bitte die Seite neu laden.',
            409
        );
    }

    return $position;
}

/**
 * Prueft die Gewichtseingabe. Sie darf leer sein -- "Erledigt" funktioniert
 * auch ohne Gewichtsangabe, etwa bei Bauch- oder Koerpergewichtsuebungen (§7.4).
 *
 * **Diese Funktion sieht nach einfachem Modus aus und darf trotzdem NICHT
 * weg** (seit 1.4.3, als es den einfachen Modus nicht mehr gibt). Sie traegt
 * zwei Faelle, die es beide weiterhin gibt:
 *
 * 1. **Abhaken ohne Werte.** Wer nur das Haekchen setzt, schickt eine leere
 *    Satzliste; saetze_pruefen() liefert dafuer null, und der Weg laeuft hier
 *    entlang. `weight` fehlt in der Nutzlast, das Ergebnis ist null -- genau
 *    richtig, die Position ist erledigt und traegt keine Zahl.
 * 2. **Wartende Eintraege von VOR 1.4.3.** Wer beim Rollout gerade im
 *    einfachen Modus trainierte, hat Eintraege im localStorage mit `weight`
 *    und ohne `sets`. Solange es diese Funktion gibt, werden sie beim
 *    Nachholen korrekt gespeichert; ohne sie waere das Gewicht stillschweigend
 *    weg. Genau deshalb konnte der Warteschlangen-Schluessel auf `-v3`
 *    bleiben, statt beim Umbau alle wartenden Eingaben zu verwerfen.
 */
function gewicht_pruefen(array $eingabe): ?float {
    $roh = $eingabe['weight'] ?? null;
    $gewicht = ($roh === null || $roh === '') ? null : to_decimal_or_null($roh);

    if ($roh !== null && $roh !== '' && $gewicht === null) {
        json_err('Bitte die Eingabe prüfen.', 422, ['weight' => 'Bitte eine Zahl eingeben.']);
    }
    if ($gewicht !== null && ($gewicht < 0 || $gewicht > GEWICHT_MAX)) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'weight' => 'Zwischen 0 und ' . (int)GEWICHT_MAX . ' kg.',
        ]);
    }

    return $gewicht;
}

/**
 * Das Gegenstueck zu gewicht_pruefen() fuer den einfachen Modus einer
 * Ausdaueruebung: die zwei Felder der Karte.
 *
 * Beide duerfen leer sein, aus demselben Grund wie das Gewicht: "Erledigt"
 * funktioniert auch ohne Angabe. Und sie bleibt aus demselben Grund stehen wie
 * gewicht_pruefen() darueber -- die beiden Faelle stehen dort. Anders als beim Intervall wird hier NICHT
 * verlangt, dass mindestens eines gefuellt ist -- ein Haekchen ohne Werte ist
 * eine gueltige Aussage ("gemacht, nichts notiert"), waehrend eine leere
 * Intervallzeile nur Platz braucht.
 *
 * @return array{distanz_m:?int,dauer_s:?int}
 */
function ausdauer_pruefen(array $eingabe): array {
    $rohM = $eingabe['distanz'] ?? null;
    $rohZ = $eingabe['dauer'] ?? null;

    $meter = ($rohM === null || $rohM === '') ? null : to_int_or_null($rohM);
    if ($rohM !== null && $rohM !== '' && $meter === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'distanz' => 'Distanz in ganzen Metern angeben.',
        ]);
    }
    if ($meter !== null && ($meter < 1 || $meter > DISTANZ_MAX)) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'distanz' => 'Zwischen 1 und ' . DISTANZ_MAX . ' m.',
        ]);
    }

    $sek = ($rohZ === null || $rohZ === '') ? null : dauer_aus_eingabe($rohZ);
    if ($rohZ !== null && $rohZ !== '' && $sek === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'dauer' => 'Zeit als mm:ss angeben, z. B. 24:30.',
        ]);
    }
    if ($sek !== null && ($sek < 1 || $sek > DAUER_MAX)) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'dauer' => 'Zwischen 0:01 und ' . dauer_mmss(DAUER_MAX) . '.',
        ]);
    }

    return ['distanz_m' => $meter, 'dauer_s' => $sek];
}

/**
 * Prueft die Satzliste des Expertenmodus (§7.4).
 *
 * DIE GANZE LISTE IST DIE NUTZLAST, nicht der einzelne Satz. Das ist die
 * tragende Entscheidung dieses Endpunkts: Der Aufruf ersetzt die Saetze einer
 * Position vollstaendig und ist damit idempotent -- beliebig oft wiederholbar,
 * ohne dass Dubletten entstehen. Genau darauf verlaesst sich die
 * Warteschlange (§7.4), die einen Eintrag je Planposition haelt, ihn bei jeder
 * Aenderung ueberschreibt und ihn nach einem Funkloch erneut abschickt. Ein
 * "Satz anlegen"-Endpunkt haette dagegen bei jedem Wiederversuch einen vierten
 * Satz erzeugt.
 *
 * Rueckgabe null heisst "keine Satzliste dabei" -- der einfache Modus. Eine
 * mitgeschickte LEERE Liste zaehlt genauso: eine Position ohne Satz ist eine
 * Position ohne Satz, und zwei Schreibweisen fuer dieselbe Aussage brauchen
 * niemanden zu beschaeftigen.
 *
 * @return list<array{satz_nr:int, reps:?int, weight:?float}>|null
 */
function saetze_pruefen(array $eingabe, string $erfassung): ?array {
    $roh = $eingabe['sets'] ?? null;

    if ($roh === null) {
        return null;
    }
    if (!is_array($roh)) {
        json_err('Bitte die Eingabe prüfen.', 422, ['sets' => 'Ungültige Satzliste.']);
    }
    if ($roh === []) {
        return null;
    }
    if (count($roh) > SAETZE_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Höchstens ' . SAETZE_MAX . ' Sätze je Übung.',
        ]);
    }

    $saetze = [];
    $nr = 0;

    foreach ($roh as $eintrag) {
        $nr++;
        if (!is_array($eintrag)) {
            json_err('Bitte die Eingabe prüfen.', 422, [
                'sets' => 'Satz ' . $nr . ': ungültig.',
            ]);
        }

        // Welches Feldpaar gilt, entscheidet allein die Erfassungsart aus der
        // Datenbank. Die Felder der jeweils anderen Art werden VERWORFEN und
        // nicht etwa mitgespeichert: Eine Zeile mit Gewicht UND Distanz waere
        // von keiner Anzeige mehr sinnvoll darstellbar, und welcher der beiden
        // Werte dann gilt, koennte niemand mehr beantworten.
        if (ist_ausdauer($erfassung)) {
            $meter = satz_distanz_pruefen($eintrag['distanz'] ?? null, $nr);
            $dauer = satz_dauer_pruefen($eintrag['dauer'] ?? null, $nr);

            if ($meter === null && $dauer === null) {
                json_err('Bitte die Eingabe prüfen.', 422, [
                    'sets' => 'Intervall ' . $nr . ': Distanz oder Zeit angeben.',
                ]);
            }

            $saetze[] = [
                'satz_nr'   => $nr,
                'reps'      => null,
                'weight'    => null,
                'distanz_m' => $meter,
                'dauer_s'   => $dauer,
            ];
            continue;
        }

        $reps   = satz_wdh_pruefen($eintrag['reps'] ?? null, $nr);
        $gewicht = satz_gewicht_pruefen($eintrag['weight'] ?? null, $nr);

        // Ein Satz ohne beides sagt nichts aus -- er waere eine Zeile, die
        // Platz braucht und keine Frage beantwortet.
        if ($reps === null && $gewicht === null) {
            json_err('Bitte die Eingabe prüfen.', 422, [
                'sets' => 'Satz ' . $nr . ': Wiederholungen oder Gewicht angeben.',
            ]);
        }

        // satz_nr wird hier vergeben und nicht vom Client uebernommen: Die
        // Reihenfolge der Liste IST die Reihenfolge der Saetze, und damit kann
        // es keine Luecken und keine doppelten Nummern geben.
        $saetze[] = [
            'satz_nr'   => $nr,
            'reps'      => $reps,
            'weight'    => $gewicht,
            'distanz_m' => null,
            'dauer_s'   => null,
        ];
    }

    return $saetze;
}

/**
 * Die Distanz eines Intervalls in Metern: leer erlaubt (nur Zeit gelaufen),
 * sonst 1 bis DISTANZ_MAX.
 *
 * Ganze Meter und keine Kommastelle: Kein Geraet im Studio zeigt Zentimeter,
 * und ein Dezimalfeld laedt dazu ein, Kilometer einzutragen.
 */
function satz_distanz_pruefen(mixed $roh, int $nr): ?int {
    if ($roh === null || $roh === '') {
        return null;
    }

    $meter = to_int_or_null($roh);
    if ($meter === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Intervall ' . $nr . ': Distanz in ganzen Metern angeben.',
        ]);
    }
    if ($meter < 1 || $meter > DISTANZ_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Intervall ' . $nr . ': zwischen 1 und ' . DISTANZ_MAX . ' m.',
        ]);
    }

    return $meter;
}

/**
 * Die Zeit eines Intervalls: leer erlaubt (nur Distanz), sonst mm:ss bis
 * DAUER_MAX. Geparst wird in dauer_aus_eingabe() (lib/helpers.php), damit
 * Server und Browser dieselben Eingaben annehmen.
 */
function satz_dauer_pruefen(mixed $roh, int $nr): ?int {
    if ($roh === null || $roh === '') {
        return null;
    }

    $sek = dauer_aus_eingabe($roh);
    if ($sek === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Intervall ' . $nr . ': Zeit als mm:ss angeben, z. B. 24:30.',
        ]);
    }
    if ($sek < 1 || $sek > DAUER_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Intervall ' . $nr . ': zwischen 0:01 und '
                . dauer_mmss(DAUER_MAX) . '.',
        ]);
    }

    return $sek;
}

/**
 * Wiederholungen eines Satzes: leer erlaubt (Halten auf Zeit), sonst eine
 * ganze Zahl zwischen 1 und WDH_MAX.
 */
function satz_wdh_pruefen(mixed $roh, int $nr): ?int {
    if ($roh === null || $roh === '') {
        return null;
    }

    $reps = to_int_or_null($roh);
    if ($reps === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': Wiederholungen als ganze Zahl angeben.',
        ]);
    }
    if ($reps < 1 || $reps > WDH_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': zwischen 1 und ' . WDH_MAX . ' Wiederholungen.',
        ]);
    }

    return $reps;
}

/**
 * Gewicht eines Satzes: leer erlaubt (Koerpergewicht), sonst 0 bis GEWICHT_MAX.
 */
function satz_gewicht_pruefen(mixed $roh, int $nr): ?float {
    if ($roh === null || $roh === '') {
        return null;
    }

    $gewicht = to_decimal_or_null($roh);
    if ($gewicht === null) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': Gewicht als Zahl angeben.',
        ]);
    }
    if ($gewicht < 0 || $gewicht > GEWICHT_MAX) {
        json_err('Bitte die Eingabe prüfen.', 422, [
            'sets' => 'Satz ' . $nr . ': zwischen 0 und ' . (int)GEWICHT_MAX . ' kg.',
        ]);
    }

    return $gewicht;
}

/**
 * Das Leitgewicht einer Position: der SCHWERSTE Satz.
 *
 * Es landet in workout_log.weight und haelt damit alles am Laufen, was es
 * heute schon gibt -- "letztes Gewicht" (§4), den Gewichtsverlauf und den
 * Bestwert (§7.8). Deshalb der schwerste und nicht der letzte Satz: Der
 * Bestwert ist die Zahl, an der man den Fortschritt misst, und wer 12×40,
 * 10×40, 9×45 macht, hat 45 kg erreicht und nicht 45 kg "zuletzt zufaellig".
 *
 * null, wenn kein einziger Satz ein Gewicht traegt -- eine 0 stuende fuer
 * "ohne Gewicht bewegt" und liesse sich von "nichts eingetragen" nicht mehr
 * unterscheiden.
 */
function leitgewicht(array $saetze): ?float {
    $best = null;
    foreach ($saetze as $s) {
        if ($s['weight'] !== null && ($best === null || $s['weight'] > $best)) {
            $best = $s['weight'];
        }
    }
    return $best;
}

/**
 * Die Leitwerte einer Intervallfolge -- das Gegenstueck zu leitgewicht().
 *
 * SUMME und nicht Maximum, und darin liegt der ganze Unterschied: Zwei
 * Intervalle zu 1000 m sind 2000 gelaufene Meter, zwei Saetze zu 40 kg sind
 * keine 80 kg. Der Bestwert einer Ausdaueruebung ist die weiteste Strecke
 * einer EINHEIT, nicht das laengste Einzelintervall.
 *
 * Gerechnet wird in lib/training.php (saetze_distanz(), saetze_dauer()) --
 * dieselben Funktionen, die der Verlauf benutzt. Zwei Summen an zwei Stellen
 * liefen irgendwann auseinander.
 *
 * @return array{distanz_m:?int,dauer_s:?int}
 */
function leitwerte(array $saetze): array {
    return [
        'distanz_m' => saetze_distanz($saetze),
        'dauer_s'   => saetze_dauer($saetze),
    ];
}

/**
 * Eine als ERLEDIGT markierte Position ist festgeschrieben (§7.4).
 *
 * Geaendert wird ueber Haekchen entfernen, korrigieren, neu abhaken -- derselbe
 * eine Mechanismus wie beim Gewichtsfeld im einfachen Modus und beim Tausch
 * (§7.5). Die deaktivierten Felder in der Oberflaeche sind nur die Bequemlichkeit
 * davor; verboten wird es hier.
 *
 * DREI Ausnahmen, und jede ist noetig:
 *
 * 1. Ohne laufende Einheit oder ohne bestehende Zeile gibt es nichts zu
 *    schuetzen.
 * 2. `done = false` ist genau der Weg, die Sperre aufzuheben -- er muss
 *    durchgehen.
 * 3. **Eine unveraendert durchgereichte Nutzlast muss durchgehen.** Sonst
 *    zerbricht die Idempotenz, auf der die Warteschlange steht (§7.4): Sie
 *    schickt einen Eintrag nach einem Funkloch erneut, und der zweite Aufruf
 *    traefe dann auf die bereits abgehakte Position und schluege mit 409 fehl --
 *    fuer den Benutzer ein Fehler, obwohl alles laengst gespeichert ist.
 */
function abgeschlossene_position_schuetzen(
    int $peId,
    ?array $saetze,
    ?float $gewicht,
    array $ausdauer,
    bool $erledigt
): void {
    $offen = offene_einheit(current_user_id());
    if ($offen === null || !$erledigt) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id, weight, distanz_m, dauer_s, done FROM workout_log
          WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$offen['id'], $peId]);
    $zeile = $stmt->fetch();

    if ($zeile === false || (int)$zeile['done'] !== 1) {
        return;
    }

    $logId   = (int)$zeile['id'];
    $bestand = saetze_zu_logs([$logId])[$logId] ?? [];

    // Alle Leitwerte muessen uebereinstimmen, nicht nur das Gewicht: Sonst
    // ginge eine geaenderte Distanz an einer abgehakten Ausdauerposition
    // stillschweigend durch -- die Sperre haette dort schlicht kein Auge fuer
    // die Werte, um die es geht.
    $gleich = saetze_gleich($bestand, $saetze ?? [])
        && zahl_gleich(to_decimal_or_null($zeile['weight']), $gewicht)
        && ($zeile['distanz_m'] === null ? null : (int)$zeile['distanz_m'])
            === $ausdauer['distanz_m']
        && ($zeile['dauer_s'] === null ? null : (int)$zeile['dauer_s'])
            === $ausdauer['dauer_s'];

    if ($gleich) {
        return;
    }

    json_err(
        'Diese Übung ist als erledigt markiert. Zum Ändern erst das Häkchen '
        . 'entfernen, dann korrigieren und neu abhaken.',
        409
    );
}

/**
 * Vergleicht zwei Satzfolgen inhaltlich -- Anzahl und ALLE vier Wertfelder.
 *
 * Alle vier und nicht nur die der aktuellen Erfassungsart: Diese Funktion
 * traegt die Idempotenz-Ausnahme in abgeschlossene_position_schuetzen(), und
 * eine Ausnahme, die zu grosszuegig vergleicht, laesst eine echte Aenderung an
 * einer festgeschriebenen Position durchgehen. Was die Erfassungsart nicht
 * betrifft, ist auf beiden Seiten ohnehin null -- der Vergleich kostet nichts.
 *
 * Ganzzahlen mit ===, Gewichte ueber zahl_gleich(): Meter und Sekunden sind
 * exakt, Fliesskommagewichte nicht.
 */
function saetze_gleich(array $a, array $b): bool {
    if (count($a) !== count($b)) {
        return false;
    }

    foreach (array_values($a) as $i => $satz) {
        $andere = array_values($b)[$i];
        if (($satz['reps'] ?? null) !== ($andere['reps'] ?? null)) {
            return false;
        }
        if (!zahl_gleich($satz['weight'] ?? null, $andere['weight'] ?? null)) {
            return false;
        }
        if (($satz['distanz_m'] ?? null) !== ($andere['distanz_m'] ?? null)) {
            return false;
        }
        if (($satz['dauer_s'] ?? null) !== ($andere['dauer_s'] ?? null)) {
            return false;
        }
    }

    return true;
}

/**
 * Gleichheit zweier Gewichte. Fliesskomma wird nie auf `===` verglichen:
 * 40.0 aus der Datenbank und 40.0 aus der Eingabe sind dasselbe Gewicht,
 * muessen aber nicht dasselbe Bitmuster sein.
 */
function zahl_gleich(?float $a, ?float $b): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.0001;
}

/**
 * Die tatsaechlich angezeigte Uebung dieser Position -- nach einem Tausch die
 * Ersatzuebung, sonst die des Plans (§7.5).
 */
function angezeigte_uebung(int $peId, int $planUebungId, int $sessionId): int {
    $stmt = db()->prepare(
        'SELECT replacement_exercise_id FROM exercise_swaps
          WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$sessionId, $peId]);
    $ersatz = $stmt->fetchColumn();

    return $ersatz === false ? $planUebungId : (int)$ersatz;
}

/**
 * Abhaken. Startet die Einheit, falls noch keine laeuft (§7.6).
 */
function aktion_abhaken(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);

    // Die Erfassungsart steht in der Datenbank und entscheidet, welches
    // Feldpaar diese Anfrage ueberhaupt tragen darf (§7.4). Sie aus der
    // Nutzlast zu nehmen waere die naheliegende Abkuerzung und zugleich eine
    // Luecke: Ein Aufruf koennte damit Meter in eine Kraftuebung schreiben.
    $ausdauerUebung = ist_ausdauer($position['erfassung'] ?? null);

    // Geprueft wird VOR der Transaktion. json_err() beendet die Anfrage
    // sofort; innerhalb einer offenen Transaktion haenge die Entscheidung
    // ueber das Rollback sonst daran, wann PHP die Verbindung abraeumt.
    $saetze = saetze_pruefen($eingabe, $ausdauerUebung ? 'ausdauer' : 'kraft');

    // Mit Satzliste bestimmen die Saetze die Leitwerte der Position, ohne sie
    // die gesendeten Felder. Die Nutzlast beschreibt die Zeile jeweils
    // vollstaendig -- deshalb koennen Leitwert und Satzliste nichts
    // auseinanderbringen.
    //
    // Je Erfassungsart wird nur das eigene Feldpaar gelesen; das andere bleibt
    // null und landet auch so in der Datenbank. Eine Zeile mit Gewicht UND
    // Distanz gibt es dadurch nicht, egal was die Nutzlast mitbringt.
    if ($ausdauerUebung) {
        $gewicht  = null;
        $ausdauer = $saetze === null ? ausdauer_pruefen($eingabe) : leitwerte($saetze);
    } else {
        $gewicht  = $saetze === null ? gewicht_pruefen($eingabe) : leitgewicht($saetze);
        $ausdauer = ['distanz_m' => null, 'dauer_s' => null];
    }

    // "Erledigt" ist ein eigener Zustand, nicht die blosse Existenz der Zeile.
    //
    // FEHLT das Feld, gilt die Position als erledigt. Das ist die Vorgabe fuer
    // den einfachen Modus, in dem Abhaken und Protokollieren dasselbe sind --
    // und zugleich der Rueckfall fuer eine Nutzlast aus einer aelteren Fassung.
    $erledigt = !array_key_exists('done', $eingabe) || !empty($eingabe['done']);

    // Ebenfalls vor der Transaktion, aus demselben Grund wie die Pruefungen
    // darueber: json_err() beendet die Anfrage sofort.
    abgeschlossene_position_schuetzen($peId, $saetze, $gewicht, $ausdauer, $erledigt);

    $userId = current_user_id();
    $planId = (int)$position['plan_id'];
    $planUebungId = (int)$position['exercise_id'];

    // Protokollieren setzt eine LAUFENDE Einheit voraus (§7.6). Bis 1.1.5 legte
    // einheit_sicherstellen() hier stillschweigend eine an -- ein einziger
    // Fehlgriff auf ein Haekchen begann damit ein Training, und started_at hielt
    // nicht den Trainingsbeginn fest, sondern den Fehlgriff. Eine Einheit
    // beginnt jetzt ausschliesslich mit "Training starten".
    //
    // 409 und nicht 403: Der Aufruf ist erlaubt, nur der Zustand passt nicht.
    // index.js zeigt die Meldung unveraendert an -- sie sagt, was zu tun ist.
    $laufende = offene_einheit($userId);
    if ($laufende === null) {
        json_err('Es läuft kein Training — bitte zuerst „Training starten" drücken.', 409);
    }
    $sessionIdOffen = (int)$laufende['id'];

    $sessionId = db_transaction(
        static function () use (
            $userId, $planId, $peId, $planUebungId, $gewicht, $ausdauer, $saetze,
            $erledigt, $sessionIdOffen
        ): int {
            $sessionId = $sessionIdOffen;
            $uebungId  = angezeigte_uebung($peId, $planUebungId, $sessionId);

            // Ein Eintrag je Einheit UND Planposition -- nicht je Uebung. Der
            // Konflikt-Zielschluessel ist deshalb (session_id, plan_exercise_id).
            $stmt = db()->prepare(
                'INSERT INTO workout_log
                     (session_id, plan_exercise_id, user_id, exercise_id, plan_id,
                      weight, distanz_m, dauer_s, done, performed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (session_id, plan_exercise_id) DO UPDATE SET
                     exercise_id  = excluded.exercise_id,
                     weight       = excluded.weight,
                     distanz_m    = excluded.distanz_m,
                     dauer_s      = excluded.dauer_s,
                     done         = excluded.done,
                     performed_at = excluded.performed_at'
            );
            $stmt->execute([
                $sessionId, $peId, $userId, $uebungId, $planId, $gewicht,
                $ausdauer['distanz_m'], $ausdauer['dauer_s'],
                $erledigt ? 1 : 0, now(),
            ]);

            saetze_schreiben($sessionId, $peId, $saetze);

            return $sessionId;
        }
    );

    json_ok(fortschritt($sessionId, $planId) + [
        'session_id' => $sessionId,
        'erledigt'   => $erledigt,
        'saetze'     => $saetze ?? [],
    ]);
}

/**
 * Ersetzt die Saetze einer Position vollstaendig: erst alle weg, dann die
 * neuen von 1 an.
 *
 * OHNE Satzliste werden vorhandene Saetze GELOESCHT. Das ist kein Versehen: Die
 * Nutzlast beschreibt die Zeile vollstaendig, und ein Aufruf aus dem einfachen
 * Modus sagt "diese Position hat ein Gewicht und keine Saetze". Liesse man die
 * alten stehen, zeigte die Position ein Leitgewicht aus einer Satzfolge, die
 * niemand mehr sieht. Praktisch tritt der Fall nicht ein -- api/auth.php sperrt
 * den Moduswechsel bei laufender Einheit --, aber die Regel gehoert
 * festgeschrieben und nicht dem Zufall ueberlassen.
 *
 * Laeuft ausschliesslich innerhalb der Transaktion aus aktion_abhaken(): Der
 * Zwischenzustand "Saetze geloescht, neue noch nicht da" darf nach aussen nie
 * sichtbar werden.
 */
function saetze_schreiben(int $sessionId, int $peId, ?array $saetze): void {
    $stmt = db()->prepare(
        'SELECT id FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    );
    $stmt->execute([$sessionId, $peId]);
    $logId = (int)$stmt->fetchColumn();

    db()->prepare('DELETE FROM workout_sets WHERE workout_log_id = ?')
        ->execute([$logId]);

    if ($saetze === null) {
        return;
    }

    $einfuegen = db()->prepare(
        'INSERT INTO workout_sets
             (workout_log_id, satz_nr, reps, weight, distanz_m, dauer_s)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($saetze as $s) {
        $einfuegen->execute([
            $logId, $s['satz_nr'], $s['reps'], $s['weight'],
            $s['distanz_m'], $s['dauer_s'],
        ]);
    }
}

/**
 * Haekchen wieder abwaehlen: der Eintrag dieser Einheit verschwindet (§7.4).
 *
 * Die Einheit selbst bleibt offen -- auch wenn es der letzte Eintrag war.
 * Sie zu schliessen ist eine bewusste Handlung, kein Nebeneffekt.
 */
function aktion_abwaehlen(array $eingabe): never {
    $peId = to_int_or_null($eingabe['plan_exercise_id'] ?? null);
    if ($peId === null) {
        json_err('Keine Planposition angegeben.', 422);
    }

    $position = position_laden($peId);
    $offen = offene_einheit(current_user_id());

    if ($offen === null) {
        json_err('Es läuft gerade keine Trainingseinheit.', 409);
    }

    // Die Saetze dieser Position gehen mit: workout_sets.workout_log_id traegt
    // ON DELETE CASCADE. Deshalb steht hier kein zweites DELETE -- eines, das
    // man beim naechsten Loeschpfad wieder vergessen koennte.
    db()->prepare(
        'DELETE FROM workout_log WHERE session_id = ? AND plan_exercise_id = ?'
    )->execute([$offen['id'], $peId]);

    json_ok(fortschritt((int)$offen['id'], (int)$position['plan_id']) + [
        'erledigt' => false,
    ]);
}

/**
 * Der Zaehler "x/n" der Trainingsleiste: n sind die Planpositionen, x die als
 * ERLEDIGT markierten Positionen dieser Einheit (§7.3).
 *
 * `done = 1` und nicht blosse Existenz der Zeile: Im Expertenmodus entsteht die
 * Zeile schon mit dem ersten Satz, und dann ist man mitten in der Uebung und
 * nicht fertig damit. Im einfachen Modus fallen beide zusammen -- dort schreibt
 * jeder Aufruf `done = 1`.
 *
 * Die zweite Zahl der Leiste ("n uebersprungen") steht hier bewusst NICHT: Sie
 * haengt nicht am Datenbestand, sondern an der REIHENFOLGE der Positionen --
 * offen und vor der aktiven. Das ist dieselbe Rechnung, die den orangen Balken
 * setzt (positions_zustaende() bzw. aktiveMarkieren()), und sie gehoert genau
 * einmal dorthin.
 *
 * `alle_erledigt` haengt allein an `done` -- angefangene Uebungen sind nicht
 * fertig, und die Rueckfrage "Training beenden?" darf nicht kommen, solange
 * irgendwo noch ein Haekchen fehlt.
 */
function fortschritt(int $sessionId, int $planId): array {
    // Dasselbe Fenster wie plan_positionen(): der Plan plus die Positionen, die
    // nur zu dieser Einheit gehoeren (§7.6). Beide Zaehlungen MUESSEN dieselbe
    // Menge sehen -- steht unten eine Karte mehr, als "n" kennt, laeuft "x/n"
    // ueber das Ziel hinaus, und genau davor warnt Fallstrick 2.
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM plan_exercises
          WHERE plan_id = ? AND (session_id IS NULL OR session_id = ?)'
    );
    $stmt->execute([$planId, $sessionId]);
    $n = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM workout_log WHERE session_id = ? AND done = 1'
    );
    $stmt->execute([$sessionId]);
    $x = (int)$stmt->fetchColumn();

    return ['erledigt_anzahl' => $x, 'gesamt' => $n, 'alle_erledigt' => $n > 0 && $x >= $n];
}
