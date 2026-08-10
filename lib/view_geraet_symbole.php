<?php
declare(strict_types=1);

require_once __DIR__ . '/geraete.php';

/**
 * Der Symbolvorrat fuer die Geraete-Abzeichen -- einmal je Seite.
 *
 * Die Abzeichen entstehen an ZWEI Stellen: serverseitig in den Listen
 * (geraet_abzeichen() in lib/geraete.php) und clientseitig in vorschlagMarkup()
 * aus assets/app.js, das den Tauschdialog und die Uebungsauswahl fuellt. Zwei
 * Kopien der Symbole und der Beschriftungen waeren ein sicherer Weg in
 * Abweichungen, deshalb steht beides genau hier:
 *
 *   - die <symbol>-Definitionen, referenziert per <use href="#geraet-...">
 *   - die Beschriftungstabelle als JSON, gelesen von geraetAbzeichen() im JS
 *
 * Eingebunden aus lib/view_header.php, direkt nach <body>. Ein <use> wirkt nur
 * innerhalb desselben Dokuments -- eine ausgelagerte Sprite-Datei waere ein
 * zusaetzlicher Netzabruf und im Offline-Fall die erste Luecke.
 *
 * Die Symbole zeichnen mit stroke="currentColor" und fill="none": so erben sie
 * die Textfarbe des Abzeichens und bleiben in jedem Kontext lesbar.
 */
?>
<svg class="symbol-vorrat" aria-hidden="true" focusable="false"
     xmlns="http://www.w3.org/2000/svg"><defs>

    <?php // Gewichtsblock mit Plattenfugen, darueber der Rahmenarm. ?>
    <symbol id="geraet-maschine" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <rect x="4" y="7" width="7" height="12" rx="1"></rect>
        <path d="M4 11h7M4 15h7"></path>
        <path d="M7.5 7V4h12v6"></path>
    </symbol>

    <?php // Zwei Fuehrungsschienen, dazwischen die Stange mit ihren Klemmen. ?>
    <symbol id="geraet-multipresse" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 3v18M18 3v18"></path>
        <path d="M3 12h18"></path>
        <path d="M9 10v4M15 10v4"></path>
    </symbol>

    <?php // Umlenkrolle, Seil, Griffstange. ?>
    <symbol id="geraet-kabel" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="5" r="2.5"></circle>
        <path d="M12 7.5V17"></path>
        <path d="M8 17h8"></path>
    </symbol>

    <?php // Lange Stange, je Seite zwei Scheiben. ?>
    <symbol id="geraet-langhantel" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 12h20"></path>
        <path d="M8 6v12M5 8.5v7"></path>
        <path d="M16 6v12M19 8.5v7"></path>
    </symbol>

    <?php // Kurze Stange, je Seite ein Block -- die Abgrenzung zur Langhantel. ?>
    <symbol id="geraet-kurzhantel" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M8 12h8"></path>
        <rect x="3" y="8" width="4.5" height="8" rx="1.2"></rect>
        <rect x="16.5" y="8" width="4.5" height="8" rx="1.2"></rect>
    </symbol>

    <?php // Buegelgriff ueber rundem Koerper. ?>
    <symbol id="geraet-kettlebell" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M8.5 9.5V7.5a3.5 3.5 0 0 1 7 0v2"></path>
        <circle cx="12" cy="15" r="5.5"></circle>
    </symbol>

    <?php // Strichfigur: kein Geraet, nur der eigene Koerper. ?>
    <symbol id="geraet-koerper" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="4.5" r="2.5"></circle>
        <path d="M12 7.5v6.5"></path>
        <path d="M6 10.5h12"></path>
        <path d="M12 14l-3.5 6M12 14l3.5 6"></path>
    </symbol>

</defs></svg>

<?php // Die Beschriftungen fuer das JS. Kein <script> mit Zuweisung, sondern
      // application/json: der Inhalt wird nie ausgefuehrt, und JSON_HEX_TAG
      // schliesst aus, dass eine kuenftige Beschriftung das Tag sprengt. ?>
<script type="application/json" id="geraete-daten"><?=
    json_encode(GERAETE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                       | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
?></script>
