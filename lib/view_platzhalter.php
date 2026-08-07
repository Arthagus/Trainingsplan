<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Bausteine fuer Seiten, die im Menue schon stehen, aber noch nicht gebaut
 * sind.
 *
 * Zweck ist nicht Kosmetik: Ein Menuepunkt, der ins Leere fuehrt, ist
 * schlimmer als keiner -- eine Seite, die sagt was hierher kommt und warum sie
 * noch fehlt, ist besser als beides. So ist der offene Rest sichtbar, ohne
 * dass man ihn im Lastenheft suchen muss.
 *
 * Benutzung:
 *
 *     $pageTitle = 'Wartung & Backup';
 *     require __DIR__ . '/lib/view_header.php';
 *     platzhalter_beginn('§6.5');
 *     platzhalter_punkte([...]);
 *     platzhalter_ende();
 *     require __DIR__ . '/lib/view_footer.php';
 */

/**
 * Oeffnet den Platzhalter-Kasten.
 *
 * @param string $abschnitt Fundstelle im Lastenheft, z. B. '§6.5'
 * @param string $grund     Warum es die Seite noch nicht gibt (optional)
 */
function platzhalter_beginn(string $abschnitt, string $grund = ''): void {
    ?>
    <div class="karte platzhalter">
        <p class="platzhalter-marke">Kommt später</p>
        <p>
            Diese Seite ist geplant, aber noch nicht gebaut.
            Beschrieben ist sie in <code>LASTENHEFT.md</code> <?= h($abschnitt) ?>.
        </p>
        <?php if ($grund !== ''): ?>
            <p class="matt"><?= h($grund) ?></p>
        <?php endif; ?>
    <?php
}

/**
 * Listet auf, was auf der Seite einmal stehen wird.
 *
 * @param array<string,string> $punkte Überschrift => Erläuterung
 */
function platzhalter_punkte(array $punkte): void {
    if ($punkte === []) {
        return;
    }
    ?>
        <h2>Was hierher kommt</h2>
        <dl class="platzhalter-liste">
            <?php foreach ($punkte as $titel => $text): ?>
                <dt><?= h($titel) ?></dt>
                <dd><?= h($text) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php
}

function platzhalter_ende(): void {
    ?>
    </div>
    <?php
}
