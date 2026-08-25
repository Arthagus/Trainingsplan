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
            neuLadenNachEnde();
        } catch (fehler) {
            // 409 heisst "es laeuft keine Einheit" -- genau das war das Ziel.
            // Typischer Hergang bei schlechtem Empfang: Der erste Aufruf kam
            // durch, seine ANTWORT ging verloren, der Benutzer tippt erneut.
            // Eine Fehlermeldung waere hier schlicht falsch.
            if (fehler.status === 409) {
                neuLadenNachEnde();
                return;
            }
            meldung(fehler.message, 'fehler');
        }
    }

    /**
     * Merkt über den Seitenwechsel hinweg, wohin die neue Seite scrollen soll.
     *
     * Zwei Ziele, beide nach einem Neuladen, das diese Seite selbst ausgelöst
     * hat: nach dem START zur aktiven Übung, nach dem ENDE ganz nach oben. Ein
     * gewöhnlicher Seitenaufruf trägt keinen Merker und scrollt nicht.
     *
     * `sessionStorage` und nicht `localStorage`: Das gilt für genau diesen Tab
     * und diesen Moment. Bliebe es liegen, spränge die Seite beim nächsten
     * Öffnen grundlos.
     */
    const SPRUNG_SCHLUESSEL = 'trainingsplan-sprung';
    const SPRUNG_AKTIVE     = 'aktive';
    const SPRUNG_OBEN       = 'oben';

    function merkeSprung(ziel) {
        try {
            window.sessionStorage.setItem(SPRUNG_SCHLUESSEL, ziel);
        } catch (e) {
            // Privater Modus: Dann wird eben nicht gescrollt.
        }
    }

    function sprungAbholen() {
        try {
            const ziel = window.sessionStorage.getItem(SPRUNG_SCHLUESSEL);
            window.sessionStorage.removeItem(SPRUNG_SCHLUESSEL);
            return ziel;
        } catch (e) {
            return null;
        }
    }

    /**
     * Nach dem Beenden: Seite neu holen, OHNE `?plan=` und ganz oben.
     *
     * Zwei Dinge, die beide am selben Moment hängen — die Einheit ist zu, und
     * was jetzt kommt, ist der Vorschlag für das NÄCHSTE Training:
     *
     * 1. **Ohne Query.** `?plan=` stammt aus der Planwahl VOR dem Training.
     *    Während der Einheit ist der Parameter wirkungslos (der Plan kommt aus
     *    `sessions.plan_id`), danach greift er wieder — und die Seite zeigte
     *    denselben Plan, den man gerade fertig trainiert hat, statt den nächsten
     *    aus der Rotation. `location.pathname` wirft ihn ab und ist zugleich
     *    basispfad-sicher.
     * 2. **Ganz nach oben.** Der Knopf steht auch am ENDE der Liste, man steht
     *    also unten — und die neue Seite ist eine andere: Startkasten,
     *    Planwahl, Vorschlag. Unten stünde man dann mitten in der Übungsliste
     *    eines Trainings, das noch gar nicht läuft.
     *
     * `scrollRestoration = 'manual'` gehört dazu, weil Punkt 1 nicht in jedem
     * Fall eine neue Adresse ergibt: Ohne `?plan=` ist Ziel gleich Herkunft,
     * der Browser behandelt das als Neuladen und stellt die alte Scrollposition
     * wieder her — also genau das Ende der Liste, das wir verlassen wollen.
     */
    function neuLadenNachEnde() {
        merkeSprung(SPRUNG_OBEN);
        try {
            window.history.scrollRestoration = 'manual';
        } catch (e) {
            // Ältere Browser: Dann greift nur das Scrollen auf der neuen Seite.
        }
        window.location.href = window.location.pathname;
    }

    // Punkt 7: Einheit ausdrücklich starten, damit der Zeitstempel den
    // Trainingsbeginn festhält und nicht das Ende der ersten Übung.
    const starten = qs('#einheit-starten');
    if (starten) {
        starten.addEventListener('click', async () => {
            starten.disabled = true;

            // Der Knopf sagt, dass etwas passiert. Zwischen dem Tipp und der
            // laufenden Einheit liegen drei Netzrunden — der Aufruf hier, die
            // neu geladene Seite und deren Skript —, und bei einem Aussetzer
            // kommt eine Wiederholpause dazu. Ein bloß ausgegrauter Knopf ist
            // in dieser Zeit nicht von einem toten zu unterscheiden.
            const beschriftung = starten.textContent;
            starten.textContent = 'Startet …';

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
                merkeSprung(SPRUNG_AKTIVE);
                window.location.reload();
            } catch (fehler) {
                meldung(fehler.message, 'fehler');
                // Nur im Fehlerfall zuruecksetzen: Im Erfolgsfall laeuft der
                // Reload schon, und "Training starten" duerfte dabei nicht
                // wieder aufblitzen.
                starten.textContent = beschriftung;
                starten.disabled = false;
            }
        });
    }

    // Drei Wege zum selben Ziel, und alle drei brauchen dieselbe Rückfrage:
    // oben in der Karte, unten nach der letzten Übung, und der Notfall-Kasten
    // für den Fall eines gelöschten Plans. Es gibt sie bewusst mehrfach — nach
    // der letzten Übung steht man ganz unten und will nicht zurückscrollen —,
    // aber nur EINE Funktion dahinter.
    ['#einheit-beenden', '#einheit-beenden-unten', '#einheit-beenden-notfall'].forEach((wahl) => {
        const knopf = qs(wahl);
        if (!knopf) return;
        knopf.addEventListener('click', () => {
            if (window.confirm('Training wirklich beenden?')) einheitBeenden();
        });
    });

    // --- Trainingsdauer in der Leiste --------------------------------------

    /**
     * Zaehlt die Dauer der laufenden Einheit in der Leiste am oberen Rand hoch.
     *
     * Der Ausgangswert kommt als BEREITS VERSTRICHENE SEKUNDEN vom Server
     * (data-sekunden). Gerechnet wird hier nur noch mit der Zeit SEIT dem Laden
     * der Seite -- also mit einer Differenz. Das ist der Kern: Eine falsch
     * gestellte Geraeteuhr oder eine andere Zeitzone verschoebe einen absoluten
     * Zeitstempel um Stunden, eine Differenz nicht.
     *
     * Aus demselben Grund wird bei jedem Durchlauf neu aus der Uhr gerechnet und
     * nicht einfach hochgezaehlt: Schlaeft das Handy in der Tasche, drosselt der
     * Browser den Zeitgeber oder haelt ihn ganz an. Ein Zaehler bliebe dann
     * zurueck; die Differenz stimmt nach dem Aufwachen sofort wieder.
     *
     * Der Takt von 20 s hat nichts mit Genauigkeit zu tun, sondern mit dem
     * Versatz: Die Minutengrenze liegt irgendwo zwischen zwei Durchlaeufen. Bei
     * 60 s Takt stuende bis zu eine Minute lang die alte Zahl da.
     */
    (function dauerZaehlen() {
        const feld = qs('#leiste-dauer');
        if (!feld) return;

        const start   = Number(feld.closest('.training-leiste').dataset.sekunden) || 0;
        const geladen = Date.now();

        function beschriften(sekunden) {
            const min = Math.floor(sekunden / 60);
            if (min < 1)  return 'gerade begonnen';
            if (min < 60) return 'seit ' + min + ' min';

            // Zweistellige Minuten ab der ersten Stunde, damit „1 h 05 min"
            // nicht schmaler ist als „1 h 47 min" und die Leiste beim
            // Weiterzaehlen ruhig bleibt.
            const rest = min % 60;
            return 'seit ' + Math.floor(min / 60) + ' h '
                 + String(rest).padStart(2, '0') + ' min';
        }

        function zeichnen() {
            feld.textContent = beschriften(start + Math.floor((Date.now() - geladen) / 1000));
        }

        zeichnen();
        setInterval(zeichnen, 20000);
    })();

    if (!liste) return;

    // --- Fortschritt -------------------------------------------------------

    /**
     * Schreibt die Zahlen der Leiste fort und fragt bei Vollständigkeit nach.
     *
     * Die Einheit schließt sich NIE von selbst (§7.6): Sonst wäre das
     * Ab-wählen eines versehentlichen Häkchens undefiniert, und der Bildschirm
     * wechselte, während man noch am Gerät steht.
     */
    function fortschrittSetzen(daten) {
        if (daten.gesamt === undefined) return;

        zahlenSchreiben(daten.erledigt_anzahl, daten.gesamt);

        liste.dataset.erledigt = daten.erledigt_anzahl;
        liste.dataset.gesamt = daten.gesamt;
    }

    /**
     * Schreibt „x/y beendet · n übersprungen" in die Leiste am oberen Rand.
     *
     * Die übersprungenen werden NICHT übergeben, sondern hier aus der Liste
     * gezählt — und zwar an den orangen Balken selbst (`.zeile-uebersprungen`).
     * Das ist der Kern: Die Leiste nennt damit genau die Übungen, die man in
     * der Liste auch orange sieht. Eine eigene Rechnung daneben liefe früher
     * oder später auseinander, und dann stünde oben eine Zahl, die man unten
     * nicht wiederfindet.
     *
     * Daraus folgt eine Reihenfolge, die man kennen muss: `aktiveMarkieren()`
     * setzt die Klasse und muss VORHER gelaufen sein. Alle Aufrufer erfüllen
     * das über `zustandSetzen()`, das beides in dieser Reihenfolge tut.
     *
     * Fehlt die Leiste (kein laufendes Training), passiert nichts.
     */
    function zahlenSchreiben(beendet, gesamt) {
        const uebersprungen = qsa('.position-karte.zeile-uebersprungen', liste).length;

        const anzeige = qs('#zahl-uebersprungen');
        if (anzeige) {
            anzeige.textContent = String(uebersprungen);
            // Orange erst, wenn es wirklich welche gibt: „0 übersprungen" ist
            // der Normalfall und keine Warnung.
            anzeige.parentNode.classList.toggle('zaehlt', uebersprungen > 0);
        }

        const fortschritt = qs('#zahl-beendet');
        if (fortschritt) fortschritt.textContent = beendet + '/' + gesamt;
    }

    /**
     * Schreibt die Zahlen aus dem fort, was auf dem Bildschirm steht.
     *
     * Solange Eintraege in der Warteschlange liegen, kennt der Server den
     * Stand noch nicht -- die Zeilen sind die Wahrheit. Sobald die Schlange
     * leer ist, uebernimmt wieder fortschrittSetzen() mit der Serverzahl.
     */
    function fortschrittLokal() {
        if (!qs('#fortschritt')) return;

        const beendet = qsa('.position-karte.zeile-erledigt', liste).length;

        zahlenSchreiben(beendet, Number(liste.dataset.gesamt) || 0);
        liste.dataset.erledigt = beendet;
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
     * Hat diese Position einen Protokolleintrag?
     *
     * Serverseitig steht die Antwort in `data-eintrag`; im Betrieb ändert sie
     * sich unter den Händen, deshalb wird sie hier aus dem gerechnet, was
     * tatsächlich in der Karte steht. Die Regel ist dieselbe wie in
     * `api/log.php`: Eine Zeile existiert, solange sie abgehakt ist ODER noch
     * mindestens einen Satz trägt.
     */
    function hatEintrag(karte) {
        if (istErledigt(karte)) return true;

        const saetze = saetzeFuerServer(karte);
        if (saetze !== null) return saetze.length > 0;

        return karte.dataset.eintrag === '1';
    }

    /**
     * Zieht Grün und Orange über die ganze Liste nach.
     *
     * DIESELBE REGEL WIE positions_zustaende() IN lib/training.php — dort steht
     * sie ausgeschrieben samt Begründung. Beide Hälften gehören zusammen
     * geändert, sonst springt die Farbe beim nächsten Neuladen.
     *
     * Kurzfassung: grün ist die Position, an der gerade protokolliert wird
     * (Eintrag, aber noch nicht erledigt); sonst die erste offene nach der
     * letzten mit Eintrag; sonst die erste offene überhaupt. Orange ist jede
     * offene Position davor — das übersprungene Gerät, zu dem man zurückwill.
     *
     * Nur während eines Trainings: Wer den Plan bloß anschaut, bekommt eine
     * ruhige Liste ohne Aussage darüber, wo er angeblich gerade ist.
     */
    function aktiveMarkieren() {
        const karten = qsa('.position-karte', liste);

        let aktiv = -1;
        if (sessionId > 0) {
            let letzterEintrag = -1;
            karten.forEach((k, i) => {
                if (hatEintrag(k)) {
                    letzterEintrag = i;
                    if (!istErledigt(k)) aktiv = i;
                }
            });

            if (aktiv < 0) {
                for (let i = letzterEintrag + 1; i < karten.length; i++) {
                    if (!istErledigt(karten[i])) { aktiv = i; break; }
                }
            }
            if (aktiv < 0) {
                aktiv = karten.findIndex((k) => !istErledigt(k));
            }
        }

        karten.forEach((k, i) => {
            const erledigt = istErledigt(k);
            const istAktiv = i === aktiv;
            const uebersprungen = !erledigt && !istAktiv && aktiv >= 0 && i < aktiv;

            k.classList.toggle('zeile-aktiv', istAktiv);
            k.classList.toggle('zeile-uebersprungen', uebersprungen);
            // Grau bleibt, was weder erledigt noch dran noch übersprungen ist.
            k.classList.toggle('zeile-offen', !erledigt && !istAktiv && !uebersprungen);
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

        // `data-eintrag` kam vom Server und veraltet, sobald hier etwas
        // passiert — im einfachen Modus verschwindet die Zeile beim Ab-wählen.
        // hatEintrag() liest es als Rückfall, also muss es mitwandern.
        karte.dataset.eintrag = protokolliert ? '1' : '';

        // Abgehakt heißt: Der Wert steht fest. Ändern geht nur über Häkchen
        // entfernen, korrigieren, neu abhaken (§7.4).
        //
        // Im Expertenmodus gibt es dieses Feld nicht, und die Satzliste bleibt
        // bewusst offen: Dort ist das Nachtragen weiterer Sätze der Normalfall
        // und nicht die Korrektur — wer nach Satz 1 erst das Häkchen entfernen
        // müsste, um Satz 2 einzutragen, käme keine drei Übungen weit. Fest
        // stehen die Werte mit dem Ende der Einheit.
        const gewicht = qs('.gewicht', karte);
        if (gewicht) gewicht.readOnly = erledigt || sessionId <= 0;

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

    /** Wie viele weitere Sätze nach dem Scrollen noch hinpassen sollen. */
    const SAETZE_IN_SICHT = 3;

    /**
     * Meldet dem Tastatur-Anker (`assets/app.js`, Fallstrick 19g), wie viel
     * Platz unter einem Satzfeld freibleiben soll.
     *
     * Muss der Anker überhaupt scrollen — das Feld läge sonst hinter der
     * Tastatur —, dann soll er es gleich richtig tun: Wer im Satzblock tippt,
     * drückt als Nächstes „+ Satz" und füllt die neue Zeile aus. Landet das
     * Feld nur knapp über der Tastaturkante, ist der Knopf schon verdeckt und
     * man scrollt bei jedem Satz von Hand nach.
     *
     * Gerechnet wird vom **Ende des Blocks**, nicht vom Feld: Dort sitzt „+
     * Satz", und dort wachsen die neuen Zeilen hinein. Steht der Cursor in
     * Satz 1 von fünf, zählen die vier darunter mit — sie stehen ja im Weg.
     * Dazu Platz für SAETZE_IN_SICHT weitere Zeilen in der Höhe, die eine Zeile hier
     * tatsächlich hat; ein fester Pixelwert wäre bei der nächsten
     * Schriftgröße falsch.
     *
     * Ist es kein Satzfeld (einfacher Modus, Anmeldung, Adminmasken), gibt es
     * nichts zu reservieren — dann bleibt es bei der blossen Luft zur
     * Tastaturkante.
     *
     * **Zu viel ist hier ungefährlich:** Der Anker klammert nach oben ab und
     * schiebt das Feld nie unter den Leisten-Stapel. Im Zweifel landet das Feld
     * also ganz oben, und das ist genau der Fall mit dem meisten Platz darunter.
     */
    ankerReserveMelden((feld) => {
        const zeile = feld.closest('.satz-zeile');
        if (!zeile) return 0;

        const block = zeile.closest('.saetze-block');
        const zeilenHoehe = zeile.getBoundingClientRect().height;
        const blockUnten = block
            ? block.getBoundingClientRect().bottom
            : zeile.getBoundingClientRect().bottom;

        const darunter = Math.max(0, blockUnten - feld.getBoundingClientRect().bottom);

        return darunter + zeilenHoehe * SAETZE_IN_SICHT;
    });

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

        // Zwei Gründe, warum an einer Satzliste nichts zu ändern ist, und sie
        // brauchen zwei verschiedene Erklärungen: abgehakt (§7.4) — oder es
        // läuft überhaupt kein Training (§7.6). Der zweite ist der häufigere,
        // wenn jemand seinen Plan bloß durchsieht.
        const laeuft = sessionId > 0;
        const fest = istErledigt(karte) || !laeuft;
        const grund = laeuft ? 'Erst das Häkchen entfernen' : 'Erst das Training starten';

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

        // Mit dem ersten Satz wandert Grün hierher, und die Position, die man
        // dafür ausgelassen hat, wird orange. Das gehört an diese Stelle und
        // nicht an die einzelnen Aufrufer: Sätze ändern sich an mehreren
        // (Tippen, Stepper, „+ Satz", „✕"), und eine vergessene Stelle wäre
        // eine Farbe, die erst beim nächsten Neuladen stimmt.
        aktiveMarkieren();
    }

    // Welches der beiden Verfahren aus SATZ_VORLAGE (lib/training.php) gilt.
    // Beim Seitenaufbau festgeschrieben — es ändert sich während der Sitzung
    // nicht, und genau deshalb ist es eine Konstante und keine Abfrage bei
    // jedem Tipp.
    const vorlageGleicherSatz = liste.dataset.satzVorlage !== 'letzter_satz';

    /**
     * Die Vorbelegung für den nächsten Satz — der Benutzer wählt das Verfahren
     * auf der Kontoseite (§7.4).
     *
     * „Wie beim letzten Training" (Vorgabe): Satz k bekommt Satz k vom letzten
     * Mal. Wer 12/10/9 gewohnt ist, bekommt beim dritten Antippen 9 und nicht
     * 10 — die ganze Satzfolge entsteht mit drei Tipps ohne eine Korrektur.
     *
     * „Wie der Satz davor": Ab Satz 2 zählt, was heute im vorigen Satz steht.
     * Wer sich herantastet, korrigiert einmal und trägt die Korrektur damit
     * automatisch weiter.
     *
     * Der ERSTE Satz kommt in beiden Fällen vom letzten Training — deshalb das
     * `nr === 1` in der Bedingung. Gibt es gar keine Vorlage, bleibt das
     * zuletzt bekannte Gewicht dieser Übung mit leerem Wiederholungsfeld.
     *
     * Die Regel steht NUR hier. Ein PHP-Gegenstück gibt es bewusst nicht: Der
     * Server erfindet nie einen Satz, er liefert bloß die Vorlage.
     *
     * Bis 1.1.13 stand hier eine vierte Stufe („sonst der letzte Satz vom
     * letzten Mal"). Sie war nicht erreichbar: `nr` ist immer
     * `saetze.length + 1`, also greift ab Satz 2 stets die Stufe davor, und für
     * Satz 1 widersprach ihre Bedingung der ersten Stufe. Entfernt, damit die
     * neue Logik nicht um einen toten Zweig herum gebaut wird.
     */
    function naechsterSatz(karte, saetze) {
        const nr = saetze.length + 1;
        const letzte = satzListeAusDaten(karte.dataset.letzteSaetze);

        if ((nr === 1 || vorlageGleicherSatz) && letzte.length >= nr) {
            return letzte[nr - 1];
        }
        if (saetze.length > 0) {
            return saetze[saetze.length - 1];
        }
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
     * Der Gegenpol zu zurAktivenSpringen(): an den Anfang der Seite.
     *
     * Aufgerufen nach dem BEENDEN, wenn die neue Seite steht. Sie zeigt dann
     * etwas anderes als vorher — Startkasten, Planwahl, Vorschlag für das
     * nächste Training —, und das steht oben.
     *
     * ZWEIMAL gescrollt, und das ist kein Gürtel-und-Hosenträger: Der Browser
     * stellt eine gemerkte Scrollposition irgendwann während des Ladens wieder
     * her, und wann genau, ist nicht zugesichert. Läuft dieses Skript vorher,
     * überschriebe die Wiederherstellung den Sprung. Der zweite Aufruf beim
     * `load`-Ereignis liegt sicher dahinter. `neuLadenNachEnde()` schaltet die
     * Wiederherstellung ohnehin ab; das hier greift, wenn der Browser das nicht
     * kennt oder der Merker über einen anderen Weg gesetzt wurde.
     *
     * Ohne `behavior: 'smooth'`: Die Seite ist gerade erst entstanden, es gibt
     * keine Bewegung, der man folgen könnte — ein Scrollen über die halbe
     * Übungsliste wäre nur Zeit, in der man wartet.
     */
    function nachObenSpringen() {
        window.scrollTo(0, 0);
        window.addEventListener('load', () => window.scrollTo(0, 0), { once: true });
    }

    /**
     * Klappt den Satzblock der aktiven Übung auf und scrollt sie in den Blick.
     *
     * Zwei Aufrufer: nach dem Abhaken einer Übung und nach dem Start eines
     * Trainings. Beide Male ist die Frage dieselbe — „wo geht es weiter?".
     */
    function zurAktivenSpringen() {
        // Die markierte Karte, nicht „die erste noch nicht erledigte": Seit die
        // Regel Übersprungenes kennt, sind das zwei verschiedene Karten — nach
        // dem Auslassen einer Übung spränge die Ansicht sonst zurück auf das
        // belegte Gerät, statt weiterzugehen. aktiveMarkieren() ist vorher
        // gelaufen; im Zweifel wird eben nicht gescrollt.
        const aktive = qs('.zeile-aktiv', liste);
        if (!aktive) return;

        const block = qs('.saetze-block', aktive);
        if (block) block.open = true;

        // NICHT scrollIntoView({block:'start'}): Das setzt die Karte exakt an den
        // oberen Viewport-Rand — und dort klebt der Leisten-Stapel
        // (`position: sticky; top: 0`), der das Darunterliegende überlagert. Bei
        // laufender Einheit hängt dort immer die Trainingsleiste, im Störfall
        // zusätzlich die Verbindungsleiste; ohne den Versatz landete die Karte
        // unter ihnen und der Übungsname war verdeckt.
        //
        // Wie hoch der Stapel gerade baut, rechnet stapelUnterkante() in
        // assets/app.js aus — dieselbe Rechnung braucht seit 1.2.11 der
        // Tastatur-Anker, und zwei Fassungen davon liefen irgendwann
        // auseinander. Warum die unterste KANTE und nicht offsetHeight, steht
        // dort.
        const versatz = stapelUnterkante();

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

            // Die erste Aktion startet die Einheit — die Leiste mit den Zahlen
            // und dem Beenden-Knopf gibt es dann noch gar nicht.
            const brauchtReload = aktiv && daten.session_id && !qs('#fortschritt');
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

    // Im laufenden Training gibt es beide Wege: nur heute oder dauerhaft.
    //
    // VOR dem Start nur den dauerhaften. „Nur diese Einheit" braucht eine
    // session_id und legte dafür früher stillschweigend eine Einheit an — genau
    // das soll ein Tausch nicht mehr tun (§7.6). Der Knopf wird deshalb gar
    // nicht erst angeboten; api/swap.php lehnt es zusätzlich ab. Dauerhaft
    // tauschen bleibt möglich: Das ändert den Plan und nicht das Protokoll.
    const TAUSCH_KNOEPFE = sessionId > 0
        ? '<button type="button" class="waehlen" data-modus="session">'
          + 'Nur diese Einheit</button>'
          + '<button type="button" class="leise waehlen" data-modus="permanent">'
          + 'Dauerhaft im Plan</button>'
        : '<button type="button" class="waehlen" data-modus="permanent">'
          + 'Dauerhaft im Plan</button>';

    // Ohne diesen Satz sieht der fehlende zweite Knopf wie ein Fehler aus.
    const TAUSCH_HINWEIS = sessionId > 0
        ? ''
        : '<p class="matt">Nur für heute tauschen geht, sobald das Training läuft. '
          + 'Vorher lässt sich der Plan dauerhaft ändern.</p>';

    /** Zeichnet die Vorschlagsliste, gefiltert auf das gewählte Gerät. */
    function tauschZeichnen() {
        tauschListe.innerHTML = TAUSCH_HINWEIS
            + geraetGefiltert(tauschVorschlaege, tauschGeraet.value)
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

    // Nur direkt nach „Training starten" bzw. „Training beendet": Die Seite kam
    // gerade neu, und erst jetzt steht fest, wohin geschaut werden soll. Bei
    // jedem gewöhnlichen Seitenaufruf bleibt der Bildschirm, wo er ist.
    const sprungZiel = sprungAbholen();
    if (sprungZiel === SPRUNG_AKTIVE) zurAktivenSpringen();
    else if (sprungZiel === SPRUNG_OBEN) nachObenSpringen();

    // `scrollRestoration` gehört dem History-Eintrag und überlebt das Neuladen.
    // Zurückgestellt wird es deshalb IMMER und nicht nur nach einem Sprung:
    // Ginge der Merker verloren (privater Modus), bliebe der Tab sonst dauerhaft
    // ohne Wiederherstellung — und ein Neuladen mitten im Training spränge nach
    // oben. Erst beim `load`-Ereignis, denn bis dahin hätte der Browser eine
    // Wiederherstellung längst vorgenommen.
    window.addEventListener('load', () => {
        try {
            window.history.scrollRestoration = 'auto';
        } catch (e) {
            // Nichts zu tun.
        }
    }, { once: true });
})();
