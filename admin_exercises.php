<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Uebungsverwaltung (§6.3).
 *
 * Archiviert heisst versteckt, nicht verschwunden: Der Filter zeigt die
 * Anzahlen aller drei Zustaende, und jede archivierte Zeile nennt
 * Archivierungsdatum, betroffene Plaene und die Menge ihrer Historie.
 */

$filter      = to_str($_GET['filter'] ?? 'aktiv');
$gruppeFilter = to_int_or_null($_GET['group'] ?? null);

if (!in_array($filter, ['aktiv', 'archiviert', 'alle'], true)) {
    $filter = 'aktiv';
}

$alleGruppen = db()->query(
    'SELECT id, name_de, parent_id FROM muscle_groups ORDER BY sort_order, name_de'
)->fetchAll();

// Baum: Hauptgruppen in Sortierreihenfolge, darunter ihre Untergruppen.
$hauptGruppen = array_values(array_filter($alleGruppen, static fn(array $g): bool => $g['parent_id'] === null));
$unterGruppen = [];
foreach ($alleGruppen as $g) {
    if ($g['parent_id'] !== null) {
        $unterGruppen[(int)$g['parent_id']][] = $g;
    }
}

// --- Anzahlen fuer den Filter -------------------------------------------
// Bewusst mit dem Muskelgruppen-Filter, aber ohne den Zustandsfilter: die
// Zahl fuer "Archiviert" muss auch dann sichtbar sein, wenn gerade "Aktiv"
// gewaehlt ist -- sonst merkt niemand, dass es ein Archiv gibt.
$wo = [];
$werte = [];
if ($gruppeFilter !== null) {
    // Eine Hauptgruppe steht fuer sich UND fuer alle ihre Untergruppen: Wer
    // auf "Ruecken" filtert, will die Latissimus- und Trapez-Uebungen sehen.
    // Uebungen sind praktisch immer an einer Untergruppe zugeordnet -- ohne
    // diesen zweiten Zweig blieb die Liste bei jeder unterteilten Hauptgruppe
    // leer.
    //
    // Fuer eine Untergruppe faellt der zweite Zweig von selbst weg: Mehr als
    // zwei Ebenen gibt es nicht, also zeigt nie etwas auf sie als Elternteil.
    $wo[] = 'EXISTS (SELECT 1 FROM exercise_muscle_groups emg
                       JOIN muscle_groups fmg ON fmg.id = emg.muscle_group_id
                      WHERE emg.exercise_id = e.id
                        AND (fmg.id = ? OR fmg.parent_id = ?))';
    $werte[] = $gruppeFilter;
    $werte[] = $gruppeFilter;
}
$woSql = $wo === [] ? '' : ' WHERE ' . implode(' AND ', $wo);

$stmt = db()->prepare(
    'SELECT SUM(CASE WHEN e.archived = 0 THEN 1 ELSE 0 END) AS aktiv,
            SUM(CASE WHEN e.archived = 1 THEN 1 ELSE 0 END) AS archiviert,
            COUNT(*) AS alle
       FROM exercises e' . $woSql
);
$stmt->execute($werte);
$anzahl = $stmt->fetch();
$anzahl = [
    'aktiv'      => (int)($anzahl['aktiv'] ?? 0),
    'archiviert' => (int)($anzahl['archiviert'] ?? 0),
    'alle'       => (int)($anzahl['alle'] ?? 0),
];

// --- Liste ----------------------------------------------------------------
$listeWo   = $wo;
$listeWerte = $werte;
if ($filter === 'aktiv') {
    $listeWo[] = 'e.archived = 0';
} elseif ($filter === 'archiviert') {
    $listeWo[] = 'e.archived = 1';
}
$listeWoSql = $listeWo === [] ? '' : ' WHERE ' . implode(' AND ', $listeWo);

