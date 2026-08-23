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
            verbindung.erreichbar(false);
            if (!letzterVersuch) {
                await schlafen(API_PAUSEN_MS[versuch]);
                continue;
            }
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

/**
 * Die Leiste am oberen Rand, die den Verbindungszustand anzeigt.
 *
 * `navigator.onLine` allein taugt dafuer nicht: Es meldet lediglich, ob das
 * Geraet ueberhaupt eine Netzwerkschnittstelle hat. Im Studio-WLAN ohne
 * Internet oder bei einem Balken Mobilfunk steht es auf true, waehrend jeder
 * Aufruf ins Leere laeuft. Die belastbare Aussage liefert deshalb apiFetch:
 * Was tatsaechlich gescheitert ist, zaehlt mehr als das, was der Browser
 * vermutet.
 */
const verbindung = {
    _erreichbar: true,
    _wartend: 0,
    // Haengt die Leiste im Leisten-Stapel? Nur dann darf sie schweben -- im
    // Rueckfall (siehe _element()) fehlt der Bezugsrahmen, und `absolute`
    // bezoege sich auf das Dokument statt auf den Stapel.
    _imStapel: false,

    /** Von apiFetch gesetzt: Hat der letzte Aufruf den Server erreicht? */
    erreichbar(ja) {
        if (this._erreichbar === ja) return;
        this._erreichbar = ja;
        this._zeichnen();
    },

    /** Von der Seite gesetzt: Wie viele Eingaben warten noch auf das Netz? */
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
            // nach UNTEN.
            //
            // In 1.1.14 stand sie oben, mit der Begruendung "ist das Netz weg,
            // ist das die wichtigste Information auf dem Bildschirm". Das war
            // falsch herum, und es ist im Studio sofort aufgefallen: Diese
            // Leiste erscheint bei JEDEM Abhaken fuer den Bruchteil einer
            // Sekunde. Stand sie oben, schob sie die Trainingsleiste jedes Mal
            // nach unten und gleich wieder hinauf -- ausgerechnet die Zeile,
            // die man staendig abliest, zappelte bei jeder Eingabe.
            //
            // Das ist dieselbe Regel wie bei .zeile-wartet (Fallstrick 19):
            // Was von selbst kommt und geht, darf nichts verschieben, was
            // stehen bleiben soll. Unten angehaengt bleibt die Trainingsleiste
            // ruhig; sichtbar und sticky ist die Verbindungsleiste weiterhin,
            // sie sitzt nur eine Zeile tiefer.
            //
            // Der Rueckfall auf body ist fuer den Fall, dass eine Seite den
            // Stapel nicht rendert (etwa ohne Kopf-Partial). Dann klebt die
            // Leiste wie bisher selbst; die Regel dafuer steht im Stylesheet.
            const stapel = qs('#leisten');
            if (stapel) {
                stapel.appendChild(el);
                this._imStapel = true;
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
            text = 'Keine Verbindung — ' + n + (n === 1 ? ' Eingabe wartet' : ' Eingaben warten')
                 + ' auf das Netz. Nichts geht verloren.';
        } else if (weg) {
            text = 'Keine Verbindung zum Server.';
        } else if (n > 0) {
            text = n + (n === 1 ? ' Eingabe wird' : ' Eingaben werden') + ' gespeichert …';
        }

        const el = this._element();
        el.textContent = text;
        el.classList.toggle('verbindung-weg', weg);

        // Der fluechtige Fall schwebt, der dauerhafte nicht.
        //
        // "... wird gespeichert" erscheint bei JEDEM Abhaken fuer einen
        // Sekundenbruchteil. Belegte sie dabei Platz im Stapel, schoebe sie die
        // ganze Seite nach unten und gleich wieder hinauf -- genau das war am
        // 2026-08-23 vom iPhone gemeldet. Auf einem Pixel fiel es nie auf: Dort
        // gleicht Scroll Anchoring die Layoutaenderung durch Mitziehen der
        // Scrollposition aus, WebKit kennt das nicht (siehe Stylesheet).
        // Schwebend aendert sie die Hoehe des Stapels
        // nicht und verschiebt deshalb nichts; sie verdeckt fuer diesen Moment
        // die oberste Zeile darunter, und das ist der guenstigere Tausch.
        //
        // "Keine Verbindung zum Server" bleibt dagegen stehen, bis das Netz
        // zurueck ist. Schwebend verdeckte sie dauerhaft eine Zeile, die
        // niemand mehr zu Gesicht bekaeme -- also laeuft sie im Fluss mit und
        // schiebt einmal. Ein einmaliges Verschieben bei einem echten
        // Zustandswechsel ist kein Zappeln.
        el.classList.toggle('leiste-schwebt', this._imStapel && !weg);

        el.hidden = text === '';
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
 * Gemessen wird die UNTERSTE KANTE, nicht offsetHeight: Seit 1.2.10 schwebt
 * die Verbindungsleiste im fluechtigen Zustand (position: absolute, damit sie
 * beim Auftauchen nichts verschiebt) und zaehlt damit nicht mehr zur Hoehe des
 * Stapels. Sie verdeckt aber weiterhin, was darunter liegt. Ueber die Kanten
 * aller Kinder gerechnet stimmt der Wert in beiden Faellen von selbst.
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
        + '<p class="gruppen-anzeige">' + gruppen + '</p>'
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
