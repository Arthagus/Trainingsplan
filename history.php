<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';

bootstrap_session();
require_login();

/**
 * Trainingshistorie (§10).
 *
 * Zwei Ansichten:
 *   - Einheiten: wann wurde was trainiert, aufklappbar mit den Gewichten
 *   - Übungen:   wie hat sich das Gewicht je Übung entwickelt
 *
 * **Jeder sieht ausschliesslich seine eigenen Daten.** Es gibt hier bewusst
 * keine Benutzerauswahl fuer Admins: Trainingsdaten sind persoenlich. Die
 * Abfragen in lib/training.php filtern durchgehend ueber user_id, der Wert
 * kommt aus der Sitzung und nie aus einem Parameter -- damit ist der
 * IDOR-Schutz (§5) hier keine Pruefung, die man vergessen koennte, sondern
 * Teil der Abfrage.
 */

$userId  = current_user_id();
$ansicht = to_str($_GET['ansicht'] ?? 'einheiten');
if (!in_array($ansicht, ['einheiten', 'uebungen'], true)) {
    $ansicht = 'einheiten';
}

$einheiten = einheiten_verlauf($userId);
$uebungen  = uebungen_mit_verlauf($userId);
$offen     = offene_einheit($userId);

/**
 * Zeichnet den Gewichtsverlauf als kleine Kurve.
 *
 * Inline-SVG statt Diagramm-Bibliothek: Das hält die Regel „kein Build-Step,
 * keine Abhängigkeiten" ein und wiegt nichts. Bei weniger als zwei Punkten
 * gibt es nichts zu verbinden — dann bleibt die Fläche leer.
 *
 * @param array $punkte Aufsteigend nach Zeit, je ['weight' => float]
 */
function verlauf_kurve(array $punkte): void {
    $werte = array_map(static fn(array $p): float => (float)$p['weight'], $punkte);
    if (count($werte) < 2) {
        return;
    }

    $min = min($werte);
    $max = max($werte);
    // Alle Werte gleich: eine waagerechte Linie in der Mitte, sonst teilte
    // man durch null.
    $spanne = ($max - $min) > 0.0 ? ($max - $min) : 1.0;

    $b = 100.0;   // Koordinatensystem, per viewBox skaliert
    $h = 28.0;
    $rand = 3.0;

    $koord = [];
    foreach ($werte as $i => $w) {
        $x = count($werte) === 1 ? 0.0 : ($i / (count($werte) - 1)) * $b;
        $y = $h - $rand - (($w - $min) / $spanne) * ($h - 2 * $rand);
        $koord[] = round($x, 2) . ',' . round($y, 2);
    }
    $letzte = end($koord);
    [$lx, $ly] = explode(',', (string)$letzte);
    ?>
    <svg class="verlauf-kurve" viewBox="0 0 <?= $b ?> <?= $h ?>"
         preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polyline points="<?= h(implode(' ', $koord)) ?>"
                  fill="none" stroke="currentColor" stroke-width="1.5"
                  vector-effect="non-scaling-stroke"
                  stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="<?= h($lx) ?>" cy="<?= h($ly) ?>" r="2" fill="currentColor"/>
    </svg>
    <?php
}

$pageTitle = 'Verlauf';
require __DIR__ . '/lib/view_header.php';
?>

<nav class="filterleiste" aria-label="Ansicht">
    <span class="filter-gruppe">
        <a href="?ansicht=einheiten" class="<?= $ansicht === 'einheiten' ? 'aktiv' : '' ?>">
            Einheiten (<?= count($einheiten) ?>)
        </a>
        <a href="?ansicht=uebungen" class="<?= $ansicht === 'uebungen' ? 'aktiv' : '' ?>">
            Übungen (<?= count($uebungen) ?>)
        </a>
    </span>
</nav>

<?php if ($offen !== null): ?>
    <div class="karte einheit-laeuft">
        <strong>Eine Einheit läuft gerade</strong>
        <span class="matt">seit <?= h(format_datetime($offen['started_at'])) ?></span>
        <p class="matt">
            Sie erscheint hier, sobald sie beendet ist.
            <a href="<?= h(base_path()) ?>/index.php">Zum Training</a>
        </p>
    </div>
<?php endif; ?>

