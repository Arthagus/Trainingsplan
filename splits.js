'use strict';

/**
 * Workout-Splits (§6.4, §7.6).
 *
 * Die Seite laedt nach jeder Aenderung neu, statt die Liste im Browser
 * nachzufuehren -- dieselbe Entscheidung wie in plans.js. Was sich hier
 * aendert, wirkt an mehreren Stellen zugleich (Rotationsvorschau, aktiver
 * Split, Plan-Namen), und eine zweite Fassung dieser Zusammenhaenge im JS
 * waere genau die Dublette, die spaeter abweicht.
 */

(() => {
    const ENDPUNKT = 'api/splits.php';

    /** Schickt eine Aktion und laedt neu; Fehler landen an der Zeile. */
    async function senden(zeile, nutzlast, knopf) {
        const fehlerFeld = zeile ? qs('.zeilen-fehler', zeile) : null;
        if (fehlerFeld) {
            fehlerFeld.hidden = true;
            fehlerFeld.textContent = '';
        }
        if (knopf) knopf.disabled = true;

        try {
            await apiFetch(ENDPUNKT, { body: nutzlast });
            window.location.reload();
        } catch (fehler) {
            if (knopf) knopf.disabled = false;
            // Die Sperre bei laufendem Training und der 403 auf eine Vorlage
            // erklaeren sich in einem ganzen Satz -- deshalb an der Zeile und
            // nicht als fluechtige Meldung.
            if (fehlerFeld) {
                fehlerFeld.textContent = fehler.message;
                fehlerFeld.hidden = false;
            } else {
                meldung(fehler.message, 'fehler');
            }
        }
    }

    // --- Neuen Split anlegen ------------------------------------------------
    const neu = qs('#split-neu');
    if (neu) {
        const fehler = qs('#split-neu-fehler');

        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            fehler.hidden = true;
            fehler.textContent = '';

            const feld = qs('input[name="name"]', neu);
            const vorlage = qs('#split_vorlage');

            try {
                await apiFetch(ENDPUNKT, {
                    body: {
                        action: 'create',
                        name: feld.value,
                        vorlage: vorlage ? vorlage.checked : false,
                    },
                });
                window.location.reload();
            } catch (f) {
                fehler.textContent = f.fields?.name || f.message;
                fehler.hidden = false;
                feld.focus();
            }
        });
    }

    // --- User Splits: aus einem fremden oder eigenen Split eine Vorlage -----
    //
    // Ein Pulldown statt einer Kartenliste, und das ist der Unterschied in der
    // Sache: Hier wird nichts verwaltet, hier wird genau eine Handlung
    // ausgeloest. Loeschen und Umbenennen gibt es bewusst nicht -- das sind die
    // persoenlichen Splits anderer Leute.
    const kandidat = qs('#kandidat');
    if (kandidat) {
        const vorschau = qs('#kandidat-plaene');
        const knopf    = qs('#kandidat-veroeffentlichen');
        const kasten   = qs('#kandidaten');
        const fehler   = qs('.zeilen-fehler', kasten);

        const zeigen = () => {
            const opt = kandidat.selectedOptions[0];
            vorschau.textContent = opt ? (opt.dataset.plaene || 'Noch kein Plan darin.') : '';
        };
        kandidat.addEventListener('change', zeigen);
        zeigen();

        knopf.addEventListener('click', async () => {
            const opt = kandidat.selectedOptions[0];
            if (!opt) return;

            // Rueckfrage, weil das Ergebnis fuer ALLE sichtbar wird -- und weil
            // der Name im Katalog anders lauten soll als der private. Der
            // Vorschlag ist deshalb der Splitname OHNE den Benutzer davor.
            const wunsch = window.prompt(
                'Als Vorlage für alle veröffentlichen. Unter welchem Namen?\n\n'
                + 'Es entsteht eine Kopie — der Split des Benutzers bleibt unverändert '
                + 'und wird von späteren Änderungen an der Vorlage nicht berührt.',
                opt.dataset.name || ''
            );
            if (wunsch === null) return;

            fehler.hidden = true;
            fehler.textContent = '';
            knopf.disabled = true;
            try {
                await apiFetch(ENDPUNKT, {
                    body: { action: 'publish', id: Number(opt.value), name: wunsch },
                });
                window.location.reload();
            } catch (f) {
                knopf.disabled = false;
                fehler.textContent = f.message;
                fehler.hidden = false;
            }
        });
    }

    // --- Alles Uebrige haengt an einer Splitkarte ---------------------------
    document.addEventListener('click', (e) => {
        const zeile = e.target.closest('.split[data-id]');
        if (!zeile) return;

        const id = Number(zeile.dataset.id);
        const name = qs('.split-name', zeile)?.value
            ?? qs('strong', zeile)?.textContent.trim()
            ?? '';

        const alsText = e.target.closest('.split-text');
        if (alsText) {
            textZeigen(zeile, name);
            return;
        }

        const aktivieren = e.target.closest('.split-aktivieren');
        if (aktivieren) {
            senden(zeile, { action: 'activate', id }, aktivieren);
            return;
        }

        const speichern = e.target.closest('.split-speichern');
        if (speichern) {
            senden(zeile, { action: 'rename', id, name }, speichern);
            return;
        }

        const kopieren = e.target.closest('.split-kopieren');
        if (kopieren) {
            senden(zeile, { action: 'copy', id }, kopieren);
            return;
        }

        const duplizieren = e.target.closest('.split-duplizieren');
        if (duplizieren) {
            senden(zeile, { action: 'copy', id }, duplizieren);
            return;
        }

        const vorlageDuplizieren = e.target.closest('.vorlage-duplizieren');
        if (vorlageDuplizieren) {
            // Eine zweite VORLAGE, nicht eine persoenliche Kopie -- deshalb
            // 'publish' und nicht 'copy'.
            //
            // OHNE Rueckfrage nach dem Namen: Beim Duplizieren weiss man noch
            // nicht, wie die Variante heissen soll, das ergibt sich erst beim
            // Bearbeiten. Der Server haengt "(Kopie)" an; umbenannt wird
            // danach am Namensfeld der Karte. Eine Rueckfrage, die man mit dem
            // Vorschlag bestaetigt, ist keine Frage, sondern ein Klick mehr.
            senden(zeile, { action: 'publish', id }, vorlageDuplizieren);
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
            senden(zeile, { action: 'delete', id }, loeschen);
        }
    });

    // --- Split als Text -----------------------------------------------------

    /**
     * Zeigt den Text der Karte im Dialog.
     *
     * Der Text kommt aus der Karte selbst (<pre class="split-text-inhalt">),
     * nicht aus einem Netzaufruf: Das Schreiben in die Zwischenablage muss in
     * derselben Benutzeraktion stattfinden wie der Klick, und ein await auf
     * einen fetch bricht diesen Zusammenhang in strengeren Browsern.
     */
    function textZeigen(zeile, name) {
        const dialog = qs('#text-dialog');
        const quelle = qs('.split-text-inhalt', zeile);
        if (!dialog || !quelle) return;

        qs('#text-titel').textContent = name ? 'Split „' + name + '“ als Text' : 'Split als Text';
        qs('#text-inhalt').value = quelle.textContent;
        qs('#text-hinweis').textContent = '';
        dialog.showModal();
    }

    const textDialog = qs('#text-dialog');
    if (textDialog) {
        const feld    = qs('#text-inhalt');
        const hinweis = qs('#text-hinweis');

        qs('#text-schliessen').addEventListener('click', () => textDialog.close());

        qs('#text-kopieren').addEventListener('click', async () => {
            // Zwei Wege, und der zweite wird wirklich gebraucht:
            // navigator.clipboard gibt es nur im sicheren Kontext, und selbst
            // dort kann die Berechtigung fehlen. Dann bleibt das Markieren --
            // der Text steht ja sichtbar da, es fehlt nur noch Strg+C.
            try {
                await navigator.clipboard.writeText(feld.value);
                hinweis.textContent = 'Kopiert.';
            } catch (fehler) {
                feld.focus();
                feld.select();
                hinweis.textContent = 'Der Browser lässt das Kopieren nicht zu — '
                    + 'der Text ist markiert, bitte mit Strg+C bzw. ⌘+C kopieren.';
            }
        });
    }
})();
