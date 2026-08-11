'use strict';

/**
 * Service Worker.
 *
 * ZWINGENDE REGEL (LASTENHEFT.md §2): Gecacht werden ausschliesslich statische
 * Assets -- CSS, JS, Manifest, Icons -- mit Strategie cache-first. HTML-Seiten
 * und API-Antworten NIEMALS.
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

// Beim Ändern dieser Datei hochzählen. Ein neuer Name wirft den alten Cache
// beim Aktivieren weg (siehe 'activate') und erzwingt frische Assets.
const CACHE = 'trainingsplan-assets-v18';

const ASSETS = [
    'style.css',
    'app.js',
    'manifest.json',
    'icon-192.png',
    'icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(ASSETS))
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

    // Nur Dateien aus assets/ und nur die bekannten Endungen. Alles andere --
    // HTML-Seiten, api/*, image.php -- laeuft ungefiltert ans Netz (network-only).
    const istAsset = url.pathname.includes('/assets/')
        && /\.(css|js|json|png|svg|webmanifest)$/.test(url.pathname);

    if (!istAsset) {
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
            const ausDemNetz = fetch(anfrage).then((antwort) => {
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
