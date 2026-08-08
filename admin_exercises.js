'use strict';

/**
 * Uebungsverwaltung (§6.3).
 */

(() => {
    const ENDPUNKT = 'api/exercises.php';

    // -----------------------------------------------------------------------
    // Muskelgruppen-Auswahl
    //
    // Die Regel aus §6.3: mindestens eine Gruppe, und genau eine davon primär.
    // Der Radiobutton ist nur für angehakte Zeilen bedienbar; wird die primäre
    // Gruppe abgewählt, rückt die oberste verbleibende nach. Das Formular darf
    // nie ohne Primärgruppe abschickbar sein.
    //
    // Der Server prüft dasselbe noch einmal — diese Logik ist Bequemlichkeit,
    // keine Absicherung.
    // -----------------------------------------------------------------------

    function gruppenWahlEinrichten(feld) {
        const zeilen = qsa('.gruppen-zeile', feld);

        /**
         * Hält die beiden Spalten widerspruchsfrei: Die primäre Gruppe kann
         * nicht gleichzeitig sekundär sein, also wird ihr Sekundär-Häkchen
         * entfernt und gesperrt. Alle übrigen Zeilen sind frei wählbar.
         *
         * Die Ausschließlichkeit von „primär" macht der Radiobutton selbst —
         * dafür braucht es kein Javascript.
         */
        function abgleichen() {
            zeilen.forEach((zeile) => {
                const primaer   = qs('input[type="radio"]', zeile);
                const sekundaer = qs('input[type="checkbox"]', zeile);

                sekundaer.disabled = primaer.checked;
                if (primaer.checked) {
                    sekundaer.checked = false;
                }
            });
        }

        feld.addEventListener('change', (e) => {
            if (e.target.type === 'radio') {
                abgleichen();
            }
        });

        abgleichen();
    }

    qsa('[data-gruppen-wahl]').forEach(gruppenWahlEinrichten);

    // -----------------------------------------------------------------------
    // Formulare abschicken
    // -----------------------------------------------------------------------

    /**
     * Schickt ein Übungsformular als FormData ab — nötig wegen des Bildes.
     * apiFetch reicht FormData unangetastet durch; einen Content-Type dürfen
     * wir dabei nicht setzen, sonst fehlt die multipart-Grenze.
     */
    async function formularSenden(formular, aktion, hinweis) {
        hinweis.hidden = true;
        feldFehlerLeeren(formular);

        const daten = new FormData(formular);
        daten.set('action', aktion);

        const knopf = qs('button[type="submit"]', formular);
        knopf.disabled = true;

        try {
            await apiFetch(ENDPUNKT, { body: daten });
            window.location.reload();
        } catch (fehler) {
            feldFehlerZeigen(fehler, hinweis, formular);
            knopf.disabled = false;
        }
    }

    const neu = qs('#neu-formular');
    if (neu) {
        neu.addEventListener('submit', (e) => {
            e.preventDefault();
            formularSenden(neu, 'create', qs('#neu-fehler'));
        });
    }

    qsa('.bearbeiten-formular').forEach((formular) => {
        formular.addEventListener('submit', (e) => {
            e.preventDefault();
            formularSenden(formular, 'update', qs('.formular-fehler', formular));
        });
    });

    // -----------------------------------------------------------------------
    // Zeilenaktionen
    // -----------------------------------------------------------------------

    const liste = qs('#uebungs-liste');
    if (!liste) return;

    function zeilenFehler(zeile, text) {
        const p = qs('.zeilen-fehler', zeile);
        p.textContent = text;
        p.hidden = false;
    }

    /** Führt eine Aktion aus und lädt bei Erfolg neu. */
    async function aktion(zeile, knopf, koerper, erfolgstext) {
        qs('.zeilen-fehler', zeile).hidden = true;
        knopf.disabled = true;
        try {
            await apiFetch(ENDPUNKT, { body: koerper });
            meldung(erfolgstext, 'gut');
            window.location.reload();
        } catch (fehler) {
            // Die Begründung nennt Planreferenzen und Protokollmenge und ist
            // zu lang für eine Kurzmeldung — sie bleibt an der Zeile stehen.
            zeilenFehler(zeile, fehler.message);
            knopf.disabled = false;
        }
    }

    liste.addEventListener('click', (e) => {
        const knopf = e.target.closest('button');
        if (!knopf) return;

        const zeile = knopf.closest('.uebung');
        const id = Number(zeile.dataset.id);
        const name = qs('.uebung-text strong', zeile).textContent.trim();

        // Bild groß — derselbe Dialog wie im Training (assets/app.js).
        if (knopf.classList.contains('bild-knopf')) {
            const bild = qs('.uebung-bild', zeile);
            const beschreibung = qs('.beschreibung', zeile);
            bildGrossZeigen(
                name,
                bild ? bild.getAttribute('src') : '',
                beschreibung ? beschreibung.textContent : ''
            );
            return;
        }

        if (knopf.classList.contains('bearbeiten')) {
            const formular = qs('.bearbeiten-formular', zeile);
            const offen = !formular.hidden;
            formular.hidden = offen;
            knopf.setAttribute('aria-expanded', String(!offen));
            knopf.textContent = offen ? 'Bearbeiten' : 'Bearbeiten abbrechen';
            if (!offen) qs('input[name="name_de"]', formular).focus();
            return;
        }

        if (knopf.classList.contains('abbrechen')) {
            const formular = knopf.closest('.bearbeiten-formular');
            formular.hidden = true;
            const auf = qs('.bearbeiten', zeile);
            auf.setAttribute('aria-expanded', 'false');
            auf.textContent = 'Bearbeiten';
            return;
        }

        if (knopf.classList.contains('archivieren')) {
            const plaene = knopf.dataset.plaene;
            let frage = 'Übung „' + name + '“ archivieren?';
            if (plaene) {
                // §6.3: Steht die Übung noch in Plänen, wird das vor dem
                // Archivieren genannt und bestätigt.
                frage += '\n\nSie steht noch in diesen Plänen: ' + plaene
                       + '\nDort bleibt sie stehen, verschwindet aber aus Auswahllisten'
                       + ' und Tauschvorschlägen.';
            }
            if (!window.confirm(frage)) return;
            aktion(zeile, knopf, { action: 'archive', id }, 'Archiviert.');
            return;
        }

        if (knopf.classList.contains('reaktivieren')) {
            aktion(zeile, knopf, { action: 'unarchive', id }, 'Reaktiviert.');
            return;
        }

        if (knopf.classList.contains('loeschen')) {
            if (!window.confirm('Übung „' + name + '“ endgültig löschen? '
                + 'Das Bild wird mitgelöscht und lässt sich nicht wiederherstellen.')) {
                return;
            }
            aktion(zeile, knopf, { action: 'delete', id }, 'Gelöscht.');
        }
    });
})();
