<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/backup.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Wartung & Sicherung (§6.5).
 *
 * Vorlage war Speisekarte/doku/maintenance_anleitung.md; die Aktionen laufen
 * hier aber über api/maintenance.php und apiFetch(), nicht über klassische
 * Formular-POSTs — das hält die Konvention aus §2.1 ein und erspart die
 * Statusmeldung per Redirect.
 */

$dbPfad   = db_path();
$dbGross  = is_file($dbPfad) ? (int)filesize($dbPfad) : 0;
$walPfad  = $dbPfad . '-wal';
$walGross = is_file($walPfad) ? (int)filesize($walPfad) : 0;

$sqlite  = (string)db()->query('SELECT sqlite_version()')->fetchColumn();
$journal = (string)db()->query('PRAGMA journal_mode')->fetchColumn();

$zahlen = [
    'Benutzer'       => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'Muskelgruppen'  => (int)db()->query('SELECT COUNT(*) FROM muscle_groups')->fetchColumn(),
    'Übungen'        => (int)db()->query('SELECT COUNT(*) FROM exercises')->fetchColumn(),
    // Splits getrennt nach Katalog und Bestand: Die Zahl der Vorlagen sagt,
    // wie viel Auswahl es gibt, die der persoenlichen, wie viel wirklich
    // benutzt wird. Zusammengezaehlt saehe man beides nicht.
    'Vorlagen'       => (int)db()->query('SELECT COUNT(*) FROM splits WHERE user_id IS NULL')->fetchColumn(),
    'Splits'         => (int)db()->query('SELECT COUNT(*) FROM splits WHERE user_id IS NOT NULL')->fetchColumn(),
    'Pläne'          => (int)db()->query('SELECT COUNT(*) FROM plans')->fetchColumn(),
    'Einheiten'      => (int)db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),
    'Protokollzeilen'=> (int)db()->query('SELECT COUNT(*) FROM workout_log')->fetchColumn(),
    // Seit 1.1.0 liegt im Expertenmodus das eigentliche Trainingsvolumen hier
    // und nicht in workout_log: Eine Protokollzeile kann einen Satz tragen oder
    // sechs. Ohne diesen Zaehler sagte die Seite nichts darueber, wie viel
    // tatsaechlich drinsteht -- und genau das will man von einer Uebersicht
    // wissen, bevor man eine Sicherung beurteilt.
    'Sätze'          => (int)db()->query('SELECT COUNT(*) FROM workout_sets')->fetchColumn(),
];

$uploads      = uploads_path();
$bilderAnzahl = is_dir($uploads) ? count((array)glob($uploads . '/*.jpg')) : 0;
$bilderGross  = 0;
foreach ((array)glob($uploads . '/*.jpg') as $b) {
    $bilderGross += (int)filesize($b);
}

$sicherungen = backup_liste();
$letzte      = $sicherungen === [] ? null : $sicherungen[0];
// Ohne Sicherung oder mit einer sehr alten ist das die wichtigste Aussage der
// Seite -- deshalb steht sie ganz oben und nicht in der Liste versteckt.
$warnung     = $letzte === null || (time() - $letzte['zeit']) > 14 * 86400;

$pageTitle = 'Wartung & Sicherung';
require __DIR__ . '/lib/view_header.php';
?>

<p id="wartung-meldung" class="karte" role="status" hidden></p>

<?php if ($warnung): ?>
    <div class="karte hinweis-warnung">
        <strong>
            <?= $letzte === null
                ? 'Es gibt noch keine Sicherung.'
                : 'Die letzte Sicherung ist vom ' . h(date('d.m.Y', $letzte['zeit'])) . '.' ?>
        </strong>
        <p class="matt">
            Die Datenbank lässt sich <strong>nicht</strong> von Hand kopieren — sie läuft im
            WAL-Modus, eine Dateikopie wäre unvollständig. Der einzige verlässliche Weg ist
            die Sicherung hier.
        </p>
    </div>
<?php endif; ?>

<h2>Zustand</h2>

