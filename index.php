<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';

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

$positionen = ($planId === null) ? [] : plan_positionen($userId, $planId, $offen === null ? null : (int)$offen['id']);
$erledigt   = count(array_filter($positionen, static fn(array $z): bool => $z['erledigt']));
$gesamt     = count($positionen);

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
            data-erledigt="<?= $erledigt ?>" data-gesamt="<?= $gesamt ?>">
            <?php foreach ($positionen as $z): ?>
                <li class="karte position-karte <?= $z['erledigt'] ? 'zeile-erledigt' : 'zeile-offen' ?>"
                    data-pe="<?= $z['plan_exercise_id'] ?>">

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
                            <?php if (!empty($z['focus'])): ?>
                                <p class="schwerpunkt-zeile">
                                    <span class="schwerpunkt"><?= h((string)$z['focus']) ?></span>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($z['description'])): ?>
                        <p class="beschreibung" hidden><?= h((string)$z['description']) ?></p>
                    <?php endif; ?>

                    <?php // Ohne vorherigen Wert steht hier nichts: Dass noch keiner
                          // protokolliert wurde, sieht man am leeren Feld. ?>
                    <?php if ($z['letztes_gewicht'] !== null): ?>
                        <p class="matt letzter-wert">
                            zuletzt <?= h(format_decimal($z['letztes_gewicht'])) ?> kg
                        </p>
                    <?php endif; ?>

                    <?php // Tauschen -- Gewicht -- Erledigt in EINER Zeile. Das
                          // Gewichtsfeld war ueber die volle Breite gezogen und
                          // beanspruchte damit mehr Platz als die beiden Aktionen
                          // zusammen; gebraucht werden drei bis vier Zeichen. ?>
                    <div class="position-aktionen">
                        <!-- Abgehakt heisst gesperrt (§7.5): erst Haekchen weg,
                             dann tauschen. index.js schaltet das mit um. -->
                        <button type="button" class="leise tauschen"
                                <?= $z['erledigt'] ? 'disabled title="Erst das Häkchen entfernen"' : '' ?>>
                            Tauschen
                        </button>

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

                        <label class="zeile-wahl erledigt-wahl">
                            <input type="checkbox" class="erledigt"
                                   <?= $z['erledigt'] ? 'checked' : '' ?>>
                            Erledigt
                        </label>

                        <button type="button" class="wiederholen" hidden>Erneut versuchen</button>
                    </div>

                    <?php // Der sichtbare Vorbehalt bei schlechtem Netz: Das Häkchen
                          // bleibt stehen, trägt aber erkennbar, dass der Server es
                          // noch nicht bestätigt hat. Früher sprang es stattdessen
                          // zurück — richtig, aber am Gerät stehend unbrauchbar. ?>
                    <p class="wartet-hinweis" hidden>
                        Noch nicht gespeichert — wird nachgeholt, sobald das Netz da ist.
                    </p>
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
    <div id="tausch-liste"></div>
    <p id="tausch-fehler" class="feld-fehler" role="alert" hidden></p>
    <p><button type="button" id="tausch-schliessen" class="leise">Abbrechen</button></p>
</dialog>

<?php require __DIR__ . '/lib/view_bild_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
