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
 * keine Vererbung: Aendert der Admin die Vorlage, bleibt jede bestehende Kopie
 * unberuehrt.
 *
 * Seit 1.2.11 gibt es dazu genau EINEN Weg zurueck, und der Benutzer geht ihn
 * selbst: Weicht seine Kopie von der Vorlage ab -- weil er sie angepasst hat
 * ODER weil der Admin die Vorlage verbessert hat --, erscheint an seiner Karte
 * "Auf Vorlage zurücksetzen". Nichts daran passiert automatisch; ohne den
 * Knopf bleibt alles, wie es ist.
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

// Der Text zum Kopieren (§6.4). Er entsteht SERVERSEITIG und steht fertig in
// der Seite, statt ihn beim Antippen nachzuladen: Das Schreiben in die
// Zwischenablage muss in derselben Benutzeraktion passieren wie der Klick --
// nach einem await auf einen Netzaufruf verweigern strengere Browser (iOS
// Safari) den Zugriff. Nebenbei funktioniert der Knopf damit auch offline.
// Die Kandidatenliste bleibt aussen vor: Das sind die Splits ANDERER Leute,
// dort steht kein Knopf.
$splitTexte = split_texte(array_merge(
    array_column($meine, 'id'),
    array_column($dieVorlagen, 'id')
));

// Herkunft und Abweichung der eigenen Splits (§6.4). Nur fuer die eigenen:
// Eine Vorlage hat selbst keine Vorlage, und fremde Splits verwaltet niemand
// von hier aus.
$vorlageStand = vorlage_stand(array_column($meine, 'id'));

$pageTitle = 'Splits';
require __DIR__ . '/lib/view_header.php';

