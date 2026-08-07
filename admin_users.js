'use strict';

/**
 * Benutzerverwaltung (§6.1).
 */

(() => {
    const ENDPUNKT = 'api/users.php';

    // --- Neuer Benutzer ----------------------------------------------------

    const neu = qs('#neu-formular');
    if (neu) {
        const hinweis = qs('#neu-fehler');
        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            hinweis.hidden = true;
            feldFehlerLeeren(neu);

            try {
                await apiFetch(ENDPUNKT, {
                    body: {
                        action: 'create',
                        name: qs('#name').value,
                        password: qs('#password').value,
                        is_admin: qs('#is_admin').checked,
                    },
                });
                window.location.reload();
            } catch (fehler) {
                feldFehlerZeigen(fehler, hinweis, neu);
            }
        });
    }

    const liste = qs('#benutzer-liste');
    if (!liste) return;

    function zeilenFehler(zeile, text) {
        const p = qs('.zeilen-fehler', zeile);
        p.textContent = text;
        p.hidden = false;
    }

    async function senden(zeile, koerper, erfolgstext) {
        qs('.zeilen-fehler', zeile).hidden = true;
        try {
            await apiFetch(ENDPUNKT, { body: koerper });
            meldung(erfolgstext, 'gut');
            window.location.reload();
            return true;
        } catch (fehler) {
            // Die Letzter-Admin-Regel erklärt sich in einem ganzen Satz.
            zeilenFehler(zeile, fehler.message);
            return false;
        }
    }

    // --- Umbenennen --------------------------------------------------------

    qsa('.umbenennen-formular', liste).forEach((formular) => {
        formular.addEventListener('submit', async (e) => {
            e.preventDefault();
            const zeile = formular.closest('.benutzer');
            const knopf = qs('button[type="submit"]', formular);
            knopf.disabled = true;
            const gut = await senden(zeile, {
                action: 'rename',
                id: Number(zeile.dataset.id),
                name: qs('.neuer-name', formular).value,
            }, 'Benutzer umbenannt.');
            if (!gut) knopf.disabled = false;
        });
    });

    // --- Passwort zurücksetzen ---------------------------------------------

    qsa('.reset-formular', liste).forEach((formular) => {
        formular.addEventListener('submit', async (e) => {
            e.preventDefault();
            const zeile = formular.closest('.benutzer');
            const knopf = qs('button[type="submit"]', formular);
            knopf.disabled = true;
            const gut = await senden(zeile, {
                action: 'reset_password',
                id: Number(zeile.dataset.id),
                password: qs('.neues-passwort', formular).value,
            }, 'Passwort gesetzt, Geräte abgemeldet.');
            if (!gut) knopf.disabled = false;
        });
    });

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('button');
        if (!knopf || knopf.disabled) return;

        const zeile = knopf.closest('.benutzer');
        const id = Number(zeile.dataset.id);
        const name = qs('strong', zeile).firstChild.textContent.trim();

        // Ein aufgeklapptes Formular schließt das andere: Zwei offene
        // Eingabefelder in einer Zeile sind auf dem Handy nicht mehr
        // auseinanderzuhalten.
        function aufklappen(wahl, feldWahl) {
            qsa('form', zeile).forEach((f) => {
                if (!f.matches(wahl)) f.hidden = true;
            });
            const formular = qs(wahl, zeile);
            formular.hidden = !formular.hidden;
            if (!formular.hidden) qs(feldWahl, formular).focus();
        }

        if (knopf.classList.contains('umbenennen')) {
            aufklappen('.umbenennen-formular', '.neuer-name');
            return;
        }

        if (knopf.classList.contains('zuruecksetzen')) {
            aufklappen('.reset-formular', '.neues-passwort');
            return;
        }

        // Gilt für beide Formulare -- deshalb closest('form') und nicht die
        // Klasse eines bestimmten.
        if (knopf.classList.contains('abbrechen')) {
            knopf.closest('form').hidden = true;
            return;
        }

        if (knopf.classList.contains('admin-an')) {
            if (!window.confirm('„' + name + '“ darf danach Übungen, Pläne und Benutzer verwalten. Fortfahren?')) {
                return;
            }
            senden(zeile, { action: 'set_admin', id, is_admin: true }, 'Adminrecht erteilt.');
            return;
        }

        if (knopf.classList.contains('admin-aus')) {
            senden(zeile, { action: 'set_admin', id, is_admin: false }, 'Adminrecht entzogen.');
            return;
        }

        if (knopf.classList.contains('loeschen')) {
            const einheiten = Number(zeile.dataset.einheiten);
            let frage = 'Benutzer „' + name + '“ endgültig löschen?\n\n'
                      + 'Pläne und angemeldete Geräte werden mitgelöscht.';
            if (einheiten > 0) {
                // §4.1: Die Zahl der betroffenen Einheiten wird vor dem Löschen
                // genannt und ausdrücklich bestätigt.
                frage += '\n\n' + einheiten + ' protokollierte Trainingseinheit(en) bleiben '
                       + 'erhalten, verlieren aber die Zuordnung zu diesem Benutzer.';
            }
            if (!window.confirm(frage)) return;

            knopf.disabled = true;
            const gut = await senden(zeile, { action: 'delete', id }, 'Benutzer gelöscht.');
            if (!gut) knopf.disabled = false;
        }
    });
})();
