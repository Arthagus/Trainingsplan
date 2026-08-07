'use strict';

/**
 * Trainingshistorie (§7.8).
 */

(() => {
    const liste = qs('.liste-schlicht');
    if (!liste) return;

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.einheit-loeschen');
        if (!knopf) return;

        const karte = knopf.closest('[data-session]');
        const id = Number(karte.dataset.session);
        const datum = qs('.einheit-datum', karte).textContent.trim();

        // Eine Einheit zu löschen ist endgültig — das Protokoll geht mit.
        if (!window.confirm(
            'Einheit vom ' + datum + ' löschen?\n\n'
            + 'Die protokollierten Gewichte dieser Einheit gehen verloren und '
            + 'verschwinden aus dem Verlauf.')) {
            return;
        }

        const fehlerfeld = qs('.zeilen-fehler', karte);
        fehlerfeld.hidden = true;
        knopf.disabled = true;

        try {
            await apiFetch('api/session.php', {
                body: { action: 'delete', session_id: id },
            });
            // Die Kurven und Zähler oben stimmen danach nicht mehr — neu laden.
            window.location.reload();
        } catch (fehler) {
            fehlerfeld.textContent = fehler.message;
            fehlerfeld.hidden = false;
            knopf.disabled = false;
        }
    });
})();
