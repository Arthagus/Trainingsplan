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
 * Workout-Splits (§6.4, §7.6).
 *
 * Zwei Listen, und der Unterschied zwischen ihnen ist die ganze Fachlichkeit:
 *
 *   MEINE SPLITS   Was dem Benutzer gehoert. Nur hier wird trainiert, nur hier
 *                  wirkt ein dauerhafter Tausch, nur hier laeuft eine Rotation.
 *   VORLAGEN       Der Katalog. Ansehen und kopieren -- mehr nicht. Wer eine
 *                  Vorlage benutzen will, zieht eine Kopie und besitzt sie
 *                  danach vollstaendig.
 *
 * Es gibt bewusst KEINEN Weg, direkt auf einer Vorlage zu trainieren, und
 * keinen Abgleich zwischen Vorlage und Kopie. Aendert der Admin die Vorlage,
 * bleibt jede bestehende Kopie, wie sie ist; wer den neuen Stand will, kopiert
 * erneut und unterscheidet die beiden am Namen.
 */

$benutzer = current_user();
$userId   = (int)$benutzer['id'];
$istAdmin = (int)$benutzer['is_admin'] === 1;

$meine       = splits_von($userId);
$dieVorlagen = vorlagen();
$aktiv       = aktiver_split($userId);
$aktivId     = $aktiv === null ? 0 : (int)$aktiv['id'];
$offen       = offene_einheit($userId);

// Die Splits ALLER Benutzer, aus denen sich noch eine Vorlage machen laesst
// (§6.4). Nur fuer Admins, und bewusst inklusive der eigenen: Veroeffentlichen
// liegt damit an genau einer Stelle statt zusaetzlich als Knopf an jeder
// eigenen Karte.
$kandidaten = $istAdmin ? benutzer_splits_ohne_vorlage() : [];

// Die Plan-Namen als Vorschau. Ohne sie ist ein Splitname eine leere
// Behauptung -- man waehlt einen Split danach aus, was drinsteht.
$planNamen = split_plan_namen(array_merge(
    array_column($meine, 'id'),
    array_column($dieVorlagen, 'id'),
    array_column($kandidaten, 'id')
));

$pageTitle = 'Splits';
require __DIR__ . '/lib/view_header.php';

