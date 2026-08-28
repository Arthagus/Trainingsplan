<?php
declare(strict_types=1);

/**
 * Der Dialog zum Umbenennen eines Splits oder einer Vorlage (§6.4, seit 1.3.2).
 *
 * Als Partial, weil ihn beide Splitseiten brauchen -- splits.php und
 * admin_splits.php. Bedient wird er ueber splitUmbenennenFragen() aus
 * assets/app.js; wer den Dialog nicht einbindet, bekommt dort auch keinen
 * Fehler, sondern schlicht keine Wirkung.
 *
 * Er ersetzt das frueher dauerhaft sichtbare Namensfeld im Kartenkopf: Dort
 * steht seit 1.3.2 das Auswahlfeld, mit dem man zwischen den Splits wechselt.
 * Ein Feld, das zugleich Titel und Eingabe ist, laedt zum Tippen ein und
 * speichert dann doch erst, wenn man den Knopf daneben findet.
 *
 * Die Ueberschrift kommt von der Aufrufstelle ("Split umbenennen" bzw.
 * "Vorlage umbenennen") -- ein Satz ueber eine Vorlage, der sie "Split" nennt,
 * waere an genau der Stelle falsch, an der der Unterschied zaehlt.
 */
?>
<dialog id="name-dialog">
    <h2 id="name-titel">Umbenennen</h2>
    <label for="name-feld" class="nur-lesbar">Neuer Name</label>
    <?php // maxlength wie SPLIT_NAME_MAX in lib/splits.php. Es ist die
          // Bequemlichkeit, nicht die Pruefung -- die steht serverseitig in
          // split_name_pruefen() und antwortet mit 422. ?>
    <input type="text" id="name-feld" maxlength="80" autocomplete="off">
    <p id="name-fehler" class="feld-fehler" role="alert" hidden></p>
    <p class="gruppe-knoepfe">
        <button type="button" id="name-speichern">Speichern</button>
        <button type="button" id="name-abbrechen" class="leise">Abbrechen</button>
    </p>
</dialog>
