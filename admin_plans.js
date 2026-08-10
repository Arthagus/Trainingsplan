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

    // --- Übung auswählen (§6.4) -------------------------------------------
    // Statt eines Pulldowns mit allen aktiven Übungen: ein Dialog, dessen Liste
    // sich nach Muskelgruppe und Trainingsgerät filtern lässt — einzeln oder
    // kombiniert. Bei dreistelligem Übungsbestand ist das der Unterschied
    // zwischen bedienbar und unbedienbar.

    const waehlenDialog = qs('#waehlen-dialog');
    const waehlenListe  = qs('#waehlen-liste');
    const waehlenFehler = qs('#waehlen-fehler');
    const waehlenGruppe = qs('#waehlen-gruppe');
    const waehlenGeraet = qs('#waehlen-geraet');
    let waehlenPlan = null;

    // Die vollständigen Optionslisten einmal beim Laden sichern. Die Felder
    // werden gleich beschnitten — ohne diese Kopie wäre das ein Weg ohne
    // Rückweg, und die weggefilterten Einträge kämen nie wieder.
    const alleOptionen = (feld) =>
        Array.from(feld.options).map((o) => ({ value: o.value, text: o.textContent.trim() }));
    const gruppenOptionen = alleOptionen(waehlenGruppe);
    const geraeteOptionen = alleOptionen(waehlenGeraet);

    qs('#waehlen-schliessen').addEventListener('click', () => waehlenDialog.close());

    /**
     * Beschränkt ein Auswahlfeld auf die Werte, die noch zu Treffern führen.
     *
     * Die erste Option („alle …", Wert '') bleibt immer stehen — sie ist der Weg
     * zurück. Ist die aktuelle Wahl nicht mehr dabei, meldet die Funktion das:
     * Der Aufrufer setzt dann zurück und lädt neu, statt eine garantiert leere
     * Liste stehen zu lassen.
     *
     * @param erlaubt Liste der zulässigen Werte, oder null für „alle"
     * @returns {boolean} true, wenn die bisherige Wahl entfallen ist
     */
    function auswahlBeschneiden(feld, vorrat, erlaubt) {
        const zulaessig = (wert) => erlaubt === null || erlaubt.includes(wert);
        const wahl = feld.value;
        const bleibt = wahl === '' || zulaessig(wahl);

        feld.innerHTML = '';
        // Werte als String vergleichen: Die Gruppen-IDs kommen als Zahl aus dem
        // JSON, im DOM ist jeder Optionswert Text.
        vorrat.forEach((o) => {
            if (o.value === '' || zulaessig(o.value)) {
                feld.add(new Option(o.text, o.value));
            }
        });

        feld.value = bleibt ? wahl : '';
        return !bleibt;
    }

    /** Lädt die Trefferliste zum aktuellen Plan und den aktuellen Filtern. */
    async function waehlenLaden(zweiterVersuch = false) {
        if (!waehlenPlan) return;

        waehlenFehler.hidden = true;
        waehlenListe.innerHTML = '<p class="matt">Wird geladen …</p>';

        try {
            const daten = await apiFetch(ENDPUNKT, {
                body: {
                    action: 'exercise_picker',
                    plan_id: Number(waehlenPlan.dataset.id),
                    group_id: waehlenGruppe.value ? Number(waehlenGruppe.value) : null,
                    equipment: waehlenGeraet.value,
                },
            });

            // Die Felder schränken sich gegenseitig ein: Nach der Wahl einer
            // Muskelgruppe stehen unter Trainingsgerät nur noch die Geräte, für
            // die es dort auch eine Übung gibt — und umgekehrt. Der Server
            // rechnet jede Facette ohne ihren eigenen Filter, deshalb bleibt der
            // Weg zurück auf „alle" immer offen.
            const raus = [
                auswahlBeschneiden(waehlenGruppe, gruppenOptionen,
                    daten.facetten.gruppen.map(String)),
                auswahlBeschneiden(waehlenGeraet, geraeteOptionen,
                    daten.facetten.geraete),
            ].some(Boolean);

            // Ein Wechsel kann die Wahl im anderen Feld ungültig machen: erst
            // Kurzhantel, dann eine Muskelgruppe ohne Kurzhantelübung. Die Wahl
            // steht dann auf „alle" und die Liste dazu muss neu geholt werden.
            // Genau einmal — danach ist beides '' und damit immer gültig.
            if (raus && !zweiterVersuch) {
                await waehlenLaden(true);
                return;
            }

            if (!daten.exercises.length) {
                waehlenListe.innerHTML =
                    '<p>Keine passende Übung. Mit weniger Filtern suchen oder unter '
                    + '<a href="admin_exercises.php">Übungen</a> eine anlegen.</p>';
                return;
            }

            // Was schon im Plan steht, bleibt sichtbar und wird nur gesperrt:
            // Herausgefiltert wüsste man nicht, ob die gesuchte Übung fehlt oder
            // längst dabei ist. Dieselbe Überlegung wie in der API.
            waehlenListe.innerHTML = daten.exercises.map((v) => vorschlagMarkup(v,
                v.im_plan
                    ? '<button type="button" disabled>Bereits im Plan</button>'
                    : '<button type="button" class="waehlen-hinzu"'
                      + (daten.gesperrt ? ' disabled' : '') + '>Hinzufügen</button>'
            )).join('');

            if (daten.gesperrt) {
                // Ein zweiter Tab kann den Dialog geöffnet haben, nachdem hier
                // ein Training gestartet wurde. Dann sagt es die Auswahl, statt
                // den Benutzer in ein 409 laufen zu lassen.
                waehlenFehler.textContent =
                    'Dieser Benutzer trainiert gerade — solange lässt sich der Plan '
                    + 'nicht ändern.';
                waehlenFehler.hidden = false;
            }
        } catch (fehler) {
            waehlenListe.innerHTML = '';
            waehlenFehler.textContent = fehler.message;
            waehlenFehler.hidden = false;
        }
    }

    function waehlenOeffnen(plan) {
        waehlenPlan = plan;
        qs('#waehlen-titel').textContent =
            'Übung hinzufügen zu ' + qs('.plan-name', plan).value.trim();
        // Beide Felder auf den vollen Vorrat zurück, bevor neu beschnitten wird.
        // Die getroffene Wahl bleibt dabei stehen — wer nacheinander mehrere
        // Rückenübungen aufnimmt, will den Filter nicht jedes Mal neu setzen.
        // Ohne diesen Schritt behielte das Feld dagegen die Einschränkung der
        // letzten Suche, und Einträge fehlten ohne erkennbaren Grund.
        auswahlBeschneiden(waehlenGruppe, gruppenOptionen, null);
        auswahlBeschneiden(waehlenGeraet, geraeteOptionen, null);
        waehlenDialog.showModal();
        waehlenLaden();
    }

    // Die Filter laden nur die Liste neu — der Dialog bleibt offen, sonst wäre
    // ein zweiter Filterversuch ein zweiter Weg durch die ganze Maske.
    waehlenGruppe.addEventListener('change', waehlenLaden);
    waehlenGeraet.addEventListener('change', waehlenLaden);

    waehlenListe.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.waehlen-hinzu');
        if (!knopf || !waehlenPlan) return;

        knopf.disabled = true;
        waehlenFehler.hidden = true;

        try {
            await apiFetch(ENDPUNKT, {
                body: {
                    action: 'add_exercise',
                    plan_id: Number(waehlenPlan.dataset.id),
                    exercise_id: Number(knopf.closest('.vorschlag').dataset.id),
                },
            });
            // Die neue Position kommt server-gerendert — wie bei jeder anderen
            // Planänderung auch.
            window.location.reload();
        } catch (fehler) {
            waehlenFehler.textContent = fehler.message;
            waehlenFehler.hidden = false;
            knopf.disabled = false;
        }
    });

    // --- Übungstausch (§7.5) ----------------------------------------------
    // Dieselben Vorschläge wie im Training, nur dauerhaft: ohne laufende
    // Einheit gibt es nichts, worauf ein befristeter Tausch sich bezöge.

    const tauschDialog = qs('#tausch-dialog');
    const tauschListe  = qs('#tausch-liste');
    const tauschFehler = qs('#tausch-fehler');
    const tauschFilter = qs('.tausch-filter');
    const tauschGeraet = qs('#tausch-geraet');
    let tauschPosition = null;
    let tauschVorschlaege = [];

    qs('#tausch-schliessen').addEventListener('click', () => tauschDialog.close());

    // Nur dauerhaft — ohne laufende Einheit gibt es nichts, worauf ein
    // befristeter Tausch sich bezöge.
    const TAUSCH_KNOEPFE = '<button type="button" class="waehlen">Übernehmen</button>';

    /** Zeichnet die Vorschlagsliste, gefiltert auf das gewählte Gerät. */
    function tauschZeichnen() {
        tauschListe.innerHTML = geraetGefiltert(tauschVorschlaege, tauschGeraet.value)
            .map((v) => vorschlagMarkup(v, TAUSCH_KNOEPFE)).join('');
    }

    tauschGeraet.addEventListener('change', tauschZeichnen);

    async function tauschOeffnen(position) {
        tauschPosition = position;
        tauschFehler.hidden = true;
        // Der Filter der vorigen Position darf nicht stehen bleiben — sonst
        // zeigte der Dialog beim Öffnen eine schon eingeschränkte und scheinbar
        // unvollständige Liste.
        tauschVorschlaege = [];
        tauschFilter.hidden = true;
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

            tauschVorschlaege = daten.suggestions;
            tauschFilter.hidden = !geraetFilterFuellen(tauschGeraet, tauschVorschlaege);
            tauschZeichnen();
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
        // Öffnet nur den Auswahldialog; geschickt wird erst dort (§6.4).
        if (knopf.classList.contains('uebung-waehlen')) {
            waehlenOeffnen(plan);
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
