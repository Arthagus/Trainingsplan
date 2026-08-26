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
            // Zurueckgegeben fuer die Aufrufer, die mehr brauchen als die
            // Meldung -- bisher nur die Bildsuche unten.
            return daten;
        } catch (fehler) {
            melden(fehler.message, 'fehler');
            return null;
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

            const lauf = ausfuehren(koerper, knopf, aktion === 'backup');

            if (aktion === 'images_orphans') {
                lauf.then((daten) => { if (daten) verwaisteZeigen(daten.dateien || []); });
            }
        });
    });

    // --- Verwaiste Bilder --------------------------------------------------

    const verwaisteKasten = qs('#verwaiste-bilder');

    /**
     * Zeigt, was die Suche gefunden hat.
     *
     * Der Kasten mit dem Löschen-Knopf erscheint NUR, wenn es etwas zu löschen
     * gibt — und verschwindet wieder, sobald die Suche leer ausgeht. Ein
     * dauerhaft sichtbarer Löschen-Knopf neben einer leeren Liste lädt zum
     * Ausprobieren ein, und diese Aktion nimmt Dateien weg, deren einzige Kopie
     * in einer Sicherung MIT Bildern steckt.
     */
    function verwaisteZeigen(dateien) {
        if (!verwaisteKasten) return;

        if (dateien.length === 0) {
            verwaisteKasten.hidden = true;
            return;
        }

        qs('#verwaiste-kopf', verwaisteKasten).textContent =
            'Diese Dateien gehören zu keiner Übung:';
        qs('#verwaiste-liste', verwaisteKasten).innerHTML = dateien.map((d) =>
            '<li><code>' + escapeHtml(d.name) + '</code> '
            + '<span class="matt">' + escapeHtml(bytesLesbar(d.groesse))
            + (d.alter_tage > 0 ? ', ' + Number(d.alter_tage) + ' Tage alt' : '')
            + '</span></li>').join('');
        verwaisteKasten.hidden = false;
    }

    /** Ohne Nachbau der PHP-Funktion bytes_lesbar(): dieselbe Staffelung. */
    function bytesLesbar(bytes) {
        const zahl = Number(bytes) || 0;
        if (zahl >= 1048576) return (zahl / 1048576).toFixed(1).replace('.', ',') + ' MB';
        if (zahl >= 1024) return Math.round(zahl / 1024) + ' KB';
        return zahl + ' Bytes';
    }

    const verwaisteLoeschen = qs('#verwaiste-loeschen');
    if (verwaisteLoeschen) {
        verwaisteLoeschen.addEventListener('click', () => {
            const anzahl = qsa('li', qs('#verwaiste-liste')).length;
            if (!window.confirm(anzahl + ' Datei(en) endgültig löschen?\n\n'
                + 'Sie gehören zu keiner Übung. Zurückholen lassen sie sich nur aus '
                + 'einer Sicherung, die MIT Bildern erstellt wurde.')) {
                return;
            }
            // Neu laden: Die Kachel „Bilder" im Zustand oben zählt die Dateien,
            // und die stimmt danach nicht mehr.
            ausfuehren({ action: 'images_cleanup' }, verwaisteLoeschen, true);
        });
    }

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
