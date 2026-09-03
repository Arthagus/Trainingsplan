<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Muskelgruppen-Verwaltung (§6.2).
 *
 * Zwei Ebenen: Hauptgruppen und ihre Untergruppen. Die Trennung ist nicht
 * kosmetisch -- der Uebungstausch (§7.5) vergleicht auf HAUPTGRUPPEN-Ebene.
 * Dadurch darf die Unterteilung beliebig fein werden, ohne dass die
 * Vorschlagslisten leer laufen: Eine Uebung fuer "Brust (oben)" bekommt alles
 * unter "Brust" als moeglichen Ersatz.
 *
 * Die Anzahlen je Gruppe sind kein Beiwerk: Ohne sie ist nicht erkennbar,
 * was tatsaechlich benutzt wird und warum sich eine Gruppe nicht loeschen laesst.
 */

$alle = db()->query(
    'SELECT mg.id, mg.name_de, mg.name_en, mg.parent_id, mg.sort_order,
            COUNT(CASE WHEN e.archived = 0 THEN 1 END) AS aktiv,
            COUNT(CASE WHEN e.archived = 1 THEN 1 END) AS archiviert,
            (SELECT COUNT(*) FROM muscle_groups k WHERE k.parent_id = mg.id) AS kinder
       FROM muscle_groups mg
       LEFT JOIN exercise_muscle_groups emg ON emg.muscle_group_id = mg.id
       LEFT JOIN exercises e               ON e.id = emg.exercise_id
      GROUP BY mg.id, mg.name_de, mg.name_en, mg.parent_id, mg.sort_order
      ORDER BY mg.sort_order, mg.name_de'
)->fetchAll();

// Baum bauen: Hauptgruppen in ihrer Sortierung, darunter die eigenen Kinder.
$haupt = array_values(array_filter($alle, static fn(array $g): bool => $g['parent_id'] === null));
$kinder = [];
foreach ($alle as $g) {
    if ($g['parent_id'] !== null) {
        $kinder[(int)$g['parent_id']][] = $g;
    }
}

// Untergruppen, deren Hauptgruppe fehlt — sollte nicht vorkommen, wäre aber
// unsichtbar und damit unreparierbar.
$bekannt = array_map(static fn(array $g): int => (int)$g['id'], $haupt);
$waisen = [];
foreach ($kinder as $pid => $liste) {
    if (!in_array($pid, $bekannt, true)) {
        $waisen = array_merge($waisen, $liste);
    }
}

