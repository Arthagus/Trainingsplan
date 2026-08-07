<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/upload.php';

/**
 * Sicherung und Wiederherstellung (§6.5).
 *
 * Die zentrale Regel: **Eine Sicherung entsteht ueber `VACUUM INTO`, niemals
 * als Dateikopie.** Die Datenbank laeuft im WAL-Modus -- ein `cp` der `.db`
 * ohne die zugehoerigen `-wal`/`-shm`-Dateien liefert im besten Fall einen
 * veralteten, im schlechteren einen unbrauchbaren Stand. `VACUUM INTO` erzeugt
 * dagegen eine in sich geschlossene, bereits kompaktierte Kopie, waehrend die
 * App weiterlaeuft.
 *
 * Und: **Vor jedem Ueberschreiben wird geprueft.** Eine kaputte Sicherung darf
 * niemals den Produktivstand zerstoeren -- deshalb wandert sie erst in einen
 * Zwischenordner, wird dort mit `PRAGMA integrity_check` und einem Blick auf
 * die erwarteten Tabellen geprueft, und erst danach eingespielt.
 */

const BACKUP_MAX_UPLOAD = 100 * 1024 * 1024;   // 100 MB
const BACKUP_DB_NAME    = 'trainingsplan.db';  // Name innerhalb des Archivs
const BACKUP_BEHALTEN   = 20;                  // Hoechstzahl automatisch behaltener Sicherungen

/**
 * Verzeichnis der Sicherungen -- im Datenvolume, damit sie einen Rebuild
 * ueberleben.
 */
function backup_dir(): string {
    $dir = dirname(db_path()) . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
        throw new RuntimeException('Sicherungsverzeichnis lässt sich nicht anlegen: ' . $dir);
    }
    return $dir;
}

function zip_verfuegbar(): bool {
    return class_exists('ZipArchive');
}

/**
 * Rekursives Loeschen. Folgt bewusst keinen Symlinks -- sonst raeumte ein
 * praeparierter Link im Zwischenordner das halbe Dateisystem ab.
 */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff((array)scandir($dir), ['.', '..']) as $eintrag) {
        $pfad = $dir . DIRECTORY_SEPARATOR . $eintrag;
        if (is_dir($pfad) && !is_link($pfad)) {
            rrmdir($pfad);
        } else {
            @unlink($pfad);
        }
    }
    @rmdir($dir);
}

function bytes_lesbar(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' Bytes';
}

/**
 * Prueft einen Sicherungsnamen und liefert den vollen Pfad.
 *
 * basename() gegen Verzeichniswechsel, Whitelist der Endungen, und ein
 * realpath-Praefixvergleich als Netz darunter -- dieselbe Path-Jail wie in
 * image.php. Der Name kommt vom Client, also gilt er als feindlich.
 */
