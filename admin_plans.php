<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Planverwaltung (§6.4).
 *
 * Je Benutzer beliebig viele Plaene. Die Reihenfolge der Plaene ist die
 * Rotationsreihenfolge aus §7.6 -- deshalb steht sie hier nicht nur zum
 * Sortieren bereit, sondern wird als Vorschau ausgegeben.
 */

$benutzer = db()->query(
    'SELECT id, name, is_admin, last_plan_id FROM users ORDER BY name'
)->fetchAll();

$gewaehlt = to_int_or_null($_GET['user'] ?? null);

$aktuellerBenutzer = null;
foreach ($benutzer as $b) {
    if ((int)$b['id'] === $gewaehlt) {
        $aktuellerBenutzer = $b;
        break;
    }
}

// Fehlender oder unbekannter Parameter (etwa ein Lesezeichen auf einen
// geloeschten Benutzer): auf den ersten zurueckfallen, statt eine leere
// Auswahl mit nicht funktionierendem Formular zu zeigen.
if ($aktuellerBenutzer === null && $benutzer !== []) {
    $aktuellerBenutzer = $benutzer[0];
    $gewaehlt = (int)$aktuellerBenutzer['id'];
}

$plaene            = [];
$positionen        = [];
$gruppenZuPosition = [];
$offeneEinheit     = null;
$vorschlag         = null;
$freieUebungen     = [];

