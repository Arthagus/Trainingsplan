'use strict';

/**
 * Konto: Passwortwechsel, Benutzername, Satz-Vorbelegung und Geräte (§7.7).
 */

(() => {
    // --- Vorbelegung neuer Sätze (§7.4) ------------------------------------

    const vorlage = qs('.vorlage-wahl');

    if (vorlage) {
        const vorlageHinweis = qs('#vorlage-fehler');

        vorlage.addEventListener('change', async (e) => {
            const feld = e.target.closest('input[name="satz_vorlage"]');
            if (!feld) return;

            vorlageHinweis.hidden = true;
            vorlage.disabled = true;

            try {
                await apiFetch('api/auth.php', {
                    body: { action: 'set_satz_vorlage', satz_vorlage: feld.value },
                });
                meldung('Vorbelegung gespeichert.', 'gut');
            } catch (fehler) {
                // Kein Zurückspringen wie beim Expertenhaken: Bei Radiobuttons
                // wüsste man dafür, welcher vorher an war — den müsste man
                // getrennt mitführen. Stattdessen sagt die Meldung, dass es
                // nicht gespeichert ist, und ein Neuladen zeigt den echten Stand.
                vorlageHinweis.textContent = fehler.message + ' Die Auswahl ist nicht gespeichert.';
                vorlageHinweis.hidden = false;
            } finally {
                vorlage.disabled = false;
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

    // Wie auf login.php: der Fokus nur am Zeigegeraet, damit der
    // Passwortmanager am Handy ueberhaupt gefragt wird. Begruendung dort und
    // unter *Frontend* in CLAUDE.md.
    if (!window.matchMedia('(pointer: coarse)').matches) {
        const erstes = qs('#current');
        if (erstes) erstes.focus();
    }

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

    // --- Geraete (§7.7) -----------------------------------------------------
    //
    // Lag bis 1.2.2 in devices.js. Die Seite heisst "Konto" und traegt seither
    // beides; ein eigener Menuepunkt fuer eine Liste, die man alle paar Monate
    // anfasst, war am Handy zu teuer.

    const liste = qs('#geraete-liste');

    if (liste) {
        liste.addEventListener('click', async (e) => {
            const knopf = e.target.closest('.geraet-abmelden');
            if (!knopf) return;

            const zeile = knopf.closest('[data-token]');
            const id = Number(zeile.dataset.token);

            knopf.disabled = true;
            try {
                await apiFetch('api/auth.php', {
                    body: { action: 'revoke_device', token_id: id },
                });
                zeile.remove();
                meldung('Gerät abgemeldet.', 'gut');

                if (!qs('[data-token]')) {
                    // War es das letzte, stimmt die Seite nicht mehr mit dem
                    // Server ueberein -- neu laden statt einen Leerzustand
                    // im Browser nachzubauen.
                    window.location.reload();
                }
            } catch (fehler) {
                knopf.disabled = false;
                meldung(fehler.message, 'fehler');
            }
        });
    }

    const alle = qs('#alle-abmelden');
    if (alle) {
        alle.addEventListener('click', async () => {
            if (!window.confirm('Wirklich alle Geräte abmelden? Auch dieses hier verlangt danach wieder das Passwort.')) {
                return;
            }

            alle.disabled = true;
            try {
                await apiFetch('api/auth.php', { body: { action: 'revoke_all' } });
                window.location.reload();
            } catch (fehler) {
                alle.disabled = false;
                meldung(fehler.message, 'fehler');
            }
        });
    }
})();
