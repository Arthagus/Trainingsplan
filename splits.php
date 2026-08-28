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
 * Workout-Splits (§6.4, §7.6) -- der eigene Bestand.
 *
 * SEIT 1.2.23 STEHT HIER NUR NOCH, WAS DEM AUFRUFER GEHOERT, und zwar fuer
 * jeden gleich: Ein Admin sieht dieselbe Seite wie jeder andere. Der Katalog
 * und alles, was man an ihm tut -- Vorlagen anlegen, umbenennen, bearbeiten,
 * duplizieren, loeschen, aus einem Benutzer-Split veroeffentlichen --, liegt
 * auf admin_splits.php.
 *
 * Der Grund ist nicht Ordnungsliebe: Bis 1.2.22 war die halbe Seite fuer einen
 * normalen Benutzer ein Katalog mit genau einem erlaubten Knopf darin, und fuer
 * einen Admin war dieselbe Seite gleichzeitig Selbstbedienung und Verwaltung.
 * Eine Seite, auf der zwei verschiedene Rollen zwei verschiedene Dinge tun,
 * beantwortet keine der beiden Fragen gut.
 *
 * SEIT 1.3.2 STEHT DAVON IMMER NUR EINE KARTE OFFEN -- die des aktiven Splits
 * bzw. die aus ?split= --, und im Kartenkopf sitzt statt des Namensfelds ein
 * Auswahlfeld ueber alle eigenen Splits. Bei einem halben Dutzend war die
 * Seite vorher eine lange Rolle, von der fuenf Sechstel nur im Weg standen.
 * Umbenannt wird seither im Dialog hinter "Umbenennen".
 *
 * Was bleibt: Nur hier wird trainiert, nur hier wirkt ein dauerhafter Tausch,
 * nur hier laeuft eine Rotation. Wer eine Vorlage benutzen will, zieht ueber
 * den Kasten "Vorlage übernehmen" eine Kopie und besitzt sie danach
 * vollstaendig. Es gibt bewusst KEINEN Weg, direkt auf einer Vorlage zu
 * trainieren, und keine Vererbung: Aendert der Admin die Vorlage, bleibt jede
 * bestehende Kopie unberuehrt.
 *
 * Seit 1.2.11 gibt es dazu genau EINEN Weg zurueck, und der Benutzer geht ihn
 * selbst: Weicht seine Kopie von der Vorlage ab -- weil er sie angepasst hat
 * ODER weil der Admin die Vorlage verbessert hat --, erscheint an seiner Karte
 * "Auf Vorlage zurücksetzen". Nichts daran passiert automatisch; ohne den
 * Knopf bleibt alles, wie es ist.
 */

$benutzer = current_user();
$userId   = (int)$benutzer['id'];

$meine       = splits_von($userId);
$dieVorlagen = vorlagen();
$aktiv       = aktiver_split($userId);
$aktivId     = $aktiv === null ? 0 : (int)$aktiv['id'];
$offen       = offene_einheit($userId);

// Die Plan-Namen als Vorschau. Ohne sie ist ein Splitname eine leere
// Behauptung -- man waehlt einen Split danach aus, was drinsteht.
//
// Die Vorlagen sind dabei, obwohl ihre Karten nicht mehr hier stehen: Der
// Kasten unten zeigt die Vorschau der gewaehlten Vorlage, und das Auswahlfeld
// der Herkunft traegt sie fuer die Rueckfrage vor dem Zuruecksetzen.
$planNamen = split_plan_namen(array_merge(
    array_column($meine, 'id'),
    array_column($dieVorlagen, 'id')
));

// Der Text zum Kopieren (§6.4). Er entsteht SERVERSEITIG und steht fertig in
// der Seite, statt ihn beim Antippen nachzuladen: Das Schreiben in die
// Zwischenablage muss in derselben Benutzeraktion passieren wie der Klick --
// nach einem await auf einen Netzaufruf verweigern strengere Browser (iOS
// Safari) den Zugriff. Nebenbei funktioniert der Knopf damit auch offline.
$splitTexte = split_texte(array_merge(
    array_column($meine, 'id'),
    array_column($dieVorlagen, 'id')
));

// Herkunft und Abweichung der eigenen Splits (§6.4).
$vorlageStand = vorlage_stand(array_column($meine, 'id'));

// Welche Karte offen steht (seit 1.3.2): ohne Parameter der AKTIVE Split --
// der, mit dem gerade trainiert wird, und damit der, den man im Sinn hat.
// ?split= schlaegt das und kommt vom Auswahlfeld im Kartenkopf, das die
// Adresse bei jedem Wechsel mitschreibt; sonst stuende man nach jeder Aktion
// (die Seite laedt neu) wieder beim aktiven statt bei dem, den man bearbeitet.
//
// KEIN IDOR-Thema: $meine enthaelt ausschliesslich eigene Splits, und
// split_liste() faellt bei einem unbekannten Wert auf die erste Karte zurueck.
$zeigeId = to_int_or_null($_GET['split'] ?? null) ?? $aktivId;

$pageTitle = 'Splits';
require __DIR__ . '/lib/view_header.php';
require __DIR__ . '/lib/view_split_karte.php';
?>

<?php if ($offen !== null): ?>
    <div class="karte hinweis-warnung">
        <strong>Es läuft gerade ein Training.</strong>
        <p class="matt">
            Der Split lässt sich erst nach dem Beenden wechseln — die laufende
            Einheit hängt an einem Plan des aktuellen Splits.
        </p>
    </div>
<?php endif; ?>

<h2>Meine Splits</h2>

