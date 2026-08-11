'use strict';

/**
 * Konto: Passwortwechsel, Benutzername und Trainingsansicht (§7.7).
 */

(() => {
    // --- Expertenmodus -----------------------------------------------------

    const experte = qs('#experte');
    if (experte) {
        const experteHinweis = qs('#experte-fehler');

        experte.addEventListener('change', async () => {
            const gewuenscht = experte.checked;
            experteHinweis.hidden = true;
            experte.disabled = true;

            try {
                await apiFetch('api/auth.php', {
                    body: { action: 'set_expert_mode', expert_mode: gewuenscht },
                });
                meldung(
                    gewuenscht
                        ? 'Expertenmodus eingeschaltet — Sätze werden einzeln erfasst.'
                        : 'Expertenmodus ausgeschaltet — ein Gewicht je Übung.',
                    'gut'
                );
            } catch (fehler) {
                // Der Schalter springt zurueck: Eine Anzeige, die etwas
                // anderes behauptet als der Server, waere schlimmer als die
                // Fehlermeldung.
                experte.checked = !gewuenscht;
                experteHinweis.textContent = fehler.message;
                experteHinweis.hidden = false;
            } finally {
                experte.disabled = false;
            }
        });
    }

    // --- Benutzername ------------------------------------------------------

    // Fehlt, solange ein Passwortwechsel erzwungen ist -- password.php
    // rendert den Abschnitt dann gar nicht.
    const nameFormular = qs('#name-formular');
    if (nameFormular) {
        const nameKnopf   = qs('#name-speichern');
        const nameHinweis = qs('#name-fehler');

        nameFormular.addEventListener('submit', async (e) => {
            e.preventDefault();
            nameHinweis.hidden = true;
            feldFehlerLeeren(nameFormular);
            nameKnopf.disabled = true;

            try {
                const daten = await apiFetch('api/auth.php', {
                    body: {
                        action: 'change_name',
                        name: qs('#new_name').value,
                        password: qs('#name_password').value,
                    },
                });
                meldung('Benutzername geändert: ' + daten.name, 'gut');
                // Neu laden, weil der Name auch in der Kopfzeile steht.
                window.location.reload();
            } catch (fehler) {
                feldFehlerZeigen(fehler, nameHinweis, nameFormular);
                nameKnopf.disabled = false;
            }
        });
    }

    // --- Passwort ----------------------------------------------------------

    const formular = qs('#passwort-formular');
    if (!formular) return;

    const knopf   = qs('#speichern');
    const hinweis = qs('#passwort-fehler');

    formular.addEventListener('submit', async (e) => {
        e.preventDefault();
        hinweis.hidden = true;
        feldFehlerLeeren(formular);
        knopf.disabled = true;

        try {
            const daten = await apiFetch('api/auth.php', {
                body: {
                    action: 'change_password',
                    current: qs('#current').value,
                    new: qs('#new').value,
                    new_repeat: qs('#new_repeat').value,
                },
            });
            meldung('Passwort geändert.', 'gut');
            window.location.href = daten.redirect || 'index.php';
        } catch (fehler) {
            feldFehlerZeigen(fehler, hinweis, formular);
            knopf.disabled = false;
        }
    });
})();
