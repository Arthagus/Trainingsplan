<?php
declare(strict_types=1);

/**
 * Der Dialog, der ein Uebungsbild gross zeigt -- samt Name und Beschreibung.
 *
 * Als Partial, weil ihn drei Seiten brauchen: das Training (§7.4), die
 * Uebungsverwaltung und die Planverwaltung. Bedient wird er ueber
 * bildGrossZeigen() aus assets/app.js; wer den Dialog nicht einbindet, bekommt
 * dort auch keinen Fehler, sondern schlicht keine Wirkung.
 */
?>
<dialog id="info-dialog">
    <h2 id="info-titel"></h2>
    <?php // title als Hinweis auf den Klick zum Schliessen -- der Knopf unten
          // bleibt der Weg mit der Tastatur. ?>
    <img id="info-bild" alt="" title="Zum Schließen antippen" hidden>
    <p id="info-text"></p>
    <p><button type="button" id="info-schliessen" class="leise">Schließen</button></p>
</dialog>
