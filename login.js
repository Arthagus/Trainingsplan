'use strict';

/**
 * Anmeldeformular.
 */

(() => {
    const formular = qs('#anmelde-formular');
    if (!formular) return;

    const knopf   = qs('#anmelden');
    const hinweis = qs('#anmelde-fehler');

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