// Bei gesetztem Filter zuerst die Uebungen, deren PRIMAERE Gruppe passt.
// Sonst stand bei "Brust" eine Trizeps-Uebung obenan, die Brust nur
// mittrainiert -- gesucht wird aber, was fuer die Brust gemacht wird.
$sortWerte = [];
$sortSql   = 'e.archived, e.name_de';
if ($gruppeFilter !== null) {
    $sortSql = 'e.archived,
                CASE WHEN EXISTS (SELECT 1 FROM exercise_muscle_groups emg
                                    JOIN muscle_groups pmg ON pmg.id = emg.muscle_group_id
                                   WHERE emg.exercise_id = e.id AND emg.is_primary = 1
                                     AND (pmg.id = ? OR pmg.parent_id = ?))
                     THEN 0 ELSE 1 END,
                e.name_de';
    $sortWerte = [$gruppeFilter, $gruppeFilter];
}

$stmt = db()->prepare(
    'SELECT e.id, e.name_de, e.name_en, e.description, e.focus, e.image_path,
            e.archived, e.archived_at, e.created_at,
            (SELECT COUNT(*) FROM workout_log wl WHERE wl.exercise_id = e.id) AS log_anzahl
       FROM exercises e' . $listeWoSql . '
      ORDER BY ' . $sortSql
);
$stmt->execute([...$listeWerte, ...$sortWerte]);
$uebungen = $stmt->fetchAll();

// --- Zuordnungen und Planreferenzen in je einer Abfrage -------------------
// Statt je Uebung nachzuschlagen: bei 30 Uebungen waeren das sonst 60
// zusaetzliche Abfragen fuer eine Seite.
$zuordnung = [];
foreach (db()->query(
    'SELECT emg.exercise_id, emg.muscle_group_id, emg.is_primary, mg.name_de
       FROM exercise_muscle_groups emg
       JOIN muscle_groups mg ON mg.id = emg.muscle_group_id
      ORDER BY emg.is_primary DESC, mg.sort_order, mg.name_de'
) as $z) {
    $zuordnung[(int)$z['exercise_id']][] = $z;
}

$planReferenzen = [];
foreach (db()->query(
    'SELECT pe.exercise_id, p.name AS plan_name, u.name AS benutzer
       FROM plan_exercises pe
       JOIN plans p ON p.id = pe.plan_id
       LEFT JOIN users u ON u.id = p.user_id
      ORDER BY u.name, p.name'
) as $r) {
    $planReferenzen[(int)$r['exercise_id']][] = $r;
}

/**
 * Rendert die Checkbox-Liste der Muskelgruppen mit Primaer-Radiobutton.
 *
 * Kein Dropdown: Bei Mehrfachauswahl ist die Checkbox-Liste die passende
 * Bedienform, und alle verfuegbaren Gruppen sind auf einen Blick sichtbar (§6.3).
 *
 * @param array $alle     Alle Muskelgruppen in ihrer Sortierreihenfolge
 * @param int[] $gewaehlt IDs der angehakten Gruppen
 * @param int   $primaer  ID der primaeren Gruppe (0 = keine)
 * @param string $prefix  Macht die Feld-IDs je Formular eindeutig
 */
