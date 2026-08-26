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
 * Planverwaltung innerhalb EINES Splits (§6.4).
 *
 * Die Seite hiess bis 1.1.15 admin_plans.php und war ausschliesslich fuer
 * Admins. Seit 1.2.0 bearbeitet jeder Benutzer seine eigenen Splits, und der
 * Dateiname darf das nicht mehr anders behaupten. Eine zweite,
 * benutzerseitige Fassung daneben waere die Dublette, die frueher oder spaeter
 * abweicht -- es ist DIESELBE Arbeit, nur an einem anderen Bestand.
 *
 * Die Reihenfolge der Plaene ist die Rotationsreihenfolge aus §7.6 -- deshalb
 * steht sie hier nicht nur zum Sortieren bereit, sondern als Vorschau.
 *
 * Was waehlbar ist, entscheidet $waehlbar weiter unten und NICHT der
 * ?split=-Parameter: Ein unbekannter oder fremder Wert faellt auf den ersten
 * erlaubten Split zurueck. Damit ist der IDOR-Schutz (§5) hier keine Pruefung,
 * die man vergessen kann, sondern die Liste selbst -- dasselbe Muster wie
 * $erlaubt in index.php.
 */

$benutzer = current_user();
$userId   = (int)$benutzer['id'];
$istAdmin = (int)$benutzer['is_admin'] === 1;

// Die Splits, die dieser Benutzer bearbeiten darf, in der Reihenfolge, in der
// sie im Auswahlfeld stehen sollen: erst die eigenen, dann -- fuer Admins --
// der Katalog und die Splits der anderen.
$waehlbar = [];
foreach (splits_von($userId) as $sp) {
    $waehlbar[] = $sp + ['gruppe' => 'Meine Splits', 'zusatz' => ''];
}
if ($istAdmin) {
    foreach (vorlagen() as $sp) {
        $waehlbar[] = $sp + ['gruppe' => 'Vorlagen', 'zusatz' => ''];
    }
    foreach (db()->query(
        'SELECT sp.id, sp.user_id, sp.name, sp.beschreibung, sp.sort_order,
                u.name AS besitzer
           FROM splits sp
           JOIN users u ON u.id = sp.user_id
          WHERE sp.user_id IS NOT NULL
          ORDER BY u.name, sp.sort_order, sp.id'
    ) as $sp) {
        if ((int)$sp['user_id'] === $userId) {
            continue;
        }
        $waehlbar[] = $sp + ['gruppe' => 'Andere Benutzer', 'zusatz' => (string)$sp['besitzer'] . ': '];
    }
}

$gewaehlt = to_int_or_null($_GET['split'] ?? null);

$split = null;
foreach ($waehlbar as $sp) {
    if ((int)$sp['id'] === $gewaehlt) {
        $split = $sp;
        break;
    }
}

// Fehlender, unbekannter oder fremder Parameter: auf den AKTIVEN Split des
// Aufrufers zurueckfallen -- den, auf dem er gerade trainiert.
//
// Bis 1.2.11 stand hier schlicht $waehlbar[0], also der erste eigene Split in
// seiner Sortierung. Das ist bei mehreren Splits fast immer der falsche: Wer
// ueber die Kopfzeile auf "Pläne" geht, will den bearbeiten, mit dem er
// arbeitet, und nicht den, der zufaellig oben steht.
//
// aktiver_split() liefert ausschliesslich EIGENE Splits (der JOIN verlangt
// sp.user_id = u.id) -- ein Admin landet hier also nie auf einer Vorlage oder
// im Bestand eines anderen. Genommen wird trotzdem die Zeile aus $waehlbar und
// nicht die von dort: Nur sie traegt 'gruppe' und 'zusatz' fuer das
// Auswahlfeld.
//
// Ein ausdrueckliches ?split= schlaegt das weiterhin -- der Weg von
// splits.php ("Pläne bearbeiten") fuehrt genau darueber.
if ($split === null && $waehlbar !== []) {
    $aktiv = aktiver_split($userId);

    if ($aktiv !== null) {
        foreach ($waehlbar as $sp) {
            if ((int)$sp['id'] === (int)$aktiv['id']) {
                $split = $sp;
                break;
            }
        }
    }

    // Kein aktiver Split (noch nie trainiert, gar keine eigenen) -- dann bleibt
    // der erste erlaubte, statt eine leere Seite mit totem Formular zu zeigen.
    $split ??= $waehlbar[0];

    $gewaehlt = (int)$split['id'];
}

