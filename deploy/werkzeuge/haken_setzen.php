<?php
// SCHREIBT. Setzt fehlende Haekchen einer abgeschlossenen Einheit (Fallstrick 13).
//
//   php haken_setzen.php <einheit-id>              ganze Einheit
//   php haken_setzen.php <einheit-id> <position>   nur diese eine Position
//
// Angefasst wird eine Zeile nur, wenn ALLE drei Bedingungen stimmen:
//
//   1. done = 0
//   2. sie traegt Gewicht ODER Saetze -- eine Zeile ganz ohne Werte kann eine
//      bewusst angefangene und nicht beendete Uebung sein, und daraus ein
//      "erledigt" zu machen hiesse, eine Aussage zu erfinden.
//   3. sie haengt noch an einer PLANPOSITION.
//
// Die dritte Bedingung ist die unscheinbarste und die wichtigste. Wird eine
// Uebung nach dem Training aus dem Plan genommen, setzt ON DELETE SET NULL die
// plan_exercise_id auf NULL (§4.1) -- die Protokollzeile bleibt, zaehlt aber in
// "x" mit, waehrend "n" nur noch die verbliebenen Planpositionen zaehlt. Ein
// Haekchen dort triebe x ueber n: "7/6". Genau davor warnt Fallstrick 2.
//
// Mehrfaches Ausfuehren ist harmlos.
require '/var/www/html/lib/training.php';

$sid = (int)($argv[1] ?? 0);
$pe  = isset($argv[2]) ? (int)$argv[2] : null;
if ($sid <= 0) {
    exit("Aufruf: php haken_setzen.php <einheit-id> [<position-id>]\n");
}

$kopf = db()->prepare(
    'SELECT s.started_at, u.name FROM sessions s
       JOIN users u ON u.id = s.user_id WHERE s.id = ?'
);
$kopf->execute([$sid]);
$e = $kopf->fetch();
if ($e === false) { exit("Diese Einheit gibt es nicht.\n"); }
echo "Einheit $sid vom {$e['started_at']} ({$e['name']})\n";

$wo = 'wl.session_id = ? AND wl.done = 0' . ($pe === null ? '' : ' AND wl.plan_exercise_id = ?');
$stmt = db()->prepare(
    "SELECT wl.id, wl.plan_exercise_id AS pe, ex.name_de, wl.weight,
            (SELECT COUNT(*) FROM workout_sets ws WHERE ws.workout_log_id = wl.id) AS saetze
       FROM workout_log wl JOIN exercises ex ON ex.id = wl.exercise_id
      WHERE $wo ORDER BY wl.id"
);
$stmt->execute($pe === null ? [$sid] : [$sid, $pe]);

$setzen = db()->prepare('UPDATE workout_log SET done = 1 WHERE id = ?');
$anzahl = 0;
foreach ($stmt->fetchAll() as $r) {
    if ((int)$r['saetze'] === 0 && $r['weight'] === null) {
        echo "  übersprungen (keine Werte):          {$r['name_de']}\n";
        continue;
    }
    if ($r['pe'] === null) {
        echo "  übersprungen (Position gelöscht):    {$r['name_de']}"
           . " — ein Häkchen triebe „x“ über „n“\n";
        continue;
    }
    $setzen->execute([$r['id']]);
    $anzahl++;
    printf("  abgehakt: Position %-5s %-26s (Gewicht %s, %d Sätze)\n",
        $r['pe'], $r['name_de'], $r['weight'] ?? '-', $r['saetze']);
}

$z = db()->prepare(
    'SELECT (SELECT COUNT(*) FROM workout_log w WHERE w.session_id = s.id AND w.done = 1) x,
            (SELECT COUNT(*) FROM plan_exercises pe WHERE pe.plan_id = s.plan_id
               AND (pe.session_id IS NULL OR pe.session_id = s.id)) n
       FROM sessions s WHERE s.id = ?'
);
$z->execute([$sid]);
$n = $z->fetch();
echo "  -> $anzahl Zeile(n) geändert, die Einheit steht jetzt auf {$n['x']}/{$n['n']}\n";
