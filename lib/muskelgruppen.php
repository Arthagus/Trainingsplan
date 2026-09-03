<?php
declare(strict_types=1);

/**
 * Die Ordnung der Muskelgruppen -- und damit die Ordnung der Uebungen (§6.2).
 *
 * Auf admin_muscle_groups.php legt der Benutzer eine Reihenfolge fest. Diese
 * Reihenfolge ist keine Sache dieser einen Seite: Sie bestimmt, in welcher
 * Folge Uebungen ueberall dort erscheinen, wo der KATALOG aufgeblaettert wird
 * -- in der Uebungsverwaltung und in der Uebungsauswahl. Ansage des Benutzers
 * vom 2026-09-03: "Wenn ich Latissimus unter Trapez verschiebe, sollen alle
 * Uebungen in der Reihenfolge ausgegeben werden."
 *
 * Vorher stand dort schlicht `e.name_de`, also das Alphabet. Damit lagen
 * Latissimus- und Trapez-Uebungen wild durcheinander, und die muehsam
 * sortierte Gruppenliste hatte ausserhalb ihrer eigenen Seite keine Wirkung.
 *
 * NICHT betroffen sind Listen, die aus einem anderen Grund geordnet sind: die
 * Positionen eines Plans (dort zaehlt die Planreihenfolge), die
 * Tauschvorschlaege (naechstliegender Ersatz zuerst -- die machen es ueber
 * mg.sort_order ohnehin schon) und der Verlauf (zuletzt trainiert zuerst).
 */

/**
 * Sortiert wird nach der PRIMAERGRUPPE -- der Gruppe, wegen der man die Uebung
 * macht (§4). Die sekundaeren Gruppen bleiben aussen vor: Eine Uebung steht an
 * genau einer Stelle der Liste, und welche das ist, darf nicht davon abhaengen,
 * welche Nebengruppe zufaellig frueher einsortiert ist.
 *
 * Der Alias der Uebungstabelle MUSS `e` sein -- so heisst er an beiden
 * Aufrufstellen. Die drei Aliase hier (`psort`, `pgrp`, `pwurz`) sind
 * absichtlich sperrig, damit sie mit den `emg`/`mg`/`fmg`/`pmg` der
 * Filterbedingungen daneben nicht kollidieren.
 *
 * LEFT JOIN und nicht JOIN, und das ist keine Vorsicht, sondern Pflicht: Eine
 * AUSDAUERuebung hat seit 1.4.1 GAR KEINE Muskelgruppe (§6.3, Fallstrick 30).
 * Ein innerer Verbund liesse Laufband und Crosstrainer aus der Uebungsliste
 * verschwinden -- lautlos, und es saehe aus, als seien sie geloescht.
 *
 * Vervielfacht wird dabei nichts: `idx_emg_one_primary` laesst je Uebung genau
 * eine Primaerzeile zu (Fallstrick 10).
 */
const MG_SORT_JOIN = '
        LEFT JOIN exercise_muscle_groups psort
               ON psort.exercise_id = e.id AND psort.is_primary = 1
        LEFT JOIN muscle_groups pgrp
               ON pgrp.id = psort.muscle_group_id
        LEFT JOIN muscle_groups pwurz
               ON pwurz.id = COALESCE(pgrp.parent_id, pgrp.id)';

/**
 * Dieselbe Ordnung, die admin_muscle_groups.php anzeigt -- und sie wird GENAUSO
 * gebildet: erst die Hauptgruppe, dann ihre Untergruppen.
 *
 * Ein blosses `pgrp.sort_order` waere der naheliegende Griff und stimmte nur
 * meistens. `sort_order` ist ueber ALLE Gruppen hinweg vergeben, und nach einem
 * Umsortieren steht darin tatsaechlich die Tiefensuche. Eine NEU angelegte
 * Untergruppe bekommt aber `MAX + 10` (api/muscle_groups.php) und haengt damit
 * global ganz hinten, waehrend die Seite sie laengst unter ihrer Hauptgruppe
 * zeigt -- ihre Uebungen stuenden dann am Ende der Liste statt bei den
 * Geschwistern. Deshalb wird hier wie im Seitenaufbau erst nach der WURZEL
 * geordnet und dann innerhalb.
 *
 * Die vier Stufen:
 *   1. Uebungen ohne Primaergruppe ganz nach hinten (die Ausdauerfaelle) --
 *      sonst stuenden sie durch die NULL-Werte vorneweg.
 *   2. Die Hauptgruppe in ihrer Reihenfolge; `name_de` nur als Gleichstand.
 *   3. Innerhalb der Hauptgruppe zuerst die Uebungen, die direkt an IHR
 *      haengen, danach die der Untergruppen -- genau wie die Karte auf
 *      admin_muscle_groups.php ueber ihren Kindern steht.
 *   4. Die Untergruppen in ihrer Reihenfolge.
 *
 * Der Uebungsname gehoert NICHT hierher: Die Aufrufstelle haengt ihn an, und an
 * einer davon steht noch etwas dazwischen.
 */
const MG_SORT_ORDER = '
        CASE WHEN pgrp.id IS NULL THEN 1 ELSE 0 END,
        pwurz.sort_order, pwurz.name_de,
        CASE WHEN pgrp.parent_id IS NULL THEN 0 ELSE 1 END,
        pgrp.sort_order, pgrp.name_de';
