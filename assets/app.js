'use strict';

/**
 * Geteilte Bausteine fuer alle Seiten. Seiten-Skripte (index.js,
 * plans.js, ...) setzen darauf auf und rufen NIE fetch() direkt.
 */

/**
 * Escaped Text vor dem Einsetzen per innerHTML.
 * Serverseitig macht das h() aus lib/helpers.php.
 */
function escapeHtml(wert) {
    return String(wert ?? '').replace(/[&<>"']/g, (z) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[z]));
}

/**
 * Das CSRF-Token aus dem <meta>-Tag im Kopf der Seite.
 */
function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Das Merkmal, mit dem lib/csrf.php ein totes Token kennzeichnet.
 * Muss mit CSRF_FEHLER_CODE dort uebereinstimmen.
 */
const CSRF_FEHLER_CODE = 'csrf_ungueltig';

/** Laeuft gerade eine Erneuerung? Dann warten alle auf dieselbe. */
let tokenErneuerung = null;

/**
 * Holt ein frisches CSRF-Token und schreibt es in den <meta>-Tag.
 *
 * Warum das ueberhaupt noetig ist, steht in api/token.php und in Fallstrick 23:
 * Die serverseitige Sitzung kann unter einer offenen Seite verschwinden, und
 * die Seite haelt dann ein Token, das zu niemandem mehr gehoert.
 *
 * Hier steht ausnahmsweise ein nacktes fetch() statt apiFetch() -- das waere
 * eine Rekursion, denn apiFetch ist ja gerade der Aufrufer. Die Regel "kein
 * direkter fetch()-Aufruf" gilt fuer SEITEN-Skripte; dies hier ist der
 * Wrapper selbst.
 *
 * Die gemeinsame Zusage verhindert, dass mehrere gleichzeitig gescheiterte
 * Aufrufe -- etwa zwei Eintraege der Warteschlange hintereinander -- jeder fuer
 * sich ein Token holen und sich dabei gegenseitig ueberschreiben.
 *
 * @returns {Promise<boolean>} true, wenn ein neues Token gesetzt wurde
 */
function tokenErneuern() {
    if (tokenErneuerung) {
        return tokenErneuerung;
    }

    tokenErneuerung = (async () => {
        try {
            const antwort = await fetch('api/token.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
            });
            if (!antwort.ok) {
                return false;
            }

            const nutzlast = await antwort.json();
            const neu = (nutzlast && nutzlast.ok === true && nutzlast.data)
                ? String(nutzlast.data.token || '')
                : '';
            if (neu === '') {
                return false;
            }

            const meta = document.querySelector('meta[name="csrf-token"]');
            if (!meta) {
                return false;
            }
            meta.setAttribute('content', neu);
            return true;
        } catch (e) {
            // Netzproblem: Der Aufrufer faellt auf seinen normalen Fehlerweg
            // zurueck, die Warteschlange behaelt ihren Eintrag.
            return false;
        } finally {
            tokenErneuerung = null;
        }
    })();

    return tokenErneuerung;
}

/**
 * Zeitlimit fuer einen einzelnen Serveraufruf.
 *
 * Der Grund ist der schlechte Empfang im Studio, nicht die Sparsamkeit:
 * `fetch` kennt von sich aus KEIN Zeitlimit. Bei abgerissener Verbindung
 * wirft es sofort und die Fehlerbehandlung greift -- bei schlechtem Empfang
 * wartet es dagegen, bis der Browser nach einer halben bis zwei Minuten von
 * selbst aufgibt. In der Trainingsansicht bedeutete das: Das Haekchen steht
 * deaktiviert da, ohne Meldung, ohne Wiederholen-Knopf. Genau dieser Zustand
 * -- ein Balken Empfang, nicht null -- ist der Regelfall auf Mobilfunk.
 */
const API_ZEITLIMIT_MS = 12000;

/**
 * Zeitlimit fuer Aufrufe, bei denen eine Datei mitgeht.
 *
 * Ein Uebungsbild oder eine Sicherung mit Bildern sind ein Vielfaches dessen,
 * was ein JSON-Aufruf uebertraegt -- ueber Mobilfunk dauert das laenger als
 * zwoelf Sekunden, ohne dass irgendetwas kaputt waere. Das Limit haengt
 * deshalb am FormData-Body und nicht an der Aufrufstelle: So kann man es beim
 * naechsten Upload nicht vergessen.
 */
const API_ZEITLIMIT_UPLOAD_MS = 120000;

/**
 * Wartezeiten zwischen den automatischen Wiederholungen -- drei Pausen, also
 * bis zu vier Versuche.
 *
 * Der ERSTE Wert ist kurz, und das ist der Punkt. Bis 1.2.10 stand hier
 * [2000, 5000]: Scheiterte der erste Versuch sofort -- ein Aussetzer im WLAN,
 * eine abgelaufene Verbindung, ein Funkloch von einer Zehntelsekunde --, wartete
 * der Code stur zwei Sekunden, bevor er es erneut versuchte. Bei "Training
 * starten" sah das so aus: Knopf blass, zwei Sekunden nichts, dann laeuft das
 * Training. Gemeldet am 2026-08-23.
 *
 * Ein Aussetzer, der beim zweiten Versuch weg ist, ist nach 400 ms genauso weg
 * wie nach 2000. Dafuer gibt es jetzt einen Umlauf mehr -- der schlimmste Fall
 * sinkt trotzdem von 7 s auf 4,4 s Wartezeit, und ein laengerer Aussetzer hat
 * eine Gelegenheit mehr, sich zu erholen.
 *
 * Fuer die Warteschlange aendert das wenig: Dort bleibt der Eintrag bei
 * err.offline ohnehin liegen und wird vom eigenen Zeitgeber nachgeholt.
 */
const API_PAUSEN_MS = [400, 2000, 2000];

function schlafen(ms) {
    return new Promise((fertig) => setTimeout(fertig, ms));
}

/**
 * Der EINE fetch-Wrapper der Anwendung.
 *
 * Nimmt einem Seiten-Skript die Dinge ab, die sonst an jeder Aufrufstelle
 * einzeln richtig gemacht werden muessten:
 *   - X-CSRF-Token setzen
 *   - Objekt-Bodies als JSON kodieren, FormData unangetastet durchreichen
 *     (dort setzt der Browser die multipart-Grenze selbst -- ein manuell
 *     gesetzter Content-Type macht den Upload kaputt)
 *   - den Umschlag {ok, data} auspacken
 *   - bei 401 zur Anmeldung schicken
 *   - ein totes CSRF-Token einmalig erneuern und den Aufruf wiederholen
 *   - nach API_ZEITLIMIT_MS abbrechen, statt beliebig lange zu haengen
 *   - den Verbindungszustand fuer die Leiste am Seitenkopf fortschreiben
 *
 * Wirft bei Fehlern ein Error-Objekt, dessen .fields die feldbezogenen
 * Meldungen aus json_err() traegt. Bei Netzproblemen traegt es zusaetzlich
 * .offline = true und, wenn das Zeitlimit zugeschlagen hat, .timeout = true.
 *
 * Zusaetzliche Optionen ueber die von fetch hinaus:
 *   zeitlimit    Zahl   Millisekunden statt API_ZEITLIMIT_MS
 *   wiederholen  bool   bis zu zwei automatische Wiederversuche
 *
 * `wiederholen` ist AUSDRUECKLICH und nicht der Standard: Ein Wiederversuch
 * ist nur dort erlaubt, wo der Endpunkt ihn vertraegt. api/log.php (Upsert
 * ueber (session_id, plan_exercise_id)), api/session.php -> start
 * (einheit_sicherstellen() liefert die laufende Einheit zurueck) und die
 * lesenden Aktionen vertragen ihn. api/session.php -> end vertraegt ihn NICHT:
 * Der zweite Aufruf findet keine offene Einheit mehr und antwortet mit 409.
 */
async function apiFetch(url, options = {}) {
    const opt = { ...options };
    const versuche = opt.wiederholen ? API_PAUSEN_MS.length + 1 : 1;
    const gesetztesLimit = Number(opt.zeitlimit);
    delete opt.zeitlimit;
    delete opt.wiederholen;

    opt.headers = { 'X-CSRF-Token': csrfToken(), ...(options.headers || {}) };
    opt.credentials = 'same-origin';

    const body = opt.body;
    const istFormData = typeof FormData !== 'undefined' && body instanceof FormData;

    const zeitlimit = gesetztesLimit > 0
        ? gesetztesLimit
        : (istFormData ? API_ZEITLIMIT_UPLOAD_MS : API_ZEITLIMIT_MS);

    if (body !== undefined && body !== null && !istFormData && typeof body === 'object') {
        opt.body = JSON.stringify(body);
        opt.headers['Content-Type'] = 'application/json';
    }

    if (opt.body !== undefined && !opt.method) {
        opt.method = 'POST';
    }

    // Die Token-Erneuerung gibt es EINMAL je Aufruf. Sonst liefe der Aufruf im
    // Kreis, wenn der Server das frische Token ebenfalls ablehnt.
    let tokenErneuert = false;

    for (let versuch = 0; ; versuch++) {
        const letzterVersuch = versuch >= versuche - 1;

        // Fuer jeden Versuch ein eigener Abbruch -- ein AbortSignal laesst sich
        // nach dem Ausloesen nicht zuruecksetzen.
        const abbruch = new AbortController();
        const wecker = setTimeout(() => abbruch.abort(), zeitlimit);
        opt.signal = abbruch.signal;

        let antwort;
        try {
            antwort = await fetch(url, opt);
        } catch (e) {
            clearTimeout(wecker);
            if (!letzterVersuch) {
                await schlafen(API_PAUSEN_MS[versuch]);
                continue;
            }
            // Erst JETZT gilt die Verbindung als weg, nicht schon beim ersten
            // gescheiterten Versuch. Ein Aussetzer, den der Wiederversuch nach
            // 400 ms auffaengt, ist kein Problem, das jemand sehen muss -- die
            // Leiste blitzte dafuer fuer einen Sekundenbruchteil rot auf, und
            // genau solches Aufblitzen soll es nicht mehr geben.
            verbindung.erreichbar(false);
            throw verbindungsFehler(abbruch.signal.aborted);
        }
        clearTimeout(wecker);

        // 5xx ist ein voruebergehender Serverfehler -- der Wiederversuch lohnt.
        // 4xx ist eine Ablehnung und wiederholt sich bei jedem Versuch gleich.
        if (antwort.status >= 500 && !letzterVersuch) {
            await schlafen(API_PAUSEN_MS[versuch]);
            continue;
        }

        verbindung.erreichbar(true);

        // 401 heisst "Sitzung abgelaufen" -- ausser auf der Anmeldeseite selbst,
        // wo es schlicht "Passwort falsch" heisst. Ohne diese Ausnahme wuerde ein
        // Tippfehler die Seite neu laden und die Meldung mitnehmen.
        if (antwort.status === 401 && !/\/login\.php$/.test(window.location.pathname)) {
            window.location.href = 'login.php';
            // Nie erreicht, verhindert aber, dass der Aufrufer weiterrechnet.
            throw new Error('Nicht angemeldet.');
        }

        let nutzlast = null;
        try {
            nutzlast = await antwort.json();
        } catch (e) {
            nutzlast = null;
        }

        // Ein totes Token ist KEINE fachliche Ablehnung, sondern ein
        // reparabler Zustand: Die Sitzung ist unter der offenen Seite
        // verschwunden, "Angemeldet bleiben" hat laengst eine neue aufgemacht,
        // und ihr fehlt nur das Token. Frueher scheiterte von da an JEDER
        // Schreibaufruf mit 403, bis jemand von Hand neu lud -- im Studio am
        // 2026-08-16 genau so passiert (Fallstrick 23).
        //
        // Das kostet ausdruecklich KEINEN der Versuche aus `wiederholen`:
        // Die sind fuer schlechtes Netz gedacht, und der Aufruf hier ist gar
        // nicht gescheitert, er war nur falsch adressiert.
        if (antwort.status === 403
            && nutzlast && nutzlast.code === CSRF_FEHLER_CODE
            && !tokenErneuert) {
            tokenErneuert = true;
            if (await tokenErneuern()) {
                opt.headers['X-CSRF-Token'] = csrfToken();
                // Das versuch-- gleicht das versuch++ der Schleife aus, dieser
                // Durchgang zaehlt also nicht mit. `tokenErneuert` deckelt es.
                versuch--;
                continue;
            }
        }

        if (!antwort.ok || !nutzlast || nutzlast.ok !== true) {
            const fehler = new Error((nutzlast && nutzlast.error) || 'Unerwarteter Serverfehler.');
            fehler.status = antwort.status;
            fehler.fields = (nutzlast && nutzlast.fields) || {};
            fehler.code   = (nutzlast && nutzlast.code) || '';
            throw fehler;
        }

        return nutzlast.data;
    }
}

/**
 * Der Fehler, den ein Netzproblem wirft.
 *
 * Zeitlimit und harter Abriss bekommen verschiedene Texte: "Der Server
 * antwortet nicht" beschreibt schlechten Empfang, "keine Verbindung" das
 * Funkloch. Fuer den Benutzer am Geraet ist das der Unterschied zwischen
 * "gleich nochmal" und "erst rausgehen".
 */
function verbindungsFehler(abgelaufen) {
    const fehler = new Error(abgelaufen
        ? 'Der Server antwortet nicht — vermutlich schlechter Empfang.'
        : 'Keine Verbindung zum Server.');
    fehler.offline = true;
    fehler.timeout = !!abgelaufen;
    return fehler;
}

/* --- Verbindungszustand -------------------------------------------------- */

// Wie lange nach einem Verbindungsverlust bis zur naechsten Nachfrage.
const VERBINDUNG_NACHFASSEN_MS = 15000;

/**
 * Die Leiste am oberen Rand -- eine WARNUNG, keine Fortschrittsanzeige.
 *
 * Sie kennt genau einen Zustand: Der Server ist nicht erreichbar, Eingaben
 * kommen gerade nicht durch. Dann steht sie rot da und bleibt stehen, bis das
 * Problem weg ist.
 *
 * Bis 1.2.14 meldete sie zusaetzlich den fluechtigen Zustand "n Eingaben
 * werden gespeichert ...". Der erschien bei JEDEM Abhaken fuer einen
 * Sekundenbruchteil -- und war damit genau die Sorte Anzeige, die staendig
 * auf- und zuklappt, ohne je etwas zu sagen, wonach jemand handeln koennte.
 * Die wartende Zeile ist ohnehin am gestrichelten Rand zu erkennen
 * (.zeile-wartet), und ein endgueltig gescheitertes Speichern meldet die Zeile
 * selbst, mit Wiederholen-Knopf. Damit ist auch der ganze Schwebe-Kniff aus
 * 1.2.10 (.leiste-schwebt) hinfaellig: Was nur noch bei einem echten
 * Zustandswechsel erscheint, darf den Inhalt einmal schieben.
 *
 * `navigator.onLine` allein taugt als Quelle nicht: Es meldet lediglich, ob das
 * Geraet ueberhaupt eine Netzwerkschnittstelle hat. Im Studio-WLAN ohne
 * Internet oder bei einem Balken Mobilfunk steht es auf true, waehrend jeder
 * Aufruf ins Leere laeuft. Die belastbare Aussage liefert deshalb apiFetch:
 * Was tatsaechlich gescheitert ist, zaehlt mehr als das, was der Browser
 * vermutet.
 */
const verbindung = {
    _erreichbar: true,
    _wartend: 0,
    _pruefUhr: null,

    /**
     * Von apiFetch gesetzt: Hat der Aufruf den Server erreicht?
     *
     * `false` kommt erst, wenn ein Aufruf ENDGUELTIG gescheitert ist -- nach
     * allen Wiederversuchen, die er hatte.
     */
    erreichbar(ja) {
        if (this._erreichbar === ja) return;
        this._erreichbar = ja;
        this._zeichnen();
    },

    /**
     * Von der Seite gesetzt: Wie viele Eingaben warten noch auf das Netz?
     *
     * Die Zahl loest die Leiste NICHT aus -- sie steht nur in ihr, wenn das
     * Netz ohnehin weg ist. Wartende Eingaben bei stehender Verbindung sind
     * kein Problem, sondern der Normalfall zwischen zwei Tastendruecken.
     */
    wartend(anzahl) {
        const n = Number(anzahl) || 0;
        if (this._wartend === n) return;
        this._wartend = n;
        this._zeichnen();
    },

    _element() {
        let el = qs('#verbindung');
        if (!el) {
            el = document.createElement('div');
            el.id = 'verbindung';
            el.className = 'verbindungs-leiste';
            // status statt alert: Ein Verbindungsabriss ist eine Lagemeldung,
            // keine Meldung, die den Screenreader unterbrechen sollte.
            el.setAttribute('role', 'status');
            el.hidden = true;

            // In den Leisten-Stapel aus lib/view_header.php, und zwar ganz
            // nach UNTEN -- die Trainingsleiste steht laenger da als diese
            // hier und gehoert deshalb oben hin (siehe dort).
            //
            // Der Rueckfall auf body ist fuer den Fall, dass eine Seite den
            // Stapel nicht rendert (etwa ohne Kopf-Partial). Dann klebt die
            // Leiste wie bis 1.1.13 selbst; die Regel dafuer steht im
            // Stylesheet.
            const stapel = qs('#leisten');
            if (stapel) {
                stapel.appendChild(el);
            } else {
                el.classList.add('leiste-allein');
                document.body.insertBefore(el, document.body.firstChild);
            }
        }
        return el;
    },

    _zeichnen() {
        const weg = !navigator.onLine || !this._erreichbar;
        const n   = this._wartend;
        let text = '';

        if (weg && n > 0) {
            text = 'Keine Verbindung zum Server — ' + n
                 + (n === 1 ? ' Eingabe wartet' : ' Eingaben warten')
                 + ' auf das Netz. Nichts geht verloren.';
        } else if (weg) {
            text = 'Keine Verbindung zum Server.';
        }

        const el = this._element();
        el.textContent = text;
        el.hidden = text === '';

        if (weg) this._nachfassenPlanen();
    },

    /**
     * Fragt selbst nach, ob der Server wieder da ist.
     *
     * Die Zusage lautet: Die Leiste bleibt stehen, BIS das Problem weg ist --
     * also braucht es etwas, das das Ende des Problems ueberhaupt bemerkt. In
     * der Trainingsansicht erledigt das die Warteschlange, die ohnehin
     * nachfasst; auf jeder anderen Seite passiert von selbst gar nichts, und
     * die Leiste stuende bis zum naechsten Klick rot da, obwohl das Netz
     * laengst zurueck ist. Auf das `online`-Ereignis ist dabei kein Verlass:
     * Beim klassischen einen Balken Empfang feuert es nie.
     *
     * Bewusst ein nackter fetch und kein apiFetch: Gefragt ist allein, ob
     * ueberhaupt eine Antwort kommt. JEDE Antwort beweist das -- auch ein 401,
     * bei dem apiFetch zur Anmeldung umleiten wuerde, und zwar aus einer
     * Hintergrundabfrage heraus mitten im Training.
     */
    _nachfassenPlanen() {
        if (this._pruefUhr !== null) return;

        this._pruefUhr = setTimeout(() => {
            this._pruefUhr = null;
            // Nur der von apiFetch gemeldete Zustand wird nachgeprueft. Steht
            // die Leiste bloss wegen navigator.onLine, meldet sich das
            // `online`-Ereignis von selbst.
            if (this._erreichbar) return;

            fetch('api/token.php', { cache: 'no-store', credentials: 'same-origin' })
                .then(() => this.erreichbar(true))
                .catch(() => this._nachfassenPlanen());
        }, VERBINDUNG_NACHFASSEN_MS);
    },
};

window.addEventListener('offline', () => verbindung._zeichnen());
window.addEventListener('online', () => verbindung.erreichbar(true));

/* --- Warteschlange ------------------------------------------------------- */

// Die Nummer im Namen steht genau fuer solche Faelle: Aendert sich die FORM
// eines Eintrags, bekommt der Schluessel eine neue Nummer, und die alte Ablage
// bleibt unberuehrt liegen statt falsch verstanden zu werden.
//
//   v2 (1.1.0): Eintrag traegt zusaetzlich `sets`. Ohne das Feld liefe er im
//               Expertenmodus als "check ohne Satzliste" durch -- und der
//               loescht die Saetze der Position (api/log.php).
//   v3 (1.1.1): Eintrag traegt zusaetzlich `done`. Ohne das Feld gilt eine
//               Position serverseitig als erledigt, sobald ueberhaupt etwas
//               protokolliert ist -- ein wartender erster Satz haekte die
//               Uebung also ab, obwohl der Benutzer noch mitten drin ist.
const WARTESCHLANGE_SCHLUESSEL = 'trainingsplan-warteschlange-v3';

/** Aelter als das: Der Eintrag gehoert zu einem vergessenen Training. */
const WARTESCHLANGE_MAX_ALTER_MS = 24 * 3600 * 1000;

/**
 * Ablage fuer Eingaben, die noch nicht beim Server angekommen sind (§7.4).
 *
 * Bewusst localStorage und nicht IndexedDB: Die Nutzlast ist ein winziges
 * Objekt je Planposition, und ein zweiter Baustein widerspraeche der Regel
 * "kein Build-Step, keine Abhaengigkeiten".
 *
 * ZWEI SCHLUESSEL, die nicht fehlen duerfen:
 *
 * - `user_id`, weil localStorage der Herkunft gehoert und nicht der Sitzung.
 *   Auf einem geteilten Geraet holte der naechste Angemeldete sonst die
 *   Haekchen seines Vorgaengers nach.
 * - `session_id`, weil ein Eintrag genau zu EINER Trainingseinheit gehoert.
 *   Ohne diese Pruefung liefe ein Haekchen von gestern Abend in die Einheit
 *   von heute frueh.
 *
 * Passt eines von beiden nicht, wird die Ablage verworfen statt geraten. Das
 * ist die einzige vertretbare Reaktion: Ein Haekchen, dessen Einheit nicht
 * mehr feststeht, wuerde beim Nachholen eine NEUE Einheit eroeffnen --
 * einheit_sicherstellen() in api/log.php tut genau das (§7.6).
 *
 * Je Planposition steht hoechstens EIN Eintrag; ein neuer ueberschreibt den
 * alten. Abhaken, abwaehlen und erneut abhaken erzeugt deshalb einen Aufruf
 * und nicht drei, und die Schlange kann nie laenger werden als der Plan.
 *
 * `sessionId` 0 heisst "keine offene Einheit". Die Schlange ist dann nicht
 * `aktiv`, raeumt beim ersten Lesen aber eine liegengebliebene Ablage weg.
 */
function warteschlange(userId, sessionId) {
    let verfuegbar = true;
    try {
        // Im privaten Modus mancher Browser wirft schon der Zugriff.
        window.localStorage.getItem(WARTESCHLANGE_SCHLUESSEL);
    } catch (e) {
        verfuegbar = false;
    }

    function wegwerfen() {
        try {
            window.localStorage.removeItem(WARTESCHLANGE_SCHLUESSEL);
        } catch (e) {
            // Nicht schreibbar: Beim Lesen faellt die Ablage ohnehin durch.
        }
    }

    function lesen() {
        if (!verfuegbar) return {};
        let ablage = null;
        try {
            ablage = JSON.parse(window.localStorage.getItem(WARTESCHLANGE_SCHLUESSEL));
        } catch (e) {
            ablage = null;
        }
        if (!ablage) return {};

        if (Number(ablage.user_id) !== userId
            || Number(ablage.session_id) !== sessionId) {
            // Fremder Benutzer oder eine andere Einheit -- nicht bloss
            // ignorieren, sondern entfernen. Sonst laege der Rest eines
            // beendeten Trainings unbegrenzt im Speicher des Geraets.
            wegwerfen();
            return {};
        }

        // Zu alte Eintraege stammen aus einem vergessenen Training.
        const grenze = Date.now() - WARTESCHLANGE_MAX_ALTER_MS;
        const eintraege = ablage.eintraege || {};
        Object.keys(eintraege).forEach((k) => {
            if (Number(eintraege[k].ts) < grenze) delete eintraege[k];
        });
        return eintraege;
    }

    function schreiben(eintraege) {
        if (!verfuegbar) return;
        try {
            if (Object.keys(eintraege).length === 0) {
                window.localStorage.removeItem(WARTESCHLANGE_SCHLUESSEL);
                return;
            }
            window.localStorage.setItem(WARTESCHLANGE_SCHLUESSEL, JSON.stringify({
                user_id: userId,
                session_id: sessionId,
                eintraege: eintraege,
            }));
        } catch (e) {
            // Voller Speicher: Die Eingabe geht dann direkt ans Netz oder
            // scheitert sichtbar. Verschluckt wird nichts.
        }
    }

    // Einmal lesen, damit eine Ablage aus einer fremden Einheit sofort
    // verschwindet -- auch dann, wenn diese Schlange gar nicht `aktiv` ist und
    // sonst nie jemand hineinsaehe.
    lesen();

    return {
        // Nur innerhalb einer laufenden Einheit und nur mit nutzbarer Ablage.
        aktiv: verfuegbar && sessionId > 0,
        eintraege: lesen,
        eintrag(peId) {
            return lesen()[String(peId)] || null;
        },
        anzahl() {
            return Object.keys(lesen()).length;
        },
        setzen(peId, eintrag) {
            const alle = lesen();
            alle[String(peId)] = eintrag;
            schreiben(alle);
        },
        entfernen(peId) {
            const alle = lesen();
            delete alle[String(peId)];
            schreiben(alle);
        },
        leeren() {
            schreiben({});
        },
    };
}

/**
 * Kurzform fuer querySelector im Dokument oder in einem Element.
 */
function qs(selektor, wurzel = document) {
    return wurzel.querySelector(selektor);
}

function qsa(selektor, wurzel = document) {
    return Array.from(wurzel.querySelectorAll(selektor));
}

/* --- Leisten-Stapel und Scrollen ----------------------------------------- */

/**
 * Die unterste Kante des Leisten-Stapels, in Viewport-Koordinaten.
 *
 * Gemessen wird der ganze STAPEL (#leisten aus lib/view_header.php) und nicht
 * eine Liste einzelner Leisten: Dort haengen je nach Lage keine, eine oder
 * zwei drin, und eine Aufzaehlung waere genau die Stelle, an der man die
 * dritte vergisst. Ausgeblendete Kinder tragen nichts bei, ein leerer Stapel
 * misst 0.
 *
 * Gemessen wird die UNTERSTE KANTE, nicht offsetHeight. Heute liegt beides
 * gleichauf -- seit 1.2.15 laeuft jede Leiste wieder im Fluss mit, nachdem der
 * fluechtige Zustand der Verbindungsleiste entfallen ist und mit ihm die
 * schwebende Fassung aus 1.2.10 (`.leiste-schwebt`, position: absolute). Die
 * Rechnung ueber die Kanten bleibt trotzdem: Sie stimmt auch fuer eine Leiste,
 * die aus dem Fluss genommen ist, und das ist genau der Fall, den man beim
 * naechsten Mal wieder braeuchte, ohne hier daran zu denken.
 */
function stapelUnterkante() {
    const stapel = qs('#leisten');
    if (!stapel) return 0;

    let unten = stapel.getBoundingClientRect().bottom;
    for (const kind of stapel.children) {
        if (kind.hidden) continue;
        unten = Math.max(unten, kind.getBoundingClientRect().bottom);
    }

    return unten;
}

/**
 * Tastatur-Anker: haelt die Seite still, waehrend die Bildschirmtastatur
 * hochfaehrt (Fallstrick 19g).
 *
 * WebKit scrollt beim Fokussieren eines Eingabefelds die Seite, damit das Feld
 * ueber der Tastatur steht -- und zwar auch dann, wenn es dort ohnehin schon
 * stuende. Am iPhone rutschte deshalb bei jedem Tipp ins Gewichtsfeld die ganze
 * Trainingsansicht ein Stueck nach oben; Chrome auf dem Pixel laesst sie stehen.
 * Das ist dieselbe Sorte Aerger wie die springende Verbindungsleiste: Was von
 * selbst kommt und geht, darf nichts verschieben.
 *
 * Abschalten laesst sich das nicht -- es gibt keine CSS-Eigenschaft und keinen
 * Viewport-Schalter dafuer. (`interactive-widget` kennt nur Chromium, und der
 * wuerde ausgerechnet das Geraet umstellen, das sich heute richtig verhaelt.)
 *
 * DER AUFBAU IN ZWEI PHASEN IST DER GANZE WITZ. Der naheliegende Weg -- "warten,
 * bis die Tastatur oben ist, dann zurueckscrollen" -- erzeugt genau das, was
 * niemand will: Der Browser bewegt sichtbar, das Skript bewegt sichtbar zurueck,
 * das Feld huepft. Stattdessen:
 *
 *  1. FESTHALTEN. Jede fremde Scrollbewegung wird sofort im scroll-Ereignis
 *     zurueckgenommen, ohne Vorbedingung. Das laeuft noch vor dem naechsten
 *     Bildaufbau, der Zwischenzustand wird also gar nicht erst gezeichnet -- die
 *     Seite steht einfach still.
 *  2. EINMAL ENTSCHEIDEN. Sobald der Sichtbereich zur Ruhe gekommen ist (die
 *     Tastatur steht), wird EIN Mal nachgesehen: Ist das Feld sichtbar, war es
 *     das, und die Seite hat sich nie bewegt. Ist es verdeckt, folgt genau EINE
 *     Bewegung -- und dann grosszuegig, siehe ankerReserveMelden().
 *
 * Im Normalfall also gar keine Bewegung, im Ausnahmefall eine statt hin und her.
 *
 * GEMESSEN WIRD GEGEN DEN SICHTBAREN BEREICH (visualViewport), nicht gegen eine
 * eigene Annahme darueber, wie hoch eine Tastatur ist. Damit haengt die Frage am
 * Zustand und nicht am Browser -- und beide Geraete beantworten sie gleich:
 *
 *   Feld bei offener Tastatur sichtbar  -> es wird NICHT gescrollt.
 *   Feld von der Tastatur verdeckt      -> es wird gescrollt, einmal.
 *
 * Das ist die ganze Zusage, und sie gilt in beide Richtungen. Der Anker nimmt
 * dem iPhone die ueberfluessige Bewegung, und er ERGAENZT eine, wo der Browser
 * von sich aus keine macht, das Feld aber verdeckt waere. "Sichtbar" schliesst
 * dabei den Leisten-Stapel ein: Was unter der klebenden Leiste liegt, ist
 * genauso wenig zu sehen wie das, was hinter der Tastatur liegt.
 *
 * Vier Grenzen, jede aus eigenem Grund:
 *
 *  - NUR NACH EINER BERUEHRUNG. Ein Mauszeiger loest keine Tastatur aus. Ohne
 *    diese Bedingung wuerde am Schreibtisch ein Mausrad-Scroll direkt nach dem
 *    Klick ins Feld zurueckgedreht -- und schlimmer, jedes Reveal-Scrollen des
 *    Browsers, das dort voellig in Ordnung ist.
 *  - NUR TEXTFELDER, und zwar nach einer Positivliste (TASTATUR_TYPEN).
 *    Ausdruecklich KEINE Kaestchen -- Begruendung dort.
 *  - NUR EIN KNAPPES ZEITFENSTER bei gehaltenem Fokus, und eine bewusste
 *    Wischgeste (wheel, touchmove) loest sofort. Wer waehrend des Tippens
 *    scrollt, soll gescrollt haben.
 *  - NUR BEGRENZT OFT, als Notbremse gegen einen Browser, der sich anders
 *    verhaelt als gedacht.
 */
const TASTATUR_LUFT = 8;

/** So lange nach dem Fokus wird festgehalten; danach entscheidet Phase 2. */
const TASTATUR_FENSTER_MS = 900;

/** Ruhe im Sichtbereich, ab der die Tastatur als "steht" gilt. */
const TASTATUR_RUHE_MS = 160;

/** So alt darf die Beruehrung sein, die den Fokus ausgeloest hat. */
const TASTATUR_BERUEHRUNG_MS = 1000;

/** Notbremse gegen ein Hochschaukeln; die echte Grenze ist das Zeitfenster. */
const TASTATUR_MAX_KORREKTUREN = 120;

/**
 * Welche Feldarten eine Tastatur hochholen -- als POSITIVLISTE.
 *
 * Die Umkehrung ("alles ausser Kaestchen und Knoepfen") waere kuerzer und
 * falsch herum: Eine Feldart, die niemand bedacht hat, bekaeme dann von selbst
 * einen Anker. So bekommt sie keinen, und das ist der harmlosere Irrtum.
 *
 * Kaestchen fehlen hier nicht aus Ordnungsliebe: Das Haekchen "Erledigt" ist
 * ein <input>, und index.js scrollt unmittelbar danach zur naechsten Uebung
 * (zurAktivenSpringen). Der Anker wuerde diesen Sprung festhalten und damit
 * eine gewollte Bewegung verschlucken.
 */
const TASTATUR_TYPEN = new Set(['text', 'search', 'tel', 'url', 'email',
    'password', 'number', 'date', 'time', 'datetime-local', 'month', 'week']);

/** Holt der Fokus auf dieses Element eine Bildschirmtastatur hoch? */
function holtTastatur(el) {
    if (el.tagName === 'TEXTAREA') return true;
    if (el.tagName !== 'INPUT') return false;

    return TASTATUR_TYPEN.has(String(el.type || 'text').toLowerCase());
}

/**
 * Wie viel Platz UNTER dem Feld freibleiben soll, wenn ohnehin gescrollt wird.
 *
 * Vorgabe: keiner -- dann bleibt es bei der blossen Luft zur Tastaturkante.
 * Die Trainingsansicht meldet hier ihren eigenen Bedarf an (index.js): Wer im
 * Satzblock tippt, will gleich danach "+ Satz" druecken und den neuen Satz
 * ausfuellen, ohne erneut zu scrollen.
 *
 * Als MELDER und nicht als feste Rechnung, weil app.js von Satzzeilen nichts
 * wissen soll -- das ist Sache der Seite, die sie baut. Sonst stuenden hier
 * Klassennamen aus index.js, und die naechste Seite mit einem aehnlichen
 * Beduerfnis brauchte einen zweiten Sonderfall daneben.
 */
let ankerReserveGeber = null;

/** Meldet die Rechnung an; nimmt das fokussierte Feld, liefert Pixel. */
function ankerReserveMelden(geber) {
    ankerReserveGeber = geber;
}

let ankerFeld = null;
let ankerY = 0;
let ankerBis = 0;
let ankerKorrekturen = 0;
let ankerUhr = null;
let letzteBeruehrung = 0;

/** Gibt die Seite wieder frei. */
function ankerLoesen() {
    ankerFeld = null;
    clearTimeout(ankerUhr);
    ankerUhr = null;
}

/** Die untere Kante des sichtbaren Bereichs, Tastatur eingerechnet. */
function sichtUnterkante() {
    const sicht = window.visualViewport;

    return sicht ? sicht.offsetTop + sicht.height : window.innerHeight;
}

/**
 * Phase 1: nimmt eine fremde Scrollbewegung sofort zurueck.
 *
 * Ohne jede Pruefung, ob das Feld danach sichtbar waere -- das entscheidet
 * Phase 2, und zwar erst dann, wenn die Tastatur wirklich steht und die Frage
 * ueberhaupt beantwortbar ist.
 */
function ankerHalten() {
    if (!ankerFeld) return;

    if (Date.now() > ankerBis
        || ankerKorrekturen >= TASTATUR_MAX_KORREKTUREN
        || document.activeElement !== ankerFeld) {
        ankerLoesen();
        return;
    }

    if (window.scrollY === ankerY) return;

    ankerKorrekturen += 1;
    window.scrollTo(window.scrollX, ankerY);
}

/**
 * Phase 2: einmal nachsehen und den Anker loesen.
 *
 * Drei Zeilen Rechnung, und die Reihenfolge ist der ganze Inhalt:
 *
 *  1. WANN ueberhaupt bewegt wird, entscheidet allein das Feld: Steht seine
 *     Unterkante ueber der Tastatur, ist nichts zu tun -- auch dann nicht, wenn
 *     darunter kein Platz mehr fuer weitere Saetze waere. Sonst waere praktisch
 *     jeder Fokus eine Bewegung, und die Zusage "sichtbar heisst stehenbleiben"
 *     waere hinfaellig.
 *  2. WIE WEIT bewegt wird, entscheidet die gemeldete Reserve: Wenn schon
 *     gescrollt wird, dann gleich so, dass darunter noch etwas hinpasst.
 *  3. Die Klammer nach oben. Sie faengt drei Faelle auf einmal: das Feld ueber
 *     dem Stapel, ein Feld hoeher als der verbliebene Streifen -- und die
 *     grosszuegige Reserve aus (2), die sonst das Feld selbst unter die
 *     klebende Leiste schoebe. Mehr als "Feld direkt unter den Stapel" ist
 *     nicht zu holen, und mehr Platz darunter gibt es auf diesem Bildschirm
 *     dann auch nicht.
 */
function ankerAbschliessen() {
    const feldEl = ankerFeld;
    ankerLoesen();
    if (!feldEl || document.activeElement !== feldEl) return;

    const feld = feldEl.getBoundingClientRect();
    const oben = stapelUnterkante() + TASTATUR_LUFT;
    const unten = sichtUnterkante() - TASTATUR_LUFT;
    const reserve = ankerReserveGeber ? ankerReserveGeber(feldEl) : 0;

    let weg = 0;
    if (feld.bottom > unten) weg = feld.bottom + reserve - unten;
    if (feld.top - weg < oben) weg = feld.top - oben;

    if (weg !== 0) window.scrollBy(0, weg);
}

/**
 * Setzt Phase 2 neu an -- nie spaeter als das Ende des Zeitfensters.
 *
 * Jede Aenderung am Sichtbereich schiebt sie nach hinten: Solange sich die
 * Tastatur noch bewegt, ist die Frage "ist das Feld sichtbar" nicht sinnvoll zu
 * beantworten.
 */
function ankerNachfassen(ms) {
    clearTimeout(ankerUhr);
    ankerUhr = setTimeout(ankerAbschliessen,
        Math.max(0, Math.min(ms, ankerBis - Date.now())));
}

// Eine Beruehrung merken, BEVOR der Fokus kommt: Nur sie holt eine Tastatur
// hoch. pointerdown deckt den Normalfall ab, touchstart aeltere Fassungen.
function beruehrt(e) {
    if (e.type === 'touchstart' || e.pointerType === 'touch') {
        letzteBeruehrung = Date.now();
    }
}
document.addEventListener('pointerdown', beruehrt, { capture: true, passive: true });
document.addEventListener('touchstart', beruehrt, { capture: true, passive: true });

document.addEventListener('focusin', (e) => {
    const ziel = e.target;
    if (!(ziel instanceof HTMLElement) || !holtTastatur(ziel)) return;
    if (Date.now() - letzteBeruehrung > TASTATUR_BERUEHRUNG_MS) return;

    ankerFeld = ziel;
    ankerY = window.scrollY;
    ankerBis = Date.now() + TASTATUR_FENSTER_MS;
    ankerKorrekturen = 0;

    // Auch dann eine Entscheidung faellen, wenn ueberhaupt nichts passiert --
    // der Regelfall auf einem Geraet, das nicht scrollt.
    ankerNachfassen(TASTATUR_FENSTER_MS);
});

document.addEventListener('focusout', ankerLoesen);

// Eine bewusste Wischgeste beendet das Festhalten sofort.
window.addEventListener('wheel', ankerLoesen, { passive: true });
window.addEventListener('touchmove', ankerLoesen, { passive: true });

window.addEventListener('scroll', ankerHalten, { passive: true });

if (window.visualViewport) {
    // resize waehrend der Einblendung, scroll fuer den Fall, dass der Browser
    // statt der Seite den Sichtbereich verschiebt. Beides heisst: noch in
    // Bewegung, Phase 2 wartet.
    window.visualViewport.addEventListener('resize', () => {
        ankerHalten();
        if (ankerFeld) ankerNachfassen(TASTATUR_RUHE_MS);
    });
    window.visualViewport.addEventListener('scroll', () => {
        ankerHalten();
        if (ankerFeld) ankerNachfassen(TASTATUR_RUHE_MS);
    });
}

/**
 * Zeigt eine kurze Meldung am unteren Rand.
 *
 * Bewusst kein Ersatz fuer sichtbare Fehlerzustaende: Schlaegt ein Speichern
 * fehl, muss die betroffene Zeile unbestaetigt bleiben und einen
 * Wiederholen-Knopf zeigen. Ein Hinweis, der nach drei Sekunden verschwindet,
 * darf nie die einzige Spur eines Fehlers sein.
 */
function meldung(text, art = 'info') {
    let box = qs('#meldung');
    if (!box) {
        box = document.createElement('div');
        box.id = 'meldung';
        document.body.appendChild(box);
    }
    box.textContent = text;
    box.className = 'meldung meldung-' + art + ' sichtbar';

    clearTimeout(meldung._uhr);
    meldung._uhr = setTimeout(() => box.classList.remove('sichtbar'), 3500);
}

/**
 * Blendet alle Feldfehler unterhalb von wurzel aus.
 *
 * Erwartet die Auszeichnung <p class="feld-fehler" data-fehler-fuer="feldid">.
 */
function feldFehlerLeeren(wurzel = document) {
    qsa('[data-fehler-fuer]', wurzel).forEach((p) => {
        p.hidden = true;
        p.textContent = '';
        const feld = qs('#' + p.dataset.fehlerFuer, wurzel);
        if (feld) feld.removeAttribute('aria-invalid');
    });
}

/**
 * Verteilt einen Fehler aus apiFetch() auf die Felder: die allgemeine Meldung
 * in das Hinweisfeld, .fields auf die zugehoerigen Eingaben. Setzt den Fokus
 * auf das erste betroffene Feld.
 */
function feldFehlerZeigen(fehler, hinweis, wurzel = document) {
    if (hinweis) {
        hinweis.textContent = fehler.message;
        hinweis.hidden = false;
    }

    const felder = fehler.fields || {};
    let erstes = null;

    Object.keys(felder).forEach((name) => {
        const p = qs('[data-fehler-fuer="' + name + '"]', wurzel);
        const feld = qs('#' + name, wurzel);
        if (p) {
            p.textContent = felder[name];
            p.hidden = false;
        }
        if (feld) {
            feld.setAttribute('aria-invalid', 'true');
            if (!erstes) erstes = feld;
        }
    });

    if (erstes) erstes.focus();
}

/**
 * Wandelt eine Eingabe mit Dezimalkomma in eine Zahl. Leere Eingabe -> null.
 * Gegenstueck zu to_decimal_or_null() in lib/helpers.php.
 */
function zahlAusEingabe(wert) {
    const s = String(wert ?? '').trim().replace(',', '.');
    if (s === '') return null;
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
}

/**
 * Formatiert eine Zahl fuer die Anzeige: 62.5 -> "62,5", 60 -> "60".
 */
function zahlFuerAnzeige(wert) {
    if (wert === null || wert === undefined || wert === '') return '';
    return String(wert).replace('.', ',');
}

/**
 * Formatiert eine Dauer in Sekunden als "5:30" bzw. "1:02:45".
 * Gegenstueck zu dauer_mmss() in lib/helpers.php -- beide muessen zeichengleich
 * antworten, weil die Zeit server-gerendert und im Browser gebaut am Handy
 * direkt uebereinander steht.
 */
function dauerMMSS(sekunden) {
    if (sekunden === null || sekunden === undefined || sekunden === '') return '';
    const n = Math.trunc(Number(sekunden));
    if (!Number.isFinite(n) || n < 0) return '';

    const s = n % 60;
    const m = Math.trunc(n / 60) % 60;
    const h = Math.trunc(n / 3600);

    const mm = h > 0 ? String(m).padStart(2, '0') : String(m);
    return (h > 0 ? h + ':' : '') + mm + ':' + String(s).padStart(2, '0');
}

/**
 * Wandelt eine Zeiteingabe in Sekunden. Leer oder ungueltig -> null.
 * Gegenstueck zu dauer_aus_eingabe() in lib/helpers.php; die Sonderfaelle sind
 * dort begruendet -- eine nackte Zahl gilt als MINUTEN, Sekunden ueber 59
 * werden abgewiesen und nicht umgerechnet.
 */
function dauerAusEingabe(wert) {
    const s = String(wert ?? '').trim();
    if (s === '') return null;

    if (/^\d+$/.test(s)) return Number(s) * 60;

    const t = s.match(/^(?:(\d+):)?(\d{1,2}):(\d{1,2})$/);
    if (!t) return null;

    const h = t[1] === undefined ? 0 : Number(t[1]);
    const m = Number(t[2]);
    const sek = Number(t[3]);

    if (sek > 59 || (h > 0 && m > 59)) return null;
    return h * 3600 + m * 60 + sek;
}

/**
 * Die Uebungsauswahl (§6.4) -- geteilt zwischen Planverwaltung und Training.
 *
 * Statt eines Pulldowns mit allen aktiven Uebungen: ein Dialog, dessen Liste
 * sich nach Muskelgruppe und Trainingsgeraet filtern laesst, einzeln oder
 * kombiniert. Bei dreistelligem Uebungsbestand ist das der Unterschied
 * zwischen bedienbar und unbedienbar.
 *
 * Stand bis 1.4.1 in plans.js. Seit 1.4.2 haengt auch die Trainingsansicht
 * spontan eine Uebung an (§7.6) und braucht dieselbe Maske -- zwei Fassungen
 * davon waeren irgendwann verschieden, dieselbe Ueberlegung wie bei
 * vorschlagMarkup().
 *
 * Was die Aufrufstelle beisteuert, ist genau das, was sie unterscheidet:
 *
 * - `hinzufuegen(exerciseId, ziel, knopf)` schickt den Aufruf ab und liefert
 *   den Satz zurueck, der danach in der Liste steht.
 * - `knoepfe` (optional) ersetzt den einen "Hinzufügen"-Knopf durch eigenes
 *   Markup -- die Trainingsansicht braucht dort ZWEI, fuer dauerhaft und fuer
 *   nur heute (§7.6). Genau dasselbe Muster wie TAUSCH_KNOEPFE im
 *   Tauschfenster; welcher Knopf gedrueckt wurde, liest `hinzufuegen` an
 *   seinem `data-modus`.
 *
 * Alles andere -- Filter, Facetten, Ueberholschutz, Fehlerbehandlung -- ist
 * hier und nur hier.
 *
 * Gibt es den Dialog auf dieser Seite nicht, liefert die Funktion null. Der
 * Aufrufer prueft das; einen Knopf ohne Maske gibt es dann ohnehin nicht.
 */
function uebungWaehlenEinrichten({ hinzufuegen, knoepfe = null }) {
    const dialog = qs('#waehlen-dialog');
    if (!dialog) return null;

    const liste  = qs('#waehlen-liste');
    const fehler = qs('#waehlen-fehler');
    const gruppe = qs('#waehlen-gruppe');
    const geraet = qs('#waehlen-geraet');

    // Wohin hinzugefuegt wird -- von der Aufrufstelle beim Oeffnen gesetzt.
    let ziel = null;

    // Jeder Ladevorgang bekommt eine Nummer, und nur der jeweils JUENGSTE darf
    // die Liste zeichnen.
    //
    // Ohne das gewinnt die zuletzt eingetroffene Antwort, nicht die zuletzt
    // gestellte Frage: Zwei Abrufe koennen sich ueberholen -- Dialog fuer Plan A
    // oeffnen, schliessen, gleich darauf Plan B oeffnen; oder zwei Filter kurz
    // hintereinander umstellen. Trifft die aeltere Antwort spaeter ein,
    // ueberschreibt sie die neuere, und die Liste beschreibt dann einen Zustand,
    // den niemand mehr angefragt hat -- samt Hinweis "Schon in ..." und samt
    // "Bereits im Plan" zum falschen Plan.
    let lauf = 0;

    // Die vollstaendigen Optionslisten einmal beim Laden sichern. Die Felder
    // werden gleich beschnitten -- ohne diese Kopie waere das ein Weg ohne
    // Rueckweg, und die weggefilterten Eintraege kaemen nie wieder.
    const alleOptionen = (feld) =>
        Array.from(feld.options).map((o) => ({ value: o.value, text: o.textContent.trim() }));
    const gruppenOptionen = alleOptionen(gruppe);
    const geraeteOptionen = alleOptionen(geraet);

    qs('#waehlen-schliessen').addEventListener('click', () => dialog.close());

    // Ein geschlossener Dialog nimmt keine Antwort mehr an -- am 'close' und
    // nicht am Schliessen-Knopf, weil die Escape-Taste denselben Weg nimmt.
    // Sonst zeichnete ein Abruf, der beim Schliessen noch unterwegs war, beim
    // naechsten Oeffnen kurz die Liste des VORIGEN Plans.
    dialog.addEventListener('close', () => { lauf++; });

    /**
     * Beschraenkt ein Auswahlfeld auf die Werte, die noch zu Treffern fuehren.
     *
     * Die erste Option ("alle ...", Wert '') bleibt immer stehen -- sie ist der
     * Weg zurueck. Ist die aktuelle Wahl nicht mehr dabei, meldet die Funktion
     * das: Der Aufrufer setzt dann zurueck und laedt neu, statt eine garantiert
     * leere Liste stehen zu lassen.
     *
     * @param erlaubt Liste der zulaessigen Werte, oder null fuer "alle"
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

    /** Laedt die Trefferliste zum aktuellen Ziel und den aktuellen Filtern. */
    async function laden(zweiterVersuch = false) {
        if (!ziel) return;

        const nr = ++lauf;

        fehler.hidden = true;
        liste.innerHTML = '<p class="matt">Wird geladen …</p>';

        try {
            const daten = await apiFetch('api/plans.php', {
                body: {
                    action: 'exercise_picker',
                    plan_id: ziel.planId,
                    // Nur gesetzt, wenn die Aufrufstelle sich auf eine laufende
                    // Einheit bezieht (§7.6): Dann zaehlt "Bereits im Plan" auch
                    // die Uebungen, die nur heute dazugehoeren.
                    session_id: ziel.sessionId || null,
                    group_id: gruppe.value ? Number(gruppe.value) : null,
                    equipment: geraet.value,
                },
            });

            // Ueberholt: Inzwischen ist ein neuerer Abruf unterwegs (anderes
            // Ziel, anderer Filter) oder der Dialog wurde geschlossen. Diese
            // Antwort ist damit die Auskunft auf eine Frage von gestern.
            if (nr !== lauf) return;

            // Die Felder schraenken sich gegenseitig ein: Nach der Wahl einer
            // Muskelgruppe stehen unter Trainingsgeraet nur noch die Geraete, fuer
            // die es dort auch eine Uebung gibt -- und umgekehrt. Der Server
            // rechnet jede Facette ohne ihren eigenen Filter, deshalb bleibt der
            // Weg zurueck auf "alle" immer offen.
            const raus = [
                auswahlBeschneiden(gruppe, gruppenOptionen, daten.facetten.gruppen.map(String)),
                auswahlBeschneiden(geraet, geraeteOptionen, daten.facetten.geraete),
            ].some(Boolean);

            // Ein Wechsel kann die Wahl im anderen Feld ungueltig machen: erst
            // Kurzhantel, dann eine Muskelgruppe ohne Kurzhantelübung. Die Wahl
            // steht dann auf "alle" und die Liste dazu muss neu geholt werden.
            // Genau einmal -- danach ist beides '' und damit immer gueltig.
            if (raus && !zweiterVersuch) {
                await laden(true);
                return;
            }

            if (!daten.exercises.length) {
                liste.innerHTML =
                    '<p>Keine passende Übung. Mit weniger Filtern suchen oder unter '
                    + '<a href="admin_exercises.php">Übungen</a> eine anlegen.</p>';
                return;
            }

            // Was schon im Plan steht, bleibt sichtbar und wird nur gesperrt:
            // Herausgefiltert wuesste man nicht, ob die gesuchte Uebung fehlt oder
            // laengst dabei ist. Dieselbe Ueberlegung wie in der API.
            // Der Hinweis "Schon in ..." kommt aus vorschlagMarkup() selbst -- er
            // gehoert zur Uebung und gilt in allen drei Listen gleich.
            liste.innerHTML = daten.exercises.map((v) => vorschlagMarkup(v,
                v.im_plan
                    ? '<button type="button" disabled>Bereits im Plan</button>'
                    : (knoepfe
                        ?? '<button type="button" class="waehlen-hinzu">Hinzufügen</button>')
            )).join('');
        } catch (f) {
            // Auch die Fehlermeldung gehoert zu ihrem Abruf: Die eines
            // ueberholten Versuchs stuende sonst ueber einer Liste, die laengst
            // geladen ist.
            if (nr !== lauf) return;
            liste.innerHTML = '';
            fehler.textContent = f.message;
            fehler.hidden = false;
        }
    }

    // Die Filter laden nur die Liste neu -- der Dialog bleibt offen, sonst waere
    // ein zweiter Filterversuch ein zweiter Weg durch die ganze Maske.
    gruppe.addEventListener('change', () => laden());
    geraet.addEventListener('change', () => laden());

    liste.addEventListener('click', async (e) => {
        const knopf = e.target.closest('.waehlen-hinzu');
        if (!knopf || !ziel) return;

        // ALLE Knoepfe dieses Vorschlags sperren, nicht nur den getroffenen:
        // Wo es zwei gibt -- dauerhaft und nur heute --, waere der zweite sonst
        // waehrend des laufenden Aufrufs noch drueckbar.
        const eintrag = knopf.closest('.vorschlag');
        const geschwister = qsa('.waehlen-hinzu', eintrag);
        geschwister.forEach((k) => { k.disabled = true; });
        fehler.hidden = true;

        try {
            const satz = await hinzufuegen(Number(eintrag.dataset.id), ziel, knopf);
            // Ab jetzt beschreibt die Liste den Stand von vorhin und darf nicht
            // mehr wie eine Auskunft aussehen.
            liste.innerHTML = '<p class="matt">' + escapeHtml(satz) + '</p>';
        } catch (f) {
            fehler.textContent = f.message;
            fehler.hidden = false;
            geschwister.forEach((k) => { k.disabled = false; });
        }
    });

    return {
        /**
         * @param neuesZiel {planId, titel, sessionId?} -- sessionId nur, wenn
         *                  die Auswahl sich auf eine laufende Einheit bezieht.
         */
        oeffnen(neuesZiel) {
            ziel = neuesZiel;
            qs('#waehlen-titel').textContent = neuesZiel.titel;
            // Beide Felder auf den vollen Vorrat zurueck, bevor neu beschnitten
            // wird. Die getroffene Wahl bleibt dabei stehen -- wer nacheinander
            // mehrere Rueckenuebungen aufnimmt, will den Filter nicht jedes Mal
            // neu setzen. Ohne diesen Schritt behielte das Feld dagegen die
            // Einschraenkung der letzten Suche, und Eintraege fehlten ohne
            // erkennbaren Grund.
            auswahlBeschneiden(gruppe, gruppenOptionen, null);
            auswahlBeschneiden(geraet, geraeteOptionen, null);
            dialog.showModal();
            laden();
        },
        schliessen() { dialog.close(); },
    };
}

/**
 * Das Abzeichen zur Erfassungsart -- Gegenstueck zu erfassung_abzeichen() in
 * lib/geraete.php.
 *
 * Nur bei Ausdauer, sonst leer. Und ohne Nachschlagetabelle wie bei den
 * Geraeten: Es gibt genau einen sichtbaren Fall, eine JSON-Liste dafuer im
 * Dokument waere ein Kanal, durch den nie mehr als ein Wort geht.
 */
function erfassungAbzeichen(code) {
    if (code !== 'ausdauer') return '';
    return '<span class="abzeichen erfassung-ausdauer">Ausdauer</span>';
}

/**
 * Die URL des Vorschaubilds zu einem gespeicherten Bildpfad.
 *
 * Gegenstueck zur gleichen Rechnung in den PHP-Seiten: Der Dateiname besteht
 * aus 32 Hex-Zeichen und der Endung, das Thumbnail traegt denselben Namen mit
 * dem Zusatz "_thumb.jpg".
 */
function thumbUrl(imagePath) {
    return 'image.php?f=' + encodeURIComponent(String(imagePath).slice(0, 32) + '_thumb.jpg');
}

/**
 * Zeigt ein Uebungsbild gross, dazu Name und Beschreibung.
 *
 * Geteilt zwischen Training (§7.4), Uebungs- und Planverwaltung: Ueberall ist
 * es dasselbe Motiv aus derselben Quelle, und ueberall gilt derselbe Kniff mit
 * dem Nachladen. Dreimal gepflegt waeren die drei irgendwann verschieden.
 *
 * Die Seite muss dazu lib/view_bild_dialog.php eingebunden haben. Fehlt der
 * Dialog, passiert nichts -- das ist kein Fehler, sondern eine Seite ohne
 * Bilder.
 *
 * @param {string} titel        Ueberschrift, in aller Regel der Uebungsname
 * @param {string} thumbSrc     URL des Vorschaubilds, leer wenn es keins gibt
 * @param {string} beschreibung Beschreibungstext, darf leer sein
 */
let bildDialogVerdrahtet = false;
let bildDialogLauf = 0;

function bildGrossZeigen(titel, thumbSrc, beschreibung) {
    const dialog = qs('#info-dialog');
    if (!dialog) return;

    if (!bildDialogVerdrahtet) {
        bildDialogVerdrahtet = true;
        qs('#info-schliessen').addEventListener('click', () => dialog.close());

        // Ein Tipp auf das grosse Bild schliesst wieder — man hat es ohnehin
        // gerade unter dem Finger, und der Knopf steht am unteren Ende eines
        // womoeglich gescrollten Dialogs. Der Knopf bleibt: Er ist der Weg
        // mit der Tastatur.
        qs('#info-bild').addEventListener('click', () => dialog.close());
    }

    qs('#info-titel').textContent = titel;
    qs('#info-text').textContent = String(beschreibung ?? '').trim() !== ''
        ? beschreibung
        : 'Keine Beschreibung hinterlegt.';

    const gross = qs('#info-bild');
    if (thumbSrc) {
        // Erst das Thumbnail: Es liegt bereits geladen in der Zeile und
        // erscheint deshalb verzoegerungsfrei.
        //
        // Ein <img> behaelt naemlich sein altes Bild, bis das neue
        // VOLLSTAENDIG geladen ist — ein blosses Setzen von src blendet nichts
        // aus. Ueber Mobilfunk stand deshalb ein bis zwei Sekunden lang das
        // zuletzt angesehene Motiv im Dialog.
        gross.src = thumbSrc;
        gross.hidden = false;

        // Das grosse Bild im Hintergrund nachladen und erst austauschen, wenn
        // es da ist. Der Zaehler verhindert, dass ein spaet eintreffendes Bild
        // eine inzwischen andere Uebung ueberschreibt — beim schnellen
        // Durchtippen sonst genau derselbe Fehler.
        const lauf = ++bildDialogLauf;
        const voll = new Image();
        voll.onload = () => {
            if (lauf === bildDialogLauf) gross.src = voll.src;
        };
        voll.src = thumbSrc.replace('_thumb.jpg', '.jpg');
    } else {
        gross.hidden = true;
        gross.removeAttribute('src');
        bildDialogLauf++;
    }

    dialog.showModal();
}

/**
 * Eine Aktion an api/splits.php schicken und die Seite neu laden (§6.4).
 *
 * Geteilt zwischen splits.php und admin_splits.php: Beide Seiten bedienen
 * denselben Endpunkt, beide laden nach jeder Änderung neu, und beide zeigen
 * Fehler an der Zeile, zu der sie gehören. Zweimal geschrieben wäre es
 * zweimal zu pflegen.
 *
 * Neu laden statt die Liste im Browser nachzuführen ist dieselbe Entscheidung
 * wie in plans.js: Was sich hier ändert, wirkt an mehreren Stellen zugleich
 * (Rotationsvorschau, aktiver Split, Plan-Namen, der Knopf „Auf Vorlage
 * zurücksetzen"), und eine zweite Fassung dieser Zusammenhänge im JS wäre die
 * Dublette, die später abweicht.
 *
 * @param {Element|null} zeile    Träger mit .zeilen-fehler; null = keine Zeile
 * @param {object}       nutzlast Body für apiFetch
 * @param {Element|null} knopf    wird für die Dauer des Aufrufs gesperrt
 */
async function splitAktion(zeile, nutzlast, knopf) {
    const fehlerFeld = zeile ? qs('.zeilen-fehler', zeile) : null;
    if (fehlerFeld) {
        fehlerFeld.hidden = true;
        fehlerFeld.textContent = '';
    }
    if (knopf) knopf.disabled = true;

    try {
        await apiFetch('api/splits.php', { body: nutzlast });
        window.location.reload();
    } catch (fehler) {
        if (knopf) knopf.disabled = false;
        // Die Sperre bei laufendem Training und der 403 auf eine Vorlage
        // erklaeren sich in einem ganzen Satz -- deshalb an der Zeile und
        // nicht als fluechtige Meldung.
        if (fehlerFeld) {
            fehlerFeld.textContent = fehler.message;
            fehlerFeld.hidden = false;
        } else {
            meldung(fehler.message, 'fehler');
        }
    }
}

/**
 * Zeigt einen Split als reinen Text im Dialog (§6.4).
 *
 * Der Text kommt aus einem <pre class="split-text-inhalt"> in der Seite und
 * nicht aus einem Netzaufruf: Das Schreiben in die Zwischenablage muss in
 * derselben Benutzeraktion stattfinden wie der Klick, und ein await auf einen
 * fetch bricht diesen Zusammenhang in strengeren Browsern (iOS Safari).
 * Nebenbei arbeitet der Knopf damit auch ohne Netz.
 *
 * Übergeben wird die QUELLE und nicht die Karte: Auf splits.php hängt der Text
 * seit 1.2.23 auch am Kasten „Vorlage übernehmen", und der ist keine Karte.
 *
 * Die Seite muss dazu lib/view_split_text_dialog.php eingebunden haben. Fehlt
 * der Dialog, passiert nichts -- dasselbe Verhalten wie bei bildGrossZeigen().
 *
 * @param {Element|null} quelle Das <pre> mit dem Text
 * @param {string}       name   Splitname für die Überschrift, darf leer sein
 */
let textDialogVerdrahtet = false;

function splitTextZeigen(quelle, name) {
    const dialog = qs('#text-dialog');
    if (!dialog || !quelle) return;

    if (!textDialogVerdrahtet) {
        textDialogVerdrahtet = true;
        const feld    = qs('#text-inhalt');
        const hinweis = qs('#text-hinweis');

        qs('#text-schliessen').addEventListener('click', () => dialog.close());

        qs('#text-kopieren').addEventListener('click', async () => {
            // Zwei Wege, und der zweite wird wirklich gebraucht:
            // navigator.clipboard gibt es nur im sicheren Kontext, und selbst
            // dort kann die Berechtigung fehlen. Dann bleibt das Markieren --
            // der Text steht ja sichtbar da, es fehlt nur noch Strg+C.
            try {
                await navigator.clipboard.writeText(feld.value);
                hinweis.textContent = 'Kopiert.';
            } catch (fehler) {
                feld.focus();
                feld.select();
                hinweis.textContent = 'Der Browser lässt das Kopieren nicht zu — '
                    + 'der Text ist markiert, bitte mit Strg+C bzw. ⌘+C kopieren.';
            }
        });
    }

    qs('#text-titel').textContent = name ? 'Split „' + name + '“ als Text' : 'Split als Text';
    qs('#text-inhalt').value = quelle.textContent;
    qs('#text-hinweis').textContent = '';
    dialog.showModal();
}

/**
 * Zwischen den Splitkarten einer Liste umschalten (§6.4, seit 1.3.2).
 *
 * Gerendert sind alle, sichtbar ist eine (lib/view_split_karte.php). Der
 * Wechsel tauscht deshalb nur [hidden] -- kein Netzaufruf, kein Seitenaufbau,
 * und er funktioniert im Studio auch ohne Verbindung.
 *
 * Die gewählte Karte wandert als ?split= in die Adresse, und zwar über
 * replaceState: Jede Aktion auf diesen Seiten lädt anschließend neu
 * (splitAktion), und ohne den Parameter stünde man danach wieder bei der
 * aktiven statt bei der, die man gerade bearbeitet hat. Kein pushState — das
 * Umschalten ist keine Station, zu der die Zurück-Taste führen soll.
 *
 * Geteilt zwischen splits.php und admin_splits.php: dieselbe Liste, dieselbe
 * Frage, und beide Seiten binden denselben Kartenbaustein ein.
 */
function splitWechselVerdrahten() {
    const liste = qs('.split-liste');
    if (!liste) return;

    liste.addEventListener('change', (e) => {
        const feld = e.target.closest('.split-wechsel');
        if (!feld) return;

        const ziel = Number(feld.value);

        // Über die ganze Liste und nicht nur über die beiden betroffenen
        // Karten: Steht aus irgendeinem Grund mehr als eine offen, räumt das
        // auf, statt den Zustand fortzuschreiben.
        for (const karte of qsa('.split[data-id]', liste)) {
            karte.hidden = Number(karte.dataset.id) !== ziel;
        }

        try {
            const adresse = new URL(window.location.href);
            adresse.searchParams.set('split', String(ziel));
            window.history.replaceState(null, '', adresse);
        } catch (fehler) {
            // Ohne Adressleiste ist der Wechsel trotzdem passiert -- er
            // überlebt dann nur das nächste Neuladen nicht. Kein Grund, dem
            // Benutzer etwas zu melden.
        }
    });
}

/**
 * Fragt nach einem neuen Namen für einen Split oder eine Vorlage (§6.4).
 *
 * Seit 1.3.2 steht der Name nicht mehr als Eingabefeld im Kartenkopf — dort
 * sitzt das Auswahlfeld —, also muss "Umbenennen" selbst nach ihm fragen.
 *
 * Der Aufruf geht NICHT über splitAktion(): Ein zu langer oder leerer Name
 * antwortet mit 422 und gehört im offenen Dialog gemeldet, direkt unter dem
 * Feld, in dem er steht. splitAktion() schriebe ihn an die Karte dahinter —
 * unter den Dialog, wo ihn niemand sieht.
 *
 * Die Seite muss lib/view_split_name_dialog.php eingebunden haben. Fehlt der
 * Dialog, passiert nichts — dasselbe Verhalten wie bei bildGrossZeigen().
 *
 * @param {Element} zeile   Die Karte (.split[data-id])
 * @param {string}  titel   Überschrift, z. B. "Split umbenennen"
 */
let nameDialogVerdrahtet = false;
let nameZiel = null;

function splitUmbenennenFragen(zeile, titel) {
    const dialog = qs('#name-dialog');
    if (!dialog) return;

    const feld   = qs('#name-feld');
    const fehler = qs('#name-fehler');

    if (!nameDialogVerdrahtet) {
        nameDialogVerdrahtet = true;

        qs('#name-abbrechen').addEventListener('click', () => dialog.close());

        const speichern = async () => {
            if (nameZiel === null) return;

            fehler.hidden = true;
            fehler.textContent = '';
            const knopf = qs('#name-speichern');
            knopf.disabled = true;

            try {
                await apiFetch('api/splits.php', {
                    body: { action: 'rename', id: nameZiel, name: feld.value },
                });
                window.location.reload();
            } catch (f) {
                knopf.disabled = false;
                fehler.textContent = f.fields?.name || f.message;
                fehler.hidden = false;
                feld.focus();
            }
        };

        qs('#name-speichern').addEventListener('click', speichern);

        // Enter im Feld speichert. Es ist kein <form> — ein Formular im Dialog
        // brächte method="dialog" und eigenes Absendeverhalten mit, und hier
        // geht ohnehin nichts über den Browser raus.
        feld.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                speichern();
            }
        });
    }

    nameZiel = Number(zeile.dataset.id);

    qs('#name-titel').textContent = titel;
    // data-name und nicht der Text des Auswahlfelds: Der trägt bei einer
    // Karte ohne Geschwister gar keine <option>, und h() hat den Namen dort
    // ohnehin schon einmal durchlaufen.
    feld.value = zeile.dataset.name || '';
    fehler.hidden = true;
    fehler.textContent = '';
    qs('#name-speichern').disabled = false;

    dialog.showModal();
    feld.focus();
    feld.select();
}