$istVorlage = $split !== null && $split['user_id'] === null;
$besitzerId = ($split === null || $split['user_id'] === null) ? null : (int)$split['user_id'];

$plaene            = [];
$positionen        = [];
$gruppenZuPosition = [];
$offeneEinheit     = null;
$aktiveUebungen    = 0;

if ($split !== null) {
    $plaene = plaene_im_split($gewaehlt);

    // Eine Vorlage hat keinen Besitzer und wird von niemandem trainiert --
    // es gibt also auch keine offene Einheit.
    //
    // Der Rotations-VORSCHLAG stand hier bis 1.2.18 daneben (naechster_plan()
    // und zuletzt_trainierter_plan()) und markierte den naechsten Plan in der
    // Kette blau. Er ist ersatzlos weg: Auf dieser Seite baut man den Plan um,
    // und welches Training als naechstes ansteht, gehoert in die
    // Trainingsansicht -- dort steht es und wird dort auch gebraucht.
    if ($besitzerId !== null) {
        $offeneEinheit = offene_einheit($besitzerId);
    }

    if ($plaene !== []) {
        $planIds = array_map(static fn(array $p): int => (int)$p['id'], $plaene);
        $platzhalter = implode(',', array_fill(0, count($planIds), '?'));

        $stmt = db()->prepare(
            "SELECT pe.id, pe.plan_id, pe.sort_order, e.id AS exercise_id,
                    e.name_de, e.name_en, e.focus, e.equipment, e.archived,
                    e.image_path, e.image_crop, e.description
               FROM plan_exercises pe
               JOIN exercises e ON e.id = pe.exercise_id
              WHERE pe.plan_id IN ($platzhalter)
              ORDER BY pe.sort_order, pe.id"
        );
        $stmt->execute($planIds);
        foreach ($stmt->fetchAll() as $z) {
            $positionen[(int)$z['plan_id']][] = $z;
        }

        // Muskelgruppen in EINER Abfrage nachschlagen, nicht je Position --
        // dieselbe Ueberlegung wie in admin_exercises.php. Sortiert wie dort:
        // primaer zuerst, danach die sekundaeren. Die Anzeige zeigt seit 1.0.12
        // alle Gruppen und nicht mehr nur die primaere, damit die Planseite
        // dasselbe Bild einer Uebung zeigt wie Training und Uebungsverwaltung.
        $gruppenZuPosition = [];
        foreach (db()->query(
            'SELECT emg.exercise_id, emg.is_primary, mg.name_de
               FROM exercise_muscle_groups emg
               JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
              ORDER BY emg.is_primary DESC, mg.sort_order, mg.name_de'
        ) as $g) {
            $gruppenZuPosition[(int)$g['exercise_id']][] = $g;
        }
    }

    // Nur aktive Uebungen lassen sich in einen Plan aufnehmen (§6.3). Hier
    // reicht die ANZAHL: Die Uebungen selbst holt die Auswahl gefiltert ueber
    // api/plans.php -> exercise_picker nach. Frueher stand hier die vollstaendige
    // Liste, und sie wurde je Plan erneut in ein Pulldown gerendert.
    $aktiveUebungen = (int)db()->query(
        'SELECT COUNT(*) FROM exercises WHERE archived = 0'
    )->fetchColumn();
}

// Die Muskelgruppen fuer den Filter der Uebungsauswahl -- zweistufig wie in der
// Uebungsverwaltung, damit eine Hauptgruppe ihre Untergruppen mit einschliesst.
$alleGruppen = db()->query(
    'SELECT id, name_de, parent_id FROM muscle_groups ORDER BY sort_order, name_de'
)->fetchAll();

$hauptGruppen = array_values(array_filter(
    $alleGruppen,
    static fn(array $g): bool => $g['parent_id'] === null
));
$unterGruppen = [];
foreach ($alleGruppen as $g) {
    if ($g['parent_id'] !== null) {
        $unterGruppen[(int)$g['parent_id']][] = $g;
    }
}

$gesperrt = $offeneEinheit !== null;

