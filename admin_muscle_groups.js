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
     * wieder nach Hauptgruppe — damit bleibt beides stimmig. Genau deshalb muss
     * eine Hauptgruppe zusammen mit ihrem Halter wandern (siehe block()): Bliebe
     * er stehen, stünde in der gesendeten Folge eine Untergruppe vor ihrer
     * eigenen Hauptgruppe.
     */
    async function reihenfolgeSpeichern() {
        const ids = qsa('.gruppe', liste).map((li) => Number(li.dataset.id));
        await apiFetch('api/muscle_groups.php', {
            body: { action: 'reorder', ids },
        });
    }

    /**
     * Der Block, den ein Pfeil bewegt: die Karte selbst und — bei einer
     * Hauptgruppe — der Halter mit ihren Untergruppen.
     *
     * Eine Hauptgruppe allein zu verschieben ließe ihre Untergruppen unter der
     * fremden Überschrift darüber zurück.
     */
    function block(zeile) {
        const teile = [zeile];
        const halter = zeile.nextElementSibling;
        // Der Halter „Ohne Hauptgruppe" am Listenende trägt kein data-parent
        // und gehört niemandem — er darf nicht mitwandern.
        if (halter && halter.dataset.parent === zeile.dataset.id) {
            teile.push(halter);
        }
        return teile;
    }

    /**
     * Die nächste Gruppenkarte in dieser Richtung, Halter übersprungen.
     *
     * Auf oberster Ebene liegt zwischen zwei Hauptgruppen das <li> mit den
     * Untergruppen der ersten. previousElementSibling zeigte dort also auf
     * etwas, das gar keine Gruppe ist — und der Griff daneben war der ganze
     * Fehler: insertBefore() bekam einen Nachbarn aus einer anderen Liste
     * gereicht und warf, ohne dass es jemand sah.
     */
    function nachbarGruppe(el, hoch) {
        let n = hoch ? el.previousElementSibling : el.nextElementSibling;
        while (n && !n.classList.contains('gruppe')) {
            n = hoch ? n.previousElementSibling : n.nextElementSibling;
        }
        return n;
    }

    /**
     * Randpfeile sperren: je Ebene oben kein „↑", unten kein „↓".
     *
     * Steht hier und nicht im Server-Rendering, weil ein geglückter Zug die
     * Seite NICHT neu lädt: Die Karten wandern im DOM, und eine serverseitig
     * gesetzte Sperre gehörte danach zur falschen Karte (Fallstrick 28). Eine
     * Regel, eine Stelle — und ein Pfeil, der sichtbar nichts kann, ist besser
     * als einer, der stumm nichts tut.
     */
    function pfeileNachziehen() {
        [liste, ...qsa('.untergruppen', liste)].forEach((ebene) => {
            const zeilen = qsa(':scope > .gruppe', ebene);
            zeilen.forEach((zeile, i) => {
                qs('.hoch', zeile).disabled = i === 0;
                qs('.runter', zeile).disabled = i === zeilen.length - 1;
            });
        });
    }

    pfeileNachziehen();

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('button');
        if (!knopf || knopf.disabled) return;

        const zeile = knopf.closest('.gruppe');
        const id = Number(zeile.dataset.id);
        zeilenFehlerLeeren(zeile);

        // --- Sortieren -----------------------------------------------------
        if (knopf.classList.contains('hoch') || knopf.classList.contains('runter')) {
            const hoch = knopf.classList.contains('hoch');
            const eigen = block(zeile);
            const nachbar = nachbarGruppe(hoch ? zeile : eigen[eigen.length - 1], hoch);
            if (!nachbar) return;

            // Verschoben wird IN DER EIGENEN LISTE: eine Untergruppe innerhalb
            // ihres <ul>, eine Hauptgruppe auf oberster Ebene. Beides ist
            // dasselbe Elternteil, und nur deshalb stimmt insertBefore().
            const behaelter = zeile.parentElement;
            const anker = eigen[eigen.length - 1].nextElementSibling;
            const nachbarBlock = block(nachbar);

            if (hoch) {
                eigen.forEach((el) => behaelter.insertBefore(el, nachbarBlock[0]));
            } else {
                nachbarBlock.forEach((el) => behaelter.insertBefore(el, eigen[0]));
            }
            pfeileNachziehen();

            // ALLE Pfeile sperren, bis die Antwort da ist — nicht nur die dieser
            // Karte. Zwei schnelle Tipps schickten sonst zwei reorder
            // gleichzeitig los, und weil jeder die GANZE Reihenfolge schreibt,
            // gewänne die zuletzt eingetroffene Antwort und nicht die zuletzt
            // gestellte Frage (Fallstrick 28).
            const pfeile = qsa('.hoch, .runter', liste);
            pfeile.forEach((k) => { k.disabled = true; });

            try {
                await reihenfolgeSpeichern();
                meldung('Reihenfolge gespeichert.', 'gut');
            } catch (fehler) {
                // Zurücknehmen statt neu laden: Diese Seite lädt auch im
                // Erfolgsfall nicht neu, die Ansicht IST der Arbeitsstand — und
                // ein Neuladen würfe nebenbei jede noch nicht gespeicherte
                // Namensänderung in den Feldern weg.
                eigen.forEach((el) => behaelter.insertBefore(el, anker));
                meldung(fehler.message, 'fehler');
            } finally {
                pfeile.forEach((k) => { k.disabled = false; });
                pfeileNachziehen();
            }
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
                pfeileNachziehen();
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