/**
 * Das Abzeichen eines Trainingsgeräts — das Gegenstück zu geraet_abzeichen()
 * aus lib/geraete.php.
 *
 * Beschriftungen und Symbole kommen aus derselben Quelle wie serverseitig:
 * lib/view_geraet_symbole.php legt beides ins Dokument, den Symbolvorrat als
 * <symbol> und die Beschriftungen als JSON. Eine zweite Liste hier im JS wäre
 * eine Kopie, die beim achten Gerätetyp still veraltet.
 */
let geraeteTabelle = null;

/** Die Beschriftung zu einem Gerätecode, oder '' für leer und unbekannt. */
function geraetName(code) {
    if (geraeteTabelle === null) {
        const quelle = qs('#geraete-daten');
        // Ohne den Vorrat im Dokument bleibt die Tabelle leer statt zu werfen —
        // dieselbe Nachsicht wie bei bildGrossZeigen().
        try {
            geraeteTabelle = quelle ? JSON.parse(quelle.textContent) : {};
        } catch {
            geraeteTabelle = {};
        }
    }
    return (code && geraeteTabelle[code]) || '';
}

function geraetAbzeichen(code) {
    const label = geraetName(code);
    if (!label) {
        return '<span class="abzeichen geraet-fehlt">Gerät fehlt</span>';
    }

    return '<span class="abzeichen geraet">'
        + '<svg class="geraet-symbol" aria-hidden="true" focusable="false">'
        + '<use href="#geraet-' + escapeHtml(code) + '"></use></svg>'
        + escapeHtml(label) + '</span>';
}

