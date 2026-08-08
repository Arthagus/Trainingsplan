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
    'Pläne'          => (int)db()->query('SELECT COUNT(*) FROM plans')->fetchColumn(),
    'Einheiten'      => (int)db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn(),
    'Protokollzeilen'=> (int)db()->query('SELECT COUNT(*) FROM workout_log')->fetchColumn(),
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

<h2>Datenbankpflege</h2>

<div class="karte">
    <dl class="platzhalter-liste">
        <dt>Integrität prüfen</dt>
        <dd>
            Sucht strukturelle Schäden und Fremdschlüssel, die ins Leere zeigen.
            Ändert nichts.
            <button type="button" class="leise wartung" data-aktion="integrity">Prüfen</button>
        </dd>

        <dt>Kompaktieren (VACUUM)</dt>
        <dd>
            Schreibt die Datei neu und gibt Platz frei, den gelöschte Zeilen belegen.
            <button type="button" class="leise wartung" data-aktion="vacuum">Kompaktieren</button>
        </dd>

        <dt>Statistiken auffrischen</dt>
        <dd>
            <code>PRAGMA optimize</code> — hilft dem Abfrageplaner, die richtigen Indizes
            zu wählen.
            <button type="button" class="leise wartung" data-aktion="optimize">Auffrischen</button>
        </dd>

        <dt>WAL zurückschreiben</dt>
        <dd>
            Überträgt das Write-Ahead-Log in die Hauptdatei. Sinnvoll, wenn die
            <code>-wal</code>-Datei oben ungewöhnlich groß ist.
            <button type="button" class="leise wartung" data-aktion="checkpoint">Zurückschreiben</button>
        </dd>
    </dl>
</div>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
