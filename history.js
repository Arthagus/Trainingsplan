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
    const liste = qs('.liste-schlicht:not(.korrektur-liste)');
    if (!liste) return;

    liste.addEventListener('click', async (e) => {
        // Bearbeiten ist ein Link in die Korrekturansicht; die Rückfrage davor
        // ist die Sicherheitsabfrage aus §7.8. Gespeichert wird dort erst mit
        // einem eigenen Knopf — Abbrechen ändert nichts.
        const bearbeiten = e.target.closest('.einheit-bearbeiten');
        if (bearbeiten) {
            const datum = qs('.einheit-datum', bearbeiten.closest('[data-session]'))
                .textContent.trim();
            if (!window.confirm(
                'Einheit vom ' + datum + ' nachträglich bearbeiten?\n\n'
                + 'Geänderte Sätze gelten danach für Verlauf, Bestwerte und die '
                + 'Vorbelegung im nächsten Training.')) {
                e.preventDefault();
            }
            return;
        }

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


/**
 * Nachträgliche Korrektur einer abgeschlossenen Einheit (§7.8, Fallstrick 35).
 *
 * Je protokollierter Übung eine Satzliste zum Ändern, Nachtragen und
 * Entfernen. Gespeichert wird erst über „Änderungen speichern", und zwar nur,
 * was sich gegenüber dem Anfangsstand geändert hat — eine unberührte Übung
 * geht gar nicht erst über die Leitung.
 *
 * Die Zeile ist bewusst eine eigene, schlichtere Fassung als satzZeileMarkup()
 * in index.js: kein Stepper, keine Sperre nach dem Abhaken, keine
 * Warteschlange. Hier sitzt man nicht mit feuchten Fingern an der Maschine,
 * sondern trägt eine Zahl nach.
 */
(() => {
    const liste = qs('.korrektur-liste');
    if (!liste) return;

    const speichern = qs('.korrektur-speichern');

    function zeileMarkup(satz, nr, ausdauer, unterstuetzt) {
        const weg = '<button type="button" class="leise satz-weg"'
            + ' aria-label="' + (ausdauer ? 'Intervall ' : 'Satz ') + nr
            + ' löschen">✕</button>';

        if (ausdauer) {
            return '<li class="satz-zeile zeile-ausdauer">'
                + '<span class="satz-nr" aria-hidden="true">' + nr + '.</span>'
                + '<span class="wert-feld">'
                + '<input type="text" inputmode="numeric" pattern="[0-9]*"'
                + ' class="satz-distanz" placeholder="—"'
                + ' aria-label="Intervall ' + nr + ': Distanz in Metern"'
                + ' value="' + escapeHtml(satz.distanz) + '">'
                + '<span class="wert-einheit" aria-hidden="true">m</span>'
                + '</span>'
                + '<span class="satz-in" aria-hidden="true">in</span>'
                + '<span class="wert-feld">'
                + '<input type="text" inputmode="numeric" pattern="[0-9:]*"'
                + ' class="satz-dauer" placeholder="mm:ss"'
                + ' aria-label="Intervall ' + nr + ': Zeit als Minuten und Sekunden"'
                + ' value="' + escapeHtml(satz.dauer) + '">'
                + '</span>'
                + weg + '</li>';
        }

        return '<li class="satz-zeile">'
            + '<span class="satz-nr" aria-hidden="true">' + nr + '.</span>'
            + '<span class="wert-feld">'
            + '<input type="text" inputmode="numeric" pattern="[0-9]*"'
            + ' class="satz-reps" placeholder="—"'
            + ' aria-label="Satz ' + nr + ': Wiederholungen"'
            + ' value="' + escapeHtml(satz.reps) + '">'
            + '<span class="wert-einheit" aria-hidden="true">Wdh</span>'
            + '</span>'
            + '<span class="satz-mal" aria-hidden="true">×</span>'
            + '<span class="wert-feld">'
            + '<input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"'
            + ' class="satz-gewicht" placeholder="—"'
            + ' aria-label="Satz ' + nr + ': '
            + (unterstuetzt ? 'Unterstützung' : 'Gewicht') + ' in kg"'
            + ' value="' + escapeHtml(satz.weight) + '">'
            + '<span class="wert-einheit" aria-hidden="true">kg</span>'
            + '</span>'
            + weg + '</li>';
    }

    const istAusdauer = (karte) => karte.dataset.erfassung === 'ausdauer';

    /** Die Zeilen, wie sie gerade im DOM stehen — auch die leeren. */
    function lesen(karte) {
        const ausdauer = istAusdauer(karte);
        return qsa('.satz-zeile', karte).map((z) => ausdauer
            ? { distanz: qs('.satz-distanz', z).value.trim(),
                dauer: qs('.satz-dauer', z).value.trim() }
            : { reps: qs('.satz-reps', z).value.trim(),
                weight: qs('.satz-gewicht', z).value.trim() });
    }

    function zeichnen(karte, saetze) {
        const ausdauer = istAusdauer(karte);
        const unterstuetzt = karte.dataset.unterstuetzt === '1';
        qs('.satz-liste', karte).innerHTML = saetze
            .map((s, i) => zeileMarkup(s, i + 1, ausdauer, unterstuetzt)).join('');
    }

    /**
     * Die Nutzlast einer Übung. Eine ganz leere Zeile fällt heraus — sie ist
     * eine, die man angelegt und nicht ausgefüllt hat, und der Server wiese
     * sie mit 422 ab (Fallstrick 17).
     */
    function nutzlast(karte) {
        return lesen(karte).filter((s) => Object.values(s).some((w) => w !== ''));
    }

    // Anfangsstand aus den Daten der Seite; der Vergleich dagegen entscheidet,
    // was beim Speichern verschickt wird.
    const anfang = new Map();
    qsa('.korrektur-position', liste).forEach((karte) => {
        let daten = [];
        try {
            daten = JSON.parse(karte.dataset.saetze || '[]');
        } catch (_) {
            daten = [];
        }
        zeichnen(karte, daten.map((s) => ({
            reps: s.reps === null ? '' : String(s.reps),
            weight: zahlFuerAnzeige(s.weight),
            distanz: s.distanz_m === null ? '' : String(s.distanz_m),
            dauer: s.dauer_s === null ? '' : dauerMMSS(s.dauer_s),
        })));
        anfang.set(karte, JSON.stringify(nutzlast(karte)));
    });

    liste.addEventListener('click', (e) => {
        const karte = e.target.closest('.korrektur-position');
        if (!karte) return;

        if (e.target.closest('.satz-hinzu')) {
            // Ein neuer Satz beginnt mit den Werten des vorigen — meist ist es
            // derselbe Satz noch einmal, und geändert wird eine Zahl.
            const saetze = lesen(karte);
            const vorlage = saetze.length > 0
                ? { ...saetze[saetze.length - 1] }
                : { reps: '', weight: '', distanz: '', dauer: '' };
            saetze.push(vorlage);
            zeichnen(karte, saetze);
            const felder = qsa('.satz-zeile:last-child input', karte);
            if (felder.length > 0) felder[0].focus();
            return;
        }

        const weg = e.target.closest('.satz-weg');
        if (weg) {
            const zeilen = qsa('.satz-zeile', karte);
            const saetze = lesen(karte);
            saetze.splice(zeilen.indexOf(weg.closest('.satz-zeile')), 1);
            zeichnen(karte, saetze);
        }
    });

    if (!speichern) return;

    speichern.addEventListener('click', async () => {
        speichern.disabled = true;
        qsa('.zeilen-fehler', liste).forEach((f) => { f.hidden = true; });

        // Nacheinander und nicht gleichzeitig: Scheitert eine Übung, bleibt
        // sie mit ihrer Meldung stehen, und was davor lag, ist gespeichert —
        // ein zweiter Klick schickt nur noch, was sich weiterhin unterscheidet.
        for (const karte of qsa('.korrektur-position', liste)) {
            const saetze = nutzlast(karte);
            const stand = JSON.stringify(saetze);
            if (stand === anfang.get(karte)) continue;

            try {
                await apiFetch('api/log.php', {
                    body: {
                        action: 'correct',
                        log_id: Number(karte.dataset.log),
                        sets: saetze,
                    },
                });
                anfang.set(karte, stand);
            } catch (fehler) {
                const feld = qs('.zeilen-fehler', karte);
                feld.textContent = (fehler.fields && fehler.fields.sets) || fehler.message;
                feld.hidden = false;
                karte.scrollIntoView({ block: 'center' });
                speichern.disabled = false;
                return;
            }
        }

        window.location.href = liste.dataset.zurueck;
    });
})();
