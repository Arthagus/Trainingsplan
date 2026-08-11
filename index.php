<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';
require_once __DIR__ . '/lib/geraete.php';

bootstrap_session();
require_login();

/**
 * Handy-Ansicht (§7.2 bis §7.6).
 *
 * Der Hauptfall der App: Plan im Studio oeffnen, Gewichte sehen, abhaken.
 * Deshalb zeigt die Seite genau EINE Sache -- die laufende Einheit, sonst den
 * vorgeschlagenen Plan. Keine Planauswahl als Startbildschirm.
 */

$benutzer = current_user();
$userId   = (int)$benutzer['id'];

$offen  = offene_einheit($userId);
$plaene = plaene_von($userId);

if ($offen !== null) {
    // Eine laufende Einheit gewinnt immer -- unabhaengig vom Datum. Sie laeuft
    // ueber Mitternacht weiter (§7.2).
    $planId = to_int_or_null($offen['plan_id']);
} else {
    // Ohne offene Einheit: Rotationsvorschlag, per ?plan= umschaltbar.
    $gewuenscht = to_int_or_null($_GET['plan'] ?? null);
    $erlaubt    = array_map(static fn(array $p): int => (int)$p['id'], $plaene);

    if ($gewuenscht !== null && in_array($gewuenscht, $erlaubt, true)) {
        $planId = $gewuenscht;
    } else {
        $vorschlag = naechster_plan($userId, to_int_or_null($benutzer['last_plan_id']), $plaene);
        $planId    = $vorschlag === null ? null : (int)$vorschlag['id'];
    }
}

$plan = null;
foreach ($plaene as $p) {
    if ((int)$p['id'] === $planId) {
        $plan = $p;
        break;
    }
}

$experte = (int)($benutzer['expert_mode'] ?? 0) === 1;

$positionen = ($planId === null)
    ? []
    : plan_positionen($userId, $planId, $offen === null ? null : (int)$offen['id'], $experte);
$erledigt   = count(array_filter($positionen, static fn(array $z): bool => $z['erledigt']));
$gesamt     = count($positionen);

// Die AKTIVE Position: die erste noch nicht erledigte -- die Uebung, an der man
// gerade steht. Sie traegt den gruenen Balken und, im Expertenmodus, den einzig
// aufgeklappten Satzblock. Alles andere bleibt zu, sonst waere die Seite bei
// acht Uebungen eine einzige lange Rolle. Der Zustand wird bewusst nirgends
// gespeichert: Er wandert von selbst mit, waehrend man den Plan durcharbeitet.
//
// NUR WAEHREND EINES TRAININGS. Wer den Plan bloss anschaut, sieht eine ruhige
// Liste: kein aufgeklappter Satzblock, keine "hier bist du"-Markierung. Beides
// waere eine Aussage ueber einen Ablauf, der noch gar nicht laeuft.
$aktivePosition = null;
if ($offen !== null) {
    foreach ($positionen as $z) {
        if (!$z['erledigt']) {
            $aktivePosition = $z['plan_exercise_id'];
            break;
        }
    }
}

// Eine offene Einheit, die aelter als 12 Stunden ist, ist mit hoher
// Wahrscheinlichkeit vergessen worden -- sie blockiert sonst dauerhaft die
// Rotation (§7.6). Automatisch geschlossen wird trotzdem nichts.
$alt = false;
if ($offen !== null) {
    $alt = (strtotime((string)$offen['started_at']) + 12 * 3600) < time();
}

