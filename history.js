'use strict';

/**
 * Trainingshistorie (§7.8).
 */

// Filter der Übungsansicht: Jede Änderung zeigt sofort an, ohne eigenen Knopf —
// dasselbe Verhalten wie die Filter der Übungsverwaltung.
(() => {
    const form = qs('.verlauf-filter');
    if (!form) return;

    const sortierung = form.elements.sort;

    form.addEventListener('change', (e) => {
        // Wer die Einheit wechselt, ohne die Sortierung je angefasst zu haben,
        // bekommt die Vorgabe der Seite: eine gewählte Einheit steht in ihrer
        // Trainingsreihenfolge. Das Feld zeigt dann nur den bisherigen
        // Vorgabewert an — ihn mitzuschicken hieße, eine Wahl zu übermitteln,
        // die niemand getroffen hat. Deaktivierte Felder schickt ein Formular
        // nicht mit.
        if (e.target === form.elements.einheit && sortierung
            && sortierung.hasAttribute('data-vorgabe')) {
            sortierung.disabled = true;
        }
        // Das Muskelgruppen-Feld gilt nur bei "alle Einheiten" und "Nach
        // Muskelgruppe". Wer eines der beiden anderen Felder ändert, verlässt
        // diesen Zustand (oder bleibt ohnehin darin, dann ist "alle" richtig):
        // Der Server liest den Wert dann nicht, und in der Adresse hätte er
        // nichts verloren.
        const gruppe = form.elements.gruppe;
        if (gruppe && e.target !== gruppe) {
            gruppe.disabled = true;
        }
        form.submit();
    });

    // Zurück-Taste: Der Browser stellt die Seite aus seinem Cache wieder her,
    // samt dem eben deaktivierten Feld — es ließe sich dann nicht mehr bedienen.
    window.addEventListener('pageshow', () => {
        if (sortierung) sortierung.disabled = false;
        if (form.elements.gruppe) form.elements.gruppe.disabled = false;
    });
})();

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
