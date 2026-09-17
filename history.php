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
 * Trainingshistorie (§10).
 *
 * Zwei Ansichten:
 *   - Einheiten: wann wurde was trainiert, aufklappbar mit den Gewichten
 *   - Übungen:   wie hat sich das Gewicht je Übung entwickelt
 *
 * **Jeder sieht ausschliesslich seine eigenen Daten.** Es gibt hier bewusst
 * keine Benutzerauswahl fuer Admins: Trainingsdaten sind persoenlich. Die
 * Abfragen in lib/training.php filtern durchgehend ueber user_id, der Wert
 * kommt aus der Sitzung und nie aus einem Parameter -- damit ist der
 * IDOR-Schutz (§5) hier keine Pruefung, die man vergessen koennte, sondern
 * Teil der Abfrage.
 */

$userId  = current_user_id();
$ansicht = to_str($_GET['ansicht'] ?? 'einheiten');
if (!in_array($ansicht, ['einheiten', 'uebungen'], true)) {
    $ansicht = 'einheiten';
}

$einheiten = einheiten_verlauf($userId);
$offen     = offene_einheit($userId);

// Nur wenn es mehrere Splits gibt, ist der Splitname im Verlauf eine
// Information -- bei einem einzigen waere er in jeder Zeile dasselbe Wort.
$mehrereSplits = count(splits_von($userId)) > 1;

// --- Eingrenzung der Uebungsansicht (§7.8) ---------------------------------
//
// ?einheit= grenzt auf die Uebungen EINER Einheit ein, ?uebung= auf eine
// einzige Uebung (die Einzelansicht, in die ein Klick aus "Einheiten" fuehrt),
// ?sort= ordnet. Alle drei sind reine Anzeige und werden an jeder Stelle
// gegen den eigenen Bestand geprueft:
//
//   - einheit nur, wenn sie in $einheiten steht -- dieselbe Liste, die das
//     Auswahlfeld fuellt. Wie $erlaubt in index.php ist die Liste hier der
//     IDOR-Schutz; eine fremde oder unbekannte ID faellt still auf "alle
//     Einheiten" zurueck. Die Abfrage prueft die user_id zusaetzlich selbst.
//   - uebung ist ohnehin nur ein weiterer Filter in einer Abfrage, die per
//     user_id auf den eigenen Verlauf beschraenkt ist.
$einheitId = to_int_or_null($_GET['einheit'] ?? null);
$gewaehlteEinheit = null;
foreach ($einheiten as $kandidat) {
    if ((int)$kandidat['id'] === $einheitId) {
        $gewaehlteEinheit = $kandidat;
        break;
    }
}
if ($gewaehlteEinheit === null) {
    $einheitId = null;
}
$uebungId = to_int_or_null($_GET['uebung'] ?? null);

// Ohne ausdrueckliche Wahl ordnet eine gewaehlte Einheit in ihrer eigenen
// Reihenfolge -- so, wie man sie trainiert hat und wie "Einheiten" sie zeigt.
$sortGewaehlt = to_str($_GET['sort'] ?? '');
$sortierung   = $sortGewaehlt !== '' ? $sortGewaehlt : ($einheitId !== null ? 'einheit' : 'zuletzt');
if (!array_key_exists($sortierung, VERLAUF_SORTIERUNG)
    || ($sortierung === 'einheit' && $einheitId === null)) {
    $sortierung = 'zuletzt';
}

// ?gruppe= grenzt auf eine Muskelgruppe ein -- aber NUR bei "alle Einheiten"
// und "Nach Muskelgruppe" (Vorgabe des Benutzers, 2026-09-14). Nur dort wird
// das Auswahlfeld angeboten, und nur dort gilt der Wert: Wer die Sortierung
// oder die Einheit wechselt, schickt das Feld noch mit, weil es im Moment des
// Absendens im Formular steht. Wirkte der Wert dann weiter, stuende die Liste
// eingegrenzt da, ohne dass irgendwo ein Feld zeigte, wodurch.
//
// Die Muskelgruppen sind dieselbe zweistufige Liste wie in der Uebungsauswahl
// (lib/view_uebung_waehlen_dialog.php): Hauptgruppen in ihrer Reihenfolge,
// darunter eingerueckt die Untergruppen. Eine unbekannte ID faellt auf "alle".
$gruppeFilterAktiv = $ansicht === 'uebungen' && $einheitId === null && $sortierung === 'muskel';
$hauptGruppen = [];
$unterGruppen = [];
$gruppeId     = null;
if ($gruppeFilterAktiv) {
    $alleGruppen = db()->query(
        'SELECT id, name_de, parent_id FROM muscle_groups ORDER BY sort_order, name_de'
    )->fetchAll();
    foreach ($alleGruppen as $g) {
        if ($g['parent_id'] === null) {
            $hauptGruppen[] = $g;
        } else {
            $unterGruppen[(int)$g['parent_id']][] = $g;
        }
    }
    $gruppeWunsch = to_int_or_null($_GET['gruppe'] ?? null);
    if (in_array($gruppeWunsch, array_map(static fn(array $g): int => (int)$g['id'], $alleGruppen), true)) {
        $gruppeId = $gruppeWunsch;
    }
}

$uebungen = uebungen_mit_verlauf($userId, $sortierung, $einheitId, $uebungId, $gruppeId);
// Die Zahl in der Umschaltleiste nennt immer den ganzen Bestand -- eine Zahl,
// die mit dem Filter schrumpft, liest sich dort wie ein verlorener Verlauf.
$uebungenGesamt = ($einheitId === null && $uebungId === null && $gruppeId === null)
    ? count($uebungen)
    : count(uebungen_mit_verlauf($userId));

// Die Einheit ?offen= steht in der Ansicht "Einheiten" aufgeklappt -- das Ziel
// des Datums-Links aus der Uebungstabelle.
$offenId = to_int_or_null($_GET['offen'] ?? null);

// ?bearbeiten= zeigt EINE abgeschlossene Einheit zum Korrigieren ihrer Saetze
// (§7.8, Fallstrick 35). Wie ?einheit= nur, wenn sie in $einheiten steht --
// die Liste enthaelt ausschliesslich eigene, beendete Einheiten und ist damit
// der IDOR-Schutz der Anzeige; api/log.php prueft beim Speichern selbst.
$bearbeitenId = to_int_or_null($_GET['bearbeiten'] ?? null);
$bearbeiteteEinheit = null;
foreach ($einheiten as $kandidat) {
    if ($ansicht === 'einheiten' && (int)$kandidat['id'] === $bearbeitenId) {
        $bearbeiteteEinheit = $kandidat;
        break;
    }
}