function gruppen_auswahl(array $haupt, array $unter, array $gewaehlt, int $primaer, string $prefix): void {
    /** Eine wählbare Zeile: Radiobutton (primär) und Checkbox (sekundär). */
    $zeile = static function (array $g, bool $istUnter, bool $istLetzte = false)
        use ($gewaehlt, $primaer, $prefix): void {
        $gid          = (int)$g['id'];
        $istPrimaer   = $primaer === $gid;
        $istSekundaer = !$istPrimaer && in_array($gid, $gewaehlt, true);

        // Die letzte Untergruppe braucht eine eigene Klasse: Bei ihr endet die
        // senkrechte Baumlinie auf halber Hoehe, statt durchzulaufen. Mit
        // :last-of-type ginge das nicht -- die Zeilen aller Hauptgruppen sind
        // Geschwister im selben Elternelement.
        $klassen = 'gruppen-zeile';
        if ($istUnter)  { $klassen .= ' gruppen-zeile-unter'; }
        if ($istLetzte) { $klassen .= ' gruppen-zeile-letzte'; }
        ?>
        <div class="<?= $klassen ?>">
            <?php // Direkt anklickbar -- der Radiobutton macht die Ausschliesslichkeit
                  // von selbst: die vorherige Wahl faellt weg. ?>
            <input type="radio" name="primary_group_id" value="<?= $gid ?>"
                   id="<?= h($prefix) ?>_p_<?= $gid ?>"
                   aria-label="<?= h((string)$g['name_de']) ?> als primär"
                   <?= $istPrimaer ? 'checked' : '' ?>>
            <?php // Gesperrt, sobald dieselbe Zeile primaer ist -- eine Gruppe kann
                  // nicht gleichzeitig primaer und sekundaer sein. ?>
            <input type="checkbox" name="secondary_group_ids[]" value="<?= $gid ?>"
                   id="<?= h($prefix) ?>_s_<?= $gid ?>"
                   aria-label="<?= h((string)$g['name_de']) ?> als sekundär"
                   <?= $istSekundaer ? 'checked' : '' ?>
                   <?= $istPrimaer ? 'disabled' : '' ?>>
            <label for="<?= h($prefix) ?>_s_<?= $gid ?>"><?= h((string)$g['name_de']) ?></label>
        </div>
        <?php
    };
    ?>
    <fieldset class="gruppen-wahl" data-gruppen-wahl>
        <legend>Muskelgruppen</legend>
        <p class="matt">
            <strong>Primär</strong> ist die Gruppe, wegen der man die Übung macht.
            <strong>Sekundär</strong> wird mittrainiert; davon beliebig viele.
        </p>
        <?php // Ohne diesen Satz ueberrascht das Verhalten: Man hakt "Trizeps" an und
              // bekommt beim Tausch Bizeps-Uebungen vorgeschlagen, weil beide unter
              // "Arme" haengen. Der Zusammenhang muss AN der Maske stehen. ?>
        <p class="matt hinweis-tausch">
            <strong>Getauscht wird innerhalb der Hauptgruppe.</strong> Für eine Übung an
            <em>Trizeps</em> kommt beim Übungstausch alles unter <em>Arme</em> infrage —
            also auch Bizeps-Übungen. Wähle die Untergruppe möglichst genau; die
            Hauptgruppe ergibt sich daraus.
        </p>

        <div class="gruppen-kopf">
            <span class="spalte-primaer">primär</span>
            <span class="spalte-sekundaer">sekundär</span>
            <span></span>
        </div>

        <?php foreach ($haupt as $hg): ?>
            <?php $kinder = $unter[(int)$hg['id']] ?? []; ?>
            <?php if ($kinder === []): ?>
                <?php // Hauptgruppe ohne Untergruppen bleibt waehlbar -- sonst liesse
                      // sich fuer sie ueberhaupt keine Uebung anlegen. ?>
                <?php $zeile($hg, false); ?>
            <?php else: ?>
                <?php // Hat sie Untergruppen, ist sie NICHT waehlbar: Sie ist die
                      // Tauschklasse und ergibt sich aus der Untergruppe. Waere sie
                      // zusaetzlich anklickbar, gaebe es zwei Wege fuer dieselbe
                      // Aussage und eine uneindeutige Datenlage. ?>
                <p class="gruppen-ueberschrift"><?= h((string)$hg['name_de']) ?></p>
                <?php $letzterIndex = count($kinder) - 1; ?>
                <?php foreach ($kinder as $i => $ug): ?>
                    <?php $zeile($ug, true, $i === $letzterIndex); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <p class="feld-fehler" data-fehler-fuer="muscle_groups" hidden></p>
        <p class="matt">
            Neue Gruppen werden unter
            <a href="<?= h(base_path()) ?>/admin_muscle_groups.php">Muskelgruppen</a> angelegt.
        </p>
    </fieldset>
    <?php
}