/**
 * Der Übungsname, zweisprachig — das Gegenstück zu uebung_name() in
 * lib/helpers.php (§4).
 *
 * Nötig, weil das Tauschfenster und die Übungsauswahl im Browser entstehen,
 * die Übungszeile daneben aber server-gerendert ist. Beide Hälften gehören
 * zusammen geändert: Zwei Schreibweisen desselben Namens liest man am Handy
 * als Unterschied in der Sache — dasselbe Argument wie bei
 * saetzeZusammenfassung().
 *
 * Der Umbruch zwischen beiden Namen kommt aus der Klasse .name-en und nicht
 * aus einem eigenen Element.
 */
function uebungName(de, en) {
    const markup = '<strong>' + escapeHtml(de) + '</strong>';

    return (en && String(en).trim())
        ? markup + '<span class="matt name-en">' + escapeHtml(en) + '</span>'
        : markup;
}

/**
 * Rüstet ein <select> als Gerätefilter über einer Vorschlagsliste aus.
 *
 * Geteilt zwischen Training und Planverwaltung — beide Tauschdialoge sollen sich
 * gleich verhalten, und zweimal gepflegt wären sie irgendwann verschieden. Die
 * erste Option („alle Trainingsgeräte") steht im Markup und bleibt stehen; alles
 * dahinter kommt von hier.
 *
 * Angeboten wird nur, was in der Liste auch **vorkommt**. Ein Eintrag, der
 * zuverlässig eine leere Liste erzeugt, ist schlechter als kein Eintrag — und
 * bei weniger als zwei Geräten ist die Auswahl bedeutungslos, dann sagt der
 * Rückgabewert dem Aufrufer, dass er sie ausblenden soll.
 *
 * @param {HTMLSelectElement} feld
 * @param {object[]} vorschlaege
 * @returns {boolean} ob der Filter überhaupt eine Wahl lässt
 */
