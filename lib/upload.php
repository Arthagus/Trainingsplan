<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
// Nur fuer verwaiste_bilder(): Die Frage "gehoert diese Datei noch zu einer
// Uebung" ist ohne die Datenbank nicht zu beantworten.
require_once __DIR__ . '/db.php';

/**
 * Bild-Uploads fuer Uebungen (§5).
 *
 * Der Ablauf ist bewusst misstrauisch: Der Typ wird aus dem INHALT bestimmt,
 * nie aus der Endung, und die Datei wird ueber GD neu enkodiert statt
 * durchgereicht. Damit ueberlebt kein eingebetteter Code den Upload -- eine
 * als .jpg getarnte PHP-Datei wird beim Neuzeichnen zu einem Bild ohne
 * Nutzlast oder scheitert vorher.
 */

const UPLOAD_MAX_BYTES  = 5 * 1024 * 1024;   // 5 MB (§5)
const UPLOAD_MAX_EDGE   = 1600;              // laengste Kante des Hauptbildes
const UPLOAD_THUMB_EDGE = 320;               // laengste Kante des Thumbnails
const UPLOAD_JPEG_QUALITY = 85;

/**
 * Obergrenze der Bildflaeche in Pixeln.
 *
 * Die Dateigroesse allein schuetzt nicht: GD arbeitet unkomprimiert, ein
 * 25-Megapixel-Bild belegt beim Dekodieren rund 100 MB -- unabhaengig davon,
 * dass die JPEG-Datei nur 4 MB gross ist. Ohne diese Pruefung reisst
 * imagecreatefromjpeg() das memory_limit und der Request endet in einem
 * nackten 500er statt in einer verstaendlichen Meldung.
 *
 * 25 MP lassen jede Handykamera durch (12 MP sind ueblich) und bleiben unter
 * dem im Dockerfile gesetzten Limit von 256 MB.
 */
const UPLOAD_MAX_PIXEL = 25_000_000;

/**
 * Verzeichnis der hochgeladenen Bilder. Wie beim DB-Pfad ueber die Umgebung
 * konfigurierbar, weil es im Container ein Volume ist.
 */
function uploads_path(): string {
    $env = getenv('UPLOADS_PATH');
    return rtrim(($env !== false && $env !== '') ? $env : __DIR__ . '/../uploads', '/');
}

/**
 * Nimmt einen Upload aus $_FILES entgegen und legt Bild plus Thumbnail ab.
 *
 * @param array $file Ein Eintrag aus $_FILES
 * @return string Der Dateiname des Hauptbildes (ohne Pfad), z. B. "a1b2….jpg"
 * @throws RuntimeException mit einer Meldung, die dem Benutzer gezeigt werden darf
 */
