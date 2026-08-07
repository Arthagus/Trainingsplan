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

    // --- Einheit beenden ---------------------------------------------------

    async function einheitBeenden() {
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
     */
    function wartendSetzen(karte, wartet) {
        karte.classList.toggle('zeile-wartet', wartet);
        const hinweis = qs('.wartet-hinweis', karte);
        if (hinweis) hinweis.hidden = !wartet;
    }

    function zustandSetzen(karte, erledigt) {
        karte.classList.toggle('zeile-erledigt', erledigt);
        karte.classList.toggle('zeile-offen', !erledigt);
        qs('.erledigt', karte).checked = erledigt;

        // Abgehakt heißt: Der Wert steht fest. Ändern geht nur über Häkchen
        // entfernen, korrigieren, neu abhaken (§7.4).
        qs('.gewicht', karte).readOnly = erledigt;

        // Aus demselben Grund lässt sich eine abgehakte Position nicht tauschen
        // (§7.5) — der Protokolleintrag hält fest, was tatsächlich gemacht
        // wurde. Der Server weist es ohnehin ab; hier wird es gar nicht erst
        // angeboten.
        const tausch = qs('.tauschen', karte);
        tausch.disabled = erledigt;
        if (erledigt) {
            tausch.title = 'Erst das Häkchen entfernen';
        } else {
            tausch.removeAttribute('title');
        }
    }

    // --- Abhaken und Ab-wählen --------------------------------------------

    /** Der Aufruf-Body zu einem Eintrag der Warteschlange. */
    function nutzlast(peId, eintrag) {
        return eintrag.action === 'check'
            ? { action: 'check', plan_exercise_id: peId, weight: eintrag.weight }
            : { action: 'uncheck', plan_exercise_id: peId };
    }

    /**
     * Der Weg OHNE laufende Einheit: direkt zum Server, wie bisher.
     *
     * Hier startet das Abhaken die Einheit erst (§7.6) — es gibt also noch
     * keine session_id, an der sich eine Warteschlange festmachen liesse.
     * Scheitert der Aufruf, springt das Haekchen zurueck und der
     * Wiederholen-Knopf erscheint.
     */
    async function abhakenDirekt(karte, gewuenscht) {
        const peId = Number(karte.dataset.pe);
        const kasten = qs('.erledigt', karte);
        fehlerLeeren(karte);
        kasten.disabled = true;

        try {
            const daten = await apiFetch('api/log.php', {
                wiederholen: true,
                body: nutzlast(peId, {
                    action: gewuenscht ? 'check' : 'uncheck',
                    weight: qs('.gewicht', karte).value,
                }),
            });

            zustandSetzen(karte, gewuenscht);
            fortschrittSetzen(daten);

            // Die erste Aktion startet die Einheit — die Kopfzeile mit „x/n"
            // und dem Beenden-Knopf gibt es dann noch gar nicht.
            const brauchtReload = gewuenscht && daten.session_id && !qs('#fortschritt-text');
            abschlussFrage(daten, brauchtReload);
        } catch (fehler) {
            zustandSetzen(karte, !gewuenscht);
            fehlerZeigen(karte, fehler, () => abhaken(karte, gewuenscht));
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
    async function abhaken(karte, gewuenscht) {
        if (!schlange.aktiv) {
            await abhakenDirekt(karte, gewuenscht);
            return;
        }

        const peId = Number(karte.dataset.pe);
        fehlerLeeren(karte);

        // Der bestaetigte Zustand, auf den bei einer endgueltigen Ablehnung
        // zurueckgefallen wird. Liegt schon ein Eintrag vor, ist es dessen
        // `vorher` — die Zeile zeigt dann ja bereits Unbestaetigtes, und das
        // Gegenteil der aktuellen Anzeige waere der falsche Bezugspunkt.
        const offen  = schlange.eintrag(peId);
        const vorher = offen ? offen.vorher : !gewuenscht;

        schlange.setzen(peId, {
            action: gewuenscht ? 'check' : 'uncheck',
            weight: gewuenscht ? qs('.gewicht', karte).value : '',
            vorher: vorher,
            ts: Date.now(),
        });

        zustandSetzen(karte, gewuenscht);
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
                            () => abhaken(karte, eintrag.action === 'check'));
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

            const erledigt = eintraege[k].action === 'check';
            if (erledigt) qs('.gewicht', karte).value = eintraege[k].weight || '';
            zustandSetzen(karte, erledigt);
            wartendSetzen(karte, true);
        });

        verbindung.wartend(schlange.anzahl());
        fortschrittLokal();
    }

    liste.addEventListener('change', (e) => {
        const karte = e.target.closest('.position-karte');
        if (!karte) return;

        // Nur das Häkchen löst etwas aus. Das Gewichtsfeld ist nach dem Abhaken
        // schreibgeschützt (§7.4) — geändert wird über Häkchen weg, korrigieren,
        // neu abhaken.
        if (e.target.classList.contains('erledigt')) {
            abhaken(karte, e.target.checked);
        }
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

    // --- Bild und Beschreibung --------------------------------------------

    const infoDialog = qs('#info-dialog');
    qs('#info-schliessen').addEventListener('click', () => infoDialog.close());

    // Ein Tipp auf das große Bild schließt wieder — man hat es ohnehin gerade
    // unter dem Finger, und der Knopf steht am unteren Ende eines womöglich
    // gescrollten Dialogs. Der Knopf bleibt: Er ist der Weg mit der Tastatur.
    qs('#info-bild').addEventListener('click', () => infoDialog.close());

    // Zählt die Bildwechsel im Info-Dialog mit — siehe unten beim Nachladen.
    let infoBildLauf = 0;

    // --- Übungstausch ------------------------------------------------------

    const tauschDialog = qs('#tausch-dialog');
    const tauschListe = qs('#tausch-liste');
    const tauschFehler = qs('#tausch-fehler');
    let tauschKarte = null;

    qs('#tausch-schliessen').addEventListener('click', () => tauschDialog.close());

    async function tauschOeffnen(karte) {
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
                tauschListe.innerHTML = '<p>Diese Übung ist bereits als erledigt markiert. '
                    + 'Zum Tauschen erst das Häkchen entfernen, dann tauschen und neu abhaken.</p>';
                return;
            }

            if (!daten.suggestions.length) {
                tauschListe.innerHTML = keinVorschlagText(daten.im_plan);
                return;
            }

            // Im Training gibt es beide Wege: nur heute oder dauerhaft.
            const knoepfe =
                '<button type="button" class="waehlen" data-modus="session">'
                + 'Nur diese Einheit</button>'
                + '<button type="button" class="leise waehlen" data-modus="permanent">'
                + 'Dauerhaft im Plan</button>';

            tauschListe.innerHTML =
                daten.suggestions.map((v) => vorschlagMarkup(v, knoepfe)).join('');
        } catch (fehler) {
            tauschListe.innerHTML = '';
            tauschFehler.textContent = fehler.message;
            tauschFehler.hidden = false;
        }
    }

    tauschListe.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.waehlen');
        if (!knopf || !tauschKarte) return;

        const exerciseId = Number(knopf.closest('.vorschlag').dataset.id);
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

        if (e.target.closest('.bild-knopf')) {
            const bild = qs('.uebung-bild', karte);
            const beschreibung = qs('.beschreibung', karte);

            qs('#info-titel').textContent = qs('.uebung-text strong', karte).textContent.trim();
            qs('#info-text').textContent = beschreibung
                ? beschreibung.textContent
                : 'Keine Beschreibung hinterlegt.';

            const gross = qs('#info-bild');
            if (bild) {
                // Erst das Thumbnail: Es liegt bereits geladen in der Zeile und
                // erscheint deshalb verzögerungsfrei.
                //
                // Ein <img> behält nämlich sein altes Bild, bis das neue
                // VOLLSTÄNDIG geladen ist — ein bloßes Setzen von src blendet
                // nichts aus. Über Mobilfunk stand deshalb ein bis zwei
                // Sekunden lang das zuletzt angesehene Motiv im Dialog.
                const klein = bild.getAttribute('src');
                gross.src = klein;
                gross.hidden = false;

                // Das große Bild im Hintergrund nachladen und erst austauschen,
                // wenn es da ist. Der Zähler verhindert, dass ein spät
                // eintreffendes Bild eine inzwischen andere Übung überschreibt
                // — beim schnellen Durchtippen sonst genau derselbe Fehler.
                const lauf = ++infoBildLauf;
                const voll = new Image();
                voll.onload = () => {
                    if (lauf === infoBildLauf) gross.src = voll.src;
                };
                voll.src = klein.replace('_thumb.jpg', '.jpg');
            } else {
                gross.hidden = true;
                gross.removeAttribute('src');
                infoBildLauf++;
            }
            infoDialog.showModal();
        }
    });

    // --- Beim Laden ---------------------------------------------------------

    // Erst die wartenden Eingaben auf die Zeilen übertragen, dann versuchen,
    // sie loszuwerden. Die Reihenfolge ist wichtig: Sonst blinkt der
    // bestätigte Stand kurz auf, bevor das Eigene wieder erscheint.
    warteschlangeAnwenden();
    abarbeiten();
})();