function geraetFilterFuellen(feld, vorschlaege) {
    const vorhanden = [];
    vorschlaege.forEach((v) => {
        if (v.equipment && !vorhanden.includes(v.equipment)) {
            vorhanden.push(v.equipment);
        }
    });

    feld.length = 1;
    feld.value = '';
    vorhanden.forEach((code) => feld.add(new Option(geraetName(code), code)));

    return vorhanden.length >= 2;
}

/**
 * Die Vorschläge zum gewählten Gerät — leere Wahl heißt alle.
 *
 * Rein im Browser und ohne zweiten Abruf: Die Liste liegt nach dem ersten Abruf
 * vollständig vor, und man steht damit im Studio vor einem belegten Gerät, wo
 * das Netz schwach ist. Der Server bleibt die einzige Quelle dafür, WAS
 * überhaupt als Ersatz taugt (§7.5, dieselbe Hauptgruppe); gefiltert wird hier
 * nur innerhalb dieser Antwort.
 */
function geraetGefiltert(vorschlaege, code) {
    return code ? vorschlaege.filter((v) => v.equipment === code) : vorschlaege;
}

/**
 * Der zurueckgenommene Hinweis in der Knopfzeile: Wo im Split steht die Uebung
 * schon? (§6.4)
 *
 * Kein Verbot, sondern eine Auskunft -- dieselbe Uebung darf bewusst in
 * mehreren Plaenen stehen. Wer aber "Ganzkoerper B" fuellt und nicht zweimal
 * dasselbe trainieren will, sieht hier, was schon in "Ganzkoerper A" steht.
 *
 * Steht in vorschlagMarkup() und nicht an den Aufrufstellen: Die Auskunft
 * gehoert zur UEBUNG und nicht zu den Knoepfen darunter, und sie gilt in allen
 * drei Listen gleichermassen -- Uebungsauswahl, Tauschfenster der
 * Planverwaltung, Tauschfenster im Training. An den Aufrufstellen gebaut waere
 * sie beim naechsten Dialog wieder vergessen (Fallstrick 27).
 *
 * Fehlt das Feld -- etwa in einer Antwort, die es nicht mitliefert --, entfaellt
 * der Hinweis stillschweigend. Das ist richtig so: Ein "steht nirgends sonst"
 * waere eine Aussage, die niemand geprueft hat.
 */
