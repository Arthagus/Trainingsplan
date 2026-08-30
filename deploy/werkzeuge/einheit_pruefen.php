<?php
// NUR LESEN. Zeigt die Protokollzeilen einer Einheit samt Häkchen und Sätzen.
require '/var/www/html/lib/training.php';
$datum = $argv[1] ?? '';
$stmt = db()->prepare(
    "SELECT s.id, s.started_at, u.name,
            (SELECT COUNT(*) FROM workout_log w WHERE w.session_id = s.id AND w.done = 1) AS x,
            (SELECT COUNT(*) FROM plan_exercises pe WHERE pe.plan_id = s.plan_id
               AND (pe.session_id IS NULL OR pe.session_id = s.id)) AS n
       FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.started_at LIKE ? ORDER BY s.started_at"
);
$stmt->execute([$datum . '%']);
foreach ($stmt->fetchAll() as $e) {
    echo "Einheit {$e['id']}  {$e['started_at']}  {$e['name']}  -> {$e['x']}/{$e['n']}\n";
    $z = db()->prepare(
        "SELECT wl.plan_exercise_id AS pe, ex.name_de, wl.done, wl.weight,
                (SELECT COUNT(*) FROM workout_sets ws WHERE ws.workout_log_id = wl.id) AS saetze
           FROM workout_log wl JOIN exercises ex ON ex.id = wl.exercise_id
          WHERE wl.session_id = ? ORDER BY wl.id"
    );
    $z->execute([$e['id']]);
    foreach ($z->fetchAll() as $r) {
        printf("   Position %-4s %-26s done=%d  Gewicht=%-7s Sätze=%d%s\n",
            $r['pe'], $r['name_de'], $r['done'], $r['weight'] ?? '-', $r['saetze'],
            ((int)$r['done'] === 0 && ((int)$r['saetze'] > 0 || $r['weight'] !== null))
                ? '   <-- protokolliert, aber NICHT abgehakt' : '');
    }
}
