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

    <?php // Gewichtsblock mit Plattenfugen, daraus schraeg der Hebelarm.
          //
          // Der Arm lief bis 1.0.18 als abgewinkelter Rahmen oben heraus --
          // das sah nach Handkaffeemuehle aus und war bei 13px ohnehin ein
          // Fleck. Eine einzige Diagonale liest sich wie der Hebel einer
          // Brustpresse und kostet einen Strich statt dreier. ?>
    <symbol id="geraet-maschine" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <rect x="4" y="7" width="7" height="12" rx="1"></rect>
        <path d="M4 11h7M4 15h7"></path>
        <path d="M7.5 10 19 3"></path>
    </symbol>

    <?php // Zwei Fuehrungsschienen, dazwischen die Stange mit ihren Klemmen. ?>
    <symbol id="geraet-multipresse" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 3v18M18 3v18"></path>
        <path d="M3 12h18"></path>
        <path d="M9 10v4M15 10v4"></path>
    </symbol>

    <?php // Umlenkrolle, Seil, Latzugstange mit abgewinkelten Enden. Die Enden
          // sind der Unterschied zur blossen Griffstange: Sie machen aus zwei
          // Strichen ein erkennbares Geraet, ohne einen dritten zu kosten.
          //
          // Die Zeichnung fuellt die Hoehe des viewBox absichtlich aus (2.8 bis
          // 21.2, Mitte also 12). Vorher endete sie bei 15.5 und lag damit im
          // oberen Drittel -- das Abzeichen zentriert den KASTEN, nicht die
          // Zeichnung darin, und das Symbol sass sichtbar zu hoch neben dem
          // Text. Gilt fuer jedes weitere Symbol: Der Schwerpunkt der Striche
          // gehoert auf y=12, sonst rutscht es aus der Zeile. ?>
    <symbol id="geraet-kabel" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="5" r="2.2"></circle>
        <path d="M12 7.2V17.7"></path>
        <path d="M4.5 17.7h15"></path>
        <path d="M4.5 17.7v3.5M19.5 17.7v3.5"></path>
    </symbol>

    <?php // Lange Stange, je Seite eine grosse Scheibe innen und eine kleinere
          // aussen -- buendig aneinander, wie sie auf der echten Stange sitzen.
          //
          // Die Scheiben sitzen weit aussen, sodass nur ein Rest Stange
          // heraussteht und in der Mitte viel blanke Stange bleibt. Das laesst
          // das Symbol gestreckt wirken und ist der Unterschied zur Kurzhantel:
          // Dort sitzen breite Bloecke direkt an den Enden einer kurzen Stange.
          // Gefuellte Scheiben statt Striche, weil eine Flaeche bei 19px noch
          // Form hat, wo duenne Striche verschmelzen. ?>
    <symbol id="geraet-langhantel" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M0.5 12h23"></path>
        <rect x="3.3" y="7" width="2.4" height="10" rx="0.7"
              fill="currentColor" stroke="none"></rect>
        <rect x="1.5" y="9" width="1.8" height="6" rx="0.5"
              fill="currentColor" stroke="none"></rect>
        <rect x="18.3" y="7" width="2.4" height="10" rx="0.7"
              fill="currentColor" stroke="none"></rect>
        <rect x="20.7" y="9" width="1.8" height="6" rx="0.5"
              fill="currentColor" stroke="none"></rect>
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

    <?php // --- Die drei Ausdauergeraete (1.4.0) ------------------------------
          // Alle drei sind Standgeraete und werden von der Seite gezeigt: eine
          // waagerechte Grundlinie, darueber das Kennzeichen, an dem man sie im
          // Studio auseinanderhaelt -- Konsole am Band, Schwungrad plus Griffe
          // am Crosstrainer, Schiene plus Sitz am Ruderer. ?>

    <?php // Laufflaeche mit Standfuss, Saeule und Konsole. ?>
    <symbol id="geraet-laufband" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 20h13.5"></path>
        <path d="M4.6 16.6h10.3"></path>
        <path d="M4.6 16.6L3 20M14.9 16.6L16.5 20"></path>
        <path d="M14.9 16.6V4.5"></path>
        <path d="M11.4 4.5h7.2"></path>
    </symbol>

    <?php // Schwungrad, Pedalarm und die zwei beweglichen Griffe. ?>
    <symbol id="geraet-crosstrainer" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <circle cx="5.4" cy="15.8" r="3.3"></circle>
        <path d="M17.6 19.5V4.5"></path>
        <path d="M5.4 15.8h9.4"></path>
        <path d="M12.4 18.4h4.2"></path>
        <path d="M17.6 6.6l-4.3 3.2M17.6 6.6l3.4 2.6"></path>
    </symbol>

    <?php // Schwungrad vorn, Zugseil zum Griff, Sitz auf der Schiene. ?>
    <symbol id="geraet-rudergeraet" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
        <circle cx="5" cy="8.5" r="3.2"></circle>
        <path d="M8.2 8.5h6.3"></path>
        <path d="M14.5 6.7v3.6"></path>
        <path d="M3 16.5h18"></path>
        <rect x="10" y="12.2" width="5.5" height="2.8" rx="1"></rect>
        <path d="M4.5 16.5v3.5M19.5 16.5v3.5"></path>
    </symbol>

</defs></svg>

<?php // Die Beschriftungen fuer das JS. Kein <script> mit Zuweisung, sondern
      // application/json: der Inhalt wird nie ausgefuehrt, und JSON_HEX_TAG
      // schliesst aus, dass eine kuenftige Beschriftung das Tag sprengt. ?>
<script type="application/json" id="geraete-daten"><?=
    json_encode(GERAETE, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
                       | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)
?></script>
