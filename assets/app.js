'use strict';

/**
 * Geteilte Bausteine fuer alle Seiten. Seiten-Skripte (index.js,
 * admin_plans.js, ...) setzen darauf auf und rufen NIE fetch() direkt.
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

/** Wartezeiten zwischen den automatischen Wiederholungen. */
const API_PAUSEN_MS = [2000, 5000];

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

        if (!antwort.ok || !nutzlast || nutzlast.ok !== true) {
            const fehler = new Error((nutzlast && nutzlast.error) || 'Unerwarteter Serverfehler.');
            fehler.status = antwort.status;
            fehler.fields = (nutzlast && nutzlast.fields) || {};
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
            document.body.insertBefore(el, document.body.firstChild);
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
    // (primaer vorn), darunter Trainingsgeraet und Ausfuehrung. Das Geraet steht
    // auch hier immer -- beim Tausch ist es sogar die entscheidende Angabe, weil
    // man meist ausweicht, WEIL ein Geraet besetzt ist.
    const schwerpunkt = '<p class="schwerpunkt-zeile">'
        + geraetAbzeichen(v.equipment)
        + (v.focus ? '<span class="schwerpunkt">' + escapeHtml(v.focus) + '</span>' : '')
        + '</p>';

    // Mit Bild: An der Hantelbank erkennt man die Uebung schneller am Motiv als
    // am Namen -- und genau dort wird getauscht.
    const bild = v.image_path
        ? '<img class="vorschlag-bild" src="' + escapeHtml(thumbUrl(v.image_path))
          + '" alt="" loading="lazy" width="72" height="72">'
        : '<span class="vorschlag-bild vorschlag-bild-leer" aria-hidden="true">–</span>';

    return '<div class="vorschlag" data-id="' + Number(v.id) + '">'
        + '<div class="vorschlag-kopf">'
        + bild
        + '<div class="vorschlag-text">'
        + '<strong>' + escapeHtml(v.name_de) + '</strong>'
        + '<p class="gruppen-anzeige">' + gruppen + '</p>'
        + schwerpunkt
        + '</div></div>'
        + '<p class="vorschlag-knoepfe">' + knoepfe + '</p>'
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
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('assets/sw.js', { scope: './' }).catch(() => {
            // Ohne Service Worker laeuft die App normal weiter; sie ist ohnehin
            // online-only. Kein Grund, den Benutzer damit zu behelligen.
        });
    });
}
