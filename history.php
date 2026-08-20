<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';
require_once __DIR__ . '/lib/splits.php';

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

// Nur wenn es mehrere Splits gibt, ist der Splitname im Verlauf eine
// Information -- bei einem einzigen waere er in jeder Zeile dasselbe Wort.
$mehrereSplits = count(splits_von($userId)) > 1;

/**
 * Baut die Zelle „Saetze" als umbrechendes Gitter statt als eine Zeile.
 *
 * `saetze_text()` liefert "12×40 · 10×40 · 9×45" am Stueck, und die Zelle stand
 * mit `white-space: nowrap`. Bei fuenf Spalten -- Datum, Saetze, Volumen, 1RM,
 * Gewicht -- reichte das auf einem Pixel 10 Pro XL nicht, die Tabelle rollte
 * seitwaerts. Gemeldet aus dem Training am 2026-08-17.
 *
 * Jeder Satz kommt deshalb als eigenes <span>; das Gitter in `.satz-gitter`
 * setzt zwei nebeneinander und den Rest in weitere Zeilen. Der Mittelpunkt als
 * Trenner faellt weg -- die Spalten trennen bereits, und ein Trenner am
 * Zeilenende saehe aus, als fehlte etwas.
 *
 * Bewusst NICHT in `saetze_text()` geaendert: Das Paar `saetze_text()` /
 * `saetzeText()` (PHP + JS) muss gleich bleiben, und `saetze_zusammenfassung()`
 * baut darauf auf. Hier entsteht Markup, dort ein reiner String.
 *
 * @param array $saetze Saetze der Position, in Reihenfolge
 */
function satz_gitter(array $saetze): string {
    if ($saetze === []) {
        return '<span class="matt">—</span>';
    }

    $teile = [];
    foreach ($saetze as $s) {
        $wdh = $s['reps']   === null ? '?' : (string)$s['reps'];
        $kg  = $s['weight'] === null ? '—' : format_decimal($s['weight']);
        $teile[] = '<span>' . h($wdh . '×' . $kg) . '</span>';
    }

    return '<span class="satz-gitter">' . implode('', $teile) . '</span>';
}

/**
 * Zeichnet den Gewichtsverlauf als kleine Kurve.
 *
 * Inline-SVG statt Diagramm-Bibliothek: Das hält die Regel „kein Build-Step,
 * keine Abhängigkeiten" ein und wiegt nichts. Bei weniger als zwei Punkten
 * gibt es nichts zu verbinden — dann bleibt die Fläche leer.
 *
 * Punkte, deren Feld null ist, fallen heraus statt als 0 zu zaehlen: Volumen
 * und 1RM gibt es nur fuer satzgenau protokollierte Einheiten, und eine 0 riss
 * in die Kurve einen Einbruch, den es nie gegeben hat.
 *
 * @param array  $punkte Aufsteigend nach Zeit
 * @param string $feld   Welcher Wert gezeichnet wird
 */