function backup_pfad(string $name): string {
    $name = basename($name);

    if (preg_match('/^[A-Za-z0-9._-]+\.(zip|db)$/', $name) !== 1) {
        throw new RuntimeException('Ungültiger Dateiname.');
    }

    $dir  = realpath(backup_dir());
    $pfad = realpath($dir . '/' . $name);

    if ($dir === false || $pfad === false
        || !str_starts_with($pfad, $dir . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Diese Sicherung gibt es nicht.');
    }

    return $pfad;
}

/**
 * Erzeugt eine Sicherung.
 *
 * @param bool $mitBildern ZIP mit Datenbank und uploads/, sonst nur die Datenbank
 * @return string Dateiname der erzeugten Sicherung
 */
function backup_erstellen(bool $mitBildern): string {
    $dir      = backup_dir();
    $zeit     = date('Y-m-d_H-i-s');
    $tempDb   = $dir . '/.vacuum_' . bin2hex(random_bytes(6)) . '.db';

    // VACUUM INTO statt Dateikopie -- siehe Kopfkommentar.
    try {
        $stmt = db()->prepare('VACUUM INTO ?');
        $stmt->execute([$tempDb]);
    } catch (Throwable $e) {
        @unlink($tempDb);
        throw new RuntimeException('Die Datenbank ließ sich nicht sichern: ' . $e->getMessage());
    }

    if (!$mitBildern || !zip_verfuegbar()) {
        $ziel = $dir . '/trainingsplan_' . $zeit . '.db';
        if (!@rename($tempDb, $ziel)) {
            @unlink($tempDb);
            throw new RuntimeException('Die Sicherung ließ sich nicht ablegen.');
        }
        @chmod($ziel, 0o640);
        return basename($ziel);
    }

    $ziel = $dir . '/trainingsplan_' . $zeit . '.zip';
    $zip  = new ZipArchive();

    if ($zip->open($ziel, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tempDb);
        throw new RuntimeException('Das Archiv ließ sich nicht anlegen.');
    }

    $zip->addFile($tempDb, BACKUP_DB_NAME);

    $bilder = 0;
    $uploads = uploads_path();
    if (is_dir($uploads)) {
        foreach ((array)glob($uploads . '/*.jpg') as $bild) {
            $zip->addFile($bild, 'uploads/' . basename($bild));
            $bilder++;
        }
    }

    // Ein Zettel im Archiv: Wer es in einem Jahr oeffnet, soll wissen, was er
    // vor sich hat und wie es zurueckgespielt wird.
    $zip->addFromString('LIESMICH.txt', implode("\n", [
        'Sicherung der Trainingsplan-App',
        'Erstellt: ' . now(),
        'Enthält: ' . BACKUP_DB_NAME . ' (SQLite) und ' . $bilder . ' Übungsbilder in uploads/',
        '',
        'Zurückspielen über die Wartungsseite der App (Menüpunkt "Wartung").',
        'Die Datenbank NICHT von Hand über die laufende .db kopieren — sie läuft',
        'im WAL-Modus, eine Dateikopie wäre unvollständig.',
        '',
    ]));

    $zip->close();
    @unlink($tempDb);
    @chmod($ziel, 0o640);

    return basename($ziel);
}

/**
 * Prueft eine SQLite-Datei auf Brauchbarkeit.
 *
 * Zwei Stufen: `integrity_check` findet strukturelle Schaeden, der Blick auf
 * die Tabellen faengt den Fall ab, dass jemand eine voellig fremde -- aber in
 * sich gesunde -- SQLite-Datei einspielt.
 *
 * @return string Leerer String, wenn alles in Ordnung ist, sonst die Ursache
 */
function db_datei_pruefen(string $pfad): string {
    try {
        $test = new PDO('sqlite:' . $pfad, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $ergebnis = (string)$test->query('PRAGMA integrity_check')->fetchColumn();
        if ($ergebnis !== 'ok') {
            return 'Die Datenbank ist beschädigt: ' . $ergebnis;
        }

        $noetig = ['users', 'exercises', 'plans', 'sessions', 'workout_log'];
        $da = $test->query(
            "SELECT name FROM sqlite_master WHERE type = 'table'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $fehlt = array_diff($noetig, $da);
        if ($fehlt !== []) {
            return 'Das ist keine Sicherung dieser App — es fehlen die Tabellen: '
                 . implode(', ', $fehlt);
        }
    } catch (Throwable $e) {
        return 'Die Datei ließ sich nicht als Datenbank öffnen.';
    }

    return '';
}

/**
 * Spielt eine Sicherung ein -- ueberschreibt den kompletten Datenbestand.
 *
 * Ablauf mit Netz und doppeltem Boden:
 *   1. Sicherung in einen Zwischenordner auspacken
 *   2. dort pruefen (integrity_check + Tabellen)
 *   3. den aktuellen Stand als Rueckfallkopie beiseitelegen
 *   4. erst dann ueberschreiben
 *   5. bei Fehlschlag die Rueckfallkopie zurueckholen
 *
 * @return string Beschreibung dessen, was eingespielt wurde
 */
function backup_wiederherstellen(string $name): string {
    $quelle = backup_pfad($name);
    $endung = strtolower((string)pathinfo($quelle, PATHINFO_EXTENSION));
    $temp   = backup_dir() . '/.restore_' . bin2hex(random_bytes(6));

    if (!@mkdir($temp, 0o755, true)) {
        throw new RuntimeException('Zwischenordner lässt sich nicht anlegen.');
    }

    try {
        $bilderQuelle = null;

        if ($endung === 'zip') {
            if (!zip_verfuegbar()) {
                throw new RuntimeException('ZIP-Archive lassen sich hier nicht öffnen.');
            }
            $zip = new ZipArchive();
            if ($zip->open($quelle) !== true) {
                throw new RuntimeException('Das Archiv ließ sich nicht öffnen.');
            }
            $zip->extractTo($temp);
            $zip->close();

            $dbDatei = $temp . '/' . BACKUP_DB_NAME;
            if (!is_file($dbDatei)) {
                throw new RuntimeException(
                    'Im Archiv fehlt ' . BACKUP_DB_NAME . ' — das ist keine Sicherung dieser App.'
                );
            }
            if (is_dir($temp . '/uploads')) {
                $bilderQuelle = $temp . '/uploads';
            }
        } else {
            $dbDatei = $temp . '/' . BACKUP_DB_NAME;
            if (!@copy($quelle, $dbDatei)) {
                throw new RuntimeException('Die Sicherung ließ sich nicht lesen.');
            }
        }

        $fehler = db_datei_pruefen($dbDatei);
        if ($fehler !== '') {
            throw new RuntimeException($fehler . ' Es wurde nichts überschrieben.');
        }

        // Ab hier wird geschrieben. Verbindung schliessen, sonst haelt SQLite
        // Dateihandles auf den alten Stand.
        db_close();

        $ziel      = db_path();
        $rueckfall = $ziel . '.vor_restore';
        if (is_file($ziel) && !@copy($ziel, $rueckfall)) {
            throw new RuntimeException('Rückfallkopie ließ sich nicht anlegen — abgebrochen.');
        }

        // WAL- und SHM-Datei muessen weg: Sie gehoeren zum alten Stand und
        // wuerden die frisch eingespielte Datenbank sonst wieder ueberschreiben.
        @unlink($ziel . '-wal');
        @unlink($ziel . '-shm');

        if (!@copy($dbDatei, $ziel)) {
            if (is_file($rueckfall)) {
                @copy($rueckfall, $ziel);
            }
            throw new RuntimeException('Die Datenbank ließ sich nicht ersetzen.');
        }

        $bilderAnzahl = 0;
        if ($bilderQuelle !== null) {
            $uploads = uploads_path();
            if (!is_dir($uploads)) {
                @mkdir($uploads, 0o755, true);
            }
            foreach ((array)glob($bilderQuelle . '/*.jpg') as $bild) {
                if (@copy($bild, $uploads . '/' . basename($bild))) {
                    $bilderAnzahl++;
                }
            }
        }

        // Gegenprobe: Laesst sich die eingespielte Datenbank auch wirklich
        // oeffnen? Wenn nicht, sofort zurueck auf den alten Stand.
        try {
            db();
        } catch (Throwable $e) {
            db_close();
            if (is_file($rueckfall)) {
                @copy($rueckfall, $ziel);
            }
            throw new RuntimeException(
                'Die eingespielte Datenbank ließ sich nicht öffnen — der vorherige '
                . 'Stand wurde wiederhergestellt.'
            );
        }

        @unlink($rueckfall);

        return $bilderAnzahl > 0
            ? 'Datenbank und ' . $bilderAnzahl . ' Bilder wurden eingespielt.'
            : 'Die Datenbank wurde eingespielt.';
    } finally {
        rrmdir($temp);
    }
}

/**
 * Nimmt eine hochgeladene Sicherung entgegen -- prueft sie, bevor sie liegen
 * bleibt. Eingespielt wird sie dadurch noch nicht.
 */
function backup_hochladen(array $file): string {
    $fehlerCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($fehlerCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($fehlerCode));
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Kein gültiger Upload.');
    }
    if ((int)($file['size'] ?? 0) > BACKUP_MAX_UPLOAD) {
        throw new RuntimeException('Die Datei ist größer als '
            . (int)(BACKUP_MAX_UPLOAD / 1048576) . ' MB.');
    }

    $name   = basename((string)($file['name'] ?? 'sicherung'));
    $endung = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($endung, ['zip', 'db'], true)) {
        throw new RuntimeException('Nur .zip- und .db-Dateien sind erlaubt.');
    }

    // Pruefen, BEVOR die Datei im Sicherungsverzeichnis landet -- dort soll
    // nichts liegen, das sich nicht einspielen laesst.
    $temp = backup_dir() . '/.upload_' . bin2hex(random_bytes(6));
    if (!@mkdir($temp, 0o755, true)) {
        throw new RuntimeException('Zwischenordner lässt sich nicht anlegen.');
    }

    try {
        if ($endung === 'zip') {
            if (!zip_verfuegbar()) {
                throw new RuntimeException('ZIP-Archive lassen sich hier nicht prüfen.');
            }
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Das ist kein lesbares ZIP-Archiv.');
            }
            if ($zip->locateName(BACKUP_DB_NAME) === false) {
                $zip->close();
                throw new RuntimeException(
                    'Im Archiv fehlt ' . BACKUP_DB_NAME . ' — das ist keine Sicherung dieser App.'
                );
            }
            $zip->extractTo($temp, [BACKUP_DB_NAME]);
            $zip->close();
            $pruefDatei = $temp . '/' . BACKUP_DB_NAME;
        } else {
            $pruefDatei = $temp . '/' . BACKUP_DB_NAME;
            if (!@copy($tmp, $pruefDatei)) {
                throw new RuntimeException('Die Datei ließ sich nicht lesen.');
            }
        }

        $fehler = db_datei_pruefen($pruefDatei);
        if ($fehler !== '') {
            throw new RuntimeException($fehler);
        }

        // Sauberer Zielname; bei Kollision einen Zeitstempel anhaengen.
        $sicher = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'sicherung.' . $endung;
        $ziel   = backup_dir() . '/' . $sicher;
        if (file_exists($ziel)) {
            $ziel = backup_dir() . '/'
                  . pathinfo($sicher, PATHINFO_FILENAME)
                  . '_' . date('H-i-s') . '.' . $endung;
        }

        if (!@move_uploaded_file($tmp, $ziel)) {
            throw new RuntimeException('Die Datei ließ sich nicht ablegen.');
        }
        @chmod($ziel, 0o640);

        return basename($ziel);
    } finally {
        rrmdir($temp);
    }
}

