<?php
declare(strict_types=1);

/**
 * Eine Splitkarte (§6.4) -- geteilt zwischen splits.php und admin_splits.php.
 *
 * Die beiden Seiten zeigen verschiedene Bestaende und duerfen darin
 * Verschiedenes, aber die Karte ist dieselbe: Name, Planvorschau, eine Reihe
 * Knoepfe, eine Abschlusszeile. Zweimal geschrieben waere sie zweimal zu
 * pflegen, und die zweite Fassung wuerde beim naechsten Umbau vergessen.
 *
 * Der Unterschied steckt in $eigener, und er ist der ganze Punkt der Trennung:
 *
 *   $eigener = true    splits.php, "Meine Splits". Der Split gehoert dem
 *                      Aufrufer. Hier wird trainiert, hier wirkt ein
 *                      dauerhafter Tausch, hier laeuft eine Rotation -- und
 *                      nur hier gibt es Herkunft und "Auf Vorlage
 *                      zurücksetzen".
 *   $eigener = false   admin_splits.php, der Katalog. Eine Vorlage gehoert
 *                      niemandem, auf ihr trainiert niemand, und sie erreicht
 *                      ohnehin nur, wer die Seite oeffnen darf -- die steht
 *                      hinter require_admin().
 *
 * Deshalb steht in dieser Datei KEIN is_admin(): Wer die Karte einer Vorlage
 * sieht, darf sie auch bearbeiten. Die Rechtefrage ist am Seitenkopf
 * beantwortet und nicht in jeder Zeile neu.
 *
 * "Zu mir kopieren" gibt es hier ebenfalls nicht. Seit 1.2.23 laeuft das ueber
 * den Kasten "Vorlage übernehmen" auf splits.php -- eine Handlung, ein Ort.
 *
 * SEIT 1.3.2 STEHT IMMER NUR EINE KARTE SICHTBAR DA, und im Kopf sitzt statt
 * des Namensfelds ein Auswahlfeld ueber alle Splits der Liste (Ansage des
 * Benutzers). Bei einem halben Dutzend Splits war die Seite vorher eine lange
 * Rolle aus Karten, von denen fuenf nur im Weg standen.
 *
 * GERENDERT WERDEN TROTZDEM ALLE, die uebrigen mit [hidden]. Der Wechsel ist
 * damit ein Umschalten im Browser und kein Seitenaufbau: kein Netzaufruf, kein
 * Warten, und er funktioniert im Studio auch ohne Verbindung. Die Daten stehen
 * ohnehin schon in der Seite -- Planvorschau, Text, Herkunft.
 *
 * Der Preis ist das Auswahlfeld in JEDER Karte, mit jedes Mal derselben Liste.
 * Das ist Absicht: Die sichtbare Karte traegt dann immer schon den richtigen
 * Eintrag, und das Umschalten ist ein blosses Vertauschen von [hidden] --
 * gegenueber einem einzelnen Feld ueber der Liste spart das den Gleichlauf
 * zwischen Feld und Karte, der sonst auseinanderlaeuft.
 */

/**
 * Die Liste der Splitkarten -- genau eine davon sichtbar.
 *
 * HIER und nicht in der Seite, weil beide Seiten dieselbe Frage beantworten
 * muessen: Welche Karte steht offen? Zweimal geschrieben waere es zweimal zu
 * pflegen.
 *
 * @param list<array<string,mixed>> $splits   Die Karten dieser Liste
 * @param int                       $zeigeId  Welche offen steht; 0 oder
 *                                            unbekannt = die erste
 */