/** Baut eine Filter-URL mit den jeweils anderen Parametern. */
function filter_url(string $filter, ?int $gruppe): string {
    $p = ['filter' => $filter];
    if ($gruppe !== null) {
        $p['group'] = $gruppe;
    }
    return '?' . http_build_query($p);
}

$pageTitle = 'Übungen';
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($alleGruppen === []): ?>
    <div class="karte hinweis-warnung">
        <strong>Zuerst Muskelgruppen anlegen.</strong>
        <p class="matt">
            Eine Übung braucht mindestens eine Muskelgruppe. Solange keine
            existiert, lässt sich keine Übung anlegen.
        </p>
        <p>
            <a class="knopf" href="<?= h(base_path()) ?>/admin_muscle_groups.php">
                Zu den Muskelgruppen
            </a>
        </p>
    </div>
<?php else: ?>

<details class="karte" id="neu-bereich">
    <summary class="summary-knopf">Neue Übung anlegen</summary>

    <form id="neu-formular" class="uebung-formular" enctype="multipart/form-data" novalidate>
        <p id="neu-fehler" class="feld-fehler" role="alert" hidden></p>

        <label for="neu_name_de">Name (deutsch)</label>
        <input type="text" id="neu_name_de" name="name_de" required>
        <p class="feld-fehler" data-fehler-fuer="name_de" hidden></p>

        <label for="neu_name_en">Name (englisch, optional)</label>
        <input type="text" id="neu_name_en" name="name_en">

        <?php gruppen_auswahl($hauptGruppen, $unterGruppen, [], 0, 'neu'); ?>

        <label for="neu_focus">Ausführung (optional)</label>
        <input type="text" id="neu_focus" name="focus" maxlength="60"
               placeholder="z. B. vertikal, stehend, in gedehnter Position">
        <p class="matt">
            Was die Übung ausmacht und <em>kein</em> Muskel ist — Bewegungsrichtung,
            Körperhaltung, betonter Bereich der Bewegung. Welche Muskelpartie
            getroffen wird, gehört als Untergruppe in die Auswahl oben.
        </p>
        <p class="feld-fehler" data-fehler-fuer="focus" hidden></p>

        <label for="neu_description">Beschreibung (optional)</label>
        <textarea id="neu_description" name="description" rows="3"></textarea>

        <label for="neu_image">Bild (optional, JPEG, PNG oder WebP, max. 5 MB)</label>
        <input type="file" id="neu_image" name="image" accept="image/jpeg,image/png,image/webp">
        <p class="feld-fehler" data-fehler-fuer="image" hidden></p>

        <p><button type="submit">Übung anlegen</button></p>
    </form>
</details>

<nav class="filterleiste" aria-label="Filter">
    <span class="filter-gruppe">
        <a href="<?= h(filter_url('aktiv', $gruppeFilter)) ?>"
           class="<?= $filter === 'aktiv' ? 'aktiv' : '' ?>">Aktiv (<?= $anzahl['aktiv'] ?>)</a>
        <a href="<?= h(filter_url('archiviert', $gruppeFilter)) ?>"
           class="<?= $filter === 'archiviert' ? 'aktiv' : '' ?>">Archiviert (<?= $anzahl['archiviert'] ?>)</a>
        <a href="<?= h(filter_url('alle', $gruppeFilter)) ?>"
           class="<?= $filter === 'alle' ? 'aktiv' : '' ?>">Alle (<?= $anzahl['alle'] ?>)</a>
    </span>

    <form method="get" class="filter-form">
        <input type="hidden" name="filter" value="<?= h($filter) ?>">
        <label for="group" class="nur-lesbar">Muskelgruppe</label>
        <?php // Hauptgruppe, darunter ihre Untergruppen -- statt einer flachen
              // Liste, in der beide Ebenen ununterscheidbar nebeneinander standen.
              // Kein <optgroup>: Dessen Beschriftung ist nicht waehlbar, die
              // Hauptgruppe muss aber als Filter taugen. Deshalb das vorangestellte
              // "–" als Einrueckung. ?>
        <select id="group" name="group" onchange="this.form.submit()">
            <option value="">alle Muskelgruppen</option>
            <?php foreach ($hauptGruppen as $hg): ?>
                <option value="<?= (int)$hg['id'] ?>" <?= $gruppeFilter === (int)$hg['id'] ? 'selected' : '' ?>>
                    <?= h((string)$hg['name_de']) ?>
                </option>
                <?php foreach ($unterGruppen[(int)$hg['id']] ?? [] as $ug): ?>
                    <option value="<?= (int)$ug['id'] ?>" <?= $gruppeFilter === (int)$ug['id'] ? 'selected' : '' ?>>
                        – <?= h((string)$ug['name_de']) ?>
                    </option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Filtern</button></noscript>
    </form>