$pageTitle = $plan === null ? 'Training' : (string)$plan['name'];
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($plaene === []): ?>

    <div class="karte">
        <p><strong>Für Sie ist noch kein Trainingsplan hinterlegt.</strong></p>
        <p class="matt">
            <?php if ((int)$benutzer['is_admin'] === 1): ?>
                Pläne werden im Adminbereich angelegt.
            <?php else: ?>
                Der Administrator legt die Pläne an — bitte dort nachfragen.
            <?php endif; ?>
        </p>
        <?php if ((int)$benutzer['is_admin'] === 1): ?>
            <p>
                <a class="knopf" href="<?= h(base_path()) ?>/admin_plans.php?user=<?= $userId ?>">
                    Plan anlegen
                </a>
            </p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <?php // Bei geloeschtem Plan uebernimmt der Notfall-Kasten weiter unten --
          // sonst staenden hier zwei Beenden-Knoepfe und ein sinnloses "0/0". ?>
    <?php if ($offen !== null && $plan !== null): ?>
        <div class="karte einheit-laeuft <?= $alt ? 'hinweis-warnung' : '' ?>">
            <?php if ($alt): ?>
                <strong>Deine Einheit läuft seit <?= h(format_datetime($offen['started_at'])) ?>.</strong>
                <p class="matt">
                    Das ist länger als 12 Stunden her — fortsetzen oder beenden?
                    Nichts wird automatisch geschlossen.
                </p>
            <?php else: ?>
                <strong>Einheit läuft</strong>
                <span class="matt">seit <?= h(format_datetime($offen['started_at'])) ?></span>
            <?php endif; ?>

            <p class="fortschritt">
                <span id="fortschritt-text"><?= $erledigt ?>/<?= $gesamt ?></span> erledigt
            </p>
            <p>
                <button type="button" id="einheit-beenden" class="gefahr">Training beendet</button>
            </p>
        </div>
    <?php elseif ($plan !== null): ?>
        <div class="karte">
            <strong>Vorgeschlagen: <?= h((string)$plan['name']) ?></strong>
            <?php // Punkt 7 der Rückmeldungen: Ohne diesen Knopf entstand die Einheit
                  // erst beim Abhaken der ersten Übung -- der Zeitstempel war damit ihr
                  // ENDE, nicht der Trainingsbeginn. Für die Auswertung wären alle
                  // Dauern systematisch zu kurz. ?>
            <p>
                <button type="button" id="einheit-starten"
                        data-plan="<?= (int)$planId ?>">Training starten</button>
            </p>
            <p class="matt">
                Startet die Einheit mit der aktuellen Uhrzeit. Sie beginnt ohnehin
                automatisch, sobald die erste Übung abgehakt oder getauscht wird —
                dann zählt aber erst dieser spätere Moment.
            </p>

            <?php if (count($plaene) > 1): ?>
                <div class="plan-wahl">
                    <?php foreach ($plaene as $p): ?>
                        <a href="?plan=<?= (int)$p['id'] ?>"
                           class="<?= (int)$p['id'] === $planId ? 'aktiv' : '' ?>">
                            <?= h((string)$p['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($plan === null && $offen !== null): ?>
        <div class="karte hinweis-warnung">
            <strong>Der Plan dieser Einheit wurde gelöscht.</strong>
            <p class="matt">
                Die Einheit läuft seit <?= h(format_datetime($offen['started_at'])) ?> und
                lässt sich noch beenden, aber nicht mehr fortsetzen. Danach schlägt die App
                wieder einen vorhandenen Plan vor.
            </p>
            <p><button type="button" id="einheit-beenden-notfall" class="gefahr">Training beenden</button></p>
        </div>
    <?php elseif ($plan === null): ?>
        <?php // Sollte nicht vorkommen: Ohne offene Einheit liefert
              // naechster_plan() immer einen Plan, solange ueberhaupt einer existiert. ?>
        <div class="karte">
            <p><strong>Kein Plan auswählbar.</strong></p>
            <p class="matt">Bitte die Seite neu laden.</p>
        </div>
    <?php elseif ($positionen === []): ?>
        <div class="karte">
            <p><strong>In „<?= h((string)$plan['name']) ?>“ steht noch keine Übung.</strong></p>
            <p class="matt">
                <?php if ((int)$benutzer['is_admin'] === 1): ?>
                    Übungen werden im Adminbereich zum Plan hinzugefügt.
                <?php else: ?>
                    Der Administrator ergänzt die Übungen — bitte dort nachfragen.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>

        <?php // data-user und data-session schluesseln die Warteschlange in
              // index.js (§7.4). Beide sind zwingend: localStorage gehoert der
              // Herkunft und nicht der Sitzung, und ein wartendes Haekchen
              // gehoert zu genau EINER Trainingseinheit. Ohne offene Einheit
              // bleibt data-session leer -- dann laeuft das Abhaken direkt. ?>
        <ul id="uebungen" class="liste-schlicht"
            data-user="<?= $userId ?>"
            data-session="<?= $offen === null ? '' : (int)$offen['id'] ?>"
            data-experte="<?= $experte ? '1' : '' ?>"
            data-erledigt="<?= $erledigt ?>" data-gesamt="<?= $gesamt ?>">
            <?php foreach ($positionen as $z): ?>
                <?php // Die Satzlisten reisen als JSON im Attribut mit. Gezeichnet
                      // werden die Zeilen ausschliesslich in index.js: Sie sind ein
                      // Bedienelement, das sich im Betrieb staendig aendert
                      // (Satz dazu, Satz weg), und zwei Renderer fuer dieselbe Zeile
                      // waeren irgendwann verschieden -- derselbe Grund, aus dem sich
                      // die beiden Tauschdialoge vorschlagMarkup() teilen. ?>
                <?php
                // Drei Zustaende, drei Farben am linken Rand (§7.3):
                //   erledigt -> blau, aktiv -> gruen, sonst -> grau
                $zustandKlasse = $z['erledigt']
                    ? 'zeile-erledigt'
                    : ($z['plan_exercise_id'] === $aktivePosition ? 'zeile-aktiv' : 'zeile-offen');
                ?>
                <li class="karte position-karte <?= $zustandKlasse ?>"
                    data-pe="<?= $z['plan_exercise_id'] ?>"
                    data-eintrag="<?= $z['hat_eintrag'] ? '1' : '' ?>"
                    <?php if ($experte): ?>
                        data-saetze="<?= h(json_encode($z['saetze'])) ?>"
                        data-letzte-saetze="<?= h(json_encode($z['letzte_saetze'])) ?>"
                        <?php // Rueckfall fuer den ersten Satz einer Uebung, die noch
                              // nie satzgenau protokolliert wurde -- wer aus dem
                              // einfachen Modus kommt, hat hier trotzdem eine Zahl. ?>
                        data-letztes-gewicht="<?= h(format_decimal($z['letztes_gewicht'])) ?>"
                    <?php endif; ?>>

                    <div class="uebung-kopf">
                        <?php if (!empty($z['image_path'])): ?>
                            <?php $thumb = substr((string)$z['image_path'], 0, 32) . '_thumb.jpg'; ?>
                            <button type="button" class="bild-knopf" aria-label="Bild und Beschreibung anzeigen">
                                <img class="uebung-bild"
                                     src="<?= h(base_path()) ?>/image.php?f=<?= h($thumb) ?>"
                                     alt="" loading="lazy" width="120" height="120">
                            </button>
                        <?php else: ?>
                            <span class="uebung-bild uebung-bild-leer" aria-hidden="true">–</span>
                        <?php endif; ?>

                        <div class="uebung-text">
                            <strong><?= h($z['name_de']) ?></strong>
                            <?php if (!empty($z['name_en'])): ?>
                                <span class="matt"><?= h((string)$z['name_en']) ?></span>
                            <?php endif; ?>
                            <?php if ($z['getauscht']): ?>
                                <span class="abzeichen">statt <?= h($z['plan_uebung_name']) ?></span>
                            <?php endif; ?>

                            <?php // Erst die Muskelgruppen (primaer vorn, danach die
                                  // sekundaeren -- so sortiert die Abfrage), die Ausfuehrung
                                  // in einer eigenen Zeile darunter. Nebeneinander waren
                                  // Tauschklasse und blosse Zusatzinformation kaum
                                  // auseinanderzuhalten. ?>
                            <p class="gruppen-anzeige">
                                <?php foreach ($z['muskelgruppen'] as $g): ?>
                                    <span class="<?= (int)$g['is_primary'] === 1 ? 'gruppe-primaer' : 'gruppe-sekundaer' ?>">
                                        <?= h((string)$g['name_de']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </p>
                            <?php // Das Trainingsgeraet steht hier bewusst mit im
                                  // Studio: Es sagt, wohin man gehen muss, und ist
                                  // damit die Information, die man beim Blick aufs
                                  // Handy als naechste braucht. ?>
                            <p class="schwerpunkt-zeile">
                                <?= geraet_abzeichen($z['equipment'] ?? null) ?>
                                <?php if (!empty($z['focus'])): ?>
                                    <span class="schwerpunkt"><?= h((string)$z['focus']) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($z['description'])): ?>
                        <p class="beschreibung" hidden><?= h((string)$z['description']) ?></p>
                    <?php endif; ?>

                    <?php // Die Zeile steht IMMER, auch ohne vorherigen Wert. Bis 1.0.18
                          // blieb sie dann leer -- die Begruendung war, man sehe es am
                          // leeren Gewichtsfeld. In der Praxis sieht man dort nur nichts,
                          // und nichts ist zweideutig: kein Wert vorhanden, oder Wert
                          // vergessen? Ein Satz beantwortet das, und die Karten behalten
                          // dieselbe Hoehe. ?>
                    <p class="matt letzter-wert">
                        <?php if ($experte && $z['letzte_saetze'] !== []): ?>
                            <?php // Im Expertenmodus ist die Satzfolge vom letzten Mal
                                  // die nuetzlichere Auskunft als eine einzelne Zahl --
                                  // sie ist zugleich das, was der Knopf "+ Satz" gleich
                                  // vorschlaegt. Gleiche Form wie die Zusammenfassung im
                                  // Satzblock darunter, damit man beides ohne Umdenken
                                  // vergleichen kann: erst wie viele, dann welche. ?>
                            zuletzt <?= h(saetze_zusammenfassung($z['letzte_saetze'])) ?>
                        <?php elseif ($z['letztes_gewicht'] !== null): ?>
                            zuletzt <?= h(format_decimal($z['letztes_gewicht'])) ?> kg
                        <?php else: ?>
                            Noch kein Gewicht gespeichert
                        <?php endif; ?>
                    </p>

                    <?php if ($experte): ?>
                        <?php $eigene = $z['saetze']; ?>
                        <details class="saetze-block"
                                 <?= $z['plan_exercise_id'] === $aktivePosition ? 'open' : '' ?>>
                            <summary class="summary-knopf saetze-kopf">
                                <span class="saetze-zusammenfassung"><?=
                                    h(saetze_zusammenfassung($eigene))
                                ?></span>
                            </summary>

                            <?php // Wird von index.js aus data-saetze gefuellt. ?>
                            <ol class="satz-liste"></ol>

                            <button type="button" class="satz-hinzu">+ Satz</button>
                        </details>
                    <?php endif; ?>

                    <?php // Tauschen -- Gewicht -- Erledigt in EINER Zeile. Das
                          // Gewichtsfeld war ueber die volle Breite gezogen und
                          // beanspruchte damit mehr Platz als die beiden Aktionen
                          // zusammen; gebraucht werden drei bis vier Zeichen. ?>
                    <div class="position-aktionen">
                        <?php // Protokolliert heisst gesperrt (§7.5) -- und zwar ab dem
                              // ersten Satz, nicht erst ab dem Haekchen: Der Eintrag hält
                              // fest, was tatsächlich gemacht wurde. api/swap.php weist
                              // es ohnehin ab; hier wird es gar nicht erst angeboten. ?>
                        <button type="button" class="leise tauschen"
                                <?= $z['hat_eintrag']
                                    ? 'disabled title="Erst die protokollierten Werte entfernen"'
                                    : '' ?>>
                            Tauschen
                        </button>

                        <?php // Im Expertenmodus steht das Gewicht in jedem Satz --
                              // ein zusätzliches Feld für die ganze Übung wäre eine
                              // zweite Wahrheit neben der Satzliste. ?>
                        <?php if (!$experte): ?>
                            <span class="wert-feld">
                                <label for="w<?= $z['plan_exercise_id'] ?>" class="nur-lesbar">
                                    Gewicht in kg
                                </label>
                                <?php // type="text" mit inputmode: type="number" bricht am
                                      // Handy am Dezimalkomma.
                                      //
                                      // readonly, sobald abgehakt (§7.4): Wer den Wert ändern
                                      // will, entfernt das Häkchen, korrigiert und hakt neu ab
                                      // -- derselbe Weg wie beim Tausch (§7.5). ?>
                                <input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"
                                       id="w<?= $z['plan_exercise_id'] ?>" class="gewicht"
                                       value="<?= h(format_decimal($z['weight'])) ?>"
                                       placeholder="—" enterkeyhint="done"
                                       <?= $z['erledigt'] ? 'readonly' : '' ?>>
                                <span class="wert-einheit" aria-hidden="true">kg</span>
                            </span>
                        <?php endif; ?>

                        <label class="zeile-wahl erledigt-wahl">
                            <input type="checkbox" class="erledigt"
                                   <?= $z['erledigt'] ? 'checked' : '' ?>>
                            Erledigt
                        </label>

                        <button type="button" class="wiederholen" hidden>Erneut versuchen</button>
                    </div>

                    <?php // Der sichtbare Vorbehalt bei schlechtem Netz steckt allein im
                          // gestrichelten Balken am linken Rand — plus der Leiste ganz
                          // oben, die die Anzahl nennt. Hier stand bis 1.1.1 zusätzlich
                          // ein Hinweissatz; er machte die Karte für die Dauer des
                          // Speicherns höher und danach wieder niedriger, und bei jedem
                          // Satz sprang die ganze Liste darunter. Zwei Anzeigen für
                          // dieselbe Sache, von denen eine die Seite unruhig macht. ?>
                    <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php endif; ?>

<?php endif; ?>

<dialog id="tausch-dialog">
    <h2 id="tausch-titel">Übung tauschen</h2>
    <p class="matt">
        Vorgeschlagen werden Übungen derselben <strong>primären</strong> Hauptgruppe.
        Ganz oben stehen die mit genau derselben Untergruppe.
    </p>
    <?php // Der Gerätefilter arbeitet rein im Browser: Die Vorschläge liegen nach
          // dem ersten Abruf schon vollständig vor, und im Studio ist das Netz
          // genau die Stelle, an der man nicht auf einen zweiten Abruf warten will.
          // Die Auswahl füllt index.js aus den tatsächlich vorhandenen Vorschlägen,
          // deshalb steht hier nur die erste Option. ?>
    <p class="tausch-filter" hidden>
        <label for="tausch-geraet" class="nur-lesbar">Trainingsgerät</label>
        <select id="tausch-geraet">
            <option value="">alle Trainingsgeräte</option>
        </select>
    </p>
    <div id="tausch-liste"></div>
    <p id="tausch-fehler" class="feld-fehler" role="alert" hidden></p>
    <p><button type="button" id="tausch-schliessen" class="leise">Abbrechen</button></p>
</dialog>

<?php require __DIR__ . '/lib/view_bild_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
