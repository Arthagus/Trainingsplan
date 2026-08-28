<?php
declare(strict_types=1);

/**
 * Der Dialog, der einen Split als reinen Text zeigt (§6.4).
 *
 * Als Partial, weil ihn zwei Seiten brauchen: splits.php (eigene Splits und
 * der Kasten "Vorlage uebernehmen") und admin_splits.php (der Katalog).
 * Bedient wird er ueber splitTextZeigen() aus assets/app.js; wer den Dialog
 * nicht einbindet, bekommt dort auch keinen Fehler, sondern schlicht keine
 * Wirkung -- dasselbe Verhalten wie beim Bilddialog.
 *
 * EIN Dialog fuer ALLE Karten, gefuellt beim Oeffnen -- nicht einer je Split.
 * Bei einem Dutzend Karten waeren das ein Dutzend <dialog> mit demselben
 * Inhalt in der Seite.
 */
?>
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
