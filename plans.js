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
                        split_id: Number(qs('input[name="split_id"]', neu).value),
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

    const splitId = Number(liste.dataset.split);
    const gesperrt = liste.dataset.gesperrt === '1';

    /**
     * Die einfachen Pfeile einer Planliste nachziehen: oben kein „↑", unten
     * kein „↓".
     *
     * Serverseitig setzt plans.php dieselbe Sperre. Hier steht sie ein zweites
     * Mal, weil das Umsortieren INNERHALB eines Plans der einzige Weg ist, der
     * die Seite nicht neu lädt (siehe reorder_exercises unten): Die Zeile
     * wandert im DOM, und ohne das hier behielte die alte erste Zeile ihren
     * toten Pfeil, während die neue erste einen anbietet, der ins Leere greift.
     * Alles andere — Nachbarplan, Entfernen, Hinzufügen, Pläne tauschen — lädt
     * neu und bekommt den Zustand vom Server.
     *
     * Bei laufender Einheit wird gar nicht erst umsortiert; die Sperre aus
     * $gesperrt bleibt dann unangetastet, statt hier versehentlich aufgehoben
     * zu werden.
     */
    function posPfeileNachziehen(plan) {
        if (gesperrt) return;
        const zeilen = qsa('.position', plan);
        zeilen.forEach((zeile, i) => {
            const hoch = qs('.pos-hoch', zeile);
            const runter = qs('.pos-runter', zeile);
            if (hoch) hoch.disabled = i === 0;
            if (runter) runter.disabled = i === zeilen.length - 1;
        });
    }

    function zeilenFehler(zeile, text) {
        const p = qs('.zeilen-fehler', zeile);
        p.textContent = text;
        p.hidden = false;
    }

    /**
     * Neu laden -- und bis dahin nichts Veraltetes mehr anbieten.
     *
     * Zwischen der gespeicherten Änderung und der neuen Seite liegt am Handy
     * leicht eine Sekunde. In diesem Fenster steht die alte Seite noch
     * vollständig bedienbar da: Man kann eine Übungsauswahl öffnen, deren
     * Hinweis „Schon in …" dann den frischen Stand nennt, während die Pläne
     * darunter noch den alten zeigen -- zwei Generationen auf einem
     * Bildschirm, und es sieht aus, als stimme der Hinweis nicht.
     *
     * Deshalb werden alle Knöpfe gesperrt, sobald das Neuladen angestoßen ist.
     * Der Klick-Verteiler unten steigt bei einem deaktivierten Knopf ohnehin
     * aus, also greift die Sperre auch für die Dialoge.
     */
    let neuLadenLaeuft = false;
    function neuLaden() {
        if (neuLadenLaeuft) return;
        neuLadenLaeuft = true;
        qsa('button').forEach((k) => { k.disabled = true; });
        window.location.reload();
    }

    /** Führt eine Aktion aus; bei Erfolg neu laden, bei Fehler an der Zeile melden. */
    async function senden(zeile, koerper, erfolgstext, neuLadenNach = true) {
        qs('.zeilen-fehler', zeile).hidden = true;
        try {
            await apiFetch(ENDPUNKT, { body: koerper });
            if (neuLadenNach) {
                neuLaden();
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

    // Jeder Ladevorgang bekommt eine Nummer, und nur der jeweils JÜNGSTE darf
    // die Liste zeichnen.
    //
    // Ohne das gewinnt die zuletzt eingetroffene Antwort, nicht die zuletzt
    // gestellte Frage: Zwei Abrufe können sich überholen — Dialog für Plan A
    // öffnen, schließen, gleich darauf Plan B öffnen; oder zwei Filter kurz
    // hintereinander umstellen. Trifft die ältere Antwort später ein,
    // überschreibt sie die neuere, und die Liste beschreibt dann einen Zustand,
    // den niemand mehr angefragt hat — samt Hinweis „Schon in …" und samt
    // „Bereits im Plan" zum falschen Plan. Erst ein Neuladen räumt das auf.
    let waehlenLauf = 0;

    // Die vollständigen Optionslisten einmal beim Laden sichern. Die Felder
    // werden gleich beschnitten — ohne diese Kopie wäre das ein Weg ohne
    // Rückweg, und die weggefilterten Einträge kämen nie wieder.
    const alleOptionen = (feld) =>
        Array.from(feld.options).map((o) => ({ value: o.value, text: o.textContent.trim() }));
    const gruppenOptionen = alleOptionen(waehlenGruppe);
    const geraeteOptionen = alleOptionen(waehlenGeraet);

    qs('#waehlen-schliessen').addEventListener('click', () => waehlenDialog.close());

    // Ein geschlossener Dialog nimmt keine Antwort mehr an -- am 'close' und
    // nicht am Schließen-Knopf, weil die Escape-Taste denselben Weg nimmt.
    // Sonst zeichnete ein Abruf, der beim Schließen noch unterwegs war, beim
    // nächsten Öffnen kurz die Liste des VORIGEN Plans.
    waehlenDialog.addEventListener('close', () => { waehlenLauf++; });

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

        const lauf = ++waehlenLauf;

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

            // Überholt: Inzwischen ist ein neuerer Abruf unterwegs (anderer
            // Plan, anderer Filter) oder der Dialog wurde geschlossen. Diese
            // Antwort ist damit die Auskunft auf eine Frage von gestern.
            if (lauf !== waehlenLauf) return;

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
            // Der Hinweis „Schon in …" kommt aus vorschlagMarkup() selbst — er
            // gehört zur Übung und gilt in allen drei Listen gleich.
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
            // Auch die Fehlermeldung gehört zu ihrem Abruf: Die eines
            // überholten Versuchs stünde sonst über einer Liste, die längst
            // geladen ist.
            if (lauf !== waehlenLauf) return;
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
            // Planänderung auch. Bis die Seite da ist, sagt der Dialog, was
            // gerade passiert; seine Liste beschreibt ab jetzt den Stand von
            // vorhin und darf nicht mehr wie eine Auskunft aussehen.
            waehlenListe.innerHTML =
                '<p class="matt">Hinzugefügt — die Seite wird neu geladen …</p>';
            neuLaden();
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
            neuLaden();
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

            // ALLE Pfeile der Liste sperren, bis die Antwort da ist — nicht nur
            // die dieser Karte. Zwei schnelle Tipps schickten sonst zwei
            // reorder_plans gleichzeitig los, und weil jeder die GANZE
            // Reihenfolge schreibt, gewinnt die zuletzt eingetroffene Antwort
            // und nicht die zuletzt gestellte Frage (dieselbe Falle wie bei der
            // Übungsauswahl, Fallstrick 28). Am schwachen Netz im Studio, wo
            // apiFetch zusätzlich wiederholt, ist das keine Theorie.
            //
            // Gesperrt und nicht in eine Warteschlange gelegt: Ein Pfeil, der
            // sich für einen Moment nicht drücken lässt, ist ehrlicher als
            // einer, der Tipps sammelt, die man nicht mehr sieht.
            const planPfeile = qsa('.plan-hoch, .plan-runter', liste);
            const vorherGesperrt = planPfeile.map((k) => k.disabled);
            planPfeile.forEach((k) => { k.disabled = true; });

            const ids = qsa('.plan', liste).map((li) => Number(li.dataset.id));
            const gut = await senden(plan, { action: 'reorder_plans', split_id: splitId, ids },
                'Reihenfolge gespeichert.');

            // Scheitert das Speichern, wird die Verschiebung ZURÜCKGENOMMEN — die
            // einzige Stelle dieser Seite, an der das nötig ist.
            //
            // Der Grund sind die Pfeile: Ihre Sperre kommt aus dem
            // Server-Rendering (oberster Plan kein ↑, unterster kein ↓). Bliebe
            // die Reihenfolge nach einem Fehlschlag verschoben, gehörte jede
            // Sperre zur falschen Karte — bei genau ZWEI Plänen wäre danach kein
            // Pfeil mehr benutzbar (einer gesperrt, der andere ohne Nachbarn,
            // siehe `if (!nachbar) return`), und selbst der zweite Versuch fiele
            // aus.
            //
            // Zurücknehmen statt Nachziehen, weil der Erfolgsfall hier ohnehin
            // neu lädt: Die verschobene Ansicht ist NUR im Fehlerfall zu sehen,
            // und dort ist „nichts bewegt" die Wahrheit — womit auch ⇈/⇊ und die
            // Rotationskette weiter stimmen, die beide am Plan hängen. Innerhalb
            // eines Plans ist es umgekehrt (posPfeileNachziehen()): Dort lädt
            // auch der Erfolg nicht neu, die Ansicht MUSS also vorgreifen.
            //
            // Aus demselben Grund kommen die Sperren nur hier zurück: Der Erfolg
            // lädt neu, und neuLaden() sperrt dabei absichtlich jeden Knopf.
            if (!gut) {
                if (hoch) {
                    liste.insertBefore(nachbar, plan);
                } else {
                    liste.insertBefore(plan, nachbar);
                }
                planPfeile.forEach((k, i) => { k.disabled = vorherGesperrt[i]; });
            }
            return;
        }

        if (knopf.classList.contains('plan-speichern')) {
            const name = qs('.plan-name', plan).value;
            knopf.disabled = true;
            const gut = await senden(plan, {
                action: 'rename_plan',
                id: planId,
                name,
            }, 'Umbenannt.', false);
            knopf.disabled = false;

            // Die Rotationsanzeige oben zieht nach. Sie steht in derselben
            // Seite und nennt denselben Plan -- ohne das behauptete sie bis zum
            // naechsten Neuladen den alten Namen, und zwei Stellen auf einem
            // Bildschirm widersprachen sich.
            //
            // Bewusst kein window.location.reload(): Umbenennen ist der eine
            // Fall, der die Seitenstruktur NICHT aendert, und ein Neuladen
            // risse einen mitten aus der Liste. Der Server hat den Namen zu
            // diesem Zeitpunkt schon geprueft und gespeichert -- was hier
            // nachgezogen wird, ist nur die Anzeige.
            if (gut) {
                qsa('[data-plan-name="' + planId + '"]').forEach((el) => {
                    el.textContent = name;
                });
            }
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

        if (knopf.classList.contains('pos-plan-hoch') || knopf.classList.contains('pos-plan-runter')) {
            // In den Nachbarplan. Anders als beim Sortieren wird hier NICHT
            // im DOM vorgegriffen: Die Zeile wandert in eine andere Liste,
            // beide Plaene aendern ihre Laenge, und die Rotationsvorschau
            // bleibt gleich -- das sauber im Browser nachzubauen waere mehr
            // Code als ein Neuladen wert ist.
            const hochP = knopf.classList.contains('pos-plan-hoch');
            knopf.disabled = true;
            const gut = await senden(plan, {
                action: 'move_exercise',
                plan_exercise_id: peId,
                direction: hochP ? 'up' : 'down',
            }, 'Verschoben.');
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

            // Gesperrt, solange gespeichert wird — derselbe Grund wie bei den
            // Plänen oben, nur trifft es hier härter: Dieser eine Weg lädt auch
            // im Erfolgsfall NICHT neu. Zwei überholende Antworten hinterließen
            // also eine Datenbank, die anders sortiert ist als der Bildschirm,
            // beide Aufrufe mit ok, und man sähe es erst beim nächsten Aufruf
            // der Seite.
            const pfeile = qsa('.pos-hoch, .pos-runter', plan);
            pfeile.forEach((k) => { k.disabled = true; });

            const ids = qsa('.position', plan).map((li) => Number(li.dataset.pe));
            await senden(plan, { action: 'reorder_exercises', plan_id: planId, ids },
                'Reihenfolge gespeichert.', false);

            // Hebt die Sperre wieder auf UND setzt sie neu, wo sie hingehört:
            // oben kein „↑", unten kein „↓". Auch nach einem Fehlschlag, denn
            // die Zeile bleibt dann liegen, wo sie liegt — die Ansicht ist hier
            // der Arbeitsstand, und die Pfeile müssen zu ihr passen.
            posPfeileNachziehen(plan);
        }
    });
})();