$pageTitle = 'Pläne';
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($waehlbar === []): ?>
    <div class="karte">
        <strong>Noch kein Workout-Split.</strong>
        <p class="matt">
            Pläne stehen immer in einem Split — er bestimmt, welche Pläne
            miteinander abwechseln (§7.6).
        </p>
        <p><a class="knopf" href="<?= h(base_path()) ?>/splits.php">Zu den Splits</a></p>
    </div>
<?php else: ?>

<?php // "Angezeigter Split" benennt, WAS man hier waehlt -- und der gewaehlte
      // Wert liest sich als Aussage ueber die Seite darunter, die sich mit ihm
      // aendert. "Vorhandene Splits" waere die Beschreibung der LISTE und
      // doppelte ausserdem die Gruppenueberschriften im Menue.
      //
      // "Pläne im Split" scheidet aus einem anderen Grund aus: So heisst seit
      // 1.2.18 der Abschnitt weiter unten. Der zeigt das ERGEBNIS der Wahl;
      // beides gleich zu benennen machte aus zwei Abschnitten einen.
      //
      // Die Ueberschrift steht seit 1.2.18 UEBER dem Kasten und nicht darin --
      // dieselbe Gliederung wie auf splits.php. Das <label> bleibt trotzdem
      // stehen, nur unsichtbar: Ein <h2> beschriftet kein Formularfeld, und
      // ohne das for/id-Paar verloere das Auswahlfeld seinen Namen. ?>
<h2>Angezeigter Split</h2>

<form method="get" class="karte">
    <label for="split" class="nur-lesbar">Angezeigter Split</label>
    <select id="split" name="split" onchange="this.form.submit()">
        <?php $letzteGruppe = null; ?>
        <?php foreach ($waehlbar as $sp): ?>
            <?php if ($sp['gruppe'] !== $letzteGruppe): ?>
                <?php if ($letzteGruppe !== null): ?></optgroup><?php endif; ?>
                <optgroup label="<?= h((string)$sp['gruppe']) ?>">
                <?php $letzteGruppe = $sp['gruppe']; ?>
            <?php endif; ?>
            <option value="<?= (int)$sp['id'] ?>" <?= (int)$sp['id'] === $gewaehlt ? 'selected' : '' ?>>
                <?= h($sp['zusatz'] . (string)$sp['name']) ?>
            </option>
        <?php endforeach; ?>
        <?php if ($letzteGruppe !== null): ?></optgroup><?php endif; ?>
    </select>
    <noscript><button type="submit">Anzeigen</button></noscript>
</form>

<?php if ($istVorlage): ?>
    <div class="karte hinweis-vorlage">
        <strong>Das ist eine Vorlage.</strong>
        <p class="matt">
            Sie steht allen Benutzern im Katalog zur Auswahl. Wer sie benutzt,
            bekommt eine <em>Kopie</em> — Änderungen hier wirken deshalb nur auf
            künftige Kopien und nicht auf jemanden, der sie schon gezogen hat.
        </p>
    </div>
<?php endif; ?>

<?php if ($gesperrt): ?>
    <div class="karte hinweis-warnung">
        <strong>Offene Trainingseinheit seit <?= h(format_datetime($offeneEinheit['started_at'])) ?>.</strong>
        <p class="matt">
            Solange lassen sich Übungen weder hinzufügen, entfernen noch umsortieren:
            Die Zahlen am oberen Rand der Trainingsansicht würden sich mitten im
            Training verschieben. Umbenennen und die Reihenfolge der Pläne bleiben
            möglich.
        </p>
    </div>
<?php endif; ?>

<?php // Ohne Plan gibt es keine Rotation -- dann faellt der ganze Abschnitt
      // weg, statt eine Ueberschrift ueber die Auskunft "noch nichts da" zu
      // setzen. Was fehlt, sagt der leere Zustand unter "Pläne", genau einmal
      // und dort, wo man es aendert. ?>
<?php if ($plaene !== []): ?>
<h2>Rotation</h2>