/** Eine Splitkarte -- gleich aufgebaut fuer eigene Splits und Vorlagen. */
function split_karte(array $sp, array $planNamen, bool $eigener, int $aktivId, bool $gesperrt): void {
    $id     = (int)$sp['id'];
    $plaene = $planNamen[$id] ?? [];
    ?>
    <li class="karte split <?= $eigener && $id === $aktivId ? 'ist-aktiv' : '' ?>" data-id="<?= $id ?>">
        <div class="gruppe-zeile split-kopf">
            <div class="gruppe-felder">
                <?php // Bearbeitbar ist der Name, wo man ihn auch aendern DARF:
                      // beim eigenen Split immer, bei einer Vorlage nur als
                      // Admin. Ein Feld, das man ausfuellen kann und dessen
                      // Speichern dann an einem 403 scheitert, waere unehrlich. ?>
                <?php if ($eigener || is_admin()): ?>
                    <input type="text" class="split-name" value="<?= h((string)$sp['name']) ?>"
                           aria-label="Name des Splits">
                <?php else: ?>
                    <strong><?= h((string)$sp['name']) ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($eigener && $id === $aktivId): ?>
                <span class="abzeichen">aktiv</span>
            <?php endif; ?>
        </div>

        <p class="matt">
            <?php if ($plaene === []): ?>
                Noch kein Plan darin.
            <?php else: ?>
                <?= count($plaene) ?> Plan<?= count($plaene) === 1 ? '' : 'e' ?>:
                <?= h(implode(' → ', $plaene)) ?> ↺
            <?php endif; ?>
        </p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

        <div class="gruppe-knoepfe">
            <?php if ($eigener): ?>
                <?php if ($id !== $aktivId): ?>
                    <button type="button" class="split-aktivieren" <?= $gesperrt ? 'disabled' : '' ?>>
                        Diesen trainieren
                    </button>
                <?php endif; ?>
                <a class="knopf zweit" href="<?= h(base_path()) ?>/plans.php?split=<?= $id ?>">
                    Pläne bearbeiten
                </a>
                <button type="button" class="leise split-speichern">Umbenennen</button>
                <button type="button" class="leise split-duplizieren">Duplizieren</button>
                <?php // "Als Vorlage" stand bis 1.2.0 hier. Es liegt jetzt im
                      // Abschnitt "User Splits" darunter -- an EINER Stelle,
                      // und dort erreicht es auch die Splits der anderen. ?>
                <button type="button" class="gefahr split-loeschen">Löschen</button>
            <?php else: ?>
                <button type="button" class="split-kopieren">Zu mir kopieren</button>
                <?php if (is_admin()): ?>
                    <button type="button" class="leise split-speichern">Umbenennen</button>
                    <a class="knopf zweit" href="<?= h(base_path()) ?>/plans.php?split=<?= $id ?>">
                        Vorlage bearbeiten
                    </a>
                    <?php // Duplizieren legt eine ZWEITE VORLAGE an, nicht eine
                          // persoenliche Kopie -- dafuer steht "Zu mir kopieren"
                          // daneben. Der Weg fuer eine Variante im Katalog:
                          // duplizieren, umbenennen, bearbeiten. ?>
                    <button type="button" class="leise vorlage-duplizieren">Duplizieren</button>
                    <button type="button" class="gefahr split-loeschen">Löschen</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </li>
    <?php
}
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
            Pull“ sind zwei Pläne in einem Split, „Ganzkörper A/B“ ebenso. Nimm
            eine Vorlage unten oder leg dir selbst einen an.
        </p>
    </div>
<?php else: ?>
    <ul id="meine-splits" class="liste-schlicht" data-aktiv="<?= $aktivId ?>">
        <?php foreach ($meine as $sp): ?>
            <?php split_karte($sp, $planNamen, true, $aktivId, $offen !== null); ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<div class="karte">
    <form id="split-neu" class="zeile-eingabe" novalidate>
        <label for="split_name" class="nur-lesbar">Name des neuen Splits</label>
        <input type="text" id="split_name" name="name" placeholder="z. B. Push / Pull" required>
        <button type="submit">Split anlegen</button>
    </form>
    <?php if ($istAdmin): ?>
        <p class="matt">
            <label>
                <input type="checkbox" id="split_vorlage">
                Als Vorlage für alle anlegen
            </label>
        </p>
    <?php endif; ?>
    <p id="split-neu-fehler" class="feld-fehler" role="alert" hidden></p>
</div>

<?php if ($istAdmin): ?>
    <h2>User Splits</h2>

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
<?php endif; ?>

<h2>Vorlagen</h2>

<?php if ($dieVorlagen === []): ?>
    <div class="karte">
        <p><strong>Noch keine Vorlage im Katalog.</strong></p>
        <p class="matt">
            <?php if ($istAdmin): ?>
                Eine Vorlage entsteht am einfachsten aus einem fertigen eigenen
                Split — „Als Vorlage“ legt eine Kopie für alle an.
            <?php else: ?>
                Sobald der Administrator eine anlegt, steht sie hier zur Auswahl.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <p class="matt">
        Eine Vorlage wird beim Auswählen <strong>zu dir kopiert</strong>. Danach
        gehört die Kopie dir: Tauschen, entfernen, ergänzen — nichts davon
        wirkt auf andere, und eine spätere Änderung an der Vorlage wirkt nicht
        auf deine Kopie.
    </p>
    <ul id="vorlagen" class="liste-schlicht">
        <?php foreach ($dieVorlagen as $sp): ?>
            <?php split_karte($sp, $planNamen, false, $aktivId, $offen !== null); ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
