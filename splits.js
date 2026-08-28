'use strict';

/**
 * Workout-Splits (§6.4, §7.6) -- der eigene Bestand.
 *
 * Seit 1.2.23 liegt die Vorlagenverwaltung auf admin_splits.php; hier bleibt,
 * was dem Aufrufer gehoert, plus der Kasten, mit dem er sich eine Vorlage
 * kopiert.
 *
 * Die Seite laedt nach jeder Aenderung neu, statt die Liste im Browser
 * nachzufuehren -- dieselbe Entscheidung wie in plans.js, ausgeschrieben bei
 * splitAktion() in assets/app.js.
 */

(() => {
    // Zwischen den Karten umschalten (§6.4, seit 1.3.2).
    splitWechselVerdrahten();

    // --- Neuen Split anlegen ------------------------------------------------
    const neu = qs('#split-neu');
    if (neu) {
        const fehler = qs('#split-neu-fehler');

        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            fehler.hidden = true;
            fehler.textContent = '';

            const feld = qs('input[name="name"]', neu);

            try {
                await apiFetch('api/splits.php', {
                    body: { action: 'create', name: feld.value },
                });
                window.location.reload();
            } catch (f) {
                fehler.textContent = f.fields?.name || f.message;
                fehler.hidden = false;
                feld.focus();
            }
        });
    }

    // --- Vorlage uebernehmen (§6.4) -----------------------------------------
    //
    // Ein Auswahlfeld statt einer Kartenliste, und das ist der Unterschied in
    // der Sache: Hier wird nichts verwaltet, hier wird genau eine Handlung
    // ausgeloest. Bearbeiten, umbenennen und loeschen einer Vorlage liegen auf
    // admin_splits.php -- das ist der Katalog fuer alle, nicht der eigene
    // Bestand.
    const wahl = qs('#vorlage-wahl');
    if (wahl) {
        const vorschau = qs('#vorlage-plaene');
        const kasten   = qs('#vorlage-uebernehmen');
        const kopieren = qs('#vorlage-kopieren');
        const alsText  = qs('#vorlage-text');

        const zeigen = () => {
            const opt = wahl.selectedOptions[0];
            vorschau.textContent = opt ? (opt.dataset.plaene || 'Noch kein Plan darin.') : '';
        };
        wahl.addEventListener('change', zeigen);
        zeigen();

        kopieren.addEventListener('click', () => {
            const opt = wahl.selectedOptions[0];
            if (!opt) return;

            // Keine Rueckfrage: Kopieren legt etwas Neues an und nimmt
            // niemandem etwas weg. Wer sich vertut, loescht die Kopie an ihrer
            // Karte wieder.
            splitAktion(kasten, { action: 'copy', id: Number(opt.value) }, kopieren);
        });

        alsText.addEventListener('click', () => {
            const opt = wahl.selectedOptions[0];
            if (!opt) return;

            splitTextZeigen(
                qs('.split-text-inhalt[data-id="' + Number(opt.value) + '"]', kasten),
                opt.dataset.name || ''
            );
        });
    }

    // --- Alles Uebrige haengt an einer Splitkarte ---------------------------
    document.addEventListener('click', (e) => {
        const zeile = e.target.closest('.split[data-id]');
        if (!zeile) return;

        const id = Number(zeile.dataset.id);
        // data-name und nicht mehr ein Eingabefeld: Im Kartenkopf sitzt seit
        // 1.3.2 das Auswahlfeld, der Name steht am <li>.
        const name = zeile.dataset.name || '';

        const alsText = e.target.closest('.split-text');
        if (alsText) {
            splitTextZeigen(qs('.split-text-inhalt', zeile), name);
            return;
        }

        const aktivieren = e.target.closest('.split-aktivieren');
        if (aktivieren) {
            splitAktion(zeile, { action: 'activate', id }, aktivieren);
            return;
        }

        const speichern = e.target.closest('.split-speichern');
        if (speichern) {
            splitUmbenennenFragen(zeile, 'Split umbenennen');
            return;
        }

        const duplizieren = e.target.closest('.split-duplizieren');
        if (duplizieren) {
            splitAktion(zeile, { action: 'copy', id }, duplizieren);
            return;
        }

        const zuruecksetzen = e.target.closest('.split-zuruecksetzen');
        if (zuruecksetzen) {
            resetFragen(zeile, name, zuruecksetzen);
            return;
        }

        const loeschen = e.target.closest('.split-loeschen');
        if (loeschen) {
            if (!window.confirm(
                'Den Split „' + name + '“ mit allen darin enthaltenen Plänen löschen?\n\n'
                + 'Bereits protokollierte Einheiten bleiben im Verlauf stehen; '
                + 'sie zeigen danach „gelöschter Plan“.'
            )) {
                return;
            }
            splitAktion(zeile, { action: 'delete', id }, loeschen);
        }
    });

    // --- Rückfrage vor dem Zurücksetzen (§6.4) ------------------------------
    //
    // Ein Dialog statt window.confirm, seit die Frage zweiteilig ist (1.2.23):
    // "Zuruecksetzen?" und "auch die Plannamen?". Zwei confirm hintereinander
    // waeren zwei Klicks fuer eine Entscheidung, und beim zweiten haelt man
    // die Sache schon fuer erledigt.

    /** Merkt sich, worauf ein "Zurücksetzen" im Dialog wirken soll. */
    let resetZiel = null;

    /**
     * Öffnet die Rückfrage für eine Karte.
     *
     * Die Vorschau beschreibt, wie der Split DANACH aussieht, gehört also zur
     * Vorlage. Bis 1.2.22 stand die als Karte auf derselben Seite und wurde
     * dort abgelesen; seit sie das nicht mehr tut, trägt die <option> des
     * Herkunftsfelds den Wert (data-plaene, server-gerendert wie zuvor).
     */
    function resetFragen(zeile, name, knopf) {
        const dialog = qs('#reset-dialog');
        if (!dialog) return;

        const feld    = qs('.split-vorlage', zeile);
        const wahlOpt = feld ? feld.options[feld.selectedIndex] : null;
        const plaene  = wahlOpt ? (wahlOpt.dataset.plaene || '') : '';

        resetZiel = { zeile, knopf, id: Number(zeile.dataset.id) };

        qs('#reset-titel').textContent = 'Den Split „' + name + '“ auf die Vorlage '
            + (wahlOpt ? '„' + wahlOpt.textContent.trim() + '“ ' : '')
            + 'zurücksetzen?';
        qs('#reset-danach').textContent = plaene ? 'Danach: ' + plaene : '';
        qs('#reset-danach').hidden = plaene === '';

        // Das Kästchen nur, wenn die Plannamen wirklich auseinandergehen --
        // sonst stünde dort eine Frage ohne Folge. Jedes Mal frisch
        // unangekreuzt: Die eigene Beschriftung ist die, die der Benutzer
        // gewählt hat, und der Knopf heißt nicht "alles angleichen".
        const namenAb = knopf.dataset.namenAb === '1';
        qs('#reset-namen-zeile').hidden = !namenAb;
        qs('#reset-namen').checked = false;

        dialog.showModal();
    }

    const resetDialog = qs('#reset-dialog');
    if (resetDialog) {
        qs('#reset-abbrechen').addEventListener('click', () => resetDialog.close());

        qs('#reset-los').addEventListener('click', () => {
            if (resetZiel === null) return;

            // Das Kästchen kann ausgeblendet sein -- dann ist die Antwort
            // "nein", und zwar unabhängig davon, was zuletzt darin stand.
            const namen = !qs('#reset-namen-zeile').hidden && qs('#reset-namen').checked;

            const { zeile, knopf, id } = resetZiel;
            resetDialog.close();
            splitAktion(zeile, { action: 'reset', id, namen }, knopf);
        });
    }

    // --- Herkunft zuordnen (§6.4) -------------------------------------------
    //
    // 'change' und nicht ein Speichern-Knopf: Die Zuordnung ist folgenlos --
    // sie schaltet nur den Knopf "Auf Vorlage zurücksetzen" frei, sie aendert
    // keinen einzigen Plan. Ein Bestaetigungsschritt fuer etwas, das nichts
    // tut, waere ein Klick ohne Gegenwert.
    //
    // Danach wird neu geladen, wie ueberall auf dieser Seite: Ob der Knopf
    // erscheint, haengt am Vergleich beider Fingerabdruecke, und den rechnet
    // der Server. Ihn im Browser nachzubauen waere die Dublette, vor der der
    // Kopfkommentar warnt.
    document.addEventListener('change', (e) => {
        const feld = e.target.closest('.split-vorlage');
        if (!feld) return;

        const zeile = feld.closest('.split[data-id]');
        if (!zeile) return;

        splitAktion(zeile, {
            action: 'set_vorlage',
            id: Number(zeile.dataset.id),
            vorlage_id: Number(feld.value),
        }, null);
    });
})();