<div class="karte">
    <?php // Die Kette zeigt die REIHENFOLGE und sonst nichts. Bis 1.2.18
          // war der naechste vorgeschlagene Plan darin blau hervorgehoben,
          // mit einem Satz darunter, welches Training als Naechstes kommt.
          // Beides ist weg (gemeldet am 2026-08-26): Hier baut man den Plan
          // um -- wo man in der Rotation gerade steht, beantwortet die
          // Trainingsansicht, und zwar an der Stelle, an der man es
          // braucht. Damit ist die Kette fuer Vorlage und eigenen Split
          // dieselbe; nur die Erklaerung darunter unterscheidet sich. ?>
    <p class="rotation-kette">
        <?php foreach ($plaene as $i => $p): ?>
            <?php if ($i > 0): ?><span class="rotation-pfeil">→</span><?php endif; ?>
            <?php // data-plan-name traegt die Plan-ID: Nach dem Umbenennen
                  // zieht plans.js die Kette hier nach, statt die Seite neu
                  // zu laden. Ohne das stand oben weiter der alte Name --
                  // gemeldet am 2026-08-19. ?>
            <span data-plan-name="<?= (int)$p['id'] ?>"
                  class="rotation-glied"><?= h((string)$p['name']) ?></span>
        <?php endforeach; ?>
        <span class="rotation-pfeil">↺</span>
    </p>
    <?php if ($istVorlage): ?>
        <p class="matt">
            In dieser Reihenfolge wechseln die Pläne bei jedem ab, der die
            Vorlage kopiert. Wo er darin gerade steht, ist seine Sache — auf
            einer Vorlage trainiert niemand.
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // "Pläne im Split" und nicht bloss "Pläne": Die Seite heisst schon so
      // (das <h1> der Kopfzeile), und zwei gleichlautende Ueberschriften
      // untereinander lesen sich wie ein Fehler. Hier steht ausserdem genau
      // das -- die Plaene DIESES Splits, ausgewaehlt im Kasten ganz oben. ?>
<h2>Pläne im Split</h2>

<?php if ($plaene === []): ?>
    <div class="karte">
        <p><strong>Noch kein Plan angelegt.</strong></p>
        <p class="matt">
            Ein Plan ist ein Trainingstag innerhalb des Splits — „Push“ und
            „Pull“ sind zwei Pläne. Die Reihenfolge, in der sie hier stehen,
            ist zugleich die, in der sie sich abwechseln.
        </p>
    </div>
<?php endif; ?>

<?php if ($aktiveUebungen === 0 && $plaene !== []): ?>
    <div class="karte hinweis-warnung">
        <strong>Keine aktive Übung vorhanden.</strong>
        <p><a class="knopf" href="<?= h(base_path()) ?>/admin_exercises.php">Zu den Übungen</a></p>
    </div>
<?php endif; ?>

