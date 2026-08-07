'use strict';

/**
 * Muskelgruppen-Verwaltung (§6.2).
 */

(() => {
    const liste = qs('#gruppen-liste');

    // --- Neue Gruppe -------------------------------------------------------

    const neu = qs('#neu-formular');
    if (neu) {
        const hinweis = qs('#neu-fehler');

        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            hinweis.hidden = true;
            feldFehlerLeeren(neu);

            try {
                await apiFetch('api/muscle_groups.php', {
                    body: {
                        action: 'create',
                        name_de: qs('#neu_name_de').value,
                        name_en: qs('#neu_name_en').value,
                        parent_id: qs('#neu_parent').value || null,
                    },
                });
                // Die Liste kommt serverseitig gerendert samt Anzahlen -- neu
                // laden ist ehrlicher, als die Zeile im Browser nachzubauen.
                window.location.reload();
            } catch (fehler) {
                // Der Server meldet zu name_de, das Feld heisst neu_name_de.
                if (fehler.fields && fehler.fields.name_de) {
                    fehler.fields.neu_name_de = fehler.fields.name_de;
                }
                feldFehlerZeigen(fehler, hinweis, neu);
            }
        });
    }

    if (!liste) return;

    /** Zeigt einen Fehler in der betroffenen Zeile an, nicht als Kurzmeldung. */
    function zeilenFehler(zeile, text) {
        const p = qs('.zeilen-fehler', zeile);
        p.textContent = text;
        p.hidden = false;
    }

    function zeilenFehlerLeeren(zeile) {
        const p = qs('.zeilen-fehler', zeile);
        p.hidden = true;
        p.textContent = '';
    }

    /**
     * Schickt die aktuelle Reihenfolge aller Zeilen an den Server.
     *
     * Tiefensuche über den Baum: erst die Hauptgruppe, dann ihre Untergruppen.
     * Der Server vergibt fortlaufende Werte, die Anzeige gruppiert anschließend
     * wieder nach Hauptgruppe — damit bleibt beides stimmig.
     */
    async function reihenfolgeSpeichern() {
        const ids = qsa('.gruppe', liste).map((li) => Number(li.dataset.id));
        try {
            await apiFetch('api/muscle_groups.php', {
                body: { action: 'reorder', ids },
            });
            meldung('Reihenfolge gespeichert.', 'gut');
        } catch (fehler) {
            meldung(fehler.message, 'fehler');
            window.location.reload();
        }
    }

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('button');
        if (!knopf) return;

        const zeile = knopf.closest('.gruppe');
        const id = Number(zeile.dataset.id);
        zeilenFehlerLeeren(zeile);

        // --- Sortieren -----------------------------------------------------
        if (knopf.classList.contains('hoch') || knopf.classList.contains('runter')) {
            const hoch = knopf.classList.contains('hoch');
            const nachbar = hoch ? zeile.previousElementSibling : zeile.nextElementSibling;
            if (!nachbar) return;

            if (hoch) {
                liste.insertBefore(zeile, nachbar);
            } else {
                liste.insertBefore(nachbar, zeile);
            }
            await reihenfolgeSpeichern();
            return;
        }

        // --- Umbenennen ----------------------------------------------------
        if (knopf.classList.contains('speichern')) {
            knopf.disabled = true;
            try {
                await apiFetch('api/muscle_groups.php', {
                    body: {
                        action: 'update',
                        id,
                        name_de: qs('.name-de', zeile).value,
                        name_en: qs('.name-en', zeile).value,
                        parent_id: qs('.parent-wahl', zeile).value || null,
                    },
                });
                // Wurde die Hauptgruppe geändert, wandert die Zeile im Baum an
                // eine andere Stelle — das lässt sich im Browser nicht sinnvoll
                // nachbilden, also frisch vom Server holen.
                if (qs('.parent-wahl', zeile).value !== (zeile.dataset.parent || '')) {
                    window.location.reload();
                    return;
                }
                meldung('Gespeichert.', 'gut');
            } catch (fehler) {
                zeilenFehler(zeile, fehler.message);
            } finally {
                knopf.disabled = false;
            }
            return;
        }

        // --- Löschen -------------------------------------------------------
        if (knopf.classList.contains('loeschen')) {
            const name = qs('.name-de', zeile).value;
            if (!window.confirm('Muskelgruppe „' + name + '“ wirklich löschen?')) {
                return;
            }

            knopf.disabled = true;
            try {
                await apiFetch('api/muscle_groups.php', {
                    body: { action: 'delete', id },
                });
                zeile.remove();
                meldung('Gelöscht.', 'gut');
            } catch (fehler) {
                // Die Begründung nennt die betroffenen Übungen und ist damit
                // zu lang für eine Kurzmeldung -- sie bleibt an der Zeile stehen.
                zeilenFehler(zeile, fehler.message);
                knopf.disabled = false;
            }
        }
    });
})();