</nav>

<?php if ($uebungen === []): ?>
    <div class="karte">
        <p>
            <?php if ($filter === 'archiviert'): ?>
                Keine archivierte Übung.
            <?php elseif ($gruppeFilter !== null): ?>
                Keine Übung mit dieser Muskelgruppe.
            <?php else: ?>
                Noch keine Übung angelegt.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <ul id="uebungs-liste" class="liste-schlicht">
        <?php foreach ($uebungen as $u): ?>
            <?php
            $id         = (int)$u['id'];
            $archiviert = (int)$u['archived'] === 1;
            $gruppen    = $zuordnung[$id] ?? [];
            $plaene     = $planReferenzen[$id] ?? [];
            $logAnzahl  = (int)$u['log_anzahl'];
            $loeschbar  = $plaene === [] && $logAnzahl === 0;

            $gewaehlteIds = array_map(static fn($z): int => (int)$z['muscle_group_id'], $gruppen);
            $primaerId = 0;
            foreach ($gruppen as $z) {
                if ((int)$z['is_primary'] === 1) {
                    $primaerId = (int)$z['muscle_group_id'];
                    break;
                }
            }
            ?>
            <li class="karte uebung <?= $archiviert ? 'ist-archiviert' : '' ?>" data-id="<?= $id ?>">
                <div class="uebung-kopf">
                    <?php if (!empty($u['image_path'])): ?>
                        <?php $thumb = substr((string)$u['image_path'], 0, 32) . '_thumb.jpg'; ?>
                        <img class="uebung-bild"
                             src="<?= h(base_path()) ?>/image.php?f=<?= h($thumb) ?>"
                             alt="" loading="lazy" width="80" height="80">
                    <?php else: ?>
                        <span class="uebung-bild uebung-bild-leer" aria-hidden="true">–</span>
                    <?php endif; ?>

                    <div class="uebung-text">
                        <strong><?= h((string)$u['name_de']) ?></strong>
                        <?php if (!empty($u['name_en'])): ?>
                            <span class="matt"><?= h((string)$u['name_en']) ?></span>
                        <?php endif; ?>
                        <?php if ($archiviert): ?>
                            <span class="abzeichen abzeichen-archiv">archiviert</span>
                        <?php endif; ?>

                        <?php // Erst die Muskelgruppen (primaer vorn, danach die
                              // sekundaeren -- so sortiert die Abfrage), die Ausfuehrung
                              // in einer eigenen Zeile darunter. Gleiche Anordnung wie
                              // in der Handy-Ansicht. ?>
                        <p class="gruppen-anzeige">
                            <?php if ($gruppen === []): ?>
                                <span class="feld-fehler">keine Muskelgruppe zugeordnet</span>
                            <?php else: ?>
                                <?php foreach ($gruppen as $z): ?>
                                    <span class="<?= (int)$z['is_primary'] === 1 ? 'gruppe-primaer' : 'gruppe-sekundaer' ?>">
                                        <?= h((string)$z['name_de']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($u['focus'])): ?>
                            <p class="schwerpunkt-zeile">
                                <span class="schwerpunkt"><?= h((string)$u['focus']) ?></span>
                            </p>
                        <?php endif; ?>

                        <?php if ($archiviert): ?>
                            <p class="matt">
                                Archiviert am <?= h(format_datetime($u['archived_at'])) ?>
                                · <?= $logAnzahl ?> Protokolleintrag<?= $logAnzahl === 1 ? '' : 'e' ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($plaene !== []): ?>
                            <p class="matt">
                                In Plänen:
                                <?php
                                $namen = array_map(
                                    static fn(array $p): string =>
                                        $p['plan_name'] . ' (' . ($p['benutzer'] ?? 'gelöschter Benutzer') . ')',
                                    $plaene
                                );
                                echo h(implode(', ', $namen));
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="uebung-knoepfe">
                    <button type="button" class="leise bearbeiten"
                            aria-expanded="false">Bearbeiten</button>

                    <?php if ($archiviert): ?>
                        <button type="button" class="reaktivieren">Reaktivieren</button>
                        <button type="button" class="gefahr loeschen"
                                data-plaene="<?= count($plaene) ?>" data-logs="<?= $logAnzahl ?>"
                                <?= $loeschbar ? '' : 'disabled title="' . h(
                                    'Nicht löschbar: ' . count($plaene) . ' Planreferenz(en), '
                                    . $logAnzahl . ' Protokolleintrag/-einträge'
                                ) . '"' ?>>
                            Endgültig löschen
                        </button>
                    <?php else: ?>
                        <button type="button" class="leise archivieren"
                                data-plaene="<?= h(implode(', ', array_map(
                                    static fn(array $p): string => (string)$p['plan_name'], $plaene
                                ))) ?>">Archivieren</button>
                    <?php endif; ?>
                </div>

                <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

                <form class="uebung-formular bearbeiten-formular" enctype="multipart/form-data"
                      novalidate hidden>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <p class="feld-fehler formular-fehler" role="alert" hidden></p>

                    <label for="e<?= $id ?>_name_de">Name (deutsch)</label>
                    <input type="text" id="e<?= $id ?>_name_de" name="name_de"
                           value="<?= h((string)$u['name_de']) ?>" required>
                    <p class="feld-fehler" data-fehler-fuer="name_de" hidden></p>

                    <label for="e<?= $id ?>_name_en">Name (englisch, optional)</label>
                    <input type="text" id="e<?= $id ?>_name_en" name="name_en"
                           value="<?= h((string)($u['name_en'] ?? '')) ?>">

                    <?php gruppen_auswahl($hauptGruppen, $unterGruppen, $gewaehlteIds, $primaerId, 'e' . $id); ?>

                    <label for="e<?= $id ?>_focus">Ausführung (optional)</label>
                    <input type="text" id="e<?= $id ?>_focus" name="focus" maxlength="60"
                           value="<?= h((string)($u['focus'] ?? '')) ?>"
                           placeholder="z. B. vertikal, stehend, in gedehnter Position">
                    <p class="feld-fehler" data-fehler-fuer="focus" hidden></p>

                    <label for="e<?= $id ?>_description">Beschreibung (optional)</label>
                    <textarea id="e<?= $id ?>_description" name="description"
                              rows="3"><?= h((string)($u['description'] ?? '')) ?></textarea>

                    <label for="e<?= $id ?>_image">
                        <?= empty($u['image_path']) ? 'Bild hinzufügen' : 'Bild ersetzen' ?>
                        (JPEG, PNG oder WebP, max. 5 MB)
                    </label>
                    <input type="file" id="e<?= $id ?>_image" name="image"
                           accept="image/jpeg,image/png,image/webp">
                    <p class="feld-fehler" data-fehler-fuer="image" hidden></p>

                    <?php if (!empty($u['image_path'])): ?>
                        <label class="zeile-wahl">
                            <input type="checkbox" name="image_remove" value="1">
                            Vorhandenes Bild entfernen
                        </label>
                    <?php endif; ?>

                    <p>
                        <button type="submit">Speichern</button>
                        <button type="button" class="leise abbrechen">Abbrechen</button>
                    </p>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
