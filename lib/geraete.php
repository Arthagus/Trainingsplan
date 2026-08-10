<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Das Trainingsgeraet einer Uebung (§6.3).
 *
 * Beantwortet die Frage, die im Studio als erste kommt: Steht man an der
 * Maschine, am Kabelzug, oder holt man die Kurzhanteln? Die Muskelgruppen sagen
 * WAS trainiert wird, `exercises.focus` sagt WIE -- hier steht das WOMIT.
 *
 * **Bewusst eine Codeliste und keine Tabelle.** Anders als bei den
 * Muskelgruppen ist die Menge klein, geschlossen und aendert sich nicht mit dem
 * Datenbestand. Eine Tabelle brauchte eine Verwaltungsseite, eine API und einen
 * Loeschschutz gegen verwaiste Zuordnungen -- fuer sieben feste Werte ist das
 * ein schlechtes Geschaeft.
 *
 * **Und bewusst kein CHECK-Constraint auf der Spalte.** SQLite kann eine
 * CHECK-Klausel nur ueber einen kompletten Tabellen-Neuaufbau aendern. Ein
 * achter Geraetetyp soll eine Zeile hier kosten, keine Migration. Geprueft wird
 * in `api/exercises.php` gegen GERAETE -- dasselbe Muster wie die
 * Filter-Whitelist in `admin_exercises.php`.
 *
 * Was NICHT als eigener Typ auftaucht, weil es ins Feld "Ausfuehrung" gehoert:
 * SZ-Stange und Trap-Bar sind Langhantel; Klimmzugstange, Dip-Barren und
 * Schlingentrainer sind Koerpergewicht. Sonst zersplittert die Liste, und der
 * Filter verliert genau die Uebersicht, fuer die es ihn gibt.
 *
 * Die Symbole zu diesen Schluesseln stehen in `lib/view_geraet_symbole.php`.
 * Wer hier einen Wert ergaenzt, ergaenzt dort das passende <symbol> -- ohne das
 * bleibt das Abzeichen leer.
 */
const GERAETE = [
    'maschine'    => 'Maschine',
    'multipresse' => 'Multipresse',
    'kabel'       => 'Kabelzug',
    'langhantel'  => 'Langhantel',
    'kurzhantel'  => 'Kurzhantel',
    'kettlebell'  => 'Kettlebell',
    'koerper'     => 'Körpergewicht',
];

/**
 * Der dritte Zustand des Geraetefilters neben "egal" und "dieses Geraet":
 * findet die Uebungen OHNE Angabe.
 *
 * Steht hier und nicht auf der Seite, weil ihn zwei Stellen brauchen -- der
 * Filter der Uebungsverwaltung und die Uebungsauswahl in api/plans.php. Ohne
 * ihn waeren die Uebungen aus der Zeit vor diesem Feld nur durch
 * Durchblaettern zu finden.
 */
const GERAET_LEER = '_leer';

/** Ist der Code einer der bekannten Geraetetypen? */
function geraet_gueltig(string $code): bool {
    return array_key_exists($code, GERAETE);
}

/** Beschriftung zum Code, oder null fuer leer und unbekannt. */
function geraet_label(?string $code): ?string {
    if ($code === null) {
        return null;
    }
    return GERAETE[trim($code)] ?? null;
}

/**
 * Das fertige Abzeichen: Symbol plus Beschriftung.
 *
 * Das Symbol traegt aria-hidden -- lesbar ist der Text daneben. Ein Symbol
 * allein spart zwar Platz, verlangt aber, dass man sieben Piktogramme
 * auswendig kennt.
 *
 * Fehlt das Geraet, sagt das Abzeichen das ausdruecklich. Uebungen aus der Zeit
 * vor diesem Feld tragen keinen Wert, und ein stillschweigend leerer Platz
 * waere genau die Sorte Luecke, die niemand nachpflegt.
 */
function geraet_abzeichen(?string $code): string {
    $code = $code === null ? '' : trim($code);

    if ($code === '') {
        return '<span class="abzeichen geraet-fehlt">Gerät fehlt</span>';
    }

    $label = GERAETE[$code] ?? null;
    if ($label === null) {
        // Der Validator laesst das nicht zu; denkbar nur nach dem Zurueckspielen
        // einer Sicherung, die einen inzwischen entfernten Typ kennt. Dann soll
        // der Wert sichtbar bleiben statt lautlos zu verschwinden.
        return '<span class="abzeichen geraet-fehlt">Unbekanntes Gerät ('
            . h($code) . ')</span>';
    }

    return '<span class="abzeichen geraet">'
        . '<svg class="geraet-symbol" aria-hidden="true" focusable="false">'
        . '<use href="#geraet-' . h($code) . '"></use></svg>'
        . h($label) . '</span>';
}
