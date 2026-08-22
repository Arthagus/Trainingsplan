<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';
require_once __DIR__ . '/lib/geraete.php';
require_once __DIR__ . '/lib/splits.php';

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

$offen = offene_einheit($userId);

// Der Split ist seit 1.2.0 die Klammer um die Rotation (§6.4, §7.6). Laeuft
// eine Einheit, gilt DEREN Split -- sonst zeigte die Seite eine Auswahl aus
// einem anderen Split, waehrend darunter ein Training aus einem dritten laeuft.
$split = null;
if ($offen !== null && $offen['plan_id'] !== null) {
    $herkunft = split_von_plan((int)$offen['plan_id']);
    $split    = $herkunft === null ? null : split_laden((int)$herkunft['split_id']);
}
$split ??= aktiver_split($userId);

$splitId = $split === null ? null : (int)$split['id'];
$plaene  = $splitId === null ? [] : plaene_im_split($splitId);

if ($offen !== null) {
    // Eine laufende Einheit gewinnt immer -- unabhaengig vom Datum. Sie laeuft
    // ueber Mitternacht weiter (§7.2).
    $planId = to_int_or_null($offen['plan_id']);
} elseif ($splitId === null) {
    $planId = null;
} else {
    // Ohne offene Einheit: Rotationsvorschlag, per ?plan= umschaltbar.
    //
    // $erlaubt umfasst ausschliesslich die Plaene des AKTIVEN Splits, nicht
    // alle eigenen: Die Liste ist hier der einzige Schutz gegen eine
    // untergeschobene Plan-ID, und sie haelt zugleich zwei Splits davon ab,
    // sich in einer Rotation zu vermischen.
    $gewuenscht = to_int_or_null($_GET['plan'] ?? null);
    $erlaubt    = array_map(static fn(array $p): int => (int)$p['id'], $plaene);

    if ($gewuenscht !== null && in_array($gewuenscht, $erlaubt, true)) {
        $planId = $gewuenscht;
    } else {
        $vorschlag = naechster_plan($userId, $splitId, $plaene);
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

// Laeuft gerade ein Training? Davon haengt mehr ab als die Farbe: Vor dem
// Start ist die ganze Erfassung gesperrt (siehe unten bei den Bedienelementen).
$laeuft = $offen !== null;

// Die AKTIVE Position traegt den gruenen Balken und, im Expertenmodus, den
// einzig aufgeklappten Satzblock. Alles andere bleibt zu, sonst waere die Seite
// bei acht Uebungen eine einzige lange Rolle. Uebersprungene Positionen tragen
// den orangen Balken. Beides rechnet positions_zustaende() aus -- die Regel
// steht dort samt Begruendung, weil index.js sie nachziehen muss.
//
// Der Zustand wird bewusst nirgends gespeichert: Er ergibt sich jedes Mal neu
// aus dem, was protokolliert ist.
$zustaende      = positions_zustaende($positionen, $laeuft);
$aktivePosition = $zustaende['aktiv'];
$uebersprungen  = $zustaende['uebersprungen'];

// Eine offene Einheit, die aelter als 12 Stunden ist, ist mit hoher
// Wahrscheinlichkeit vergessen worden -- sie blockiert sonst dauerhaft die
// Rotation (§7.6). Automatisch geschlossen wird trotzdem nichts.
$alt = false;
if ($offen !== null) {
    $alt = (strtotime((string)$offen['started_at']) + 12 * 3600) < time();
}

// Die Leiste, die waehrend des Trainings oben klebt (§7.4). Sie wird VOR dem
// Kopf-Partial gebaut, weil der sie in den Leisten-Stapel setzt -- und der
// steht als erstes Element im <body>, noch vor der Navigation.
//
// Nur bei laufender Einheit, wie alles andere, was eine Aussage ueber einen
// Ablauf trifft (gruene Markierung, Orange, aufgeklappter Satzblock). Ohne
// Training gibt es weder etwas zu zaehlen noch eine Dauer.
//
// Die Dauer reist als BEREITS VERSTRICHENE SEKUNDEN mit, nicht als Zeitstempel.
// Der Grund ist die Uhr des Geraets: started_at steht in Europe/Vienna in der
// Datenbank, und ein Handy mit falsch gestellter Uhr oder anderer Zeitzone
// rechnete daraus Unsinn. So ist der Wert beim Laden exakt, und index.js zaehlt
// nur noch die Zeit SEIT dem Laden dazu -- dafuer genuegt jede Uhr.
//
// "x/n" behaelt seine bisherige id: Die Zahl stand bis 1.1.13 in der Karte
// oben, dieselben zwei Funktionen in index.js schreiben sie jetzt hier. Eine
// zweite Anzeige daneben gibt es ausdruecklich nicht.
$leisteOben = '';
if ($laeuft && $plan !== null && $positionen !== []) {
    $sekunden = max(0, time() - (int)strtotime((string)$offen['started_at']));
    $offenAnz = max(0, $gesamt - $erledigt);

    $leisteOben =
        // KEIN role="status": Die Dauer aendert sich von selbst, und ein
        // Screenreader laese dann alle paar Sekunden ungefragt die ganze Leiste
        // vor. Die Verbindungsleiste hat das Attribut zu Recht -- sie meldet
        // ein Ereignis, keinen Zaehler.
        '<div class="training-leiste" data-sekunden="' . $sekunden . '">'
      .   '<span>'
      .     '<strong id="fortschritt-text">' . $erledigt . '/' . $gesamt . '</strong> erledigt'
      .     '<span class="leiste-offen"> · <span id="fortschritt-offen">'
      .       $offenAnz . '</span> offen</span>'
      .   '</span>'
      .   '<span class="leiste-dauer" id="leiste-dauer"></span>'
      . '</div>';
}

// Die Ueberschrift nennt den SPLIT, nicht den Plan -- fest, in jedem Zustand
// der Seite. Bis 1.2.10 stand dort der Plan, und im Auswahlzustand stand damit
// derselbe Name dreimal untereinander: als Ueberschrift, als "Vorgeschlagen:
// ..." und als blau markierter Knopf der Planwahl.
//
// Welcher PLAN gilt, sagt jetzt allein die Knopfreihe; welcher SPLIT gilt, die
// Ueberschrift. Das ist zugleich die ruhigere Aufteilung: Beim Umschalten
// zwischen Plaenen aendert sich die Ueberschrift nicht mehr.
$pageTitle = $split === null ? 'Training' : (string)$split['name'];
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($splitId === null): ?>

    <?php // Der Einstieg seit 1.2.0: Ohne gewaehlten Split gibt es nichts zu
          // trainieren, und die Antwort darauf ist nicht "frag den Admin",
          // sondern der Katalog. ?>
    <div class="karte">
        <p><strong>Noch kein Workout-Split gewählt.</strong></p>
        <p class="matt">
            Ein Split bündelt die Pläne, die miteinander abwechseln — etwa
            „Push / Pull“ oder „Ganzkörper A/B“. Such dir einen aus den Vorlagen
            aus; er wird zu dir kopiert, und ab dann gehört er dir allein.
        </p>
        <p>
            <a class="knopf" href="<?= h(base_path()) ?>/splits.php">Split auswählen</a>
        </p>
    </div>

<?php elseif ($plaene === []): ?>

    <div class="karte">
        <?php // Ohne den Splitnamen: Der steht seit 1.2.10 als Ueberschrift
              // direkt darueber. ?>
        <p><strong>In diesem Split steht noch kein Plan.</strong></p>
        <p class="matt">
            Ein Split braucht mindestens einen Plan — bei zweien wechseln sie
            sich ab, bei dreien läuft die Reihenfolge durch.
        </p>
        <p>
            <a class="knopf" href="<?= h(base_path()) ?>/plans.php?split=<?= $splitId ?>">
                Pläne bearbeiten
            </a>
            <a class="knopf zweit" href="<?= h(base_path()) ?>/splits.php">Anderer Split</a>
        </p>
    </div>

<?php else: ?>

    <?php // Bei geloeschtem Plan uebernimmt der Notfall-Kasten weiter unten --
          // sonst staenden hier zwei Beenden-Knoepfe und ein sinnloses "0/0". ?>
    <?php if ($offen !== null && $plan !== null): ?>
        <div class="karte einheit-laeuft <?= $alt ? 'hinweis-warnung' : '' ?>">
            <?php // Der Planname steht seit 1.2.10 HIER, weil die Ueberschrift den
                  // Split nennt. Waehrend eines Trainings gibt es keine Planwahl --
                  // ohne diese Zeile stuende nirgends mehr, welcher Plan laeuft,
                  // und die Uebungsliste allein beantwortet das nicht. ?>
            <?php if ($alt): ?>
                <strong>„<?= h((string)$plan['name']) ?>“ läuft seit <?= h(format_datetime($offen['started_at'])) ?>.</strong>
                <p class="matt">
                    Das ist länger als 12 Stunden her — fortsetzen oder beenden?
                    Nichts wird automatisch geschlossen.
                </p>
            <?php else: ?>
                <strong>„<?= h((string)$plan['name']) ?>“ läuft</strong>
                <span class="matt">seit <?= h(format_datetime($offen['started_at'])) ?></span>
            <?php endif; ?>

            <?php // "x/n erledigt" stand bis 1.1.13 hier. Es steht jetzt in der
                  // Leiste am oberen Rand, die waehrend des Trainings immer
                  // sichtbar ist -- genau deshalb gibt es sie. Zwei Anzeigen
                  // derselben Zahl waeren doppelte Pflege ohne Gegenwert. ?>
            <p>
                <button type="button" id="einheit-beenden" class="gefahr">Training beendet</button>
            </p>
        </div>
    <?php elseif ($plan !== null): ?>
        <div class="karte">
            <?php // Reihenfolge seit 1.2.10, auf Ansage des Benutzers: erst die
                  // Wahl (welcher Plan?), dann was der Start bedeutet, dann der
                  // Start selbst, zuletzt der Weg hinaus. Vorher stand der Knopf
                  // vor seiner eigenen Erklaerung und die Wahl darunter.
                  //
                  // "Vorgeschlagen: ..." ist ERSATZLOS entfallen. Der Vorschlag
                  // selbst ist unveraendert -- naechster_plan() entscheidet
                  // weiter, welcher Knopf beim Aufruf blau ist (Fallstrick 21);
                  // er hat nur keine eigene Zeile mehr, weil der blaue Knopf
                  // dieselbe Aussage trifft. ?>

            <?php // Immer, auch bei nur EINEM Plan: Die Reihe ist nicht bloss
                  // Auswahl, sie nennt den Plan. Seit die Ueberschrift den Split
                  // traegt, stuende sein Name sonst nirgends. ?>
            <div class="plan-wahl">
                <?php foreach ($plaene as $p): ?>
                    <a href="?plan=<?= (int)$p['id'] ?>"
                       class="<?= (int)$p['id'] === $planId ? 'aktiv' : '' ?>">
                        <?= h((string)$p['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <p class="matt">
                Startet die Einheit mit der aktuellen Uhrzeit. Erst danach lassen
                sich Übungen abhaken und für heute tauschen — bloßes Durchsehen
                beginnt kein Training.
            </p>

            <?php // Punkt 7 der Rückmeldungen: Ohne diesen Knopf entstand die Einheit
                  // erst beim Abhaken der ersten Übung -- der Zeitstempel war damit ihr
                  // ENDE, nicht der Trainingsbeginn. Für die Auswertung wären alle
                  // Dauern systematisch zu kurz. ?>
            <p>
                <button type="button" id="einheit-starten"
                        data-plan="<?= (int)$planId ?>">Training starten</button>
            </p>

            <?php // Der Weg zu einem anderen Split. WELCHER Split gilt, steht seit
                  // 1.2.10 in der Ueberschrift -- hier bleibt nur noch die Aktion,
                  // und die ist ein Link und kein Knopf: Sie fuehrt weg von der
                  // Seite und steht damit unter allem, was hier zu tun ist. ?>
            <p class="matt split-zeile">
                Aktuellen Split <a href="<?= h(base_path()) ?>/splits.php">wechseln</a>
            </p>
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
            <p class="matt">Der Split gehört dir — du kannst die Übungen selbst ergänzen.</p>
            <p>
                <a class="knopf" href="<?= h(base_path()) ?>/plans.php?split=<?= $splitId ?>">
                    Übungen hinzufügen
                </a>
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
            <?php // Welches der beiden Verfahren aus SATZ_VORLAGE gilt (§7.4).
                  // Wird beim Seitenaufbau festgeschrieben und aendert sich
                  // waehrend der Sitzung nicht -- wer auf der Kontoseite
                  // umstellt, verlaesst diese Seite ohnehin und kommt mit einem
                  // neuen Aufbau zurueck. Genau deshalb braucht der Endpunkt
                  // auch keine Sperre bei laufendem Training. ?>
            data-satz-vorlage="<?= h(satz_vorlage_normalisieren($benutzer['satz_vorlage'] ?? null)) ?>"
            data-erledigt="<?= $erledigt ?>" data-gesamt="<?= $gesamt ?>">
            <?php foreach ($positionen as $z): ?>
                <?php // Die Satzlisten reisen als JSON im Attribut mit. Gezeichnet
                      // werden die Zeilen ausschliesslich in index.js: Sie sind ein
                      // Bedienelement, das sich im Betrieb staendig aendert
                      // (Satz dazu, Satz weg), und zwei Renderer fuer dieselbe Zeile
                      // waeren irgendwann verschieden -- derselbe Grund, aus dem sich
                      // die beiden Tauschdialoge vorschlagMarkup() teilen. ?>
                <?php
                // Vier Zustaende, vier Farben am linken Rand (§7.3):
                //   erledigt -> blau, aktiv -> gruen, uebersprungen -> orange,
                //   sonst -> grau
                if ($z['erledigt']) {
                    $zustandKlasse = 'zeile-erledigt';
                } elseif ($z['plan_exercise_id'] === $aktivePosition) {
                    $zustandKlasse = 'zeile-aktiv';
                } elseif (in_array((int)$z['plan_exercise_id'], $uebersprungen, true)) {
                    $zustandKlasse = 'zeile-uebersprungen';
                } else {
                    $zustandKlasse = 'zeile-offen';
                }
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
                                <img class="<?= h(trim('uebung-bild ' . bild_zuschnitt_klasse($z['image_crop'] ?? null))) ?>"
                                     src="<?= h(base_path()) ?>/image.php?f=<?= h($thumb) ?>"
                                     alt="" loading="lazy" width="150" height="150">
                            </button>
                        <?php else: ?>
                            <span class="uebung-bild uebung-bild-leer" aria-hidden="true">–</span>
                        <?php endif; ?>

                        <div class="uebung-text">
                            <?= uebung_name((string)$z['name_de'], $z['name_en']) ?>
                            <?php if ($z['getauscht']): ?>
                                <?php // Einzeilig (uebung_name_kurz), weil das Abzeichen in
                                      // der laufenden Zeile steht -- der Umbruch aus
                                      // .name-en zerlegte es. ?>
                                <span class="abzeichen">statt <?= uebung_name_kurz(
                                    (string)$z['plan_uebung_name'], $z['plan_uebung_name_en']
                                ) ?></span>
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
                        </div>

                        <?php // Das Trainingsgeraet steht hier bewusst mit im Studio: Es
                              // sagt, wohin man gehen muss, und ist damit die Information,
                              // die man beim Blick aufs Handy als naechste braucht.
                              //
                              // Seit 2026-08-17 UNTER dem Bild ueber die volle Breite statt
                              // in der Textspalte -- so bleibt der Textspalte weniger
                              // Breite abzuringen und das Bild darf groesser werden. ?>
                        <p class="schwerpunkt-zeile">
                            <?= geraet_abzeichen($z['equipment'] ?? null) ?>
                            <?php if (!empty($z['focus'])): ?>
                                <span class="schwerpunkt"><?= h((string)$z['focus']) ?></span>
                            <?php endif; ?>
                        </p>
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

                            <?php // Vor dem Start gesperrt (§7.6): Ein Satz waere die
                                  // erste Eingabe, und die legte frueher stillschweigend
                                  // eine Einheit an. Wer den Plan nur durchsieht, soll
                                  // mit einem Fehlgriff kein Training beginnen.
                                  // api/log.php lehnt es ohnehin ab; der graue Knopf ist
                                  // die Bequemlichkeit davor. ?>
                            <button type="button" class="satz-hinzu"
                                    <?= $laeuft ? '' : 'disabled title="Erst das Training starten"' ?>>+ Satz</button>
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
                                       <?php // Vor dem Start ebenfalls gesperrt: Das Feld
                                             // allein speichert zwar nichts -- gespeichert
                                             // wird erst mit dem Haekchen --, aber ein Wert,
                                             // den man eintippt und der beim Neuladen weg
                                             // ist, sieht wie Datenverlust aus. ?>
                                       <?= $z['erledigt'] || !$laeuft ? 'readonly' : '' ?>>
                                <span class="wert-einheit" aria-hidden="true">kg</span>
                            </span>
                        <?php endif; ?>

                        <?php // Vor dem Start gesperrt (§7.6). Das Haekchen war der
                              // urspruengliche Ausloeser einer Einheit -- genau das soll
                              // es nicht mehr sein: Ein Training beginnt ausschliesslich
                              // mit "Training starten", sonst haelt started_at nicht den
                              // Beginn fest, sondern irgendeinen Fehlgriff davor. ?>
                        <label class="zeile-wahl erledigt-wahl"
                               <?= $laeuft ? '' : 'title="Erst das Training starten"' ?>>
                            <input type="checkbox" class="erledigt"
                                   <?= $z['erledigt'] ? 'checked' : '' ?>
                                   <?= $laeuft ? '' : 'disabled' ?>>
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

        <?php // Der zweite Weg zum Beenden, am ENDE der Liste (§7.6).
              //
              // Bis 1.1.13 stand der Knopf nur oben. Nach der letzten Uebung ist
              // man aber ganz unten -- und musste an acht Karten vorbei wieder
              // hoch, um ein Training zu schliessen, das erkennbar fertig war.
              // Der Kasten oben bleibt trotzdem stehen: Wer mittendrin abbricht,
              // sucht ihn dort.
              //
              // Kein Knopf in der Sticky-Leiste, und das ist eine bewusste
              // Entscheidung des Benutzers: Etwas Rotes, das dauerhaft unter dem
              // Daumen klebt, wird irgendwann versehentlich getroffen.
              //
              // Nur bei laufender Einheit -- ohne Training gibt es nichts zu
              // beenden, und der Kasten waere eine Aufforderung ins Leere. ?>
        <?php if ($laeuft): ?>
            <div class="karte einheit-abschluss">
                <p>
                    <strong>Fertig für heute?</strong>
                    Mit dem Knopf wird die Einheit abgeschlossen und in den Verlauf
                    übernommen. Vorher wird noch einmal nachgefragt.
                </p>
                <p>
                    <button type="button" id="einheit-beenden-unten" class="gefahr">
                        Training beenden
                    </button>
                </p>
            </div>
        <?php endif; ?>

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
