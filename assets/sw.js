'use strict';

/**
 * Service Worker.
 *
 * ZWINGENDE REGEL (LASTENHEFT.md §2): Gecacht werden ausschliesslich statische
 * Assets -- CSS, JS, Manifest, Icons -- mit Strategie stale-while-revalidate.
 * HTML-Seiten und API-Antworten NIEMALS.
 *
 * "Statische Assets" heisst seit 1.2.11 ausdruecklich AUCH die Seiten-Skripte im
 * Wurzelverzeichnis (index.js, plans.js, ...) und nicht mehr nur /assets/.
 * Warum, steht unten bei istSeitenSkript().
 *
 * Der Grund ist nicht Sparsamkeit, sondern Korrektheit: Eine gecachte
 * HTML-Seite liefert nach dem Abmelden wieder den angemeldeten Zustand aus,
 * und das darin eingebettete CSRF-Token ist dann veraltet -- jeder folgende
 * POST scheitert mit 403. Beides sieht nach einem Serverfehler aus und ist in
 * Wahrheit dieser Cache.
 *
 * Der Service Worker cacht deshalb NICHTS, was die App bedienbar machen
 * wuerde -- er sorgt dafuer, dass sich die Seite am Handy als App
 * installieren laesst, und dass CSS und JS auch bei schlechtem Empfang sofort
 * da sind.
 *
 * Die Robustheit gegen schwaches Netz sitzt bewusst NICHT hier, sondern in
 * assets/app.js: Zeitlimit, Wiederversuche und die Warteschlange fuer das
 * Abhaken. Der Unterschied ist wesentlich -- gecachtes HTML zeigt einen
 * ALTEN Zustand, die Warteschlange haelt einen NEUEN fest.
 */

// Die Version kommt aus der eigenen Adresse: app.js registriert diese Datei als
// "assets/sw.js?v=1.1.8". Damit gibt es KEINE von Hand gepflegte Cache-Nummer
// mehr -- sie war bis 1.1.7 eine Fehlerquelle, weil man sie vergessen konnte
// und ihr Hochzaehlen ausserdem nicht genuegte (siehe unten bei 'install').
//
// Faellt der Parameter weg, bleibt 'dev': lokal ohne Versionsdatei ist das
// richtig, und im Container steht er immer.
const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE = 'trainingsplan-assets-' + VERSION;

// Die Adressen muessen EXAKT die sein, die der Header anfragt -- ein Precache
// unter einer anderen Adresse ist toter Ballast, und der erste Aufruf ginge
// trotzdem ans Netz.
//
// Deshalb zwei Gruppen: style.css und app.js aendern sich mit jeder Version und
// tragen im <link>/<script> ein ?v=. Manifest und Icons aendern sich praktisch
// nie und stehen im Header ohne Parameter -- fuer sie genuegt das
// stale-while-revalidate unten. Versionierung dort, wo sie gebraucht wird,
// nicht ueberall.
const ASSETS = [
    'style.css?v=' + VERSION,
    'app.js?v=' + VERSION,
    'manifest.json',
    'icon-192.png',
    'icon-512.png',
];

/**
 * Das Wurzelverzeichnis der App -- diese Datei liegt eine Ebene darunter.
 *
 * Nicht fest "/" schreiben: Die Registrierung laeuft ueber scope './' plus den
 * Header Service-Worker-Allowed, und beides bleibt richtig, wenn die App je
 * unter einem Unterpfad haengt.
 */
const WURZEL = new URL('../', self.location.href).pathname;

/**
 * Ein Seiten-Skript aus dem Wurzelverzeichnis (index.js, plans.js, ...)?
 *
 * Bis 1.2.10 fasste der Service Worker nur /assets/ an. Das war keine
 * Entscheidung, sondern eine Folge der Ablage: index.js ist mit 58 KB genauso
 * gross wie app.js, traegt dieselbe ?v=-Nummer und aendert sich genauso selten
 * -- lag aber im Wurzelverzeichnis und ging deshalb bei JEDEM Seitenaufruf ans
 * Netz. Zusammen mit "Cache-Control: no-cache" war das eine volle Netzrunde,
 * bevor die Seite bedienbar wurde. Am 2026-08-23 als traeger Trainingsstart
 * gemeldet und nachgemessen: drei Runden hintereinander, das hier war die
 * dritte.
 *
 * Zwei Dinge machen das ungefaehrlich, und beide muessen so bleiben:
 *
 *  - DIE ADRESSE TRAEGT DIE VERSION (?v=, aus lib/view_footer.php). Der
 *    Cache-Schluessel ist die ganze Adresse samt Parameter -- nach einem
 *    Rollout ist es eine ANDERE Adresse, die keinen alten Eintrag treffen
 *    kann. Genau der Mechanismus aus Fallstrick 12.
 *  - DER CACHE-NAME TRAEGT DIE VERSION. activate() loescht jeden fremden
 *    Cache; die Skripte der Vorversion verschwinden also mit, es waechst
 *    nichts unbegrenzt an.
 *
 * Bewusst NICHT vorab geladen (ASSETS oben): Der Service Worker holte sonst bei
 * jeder Installation alle sieben Seiten-Skripte, auch die fuer Seiten, die
 * niemand oeffnet -- unnoetiger Verkehr auf genau der Verbindung, die hier
 * entlastet werden soll. So geht der erste Aufruf nach einem Rollout ans Netz,
 * jeder weitere kommt aus dem Cache.
 *
 * NUR direkt im Wurzelverzeichnis: kein Unterordner, keine Endung ausser .js.
 * lib/ und data/ sind serverseitig ohnehin gesperrt, aber die Regel soll aus
 * sich heraus eng sein und nicht aus Versehen weit.
 */