<?php if ($ansicht === 'einheiten'): ?>

    <?php if ($einheiten === []): ?>
        <div class="karte">
            <p><strong>Noch keine abgeschlossene Trainingseinheit.</strong></p>
            <p class="matt">
                Sobald du ein Training beendest, steht es hier — mit Datum, Dauer und den
                Gewichten, die du eingetragen hast.
            </p>
        </div>
    <?php else: ?>
        <ul class="liste-schlicht">
            <?php foreach ($einheiten as $e): ?>
                <?php
                $eintraege = einheit_eintraege((int)$e['id'], $userId);
                $gesamt    = (int)$e['gesamt'];
                $fertig    = (int)$e['erledigt'];
                ?>
                <li class="karte einheit-karte" data-session="<?= (int)$e['id'] ?>">
                    <details>
                        <summary class="einheit-kopf">
                            <span class="einheit-datum">
                                <?= h(format_datetime($e['started_at'])) ?>
                            </span>
                            <span class="einheit-plan">
                                <?= $e['plan_name'] === null
                                    ? '<em class="matt">gelöschter Plan</em>'
                                    : h((string)$e['plan_name']) ?>
                            </span>
                            <span class="matt einheit-eckdaten">
                                <?= h(dauer_text($e['started_at'], $e['ended_at'])) ?>
                                · <?= $fertig ?><?= $gesamt > 0 ? '/' . $gesamt : '' ?> Übungen
                            </span>
                        </summary>

                        <?php if ($eintraege === []): ?>
                            <p class="matt">Keine Übung protokolliert.</p>
                        <?php else: ?>
                            <table class="verlauf-tabelle">
                                <thead>
                                    <tr><th>Übung</th><th class="spalte-zahl">Gewicht</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($eintraege as $z): ?>
                                    <tr>
                                        <td>
                                            <?= h((string)$z['name_de']) ?>
                                            <?php // Nach einem Tausch steht im Log die Ersatzübung;
                                                  // ohne diesen Hinweis wirkt der Plan verändert. ?>
                                            <?php if ($z['plan_uebung_name'] !== null
                                                   && (int)$z['plan_uebung_id'] !== (int)$z['exercise_id']): ?>
                                                <span class="abzeichen">statt <?= h((string)$z['plan_uebung_name']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="spalte-zahl">
                                            <?= $z['weight'] === null
                                                ? '<span class="matt">—</span>'
                                                : h(format_decimal((float)$z['weight'])) . ' kg' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <?php // Fuer Fehleingaben: versehentlich gestartet, doch nicht
                              // trainiert, Testdaten. Ohne diesen Weg blieben solche
                              // Zeilen dauerhaft stehen. ?>
                        <p class="einheit-aktionen">
                            <button type="button" class="gefahr einheit-loeschen">
                                Einheit löschen
                            </button>
                        </p>
                        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php else: ?>

    <?php if ($uebungen === []): ?>
        <div class="karte">
            <p><strong>Noch kein Gewicht protokolliert.</strong></p>
            <p class="matt">
                Sobald du beim Abhaken ein Gewicht einträgst, entsteht hier ein Verlauf.
                Übungen ohne Gewichtsangabe erscheinen nicht — für sie gäbe es nichts
                zu zeigen.
            </p>
        </div>
    <?php else: ?>
        <ul class="liste-schlicht">
            <?php foreach ($uebungen as $u): ?>
                <?php
                $verlauf = gewichts_verlauf($userId, (int)$u['exercise_id']);
                $letzter = $verlauf === [] ? null : (float)end($verlauf)['weight'];
                $erster  = $verlauf === [] ? null : (float)$verlauf[0]['weight'];
                $diff    = ($letzter !== null && $erster !== null) ? $letzter - $erster : 0.0;
                ?>
                <li class="karte">
                    <details>
                        <summary class="einheit-kopf">
                            <span class="einheit-plan"><?= h((string)$u['name_de']) ?></span>
                            <span class="verlauf-kurve-halter"><?php verlauf_kurve($verlauf); ?></span>
                            <span class="matt einheit-eckdaten">
                                <?= h(format_decimal($letzter)) ?> kg
                                <?php if (abs($diff) >= 0.01): ?>
                                    <span class="<?= $diff > 0 ? 'diff-plus' : 'diff-minus' ?>">
                                        <?= $diff > 0 ? '+' : '−' ?><?= h(format_decimal(abs($diff))) ?>
                                    </span>
                                <?php endif; ?>
                                · <?= (int)$u['anzahl'] ?>×
                            </span>
                        </summary>

                        <table class="verlauf-tabelle">
                            <thead>
                                <tr><th>Datum</th><th class="spalte-zahl">Gewicht</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($verlauf) as $v): ?>
                                <tr>
                                    <td><?= h(format_datetime($v['performed_at'])) ?></td>
                                    <td class="spalte-zahl">
                                        <?= h(format_decimal((float)$v['weight'])) ?> kg
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <p class="matt">
                            Bestwert <?= h(format_decimal((float)$u['bestwert'])) ?> kg
                        </p>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