function split_liste(
    array $splits,
    array $planNamen,
    array $splitTexte,
    bool $eigener,
    int $aktivId,
    int $zeigeId,
    bool $gesperrt,
    array $vorlagenListe = [],
    array $vorlageStand = []
): void {
    if ($splits === []) {
        return;
    }

    // Ein unbekanntes $zeigeId faellt auf die erste Karte zurueck -- dieselbe
    // Haltung wie bei ?split= in plans.php: Die Liste entscheidet, nicht der
    // Parameter. Eine Seite ganz ohne sichtbare Karte waere der schlechteste
    // Ausgang, und genau der entstuende bei einem geloeschten Split.
    $ids = array_map(static fn(array $sp): int => (int)$sp['id'], $splits);
    if (!in_array($zeigeId, $ids, true)) {
        $zeigeId = $ids[0];
    }
    ?>
    <ul class="liste-schlicht split-liste" data-aktiv="<?= $aktivId ?>">
        <?php foreach ($splits as $sp): ?>
            <?php split_karte($sp, $planNamen, $splitTexte, $eigener, $aktivId, $gesperrt,
                              $splits, (int)$sp['id'] === $zeigeId,
                              $vorlagenListe, $vorlageStand); ?>
        <?php endforeach; ?>
    </ul>
    <?php
}

/**
 * @param array<string,mixed>              $sp        Zeile aus splits
 * @param array<int,list<string>>          $planNamen split_plan_namen()
 * @param array<int,string>                $splitTexte split_texte()
 * @param bool                             $eigener   siehe oben
 * @param int                              $aktivId   der aktive Split, 0 = keiner
 * @param bool                             $gesperrt  laufende Einheit
 * @param list<array<string,mixed>>        $vorlagenListe Katalog fuer die Herkunft
 * @param array<int,array<string,mixed>>   $vorlageStand  vorlage_stand()
 * @param list<array<string,mixed>>        $geschwister   alle Karten der Liste
 * @param bool                             $sichtbar      steht sie offen?
 *
 * Aufgerufen wird sie aus split_liste() und nur von dort -- die beiden letzten
 * Parameter ergeben nur im Zusammenhang der ganzen Liste einen Sinn.
 */
