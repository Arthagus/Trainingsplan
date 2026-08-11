'use strict';

/**
 * Handy-Ansicht (§7.2 bis §7.6).
 */

(() => {
    const liste = qs('#uebungen');

    // Die Warteschlange arbeitet ausschliesslich innerhalb einer LAUFENDEN
    // Einheit (§7.4). Ohne offene Einheit bleibt es beim direkten Aufruf.
    //
    // Das ist eine bewusste Grenze, keine Luecke: Wuerde das erste Haekchen
    // auch offline angenommen, muesste die Anzeige eine Einheit zeigen, die es
    // serverseitig noch gar nicht gibt -- ohne Startzeit, ohne session_id, mit
    // einem "x/n", das sich an nichts messen laesst. Und beim Nachholen waere
    // nicht mehr entscheidbar, in welche Einheit die Eintraege gehoeren.
    //
    // Ein Training zu starten ist eine Handlung pro Besuch; darauf einen
    // Moment auf Empfang zu warten ist zumutbar. Am Geraet festzustecken,
    // waehrend die Uhr laeuft, ist es nicht.
    //
    // Angelegt wird sie trotzdem immer: Ohne offene Einheit ist sie nicht
    // `aktiv`, raeumt beim ersten Lesen aber eine liegengebliebene Ablage weg.
    const sessionId = liste ? Number(liste.dataset.session || 0) : 0;
    const userId    = liste ? Number(liste.dataset.user || 0) : 0;
    const schlange  = warteschlange(userId, sessionId);

    // Expertenmodus: Statt einem Gewicht je Übung wird jeder Satz einzeln
    // erfasst (§7.4). Die Umschaltung sitzt auf der Kontoseite und ist bei
    // laufender Einheit gesperrt — hier ist der Wert deshalb für die Dauer der
    // Seite fest.
    const experte = liste ? liste.dataset.experte === '1' : false;

    // Änderungen an Sätzen werden gebündelt geschickt: Wer dreimal auf „+"
    // tippt, soll einen Aufruf auslösen und nicht drei.
    const SATZ_SPEICHER_VERZUG_MS = 800;
    const WDH_GRENZE = 200;   // muss zu WDH_MAX in api/log.php passen

    const satzWartend = new Set();
    let satzUhr = null;

    // --- Einheit beenden ---------------------------------------------------

    async function einheitBeenden() {
        // Erst das, was noch im Verzug hängt: Ein Satz, der auf seinen
        // Sammel-Zeitgeber wartet, steht noch gar nicht in der Warteschlange —
        // die Prüfung darunter sähe ihn also nicht und die Einheit schlösse
        // über ihn hinweg.
        await satzSpeichernJetzt();

        // Erst muss alles Protokollierte beim Server sein. Sonst schloesse die
        // Einheit, waehrend noch Haekchen in der Ablage liegen -- die liefen
        // danach ins Leere, weil api/log.php ohne offene Einheit ablehnt.
        if (schlange.aktiv && schlange.anzahl() > 0) {
            meldung('Es sind noch Eingaben nicht gespeichert — bitte kurz warten.', 'fehler');
            abarbeiten();
            return;
        }

        try {
            await apiFetch('api/session.php', { body: { action: 'end' } });
            window.location.reload();
        } catch (fehler) {
            // 409 heisst "es laeuft keine Einheit" -- genau das war das Ziel.
            // Typischer Hergang bei schlechtem Empfang: Der erste Aufruf kam
            // durch, seine ANTWORT ging verloren, der Benutzer tippt erneut.
            // Eine Fehlermeldung waere hier schlicht falsch.
            if (fehler.status === 409) {
                window.location.reload();
                return;
            }
            meldung(fehler.message, 'fehler');
        }
    }

    /**
     * Merkt über den Seitenwechsel hinweg, dass zur aktiven Übung gesprungen
     * werden soll.
     *
     * `sessionStorage` und nicht `localStorage`: Das gilt für genau diesen Tab
     * und diesen Moment. Bliebe es liegen, spränge die Seite beim nächsten
     * Öffnen grundlos.
     */
    const SPRUNG_SCHLUESSEL = 'trainingsplan-sprung-zur-aktiven';

    function merkeSprungZurAktiven() {
        try {
            window.sessionStorage.setItem(SPRUNG_SCHLUESSEL, '1');
        } catch (e) {
            // Privater Modus: Dann wird eben nicht gescrollt.
        }
    }

    function sprungAbholen() {
        try {
            if (window.sessionStorage.getItem(SPRUNG_SCHLUESSEL) !== '1') return false;
            window.sessionStorage.removeItem(SPRUNG_SCHLUESSEL);
            return true;
        } catch (e) {
            return false;
        }
    }

    // Punkt 7: Einheit ausdrücklich starten, damit der Zeitstempel den
    // Trainingsbeginn festhält und nicht das Ende der ersten Übung.
    const starten = qs('#einheit-starten');
    if (starten) {
        starten.addEventListener('click', async () => {
            starten.disabled = true;
            try {
                // wiederholen erlaubt: aktion_starten() liefert die bereits
                // laufende Einheit zurueck, statt einen Fehler zu werfen.
                await apiFetch('api/session.php', {
                    wiederholen: true,
                    body: { action: 'start', plan_id: Number(starten.dataset.plan) },
                });
                // Die Seite kommt gleich neu — erst danach gibt es eine
                // laufende Einheit und damit eine aktive Übung. Der Merker
                // sagt der neuen Seite, dass sie dorthin scrollen soll.
                merkeSprungZurAktiven();
                window.location.reload();
            } catch (fehler) {
                meldung(fehler.message, 'fehler');
                starten.disabled = false;
            }
        });
    }

    ['#einheit-beenden', '#einheit-beenden-notfall'].forEach((wahl) => {
        const knopf = qs(wahl);
        if (!knopf) return;
        knopf.addEventListener('click', () => {
            if (window.confirm('Training wirklich beenden?')) einheitBeenden();
        });
    });

    if (!liste) return;

    // --- Fortschritt -------------------------------------------------------

    /**
     * Schreibt „x/n" fort und fragt bei Vollständigkeit nach.
     *
     * Die Einheit schließt sich NIE von selbst (§7.6): Sonst wäre das
     * Ab-wählen eines versehentlichen Häkchens undefiniert, und der Bildschirm
     * wechselte, während man noch am Gerät steht.
     */
    function fortschrittSetzen(daten) {
        if (daten.gesamt === undefined) return;

        const text = qs('#fortschritt-text');
        if (text) text.textContent = daten.erledigt_anzahl + '/' + daten.gesamt;

        liste.dataset.erledigt = daten.erledigt_anzahl;
        liste.dataset.gesamt = daten.gesamt;
    }

    /**
     * Schreibt „x/n" aus dem fort, was auf dem Bildschirm steht.
     *
     * Solange Eintraege in der Warteschlange liegen, kennt der Server den
     * Stand noch nicht -- die Zeilen sind die Wahrheit. Sobald die Schlange
     * leer ist, uebernimmt wieder fortschrittSetzen() mit der Serverzahl.
     */
    function fortschrittLokal() {
        const text = qs('#fortschritt-text');
        if (!text) return;

        const x = qsa('.position-karte.zeile-erledigt', liste).length;
        text.textContent = x + '/' + liste.dataset.gesamt;
        liste.dataset.erledigt = x;
    }

    /**
     * Fragt nach, wenn alle Positionen abgehakt sind, und lädt danach neu,
     * falls die Einheit gerade erst entstanden ist.
     *
     * Die Reihenfolge ist wichtig: Bei einem Plan mit nur einer Übung setzt
     * dasselbe Häkchen die Einheit in Gang UND macht sie vollständig. Ein
     * sofortiger Reload würde die Rückfrage wegräumen, bevor sie jemand sieht.
     */
    function abschlussFrage(daten, brauchtReload) {
        // Kurz warten, damit das Häkchen sichtbar gesetzt ist, bevor der
        // Dialog den Bildschirm blockiert.
        setTimeout(() => {
            if (daten.alle_erledigt
                && window.confirm('Alle Übungen erledigt — Training beenden?')) {
                einheitBeenden();   // lädt selbst neu
                return;
            }
            if (brauchtReload) window.location.reload();
        }, 150);
    }

    // --- Fehlerbehandlung je Zeile ----------------------------------------

    /**
     * Ein fehlgeschlagenes Speichern wird nie verschluckt (§2): Das Häkchen
     * geht sichtbar in den vorherigen Zustand zurück, die Zeile wird als
     * fehlerhaft markiert und ein Wiederholen-Knopf erscheint.
     */
    function fehlerZeigen(karte, fehler, wiederholung) {
        const p = qs('.zeilen-fehler', karte);
        p.textContent = fehler.message;
        p.hidden = false;

        karte.classList.add('zeile-fehler');

        const knopf = qs('.wiederholen', karte);
        knopf.hidden = false;
        knopf.onclick = () => {
            knopf.hidden = true;
            wiederholung();
        };
    }

    function fehlerLeeren(karte) {
        qs('.zeilen-fehler', karte).hidden = true;
        karte.classList.remove('zeile-fehler');
        qs('.wiederholen', karte).hidden = true;
    }

    /**
     * Markiert eine Zeile als „gesetzt, aber noch nicht bestaetigt".
     *
     * Das ist die Fortschreibung der Regel „Fehler nie stillschweigend
     * verschlucken" (§2) unter schlechtem Netz: Frueher sprang das Haekchen
     * zurueck, sobald der Aufruf scheiterte -- korrekt, aber im Studio
     * unbrauchbar, weil man mitten im Satz nicht weiss, ob man nochmal tippen
     * soll. Jetzt bleibt es stehen und traegt sichtbar den Vorbehalt.
     *
     * Der Vorbehalt ist ausschliesslich der GESTRICHELTE Balken am linken Rand
     * -- eine Aenderung, die nichts verschiebt. Ein Hinweissatz in der Karte
     * (bis 1.1.1) machte sie fuer die Dauer des Speicherns hoeher und danach
     * wieder niedriger; bei jedem Satz sprang dadurch die ganze Liste darunter.
     * Wie viele Eingaben ausstehen, sagt die Leiste am oberen Rand.
     */
    function wartendSetzen(karte, wartet) {
        karte.classList.toggle('zeile-wartet', wartet);
    }

    /**
     * Zieht die „hier bist du"-Markierung auf die erste noch offene Position.
     *
     * Ohne sie weiss man beim Scrollen durch acht Karten nicht mehr, an welchem
     * Geraet man eigentlich steht. Nur waehrend eines Trainings: Wer den Plan
     * bloss anschaut, bekommt eine ruhige Liste ohne Aussage darueber, wo er
     * angeblich gerade ist.
     */
    function aktiveMarkieren() {
        const karten = qsa('.position-karte', liste);
        const aktive = sessionId > 0 ? karten.find((k) => !istErledigt(k)) : null;

        karten.forEach((k) => {
            const istAktiv = k === aktive;
            k.classList.toggle('zeile-aktiv', istAktiv);
            // Grau bleibt, was weder erledigt noch dran ist.
            k.classList.toggle('zeile-offen', !istAktiv && !istErledigt(k));
        });
    }

    function zustandSetzen(karte, erledigt) {
        karte.classList.toggle('zeile-erledigt', erledigt);
        qs('.erledigt', karte).checked = erledigt;
        // Aktiv/offen entscheidet sich erst im Vergleich aller Karten.
        aktiveMarkieren();
        // Abgehakt heißt auch: an den Sätzen ist nichts mehr zu ändern.
        saetzeSperren(karte);

        // Gesperrt wird ab dem ersten protokollierten Satz, nicht erst ab dem
        // Häkchen: Der Eintrag hält fest, was tatsächlich gemacht wurde, und
        // ihn beim Tausch auf eine andere Übung umzuschreiben schriebe ein
        // erreichtes Gewicht einer Übung zu, die gar nicht gemacht wurde (§7.5).
        const saetze = saetzeFuerServer(karte);
        const protokolliert = erledigt || (saetze !== null && saetze.length > 0);

        // Abgehakt heißt: Der Wert steht fest. Ändern geht nur über Häkchen
        // entfernen, korrigieren, neu abhaken (§7.4).
        //
        // Im Expertenmodus gibt es dieses Feld nicht, und die Satzliste bleibt
        // bewusst offen: Dort ist das Nachtragen weiterer Sätze der Normalfall
        // und nicht die Korrektur — wer nach Satz 1 erst das Häkchen entfernen
        // müsste, um Satz 2 einzutragen, käme keine drei Übungen weit. Fest
        // stehen die Werte mit dem Ende der Einheit.
        const gewicht = qs('.gewicht', karte);
        if (gewicht) gewicht.readOnly = erledigt;

        // Aus demselben Grund lässt sich eine abgehakte Position nicht tauschen
        // (§7.5) — der Protokolleintrag hält fest, was tatsächlich gemacht
        // wurde. Der Server weist es ohnehin ab; hier wird es gar nicht erst
        // angeboten.
        const tausch = qs('.tauschen', karte);
        tausch.disabled = protokolliert;
        if (protokolliert) {
            tausch.title = experte
                ? 'Erst die protokollierten Sätze entfernen'
                : 'Erst das Häkchen entfernen';
        } else {
            tausch.removeAttribute('title');
        }
    }

    // --- Sätze (Expertenmodus) ---------------------------------------------

    /**
     * Eine Satzzeile als Markup.
     *
     * Die EINZIGE Stelle, an der eine Satzzeile entsteht — auch index.php
     * rendert sie nicht, sondern liefert die Werte als JSON im Attribut. Die
     * Zeile ist ein Bedienelement, das sich im Betrieb ständig ändert; zwei
     * Fassungen davon wären irgendwann verschieden.
     */
    function satzZeileMarkup(satz, nr) {
        return '<li class="satz-zeile" data-nr="' + nr + '">'
            + '<span class="satz-nr" aria-hidden="true">' + nr + '.</span>'
            + '<span class="stepper">'
            + '<button type="button" class="leise satz-minus"'
            + ' aria-label="Satz ' + nr + ': eine Wiederholung weniger">−</button>'
            + '<input type="text" inputmode="numeric" pattern="[0-9]*"'
            + ' class="satz-reps" enterkeyhint="done" placeholder="—"'
            + ' aria-label="Satz ' + nr + ': Wiederholungen"'
            + ' value="' + escapeHtml(satz.reps) + '">'
            + '<button type="button" class="leise satz-plus"'
            + ' aria-label="Satz ' + nr + ': eine Wiederholung mehr">+</button>'
            + '</span>'
            + '<span class="satz-mal" aria-hidden="true">×</span>'
            + '<span class="wert-feld">'
            + '<input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"'
            + ' class="satz-gewicht" enterkeyhint="done" placeholder="—"'
            + ' aria-label="Satz ' + nr + ': Gewicht in kg"'
            + ' value="' + escapeHtml(satz.weight) + '">'
            + '<span class="wert-einheit" aria-hidden="true">kg</span>'
            + '</span>'
            + '<button type="button" class="leise satz-weg"'
            + ' aria-label="Satz ' + nr + ' löschen">✕</button>'
            + '</li>';
    }

    /**
     * Die Sätze einer Karte, wie sie gerade im DOM stehen.
     *
     * Rückgabe null außerhalb des Expertenmodus — das unterscheidet „keine
     * Satzliste dabei" von „Satzliste, die leer ist", und api/log.php macht
     * dieselbe Unterscheidung.
     */
    function saetzeLesen(karte) {
        if (!experte) return null;

        return qsa('.satz-zeile', karte).map((zeile) => ({
            reps: qs('.satz-reps', zeile).value.trim(),
            weight: qs('.satz-gewicht', zeile).value.trim(),
        }));
    }

    /**
     * Was davon zum Server geht: alles außer den noch leeren Zeilen.
     *
     * Eine frisch über „+ Satz" angelegte Zeile ist zunächst leer, wenn es für
     * diese Übung noch keine Vorlage gibt — sie ist zum Ausfüllen da. Schickte
     * man sie mit, lehnte `api/log.php` sie zu Recht mit 422 ab („Wiederholungen
     * oder Gewicht angeben"), und die Zeile bekäme einen roten Rand samt
     * „Erneut versuchen", obwohl der Benutzer nichts falsch gemacht hat.
     */
    function saetzeFuerServer(karte) {
        const alle = saetzeLesen(karte);
        if (alle === null) return null;

        return alle.filter((s) => s.reps !== '' || s.weight !== '');
    }

    /** Ist die Position als fertig markiert? */
    function istErledigt(karte) {
        return qs('.erledigt', karte).checked;
    }

    /** Wandelt die Server-Fassung (Zahlen, null) in die Anzeigefassung um. */
    function satzAusDaten(satz) {
        return {
            reps: satz.reps === null || satz.reps === undefined ? '' : String(satz.reps),
            weight: satz.weight === null || satz.weight === undefined
                ? '' : zahlFuerAnzeige(satz.weight),
        };
    }

    function satzListeAusDaten(rohtext) {
        try {
            const liste = JSON.parse(rohtext || '[]');
            return Array.isArray(liste) ? liste.map(satzAusDaten) : [];
        } catch (e) {
            return [];
        }
    }

    /** „12×40 · 10×40 · 9×45" — das Gegenstück zu saetze_text() in PHP. */
    function saetzeText(saetze) {
        return saetze
            .map((s) => (s.reps || '?') + '×' + (s.weight || '—'))
            .join(' · ');
    }

    /**
     * „3 Sätze (12×45 · 10×45 · 8×50)" — Gegenstück zu saetze_zusammenfassung()
     * in lib/training.php. Die Schreibweise muss dort und hier dieselbe sein:
     * Die Zeile „zuletzt …" (server-gerendert) und dieser Kopf (hier gebaut)
     * stehen am Handy direkt übereinander.
     */
    function saetzeZusammenfassung(saetze) {
        if (saetze.length === 0) return 'Noch kein Satz';

        const anzahl = saetze.length + (saetze.length === 1 ? ' Satz' : ' Sätze');

        return anzahl + ' (' + saetzeText(saetze) + ')';
    }

    /**
     * Zeichnet Satzliste, Zusammenfassung und die Beschriftung von „+ Satz".
     *
     * Der Vorschlag steht im Knopf, damit vor dem Tippen sichtbar ist, was
     * gleich entsteht — im Studio ist das der Unterschied zwischen einem Tipp
     * und einem Tipp plus Korrektur.
     */
    function saetzeZeichnen(karte, saetze, zeilenNeu) {
        const block = qs('.saetze-block', karte);
        if (!block) return;

        // Die Zeilen NUR neu bauen, wenn sich ihre Anzahl geändert hat. Beim
        // Tippen und beim Stepper stehen die Felder schon richtig — sie über
        // innerHTML zu ersetzen risse dem Benutzer den Fokus und die
        // Cursorposition mitten aus der Eingabe.
        if (zeilenNeu) {
            qs('.satz-liste', block).innerHTML =
                saetze.map((s, i) => satzZeileMarkup(s, i + 1)).join('');
        }

        const zusammen = qs('.saetze-zusammenfassung', block);
        if (zusammen) {
            zusammen.textContent = saetzeZusammenfassung(saetze);
        }

        const knopf = qs('.satz-hinzu', block);
        const naechster = naechsterSatz(karte, saetze);
        knopf.textContent = (naechster.reps || naechster.weight)
            ? '+ Satz (' + (naechster.reps || '?') + ' × ' + (naechster.weight || '—') + ')'
            : '+ Satz';

        // Nach jedem Neuzeichnen erneut sperren: Die Zeilen entstehen über
        // innerHTML neu und wüssten sonst nichts vom Häkchen.
        saetzeSperren(karte);
    }

    /**
     * Sperrt die Satzliste, sobald die Übung abgehakt ist (§7.4).
     *
     * „Erledigt" heißt seit `1.1.1`: fertig mit dieser Übung. Dann stehen die
     * Werte fest — geändert wird über Häkchen entfernen, korrigieren, neu
     * abhaken. Das ist derselbe eine Mechanismus wie beim Gewichtsfeld im
     * einfachen Modus und beim Übungstausch (§7.5).
     *
     * In `1.1.0` war das noch anders begründet und richtig so: Damals hakte der
     * erste Satz die Übung selbst ab, ein Sperren hätte also das Nachtragen des
     * zweiten Satzes verhindert. Mit dem Schalter aus `1.1.1` ist dieses
     * Argument entfallen — wer noch einen Satz machen will, hat schlicht noch
     * nicht abgehakt.
     *
     * Das hier ist nur die Bequemlichkeit davor; verboten wird es in
     * `api/log.php` (§5-Muster: gesperrt wird serverseitig).
     */
    function saetzeSperren(karte) {
        const block = qs('.saetze-block', karte);
        if (!block) return;

        const fest = istErledigt(karte);
        const grund = 'Erst das Häkchen entfernen';

        qsa('.satz-reps, .satz-gewicht', block).forEach((feld) => {
            feld.readOnly = fest;
        });

        qsa('.satz-minus, .satz-plus, .satz-weg, .satz-hinzu', block).forEach((k) => {
            k.disabled = fest;
            if (fest) {
                k.title = grund;
            } else {
                k.removeAttribute('title');
            }
        });

        block.classList.toggle('saetze-fest', fest);
    }

    /** Schreibt die Sätze in die Karte zurück und zeichnet sie neu. */
    function saetzeSetzen(karte, saetze, zeilenNeu = true) {
        karte.dataset.saetze = JSON.stringify(saetze.map((s) => ({
            reps: s.reps === '' ? null : Number(s.reps),
            weight: s.weight === '' ? null : zahlAusEingabe(s.weight),
        })));
        saetzeZeichnen(karte, saetze, zeilenNeu);
    }

    /**
     * Die Vorbelegung für den nächsten Satz.
     *
     * Satz k bekommt Satz k vom LETZTEN MAL — nicht den vorherigen Satz von
     * heute. Genau das macht den Modus im Studio schnell: Wer 12/10/9 gewohnt
     * ist, bekommt beim dritten Antippen 9 vorgeschlagen und nicht 10, und die
     * ganze Satzfolge entsteht mit drei Tipps ohne eine einzige Korrektur.
     *
     * Erst wenn das letzte Mal weniger Sätze hatte, gilt der vorherige Satz von
     * heute — und wenn es gar keine Vorlage gibt, das zuletzt bekannte Gewicht
     * dieser Übung mit leerem Wiederholungsfeld.
     */
    function naechsterSatz(karte, saetze) {
        const nr = saetze.length + 1;
        const letzte = satzListeAusDaten(karte.dataset.letzteSaetze);

        if (letzte.length >= nr) return letzte[nr - 1];
        if (saetze.length > 0)   return saetze[saetze.length - 1];
        if (letzte.length > 0)   return letzte[letzte.length - 1];

        return { reps: '', weight: karte.dataset.letztesGewicht || '' };
    }

    /**
     * Sammelt Änderungen und schickt sie gebündelt.
     *
     * Ohne den Verzug löste jeder Tipp auf „−"/„+" einen eigenen Aufruf aus.
     * Die Warteschlange hielte zwar trotzdem nur einen Eintrag je Position,
     * aber im Studio-Netz wäre jeder dieser Aufrufe ein Zeitlimit, das
     * abläuft.
     */
    function satzSpeichernSpaeter(karte) {
        satzWartend.add(karte);
        clearTimeout(satzUhr);
        satzUhr = setTimeout(satzSpeichernJetzt, SATZ_SPEICHER_VERZUG_MS);
    }

    /**
     * Löst den Verzug sofort aus.
     *
     * Pflicht vor jeder Handlung, die auf einen VOLLSTÄNDIGEN Stand angewiesen
     * ist — Beenden und Tauschen. Beide prüfen die Warteschlange, und ein
     * Eintrag, der noch im Zeitgeber hängt, steht dort noch nicht drin.
     */
    async function satzSpeichernJetzt() {
        clearTimeout(satzUhr);
        satzUhr = null;

        if (satzWartend.size === 0) return;

        const karten = Array.from(satzWartend);
        satzWartend.clear();

        for (const karte of karten) {
            const saetze = saetzeFuerServer(karte) || [];

            // Nichts einzutragen, nichts abgehakt, und serverseitig steht auch
            // nichts: Dann gäbe es nur ein „uncheck" auf eine Zeile, die es nie
            // gab. Genau das passiert nach einem „+ Satz", das noch leer ist —
            // und im Studio-Netz ist jeder überflüssige Aufruf ein Zeitlimit,
            // das ablaufen kann.
            if (saetze.length === 0 && !istErledigt(karte)
                && karte.dataset.eintrag !== '1') {
                continue;
            }

            // Das Häkchen bleibt, wie es ist — Sätze einzutragen heißt nicht,
            // mit der Übung fertig zu sein.
            await abhaken(karte, istErledigt(karte));
        }
    }

    /**
     * Nach dem Abhaken: Satzblock zu, weiter zur nächsten offenen Übung.
     *
     * Im Studio steht man mit dem Handy in der Hand am Gerät. Wer gerade fertig
     * geworden ist, will als Nächstes wissen, wo es weitergeht — und nicht
     * scrollen, um die Übung zu suchen, die ohnehin an der Reihe ist.
     */
    function weiterZurNaechsten(karte) {
        const block = qs('.saetze-block', karte);
        if (block) block.open = false;

        zurAktivenSpringen();
    }

    /**
     * Klappt den Satzblock der aktiven Übung auf und scrollt sie in den Blick.
     *
     * Zwei Aufrufer: nach dem Abhaken einer Übung und nach dem Start eines
     * Trainings. Beide Male ist die Frage dieselbe — „wo geht es weiter?".
     */
    function zurAktivenSpringen() {
        const aktive = qsa('.position-karte', liste).find((k) => !istErledigt(k));
        if (!aktive) return;

        const block = qs('.saetze-block', aktive);
        if (block) block.open = true;

        // NICHT scrollIntoView({block:'start'}): Das setzt die Karte exakt an den
        // oberen Viewport-Rand — und dort klebt unter Umständen die
        // Verbindungsleiste. Sie hängt als erstes Element im <body> und ist
        // `position: sticky; top: 0`, überlagert das Darunterliegende also. Genau
        // beim Abhaken wird sie sichtbar (die Eingabe geht in die Warteschlange),
        // sodass die Karte zuverlässig unter ihr landete und der Übungsname
        // verdeckt war.
        //
        // Gemessen statt geraten: Ihre Höhe hängt am Text und kann auf schmalen
        // Geräten zweizeilig werden. Ist sie ausgeblendet, ist der Versatz 0.
        const leiste = qs('#verbindung');
        const versatz = (leiste && !leiste.hidden) ? leiste.offsetHeight : 0;

        // Dazu eine Handbreit Luft, damit die Karte nicht bündig am Rand klebt.
        const LUFT = 8;
        const ziel = aktive.getBoundingClientRect().top + window.scrollY - versatz - LUFT;

        window.scrollTo({ top: Math.max(0, ziel), behavior: 'smooth' });
    }

    /** Ändert die Wiederholungen eines Satzes um ±1, innerhalb der Grenzen. */
    function wdhVerschieben(zeile, richtung) {
        const feld = qs('.satz-reps', zeile);
        const jetzt = Number.parseInt(feld.value, 10);
        const neu = Number.isNaN(jetzt) ? 1 : jetzt + richtung;

        feld.value = String(Math.min(WDH_GRENZE, Math.max(1, neu)));
    }

    // --- Abhaken und Ab-wählen --------------------------------------------

    /** Der Aufruf-Body zu einem Eintrag der Warteschlange. */
    function nutzlast(peId, eintrag) {
        if (eintrag.action !== 'check') {
            return { action: 'uncheck', plan_exercise_id: peId };
        }

        const body = {
            action: 'check',
            plan_exercise_id: peId,
            weight: eintrag.weight,
            // „Fertig mit der Übung" ist ein eigener Zustand — im
            // Expertenmodus entsteht die Zeile schon mit dem ersten Satz.
            done: eintrag.done !== false,
        };
        // Nur mitschicken, wenn es eine Satzliste GIBT: Ein fehlendes `sets`
        // heißt für api/log.php „einfacher Modus", ein leeres Array dasselbe.
        if (eintrag.sets) body.sets = eintrag.sets;

        return body;
    }

    /** Der Wert des Gewichtsfeldes — im Expertenmodus gibt es keines. */
    function gewichtsWert(karte) {
        const feld = qs('.gewicht', karte);
        return feld ? feld.value : '';
    }

    /**
     * Der Weg OHNE laufende Einheit: direkt zum Server, wie bisher.
     *
     * Hier startet das Abhaken die Einheit erst (§7.6) — es gibt also noch
     * keine session_id, an der sich eine Warteschlange festmachen liesse.
     * Scheitert der Aufruf, springt das Haekchen zurueck und der
     * Wiederholen-Knopf erscheint.
     */
    async function abhakenDirekt(karte, erledigt, vorher) {
        const peId = Number(karte.dataset.pe);
        const kasten = qs('.erledigt', karte);
        const saetze = saetzeFuerServer(karte);
        const aktiv  = erledigt || (saetze !== null && saetze.length > 0);
        karte.dataset.eintrag = aktiv ? '1' : '';
        fehlerLeeren(karte);
        kasten.disabled = true;

        try {
            const daten = await apiFetch('api/log.php', {
                wiederholen: true,
                body: nutzlast(peId, {
                    action: aktiv ? 'check' : 'uncheck',
                    weight: aktiv ? gewichtsWert(karte) : '',
                    sets: aktiv ? saetze : null,
                    done: erledigt,
                }),
            });

            zustandSetzen(karte, erledigt);
            fortschrittSetzen(daten);

            // Die erste Aktion startet die Einheit — die Kopfzeile mit „x/n"
            // und dem Beenden-Knopf gibt es dann noch gar nicht.
            const brauchtReload = aktiv && daten.session_id && !qs('#fortschritt-text');
            abschlussFrage(daten, brauchtReload);
        } catch (fehler) {
            zustandSetzen(karte, vorher);
            fehlerZeigen(karte, fehler, () => abhaken(karte, erledigt));
        } finally {
            kasten.disabled = false;
        }
    }

    /**
     * Der Weg MIT laufender Einheit: erst in die Warteschlange, dann abschicken.
     *
     * Bewusst immer ueber die Ablage, auch bei bestem Empfang — ein Weg statt
     * zweier. Nebenbei ueberlebt die Eingabe damit auch einen Absturz oder ein
     * versehentliches Schliessen der App zwischen Tipp und Antwort.
     */
    async function abhaken(karte, erledigt) {
        const peId = Number(karte.dataset.pe);

        // Der bestaetigte Zustand, auf den bei einer endgueltigen Ablehnung
        // zurueckgefallen wird. Liegt schon ein Eintrag vor, ist es dessen
        // `vorher` — die Zeile zeigt dann ja bereits Unbestaetigtes, und das
        // Gegenteil der aktuellen Anzeige waere der falsche Bezugspunkt.
        const offen  = schlange.aktiv ? schlange.eintrag(peId) : null;
        const vorher = offen ? offen.vorher : !erledigt;

        if (!schlange.aktiv) {
            await abhakenDirekt(karte, erledigt, vorher);
            return;
        }

        fehlerLeeren(karte);

        // `check` oder `uncheck` haengt NICHT am Haekchen, sondern daran, ob es
        // etwas zu protokollieren gibt. Eine Position mit Saetzen bleibt in der
        // Datenbank stehen, auch wenn sie nicht als fertig markiert ist; ohne
        // Saetze und ohne Haekchen gibt es dagegen nichts festzuhalten.
        const saetze = saetzeFuerServer(karte);
        const aktiv  = erledigt || (saetze !== null && saetze.length > 0);

        // Merkt sich, ob es serverseitig eine Zeile zu dieser Position gibt.
        // Nur dafür da, überflüssige Aufrufe zu vermeiden — siehe
        // satzSpeichernJetzt().
        karte.dataset.eintrag = aktiv ? '1' : '';

        schlange.setzen(peId, {
            action: aktiv ? 'check' : 'uncheck',
            weight: aktiv ? gewichtsWert(karte) : '',
            sets: aktiv ? saetze : null,
            done: erledigt,
            vorher: vorher,
            ts: Date.now(),
        });

        zustandSetzen(karte, erledigt);
        wartendSetzen(karte, true);
        verbindung.wartend(schlange.anzahl());
        fortschrittLokal();

        await abarbeiten();
    }

    // --- Warteschlange abarbeiten -----------------------------------------

    // Wartezeiten bis zum naechsten Anlauf, solange das Netz weg bleibt.
    const NACHHOL_PAUSEN_MS = [5000, 10000, 20000, 30000, 60000];

    let laeuft = false;
    let nachholUhr = null;
    let pauseStufe = 0;

    function spaeterErneut() {
        if (nachholUhr !== null) return;
        const ms = NACHHOL_PAUSEN_MS[Math.min(pauseStufe, NACHHOL_PAUSEN_MS.length - 1)];
        pauseStufe++;
        nachholUhr = setTimeout(() => {
            nachholUhr = null;
            abarbeiten();
        }, ms);
    }

    /**
     * Schickt die wartenden Eintraege der Reihe nach zum Server.
     *
     * Nacheinander, nicht parallel: Die Eingaben sollen in der Reihenfolge
     * ankommen, in der sie gemacht wurden.
     *
     * Der eigene Zeitgeber ist nicht verzichtbar. Das `online`-Ereignis feuert
     * nur, wenn der Browser den Verlust ueberhaupt bemerkt hat — beim
     * klassischen einen Balken Empfang bleibt `navigator.onLine` durchgehend
     * true und es feuert nie.
     */
    async function abarbeiten() {
        if (!schlange.aktiv || laeuft) return;

        laeuft = true;
        clearTimeout(nachholUhr);
        nachholUhr = null;

        try {
            let letzte = null;

            for (;;) {
                const eintraege = schlange.eintraege();
                const schluessel = Object.keys(eintraege);
                if (schluessel.length === 0) break;

                schluessel.sort((a, b) => eintraege[a].ts - eintraege[b].ts);
                const peId    = Number(schluessel[0]);
                const eintrag = eintraege[schluessel[0]];
                const karte   = qs('.position-karte[data-pe="' + peId + '"]', liste);

                try {
                    letzte = await apiFetch('api/log.php', { body: nutzlast(peId, eintrag) });
                    schlange.entfernen(peId);
                    if (karte) wartendSetzen(karte, false);
                } catch (fehler) {
                    if (fehler.offline) {
                        // Netz weg: Der Eintrag bleibt liegen, die Zeile bleibt
                        // markiert, und es wird spaeter erneut versucht.
                        verbindung.wartend(schlange.anzahl());
                        spaeterErneut();
                        return;
                    }

                    // Eine fachliche Ablehnung (404, 409, 422) faellt bei jedem
                    // weiteren Versuch gleich aus. Der Eintrag MUSS deshalb
                    // raus, sonst blockiert er die ganze Schlange dauerhaft.
                    schlange.entfernen(peId);
                    letzte = null;
                    if (karte) {
                        wartendSetzen(karte, false);
                        zustandSetzen(karte, eintrag.vorher);
                        fehlerZeigen(karte, fehler,
                            () => abhaken(karte, eintrag.done === true));
                    }
                }

                verbindung.wartend(schlange.anzahl());
            }

            pauseStufe = 0;
            fortschrittLokal();

            // Die Rueckfrage erst, wenn nichts mehr aussteht: Sie fuehrt zum
            // Beenden, und beendet werden darf erst, wenn alles angekommen ist.
            if (letzte) abschlussFrage(letzte, false);
        } finally {
            laeuft = false;
        }
    }

    /**
     * Uebertraegt wartende Eintraege nach einem Seitenaufruf auf die Zeilen.
     *
     * Die Seite kommt serverseitig gerendert und zeigt damit den BESTAETIGTEN
     * Stand. Ohne diesen Schritt saehe man nach einem Neuladen im Funkloch die
     * eigenen Haekchen wieder verschwinden, obwohl sie sicher abgelegt sind.
     */
    function warteschlangeAnwenden() {
        if (!schlange.aktiv) return;

        const eintraege = schlange.eintraege();
        Object.keys(eintraege).forEach((k) => {
            const karte = qs('.position-karte[data-pe="' + k + '"]', liste);
            if (!karte) {
                // Die Position steht nicht mehr im Plan — der Eintrag ist
                // gegenstandslos geworden.
                schlange.entfernen(Number(k));
                return;
            }

            if (eintraege[k].action === 'check') {
                const feld = qs('.gewicht', karte);
                if (feld) feld.value = eintraege[k].weight || '';
                // Die wartenden Sätze schlagen die serverseitig gerenderten:
                // Die Seite zeigt den BESTÄTIGTEN Stand, die Ablage den
                // eingegebenen. Ohne diese Zeile sähe man nach einem Neuladen
                // im Funkloch die eigenen Sätze wieder verschwinden.
                if (eintraege[k].sets) saetzeSetzen(karte, eintraege[k].sets);
            }
            // Das Häkchen folgt `done` und nicht der Aktion: Eine Position mit
            // wartenden Sätzen ist protokolliert, aber nicht zwingend fertig.
            zustandSetzen(karte, eintraege[k].done === true);
            wartendSetzen(karte, true);
        });

        verbindung.wartend(schlange.anzahl());
        fortschrittLokal();
    }

    liste.addEventListener('change', (e) => {
        const karte = e.target.closest('.position-karte');
        if (!karte) return;

        // Ein geänderter Satzwert wird gespeichert wie jede andere Änderung an
        // der Satzliste — sie IST die Eingabe, es gibt daneben keine zweite
        // „Wert speichern"-Aktion (§7.4).
        if (e.target.classList.contains('satz-reps')
            || e.target.classList.contains('satz-gewicht')) {
            saetzeSetzen(karte, saetzeLesen(karte), false);
            satzSpeichernSpaeter(karte);
            return;
        }

        // Sonst löst nur das Häkchen etwas aus. Das Gewichtsfeld ist nach dem
        // Abhaken schreibgeschützt (§7.4) — geändert wird über Häkchen weg,
        // korrigieren, neu abhaken.
        if (!e.target.classList.contains('erledigt')) return;

        // Das Häkchen ist eine ausdrückliche Handlung und geht sofort raus —
        // ein für dieselbe Karte noch anstehender Sammel-Zeitgeber wäre danach
        // ein zweiter Aufruf mit demselben Inhalt.
        satzWartend.delete(karte);

        // Im Expertenmodus bleiben die Sätze stehen, in BEIDE Richtungen. Sie
        // dokumentieren, was tatsächlich gemacht wurde; sie zu löschen, weil
        // jemand eine Fertig-Markierung zurücknimmt, wäre dieselbe Sorte
        // Fehler wie ein Tausch auf eine abgehakte Position (§7.5).
        abhaken(karte, e.target.checked);

        if (e.target.checked) weiterZurNaechsten(karte);
    });

    // Nach der Rückkehr in den Vordergrund gleich nachfassen: Wer die App
    // weglegt, im Funkloch steht und sie später wieder öffnet, soll nicht auf
    // den nächsten Zeitgeber warten müssen.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            pauseStufe = 0;
            abarbeiten();
        }
    });

    window.addEventListener('online', () => {
        pauseStufe = 0;
        abarbeiten();
    });

    // --- Übungstausch ------------------------------------------------------

    const tauschDialog = qs('#tausch-dialog');
    const tauschListe = qs('#tausch-liste');
    const tauschFehler = qs('#tausch-fehler');
    const tauschFilter = qs('.tausch-filter');
    const tauschGeraet = qs('#tausch-geraet');
    let tauschKarte = null;
    let tauschVorschlaege = [];

    qs('#tausch-schliessen').addEventListener('click', () => tauschDialog.close());

    // Im Training gibt es beide Wege: nur heute oder dauerhaft.
    const TAUSCH_KNOEPFE =
        '<button type="button" class="waehlen" data-modus="session">'
        + 'Nur diese Einheit</button>'
        + '<button type="button" class="leise waehlen" data-modus="permanent">'
        + 'Dauerhaft im Plan</button>';

    /** Zeichnet die Vorschlagsliste, gefiltert auf das gewählte Gerät. */
    function tauschZeichnen() {
        tauschListe.innerHTML = geraetGefiltert(tauschVorschlaege, tauschGeraet.value)
            .map((v) => vorschlagMarkup(v, TAUSCH_KNOEPFE)).join('');
    }

    async function tauschOeffnen(karte) {
        // Erst das, was noch im Verzug haengt -- sonst greift die Pruefung
        // darunter ins Leere: Ein Satz, der auf den Sammel-Zeitgeber wartet,
        // steht noch nicht in der Warteschlange.
        await satzSpeichernJetzt();

        // Solange fuer diese Zeile noch etwas aussteht, kennt der Server ihren
        // Zustand nicht. Ein Tausch auf eine Position, die er noch fuer
        // abgehakt haelt, wird von api/swap.php abgelehnt (§7.5) — und ein
        // Tausch, der VOR einem wartenden Haekchen ankommt, protokollierte
        // hinterher die falsche Uebung.
        if (schlange.aktiv && schlange.eintrag(Number(karte.dataset.pe))) {
            meldung('Diese Übung wird gerade gespeichert — bitte kurz warten.', 'fehler');
            abarbeiten();
            return;
        }

        tauschKarte = karte;
        tauschFehler.hidden = true;
        // Der Filter der vorigen Übung darf nicht stehen bleiben — sonst zeigt
        // der Dialog beim Öffnen eine schon eingeschränkte und scheinbar
        // unvollständige Liste.
        tauschVorschlaege = [];
        tauschFilter.hidden = true;
        tauschListe.innerHTML = '<p class="matt">Wird geladen …</p>';
        qs('#tausch-titel').textContent =
            'Ersatz für ' + qs('.uebung-text strong', karte).textContent.trim();
        tauschDialog.showModal();

        try {
            // Nur lesend, also gefahrlos wiederholbar — und genau die Stelle,
            // an der man im Studio vor dem belegten Geraet steht und wartet.
            const daten = await apiFetch('api/swap.php', {
                wiederholen: true,
                body: { action: 'suggestions', plan_exercise_id: Number(karte.dataset.pe) },
            });

            if (daten.abgehakt) {
                // Sollte nicht vorkommen — der Knopf ist dann deaktiviert.
                // Falls doch (zweiter Tab, veraltete Seite), hier die Erklärung
                // statt einer Liste, die der Server ablehnen würde.
                tauschListe.innerHTML = '<p>Für diese Übung sind bereits Werte '
                    + 'protokolliert. Zum Tauschen erst die Werte entfernen — '
                    + 'Häkchen weg bzw. Sätze löschen —, dann tauschen und neu '
                    + 'eintragen.</p>';
                return;
            }

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

    tauschGeraet.addEventListener('change', tauschZeichnen);

    tauschListe.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.waehlen');
        if (!knopf || !tauschKarte) return;

        const vorschlag = knopf.closest('.vorschlag');
        const exerciseId = Number(vorschlag.dataset.id);

        // Rückfrage NUR beim dauerhaften Tausch. Die beiden Knöpfe stehen
        // nebeneinander und unterscheiden sich in der Tragweite erheblich: „Nur
        // diese Einheit" ist für heute und morgen wieder weg, „Dauerhaft im
        // Plan" ändert den Plan für alle künftigen Trainings. Ein Fehlgriff im
        // Studio, mit feuchten Fingern auf einem kleinen Bildschirm, wäre sonst
        // erst Wochen später aufgefallen — und der Weg zurück führt über die
        // Planverwaltung.
        //
        // Für „Nur diese Einheit" bewusst KEINE Rückfrage: Sie gilt für ein
        // Training, ist über einen zweiten Tausch sofort korrigierbar, und eine
        // Rückfrage bei jedem Handgriff gewöhnt man sich an wegzuklicken —
        // dann greift sie auch dort nicht mehr, wo sie zählt.
        if (knopf.dataset.modus === 'permanent') {
            const ersatz = qs('strong', vorschlag).textContent.trim();
            const bisher = qs('.uebung-text strong', tauschKarte).textContent.trim();
            const weiter = window.confirm(
                'Im Plan dauerhaft „' + bisher + '" durch „' + ersatz + '" ersetzen?\n\n'
                + 'Das gilt für alle künftigen Trainings, nicht nur für heute. '
                + 'Bereits protokollierte Einheiten bleiben unverändert.'
            );
            if (!weiter) return;
        }

        knopf.disabled = true;
        tauschFehler.hidden = true;

        try {
            await apiFetch('api/swap.php', {
                body: {
                    action: 'apply',
                    plan_exercise_id: Number(tauschKarte.dataset.pe),
                    exercise_id: exerciseId,
                    mode: knopf.dataset.modus,
                },
            });
            // Nach dem Tausch stimmen Name, Muskelgruppen und das vorbelegte
            // Gewicht der Zeile nicht mehr — die Seite kommt frisch vom Server.
            window.location.reload();
        } catch (fehler) {
            tauschFehler.textContent = fehler.message;
            tauschFehler.hidden = false;
            knopf.disabled = false;
        }
    });

    liste.addEventListener('click', (e) => {
        const karte = e.target.closest('.position-karte');
        if (!karte) return;

        if (e.target.closest('.tauschen')) {
            tauschOeffnen(karte);
            return;
        }

        // --- Sätze ---------------------------------------------------------
        // Alle drei Knöpfe ändern die Liste und schicken sie anschließend als
        // Ganzes. Erst neu zeichnen, dann speichern: Die Zeilennummern und der
        // Vorschlag im „+ Satz"-Knopf hängen an der Länge der Liste.

        const hinzu = e.target.closest('.satz-hinzu');
        if (hinzu) {
            const saetze = saetzeLesen(karte) || [];
            saetze.push(naechsterSatz(karte, saetze));
            saetzeSetzen(karte, saetze);
            satzSpeichernSpaeter(karte);
            return;
        }

        const weg = e.target.closest('.satz-weg');
        if (weg) {
            const zeile = weg.closest('.satz-zeile');
            const saetze = saetzeLesen(karte);
            saetze.splice(Number(zeile.dataset.nr) - 1, 1);
            saetzeSetzen(karte, saetze);
            satzSpeichernSpaeter(karte);
            return;
        }

        const stufe = e.target.closest('.satz-minus, .satz-plus');
        if (stufe) {
            wdhVerschieben(
                stufe.closest('.satz-zeile'),
                stufe.classList.contains('satz-plus') ? 1 : -1
            );
            // Ohne Neubau der Zeilen: Der Wert steht schon im Feld, und wer
            // schnell mehrfach tippt, verlöre sonst bei jedem Tipp den Knopf
            // unter dem Finger.
            saetzeSetzen(karte, saetzeLesen(karte), false);
            satzSpeichernSpaeter(karte);
            return;
        }

        if (e.target.closest('.bild-knopf')) {
            const bild = qs('.uebung-bild', karte);
            const beschreibung = qs('.beschreibung', karte);

            bildGrossZeigen(
                qs('.uebung-text strong', karte).textContent.trim(),
                bild ? bild.getAttribute('src') : '',
                beschreibung ? beschreibung.textContent : ''
            );
        }
    });

    // --- Beim Laden ---------------------------------------------------------

    // Die Satzzeilen entstehen hier, aus dem JSON in data-saetze. index.php
    // liefert nur die Werte und die Zusammenfassung; die bedienbare Liste hat
    // genau einen Erzeuger (satzZeileMarkup).
    if (experte) {
        qsa('.position-karte', liste).forEach((karte) => {
            saetzeZeichnen(karte, satzListeAusDaten(karte.dataset.saetze), true);
        });
    }

    // Erst die wartenden Eingaben auf die Zeilen übertragen, dann versuchen,
    // sie loszuwerden. Die Reihenfolge ist wichtig: Sonst blinkt der
    // bestätigte Stand kurz auf, bevor das Eigene wieder erscheint.
    warteschlangeAnwenden();
    abarbeiten();

    // Die Markierung der aktiven Übung setzt der Server schon; hier wird sie
    // nachgezogen, falls die Warteschlange den Stand verschoben hat.
    aktiveMarkieren();

    // Nur direkt nach „Training starten": Die Seite kam gerade neu, und jetzt
    // gibt es eine aktive Übung, zu der gesprungen werden kann. Bei jedem
    // gewöhnlichen Seitenaufruf bleibt der Bildschirm, wo er ist.
    if (sprungAbholen()) zurAktivenSpringen();
})();