function backup_loeschen(string $name): void {
    $pfad = backup_pfad($name);
    if (!@unlink($pfad)) {
        throw new RuntimeException('Die Sicherung ließ sich nicht löschen.');
    }
}

/**
 * Alle vorhandenen Sicherungen, neueste zuerst.
 */
function backup_liste(): array {
    $dir = backup_dir();
    $dateien = array_merge(
        (array)glob($dir . '/*.zip'),
        (array)glob($dir . '/*.db')
    );

    $liste = [];
    foreach ($dateien as $datei) {
        if (!is_file($datei)) {
            continue;
        }
        $endung = strtolower((string)pathinfo($datei, PATHINFO_EXTENSION));
        $liste[] = [
            'name'  => basename($datei),
            'size'  => (int)filesize($datei),
            'zeit'  => (int)filemtime($datei),
            'art'   => $endung === 'zip' ? 'mit Bildern' : 'nur Datenbank',
        ];
    }

    usort($liste, static fn(array $a, array $b): int => $b['zeit'] <=> $a['zeit']);
    return $liste;
}

/**
 * Raeumt alte Sicherungen weg, damit das Volume nicht zulaeuft.
 * Behalten werden die BACKUP_BEHALTEN neuesten.
 */
function backups_aufraeumen(): int {
    $liste = backup_liste();
    $weg = 0;
    foreach (array_slice($liste, BACKUP_BEHALTEN) as $alt) {
        if (@unlink(backup_dir() . '/' . $alt['name'])) {
            $weg++;
        }
    }
    return $weg;
}