<?php if ($meine === []): ?>
    <div class="karte">
        <p><strong>Noch kein eigener Split.</strong></p>
        <p class="matt">
            Ein Split bündelt die Pläne, die miteinander abwechseln — „Push /
            Pull“ sind zwei Pläne in einem Split, „Ganzkörper A/B“ ebenso.
            <?php if ($dieVorlagen === []): ?>
                Leg dir unten einen an.
            <?php else: ?>
                Übernimm unten eine Vorlage oder leg dir selbst einen an.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <?php split_liste($meine, $planNamen, $splitTexte, true, $aktivId, $zeigeId,
                      $offen !== null, $dieVorlagen, $vorlageStand); ?>
<?php endif; ?>

<?php // --- Vorlage uebernehmen (§6.4) --------------------------------------
      //
      // Ein Kasten mit Auswahlfeld statt der Kartenliste, die bis 1.2.22 unter
      // "Vorlagen" stand: Hier wird nichts verwaltet, hier wird genau eine
      // Handlung ausgeloest -- und die Karten boten einem normalen Benutzer
      // ohnehin nur diesen einen Knopf.
      //
      // Baugleich zum Kasten "Aus einem Benutzer-Split" auf admin_splits.php:
      // Auswahlfeld, Vorschau aus data-plaene, .zeilen-fehler, ein Knopf.
      // Dieselbe Frage, dieselbe Bedienung.
      //
      // "Als Text" steht daneben, weil man einen Split auch besprechen koennen
      // soll, BEVOR man ihn zu sich kopiert (§6.4). ?>
<?php if ($dieVorlagen !== []): ?>
    <div class="karte" id="vorlage-uebernehmen">
        <p class="matt">
            Eine Vorlage wird beim Übernehmen <strong>zu dir kopiert</strong>.
            Danach gehört die Kopie dir: Tauschen, entfernen, ergänzen — nichts
            davon wirkt auf andere, und eine spätere Änderung an der Vorlage
            wirkt nicht auf deine Kopie.
        </p>

        <label for="vorlage-wahl">Vorlage</label>
        <select id="vorlage-wahl">
            <?php foreach ($dieVorlagen as $v): ?>
                <?php $pl = $planNamen[(int)$v['id']] ?? []; ?>
                <option value="<?= (int)$v['id'] ?>"
                        data-name="<?= h((string)$v['name']) ?>"
                        data-plaene="<?= h(implode(' → ', $pl)) ?>">
                    <?= h((string)$v['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <p class="matt" id="vorlage-plaene"></p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

        <p class="gruppe-knoepfe">
            <button type="button" id="vorlage-kopieren">Zu mir kopieren</button>
            <button type="button" class="leise" id="vorlage-text">Als Text</button>
        </p>

        <?php // Der Text jeder Vorlage liegt fertig im Kasten, unsichtbar --
              // aus demselben Grund wie an der Karte, siehe oben bei
              // $splitTexte. Ausgewaehlt wird ueber data-id. ?>
        <?php foreach ($dieVorlagen as $v): ?>
            <pre class="split-text-inhalt" data-id="<?= (int)$v['id'] ?>"
                 hidden><?= h($splitTexte[(int)$v['id']] ?? '') ?></pre>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="karte">
    <form id="split-neu" class="zeile-eingabe" novalidate>
        <label for="split_name" class="nur-lesbar">Name des neuen Splits</label>
        <input type="text" id="split_name" name="name" placeholder="z. B. Push / Pull" required>
        <button type="submit">Split anlegen</button>
    </form>
    <p id="split-neu-fehler" class="feld-fehler" role="alert" hidden></p>
</div>

<?php // --- Rueckfrage vor dem Zuruecksetzen (§6.4) -------------------------
      //
      // Ein Dialog und kein window.confirm, seit die Frage zweiteilig ist
      // (1.2.23): "Zuruecksetzen -- ja oder nein?" und "auch die Plannamen?".
      // Ein confirm kann genau eine Frage stellen; zwei hintereinander waeren
      // zwei Klicks fuer eine Entscheidung, und der zweite kaeme, wenn man den
      // ersten schon fuer erledigt haelt.
      //
      // Der Text entsteht beim Oeffnen aus der Karte -- ein Dialog fuer alle,
      // wie beim Text-Dialog daneben. ?>
<dialog id="reset-dialog">
    <h2 id="reset-titel">Auf Vorlage zurücksetzen</h2>
    <p id="reset-danach" class="matt"></p>
    <p>
        Eigene Änderungen an Plänen und Übungen dieses Splits gehen dabei
        verloren. Bereits protokollierte Einheiten bleiben im Verlauf stehen;
        bei Übungen, die aus der Vorlage verschwunden sind, fehlt danach die
        Zuordnung zur Planposition.
    </p>

    <?php // Nur wenn es wirklich etwas anzugleichen gibt -- ein Kaestchen ohne
          // Wirkung ist dasselbe wie ein wirkungsloser Knopf. Unangekreuzt
          // vorbelegt: Die eigene Beschriftung ist das, was der Benutzer
          // selbst gewaehlt hat, und der Knopf heisst nicht "alles angleichen".
          ?>
    <p id="reset-namen-zeile" hidden>
        <label>
            <input type="checkbox" id="reset-namen">
            Auch die <strong>Namen der Pläne</strong> auf die Vorlage zurücksetzen
        </label>
    </p>

    <p class="gruppe-knoepfe">
        <button type="button" id="reset-los" class="gefahr">Zurücksetzen</button>
        <button type="button" id="reset-abbrechen" class="leise">Abbrechen</button>
    </p>
</dialog>

<?php require __DIR__ . '/lib/view_split_name_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_split_text_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