<ul id="plan-liste" class="liste-schlicht" data-split="<?= (int)$gewaehlt ?>"
    data-gesperrt="<?= $gesperrt ? '1' : '0' ?>">
    <?php foreach ($plaene as $planNr => $p): ?>
        <?php
        $pid   = (int)$p['id'];
        $eintr = $positionen[$pid] ?? [];
        // Am Rand der Liste geht es nicht weiter -- das gilt fuer BEIDE
        // Pfeilsorten: Der Doppelpfeil des ersten Plans hat keinen Plan
        // darueber, der einfache Pfeil des ersten Plans hat nichts, womit er
        // tauschen koennte. Deaktiviert statt weggelassen -- eine Knopfzeile,
        // die je nach Plan anders breit ist, liest sich unruhig.
        $ersterPlan  = $planNr === 0;
        $letzterPlan = $planNr === count($plaene) - 1;
        ?>
        <li class="karte plan" data-id="<?= $pid ?>">
            <div class="gruppe-zeile plan-kopf">
                <div class="gruppe-felder">
                    <input type="text" class="plan-name" value="<?= h((string)$p['name']) ?>"
                           aria-label="Planname">
                </div>
                <div class="gruppe-knoepfe">
                    <button type="button" class="leise plan-hoch" aria-label="Plan nach vorn"
                            <?= $ersterPlan ? 'disabled' : '' ?>>↑</button>
                    <button type="button" class="leise plan-runter" aria-label="Plan nach hinten"
                            <?= $letzterPlan ? 'disabled' : '' ?>>↓</button>
                    <button type="button" class="plan-speichern">Umbenennen</button>
                    <button type="button" class="gefahr plan-loeschen">Löschen</button>
                </div>
            </div>

            <p class="matt"><?= count($eintr) ?> Übung<?= count($eintr) === 1 ? '' : 'en' ?></p>
            <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

            <?php if ($eintr === []): ?>
                <p class="matt">Noch keine Übung in diesem Plan.</p>
            <?php else: ?>
                <ol class="positions-liste">
                    <?php foreach ($eintr as $posNr => $z): ?>
                        <?php // Dieselbe Regel wie bei den Plaenen eine Ebene
                              // hoeher: Die oberste Uebung kann nicht weiter
                              // nach oben, die unterste nicht weiter nach
                              // unten. plans.js zieht das beim Umsortieren
                              // nach -- als einziger Weg hier laedt er die
                              // Seite nicht neu. ?>
                        <?php
                        $ersteUebung  = $posNr === 0;
                        $letzteUebung = $posNr === count($eintr) - 1;
                        ?>
                        <?php // Zweizeilig: oben das Bild und der Name, darunter
                              // die Knoepfe ueber die ganze Breite des <li>. Alles in einer
                              // Zeile liess dem Namen bei vier Knoepfen kaum Platz, und
                              // genau der ist hier die Hauptinformation; die Knoepfe neben
                              // dem Bild wiederum brachen am Handy um.
                              //
                              // Das <li> traegt das Raster: obere Zeile das
                              // .position-raster mit Bild und Text, untere Zeile die
                              // Knoepfe ueber die ganze Breite. Die Nummer ist ein
                              // CSS-Zaehler (.positions-liste im Stylesheet) und nicht
                              // der Listenpunkt des Browsers -- der haengt an einer
                              // Textgrundlinie und stand deshalb am unteren Rand.
                              //
                              // Sie steht seit 1.2.19 als Kaestchen in der oberen linken
                              // ECKE und nicht mehr in einer eigenen Spalte davor; das
                              // Bild hat dadurch rund 26px mehr Breite. Dass der Zaehler
                              // geblieben ist, ist kein Zufall: Beim Umsortieren zaehlt
                              // der Browser neu, ohne dass plans.js eine Zahl anfassen
                              // muesste -- und genau dieser eine Weg laedt die Seite
                              // nicht neu (siehe posPfeileNachziehen()). ?>
                        <li class="position" data-pe="<?= (int)$z['id'] ?>">
                            <div class="position-raster">
                                <?php if (!empty($z['image_path'])): ?>
                                    <?php $thumb = substr((string)$z['image_path'], 0, 32) . '_thumb.jpg'; ?>
                                    <?php // Antippbar wie im Training: dasselbe Bild gross, mit
                                          // Name und Beschreibung (assets/app.js, bildGrossZeigen). ?>
                                    <button type="button" class="bild-knopf"
                                            aria-label="Bild und Beschreibung anzeigen">
                                        <img class="<?= h(trim('position-bild ' . bild_zuschnitt_klasse($z['image_crop'] ?? null))) ?>"
                                             src="<?= h(base_path()) ?>/image.php?f=<?= h($thumb) ?>"
                                             alt="" loading="lazy" width="72" height="72">
                                    </button>
                                <?php else: ?>
                                    <span class="position-bild position-bild-leer" aria-hidden="true">–</span>
                                <?php endif; ?>

                                <?php // Nur fuer den Bilddialog. Als display:none-Element ist es
                                      // kein Rasterelement und verschiebt die Spalten nicht. ?>
                                <?php if (!empty($z['description'])): ?>
                                    <p class="beschreibung" hidden><?= h((string)$z['description']) ?></p>
                                <?php endif; ?>
                                <?php // Gleicher Aufbau wie im Training und in der
                                      // Uebungsverwaltung: Name, englischer Name daneben,
                                      // darunter die Muskelgruppen (primaer vorn), die
                                      // Ausfuehrung in einer eigenen Zeile darunter. Die
                                      // Klasse .uebung-text ist dieselbe -- geteiltes
                                      // Aussehen ueber eine geteilte Regel, nicht ueber
                                      // eine zweite Kopie im Stylesheet. ?>
                                <div class="uebung-text">
                                    <?php // .position-titel bleibt am Namen: Dialogtitel und
                                          // Rueckfrage in plans.js lesen ihn darueber,
                                          // und ohne eigenes Element laesen sie die
                                          // Abzeichen als Teil des Uebungsnamens mit. ?>
                                    <?= uebung_name((string)$z['name_de'], $z['name_en'], 'position-titel') ?>
                                    <?php if ((int)$z['archived'] === 1): ?>
                                        <span class="abzeichen abzeichen-archiv">archiviert</span>
                                    <?php endif; ?>

                                    <?php $gruppen = $gruppenZuPosition[(int)$z['exercise_id']] ?? []; ?>
                                    <?php if ($gruppen !== []): ?>
                                        <p class="gruppen-anzeige">
                                            <?php foreach ($gruppen as $g): ?>
                                                <span class="<?= (int)$g['is_primary'] === 1 ? 'gruppe-primaer' : 'gruppe-sekundaer' ?>">
                                                    <?= h((string)$g['name_de']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </p>
                                    <?php endif; ?>

                                    <p class="schwerpunkt-zeile">
                                        <?= geraet_abzeichen($z['equipment'] ?? null) ?>
                                        <?php if (!empty($z['focus'])): ?>
                                            <span class="schwerpunkt"><?= h((string)$z['focus']) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <?php // Die Knopfzeile haengt am <li>, NICHT am Raster darin:
                                  // Sonst begaenne sie erst hinter der Positionsnummer und
                                  // stuende sichtbar eingerueckt. So faengt "Tauschen" am
                                  // linken Rand der Karte an -- wie die Aktionszeile im
                                  // Training -- und die Zeile ist rund 26px breiter. ?>
                            <span class="position-knoepfe">
                                <button type="button" class="leise pos-tauschen"
                                        <?= $gesperrt ? 'disabled' : '' ?>>Tauschen</button>
                                <?php // Die Pfeile in die Mitte: gleicher Abstand nach
                                      // links zum Tauschen und nach rechts zum Entfernen. ?>
                                <?php // Vier Pfeile, paarweise: aussen der grosse Sprung
                                      // in den Nachbarplan, innen der kleine
                                      // innerhalb des Plans. Der Doppelpfeil steht
                                      // jeweils neben seinem einfachen. ?>
                                <span class="pos-pfeile">
                                    <button type="button" class="leise pos-plan-hoch"
                                            aria-label="In den Plan darüber verschieben"
                                            title="<?= $ersterPlan ? 'Darüber gibt es keinen Plan' : 'In den Plan darüber verschieben' ?>"
                                            <?= $gesperrt || $ersterPlan ? 'disabled' : '' ?>>⇈</button>
                                    <button type="button" class="leise pos-hoch" aria-label="Nach oben"
                                            <?= $gesperrt || $ersteUebung ? 'disabled' : '' ?>>↑</button>
                                    <button type="button" class="leise pos-runter" aria-label="Nach unten"
                                            <?= $gesperrt || $letzteUebung ? 'disabled' : '' ?>>↓</button>
                                    <button type="button" class="leise pos-plan-runter"
                                            aria-label="In den Plan darunter verschieben"
                                            title="<?= $letzterPlan ? 'Darunter gibt es keinen Plan' : 'In den Plan darunter verschieben' ?>"
                                            <?= $gesperrt || $letzterPlan ? 'disabled' : '' ?>>⇊</button>
                                </span>
                                <button type="button" class="gefahr pos-entfernen"
                                        <?= $gesperrt ? 'disabled' : '' ?>>Entfernen</button>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($aktiveUebungen > 0): ?>
                <?php // Ein Knopf statt eines Pulldowns mit allen aktiven Uebungen:
                      // Die Auswahl dahinter laesst sich nach Muskelgruppe und
                      // Trainingsgeraet filtern, was ein <select> nicht kann. Die
                      // Liste kommt erst beim Oeffnen und dann gefiltert -- ein
                      // Pulldown je Plan trug bisher den ganzen Bestand im HTML. ?>
                <p class="zeile-eingabe">
                    <button type="button" class="uebung-waehlen" <?= $gesperrt ? 'disabled' : '' ?>>
                        Übung hinzufügen
                    </button>
                </p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>

<div class="karte">
    <?php // Der Kasten steht UNTER der Liste, wie "Split anlegen" auf
          // splits.php: Erst der Bestand, dann das Anlegen. Bis 1.2.18 sass er
          // im Rotationskasten mit -- die Ueberschrift darueber sagte also
          // "Rotation" und meinte auch das Formular. ?>
    <form id="plan-neu" class="zeile-eingabe" novalidate>
        <input type="hidden" name="split_id" value="<?= (int)$gewaehlt ?>">
        <label for="plan_name" class="nur-lesbar">Name des neuen Plans</label>
        <input type="text" id="plan_name" name="name" placeholder="z. B. Push" required>
        <button type="submit">Plan hinzufügen</button>
    </form>
    <p id="plan-neu-fehler" class="feld-fehler" role="alert" hidden></p>
</div>

<?php endif; ?>

<?php // Derselbe Dialog wie im Training, nur ohne "nur diese Einheit": Ohne
      // laufende Einheit gibt es nichts, worauf ein befristeter Tausch sich
      // beziehen koennte. Die Vorschlaege kommen aus derselben Quelle. ?>
<dialog id="tausch-dialog">
    <h2 id="tausch-titel">Übung tauschen</h2>
    <p class="matt">
        Vorgeschlagen werden Übungen derselben <strong>primären</strong> Hauptgruppe.
        Ganz oben stehen die mit genau derselben Untergruppe.
    </p>
    <p class="matt">
        Der Tausch gilt <strong>dauerhaft</strong> für diesen Plan. Bereits
        protokollierte Einheiten bleiben unverändert.
    </p>
    <?php // Wie im Training: Der Gerätefilter arbeitet auf der bereits geladenen
          // Vorschlagsliste, ohne zweiten Abruf. Die Auswahl füllt plans.js
          // aus den tatsächlich vorhandenen Vorschlägen — deshalb steht hier nur
          // die erste Option. ?>
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

<?php // Die Uebungsauswahl (§6.4). Inline und kein Partial wie der Bilddialog:
      // Sie wird nur hier gebraucht, und ein Partial fuer eine Seite ist ein
      // Umweg ohne Nutzen. Die Treffer rendert vorschlagMarkup() aus
      // assets/app.js -- dieselbe Darstellung wie beim Tausch. ?>
<dialog id="waehlen-dialog">
    <h2 id="waehlen-titel">Übung hinzufügen</h2>

    <?php // Beide Filter wirken gemeinsam, deshalb stehen sie nebeneinander:
          // "Kurzhantel" UND "Bizeps" ist der eigentliche Zweck der Maske. ?>
    <div class="waehlen-filter">
        <span>
            <label for="waehlen-gruppe" class="nur-lesbar">Muskelgruppe</label>
            <select id="waehlen-gruppe">
                <option value="">alle Muskelgruppen</option>
                <?php foreach ($hauptGruppen as $hg): ?>
                    <option value="<?= (int)$hg['id'] ?>"><?= h((string)$hg['name_de']) ?></option>
                    <?php // Kein <optgroup>: Dessen Beschriftung waere nicht waehlbar,
                          // die Hauptgruppe muss aber als Filter taugen. Deshalb das
                          // vorangestellte "–" als Einrueckung -- wie in der
                          // Uebungsverwaltung. ?>
                    <?php foreach ($unterGruppen[(int)$hg['id']] ?? [] as $ug): ?>
                        <option value="<?= (int)$ug['id'] ?>">– <?= h((string)$ug['name_de']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
        </span>
        <span>
            <label for="waehlen-geraet" class="nur-lesbar">Trainingsgerät</label>
            <select id="waehlen-geraet">
                <option value="">alle Trainingsgeräte</option>
                <?php foreach (GERAETE as $code => $label): ?>
                    <option value="<?= h($code) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
                <?php // Kein "ohne Gerät" — dieselbe Begründung wie in der
                      // Übungsverwaltung: Das Feld ist Pflicht, der Eintrag träfe
                      // nichts mehr. ?>
            </select>
        </span>
    </div>

    <div id="waehlen-liste"></div>
    <p id="waehlen-fehler" class="feld-fehler" role="alert" hidden></p>
    <p><button type="button" id="waehlen-schliessen" class="leise">Schließen</button></p>
</dialog>

<?php require __DIR__ . '/lib/view_bild_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
