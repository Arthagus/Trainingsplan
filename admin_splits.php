<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';
require_once __DIR__ . '/lib/splits.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Vorlagen -- der Split-Katalog (§6.4). Seit 1.2.23.
 *
 * Bis 1.2.22 stand das alles auf splits.php, verteilt ueber zwei Abschnitte
 * und ein halbes Dutzend is_admin()-Abfragen mitten in der Splitkarte. Hier
 * traegt die Seite die Rechtefrage: require_admin() am Kopf, und darunter
 * keine einzige Fallunterscheidung mehr.
 *
 * Zwei Wege zu einer Vorlage, und beide werden gebraucht:
 *
 *   VORLAGE ANLEGEN            Ein leerer Split im Katalog, den man
 *                              anschliessend ueber plans.php fuellt. Der Weg
 *                              fuer etwas, das es noch nirgends gibt.
 *   AUS EINEM BENUTZER-SPLIT   Ein fertiger, im Studio erprobter Split wird
 *                              kopiert. Der haeufigere Weg -- man baut einen
 *                              Split am eigenen Konto, trainiert ihn, und
 *                              veroeffentlicht ihn dann.
 *
 * VEROEFFENTLICHEN IST EINE KOPIE, kein Verschieben: Der Benutzer behaelt
 * seinen Stand unveraendert und wird von spaeteren Aenderungen an der Vorlage
 * nicht beruehrt. Es gibt keine Vererbung in die eine und keinen Rueckkanal in
 * die andere Richtung -- der einzige Weg zurueck ist "Auf Vorlage
 * zurücksetzen" an der Karte des Benutzers, und den geht er selbst.
 *
 * DIE DARSTELLUNG IST DIE VON splits.php (seit 1.3.2): eine Karte offen, im
 * Kopf ein Auswahlfeld ueber den ganzen Katalog, Umbenennen im Dialog. Beide
 * Seiten binden denselben Baustein ein -- was hier anders aussaehe, waere ein
 * Unterschied ohne Grund.
 *
 * Auf einer Vorlage trainiert niemand, auch kein Admin (Fallstrick 24). Wer
 * eine benutzen will, holt sie sich auf splits.php zu sich -- deshalb steht
 * hier kein "Zu mir kopieren".
 *
 * DER DRITTE ABSCHNITT hat mit Vorlagen nichts zu tun und steht trotzdem hier:
 * das Bearbeiten fremder Splits (§6.4). Es ist der einzige Weg dorthin, seit
 * das Auswahlfeld in plans.php nur noch die eigenen Splits fuehrt -- und es
 * ist Adminarbeit, also gehoert es in den Adminbereich. Bewusst als eigener
 * Abschnitt neben "Aus einem Benutzer-Split" und nicht mit ihm verschmolzen:
 * Die beiden Listen sind verschieden lang, weil sie verschiedene Fragen
 * stellen (was laesst sich veroeffentlichen -- was gibt es), und ein Pulldown,
 * das mal das eine und mal das andere meint, ist keins.
 */

$dieVorlagen = vorlagen();

// Die Splits ALLER Benutzer, aus denen sich noch eine Vorlage machen laesst
// (§6.4) -- bewusst inklusive der eigenen: Veroeffentlichen liegt damit an
// genau einer Stelle statt zusaetzlich als Knopf an jeder eigenen Karte.
$kandidaten = benutzer_splits_ohne_vorlage();

// Die Splits der ANDEREN, ungefiltert -- der eine Fall, den §6.4 einem Admin
// zusaetzlich erlaubt (Nachfolger des frueheren Benutzer-Dropdowns in
// plans.php). Die eigenen fehlen bewusst: Die stehen auf splits.php, und ein
// Weg dorthin gehoert nicht in den Adminbereich.
$fremde = fremde_splits((int)current_user()['id']);

// Die Plan-Namen als Vorschau. Ohne sie ist ein Splitname eine leere
// Behauptung -- man waehlt einen Split danach aus, was drinsteht.
$planNamen = split_plan_namen(array_merge(
    array_column($dieVorlagen, 'id'),
    array_column($kandidaten, 'id')
));

// Der Text zum Kopieren (§6.4), serverseitig und fertig in der Seite -- die
// Begruendung steht bei splitTextZeigen() in assets/app.js. Die
// Kandidatenliste bleibt aussen vor: Das sind die Splits ANDERER Leute, dort
// steht kein Knopf.
$splitTexte = split_texte(array_column($dieVorlagen, 'id'));

// Welche Karte offen steht (seit 1.3.2). Anders als auf splits.php gibt es
// hier keinen "aktiven" Katalogeintrag -- ohne Parameter steht deshalb die
// erste offen, und split_liste() erledigt genau das bei $zeigeId = 0.
$zeigeId = to_int_or_null($_GET['split'] ?? null) ?? 0;

$pageTitle = 'Vorlagen';
require __DIR__ . '/lib/view_header.php';
require __DIR__ . '/lib/view_split_karte.php';
?>

