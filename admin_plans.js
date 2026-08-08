'use strict';

/**
 * Planverwaltung (§6.4).
 */

(() => {
    const ENDPUNKT = 'api/plans.php';
    const liste = qs('#plan-liste');

    // --- Neuer Plan --------------------------------------------------------

    const neu = qs('#plan-neu');
    if (neu) {
        const hinweis = qs('#plan-neu-fehler');
        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            hinweis.hidden = true;
            try {
                await apiFetch(ENDPUNKT, {
                    body: {
                        action: 'create_plan',
                        user_id: Number(qs('input[name="user_id"]', neu).value),
                        name: qs('#plan_name').value,
                    },
                });
                window.location.reload();
            } catch (fehler) {
                hinweis.textContent = fehler.message;
                hinweis.hidden = false;
            }
        });
    }

    if (!liste) return;

    const userId = Number(liste.dataset.user);

    function zeilenFehler(zeile, text) {
        const p = qs('.zeilen-fehler', zeile);
        p.textContent = text;
        p.hidden = false;
    }

    /** Führt eine Aktion aus; bei Erfolg neu laden, bei Fehler an der Zeile melden. */
    async function senden(zeile, koerper, erfolgstext, neuLaden = true) {
        qs('.zeilen-fehler', zeile).hidden = true;
        try {
            await apiFetch(ENDPUNKT, { body: koerper });
            if (neuLaden) {
                window.location.reload();
            } else {
                meldung(erfolgstext, 'gut');
            }
            return true;
        } catch (fehler) {
            // Die Sperre bei offener Einheit erklärt sich in einem ganzen Satz —
            // der gehört an die Zeile, nicht in eine Kurzmeldung.
            zeilenFehler(zeile, fehler.message);
            return false;
        }
    }

    // --- Übungstausch (§7.5) ----------------------------------------------
    // Dieselben Vorschläge wie im Training, nur dauerhaft: ohne laufende
    // Einheit gibt es nichts, worauf ein befristeter Tausch sich bezöge.

    const tauschDialog = qs('#tausch-dialog');
    const tauschListe  = qs('#tausch-liste');
    const tauschFehler = qs('#tausch-fehler');
    let tauschPosition = null;

    qs('#tausch-schliessen').addEventListener('click', () => tauschDialog.close());

    async function tauschOeffnen(position) {
        tauschPosition = position;
        tauschFehler.hidden = true;
        tauschListe.innerHTML = '<p class="matt">Wird geladen …</p>';
        qs('#tausch-titel').textContent =
            'Ersatz für ' + qs('.position-titel', position).textContent.trim();
        tauschDialog.showModal();

        try {
            const daten = await apiFetch(ENDPUNKT, {
                body: {
                    action: 'swap_suggestions',
                    plan_exercise_id: Number(position.dataset.pe),
                },
            });

            if (!daten.suggestions.length) {
                tauschListe.innerHTML = keinVorschlagText(daten.im_plan);
                return;
            }

            tauschListe.innerHTML = daten.suggestions.map((v) => vorschlagMarkup(v,
                '<button type="button" class="waehlen">Übernehmen</button>')).join('');
        } catch (fehler) {
            tauschListe.innerHTML = '';
            tauschFehler.textContent = fehler.message;
            tauschFehler.hidden = false;
        }
    }

    tauschListe.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.waehlen');
        if (!knopf || !tauschPosition) return;

        knopf.disabled = true;
        tauschFehler.hidden = true;

        try {
            await apiFetch(ENDPUNKT, {
                body: {
                    action: 'swap_exercise',
                    plan_exercise_id: Number(tauschPosition.dataset.pe),
                    exercise_id: Number(knopf.closest('.vorschlag').dataset.id),
                },
            });
            // Name, Muskelgruppe und Bild der Zeile stimmen jetzt nicht mehr —
            // die Seite kommt frisch vom Server.
            window.location.reload();
        } catch (fehler) {
            tauschFehler.textContent = fehler.message;
            tauschFehler.hidden = false;
            knopf.disabled = false;
        }
    });

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('button');
        if (!knopf || knopf.disabled) return;

        const plan = knopf.closest('.plan');
        const planId = Number(plan.dataset.id);

        // --- Plan umsortieren (Rotationsreihenfolge) -----------------------
        if (knopf.classList.contains('plan-hoch') || knopf.classList.contains('plan-runter')) {
            const hoch = knopf.classList.contains('plan-hoch');
            const nachbar = hoch ? plan.previousElementSibling : plan.nextElementSibling;
            if (!nachbar) return;

            if (hoch) {
                liste.insertBefore(plan, nachbar);
            } else {
                liste.insertBefore(nachbar, plan);
            }

            const ids = qsa('.plan', liste).map((li) => Number(li.dataset.id));
            await senden(plan, { action: 'reorder_plans', user_id: userId, ids },
                'Reihenfolge gespeichert.');
            return;
        }

        if (knopf.classList.contains('plan-speichern')) {
            knopf.disabled = true;
            await senden(plan, {
                action: 'rename_plan',
                id: planId,
                name: qs('.plan-name', plan).value,
            }, 'Umbenannt.', false);
            knopf.disabled = false;
            return;
        }

        if (knopf.classList.contains('plan-loeschen')) {
            const name = qs('.plan-name', plan).value;
            const anzahl = qsa('.position', plan).length;
            if (!window.confirm('Plan „' + name + '“ mit ' + anzahl + ' Übung(en) löschen?\n\n'
                + 'Bereits protokollierte Trainingseinheiten bleiben erhalten.')) {
                return;
            }
            knopf.disabled = true;
            const gut = await senden(plan, { action: 'delete_plan', id: planId }, 'Gelöscht.');
            if (!gut) knopf.disabled = false;
            return;
        }

        // --- Übung hinzufügen ----------------------------------------------
        if (knopf.classList.contains('uebung-hinzu')) {
            const wahl = qs('.uebung-wahl', plan);
            const exerciseId = Number(wahl.value);
            if (!exerciseId) {
                zeilenFehler(plan, 'Bitte zuerst eine Übung auswählen.');
                return;
            }
            knopf.disabled = true;
            const gut = await senden(plan, {
                action: 'add_exercise', plan_id: planId, exercise_id: exerciseId,
            }, 'Hinzugefügt.');
            if (!gut) knopf.disabled = false;
            return;
        }

        // --- Position entfernen oder verschieben ---------------------------
        const position = knopf.closest('.position');
        if (!position) return;

        const peId = Number(position.dataset.pe);

        // Bild groß — derselbe Dialog wie im Training (assets/app.js).
        if (knopf.classList.contains('bild-knopf')) {
            const bild = qs('.position-bild', position);
            const beschreibung = qs('.beschreibung', position);
            bildGrossZeigen(
                qs('.position-titel', position).textContent.trim(),
                bild ? bild.getAttribute('src') : '',
                beschreibung ? beschreibung.textContent : ''
            );
            return;
        }

        if (knopf.classList.contains('pos-tauschen')) {
            tauschOeffnen(position);
            return;
        }

        if (knopf.classList.contains('pos-entfernen')) {
            const name = qs('.position-titel', position).textContent.trim();
            if (!window.confirm('„' + name + '“ aus dem Plan entfernen?\n\n'
                + 'Protokollierte Einträge dieser Position bleiben in der Historie.')) {
                return;
            }
            knopf.disabled = true;
            const gut = await senden(plan, {
                action: 'remove_exercise', plan_exercise_id: peId,
            }, 'Entfernt.');
            if (!gut) knopf.disabled = false;
            return;
        }

        if (knopf.classList.contains('pos-hoch') || knopf.classList.contains('pos-runter')) {
            const hoch = knopf.classList.contains('pos-hoch');
            const nachbar = hoch ? position.previousElementSibling : position.nextElementSibling;
            if (!nachbar) return;

            const eltern = position.parentElement;
            if (hoch) {
                eltern.insertBefore(position, nachbar);
            } else {
                eltern.insertBefore(nachbar, position);
            }

            const ids = qsa('.position', plan).map((li) => Number(li.dataset.pe));
            await senden(plan, { action: 'reorder_exercises', plan_id: planId, ids },
                'Reihenfolge gespeichert.', false);
        }
    });
})();