/** Rendert eine Gruppenzeile — Hauptgruppe oder Untergruppe. */
function gruppen_zeile(array $g, array $haupt): void {
    $id       = (int)$g['id'];
    $istHaupt = $g['parent_id'] === null;
    $aktiv    = (int)$g['aktiv'];
    $archiv   = (int)$g['archiviert'];
    $benutzt  = $aktiv + $archiv;
    $kinder   = (int)$g['kinder'];
    ?>
    <li class="karte gruppe <?= $istHaupt ? 'gruppe-haupt' : 'gruppe-unter' ?>"
        data-id="<?= $id ?>" data-parent="<?= $g['parent_id'] === null ? '' : (int)$g['parent_id'] ?>">
        <div class="gruppe-zeile">
            <div class="gruppe-felder">
                <input type="text" class="name-de" value="<?= h((string)$g['name_de']) ?>"
                       aria-label="Name deutsch">
                <input type="text" class="name-en" value="<?= h((string)($g['name_en'] ?? '')) ?>"
                       aria-label="Name englisch" placeholder="englisch (optional)">
                <label class="nur-lesbar" for="p<?= $id ?>">Hauptgruppe</label>
                <select id="p<?= $id ?>" class="parent-wahl"
                        <?= $kinder > 0 ? 'disabled title="Hat selbst Untergruppen"' : '' ?>>
                    <option value="">— eigene Hauptgruppe —</option>
                    <?php foreach ($haupt as $hg): ?>
                        <?php if ((int)$hg['id'] === $id) { continue; } ?>
                        <option value="<?= (int)$hg['id'] ?>"
                            <?= (int)($g['parent_id'] ?? 0) === (int)$hg['id'] ? 'selected' : '' ?>>
                            <?= h((string)$hg['name_de']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gruppe-knoepfe">
                <button type="button" class="leise hoch" title="Nach oben" aria-label="Nach oben">↑</button>
                <button type="button" class="leise runter" title="Nach unten" aria-label="Nach unten">↓</button>
                <button type="button" class="speichern">Speichern</button>
                <button type="button" class="gefahr loeschen"
                    <?php if ($kinder > 0): ?>
                        disabled title="Hat <?= $kinder ?> Untergruppe(n)"
                    <?php elseif ($benutzt > 0): ?>
                        disabled title="Noch <?= $benutzt ?> Übung(en) zugeordnet"
                    <?php endif; ?>>
                    Löschen
                </button>
            </div>
        </div>
        <p class="matt gruppe-anzahl">
            <?php if ($kinder > 0): ?>
                <?= $kinder ?> Untergruppe<?= $kinder === 1 ? '' : 'n' ?> ·
            <?php endif; ?>
            <?php if ($benutzt === 0): ?>
                keiner Übung zugeordnet<?= $kinder === 0 ? ' — löschbar' : '' ?>
            <?php else: ?>
                <?= $benutzt ?> Übung<?= $benutzt === 1 ? '' : 'en' ?> zugeordnet
                (<?= $aktiv ?> aktiv, <?= $archiv ?> archiviert)
            <?php endif; ?>
        </p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
    </li>
    <?php
}

$pageTitle = 'Muskelgruppen';
require __DIR__ . '/lib/view_header.php';
?>

<p class="matt">
    Diese Liste bestimmt, was in der Übungsmaske zur Auswahl steht — und in welcher
    Reihenfolge. <strong>Der Übungstausch vergleicht auf Ebene der Hauptgruppe:</strong>
    Für eine Übung an „Brust (oben)" kommt alles unter „Brust" als Ersatz infrage. Deshalb
    darf die Unterteilung so fein werden, wie du magst.
</p>

<details class="karte" id="neu-bereich">
    <summary class="summary-knopf">Neue Muskelgruppe anlegen</summary>

    <form id="neu-formular" novalidate>
        <p id="neu-fehler" class="feld-fehler" role="alert" hidden></p>

        <label for="neu_name_de">Name (deutsch)</label>
        <input type="text" id="neu_name_de" name="name_de" required>
        <p class="feld-fehler" data-fehler-fuer="neu_name_de" hidden></p>

        <label for="neu_name_en">Name (englisch, optional)</label>
        <input type="text" id="neu_name_en" name="name_en">

        <label for="neu_parent">Hauptgruppe</label>
        <select id="neu_parent" name="parent_id">
            <option value="">— eigene Hauptgruppe (oberste Ebene) —</option>
            <?php foreach ($haupt as $hg): ?>
                <option value="<?= (int)$hg['id'] ?>"><?= h((string)$hg['name_de']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="matt">
            Leer lassen ergibt eine neue Hauptgruppe. Sonst wird die Gruppe als
            Untergruppe darunter eingehängt.
        </p>

        <p><button type="submit">Hinzufügen</button></p>
    </form>
</details>

<?php if ($waisen !== []): ?>
    <div class="karte hinweis-warnung">
        <strong>Untergruppen ohne Hauptgruppe.</strong>
        <p class="matt">
            Diese Gruppen zeigen auf eine Hauptgruppe, die es nicht mehr gibt. Bitte
            eine gültige zuweisen — sie erscheinen unten unter „Ohne Hauptgruppe".
        </p>
    </div>
<?php endif; ?>

<h2>Vorhandene Gruppen</h2>

<?php if ($alle === []): ?>
    <div class="karte">
        <p>Noch keine Muskelgruppe angelegt.</p>
        <p class="matt">Ohne mindestens eine Gruppe lässt sich keine Übung anlegen.</p>
    </div>
<?php else: ?>
    <ul id="gruppen-liste" class="liste-schlicht">
        <?php foreach ($haupt as $hg): ?>
            <?php gruppen_zeile($hg, $haupt); ?>
            <?php $unter = $kinder[(int)$hg['id']] ?? []; ?>
            <?php if ($unter !== []): ?>
                <li class="untergruppen-halter" data-parent="<?= (int)$hg['id'] ?>">
                    <ul class="liste-schlicht untergruppen">
                        <?php foreach ($unter as $ug): ?>
                            <?php gruppen_zeile($ug, $haupt); ?>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($waisen !== []): ?>
            <li class="untergruppen-halter">
                <h3>Ohne Hauptgruppe</h3>
                <ul class="liste-schlicht untergruppen">
                    <?php foreach ($waisen as $w): ?>
                        <?php gruppen_zeile($w, $haupt); ?>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endif; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
