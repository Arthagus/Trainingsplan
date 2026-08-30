<?php
declare(strict_types=1);

/**
 * Die Uebungsauswahl (§6.4) als geteiltes Partial.
 *
 * Bis 1.4.1 stand dieses Markup inline in plans.php, mit der Begruendung "sie
 * wird nur hier gebraucht". Seit 1.4.2 stimmt das nicht mehr: Die
 * Trainingsansicht haengt spontan eine Uebung an (§7.6) und braucht dieselbe
 * Maske. Zwei Fassungen davon waeren irgendwann verschieden -- dieselbe
 * Ueberlegung, aus der sich die beiden Tauschfenster vorschlagMarkup() teilen.
 *
 * Die Logik dazu steht in assets/app.js (uebungWaehlenEinrichten()); die
 * Treffer rendert vorschlagMarkup(), also dieselbe Darstellung wie beim Tausch.
 *
 * Erwartet im Gueltigkeitsbereich:
 *   $hauptGruppen  Hauptgruppen in Sortierreihenfolge
 *   $unterGruppen  Untergruppen, geschluesselt nach parent_id
 */
?>
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
