'use strict';

/**
 * Wartung & Sicherung (§6.5).
 */

(() => {
    const ENDPUNKT = 'api/maintenance.php';
    const meldungsfeld = qs('#wartung-meldung');

    /** Zeigt eine Meldung dauerhaft an — anders als der flüchtige Toast. */
    function melden(text, art) {
        meldungsfeld.textContent = text;
        meldungsfeld.className = 'karte ' + (art === 'fehler' ? 'melde-fehler' : 'melde-gut');
        meldungsfeld.hidden = false;
        meldungsfeld.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    /**
     * Führt eine Wartungsaktion aus.
     *
     * Alle Knöpfe werden während des Laufs gesperrt: VACUUM auf einer großen
     * Datenbank dauert, und zwei gleichzeitige Schreibvorgänge auf derselben
     * Datei sind das Letzte, was man hier gebrauchen kann.
     */
    async function ausfuehren(koerper, knopf, neuLaden) {
        const alle = qsa('button');
        alle.forEach((b) => { b.disabled = true; });
        const alterText = knopf ? knopf.textContent : '';
        if (knopf) knopf.textContent = 'läuft …';

        try {
            const daten = await apiFetch(ENDPUNKT, { body: koerper });
            if (neuLaden) {
                // Die Liste und die Zahlen oben kommen serverseitig — nach einer
                // Änderung ist Neuladen ehrlicher als Nachbauen im Browser.
                sessionStorage.setItem('wartung-meldung', daten.meldung || 'Erledigt.');
                window.location.reload();
                return;
            }
            melden(daten.meldung || 'Erledigt.', 'gut');
        } catch (fehler) {
            melden(fehler.message, 'fehler');
        } finally {
            alle.forEach((b) => { b.disabled = false; });
            if (knopf) knopf.textContent = alterText;
        }
    }

    // Meldung, die ein Reload überdauern soll.
    const uebertrag = sessionStorage.getItem('wartung-meldung');
    if (uebertrag) {
        sessionStorage.removeItem('wartung-meldung');
        melden(uebertrag, 'gut');
    }

    // --- Wartungsaktionen und Sicherung erstellen --------------------------

    qsa('.wartung').forEach((knopf) => {
        knopf.addEventListener('click', () => {
            const aktion = knopf.dataset.aktion;
            const koerper = { action: aktion };

            if (aktion === 'backup') {
                koerper.with_images = knopf.dataset.bilder === '1';
            }

            ausfuehren(koerper, knopf, aktion === 'backup');
        });
    });

    // --- Sicherungen einspielen und löschen --------------------------------

    const liste = qs('#sicherungen');
    if (liste) {
        liste.addEventListener('click', (e) => {
            const knopf = e.target.closest('button');
            if (!knopf) return;

            const zeile = knopf.closest('.sicherung');
            const name = zeile.dataset.name;

            if (knopf.classList.contains('einspielen')) {
                // Zwei Rückfragen, und die zweite verlangt eine Eingabe: Das hier
                // ist die einzige Stelle der App, die den kompletten Datenbestand
                // überschreibt. Ein Fehlgriff kostet alles seit dieser Sicherung.
                if (!window.confirm(
                    'Sicherung „' + name + '" einspielen?\n\n'
                    + 'Der GESAMTE aktuelle Datenbestand wird überschrieben — '
                    + 'Benutzer, Übungen, Pläne und alle Trainingseinheiten seit '
                    + 'dieser Sicherung gehen verloren.')) {
                    return;
                }
                const antwort = window.prompt(
                    'Zur Bestätigung bitte EINSPIELEN eintippen:');
                if (antwort !== 'EINSPIELEN') {
                    melden('Abgebrochen — es wurde nichts verändert.', 'gut');
                    return;
                }
                ausfuehren({ action: 'restore', backup: name }, knopf, true);
                return;
            }

            if (knopf.classList.contains('sicherung-loeschen')) {
                if (!window.confirm('Sicherung „' + name + '" löschen?')) return;
                ausfuehren({ action: 'delete_backup', backup: name }, knopf, true);
            }
        });
    }

    // --- Hochladen ---------------------------------------------------------

    const upload = qs('#upload-formular');
    if (upload) {
        const fehler = qs('#upload-fehler');
        upload.addEventListener('submit', async (e) => {
            e.preventDefault();
            fehler.hidden = true;

            const datei = qs('#backup-datei').files[0];
            if (!datei) {
                fehler.textContent = 'Bitte eine Datei auswählen.';
                fehler.hidden = false;
                return;
            }

            // FormData, weil eine Datei mitgeht — apiFetch reicht sie durch.
            const daten = new FormData();
            daten.set('action', 'upload');
            daten.set('datei', datei);

            const knopf = qs('button[type="submit"]', upload);
            knopf.disabled = true;
            try {
                const antwort = await apiFetch(ENDPUNKT, { body: daten });
                sessionStorage.setItem('wartung-meldung', antwort.meldung);
                window.location.reload();
            } catch (f) {
                fehler.textContent = f.message;
                fehler.hidden = false;
                knopf.disabled = false;
            }
        });
    }
})();