/**
 * Die Satzliste, mit der eine Zeile in der Bearbeitung beginnt.
 *
 * Eine Zeile ohne workout_sets, aber mit Leitwert -- protokolliert vor 1.1.0
 * bzw. im einfachen Modus bis 1.4.2 -- bekommt ihren Wert als EINEN Satz
 * vorbelegt. Sonst stuende sie mit leerer Liste da, und wer daneben etwas
 * aendert und speichert, liesse ihr Gewicht verschwinden. Unveraendert
 * geschickt wird sie nie (history.js vergleicht mit dem Anfangsstand).
 */
function korrektur_saetze(array $zeile, array $saetze): array {
    if ($saetze !== []) {
        return $saetze;
    }
    $leer = ['satz_nr' => 1, 'reps' => null, 'weight' => null, 'distanz_m' => null, 'dauer_s' => null];
    if (ist_ausdauer($zeile['erfassung'] ?? null)) {
        if ($zeile['distanz_m'] === null && $zeile['dauer_s'] === null) {
            return [];
        }
        return [array_merge($leer, [
            'distanz_m' => $zeile['distanz_m'] === null ? null : (int)$zeile['distanz_m'],
            'dauer_s'   => $zeile['dauer_s'] === null ? null : (int)$zeile['dauer_s'],
        ])];
    }
    if ($zeile['weight'] === null) {
        return [];
    }
    return [array_merge($leer, ['weight' => (float)$zeile['weight']])];
}

/**
 * Baut eine Adresse dieser Seite. Leere Werte fallen heraus, damit die Adresse
 * nur traegt, was wirklich gewaehlt ist.
 */
function verlauf_url(array $parameter, string $anker = ''): string {
    $parameter = array_filter($parameter, static fn($w): bool => $w !== null && $w !== '');
    return '?' . http_build_query($parameter) . ($anker === '' ? '' : '#' . $anker);
}

/**
 * Die Beschriftung einer Einheit im Auswahlfeld: Datum und Uhrzeit, Plan und
 * -- wie in der Ansicht "Einheiten" -- der Split nur, wenn es mehrere gibt.
 *
 * Kurzes Datum, weil das aufgeklappte <select> am Handy nach rund 32 Zeichen
 * umbricht und sich dagegen nicht gestalten laesst (CLAUDE.md, Frontend). Die
 * Uhrzeit bleibt trotzdem: Zwei Einheiten desselben Plans am selben Tag waeren
 * sonst nicht zu unterscheiden.
 */
function einheit_beschriftung(array $e, bool $mitSplit): string {
    $teile = [format_datum_kurz($e['started_at']) . ' ' . format_zeit($e['started_at'])];
    $teile[] = $e['plan_name'] === null ? 'gelöschter Plan' : (string)$e['plan_name'];
    if ($mitSplit && $e['split_name'] !== null) {
        $teile[] = (string)$e['split_name'];
    }
    return implode(' · ', $teile);
}

/**
 * Baut die Zelle „Saetze" als umbrechendes Gitter statt als eine Zeile.
 *
 * `saetze_text()` liefert "12×40 · 10×40 · 9×45" am Stueck, und die Zelle stand
 * mit `white-space: nowrap`. Bei fuenf Spalten -- Datum, Saetze, Volumen, 1RM,
 * Gewicht -- reichte das auf einem Pixel 10 Pro XL nicht, die Tabelle rollte
 * seitwaerts. Gemeldet aus dem Training am 2026-08-17.
 *
 * Jeder Satz kommt deshalb als eigenes <span>; das Gitter in `.satz-gitter`
 * setzt zwei nebeneinander und den Rest in weitere Zeilen. Der Mittelpunkt als
 * Trenner faellt weg -- die Spalten trennen bereits, und ein Trenner am
 * Zeilenende saehe aus, als fehlte etwas.
 *
 * Bewusst NICHT in `saetze_text()` geaendert: Das Paar `saetze_text()` /
 * `saetzeText()` (PHP + JS) muss gleich bleiben, und `saetze_zusammenfassung()`
 * baut darauf auf. Hier entsteht Markup, dort ein reiner String.
 *
 * @param array  $saetze    Saetze der Position, in Reihenfolge
 * @param string $erfassung 'kraft' oder 'ausdauer' -- eine Einheit darf beides
 *                          enthalten, die Entscheidung faellt also je ZEILE
 */
function satz_gitter(array $saetze, string $erfassung): string {
    if ($saetze === []) {
        return '<span class="matt">—</span>';
    }

    $teile = [];
    foreach ($saetze as $s) {
        if (ist_ausdauer($erfassung)) {
            $m = $s['distanz_m'] === null ? '—' : $s['distanz_m'] . ' m';
            $t = $s['dauer_s']   === null ? '—' : dauer_hms($s['dauer_s']);
            $teile[] = '<span>' . h($m . '/' . $t) . '</span>';
            continue;
        }

        $wdh = $s['reps']   === null ? '?' : (string)$s['reps'];
        $kg  = $s['weight'] === null ? '—' : format_decimal($s['weight']);
        $teile[] = '<span>' . h($wdh . '×' . $kg) . '</span>';
    }

    return '<span class="satz-gitter">' . implode('', $teile) . '</span>';
}

/**
 * Zeichnet den Gewichtsverlauf als kleine Kurve.
 *
 * Inline-SVG statt Diagramm-Bibliothek: Das hält die Regel „kein Build-Step,
 * keine Abhängigkeiten" ein und wiegt nichts. Bei weniger als zwei Punkten
 * gibt es nichts zu verbinden — dann bleibt die Fläche leer.
 *
 * Punkte, deren Feld null ist, fallen heraus statt als 0 zu zaehlen: Volumen
 * und 1RM gibt es nur fuer satzgenau protokollierte Einheiten, und eine 0 riss
 * in die Kurve einen Einbruch, den es nie gegeben hat.
 *
 * **`$umgedreht` spiegelt die Hoehe** (Fallstrick 34): Bei einer Uebung mit
 * Unterstuetzung ist weniger Gewicht besser, und die Kurve soll wie ueberall
 * nach OBEN laufen, wenn es vorangeht. Die Zahlen daneben bleiben die echten kg
 * -- gedreht wird nur die Zeichnung (Entscheidung des Benutzers, 2026-09-15).
 *
 * @param array  $punkte    Aufsteigend nach Zeit
 * @param string $feld      Welcher Wert gezeichnet wird
 * @param bool   $umgedreht Kleinster Wert oben statt unten
 */