if ($aktuellerBenutzer !== null) {
    $plaene        = plaene_von($gewaehlt);
    $offeneEinheit = offene_einheit($gewaehlt);
    $vorschlag     = naechster_plan(
        $gewaehlt,
        to_int_or_null($aktuellerBenutzer['last_plan_id']),
        $plaene
    );

    if ($plaene !== []) {
        $planIds = array_map(static fn(array $p): int => (int)$p['id'], $plaene);
        $platzhalter = implode(',', array_fill(0, count($planIds), '?'));

        $stmt = db()->prepare(
            "SELECT pe.id, pe.plan_id, pe.sort_order, e.id AS exercise_id,
                    e.name_de, e.name_en, e.focus, e.archived, e.image_path, e.description
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

    // Nur aktive Uebungen lassen sich in einen Plan aufnehmen (§6.3).
    $freieUebungen = db()->query(
        'SELECT e.id, e.name_de,
                (SELECT mg.name_de
                   FROM exercise_muscle_groups emg
                   JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
                  WHERE emg.exercise_id = e.id AND emg.is_primary = 1) AS primaergruppe
           FROM exercises e
          WHERE e.archived = 0
          ORDER BY e.name_de'
    )->fetchAll();
}

$gesperrt = $offeneEinheit !== null;

$pageTitle = 'Pläne';
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($benutzer === []): ?>
    <div class="karte hinweis-warnung">
        <strong>Noch kein Benutzer angelegt.</strong>
        <p><a class="knopf" href="<?= h(base_path()) ?>/admin_users.php">Zur Benutzerverwaltung</a></p>
    </div>
<?php else: ?>

<form method="get" class="karte">
    <label for="user">Pläne von</label>
    <select id="user" name="user" onchange="this.form.submit()">
        <?php foreach ($benutzer as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $gewaehlt ? 'selected' : '' ?>>
                <?= h((string)$b['name']) ?><?= (int)$b['is_admin'] === 1 ? ' (Admin)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Anzeigen</button></noscript>
</form>

<?php if ($gesperrt): ?>
    <div class="karte hinweis-warnung">
        <strong>Offene Trainingseinheit seit <?= h(format_datetime($offeneEinheit['started_at'])) ?>.</strong>
        <p class="matt">
            Solange lassen sich Übungen weder hinzufügen, entfernen noch umsortieren:
            Die Fortschrittsanzeige „x/n“ am Handy würde sich mitten im Training
            verschieben. Umbenennen und die Reihenfolge der Pläne bleiben möglich.
        </p>
    </div>
<?php endif; ?>

<div class="karte">
    <h2>Rotation</h2>
    <?php if ($plaene === []): ?>
        <p class="matt">Noch kein Plan angelegt.</p>
    <?php else: ?>
        <p class="rotation-kette">
            <?php foreach ($plaene as $i => $p): ?>
                <?php if ($i > 0): ?><span class="rotation-pfeil">→</span><?php endif; ?>
                <span class="<?= $vorschlag !== null && (int)$p['id'] === (int)$vorschlag['id'] ? 'rotation-naechster' : 'rotation-glied' ?>">
                    <?= h((string)$p['name']) ?>
                </span>
            <?php endforeach; ?>
            <span class="rotation-pfeil">↺</span>
        </p>
        <p class="matt">
            <?php if ($vorschlag !== null): ?>
                Als Nächstes wird <strong><?= h((string)$vorschlag['name']) ?></strong> vorgeschlagen.
            <?php endif; ?>
            <?php if (empty($aktuellerBenutzer['last_plan_id'])): ?>
                (Noch keine Einheit abgeschlossen — die Rotation beginnt vorne.)
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form id="plan-neu" class="zeile-eingabe" novalidate>
        <input type="hidden" name="user_id" value="<?= (int)$gewaehlt ?>">
        <label for="plan_name" class="nur-lesbar">Name des neuen Plans</label>
        <input type="text" id="plan_name" name="name" placeholder="z. B. Push" required>
        <button type="submit">Plan hinzufügen</button>
    </form>
    <p id="plan-neu-fehler" class="feld-fehler" role="alert" hidden></p>
</div>

<?php if ($freieUebungen === [] && $plaene !== []): ?>
    <div class="karte hinweis-warnung">
        <strong>Keine aktive Übung vorhanden.</strong>
        <p><a class="knopf" href="<?= h(base_path()) ?>/admin_exercises.php">Zu den Übungen</a></p>
    </div>
<?php endif; ?>

<ul id="plan-liste" class="liste-schlicht" data-user="<?= (int)$gewaehlt ?>"
    data-gesperrt="<?= $gesperrt ? '1' : '0' ?>">
    <?php foreach ($plaene as $p): ?>
        <?php
        $pid   = (int)$p['id'];
        $eintr = $positionen[$pid] ?? [];
        ?>
        <li class="karte plan" data-id="<?= $pid ?>">
            <div class="gruppe-zeile plan-kopf">
                <div class="gruppe-felder">
                    <input type="text" class="plan-name" value="<?= h((string)$p['name']) ?>"
                           aria-label="Planname">
                </div>
                <div class="gruppe-knoepfe">
                    <button type="button" class="leise plan-hoch" aria-label="Plan nach vorn">↑</button>
                    <button type="button" class="leise plan-runter" aria-label="Plan nach hinten">↓</button>
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
                    <?php foreach ($eintr as $z): ?>
                        <?php // Zweizeilig: rechts oben der Name, darunter die Knoepfe,
                              // links das Bild ueber beide Zeilen. Nebeneinander blieb
                              // fuer den Namen bei vier Knoepfen kaum Platz, und genau
                              // der ist hier die Hauptinformation.
                              //
                              // Das <li> traegt sein eigenes Raster: links die Nummer,
                              // rechts dieses zweizeilige. Die Nummer ist ein
                              // CSS-Zaehler (.positions-liste im Stylesheet) und nicht
                              // der Listenpunkt des Browsers -- der haengt an einer
                              // Textgrundlinie und stand deshalb am unteren Rand. ?>
                        <li class="position" data-pe="<?= (int)$z['id'] ?>">
                            <div class="position-raster">
                                <?php if (!empty($z['image_path'])): ?>
                                    <?php $thumb = substr((string)$z['image_path'], 0, 32) . '_thumb.jpg'; ?>
                                    <?php // Antippbar wie im Training: dasselbe Bild gross, mit
                                          // Name und Beschreibung (assets/app.js, bildGrossZeigen). ?>
                                    <button type="button" class="bild-knopf"
                                            aria-label="Bild und Beschreibung anzeigen">
                                        <img class="position-bild"
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
                                          // Rueckfrage in admin_plans.js lesen ihn darueber,
                                          // und ohne eigenes Element laesen sie die
                                          // Abzeichen als Teil des Uebungsnamens mit. ?>
                                    <strong class="position-titel"><?= h((string)$z['name_de']) ?></strong>
                                    <?php if (!empty($z['name_en'])): ?>
                                        <span class="matt"><?= h((string)$z['name_en']) ?></span>
                                    <?php endif; ?>
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

                                    <?php if (!empty($z['focus'])): ?>
                                        <p class="schwerpunkt-zeile">
                                            <span class="schwerpunkt"><?= h((string)$z['focus']) ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="position-knoepfe">
                                    <button type="button" class="leise pos-tauschen"
                                            <?= $gesperrt ? 'disabled' : '' ?>>Tauschen</button>
                                    <?php // Die Pfeile in die Mitte: gleicher Abstand nach
                                          // links zum Tauschen und nach rechts zum Entfernen. ?>
                                    <span class="pos-pfeile">
                                        <button type="button" class="leise pos-hoch" aria-label="Nach oben"
                                                <?= $gesperrt ? 'disabled' : '' ?>>↑</button>
                                        <button type="button" class="leise pos-runter" aria-label="Nach unten"
                                                <?= $gesperrt ? 'disabled' : '' ?>>↓</button>
                                    </span>
                                    <button type="button" class="gefahr pos-entfernen"
                                            <?= $gesperrt ? 'disabled' : '' ?>>Entfernen</button>
                                </span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <?php if ($freieUebungen !== []): ?>
                <div class="zeile-eingabe">
                    <label for="add<?= $pid ?>" class="nur-lesbar">Übung hinzufügen</label>
                    <select id="add<?= $pid ?>" class="uebung-wahl" <?= $gesperrt ? 'disabled' : '' ?>>
                        <option value="">Übung wählen …</option>
                        <?php foreach ($freieUebungen as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= h((string)$u['name_de']) ?><?php
                                    if (!empty($u['primaergruppe'])) {
                                        echo ' — ' . h((string)$u['primaergruppe']);
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="uebung-hinzu" <?= $gesperrt ? 'disabled' : '' ?>>
                        Hinzufügen
                    </button>
                </div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>

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
    <div id="tausch-liste"></div>
    <p id="tausch-fehler" class="feld-fehler" role="alert" hidden></p>
    <p><button type="button" id="tausch-schliessen" class="leise">Abbrechen</button></p>
</dialog>

<?php require __DIR__ . '/lib/view_bild_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