<p class="matt">
    Der Katalog, aus dem sich jeder Benutzer auf <em>Splits</em> bedient. Eine
    Vorlage wird beim Übernehmen <strong>kopiert</strong> — was danach in der
    Kopie geschieht, wirkt nicht zurück, und eine Änderung hier wirkt nicht auf
    bestehende Kopien. Trainiert wird auf einer Vorlage nie.
</p>

<h2>Vorlagen</h2>

<?php if ($dieVorlagen === []): ?>
    <div class="karte">
        <p><strong>Noch keine Vorlage im Katalog.</strong></p>
        <p class="matt">
            Am einfachsten entsteht eine aus einem fertigen Split — unter
            <em>Aus einem Benutzer-Split</em>. Ein leerer Katalogeintrag zum
            selbst Füllen geht auch.
        </p>
    </div>
<?php else: ?>
    <?php split_liste($dieVorlagen, $planNamen, $splitTexte, false, 0, $zeigeId, false); ?>
<?php endif; ?>

<div class="karte">
    <?php // Kein Schalter "als Vorlage" wie bis 1.2.22 auf splits.php: Auf
          // einer reinen Vorlagenseite sagt ein Haekchen nur, was das
          // Formular ohnehin tut. ?>
    <form id="vorlage-neu" class="zeile-eingabe" novalidate>
        <label for="vorlage_name" class="nur-lesbar">Name der neuen Vorlage</label>
        <input type="text" id="vorlage_name" name="name" placeholder="z. B. Push / Pull" required>
        <button type="submit">Vorlage anlegen</button>
    </form>
    <p class="matt">
        Legt einen <strong>leeren</strong> Katalogeintrag an — die Pläne kommen
        anschließend über <em>Vorlage bearbeiten</em> hinein.
    </p>
    <p id="vorlage-neu-fehler" class="feld-fehler" role="alert" hidden></p>
</div>

<h2>Aus einem Benutzer-Split</h2>

<?php if ($kandidaten === []): ?>
    <div class="karte">
        <p class="matt">
            Kein Split, aus dem sich noch etwas machen ließe — jeder
            vorhandene entspricht inhaltlich bereits einer Vorlage.
        </p>
    </div>
<?php else: ?>
    <div class="karte" id="kandidaten">
        <p class="matt">
            Hier stehen die Splits <strong>aller</strong> Benutzer, die noch
            keiner Vorlage entsprechen — auch deine eigenen. Verglichen wird
            allein der Inhalt: Reihenfolge der Pläne und darin die der
            Übungen. Wer eine Vorlage bloß umbenannt hat, taucht deshalb
            nicht auf; wer eine Übung getauscht hat, schon.
        </p>

        <p class="matt">
            Bearbeiten oder löschen lässt sich hier nichts — das sind die
            persönlichen Splits anderer Leute.
        </p>

        <label for="kandidat">Split</label>
        <select id="kandidat">
            <?php foreach ($kandidaten as $sp): ?>
                <?php $pl = $planNamen[(int)$sp['id']] ?? []; ?>
                <option value="<?= (int)$sp['id'] ?>"
                        data-name="<?= h((string)$sp['name']) ?>"
                        data-plaene="<?= h(implode(' → ', $pl)) ?>">
                    <?= h((string)$sp['besitzer'] . ': ' . (string)$sp['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <p class="matt" id="kandidat-plaene"></p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

        <p>
            <button type="button" id="kandidat-veroeffentlichen">
                Als Vorlage übernehmen
            </button>
        </p>
    </div>
<?php endif; ?>

<h2>Splits anderer Benutzer</h2>

<?php if ($fremde === []): ?>
    <div class="karte">
        <p class="matt">
            Außer dir hat noch niemand einen Split.
        </p>
    </div>
<?php else: ?>
    <div class="karte" id="fremde">
        <p class="matt">
            Zum Aushelfen: die persönlichen Splits der anderen, vollständig —
            auch die ohne Plan. Was hier geändert wird, ändert
            <strong>ihr</strong> Training; umbenennen und löschen geht bewusst
            nur an ihrer eigenen Seite.
        </p>

        <label for="fremd">Split</label>
        <select id="fremd">
            <?php $letzter = null; ?>
            <?php foreach ($fremde as $sp): ?>
                <?php if ((string)$sp['besitzer'] !== $letzter): ?>
                    <?php if ($letzter !== null): ?></optgroup><?php endif; ?>
                    <optgroup label="<?= h((string)$sp['besitzer']) ?>">
                    <?php $letzter = (string)$sp['besitzer']; ?>
                <?php endif; ?>
                <option value="<?= (int)$sp['id'] ?>"><?= h((string)$sp['name']) ?></option>
            <?php endforeach; ?>
            <?php if ($letzter !== null): ?></optgroup><?php endif; ?>
        </select>

        <p>
            <?php // Ein Knopf und kein Link, weil das Ziel erst aus der Auswahl
                  // entsteht. Ohne JavaScript bleibt er wirkungslos -- deshalb
                  // steht daneben nichts, was ohne ihn verlorenginge. ?>
            <button type="button" id="fremd-bearbeiten">Pläne bearbeiten</button>
        </p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/lib/view_split_name_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_split_text_dialog.php'; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