function imSplitHinweis(v) {
    const plaene = (v && v.andere_plaene) || [];
    if (!plaene.length) {
        return '';
    }

    return '<span class="im-split-hinweis">Schon in '
        + plaene.map(escapeHtml).join(', ') + '</span>';
}

/**
 * Ein Tauschvorschlag als Markup.
 *
 * Geteilt zwischen Trainings- und Planseite (§7.5): Beide zeigen dieselbe
 * Liste aus derselben Quelle, sie unterscheiden sich nur in den Knoepfen
 * darunter -- im Training "nur diese Einheit" oder "dauerhaft", in der
 * Planverwaltung nur dauerhaft. Zweimal gepflegt waeren sie irgendwann
 * verschieden.
 *
 * Dieselbe Form nutzt die Uebungsauswahl beim Hinzufuegen zu einem Plan (§6.4):
 * Ein Treffer dort ist inhaltlich dasselbe wie ein Vorschlag hier, nur mit
 * einem anderen Knopf. Deshalb liefert api/plans.php -> exercise_picker
 * ausdruecklich dieselben Felder.
 *
 * @param {object} v        Vorschlag vom Server
 * @param {string} knoepfe  Fertiges Markup der Auswahlknoepfe
 */
function vorschlagMarkup(v, knoepfe) {
    const gruppen = (v.muskelgruppen || []).map((g) =>
        '<span class="' + (Number(g.is_primary) === 1 ? 'gruppe-primaer' : 'gruppe-sekundaer')
        + '">' + escapeHtml(g.name_de) + '</span>').join(' ');

    // Gleiche Anordnung wie in der Uebungszeile: erst die Muskelgruppen
    // (primaer vorn), darunter das Trainingsgeraet. Das Geraet steht auch hier
    // immer -- beim Tausch ist es sogar die entscheidende Angabe, weil man
    // meist ausweicht, WEIL ein Geraet besetzt ist.
    //
    // Die AUSFUEHRUNG (exercises.focus) steht hier seit 1.2.5 NICHT mehr, und
    // das ist eine Entscheidung des Benutzers: In der Uebungszeile beschreibt
    // sie eine Uebung, die man ohnehin macht; im Tauschfenster stehen fuenf
    // Karten untereinander, die man beim Ausweichen im Studio in Sekunden
    // ueberfliegt. Dort zaehlen Name, Muskelgruppe und Geraet -- alles
    // weitere macht die Liste laenger, ohne die Wahl zu erleichtern.
    // Die Server-Antwort traegt das Feld deshalb gar nicht mehr mit.
    const schwerpunkt = '<p class="schwerpunkt-zeile">'
        + geraetAbzeichen(v.equipment)
        + erfassungAbzeichen(v.erfassung)
        + '</p>';

    // Mit Bild: An der Hantelbank erkennt man die Uebung schneller am Motiv als
    // am Namen -- und genau dort wird getauscht.
    // Der Zuschnitt kommt aus exercises.image_crop und wirkt allein ueber
    // object-position (siehe bild_zuschnitt_klasse() in lib/geraete.php). Die
    // Werte muessen hier dieselben sein wie dort -- zwei Halbwahrheiten waeren
    // schlimmer als keine: Im Tauschdialog saehe das Motiv dann anders
    // ausgerichtet aus als in der Liste, aus der man kommt.
    const zuschnitt = v.image_crop === 'links'  ? ' bild-links'
                    : v.image_crop === 'rechts' ? ' bild-rechts'
                    : '';

    // Mit Bild: An der Hantelbank erkennt man die Uebung schneller am Motiv als
    // am Namen -- und genau dort wird getauscht.
    const bild = v.image_path
        ? '<img class="vorschlag-bild' + zuschnitt + '" src="' + escapeHtml(thumbUrl(v.image_path))
          + '" alt="" loading="lazy" width="72" height="72">'
        : '<span class="vorschlag-bild vorschlag-bild-leer" aria-hidden="true">–</span>';

    return '<div class="vorschlag" data-id="' + Number(v.id) + '">'
        + '<div class="vorschlag-kopf">'
        + bild
        + '<div class="vorschlag-text">'
        + uebungName(v.name_de, v.name_en)
        + (gruppen ? '<p class="gruppen-anzeige">' + gruppen + '</p>' : '')
        + schwerpunkt
        + '</div></div>'
        + '<p class="vorschlag-knoepfe">' + imSplitHinweis(v) + knoepfe + '</p>'
        + '</div>';
}