<div class="status-gitter">
    <?php // Steht bewusst an erster Stelle: Sie ist die Antwort auf die Frage,
          // die man sonst nur in Portainer beantwortet bekommt -- welcher Stand
          // laeuft hier gerade. Die Nummer kommt aus der Datei VERSION im Image,
          // nicht aus der Datenbank; ein Restore aendert sie also nicht. ?>
    <div class="status-karte">
        <span class="status-wert"><?= h(app_version()) ?></span>
        <span class="matt">Version</span>
    </div>
    <div class="status-karte">
        <span class="status-wert"><?= h(bytes_lesbar($dbGross)) ?></span>
        <span class="matt">Datenbank</span>
    </div>
    <div class="status-karte">
        <span class="status-wert"><?= h(bytes_lesbar($walGross)) ?></span>
        <span class="matt">WAL-Datei</span>
    </div>
    <div class="status-karte">
        <span class="status-wert"><?= $bilderAnzahl ?></span>
        <span class="matt">Bilder (<?= h(bytes_lesbar($bilderGross)) ?>)</span>
    </div>
    <?php foreach ($zahlen as $was => $wert): ?>
        <div class="status-karte">
            <span class="status-wert"><?= $wert ?></span>
            <span class="matt"><?= h($was) ?></span>
        </div>
    <?php endforeach; ?>
    <div class="status-karte">
        <span class="status-wert"><?= h($journal) ?></span>
        <span class="matt">Journal-Modus</span>
    </div>
    <div class="status-karte">
        <span class="status-wert"><?= h($sqlite) ?></span>
        <span class="matt">SQLite</span>
    </div>
</div>

<h2>Datenbankpflege</h2>

<div class="karte">
    <dl class="platzhalter-liste">
        <dt>Integrität prüfen</dt>
        <dd>
            Sucht strukturelle Schäden und Fremdschlüssel, die ins Leere zeigen.
            Ändert nichts.
            <p><button type="button" class="leise wartung" data-aktion="integrity">Prüfen</button></p>
        </dd>

        <dt>Kompaktieren (VACUUM)</dt>
        <dd>
            Schreibt die Datei neu und gibt Platz frei, den gelöschte Zeilen belegen.
            <p><button type="button" class="leise wartung" data-aktion="vacuum">Kompaktieren</button></p>
        </dd>

        <dt>Statistiken auffrischen</dt>
        <dd>
            <code>PRAGMA optimize</code> — hilft dem Abfrageplaner, die richtigen Indizes
            zu wählen.
            <p><button type="button" class="leise wartung" data-aktion="optimize">Auffrischen</button></p>
        </dd>

        <dt>WAL zurückschreiben</dt>
        <dd>
            Überträgt das Write-Ahead-Log in die Hauptdatei. Sinnvoll, wenn die
            <code>-wal</code>-Datei oben ungewöhnlich groß ist.
            <p><button type="button" class="leise wartung" data-aktion="checkpoint">Zurückschreiben</button></p>
        </dd>
    </dl>
</div>

<h2>Übungsbilder</h2>

<div class="karte">
    <dl class="platzhalter-liste">
        <dt>Verwaiste Bilder suchen</dt>
        <dd>
            Sucht Dateien in <code>uploads/</code>, zu denen es keine Übung mehr gibt.
            Im Normalbetrieb entstehen keine: Beim Ersetzen, Entfernen und Löschen eines
            Bildes räumt die Übungsverwaltung selbst auf. Übrig bleiben kann etwas nach
            dem <strong>Einspielen einer Sicherung</strong> — die Datenbank geht dabei auf
            einen älteren Stand zurück, die Dateien nicht. Bilder, die jünger als eine
            Stunde sind, bleiben außen vor; sie könnten gerade erst entstehen.
            <p><button type="button" class="leise wartung" data-aktion="images_orphans">Suchen</button></p>
        </dd>

        <dt>Bestandsbilder nachschneiden</dt>
        <dd>
            Seit <?= h(app_version()) ?> wird der einfarbige Rand beim <em>Hochladen</em>
            abgeschnitten. Bilder, die vorher entstanden sind, behalten ihn — hier lassen
            sie sich nachziehen. Geschnitten wird aus dem gespeicherten Bild, es wird also
            ein zweites Mal als JPEG gespeichert; die beste Qualität hat weiterhin, wer das
            Original neu hochlädt. Der Lauf lässt sich gefahrlos <strong>wiederholen</strong>
            und kommt dabei zur Ruhe: Ein zweiter Durchgang zieht höchstens einzelne Bilder
            um wenige Pixel nach — das erneute Speichern als JPEG verändert die äußerste
            Pixelreihe minimal —, ein dritter findet nichts mehr.
            <p><button type="button" class="leise wartung" data-aktion="images_recut_check">Prüfen</button></p>
        </dd>
    </dl>

    <?php // Bleibt leer, bis gesucht wurde -- die Liste kommt aus der Antwort und
          // nicht aus dem Seitenaufbau. Der Grund ist derselbe wie beim Trennen
          // der beiden Aktionen: Wer die Seite oeffnet, soll nicht schon eine
          // Loeschliste vor sich haben. ?>
    <?php // Zweiter Kasten, gleiche Bauweise wie der für die Waisen: Er kommt
          // erst mit dem Ergebnis der Prüfung und nennt, was passieren würde.
          // Der Knopf ist rot, weil der Lauf nicht umkehrbar ist -- das alte
          // Bild ist danach weg. ?>
    <div id="nachschnitt" hidden>
        <p class="matt" id="nachschnitt-kopf"></p>
        <ul class="liste-schlicht" id="nachschnitt-liste"></ul>
        <p class="matt">
            Die nachgeschnittenen Bilder bekommen <strong>neue Dateinamen</strong> — sonst
            zeigten Browser die alten aus ihrem Zwischenspeicher weiter, und zwar ein Jahr
            lang. Die alten Dateien werden danach gelöscht.
        </p>
        <p>
            <button type="button" class="gefahr" id="nachschnitt-los">
                Bilder jetzt nachschneiden
            </button>
        </p>
    </div>

    <div id="verwaiste-bilder" hidden>
        <p class="matt" id="verwaiste-kopf"></p>
        <ul class="liste-schlicht" id="verwaiste-liste"></ul>
        <p>
            <button type="button" class="gefahr" id="verwaiste-loeschen">
                Verwaiste Bilder löschen
            </button>
        </p>
    </div>