function verlauf_kurve(array $punkte, string $feld = 'weight', bool $umgedreht = false): void {
    $werte = [];
    foreach ($punkte as $p) {
        if (($p[$feld] ?? null) !== null) {
            $werte[] = (float)$p[$feld];
        }
    }
    if (count($werte) < 2) {
        return;
    }

    $min = min($werte);
    $max = max($werte);
    // Alle Werte gleich: eine waagerechte Linie in der Mitte, sonst teilte
    // man durch null.
    $spanne = ($max - $min) > 0.0 ? ($max - $min) : 1.0;

    $b = 100.0;   // Koordinatensystem, per viewBox skaliert
    $h = 28.0;
    $rand = 3.0;

    $koord = [];
    foreach ($werte as $i => $w) {
        $x = count($werte) === 1 ? 0.0 : ($i / (count($werte) - 1)) * $b;
        $anteil = ($w - $min) / $spanne;
        if ($umgedreht) {
            $anteil = 1.0 - $anteil;
        }
        $y = $h - $rand - $anteil * ($h - 2 * $rand);
        $koord[] = round($x, 2) . ',' . round($y, 2);
    }
    $letzte = end($koord);
    [$lx, $ly] = explode(',', (string)$letzte);
    ?>
    <svg class="verlauf-kurve" viewBox="0 0 <?= $b ?> <?= $h ?>"
         preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polyline points="<?= h(implode(' ', $koord)) ?>"
                  fill="none" stroke="currentColor" stroke-width="1.5"
                  vector-effect="non-scaling-stroke"
                  stroke-linejoin="round" stroke-linecap="round"/>
        <circle cx="<?= h($lx) ?>" cy="<?= h($ly) ?>" r="2" fill="currentColor"/>
    </svg>
    <?php
}

$pageTitle = 'Verlauf';
require __DIR__ . '/lib/view_header.php';
?>