function istSeitenSkript(pfad) {
    if (!pfad.startsWith(WURZEL) || !pfad.endsWith('.js')) {
        return false;
    }

    return !pfad.slice(WURZEL.length).includes('/');
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // cache: 'reload' ist hier der eigentliche Kern, nicht Beiwerk.
            //
            // cache.addAll() holt die Dateien mit dem NORMALEN fetch -- also
            // durch den HTTP-Cache des Browsers. Der Server sendet fuer Assets
            // kein Cache-Control, und ohne das darf der Browser heuristisch
            // cachen (ueblich: 10 % der Zeit seit Last-Modified). Bei 1.1.7 ist
            // genau das passiert: Der frische Cache wurde mit der ALTEN
            // style.css befuellt, der vorherige Cache danach geloescht -- neues
            // HTML, altes Stylesheet, und zwar dauerhaft.
            //
            // 'reload' umgeht den HTTP-Cache zwingend. Zusammen mit dem ?v= in
            // der Adresse ist das Guertel UND Hosentraeger; nach dem Vorfall
            // ist mir das beides wert.
            .then((cache) => cache.addAll(
                ASSETS.map((pfad) => new Request(pfad, { cache: 'reload' }))
            ))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((namen) => Promise.all(
                namen.filter((n) => n !== CACHE).map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const anfrage = event.request;

    // Alles ausser GET geht immer ans Netz.
    if (anfrage.method !== 'GET') {
        return;
    }

    const url = new URL(anfrage.url);

    // Fremde Herkunft geht uns nichts an.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Zwei Gruppen, sonst nichts: Dateien aus assets/ mit einer der bekannten
    // Endungen, und die Seiten-Skripte im Wurzelverzeichnis. Alles andere --
    // HTML-Seiten, api/*, image.php -- laeuft ungefiltert ans Netz
    // (network-only), und das ist die Regel, an der sich nichts aendert.
    const istAsset = url.pathname.includes('/assets/')
        && /\.(css|js|json|png|svg|webmanifest)$/.test(url.pathname);

    if (!istAsset && !istSeitenSkript(url.pathname)) {
        return;
    }

    // "stale-while-revalidate" statt reinem cache-first.
    //
    // Reines cache-first hat am 2026-08-07 einen echten Fehler verursacht: Ein
    // Service Worker wird NUR neu installiert, wenn sich seine eigene Datei
    // aendert. Bleibt sw.js gleich, laeuft install() nie wieder, cache.addAll()
    // ebenso wenig -- und caches.match() liefert bis in alle Ewigkeit die
    // Fassung vom allerersten Besuch. style.css und app.js waren dadurch in
    // jedem Browser eingefroren; saemtliche Stilaenderungen mehrerer Versionen
    // kamen nie an.
    //
    // Hier wird deshalb sofort aus dem Cache geantwortet (schnell, wie bisher)
    // UND parallel die frische Fassung geholt und abgelegt. Beim naechsten
    // Aufruf ist sie da. Ein Aufruf Verzoegerung -- aber nie wieder ein
    // dauerhaft veralteter Stand, auch wenn jemand vergisst, CACHE hochzuzaehlen.
    event.respondWith(
        caches.match(anfrage).then((treffer) => {
            // Auch hier am HTTP-Cache vorbei: Ohne 'reload' revalidiert diese
            // Anfrage gegen denselben Browser-Cache, aus dem die veraltete
            // Fassung stammt -- die "Revalidierung" bestaetigte dann nur den
            // alten Stand, und der zweite Seitenaufruf heilte nichts.
            const frisch = new Request(anfrage.url, { cache: 'reload' });

            const ausDemNetz = fetch(frisch).then((antwort) => {
                // Nur vollstaendige, eigene Antworten aufnehmen.
                if (antwort && antwort.status === 200 && antwort.type === 'basic') {
                    const kopie = antwort.clone();
                    caches.open(CACHE).then((cache) => cache.put(anfrage, kopie));
                }
                return antwort;
            }).catch(() => treffer);   // offline: der Cache muss reichen

            return treffer || ausDemNetz;
        })
    );
});