/** Eine Splitkarte -- gleich aufgebaut fuer eigene Splits und Vorlagen. */
function split_karte(
    array $sp,
    array $planNamen,
    array $splitTexte,
    bool $eigener,
    int $aktivId,
    bool $gesperrt,
    array $vorlagenListe = [],
    array $vorlageStand = []
): void {
    $id     = (int)$sp['id'];
    $plaene = $planNamen[$id] ?? [];

    // Hat diese Karte ueberhaupt Verwaltungsknoepfe? Genau dann, wenn der Split
    // dem Benutzer gehoert (bearbeiten, umbenennen, duplizieren) oder er Admin
    // ist (dasselbe an einer Vorlage). Ein normaler Benutzer sieht an einer
    // fremden Vorlage sonst NICHTS davon -- und dann waere die obere Reihe eine
    // Zeile mit einem einzigen leisen Knopf darin.
    $verwaltung = $eigener || is_admin();

    // "Als Text" steht mal oben, mal unten -- aber nur EINMAL geschrieben.
    // Zweimal im Markup hiesse: beim naechsten Umbenennen des Knopfes eine der
    // beiden vergessen.
    $alsTextKnopf = '<button type="button" class="leise split-text">Als Text</button>';
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

        <?php // Die Klasse traegt die Vorschau nicht zur Zierde: splits.js liest
              // sie AN DER VORLAGENKARTE aus, um in der Rueckfrage vor dem
              // Zuruecksetzen zu sagen, wie der Split danach aussieht. Damit
              // steht dort der server-gerenderte Stand und keine zweite,
              // im Browser zusammengesetzte Fassung derselben Zeile. ?>
        <p class="matt split-plaene">
            <?php if ($plaene === []): ?>
                Noch kein Plan darin.
            <?php else: ?>
                <?= count($plaene) ?> <?= count($plaene) === 1 ? 'Plan' : 'Pläne' ?>:
                <?= h(implode(' → ', $plaene)) ?> ↺
            <?php endif; ?>
        </p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

        <?php // --- Herkunft und Abgleich (§6.4, seit 1.2.11) ----------------
              //
              // Nur an eigenen Splits und nur, wenn es ueberhaupt Vorlagen
              // gibt: Eine Vorlage hat selbst keine Vorlage, und ohne Katalog
              // stuende hier ein leeres Auswahlfeld.
              //
              // Das Auswahlfeld steht dauerhaft da und nicht hinter einem
              // Knopf. Es ist die einzige Stelle, an der ein vor 1.2.11
              // entstandener Split seine Herkunft bekommt -- versteckt faende
              // sie nur, wer schon weiss, dass es sie gibt. ?>
        <?php if ($eigener && $vorlagenListe !== []):
            $stand      = $vorlageStand[$id] ?? null;
            $herkunft   = $stand === null ? 0 : $stand['vorlage_id'];
            $abweichung = $stand !== null && $stand['weicht_ab'];
        ?>
        <p class="split-herkunft">
            <label>
                <span class="matt">Vorlage</span>
                <select class="split-vorlage" aria-label="Vorlage dieses Splits">
                    <option value="0" <?= $herkunft === 0 ? 'selected' : '' ?>>— keine —</option>
                    <?php foreach ($vorlagenListe as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"
                                <?= (int)$v['id'] === $herkunft ? 'selected' : '' ?>>
                            <?= h((string)$v['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php // Der Knopf erscheint NUR bei Abweichung -- gleich, von
                  // welcher Seite sie kommt: eigene Anpassung oder verbesserte
                  // Vorlage. Stimmen beide ueberein, gaebe es nichts zu tun,
                  // und ein wirkungsloser Knopf laedt zum Ausprobieren ein. ?>
            <?php if ($abweichung): ?>
                <button type="button" class="leise split-zuruecksetzen"
                        <?= $gesperrt ? 'disabled title="Erst die laufende Einheit beenden"' : '' ?>>
                    Auf Vorlage zurücksetzen
                </button>
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php // Der Text liegt fertig in der Karte, unsichtbar. splits.js holt
              // ihn beim Antippen ueber .textContent -- damit steht in der
              // Zwischenablage genau das, was hier steht, ohne Umweg ueber
              // einen Netzaufruf oder eine zweite Zusammenbau-Vorschrift im JS.
              //
              // <pre> und nicht <div>: Der Text lebt von seinen Zeilenumbruechen,
              // und textContent eines <div> mit umgebrochener Quelltextzeile
              // brachte sie durcheinander. ?>
        <pre class="split-text-inhalt" hidden><?= h($splitTexte[$id] ?? '') ?></pre>

        <?php // Die obere Reihe entfaellt ganz, wenn nichts zu verwalten ist.
              // Eine leere Reihe waere unsichtbar, aber ihr margin-top nicht. ?>
        <?php if ($verwaltung): ?>
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
            <?php else: ?>
                <?php // "Zu mir kopieren" steht NICHT hier, sondern in der
                      // Abschlusszeile unten -- siehe dort. ?>
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
                <?php endif; ?>
            <?php endif; ?>

            <?php // "Leise" und hinter den Aktionen: Das Kopieren als Text ist
                  // ein Werkzeug daneben, kein Schritt im Ablauf. ?>
            <?= $alsTextKnopf ?>
        </div>
        <?php endif; ?>

        <?php // EINE EIGENE ZEILE fuer die beiden folgenreichen Knoepfe, und
              // zwar unabhaengig davon, wie die Reihe darueber umbricht. Genau
              // das ist der Zweck des zweiten Behaelters: Stuenden sie in
              // derselben Zeile, haenge ihre Lage davon ab, wie breit das
              // Geraet gerade ist und wie viele Knoepfe die Karte hat -- mal
              // saesse "Loeschen" am rechten Rand, mal mitten zwischen
              // "Umbenennen" und "Als Text".
              //
              // Links das Aneignen, rechts das Zerstoeren, und dazwischen die
              // ganze Breite der Karte: Die beiden sind die einzigen Knoepfe,
              // die man nicht versehentlich treffen soll, und der Abstand ist
              // hier die Sicherung. Die Ausrichtung macht margin-left:auto am
              // roten Knopf und nicht space-between -- so steht er auch dann
              // rechts, wenn er allein in der Zeile ist (eigener Split, wo es
              // kein "Zu mir kopieren" gibt).
              //
              // Die Zeile ist nie leer: Wem der Split gehoert, der darf ihn
              // loeschen; wem er nicht gehoert, der darf ihn kopieren. ?>
        <div class="gruppe-knoepfe split-abschluss">
            <?php if (!$eigener): ?>
                <button type="button" class="split-kopieren">Zu mir kopieren</button>
            <?php endif; ?>
            <?php // Ohne Verwaltungsknoepfe hat die obere Reihe nicht
                  // stattgefunden -- dann steht "Als Text" hier, rechts neben
                  // "Zu mir kopieren". Das ist der Fall eines normalen
                  // Benutzers an einer Vorlage, und da sind es genau diese
                  // zwei Knoepfe. ?>
            <?php if (!$verwaltung): ?>
                <?= $alsTextKnopf ?>
            <?php endif; ?>
            <?php // Seinen eigenen Split darf jeder loeschen, eine Vorlage nur
                  // ein Admin -- dieselbe Bedingung wie $verwaltung, hier aber
                  // ausgeschrieben, weil sie etwas anderes bedeutet: dort
                  // "darf verwalten", hier "darf loeschen". Faellt das je
                  // auseinander, faellt es an der richtigen Stelle auf. ?>
            <?php if ($eigener || is_admin()): ?>
                <button type="button" class="gefahr split-loeschen">Löschen</button>
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
            <?php split_karte($sp, $planNamen, $splitTexte, true, $aktivId, $offen !== null,
                              $dieVorlagen, $vorlageStand); ?>
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
            <?php split_karte($sp, $planNamen, $splitTexte, false, $aktivId, $offen !== null); ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php // Ein Dialog fuer ALLE Karten, gefuellt beim Oeffnen -- nicht einer je
      // Split. Bei einem Dutzend Karten waeren das ein Dutzend <dialog> mit
      // demselben Inhalt in der Seite. ?>
<dialog id="text-dialog">
    <h2 id="text-titel">Split als Text</h2>
    <p class="matt">
        Zum Einfügen anderswo — Plan für Plan, nur die Übungsnamen. Bilder,
        Muskelgruppen und Zusätze bleiben bewusst draußen.
    </p>
    <?php // readonly und nicht disabled: Ein disabled-Feld laesst sich weder
          // markieren noch kopieren -- genau das, wofuer es da ist. ?>
    <label for="text-inhalt" class="nur-lesbar">Der Split als Text</label>
    <textarea id="text-inhalt" readonly rows="14" spellcheck="false"></textarea>
    <p class="gruppe-knoepfe">
        <button type="button" id="text-kopieren">In die Zwischenablage</button>
        <button type="button" id="text-schliessen" class="leise">Schließen</button>
    </p>
    <p id="text-hinweis" class="matt" role="status"></p>
</dialog>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