function verlauf_kurve(array $punkte, string $feld = 'weight'): void {
    $werte = [];
    foreach ($punkte as $p) {
        if (($p[$feld] ?? null) !== null) {
            $werte[] = (float)$p[$feld];
        }
    }
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

                // Ein Aufruf je Einheit, nicht je Zeile: saetze_zu_logs() holt
                // die Saetze aller Positionen dieser Einheit auf einmal.
                $saetze = saetze_zu_logs(array_column($eintraege, 'log_id'));
                $mitSaetzen = $saetze !== [];
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
                                <?php // Der Split dahinter, sobald es mehr als
                                      // einen gibt: "Ganzkörper A" allein ist
                                      // nicht einzuordnen, wenn zwei Splits
                                      // einen Plan dieses Namens fuehren. ?>
                                <?php if ($mehrereSplits && $e['split_name'] !== null): ?>
                                    <span class="matt">· <?= h((string)$e['split_name']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="matt einheit-eckdaten">
                                <?= h(dauer_text($e['started_at'], $e['ended_at'])) ?>
                                · <?= $fertig ?><?= $gesamt > 0 ? '/' . $gesamt : '' ?> Übungen
                            </span>
                        </summary>

                        <?php if ($eintraege === []): ?>
                            <p class="matt">Keine Übung protokolliert.</p>
                        <?php else: ?>
                            <?php // Die Spalte „Sätze" steht nur, wenn diese Einheit
                                  // welche hat. Eine leere Spalte über jede im
                                  // einfachen Modus protokollierte Einheit hinweg
                                  // wäre am Handy verschenkte Breite. ?>
                            <table class="verlauf-tabelle">
                                <thead>
                                    <tr>
                                        <th>Übung</th>
                                        <?php if ($mitSaetzen): ?><th>Sätze</th><?php endif; ?>
                                        <?php // Die letzte Spalte haengt am selben Schalter wie
                                              // die Satz-Spalte: Wo Saetze protokolliert sind,
                                              // steht dort das geschaetzte 1RM, sonst das
                                              // Gewicht.
                                              //
                                              // Der Tausch ist kein Geschmack: Das schwerste
                                              // Gewicht steht bei satzgenauen Einheiten schon
                                              // in der Satz-Spalte daneben, die Zahl waere also
                                              // doppelt. Das 1RM ist die einzige Kennzahl, die
                                              // Gewicht UND Wiederholungen zusammenfasst -- und
                                              // damit die einzige, die man ueber verschiedene
                                              // Wiederholungszahlen hinweg vergleichen kann.
                                              //
                                              // Ohne Wiederholungen laesst es sich nicht
                                              // schaetzen; im einfachen Modus bleibt es deshalb
                                              // beim Gewicht. Eine Spalte "1RM", die ueber eine
                                              // ganze Einheit hinweg nur Striche zeigt, waere
                                              // schlechter als die Zahl, die es gibt. ?>
                                        <th class="spalte-zahl"><?= $mitSaetzen ? '1RM' : 'Gewicht' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($eintraege as $z): ?>
                                    <?php $zeilenSaetze = $saetze[(int)$z['log_id']] ?? []; ?>
                                    <tr>
                                        <td>
                                            <?= uebung_name((string)$z['name_de'], $z['name_en']) ?>
                                            <?php // Nach einem Tausch steht im Log die Ersatzübung;
                                                  // ohne diesen Hinweis wirkt der Plan verändert. ?>
                                            <?php if ($z['plan_uebung_name'] !== null
                                                   && (int)$z['plan_uebung_id'] !== (int)$z['exercise_id']): ?>
                                                <span class="abzeichen">statt <?= uebung_name_kurz(
                                                    (string)$z['plan_uebung_name'], $z['plan_uebung_name_en']
                                                ) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($mitSaetzen): ?>
                                            <td class="satz-spalte">
                                                <?= satz_gitter($zeilenSaetze) ?>
                                            </td>
                                        <?php endif; ?>
                                        <?php $e1rm = $mitSaetzen ? saetze_e1rm($zeilenSaetze) : null; ?>
                                        <td class="spalte-zahl">
                                            <?php if ($mitSaetzen): ?>
                                                <?= $e1rm === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal(round($e1rm, 1))) . ' kg' ?>
                                            <?php else: ?>
                                                <?= $z['weight'] === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal((float)$z['weight'])) . ' kg' ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php // Der Vorbehalt gehoert an die Zahl und nicht in eine
                                  // Fussnote: Ein geschaetztes Maximum sieht aus wie ein
                                  // gemessenes. Wortgleich zur Erklaerung im Uebungs-
                                  // Abschnitt weiter unten -- zwei Schreibweisen fuer
                                  // dieselbe Kennzahl liest man als zwei Kennzahlen. ?>
                            <?php if ($mitSaetzen): ?>
                                <p class="matt">
                                    <strong>1RM</strong> ist das geschätzte
                                    Einwiederholungsmaximum nach Epley
                                    (kg × (1 + Wdh ÷ 30)) aus dem besten Satz — eine
                                    Näherung, kein gemessener Wert. Das schwerste Gewicht
                                    selbst steht in der Spalte „Sätze“.
                                </p>
                            <?php endif; ?>
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

                // Volumen und geschaetztes 1RM je Punkt. Ein Aufruf fuer den
                // ganzen Verlauf; gerechnet wird in PHP und nicht in SQL -- die
                // Datenmenge ist winzig, und die Formel gehoert dorthin, wo man
                // sie lesen kann.
                $saetzeJeLog = saetze_zu_logs(array_column($verlauf, 'log_id'));
                foreach ($verlauf as $i => $p) {
                    $s = $saetzeJeLog[(int)$p['log_id']] ?? [];
                    $verlauf[$i]['saetze']  = $s;
                    $verlauf[$i]['volumen'] = saetze_volumen($s);
                    $verlauf[$i]['e1rm']    = saetze_e1rm($s);
                }
                $hatSaetze = $saetzeJeLog !== [];
                ?>
                <li class="karte">
                    <details>
                        <summary class="einheit-kopf">
                            <?php // Einzeilig: Der Kopf ist eine Flex-Zeile mit Kurve und
                                  // Eckdaten daneben -- ein Umbruch im Namen schoebe sie
                                  // auseinander. ?>
                            <span class="einheit-plan"><?= uebung_name_kurz(
                                (string)$u['name_de'], $u['name_en']
                            ) ?></span>
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

                        <?php // Volumen und 1RM stehen INNERHALB des aufgeklappten
                              // Bereichs. Die Zusammenfassungszeile bleibt der
                              // Gewichtskurve vorbehalten: Drei Kurven nebeneinander
                              // machen den <summary> am Handy unlesbar. ?>
                        <?php if ($hatSaetze): ?>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">Volumen</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'volumen'); ?>
                                </span>
                            </p>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">1RM (geschätzt)</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'e1rm'); ?>
                                </span>
                            </p>
                        <?php endif; ?>

                        <?php // Mit Sätzen, Volumen und 1RM hat die Tabelle fünf Spalten.
                              // Sie passen seit 2026-08-17 auch auf ein Handy: Das Datum
                              // trägt ein zweistelliges Jahr, und die Sätze brechen in
                              // `satz_gitter()` um, statt in einer Zeile zu stehen.
                              // Vorher rollte die Tabelle hier seitwärts.
                              //
                              // Der rollende Kasten bleibt als Netz — bei sehr vielen
                              // Sätzen oder sehr schmalen Geräten greift er weiterhin,
                              // und dann ist Rollen besser als eine zerdrückte Spalte. ?>
                        <div class="<?= $hatSaetze ? 'tabelle-rollt' : '' ?>">
                        <table class="verlauf-tabelle">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <?php if ($hatSaetze): ?>
                                        <th>Sätze</th>
                                        <th class="spalte-zahl">Volumen</th>
                                        <th class="spalte-zahl">1RM</th>
                                    <?php endif; ?>
                                    <th class="spalte-zahl">Gewicht</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($verlauf) as $v): ?>
                                <tr>
                                    <?php // Datum und Uhrzeit als zwei Elemente, damit das
                                          // Stylesheet sie am Handy untereinander und auf
                                          // breiten Schirmen nebeneinander setzen kann. ?>
                                    <td class="datum-spalte">
                                        <span class="datum-tag"><?= h(format_datum_kurz($v['performed_at'])) ?></span>
                                        <span class="datum-zeit"><?= h(format_zeit($v['performed_at'])) ?></span>
                                    </td>
                                    <?php if ($hatSaetze): ?>
                                        <td class="satz-spalte">
                                            <?= satz_gitter($v['saetze']) ?>
                                        </td>
                                        <td class="spalte-zahl">
                                            <?= $v['volumen'] === null
                                                ? '<span class="matt">—</span>'
                                                : h(format_decimal(round($v['volumen']))) . ' kg' ?>
                                        </td>
                                        <td class="spalte-zahl">
                                            <?= $v['e1rm'] === null
                                                ? '<span class="matt">—</span>'
                                                : h(format_decimal(round($v['e1rm'], 1))) . ' kg' ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="spalte-zahl">
                                        <?= h(format_decimal((float)$v['weight'])) ?> kg
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <p class="matt">
                            Bestwert <?= h(format_decimal((float)$u['bestwert'])) ?> kg
                        </p>

                        <?php // Der Vorbehalt gehört sichtbar an die Zahl und nicht in
                              // eine Fußnote: Ein geschätztes Maximum sieht aus wie ein
                              // gemessenes. Genau diese vorgetäuschte Genauigkeit hat
                              // 2026-08-07 das Wiederholungsfeld gekostet. ?>
                        <?php // Drei Absätze statt eines Blocks: Es sind drei Spalten und
                              // drei Begriffe, und untereinander findet man den gesuchten,
                              // ohne einen Fließtext zu lesen. ?>
                        <?php if ($hatSaetze): ?>
                            <p class="matt">
                                <strong>Volumen</strong> ist die Summe aus Wiederholungen
                                mal Gewicht über alle Sätze einer Einheit — es steigt
                                auch dann, wenn das Gewicht gleich bleibt.
                            </p>
                            <p class="matt">
                                <strong>1RM</strong> ist das geschätzte
                                Einwiederholungsmaximum nach Epley
                                (kg × (1 + Wdh ÷ 30)) aus dem besten Satz — eine
                                Näherung, kein gemessener Wert.
                            </p>
                            <p class="matt">
                                <strong>Gewicht</strong> ist bei satzgenau erfassten
                                Übungen der Höchstwert dieser Einheit — also das Gewicht
                                des schwersten Satzes.
                            </p>
                        <?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