function save_exercise_image(array $file): string {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($error));
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    // Ohne diese Pruefung liesse sich ueber einen praeparierten Request eine
    // beliebige Serverdatei als "Upload" ausgeben.
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Kein gültiger Upload.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Die Datei ist leer.');
    }
    if ($size > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Das Bild ist größer als 5 MB.');
    }

    // Typ aus dem Inhalt, nicht aus dem Namen und nicht aus dem vom Browser
    // mitgeschickten Content-Type -- beides ist frei waehlbar.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new RuntimeException('Dateityp lässt sich nicht bestimmen.');
    }
    $mime = (string)finfo_file($finfo, $tmp);
    finfo_close($finfo);

    // WebP ist mit dabei, weil Screenshots und Downloads heute oft in diesem
    // Format anfallen -- sonst muesste jedes Bild vorher umgewandelt werden.
    // Ausgegeben wird weiterhin ausschliesslich JPEG; es waechst also nur der
    // Kreis der Formate, die hineinreichen duerfen.
    $erlaubt = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $erlaubt, true)) {
        throw new RuntimeException('Nur JPEG, PNG und WebP sind erlaubt.');
    }
    if ($mime === 'image/webp' && !function_exists('imagecreatefromwebp')) {
        throw new RuntimeException(
            'WebP wird von dieser Installation nicht unterstützt. '
            . 'Bitte das Bild als JPEG oder PNG hochladen.'
        );
    }

    // Abmessungen VOR dem Dekodieren pruefen -- danach waere der Speicher
    // bereits belegt.
    $masse = @getimagesize($tmp);
    if ($masse === false) {
        throw new RuntimeException('Das Bild ließ sich nicht lesen.');
    }
    if ($masse[0] * $masse[1] > UPLOAD_MAX_PIXEL) {
        throw new RuntimeException(sprintf(
            'Das Bild ist mit %d × %d Pixeln zu groß. Bitte vorher verkleinern.',
            $masse[0], $masse[1]
        ));
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
    };
    if ($src === false) {
        throw new RuntimeException('Das Bild ließ sich nicht lesen.');
    }

    $dir = uploads_path();
    if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
        imagedestroy($src);
        throw new RuntimeException('Upload-Verzeichnis lässt sich nicht anlegen.');
    }

    // Zufaelliger Name: der urspruengliche Dateiname wird nicht uebernommen.
    $base  = bin2hex(random_bytes(16));
    $name  = $base . '.jpg';
    $thumb = $base . '_thumb.jpg';

    try {
        write_resized($src, $dir . '/' . $name,  UPLOAD_MAX_EDGE);
        write_resized($src, $dir . '/' . $thumb, UPLOAD_THUMB_EDGE);
    } finally {
        imagedestroy($src);
    }

    return $name;
}

/**
 * Zeichnet das Bild auf die gewuenschte Maximalkante neu und schreibt es als
 * JPEG. Kleinere Bilder werden nicht vergroessert.
 *
 * Transparenz wird gegen Weiss ersetzt, weil das Ziel JPEG ist -- ohne diesen
 * Schritt wuerden PNG-freie Bereiche schwarz.
 */
function write_resized(GdImage $src, string $target, int $maxEdge): void {
    $w = imagesx($src);
    $h = imagesy($src);

    $scale = min(1.0, $maxEdge / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $ok = imagejpeg($dst, $target, UPLOAD_JPEG_QUALITY);
    imagedestroy($dst);

    if (!$ok) {
        throw new RuntimeException('Das Bild ließ sich nicht speichern.');
    }
    @chmod($target, 0o644);
}

/**
 * Loescht Bild und Thumbnail. Fehlende Dateien sind kein Fehler.
 *
 * Der Name wird durch basename() gezogen und gegen das erwartete Muster
 * geprueft, damit ein manipulierter Datenbankwert nicht in ein
 * ../../-Loeschen umschlagen kann.
 */
function delete_exercise_image(?string $name): void {
    if ($name === null || $name === '') {
        return;
    }
    $name = basename($name);
    if (preg_match('/^[0-9a-f]{32}\.jpg$/', $name) !== 1) {
        return;
    }

    $base = substr($name, 0, 32);
    @unlink(uploads_path() . '/' . $name);
    @unlink(uploads_path() . '/' . $base . '_thumb.jpg');
}

/**
 * Uebersetzt die Fehlercodes aus $_FILES in verstaendliche Meldungen.
 */
function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Das Bild ist zu groß.',
        UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
        UPLOAD_ERR_NO_FILE                        => 'Es wurde kein Bild ausgewählt.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Der Server konnte das Bild nicht ablegen.',
        UPLOAD_ERR_EXTENSION                      => 'Der Upload wurde serverseitig blockiert.',
        default                                   => 'Der Upload ist fehlgeschlagen.',
    };
}