function split_karte(
    array $sp,
    array $planNamen,
    array $splitTexte,
    bool $eigener,
    int $aktivId,
    bool $gesperrt,
    array $geschwister,
    bool $sichtbar,
    array $vorlagenListe = [],
    array $vorlageStand = []
): void {
    $id     = (int)$sp['id'];
    $plaene = $planNamen[$id] ?? [];
    ?>
    <li class="karte split <?= $eigener && $id === $aktivId ? 'ist-aktiv' : '' ?>"
        data-id="<?= $id ?>" data-name="<?= h((string)$sp['name']) ?>"
        <?= $sichtbar ? '' : 'hidden' ?>>
        <div class="gruppe-zeile split-kopf">
            <div class="gruppe-felder">
                <?php // Ein Auswahlfeld nur, wenn es etwas zu waehlen GIBT.
                      // Bei einem einzigen Split waere es eine Liste mit einem
                      // Eintrag -- dieselbe Sorte wirkungsloses Bedienelement
                      // wie ein Knopf, der nichts tut.
                      //
                      // Der Name steht NICHT mehr als Eingabefeld da: Umbenannt
                      // wird seit 1.3.2 im Dialog hinter "Umbenennen". Ein Feld,
                      // das zugleich Titel und Eingabe ist, laedt zum Tippen ein
                      // und speichert dann doch erst auf Knopfdruck. ?>
                <?php if (count($geschwister) > 1): ?>
                    <select class="split-wechsel" aria-label="Angezeigter Split">
                        <?php foreach ($geschwister as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"
                                    <?= (int)$g['id'] === $id ? 'selected' : '' ?>>
                                <?= h((string)$g['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <strong class="split-titel"><?= h((string)$sp['name']) ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($eigener && $id === $aktivId): ?>
                <span class="abzeichen">aktiv</span>
            <?php endif; ?>
        </div>

        <?php // Die Klasse traegt die Vorschau nicht zur Zierde: splits.js
              // liest sie aus, um in der Rueckfrage vor dem Zuruecksetzen zu
              // sagen, wie der Split danach aussieht. Seit 1.2.23 steht die
              // Vorlage nicht mehr als Karte auf derselben Seite -- die
              // Vorschau kommt jetzt aus dem Auswahlfeld der Herkunft
              // (data-plaene), siehe unten. ?>
        <p class="matt split-plaene">
            <?php if ($plaene === []): ?>
                Noch kein Plan darin.
            <?php else: ?>
                <?= count($plaene) ?> <?= count($plaene) === 1 ? 'Plan' : 'Pläne' ?>:
                <?= h(implode(' → ', $plaene)) ?> ↺
            <?php endif; ?>
        </p>
        <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>

        <?php // Der Text liegt fertig in der Karte, unsichtbar. Das JS holt ihn
              // beim Antippen ueber .textContent -- damit steht in der
              // Zwischenablage genau das, was hier steht, ohne Umweg ueber
              // einen Netzaufruf oder eine zweite Zusammenbau-Vorschrift im JS.
              //
              // <pre> und nicht <div>: Der Text lebt von seinen
              // Zeilenumbruechen, und textContent eines <div> mit umgebrochener
              // Quelltextzeile brachte sie durcheinander. ?>
        <pre class="split-text-inhalt" hidden><?= h($splitTexte[$id] ?? '') ?></pre>

        <div class="gruppe-knoepfe">
            <?php if ($eigener): ?>
                <?php if ($id !== $aktivId): ?>
                    <button type="button" class="split-aktivieren" <?= $gesperrt ? 'disabled' : '' ?>>
                        Diesen trainieren
                    </button>
                <?php endif; ?>
                <a class="knopf zweit" href="<?= h(base_path()) ?>/plans.php?split=<?= $id ?>">
                    Pläne bearbeiten
                </a>
                <button type="button" class="leise split-speichern">Umbenennen</button>
                <button type="button" class="leise split-duplizieren">Duplizieren</button>
            <?php else: ?>
                <button type="button" class="leise split-speichern">Umbenennen</button>
                <a class="knopf zweit" href="<?= h(base_path()) ?>/plans.php?split=<?= $id ?>">
                    Vorlage bearbeiten
                </a>
                <?php // Duplizieren legt eine ZWEITE VORLAGE an, nicht eine
                      // persoenliche Kopie -- dafuer steht der Kasten auf
                      // splits.php. Der Weg fuer eine Variante im Katalog:
                      // duplizieren, umbenennen, bearbeiten. ?>
                <button type="button" class="leise vorlage-duplizieren">Duplizieren</button>
            <?php endif; ?>

            <?php // "Leise" und hinter den Aktionen: Das Kopieren als Text ist
                  // ein Werkzeug daneben, kein Schritt im Ablauf. ?>
            <button type="button" class="leise split-text">Als Text</button>
        </div>

        <?php // --- Herkunft und Abgleich (§6.4, seit 1.2.11) ----------------
              //
              // Nur an eigenen Splits und nur, wenn es ueberhaupt Vorlagen
              // gibt: Eine Vorlage hat selbst keine Vorlage, und ohne Katalog
              // stuende hier ein leeres Auswahlfeld.
              //
              // Das Auswahlfeld steht dauerhaft da und nicht hinter einem
              // Knopf. Es ist die einzige Stelle, an der ein vor 1.2.11
              // entstandener Split seine Herkunft bekommt -- versteckt faende
              // sie nur, wer schon weiss, dass es sie gibt.
              //
              // SEIT 1.2.13 IM EIGENEN RAHMEN, und seit 1.2.14 zwischen den
              // beiden Knopfreihen: Die Herkunft ist eine Einstellung, die man
              // selten anfasst. Als volle Zeile ueber den Knoepfen zog sie
              // Aufmerksamkeit, die ihr nicht zusteht; der Rahmen sagt "eigene
              // Funktion", die kleine Ueberschrift haelt sie leise.
              //
              // GANZ ANS ENDE gehoert sie trotzdem nicht: Dort stand sie in
              // 1.2.13 und schob "Loeschen" nach oben. Die Abschlusszeile ist
              // die letzte Zeile der Karte, und was danach kommt, nimmt ihr
              // genau das. ?>
        <?php if ($eigener && $vorlagenListe !== []):
            $stand      = $vorlageStand[$id] ?? null;
            $herkunft   = $stand === null ? 0 : $stand['vorlage_id'];
            $abweichung = $stand !== null && $stand['weicht_ab'];
            $namenAb    = $stand !== null && $stand['namen_weichen_ab'];
        ?>
        <div class="split-herkunft">
            <?php // Der sichtbare Text IM label ist zugleich der zugaengliche
                  // Name des Auswahlfelds -- deshalb steht hier kein
                  // aria-label mehr: Es haette die Ueberschrift verdeckt,
                  // statt sie zu ergaenzen. ?>
            <label>
                <span class="split-herkunft-titel">Vorlage</span>
                <select class="split-vorlage">
                    <option value="0" <?= $herkunft === 0 ? 'selected' : '' ?>>— keine —</option>
                    <?php foreach ($vorlagenListe as $v): ?>
                        <?php // data-plaene traegt die Vorschau fuer die
                              // Rueckfrage vor dem Zuruecksetzen. Bis 1.2.22
                              // las das JS sie an der Vorlagenkarte derselben
                              // Seite ab; seit die Vorlagen dort nicht mehr
                              // stehen, muss der Wert mit dem Auswahlfeld
                              // kommen -- serverseitig gerendert wie zuvor,
                              // nur an einer anderen Stelle. ?>
                        <option value="<?= (int)$v['id'] ?>"
                                data-plaene="<?= h(implode(' → ', $planNamen[(int)$v['id']] ?? [])) ?>"
                                <?= (int)$v['id'] === $herkunft ? 'selected' : '' ?>>
                            <?= h((string)$v['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php // Der Knopf erscheint NUR bei INHALTLICHER Abweichung --
                  // gleich, von welcher Seite sie kommt: eigene Anpassung oder
                  // verbesserte Vorlage. Stimmen beide ueberein, gaebe es
                  // nichts zu tun, und ein wirkungsloser Knopf laedt zum
                  // Ausprobieren ein.
                  //
                  // ABWEICHENDE PLANNAMEN ALLEIN GENUEGEN SEIT 1.2.23 NICHT
                  // (Ansage des Benutzers): Wer seine Kopie "Tag A"/"Tag B"
                  // nennt, hat sein Training nicht geaendert. Die Namen sind
                  // die zweite Frage und stehen deshalb als data-namen-ab an
                  // diesem Knopf -- die Rueckfrage bietet das Kaestchen nur
                  // an, wenn es wirklich etwas anzugleichen gibt. ?>
            <?php if ($abweichung): ?>
                <button type="button" class="leise split-zuruecksetzen"
                        data-namen-ab="<?= $namenAb ? '1' : '0' ?>"
                        <?= $gesperrt ? 'disabled title="Erst die laufende Einheit beenden"' : '' ?>>
                    Auf Vorlage zurücksetzen
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php // EINE EIGENE ZEILE fuer den folgenreichen Knopf, und zwar
              // unabhaengig davon, wie die Reihe darueber umbricht. Genau das
              // ist der Zweck des zweiten Behaelters: Stuende "Loeschen" in
              // derselben Zeile, haenge seine Lage davon ab, wie breit das
              // Geraet gerade ist und wie viele Knoepfe die Karte hat -- mal
              // saesse er am rechten Rand, mal mitten zwischen "Umbenennen"
              // und "Als Text".
              //
              // Die Ausrichtung macht margin-left:auto am roten Knopf und
              // nicht space-between -- so steht er auch dann rechts, wenn er
              // allein in der Zeile ist, und das ist er seit 1.2.23 immer:
              // "Zu mir kopieren" stand hier bis dahin links daneben. ?>
        <div class="gruppe-knoepfe split-abschluss">
            <button type="button" class="gefahr split-loeschen">Löschen</button>
        </div>

    </li>
    <?php
}
