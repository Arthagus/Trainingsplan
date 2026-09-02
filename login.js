'use strict';

/**
 * Anmeldeformular.
 */

(() => {
    const formular = qs('#anmelde-formular');
    if (!formular) return;

    const knopf   = qs('#anmelden');
    const hinweis = qs('#anmelde-fehler');

    // Der Fokus steht hier und nicht als autofocus im Markup, und er wird
    // ausschliesslich am Zeigegeraet gesetzt.
    //
    // Firefox auf Android meldet ein Feld, das beim Laden bereits den Fokus
    // hat, nicht an Androids Autofill-Dienst -- der Passwortmanager kommt gar
    // nicht erst zum Zug. Auf dem Pixel 10 erschien deshalb nicht einmal das
    // Proton-Symbol ueber der Tastatur, waehrend es auf anderen Seiten da war
    // (gemeldet 2026-09-02). Am Desktop faellt es nicht auf: Dort ist der
    // Passwortmanager eine Erweiterung und liest das DOM, statt am Fokus zu
    // haengen.
    //
    // `pointer: coarse` fragt den PRIMAEREN Zeiger ab -- ein Notebook mit
    // Touchscreen und Maus meldet weiterhin `fine` und behaelt den Fokus.
    if (!window.matchMedia('(pointer: coarse)').matches) {
        const feld = qs('#name');
        if (feld) feld.focus();
    }

    formular.addEventListener('submit', async (e) => {
        e.preventDefault();
        hinweis.hidden = true;
        feldFehlerLeeren(formular);
        knopf.disabled = true;

        try {
            const daten = await apiFetch('api/auth.php', {
                body: {
                    action: 'login',
                    name: qs('#name').value,
                    password: qs('#password').value,
                    remember: qs('#remember') ? qs('#remember').checked : false,
                },
            });
            window.location.href = daten.redirect || 'index.php';
        } catch (fehler) {
            feldFehlerZeigen(fehler, hinweis, formular);
            // Das Passwortfeld leeren, den Benutzernamen stehen lassen --
            // meist ist genau das der Vertipper.
            qs('#password').value = '';
            knopf.disabled = false;
        }
    });
})();