</div>

<?php if (!zip_verfuegbar()): ?>
    <div class="karte hinweis-warnung">
        <strong>ZIP-Erweiterung fehlt.</strong>
        <p class="matt">
            Sicherungen enthalten deshalb nur die Datenbank, nicht die Übungsbilder.
            Im Container ist die Erweiterung normalerweise vorhanden — fehlt sie, wurde
            das Image ohne <code>zip</code> gebaut.
        </p>
    </div>
<?php endif; ?>

<h2>Sicherung erstellen</h2>

<div class="karte">
    <p class="matt">
        Die Datenbankkopie entsteht über <code>VACUUM INTO</code> — eine in sich
        geschlossene Kopie, während die App weiterläuft. Kein Anhalten nötig.
    </p>
    <p class="aktions-zeile">
        <button type="button" class="wartung" data-aktion="backup" data-bilder="1">
            Vollständig (mit Bildern)
        </button>
        <button type="button" class="leise wartung" data-aktion="backup">
            Nur Datenbank
        </button>
    </p>
</div>

<h2>Vorhandene Sicherungen</h2>

<?php if ($sicherungen === []): ?>
    <div class="karte">
        <p>Noch keine Sicherung vorhanden.</p>
    </div>
<?php else: ?>
    <ul id="sicherungen" class="liste-schlicht">
        <?php foreach ($sicherungen as $s): ?>
            <li class="karte sicherung" data-name="<?= h($s['name']) ?>">
                <div class="gruppe-zeile">
                    <div class="gruppe-felder">
                        <strong><?= h($s['name']) ?></strong>
                        <p class="matt">
                            <?= h(date('d.m.Y H:i', $s['zeit'])) ?>
                            · <?= h(bytes_lesbar($s['size'])) ?>
                            · <?= h($s['art']) ?>
                        </p>
                    </div>
                    <div class="gruppe-knoepfe">
                        <a class="knopf leise"
                           href="<?= h(base_path()) ?>/download_backup.php?f=<?= h(urlencode($s['name'])) ?>">
                            Herunterladen
                        </a>
                        <button type="button" class="leise einspielen">Einspielen</button>
                        <button type="button" class="gefahr sicherung-loeschen">Löschen</button>
                    </div>
                </div>
                <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="matt">
        Es werden höchstens <?= BACKUP_BEHALTEN ?> Sicherungen behalten; ältere werden beim
        Erstellen einer neuen automatisch entfernt.
    </p>
<?php endif; ?>

<h2>Sicherung hochladen</h2>

<div class="karte">
    <p class="matt">
        Eine anderswo erzeugte Sicherung hierher übertragen — etwa vom Testsystem.
        Sie wird beim Hochladen geprüft, aber <strong>nicht</strong> eingespielt;
        das ist ein zweiter, bewusster Schritt.
    </p>
    <form id="upload-formular" enctype="multipart/form-data" novalidate>
        <div class="zeile-eingabe">
            <input type="file" id="backup-datei" name="datei" accept=".zip,.db" required>
            <button type="submit">Hochladen</button>
        </div>
        <p class="feld-fehler" id="upload-fehler" role="alert" hidden></p>
    </form>
</div>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