/**
 * Erklaerender Text, wenn es keinen Ersatz gibt (§7.5).
 *
 * Kein leerer Kasten, und zwar mit der ZUTREFFENDEN Begruendung: "gibt es
 * nicht" und "steht schon im Plan" sind fuer den Benutzer zwei verschiedene
 * Sachverhalte.
 */
function keinVorschlagText(imPlan) {
    return imPlan > 0
        ? '<p>Alle Übungen derselben primären Hauptgruppe stehen bereits in diesem '
          + 'Plan (' + Number(imPlan) + ') — sie sind heute ohnehin dran. Für echte '
          + 'Alternativen unter Übungen eine weitere anlegen.</p>'
        : '<p>Es gibt keine andere Übung mit derselben primären Hauptgruppe. '
          + 'Unter Übungen lässt sich eine anlegen.</p>';
}

// Service Worker registrieren. scope '/' statt '/assets/', damit die
// installierte App die ganze Seite abdeckt -- dazu schickt Apache fuer diese
// Datei den Header Service-Worker-Allowed: / (siehe apache-app.conf).
// Die Version reist als Parameter mit, und sw.js liest sie aus seiner eigenen
// Adresse (self.location). Zwei Dinge haengen daran:
//
//  1. Der Cache-Name traegt die Version, ohne dass jemand eine Nummer von Hand
//     hochzaehlt -- das war bis 1.1.7 so und konnte vergessen werden.
//  2. Eine geaenderte Registrierungs-Adresse gilt dem Browser als NEUER Service
//     Worker. Er wird damit bei jeder Version zuverlaessig neu installiert,
//     statt bis zu 24 Stunden auf die naechste Pruefung der unveraenderten
//     Datei zu warten.
//
// Die Nummer steht im <script>-Tag, das diese Datei geladen hat -- so gibt es
// genau eine Quelle dafuer und keine zweite, die abweichen kann.
//
// AUF OBERSTER EBENE und nicht im load-Handler: document.currentScript ist nur
// waehrend der synchronen Ausfuehrung des Skripts gesetzt und im Handler bereits
// null. Der querySelector daneben faengt den Fall, dass diese Datei einmal
// anders eingebunden wird.
const eigenesSkript = document.currentScript
    || document.querySelector('script[src*="assets/app.js"]');
const appVersion = eigenesSkript
    ? new URL(eigenesSkript.src, location.href).searchParams.get('v')
    : null;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const adresse = 'assets/sw.js'
            + (appVersion ? '?v=' + encodeURIComponent(appVersion) : '');

        navigator.serviceWorker.register(adresse, { scope: './' }).catch(() => {
            // Ohne Service Worker laeuft die App normal weiter; sie ist ohnehin
            // online-only. Kein Grund, den Benutzer damit zu behelligen.
        });
    });
}
