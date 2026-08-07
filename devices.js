'use strict';

/**
 * Geraeteverwaltung (§7.7).
 */

(() => {
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
