'use strict';

/**
 * Vorlagen -- der Split-Katalog (§6.4). Seit 1.2.23.
 *
 * Dieselbe Bedienung wie splits.js und derselbe Endpunkt: senden, neu laden,
 * Fehler an der Zeile. Die Gemeinsamkeiten stehen als splitAktion() und
 * splitTextZeigen() in assets/app.js -- zwei Fassungen davon waeren die
 * Dublette, die spaeter abweicht.
 */

(() => {
    // Zwischen den Karten umschalten (§6.4, seit 1.3.2).
    splitWechselVerdrahten();

    // --- Neue (leere) Vorlage anlegen ---------------------------------------
    const neu = qs('#vorlage-neu');
    if (neu) {
        const fehler = qs('#vorlage-neu-fehler');

        neu.addEventListener('submit', async (e) => {
            e.preventDefault();
            fehler.hidden = true;
            fehler.textContent = '';

            const feld = qs('input[name="name"]', neu);

            try {
                // vorlage: true macht den Unterschied zum Formular auf
                // splits.php -- der Endpunkt legt dann einen Split OHNE
                // Besitzer an (splits.user_id IS NULL) und prueft dafuer
                // selbst auf is_admin().
                await apiFetch('api/splits.php', {
                    body: { action: 'create', name: feld.value, vorlage: true },
                });
                window.location.reload();
            } catch (f) {
                fehler.textContent = f.fields?.name || f.message;
                fehler.hidden = false;
                feld.focus();
            }
        });
    }

    // --- Aus einem Benutzer-Split eine Vorlage machen ------------------------
    //
    // Ein Pulldown statt einer Kartenliste, und das ist der Unterschied in der
    // Sache: Hier wird nichts verwaltet, hier wird genau eine Handlung
    // ausgeloest. Loeschen und Umbenennen gibt es bewusst nicht -- das sind die
    // persoenlichen Splits anderer Leute.
    const kandidat = qs('#kandidat');
    if (kandidat) {
        const vorschau = qs('#kandidat-plaene');
        const knopf    = qs('#kandidat-veroeffentlichen');
        const kasten   = qs('#kandidaten');
        const fehler   = qs('.zeilen-fehler', kasten);

        const zeigen = () => {
            const opt = kandidat.selectedOptions[0];
            vorschau.textContent = opt ? (opt.dataset.plaene || 'Noch kein Plan darin.') : '';
        };
        kandidat.addEventListener('change', zeigen);
        zeigen();

        knopf.addEventListener('click', async () => {
            const opt = kandidat.selectedOptions[0];
            if (!opt) return;

            // Rueckfrage, weil das Ergebnis fuer ALLE sichtbar wird -- und weil
            // der Name im Katalog anders lauten soll als der private. Der
            // Vorschlag ist deshalb der Splitname OHNE den Benutzer davor.
            const wunsch = window.prompt(
                'Als Vorlage für alle veröffentlichen. Unter welchem Namen?\n\n'
                + 'Es entsteht eine Kopie — der Split des Benutzers bleibt unverändert '
                + 'und wird von späteren Änderungen an der Vorlage nicht berührt.',
                opt.dataset.name || ''
            );
            if (wunsch === null) return;

            fehler.hidden = true;
            fehler.textContent = '';
            knopf.disabled = true;
            try {
                await apiFetch('api/splits.php', {
                    body: { action: 'publish', id: Number(opt.value), name: wunsch },
                });
                window.location.reload();
            } catch (f) {
                knopf.disabled = false;
                fehler.textContent = f.message;
                fehler.hidden = false;
            }
        });
    }

    // --- Fremden Split bearbeiten (§6.4) -------------------------------------
    //
    // Kein API-Aufruf, nur ein Sprung: Die Planverwaltung nimmt den Split als
    // ?split=, prueft selbst (split_darf_bearbeiten) und faellt zurueck, wenn
    // der Aufrufer ihn nicht bearbeiten darf.
    const fremd = qs('#fremd');
    if (fremd) {
        qs('#fremd-bearbeiten').addEventListener('click', () => {
            const opt = fremd.selectedOptions[0];
            if (!opt) return;

            window.location.href = 'plans.php?split=' + Number(opt.value);
        });
    }

    // --- Alles Uebrige haengt an einer Vorlagenkarte -------------------------
    document.addEventListener('click', (e) => {
        const zeile = e.target.closest('.split[data-id]');
        if (!zeile) return;

        const id = Number(zeile.dataset.id);
        // data-name und nicht mehr ein Eingabefeld: Im Kartenkopf sitzt seit
        // 1.3.2 das Auswahlfeld, der Name steht am <li>.
        const name = zeile.dataset.name || '';

        const alsText = e.target.closest('.split-text');
        if (alsText) {
            splitTextZeigen(qs('.split-text-inhalt', zeile), name);
            return;
        }

        const speichern = e.target.closest('.split-speichern');
        if (speichern) {
            splitUmbenennenFragen(zeile, 'Vorlage umbenennen');
            return;
        }

        const duplizieren = e.target.closest('.vorlage-duplizieren');
        if (duplizieren) {
            // Eine zweite VORLAGE, nicht eine persoenliche Kopie -- deshalb
            // 'publish' und nicht 'copy'.
            //
            // OHNE Rueckfrage nach dem Namen: Beim Duplizieren weiss man noch
            // nicht, wie die Variante heissen soll, das ergibt sich erst beim
            // Bearbeiten. Der Server haengt "(Kopie)" an; umbenannt wird
            // danach am Namensfeld der Karte. Eine Rueckfrage, die man mit dem
            // Vorschlag bestaetigt, ist keine Frage, sondern ein Klick mehr.
            splitAktion(zeile, { action: 'publish', id }, duplizieren);
            return;
        }

        const loeschen = e.target.closest('.split-loeschen');
        if (loeschen) {
            // Die Rueckfrage nennt die Folge, die man nicht erwartet: Die
            // KOPIEN bleiben. Eine Vorlage zu loeschen nimmt niemandem seinen
            // Split weg -- es verschwindet nur der Katalogeintrag, und mit ihm
            // der Knopf "Auf Vorlage zurücksetzen" an den Kopien.
            if (!window.confirm(
                'Die Vorlage „' + name + '“ mit allen darin enthaltenen Plänen löschen?\n\n'
                + 'Bereits gezogene Kopien bleiben den Benutzern erhalten; sie verlieren '
                + 'nur ihre Herkunft und damit den Knopf „Auf Vorlage zurücksetzen“.'
            )) {
                return;
            }
            splitAktion(zeile, { action: 'delete', id }, loeschen);
        }
    });
})();