/**
 * Bilddateien in uploads/, zu denen es keine Uebung mehr gibt (§6.5).
 *
 * Karteileichen entstehen nicht im Normalbetrieb -- api/exercises.php raeumt
 * beim Ersetzen, Entfernen und Loeschen selbst auf. Sie entstehen an den
 * Raendern: beim Einspielen einer aelteren Sicherung (die Datenbank geht
 * zurueck, die Dateien nicht), bei einem Restore aus einer .db OHNE Bilder,
 * oder wenn ein Container-Neustart mitten in einen Upload faellt. Selten also,
 * aber unbemerkt -- und Bilder sind das einzige, was in diesem Projekt
 * nennenswert Platz braucht.
 *
 * Drei Vorsichtsmassnahmen, jede aus eigenem Grund:
 *
 * - **Nur das eigene Namensmuster.** `<32 Hex>.jpg` und `<32 Hex>_thumb.jpg`
 *   entstehen ausschliesslich in save_exercise_image(). Was anders heisst,
 *   hat jemand von Hand dorthin gelegt; das Aufraeumen fasst es nicht an und
 *   meldet es auch nicht als verwaist.
 * - **Das Thumbnail haengt am Original**, nicht an einer eigenen Spalte: Beide
 *   gelten als benutzt, sobald `exercises.image_path` die 32 Hex-Zeichen nennt.
 *   Ohne diese Zuordnung waere JEDES Thumbnail verwaist.
 * - **Frische Dateien bleiben tabu** ($mindestAlter, Vorgabe eine Stunde).
 *   save_exercise_image() schreibt die Datei, BEVOR die Uebung in der Datenbank
 *   steht. Zwischen beidem liegen Millisekunden, aber genau dort wuerde ein
 *   gleichzeitig laufendes Aufraeumen ein Bild loeschen, das gerade entsteht.
 *
 * @return list<array{name:string,groesse:int,alter_tage:int}> nach Namen sortiert
 */
function verwaiste_bilder(int $mindestAlter = 3600): array {
    $verzeichnis = uploads_path();
    if (!is_dir($verzeichnis)) {
        return [];
    }

    $benutzt = [];
    $stmt = db()->query(
        "SELECT image_path FROM exercises WHERE image_path IS NOT NULL AND image_path <> ''"
    );
    foreach ($stmt as $zeile) {
        $name = basename((string)$zeile['image_path']);
        if (preg_match('/^([0-9a-f]{32})\.jpg$/', $name, $treffer) === 1) {
            $benutzt[$treffer[1]] = true;
        }
    }

    $jetzt   = time();
    $gefunden = [];
    foreach ((array)glob($verzeichnis . '/*.jpg') as $datei) {
        $name = basename((string)$datei);
        if (preg_match('/^([0-9a-f]{32})(_thumb)?\.jpg$/', $name, $treffer) !== 1) {
            continue;
        }
        if (isset($benutzt[$treffer[1]])) {
            continue;
        }

        $alter = $jetzt - (int)@filemtime((string)$datei);
        if ($alter < $mindestAlter) {
            continue;
        }

        $gefunden[] = [
            'name'       => $name,
            'groesse'    => (int)@filesize((string)$datei),
            'alter_tage' => intdiv(max(0, $alter), 86400),
        ];
    }

    usort($gefunden, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $gefunden;
}

/**
 * Loescht, was verwaiste_bilder() findet.
 *
 * **Die Liste kommt NICHT vom Aufrufer.** Sie wird hier neu ermittelt, und
 * damit kann kein Dateiname von aussen bestimmen, was geloescht wird -- auch
 * nicht der, den die Oberflaeche eine Minute vorher angezeigt hat. Dazwischen
 * kann eine Uebung entstanden sein, die genau dieses Bild benutzt.
 *
 * @return array{anzahl:int,bytes:int}
 */
function verwaiste_bilder_loeschen(): array {
    $anzahl = 0;
    $bytes  = 0;

    foreach (verwaiste_bilder() as $datei) {
        $pfad = uploads_path() . '/' . $datei['name'];
        if (@unlink($pfad)) {
            $anzahl++;
            $bytes += $datei['groesse'];
        }
    }

    return ['anzahl' => $anzahl, 'bytes' => $bytes];
}