<nav class="filterleiste" aria-label="Ansicht">
    <span class="filter-gruppe">
        <a href="?ansicht=einheiten" class="<?= $ansicht === 'einheiten' ? 'aktiv' : '' ?>">
            Einheiten (<?= count($einheiten) ?>)
        </a>
        <a href="?ansicht=uebungen" class="<?= $ansicht === 'uebungen' ? 'aktiv' : '' ?>">
            Übungen (<?= $uebungenGesamt ?>)
        </a>
    </span>

    <?php // Die Filter nur, wenn es ueberhaupt etwas zu ordnen gibt. ?>
    <?php if ($ansicht === 'uebungen' && $uebungenGesamt > 0): ?>
        <form method="get" class="filter-form verlauf-filter">
            <input type="hidden" name="ansicht" value="uebungen">

            <label for="verlauf-einheit" class="nur-lesbar">Einheit</label>
            <select id="verlauf-einheit" name="einheit">
                <option value="">alle Einheiten</option>
                <?php foreach ($einheiten as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= $einheitId === (int)$e['id'] ? 'selected' : '' ?>>
                        <?= h(einheit_beschriftung($e, $mehrereSplits)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php // data-vorgabe: Die Sortierung ist nicht ausdruecklich gewaehlt.
                  // history.js schickt sie beim Wechsel der Einheit dann nicht mit,
                  // damit eine neu gewaehlte Einheit in ihrer Trainingsreihenfolge
                  // erscheint -- statt in "Zuletzt trainiert", nur weil das Feld
                  // diesen Wert anzeigte. ?>
            <label for="verlauf-sort" class="nur-lesbar">Sortierung</label>
            <select id="verlauf-sort" name="sort"<?= $sortGewaehlt === '' ? ' data-vorgabe' : '' ?>>
                <?php foreach (VERLAUF_SORTIERUNG as $code => $label): ?>
                    <?php if ($code === 'einheit' && $einheitId === null) continue; ?>
                    <option value="<?= h($code) ?>" <?= $sortierung === $code ? 'selected' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($gruppeFilterAktiv): ?>
                <?php // Dasselbe Markup wie in der Uebungsauswahl: kein <optgroup>,
                      // weil die Hauptgruppe selbst waehlbar sein muss, und das
                      // vorangestellte "–" als Einrueckung der Untergruppen. ?>
                <label for="verlauf-gruppe" class="nur-lesbar">Muskelgruppe</label>
                <select id="verlauf-gruppe" name="gruppe">
                    <option value="">alle Muskelgruppen</option>
                    <?php foreach ($hauptGruppen as $hg): ?>
                        <option value="<?= (int)$hg['id'] ?>" <?= $gruppeId === (int)$hg['id'] ? 'selected' : '' ?>>
                            <?= h((string)$hg['name_de']) ?>
                        </option>
                        <?php foreach ($unterGruppen[(int)$hg['id']] ?? [] as $ug): ?>
                            <option value="<?= (int)$ug['id'] ?>" <?= $gruppeId === (int)$ug['id'] ? 'selected' : '' ?>>
                                – <?= h((string)$ug['name_de']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <noscript><button type="submit">Anzeigen</button></noscript>
        </form>
    <?php endif; ?>
</nav>

<?php if ($offen !== null): ?>
    <div class="karte einheit-laeuft">
        <strong>Eine Einheit läuft gerade</strong>
        <span class="matt">seit <?= h(format_datetime($offen['started_at'])) ?></span>
        <p class="matt">
            Sie erscheint hier, sobald sie beendet ist.
            <a href="<?= h(base_path()) ?>/index.php">Zum Training</a>
        </p>
    </div>
<?php endif; ?>

<?php if ($bearbeiteteEinheit !== null): ?>
    <?php
    $be        = $bearbeiteteEinheit;
    $beId      = (int)$be['id'];
    $beZeilen  = einheit_eintraege($beId, $userId);
    $beSaetze  = saetze_zu_logs(array_column($beZeilen, 'log_id'));
    $zurueck   = verlauf_url(['ansicht' => 'einheiten', 'offen' => $beId], 'einheit-' . $beId);
    ?>
    <div class="karte korrektur-kopf">
        <p>
            <strong>Einheit bearbeiten</strong><br>
            <?= h(format_datetime($be['started_at'])) ?> ·
            <?= $be['plan_name'] === null ? 'gelöschter Plan' : h((string)$be['plan_name']) ?>
        </p>
        <p class="matt">
            Sätze ändern, nachtragen oder entfernen. Übungen lassen sich hier weder
            hinzufügen noch entfernen — auch keine, die in dieser Einheit übersprungen
            wurde. Die Änderungen zählen ab dem Speichern für Verlauf, Bestwerte und
            die Vorbelegung im nächsten Training.
        </p>
    </div>

    <?php if ($beZeilen === []): ?>
        <div class="karte"><p class="matt">In dieser Einheit ist keine Übung protokolliert.</p></div>
    <?php else: ?>
        <ul class="liste-schlicht korrektur-liste" data-session="<?= $beId ?>"
            data-zurueck="<?= h($zurueck) ?>">
            <?php foreach ($beZeilen as $z): ?>
                <?php
                $zAusdauer = ist_ausdauer($z['erfassung'] ?? null);
                $zUnterst  = !$zAusdauer && ist_unterstuetzt($z['gewicht_wirkung'] ?? null);
                ?>
                <?php // Die Satzzeilen baut history.js aus data-saetze -- sie muessen
                      // sich ohnehin im Browser hinzufuegen und entfernen lassen. ?>
                <li class="karte korrektur-position"
                    data-log="<?= (int)$z['log_id'] ?>"
                    data-erfassung="<?= $zAusdauer ? 'ausdauer' : 'kraft' ?>"
                    data-unterstuetzt="<?= $zUnterst ? '1' : '0' ?>"
                    data-saetze="<?= h(json_encode(
                        korrektur_saetze($z, $beSaetze[(int)$z['log_id']] ?? [])
                    )) ?>">
                    <div class="uebung-text"><?= uebung_name((string)$z['name_de'], $z['name_en']) ?></div>
                    <?php if ($zUnterst): ?>
                        <p class="matt">Gewicht bedeutet hier: Unterstützung.</p>
                    <?php endif; ?>
                    <ul class="satz-liste"></ul>
                    <button type="button" class="leise satz-hinzu">
                        + <?= $zAusdauer ? 'Intervall' : 'Satz' ?>
                    </button>
                    <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="einheit-aktionen korrektur-aktionen">
        <?php if ($beZeilen !== []): ?>
            <button type="button" class="korrektur-speichern">Änderungen speichern</button>
        <?php endif; ?>
        <a class="knopf zweit" href="<?= h($zurueck) ?>">Abbrechen</a>
    </p>

<?php elseif ($ansicht === 'einheiten'): ?>

    <?php if ($einheiten === []): ?>
        <div class="karte">
            <p><strong>Noch keine abgeschlossene Trainingseinheit.</strong></p>
            <p class="matt">
                Sobald du ein Training beendest, steht es hier — mit Datum, Dauer und den
                Gewichten, die du eingetragen hast.
            </p>
        </div>
    <?php else: ?>
        <ul class="liste-schlicht">
            <?php foreach ($einheiten as $e): ?>
                <?php
                $eintraege = einheit_eintraege((int)$e['id'], $userId);
                $gesamt    = (int)$e['gesamt'];
                $fertig    = (int)$e['erledigt'];

                // Ein Aufruf je Einheit, nicht je Zeile: saetze_zu_logs() holt
                // die Saetze aller Positionen dieser Einheit auf einmal.
                $saetze = saetze_zu_logs(array_column($eintraege, 'log_id'));
                $mitSaetzen = $saetze !== [];

                // Ein Plan darf Kraft und Ausdauer mischen. Sobald EINE
                // Ausdauerposition dabei ist, bekommt die Zahlenspalte einen
                // gemeinsamen Kopf -- "1RM" waere dort fuer die halbe Tabelle
                // schlicht falsch.
                $mitAusdauer = false;
                // Dasselbe fuer eine Uebung mit Unterstuetzung (Fallstrick 34):
                // Sie hat kein 1RM, in ihrer Zeile steht das Leitgewicht -- also
                // ebenfalls der gemeinsame Kopf "Kennzahl".
                $mitUnterstuetzung = false;
                foreach ($eintraege as $pruef) {
                    if (ist_ausdauer($pruef['erfassung'] ?? null)) {
                        $mitAusdauer = true;
                    } elseif (ist_unterstuetzt($pruef['gewicht_wirkung'] ?? null)) {
                        $mitUnterstuetzung = true;
                    }
                }
                ?>
                <li class="karte einheit-karte" id="einheit-<?= (int)$e['id'] ?>"
                    data-session="<?= (int)$e['id'] ?>">
                    <details<?= $offenId === (int)$e['id'] ? ' open' : '' ?>>
                        <summary class="einheit-kopf">
                            <span class="einheit-datum">
                                <?= h(format_datetime($e['started_at'])) ?>
                            </span>
                            <span class="einheit-plan">
                                <?= $e['plan_name'] === null
                                    ? '<em class="matt">gelöschter Plan</em>'
                                    : h((string)$e['plan_name']) ?>
                                <?php // Der Split dahinter, sobald es mehr als
                                      // einen gibt: "Ganzkörper A" allein ist
                                      // nicht einzuordnen, wenn zwei Splits
                                      // einen Plan dieses Namens fuehren. ?>
                                <?php if ($mehrereSplits && $e['split_name'] !== null): ?>
                                    <span class="matt">· <?= h((string)$e['split_name']) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="matt einheit-eckdaten">
                                <?= h(dauer_text($e['started_at'], $e['ended_at'])) ?>
                                · <?= $fertig ?><?= $gesamt > 0 ? '/' . $gesamt : '' ?> Übungen
                            </span>
                        </summary>

                        <?php if ($eintraege === []): ?>
                            <p class="matt">Keine Übung protokolliert.</p>
                        <?php else: ?>
                            <?php // Die Spalte „Sätze" steht nur, wenn diese Einheit
                                  // welche hat. Eine leere Spalte über jede im
                                  // einfachen Modus protokollierte Einheit hinweg
                                  // wäre am Handy verschenkte Breite. ?>
                            <table class="verlauf-tabelle">
                                <thead>
                                    <tr>
                                        <th>Übung</th>
                                        <?php if ($mitSaetzen): ?><th>Sätze</th><?php endif; ?>
                                        <?php // Die letzte Spalte haengt am selben Schalter wie
                                              // die Satz-Spalte: Wo Saetze protokolliert sind,
                                              // steht dort das geschaetzte 1RM, sonst das
                                              // Gewicht.
                                              //
                                              // Der Tausch ist kein Geschmack: Das schwerste
                                              // Gewicht steht bei satzgenauen Einheiten schon
                                              // in der Satz-Spalte daneben, die Zahl waere also
                                              // doppelt. Das 1RM ist die einzige Kennzahl, die
                                              // Gewicht UND Wiederholungen zusammenfasst -- und
                                              // damit die einzige, die man ueber verschiedene
                                              // Wiederholungszahlen hinweg vergleichen kann.
                                              //
                                              // Ohne Wiederholungen laesst es sich nicht
                                              // schaetzen; im einfachen Modus bleibt es deshalb
                                              // beim Gewicht. Eine Spalte "1RM", die ueber eine
                                              // ganze Einheit hinweg nur Striche zeigt, waere
                                              // schlechter als die Zahl, die es gibt. ?>
                                        <?php // Sobald eine Ausdauerposition dabei ist, kann
                                              // die Spalte nicht mehr "1RM" oder "Gewicht"
                                              // heissen -- dort steht dann eine Pace. Ein
                                              // gemeinsamer Kopf ist ehrlicher als einer, der
                                              // fuer die halbe Tabelle falsch ist; eine SECHSTE
                                              // Spalte nur fuer die Pace waere bei einer reinen
                                              // Kraft-Einheit dauerhaft leer. ?>
                                        <th class="spalte-zahl"><?=
                                            ($mitAusdauer || ($mitSaetzen && $mitUnterstuetzung))
                                                ? 'Kennzahl'
                                                : ($mitSaetzen ? '1RM' : 'Gewicht')
                                        ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($eintraege as $z): ?>
                                    <?php $zeilenSaetze = $saetze[(int)$z['log_id']] ?? []; ?>
                                    <tr>
                                        <td>
                                            <?php // Der Name fuehrt in die Einzelansicht der Uebung
                                                  // -- mit ALLEN bisherigen Einheiten und dieser hier
                                                  // markiert. Die Einheit reist mit, damit man von
                                                  // dort zu ihren uebrigen Uebungen weiterkommt.
                                                  //
                                                  // Das <strong> bleibt unangetastet im Link: Es
                                                  // traegt weiterhin nur den deutschen Namen
                                                  // (Fallstrick 27). ?>
                                            <a class="uebung-link" href="<?= h(verlauf_url([
                                                'ansicht' => 'uebungen',
                                                'einheit' => (int)$e['id'],
                                                'uebung'  => (int)$z['exercise_id'],
                                            ])) ?>"><?= uebung_name((string)$z['name_de'], $z['name_en']) ?></a>
                                            <?php // Nach einem Tausch steht im Log die Ersatzübung;
                                                  // ohne diesen Hinweis wirkt der Plan verändert. ?>
                                            <?php if ($z['plan_uebung_name'] !== null
                                                   && (int)$z['plan_uebung_id'] !== (int)$z['exercise_id']): ?>
                                                <span class="abzeichen">statt <?= uebung_name_kurz(
                                                    (string)$z['plan_uebung_name'], $z['plan_uebung_name_en']
                                                ) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php $zeileAusdauer = ist_ausdauer($z['erfassung'] ?? null); ?>
                                        <?php $zeileUnterstuetzt = !$zeileAusdauer
                                            && ist_unterstuetzt($z['gewicht_wirkung'] ?? null); ?>
                                        <?php if ($mitSaetzen): ?>
                                            <td class="satz-spalte">
                                                <?= satz_gitter(
                                                    $zeilenSaetze,
                                                    $zeileAusdauer ? 'ausdauer' : 'kraft'
                                                ) ?>
                                            </td>
                                        <?php endif; ?>
                                        <?php $e1rm = $mitSaetzen && !$zeileAusdauer && !$zeileUnterstuetzt
                                            ? saetze_e1rm($zeilenSaetze)
                                            : null; ?>
                                        <td class="spalte-zahl">
                                            <?php if ($zeileAusdauer): ?>
                                                <?php // Die Pace steht ueber die GANZE Position,
                                                      // nicht je Intervall -- gefragt ist, wie
                                                      // schnell man an dem Tag war. Gerechnet
                                                      // wird aus den Leitwerten der Zeile, also
                                                      // aus denselben Summen, die auch der
                                                      // Uebungsverlauf benutzt. ?>
                                                <span class="pace-zelle"><?= h(pace_text(
                                                    $z['distanz_m'] === null ? null : (int)$z['distanz_m'],
                                                    $z['dauer_s']   === null ? null : (int)$z['dauer_s']
                                                )) ?></span>
                                            <?php elseif ($zeileUnterstuetzt): ?>
                                                <?php // Kein 1RM: kg × Wdh ergaebe bei abgenommener
                                                      // Last eine Zahl, die mit dem Fortschritt
                                                      // SINKT. Stattdessen das Leitgewicht, also der
                                                      // leichteste Satz (Fallstrick 34). ?>
                                                <?= $z['weight'] === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal((float)$z['weight'])) . ' kg'
                                                      . ' <span class="matt">Unterst.</span>' ?>
                                            <?php elseif ($mitSaetzen): ?>
                                                <?= $e1rm === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal(round($e1rm, 1))) . ' kg' ?>
                                            <?php else: ?>
                                                <?= $z['weight'] === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal((float)$z['weight'])) . ' kg' ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php // Der Vorbehalt gehoert an die Zahl und nicht in eine
                                  // Fussnote: Ein geschaetztes Maximum sieht aus wie ein
                                  // gemessenes. Wortgleich zur Erklaerung im Uebungs-
                                  // Abschnitt weiter unten -- zwei Schreibweisen fuer
                                  // dieselbe Kennzahl liest man als zwei Kennzahlen. ?>
                            <?php if ($mitSaetzen): ?>
                                <p class="matt">
                                    <strong>1RM</strong> ist das geschätzte
                                    Einwiederholungsmaximum nach Epley
                                    (kg × (1 + Wdh ÷ 30)) aus dem besten Satz — eine
                                    Näherung, kein gemessener Wert. Das schwerste Gewicht
                                    selbst steht in der Spalte „Sätze“.
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php // Fuer Fehleingaben: versehentlich gestartet, doch nicht
                              // trainiert, Testdaten. Ohne diesen Weg blieben solche
                              // Zeilen dauerhaft stehen. ?>
                        <p class="einheit-aktionen">
                            <?php // Nur, wenn es etwas zu bearbeiten gibt: Uebungen
                                  // kommen hier nicht dazu (Fallstrick 35). ?>
                            <?php if ($eintraege !== []): ?>
                                <a class="knopf zweit einheit-bearbeiten" href="<?= h(verlauf_url([
                                    'ansicht'    => 'einheiten',
                                    'bearbeiten' => (int)$e['id'],
                                ])) ?>">Einheit bearbeiten</a>
                            <?php endif; ?>
                            <button type="button" class="gefahr einheit-loeschen">
                                Einheit löschen
                            </button>
                        </p>
                        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php else: ?>


    <?php if ($uebungId !== null): ?>
        <?php // Der Weg zurueck aus der Einzelansicht -- ohne ihn stuende man
              // nach einem Klick aus "Einheiten" in einer Liste mit genau einer
              // Karte und saehe keinen Grund dafuer. ?>
        <p class="verlauf-zurueck">
            <?php if ($einheitId !== null): ?>
                <a href="<?= h(verlauf_url(['ansicht' => 'uebungen', 'einheit' => $einheitId])) ?>">
                    Alle Übungen dieser Einheit</a>
                ·
                <a href="<?= h(verlauf_url(['ansicht' => 'einheiten', 'offen' => $einheitId], 'einheit-' . $einheitId)) ?>">
                    Zur Einheit</a>
                ·
            <?php endif; ?>
            <a href="<?= h(verlauf_url(['ansicht' => 'uebungen'])) ?>">Alle Übungen</a>
        </p>
    <?php endif; ?>

    <?php if ($uebungen === [] && $uebungenGesamt > 0): ?>
        <div class="karte">
            <p><strong><?= $uebungId !== null
                ? 'Für diese Übung ist kein Wert protokolliert.'
                : ($gruppeId !== null
                    ? 'Zu dieser Muskelgruppe ist keine Übung mit Werten protokolliert.'
                    : 'In dieser Einheit ist keine Übung mit Werten protokolliert.') ?></strong></p>
            <p class="matt">
                Übungen, die ohne Gewicht, Distanz oder Zeit abgehakt wurden, haben
                keinen Verlauf.
            </p>
        </div>
    <?php elseif ($uebungen === []): ?>
        <div class="karte">
            <p><strong>Noch nichts protokolliert.</strong></p>
            <p class="matt">
                Sobald du beim Abhaken ein Gewicht einträgst — bei Ausdauerübungen
                eine Distanz oder eine Zeit —, entsteht hier ein Verlauf. Übungen
                ganz ohne Werte erscheinen nicht: für sie gäbe es nichts zu zeigen.
            </p>
        </div>
    <?php else: ?>
        <?php
        // Bei "Nach Muskelgruppe" steht ueber jeder Hauptgruppe und jeder
        // Untergruppe eine Ueberschrift, und jede Gruppe ist eine eigene Liste
        // -- eine Ueberschrift darf nicht IN einer <ul> stehen. Bei den anderen
        // Sortierungen gibt es genau eine Liste.
        $mitGruppen    = $sortierung === 'muskel' && $uebungId === null;
        $letzteHaupt   = false;
        $letzteGruppe  = false;
        $listeOffen    = false;
        ?>
            <?php foreach ($uebungen as $u): ?>
                <?php
                $hauptKey  = $u['hauptgruppe_name'] ?? null;
                $gruppeKey = $u['gruppe_id'] ?? null;
                if (!$listeOffen || ($mitGruppen && $gruppeKey !== $letzteGruppe)):
                    if ($listeOffen) {
                        echo '</ul>';
                    }
                    if ($mitGruppen && $hauptKey !== $letzteHaupt) {
                        echo '<h2 class="gruppen-titel">'
                            . ($hauptKey === null ? 'Ohne Muskelgruppe' : h((string)$hauptKey))
                            . '</h2>';
                    }
                    // Die Untergruppe nur, wo die Uebung an einer haengt --
                    // eine direkt an der Hauptgruppe haette sonst dieselbe
                    // Ueberschrift zweimal untereinander.
                    if ($mitGruppen && $u['gruppe_parent_id'] !== null) {
                        echo '<h3 class="gruppen-untertitel">' . h((string)$u['gruppe_name']) . '</h3>';
                    }
                    echo '<ul class="liste-schlicht">';
                    $listeOffen   = true;
                    $letzteHaupt  = $hauptKey;
                    $letzteGruppe = $gruppeKey;
                endif;

                $ausdauer = ist_ausdauer($u['erfassung'] ?? null);
                // Weniger ist besser (Fallstrick 34): Kurve gespiegelt, Farbe der
                // Differenz vertauscht, kein Volumen und kein 1RM.
                $unterstuetzt = !$ausdauer && ist_unterstuetzt($u['gewicht_wirkung'] ?? null);
                // Die Einzelansicht zeigt den GANZEN Verlauf, die Liste die
                // juengsten 60 je Uebung -- bei vielen Uebungen mit langer
                // Historie wuerde die Seite sonst mit jedem Monat schwerer.
                $verlaufGrenze = $uebungId !== null ? null : 60;
                $verlauf  = gewichts_verlauf(
                    $userId,
                    (int)$u['exercise_id'],
                    $ausdauer ? 'ausdauer' : 'kraft',
                    $verlaufGrenze
                );
                $gekappt = $verlaufGrenze !== null && count($verlauf) >= $verlaufGrenze;

                // Die Leitzahl der Kopfzeile: bei Kraft das Gewicht, bei
                // Ausdauer die Distanz. Beides ist die Zahl, an der man den
                // Fortschritt dieser Uebung abliest -- und beides traegt die
                // Kurve daneben.
                $feld    = $ausdauer ? 'distanz_m' : 'weight';
                $letzter = $verlauf === [] || end($verlauf)[$feld] === null
                    ? null : (float)end($verlauf)[$feld];
                $erster  = $verlauf === [] || $verlauf[0][$feld] === null
                    ? null : (float)$verlauf[0][$feld];
                $diff    = ($letzter !== null && $erster !== null) ? $letzter - $erster : 0.0;

                // Volumen und geschaetztes 1RM je Punkt -- bei Ausdauer
                // stattdessen Geschwindigkeit und Dauer. Ein Aufruf fuer den
                // ganzen Verlauf; gerechnet wird in PHP und nicht in SQL -- die
                // Datenmenge ist winzig, und die Formel gehoert dorthin, wo man
                // sie lesen kann.
                $saetzeJeLog = saetze_zu_logs(array_column($verlauf, 'log_id'));
                foreach ($verlauf as $i => $p) {
                    $s = $saetzeJeLog[(int)$p['log_id']] ?? [];
                    $verlauf[$i]['saetze']  = $s;

                    if ($ausdauer) {
                        $m = $p['distanz_m'] === null ? null : (int)$p['distanz_m'];
                        $t = $p['dauer_s']   === null ? null : (int)$p['dauer_s'];
                        // null und nicht 0, wo sich nichts rechnen laesst: Eine 0
                        // riss in die Kurve einen Einbruch, den es nie gab (§7.8).
                        $verlauf[$i]['tempo'] = tempo_kmh($m, $t);
                        $verlauf[$i]['zeit']  = $t;
                        continue;
                    }

                    $verlauf[$i]['volumen'] = saetze_volumen($s);
                    $verlauf[$i]['e1rm']    = saetze_e1rm($s);
                }
                $hatSaetze = $saetzeJeLog !== [];
                // Ob die Differenz im Kopf gut oder schlecht ist, haengt an der
                // Richtung: Bei Unterstuetzung ist ein Minus der Fortschritt.
                $diffGut = $unterstuetzt ? $diff < 0 : $diff > 0;
                ?>
                <li class="karte" id="uebung-<?= (int)$u['exercise_id'] ?>">
                    <?php // In der Einzelansicht aufgeklappt: Man kommt genau fuer die
                          // Details hierher, ein weiterer Tipp waere nur ein Hindernis. ?>
                    <details<?= $uebungId !== null ? ' open' : '' ?>>
                        <summary class="einheit-kopf">
                            <?php // Einzeilig: Der Kopf ist eine Flex-Zeile mit Kurve und
                                  // Eckdaten daneben -- ein Umbruch im Namen schoebe sie
                                  // auseinander. ?>
                            <span class="einheit-plan"><?= uebung_name_kurz(
                                (string)$u['name_de'], $u['name_en']
                            ) ?></span>
                            <?php // Dieselbe Kurve, anderes Feld: verlauf_kurve() nimmt
                                  // den Spaltennamen als Parameter und musste dafuer nicht
                                  // angefasst werden. ?>
                            <span class="verlauf-kurve-halter"><?php
                                verlauf_kurve($verlauf, $ausdauer ? 'distanz_m' : 'weight', $unterstuetzt);
                            ?></span>
                            <span class="matt einheit-eckdaten">
                                <?= h(format_decimal($letzter)) ?><?= $ausdauer ? ' m' : ' kg' ?>
                                <?php if (abs($diff) >= 0.01): ?>
                                    <?php // Das Vorzeichen bleibt ehrlich, die Farbe sagt,
                                          // ob es gut ist -- bei Unterstuetzung ist ein
                                          // Minus gruen. ?>
                                    <span class="<?= $diffGut ? 'diff-plus' : 'diff-minus' ?>">
                                        <?= $diff > 0 ? '+' : '−' ?><?= h(format_decimal(abs($diff))) ?>
                                    </span>
                                <?php endif; ?>
                                · <?= (int)$u['anzahl'] ?>×
                            </span>
                        </summary>

                        <?php // Volumen und 1RM stehen INNERHALB des aufgeklappten
                              // Bereichs. Die Zusammenfassungszeile bleibt der
                              // Gewichtskurve vorbehalten: Drei Kurven nebeneinander
                              // machen den <summary> am Handy unlesbar. ?>
                        <?php if ($ausdauer): ?>
                            <?php // Genau der Platz, an dem bei Kraft Volumen und 1RM
                                  // stehen. Geschwindigkeit ist die Kennzahl, an der man
                                  // Fortschritt sieht, wenn die Strecke gleich bleibt --
                                  // dieselbe Rolle, die das Volumen bei Kraft hat. ?>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">Geschwindigkeit</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'tempo'); ?>
                                </span>
                            </p>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">Dauer</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'zeit'); ?>
                                </span>
                            </p>
                        <?php elseif ($hatSaetze && !$unterstuetzt): ?>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">Volumen</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'volumen'); ?>
                                </span>
                            </p>
                            <p class="kurven-zeile">
                                <span class="kurve-titel">1RM (geschätzt)</span>
                                <span class="verlauf-kurve-halter">
                                    <?php verlauf_kurve($verlauf, 'e1rm'); ?>
                                </span>
                            </p>
                        <?php endif; ?>

                        <?php // Mit Sätzen, Volumen und 1RM hat die Tabelle fünf Spalten.
                              // Sie passen seit 2026-08-17 auch auf ein Handy: Das Datum
                              // trägt ein zweistelliges Jahr, und die Sätze brechen in
                              // `satz_gitter()` um, statt in einer Zeile zu stehen.
                              // Vorher rollte die Tabelle hier seitwärts.
                              //
                              // Der rollende Kasten bleibt als Netz — bei sehr vielen
                              // Sätzen oder sehr schmalen Geräten greift er weiterhin,
                              // und dann ist Rollen besser als eine zerdrückte Spalte. ?>
                        <?php // Bei Ausdauer sind es Datum, Intervalle, Distanz, Zeit und
                              // Pace -- ebenfalls fuenf. Die Pace steht als EINE Spalte mit
                              // zwei Zeilen darin (km/h ueber min/km), nach dem Muster von
                              // Datum und Uhrzeit daneben. Als sechste Spalte spraengte sie
                              // die Breite, die hier schon einmal knapp geworden ist. ?>
                        <div class="<?= $hatSaetze ? 'tabelle-rollt' : '' ?>">
                        <table class="verlauf-tabelle">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <?php if ($ausdauer): ?>
                                        <?php if ($hatSaetze): ?><th>Intervalle</th><?php endif; ?>
                                        <th class="spalte-zahl">Distanz</th>
                                        <th class="spalte-zahl">Zeit</th>
                                        <th class="spalte-zahl">Pace</th>
                                    <?php elseif ($hatSaetze && $unterstuetzt): ?>
                                        <th>Sätze</th>
                                        <th class="spalte-zahl">Unterstützung</th>
                                    <?php elseif ($hatSaetze): ?>
                                        <th>Sätze</th>
                                        <th class="spalte-zahl">Volumen</th>
                                        <th class="spalte-zahl">1RM</th>
                                        <th class="spalte-zahl">Gewicht</th>
                                    <?php else: ?>
                                        <th class="spalte-zahl">Gewicht</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($verlauf) as $v): ?>
                                <?php $vSession = $v['session_id'] === null ? null : (int)$v['session_id']; ?>
                                <?php // Die Zeile der gewaehlten Einheit ist markiert -- dafuer
                                      // grenzt man ein: um diese eine Einheit mit den
                                      // uebrigen zu vergleichen. ?>
                                <tr<?= $einheitId !== null && $vSession === $einheitId ? ' class="zeile-gewaehlt"' : '' ?>>
                                    <?php // Datum und Uhrzeit als zwei Elemente, damit das
                                          // Stylesheet sie am Handy untereinander und auf
                                          // breiten Schirmen nebeneinander setzen kann.
                                          //
                                          // Das Datum fuehrt zur Einheit zurueck, aufgeklappt --
                                          // das Gegenstueck zum Link am Uebungsnamen dort. ?>
                                    <td class="datum-spalte">
                                        <?php if ($vSession !== null): ?>
                                            <a href="<?= h(verlauf_url(
                                                ['ansicht' => 'einheiten', 'offen' => $vSession],
                                                'einheit-' . $vSession
                                            )) ?>">
                                        <?php endif; ?>
                                        <span class="datum-tag"><?= h(format_datum_kurz($v['performed_at'])) ?></span>
                                        <span class="datum-zeit"><?= h(format_zeit($v['performed_at'])) ?></span>
                                        <?php if ($vSession !== null): ?></a><?php endif; ?>
                                    </td>
                                    <?php if ($ausdauer): ?>
                                        <?php
                                        $vm = $v['distanz_m'] === null ? null : (int)$v['distanz_m'];
                                        $vt = $v['dauer_s']   === null ? null : (int)$v['dauer_s'];
                                        $vjeKm = sekunden_je_km($vm, $vt);
                                        ?>
                                        <?php if ($hatSaetze): ?>
                                            <td class="satz-spalte">
                                                <?= satz_gitter($v['saetze'], 'ausdauer') ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="spalte-zahl">
                                            <?= $vm === null
                                                ? '<span class="matt">—</span>'
                                                : h((string)$vm) . ' m' ?>
                                        </td>
                                        <td class="spalte-zahl">
                                            <?= $vt === null
                                                ? '<span class="matt">—</span>'
                                                : h(dauer_hms($vt)) ?>
                                        </td>
                                        <td class="spalte-zahl pace-spalte">
                                            <?php if ($v['tempo'] === null || $vjeKm === null): ?>
                                                <span class="matt">—</span>
                                            <?php else: ?>
                                                <span class="pace-kmh"><?=
                                                    h(format_decimal(round($v['tempo'], 1)))
                                                ?> km/h</span>
                                                <span class="pace-jekm"><?=
                                                    h(dauer_hms($vjeKm))
                                                ?> /km</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <?php if ($hatSaetze): ?>
                                            <td class="satz-spalte">
                                                <?= satz_gitter($v['saetze'], 'kraft') ?>
                                            </td>
                                        <?php endif; ?>
                                        <?php if ($hatSaetze && !$unterstuetzt): ?>
                                            <td class="spalte-zahl">
                                                <?= $v['volumen'] === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal(round($v['volumen']))) . ' kg' ?>
                                            </td>
                                            <td class="spalte-zahl">
                                                <?= $v['e1rm'] === null
                                                    ? '<span class="matt">—</span>'
                                                    : h(format_decimal(round($v['e1rm'], 1))) . ' kg' ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="spalte-zahl">
                                            <?= h(format_decimal((float)$v['weight'])) ?> kg
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>

                        <?php if ($gekappt && (int)$u['anzahl'] > count($verlauf)): ?>
                            <p class="matt">
                                Die letzten <?= count($verlauf) ?> von <?= (int)$u['anzahl'] ?> Einheiten —
                                <a href="<?= h(verlauf_url([
                                    'ansicht' => 'uebungen',
                                    'einheit' => $einheitId,
                                    'uebung'  => (int)$u['exercise_id'],
                                ])) ?>">alle anzeigen</a>
                            </p>
                        <?php endif; ?>

                        <p class="matt">
                            <?php // Bestwert heisst bei Ausdauer die WEITESTE Strecke einer
                                  // Einheit -- die Entsprechung zum schwersten Gewicht. Eine
                                  // beste Pace waere die naheliegende Alternative und
                                  // irrefuehrend: Sie ist auf 400 m fast immer besser als
                                  // auf 10 km. ?>
                            <?php // Bei Unterstuetzung das NIEDRIGSTE Gewicht (Fallstrick 34). ?>
                            Bestwert <?= $ausdauer
                                ? h((string)(int)($u['bestdistanz'] ?? 0)) . ' m'
                                : ($unterstuetzt
                                    ? h(format_decimal((float)$u['bestwert_min'])) . ' kg Unterstützung'
                                    : h(format_decimal((float)$u['bestwert'])) . ' kg') ?>
                        </p>

                        <?php // Der Vorbehalt gehört sichtbar an die Zahl und nicht in
                              // eine Fußnote: Ein geschätztes Maximum sieht aus wie ein
                              // gemessenes. Genau diese vorgetäuschte Genauigkeit hat
                              // 2026-08-07 das Wiederholungsfeld gekostet. ?>
                        <?php // Drei Absätze statt eines Blocks: Es sind drei Spalten und
                              // drei Begriffe, und untereinander findet man den gesuchten,
                              // ohne einen Fließtext zu lesen. ?>
                        <?php if ($ausdauer): ?>
                            <p class="matt">
                                <strong>Pace</strong> ist die Durchschnittsgeschwindigkeit
                                der ganzen Einheit und darunter dieselbe Angabe als Zeit
                                je Kilometer. Beides steht da, weil beides eine andere
                                Frage beantwortet: km/h steht am Gerät, min/km ist die
                                Zahl, in der man beim Laufen denkt.
                            </p>
                            <p class="matt">
                                <strong>Distanz</strong> und <strong>Zeit</strong> sind bei
                                mehreren Intervallen die <em>Summe</em> über die Einheit —
                                zwei Intervalle zu 1000 m sind 2000 gelaufene Meter. Die
                                Pace bezieht sich deshalb auch auf die ganze Einheit und
                                nicht auf das schnellste Intervall.
                            </p>
                        <?php elseif ($unterstuetzt): ?>
                            <p class="matt">
                                <strong>Unterstützung</strong> ist die Last, die die
                                Maschine abnimmt — <em>weniger ist besser</em>, bei 0 kg
                                geht die Übung ohne Hilfe. Je Einheit zählt der leichteste
                                Satz, und die Kurve steigt, wenn die Unterstützung sinkt.
                                Volumen und 1RM entfallen: Mit abgenommener Last gerechnet
                                sänken sie mit dem Fortschritt.
                            </p>
                        <?php elseif ($hatSaetze): ?>
                            <p class="matt">
                                <strong>Volumen</strong> ist die Summe aus Wiederholungen
                                mal Gewicht über alle Sätze einer Einheit — es steigt
                                auch dann, wenn das Gewicht gleich bleibt.
                            </p>
                            <p class="matt">
                                <strong>1RM</strong> ist das geschätzte
                                Einwiederholungsmaximum nach Epley
                                (kg × (1 + Wdh ÷ 30)) aus dem besten Satz — eine
                                Näherung, kein gemessener Wert.
                            </p>
                            <p class="matt">
                                <strong>Gewicht</strong> ist bei satzgenau erfassten
                                Übungen der Höchstwert dieser Einheit — also das Gewicht
                                des schwersten Satzes.
                            </p>
                        <?php endif; ?>
                    </details>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
