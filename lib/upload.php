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

    // Rand weg, BEVOR die beiden Größen entstehen -- so tragen Vollbild und
    // Thumbnail denselben Zuschnitt. Die Funktion gibt im Zweifel das
    // unveränderte Bild zurück und gibt in jedem Fall genau ein Handle heraus.
    [$src, $randfarbe] = bild_rand_schneiden($src);

    try {
        write_resized($src, $dir . '/' . $name, UPLOAD_MAX_EDGE);
        write_thumb_quadrat($src, $dir . '/' . $thumb, UPLOAD_THUMB_EDGE, $randfarbe);
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

/**
 * --- Rand abschneiden (§6.3) ---------------------------------------------
 *
 * Die Übungsbilder aus den üblichen Katalogen zeigen ein Motiv auf weißer
 * Fläche, und der Rand ist selten mittig: Gemessen am Live-Bestand lagen bei
 * einem Bild 82 px links und 132 px rechts, bei einem anderen 109 px links und
 * 7 px rechts (2026-08-26). Im quadratischen Rahmen der Planseite sieht man
 * davon vor allem die leere Fläche — das Motiv wirkt klein und aus der Mitte
 * gerutscht.
 *
 * Geschnitten wird deshalb beim HOCHLADEN, nicht bei der Anzeige: Was einmal
 * weg ist, muss nicht bei jedem Seitenaufbau neu gerechnet werden, und beide
 * Größen (Vollbild und Thumbnail) entstehen ohnehin aus derselben Vorlage.
 *
 * **Bestehende Bilder bleiben unberührt.** Wer den neuen Zuschnitt für sie
 * will, lädt sie erneut hoch — ein Nachziehen über den Bestand hieße, fremde
 * Dateien ohne Rückfrage zu verändern.
 */

/**
 * Wie weit ein Pixel je Kanal von der Randfarbe abweichen darf.
 *
 * **8 und nicht mehr**, seit am 2026-08-26 aus dem Betrieb gemeldet wurde, dass
 * der Schnitt zu tief geht. Die Katalogbilder zeichnen Maschinen in sehr hellem
 * Grau; bei 14 ging ein Teil davon als Weiss durch. An einem Bild gemessen:
 * Mit 14 blieben 987 px Breite uebrig, mit 8 dagegen 1206 -- **219 px Motiv**,
 * das die groessere Toleranz verschluckt hat. Zwischen 8 und 4 liegen nur noch
 * 7 px; unter 8 zu gehen bringt also nichts und macht den Schnitt anfaellig
 * fuer JPEG-Rauschen.
 */
const BILD_RAND_TOLERANZ = 8;

/**
 * Sucht den Inhalt innerhalb eines einfarbigen Randes.
 *
 * **Bewusst ohne GD.** Die Funktion bekommt eine Ablesefunktion statt eines
 * Bildes — damit ist genau der Teil prüfbar, in dem die Entscheidungen
 * stecken, und zwar auf diesem Rechner, dem GD fehlt (siehe CLAUDE.md). Der
 * GD-Teil darüber ist dann nur noch Umschütten.
 *
 * Die Randfarbe kommt aus den VIER ECKEN, und sie müssen zusammenpassen:
 * Tun sie es nicht, hat das Bild keinen einfarbigen Rand (ein Foto etwa), und
 * es wird nichts geschnitten. Das ist die wichtigste Sicherung — ohne sie
 * würde an einem Farbverlauf ein beliebiger Streifen abgeschnitten.
 *
 * @param callable(int,int):array{0:int,1:int,2:int} $pixel liefert R,G,B
 * @return array{0:int,1:int,2:int,3:int,4:array{0:int,1:int,2:int}}|null
 *         [links, oben, breite, hoehe, randfarbe] oder null (nichts zu tun)
 */
function bild_inhalt_finden(
    callable $pixel,
    int $breite,
    int $hoehe,
    int $toleranz = BILD_RAND_TOLERANZ
): ?array {
    if ($breite < 8 || $hoehe < 8) {
        return null;
    }

    $ecken = [
        $pixel(0, 0),
        $pixel($breite - 1, 0),
        $pixel(0, $hoehe - 1),
        $pixel($breite - 1, $hoehe - 1),
    ];
    $rand = $ecken[0];
    foreach ($ecken as $ecke) {
        if (!farbe_nah($ecke, $rand, $toleranz)) {
            return null;
        }
    }

    $istRandZeile = static function (int $y) use ($pixel, $breite, $rand, $toleranz): bool {
        for ($x = 0; $x < $breite; $x++) {
            if (!farbe_nah($pixel($x, $y), $rand, $toleranz)) {
                return false;
            }
        }
        return true;
    };
    $istRandSpalte = static function (int $x) use ($pixel, $hoehe, $rand, $toleranz): bool {
        for ($y = 0; $y < $hoehe; $y++) {
            if (!farbe_nah($pixel($x, $y), $rand, $toleranz)) {
                return false;
            }
        }
        return true;
    };

    $oben = 0;
    while ($oben < $hoehe && $istRandZeile($oben)) {
        $oben++;
    }
    if ($oben >= $hoehe) {
        return null;   // vollständig einfarbig -- da ist kein Motiv
    }

    $unten = $hoehe - 1;
    while ($unten > $oben && $istRandZeile($unten)) {
        $unten--;
    }

    $links = 0;
    while ($links < $breite && $istRandSpalte($links)) {
        $links++;
    }

    $rechts = $breite - 1;
    while ($rechts > $links && $istRandSpalte($rechts)) {
        $rechts--;
    }

    $neuBreite = $rechts - $links + 1;
    $neuHoehe  = $unten - $oben + 1;

    // Nichts gewonnen -- und die Schwelle ist ANTEILIG, nicht ein fester
    // Pixelwert. Der Grund ist der Wiederholungslauf: Nach dem Schneiden wird
    // das Bild erneut als JPEG gespeichert, und die Kompression hellt die
    // aeusserste Pixelreihe eines harten Motivrandes so weit auf, dass sie
    // wieder in die Toleranz faellt. Beim zweiten Lauf wuerden also zwei Pixel
    // abgeschnitten, beim dritten wieder zwei -- am 2026-08-26 genau so
    // beobachtet (229x229 wurde zu 227x227). Ueber viele Laeufe fraesse sich
    // der Schnitt ins Motiv, und jeder Lauf kostete einen neuen Dateinamen und
    // eine weitere Enkodierung.
    //
    // Zwei Prozent der Kante, mindestens aber drei Pixel: Ein echter Rand liegt
    // weit darueber (gemessen: 8 bis 46 % der Kante), ein Nachzittern der
    // Kompression weit darunter. Damit ist der Lauf wiederholbar, und genau das
    // verspricht die Wartungsseite.
    $wegX = $breite - $neuBreite;
    $wegY = $hoehe  - $neuHoehe;
    $schwelleX = max(3, (int)round($breite * 0.02));
    $schwelleY = max(3, (int)round($hoehe  * 0.02));
    if ($wegX < $schwelleX && $wegY < $schwelleY) {
        return null;
    }

    // Notbremse gegen einen Fehlschnitt: Bleibt weniger als ein Fünftel der
    // Kante übrig, stimmt etwas nicht -- ein Wasserzeichen, ein Verlauf, ein
    // fast leeres Bild. Dann lieber gar nicht schneiden.
    if ($neuBreite * 5 < $breite || $neuHoehe * 5 < $hoehe) {
        return null;
    }

    return [$links, $oben, $neuBreite, $neuHoehe, $rand];
}

/** Zwei Farben, je Kanal höchstens $toleranz auseinander. */
function farbe_nah(array $a, array $b, int $toleranz): bool {
    return abs($a[0] - $b[0]) <= $toleranz
        && abs($a[1] - $b[1]) <= $toleranz
        && abs($a[2] - $b[2]) <= $toleranz;
}

/**
 * Der Rahmen, in dem ein zugeschnittenes Motiv landet.
 *
 * **Hochkant wird auf quadratisch AUFGEFÜLLT, quer bleibt quer.** Das ist die
 * ganze Fachlichkeit dieser Funktion, und sie beantwortet die Vorgabe „oben
 * und unten wird nie abgeschnitten, links und rechts darf" (Benutzer,
 * 2026-08-26) ohne eine einzige Zeile CSS:
 *
 * - Ein hochkantes Motiv wäre im quadratischen Rahmen der Anzeige oben und
 *   unten beschnitten. Aufgefüllt mit der Randfarbe ist es quadratisch, und
 *   `object-fit: cover` hat nichts mehr zu schneiden — das Motiv steht
 *   vollständig und mittig.
 * - Ein querformatiges bleibt, wie es ist; dort schneidet die Anzeige links
 *   und rechts, und `image_crop` sagt weiterhin, welche Seite stehen bleibt
 *   (§6.3, Fallstrick 16). Die Einstellung behält damit ihren Sinn.
 *
 * Aufgefüllt wird mit der Randfarbe und nicht mit Weiß: Bei einem Bild mit
 * hellgrauem Hintergrund wäre ein weißer Streifen sichtbar, die eigene
 * Randfarbe ist es nie.
 *
 * @return array{0:int,1:int,2:int,3:int} [rahmenBreite, rahmenHoehe, zielX, zielY]
 */
function bild_rahmen_quadrat(int $breite, int $hoehe): array {
    if ($hoehe <= $breite) {
        return [$breite, $hoehe, 0, 0];
    }

    return [$hoehe, $hoehe, intdiv($hoehe - $breite, 2), 0];
}

/**
 * Schneidet den einfarbigen Rand ab und füllt Hochkantiges auf quadratisch auf.
 *
 * Der GD-Teil, und bewusst der kleinere: Alles, was zu entscheiden ist, steckt
 * in bild_inhalt_finden() und bild_rahmen_quadrat() -- hier wird nur gelesen,
 * geschnitten und kopiert.
 *
 * **Gesucht wird auf einer verkleinerten Kopie, aber nur bei großen Bildern.**
 * imagecolorat() über ein 3000×2000-Bild sind sechs Millionen Aufrufe; bis
 * 1000 px Kante wird deshalb pixelgenau gesucht, darüber auf einer Kopie.
 *
 * **Die Kante war zuerst 200 px, und das war der zweite Grund für den zu tiefen
 * Schnitt** (2026-08-26): Ein Kopfscheitel oder das Ende einer Kurzhantel ist
 * dort ein Bruchteil eines Pixels und verschwindet im Mittelwert. Mit 1000 px
 * ist der Faktor bei den üblichen Bildern 1 — es wird also gar nicht mehr
 * gemittelt.
 *
 * **Und die Zugabe hängt jetzt am Faktor**, nicht mehr fest bei einem Pixel:
 * Ein Pixel der Suchkopie deckt `$faktor` Pixel des Originals ab, und genau so
 * viel kann der Mittelwert verstecken. Wer hier ein festes Maß einsetzt, holt
 * sich den Fehler zurück.
 *
 * **Gesucht wird auf WEISSEM Grund.** PNG und WebP können transparent sein;
 * `imagecolorat()` liefert dort Schwarz mit Alpha, und der Schnitt hielte eine
 * schwarze Hantel vor transparentem Grund für Hintergrund. Weiß ist außerdem
 * das, was `write_resized()` später ohnehin einsetzt — gesucht wird damit auf
 * genau dem Bild, das hinterher in der Datei steht.
 *
 * Bei jedem Zweifel bleibt das Bild, wie es ist: kein GD-Aufruf, der scheitern
 * könnte, ohne Rückfallweg. Ein nicht geschnittenes Bild ist ein Schönheits-
 * fehler, ein falsch geschnittenes ist Datenverlust.
 */
function bild_rand_schneiden(GdImage $src): array {
    $breite = imagesx($src);
    $hoehe  = imagesy($src);
    if ($breite < 16 || $hoehe < 16) {
        return [$src, [255, 255, 255]];
    }

    // Weisser Grund zum Suchen -- siehe oben. Scheitert das, wird auf dem
    // Original gesucht; bei einem JPEG ist das ohnehin dasselbe Bild.
    $flach = @imagecreatetruecolor($breite, $hoehe);
    if ($flach !== false) {
        $weiss = imagecolorallocate($flach, 255, 255, 255);
        imagefilledrectangle($flach, 0, 0, $breite - 1, $hoehe - 1, $weiss);
        imagecopy($flach, $src, 0, 0, 0, 0, $breite, $hoehe);
    }
    $grund = $flach !== false ? $flach : $src;

    // Verkleinerte Kopie nur zum Suchen, und nur wenn es sich lohnt.
    $kante = 1000;
    $faktor = 1.0;
    $probe = $grund;
    if (max($breite, $hoehe) > $kante) {
        $skaliert = @imagescale($grund, (int)round($breite * $kante / max($breite, $hoehe)));
        if ($skaliert !== false) {
            $probe  = $skaliert;
            $faktor = $breite / imagesx($probe);
        }
    }

    $lesen = static function (int $x, int $y) use ($probe): array {
        $farbe = imagecolorat($probe, $x, $y);
        return [($farbe >> 16) & 0xFF, ($farbe >> 8) & 0xFF, $farbe & 0xFF];
    };

    $fund = bild_inhalt_finden($lesen, imagesx($probe), imagesy($probe));

    if ($probe !== $grund) {
        imagedestroy($probe);
    }
    if ($flach !== false) {
        imagedestroy($flach);
    }
    if ($fund === null) {
        return [$src, [255, 255, 255]];
    }

    [$pLinks, $pOben, $pBreite, $pHoehe, $rand] = $fund;

    // Zurück auf die Originalmaße. Die Zugabe ist ein Pixel der SUCHKOPIE --
    // so viel kann deren Mittelwert verstecken (siehe oben).
    $zugabe = (int)ceil($faktor) + 1;
    $links  = max(0, (int)floor($pLinks * $faktor) - $zugabe);
    $oben   = max(0, (int)floor($pOben * $faktor) - $zugabe);
    $rechts = min($breite, (int)ceil(($pLinks + $pBreite) * $faktor) + $zugabe);
    $unten  = min($hoehe,  (int)ceil(($pOben  + $pHoehe)  * $faktor) + $zugabe);

    $geschnitten = @imagecrop($src, [
        'x' => $links, 'y' => $oben,
        'width' => $rechts - $links, 'height' => $unten - $oben,
    ]);
    if ($geschnitten === false) {
        return [$src, $rand];
    }

    imagedestroy($src);

    return [$geschnitten, $rand];
}

/**
 * Schreibt das Thumbnail -- und füllt ein hochkantes Motiv auf quadratisch auf.
 *
 * **Die Füllung gehört ins Thumbnail und NICHT in die gespeicherte Datei.**
 * Am 2026-08-26 stand sie zuerst im Bild selbst, und das machte den
 * Nachschnitt unbrauchbar: Der Detektor erkennt die aufgefüllten Streifen beim
 * nächsten Lauf völlig zu Recht als Rand, schneidet sie ab, füllt wieder auf --
 * und weil bei jeder Runde ein, zwei Pixel Zugabe verlorengehen, fräse sich
 * das über viele Läufe ins Motiv. Beobachtet an einem Bild: 229×229 wurde zu
 * 227×227, bei jedem Lauf aufs Neue.
 *
 * So herum stimmt beides: Das Vollbild trägt das nackte Motiv und ist damit
 * die verlässliche Vorlage für jede spätere Ableitung -- ein zweiter
 * Nachschnitt findet daran nichts mehr. Das Thumbnail trägt den Rahmen, den
 * die Anzeige braucht: quadratisch, damit `object-fit: cover` einem hochkanten
 * Motiv nicht Kopf und Füße abschneidet.
 *
 * Gefüllt wird mit der Randfarbe aus dem Schnitt, nicht mit Weiß -- bei einem
 * Bild auf hellgrauem Grund wäre ein weißer Streifen sichtbar.
 */
function write_thumb_quadrat(GdImage $src, string $target, int $maxEdge, array $fuellung): void {
    if (imagesy($src) <= imagesx($src)) {
        write_resized($src, $target, $maxEdge);
        return;
    }

    $hoehe  = imagesy($src);
    $breite = imagesx($src);
    [$rBreite, $rHoehe, $zielX, $zielY] = bild_rahmen_quadrat($breite, $hoehe);

    $rahmen = @imagecreatetruecolor($rBreite, $rHoehe);
    if ($rahmen === false) {
        write_resized($src, $target, $maxEdge);
        return;
    }

    $farbe = imagecolorallocate($rahmen, $fuellung[0], $fuellung[1], $fuellung[2]);
    imagefilledrectangle($rahmen, 0, 0, $rBreite - 1, $rHoehe - 1, $farbe);
    imagecopy($rahmen, $src, $zielX, $zielY, 0, 0, $breite, $hoehe);

    try {
        write_resized($rahmen, $target, $maxEdge);
    } finally {
        imagedestroy($rahmen);
    }
}

/**
 * --- Bestandsbilder nachschneiden (§6.5) ---------------------------------
 *
 * Der Randschnitt aus 1.2.21 greift beim HOCHLADEN. Was vorher im Bestand
 * liegt, behaelt seinen Rand -- und niemand laedt sechzehn Bilder von Hand
 * neu hoch, um ihn loszuwerden. Diese beiden Funktionen holen das nach.
 *
 * **Die neue Datei bekommt einen NEUEN NAMEN, und das ist der Kern.**
 * image.php liefert jedes Bild mit `Cache-Control: private, max-age=31536000,
 * immutable` aus -- ein Jahr, und `immutable` heisst: Der Browser fragt gar
 * nicht erst nach. Der Dateiname ist der einzige Schluessel. Wer dieselbe
 * Datei ueberschreibt, sieht ein Jahr lang das alte Bild, ohne dass irgendetwas
 * kaputt aussieht. Dieselbe Mechanik wie beim eingefrorenen Asset-Cache
 * (Fallstrick 12): Ein Reparaturweg, der durch die kaputte Ebene laeuft, ist
 * keiner.
 *
 * Damit ist es keine reine Dateiaktion mehr: exercises.image_path muss
 * mitwandern. Die Reihenfolge ist dieselbe wie beim Bildwechsel im Formular
 * (api/exercises.php): erst schreiben, dann die Datenbank, und **danach** die
 * alte Datei loeschen. Solange der Schreibvorgang scheitern kann, bleibt das
 * alte Bild in Gebrauch.
 */

/**
 * Was ein Nachschnitt aendern wuerde -- ohne irgendetwas zu aendern.
 *
 * Gerechnet wird mit **derselben** Funktion, die auch der echte Lauf benutzt
 * (bild_rand_schneiden()), und das Ergebnis danach verworfen. Eine zweite,
 * schlankere Vorschaurechnung waere schneller und irgendwann falsch: Sie
 * koennte von der echten abweichen, und dann verspraeche die Vorschau etwas,
 * das der Lauf nicht haelt.
 *
 * @return array{gesamt:int,betroffen:int,fehlend:int,liste:list<array{id:int,name:string,vorher:string,nachher:string}>}
 */
function bestandsbilder_pruefen(): array {
    $gesamt   = 0;
    $fehlend  = 0;
    $liste    = [];

    foreach (bestandsbilder_zeilen() as $zeile) {
        $gesamt++;
        $pfad = uploads_path() . '/' . $zeile['image_path'];
        if (!is_file($pfad)) {
            $fehlend++;
            continue;
        }

        $src = @imagecreatefromjpeg($pfad);
        if ($src === false) {
            $fehlend++;
            continue;
        }

        $vorherW = imagesx($src);
        $vorherH = imagesy($src);
        [$src] = bild_rand_schneiden($src);
        $nachW = imagesx($src);
        $nachH = imagesy($src);
        imagedestroy($src);

        if ($nachW === $vorherW && $nachH === $vorherH) {
            continue;
        }

        $liste[] = [
            'id'      => (int)$zeile['id'],
            'name'    => (string)$zeile['name_de'],
            'vorher'  => $vorherW . '×' . $vorherH,
            'nachher' => $nachW . '×' . $nachH,
        ];
    }

    return [
        'gesamt'   => $gesamt,
        'betroffen' => count($liste),
        'fehlend'  => $fehlend,
        'liste'    => $liste,
    ];
}

/**
 * Schneidet die Bestandsbilder nach.
 *
 * **Wiederholbar, und er kommt zur Ruhe.** Ein zweiter Lauf ueberspringt fast
 * alles; gemessen an 17 Bildern zog er genau eines noch um wenige Pixel nach,
 * der dritte fand nichts mehr. Der Grund fuer das Nachzittern ist das erneute
 * Speichern als JPEG: Es hellt die aeusserste Pixelreihe eines harten
 * Motivrandes leicht auf, und dann liegt sie wieder in der Toleranz. Dass das
 * ENDET und nicht ins Motiv frisst, haengt an zwei Dingen: Jeder Lauf kann nur
 * verkleinern, und er wird nur taetig, wenn mindestens die anteilige Schwelle
 * aus bild_inhalt_finden() zusammenkommt. Beides zusammen laesst sich nicht
 * beliebig oft wiederholen.
 *
 * Scheitert eine einzelne Uebung, werden ihre halbfertigen Dateien wieder
 * entfernt und der Lauf geht weiter. Ein Bild, das sich nicht schneiden laesst,
 * darf die anderen fuenfzehn nicht aufhalten.
 *
 * @return array{geaendert:int,uebersprungen:int,fehlgeschlagen:int,bytes:int}
 */
function bestandsbilder_nachschneiden(): array {
    $geaendert      = 0;
    $uebersprungen  = 0;
    $fehlgeschlagen = 0;
    $bytes          = 0;

    $stmt = db()->prepare('UPDATE exercises SET image_path = ? WHERE id = ?');

    foreach (bestandsbilder_zeilen() as $zeile) {
        $alt  = (string)$zeile['image_path'];
        $pfad = uploads_path() . '/' . $alt;
        if (!is_file($pfad)) {
            $fehlgeschlagen++;
            continue;
        }

        $src = @imagecreatefromjpeg($pfad);
        if ($src === false) {
            $fehlgeschlagen++;
            continue;
        }

        $vorherW = imagesx($src);
        $vorherH = imagesy($src);
        [$src, $randfarbe] = bild_rand_schneiden($src);

        if (imagesx($src) === $vorherW && imagesy($src) === $vorherH) {
            imagedestroy($src);
            $uebersprungen++;
            continue;
        }

        $vorherBytes = (int)@filesize($pfad)
            + (int)@filesize(uploads_path() . '/' . substr($alt, 0, 32) . '_thumb.jpg');

        $basis = bin2hex(random_bytes(16));
        $neu   = $basis . '.jpg';
        $thumb = $basis . '_thumb.jpg';

        try {
            write_resized($src, uploads_path() . '/' . $neu, UPLOAD_MAX_EDGE);
            write_thumb_quadrat($src, uploads_path() . '/' . $thumb, UPLOAD_THUMB_EDGE, $randfarbe);
            $stmt->execute([$neu, (int)$zeile['id']]);
        } catch (Throwable $e) {
            // Halbfertiges wieder weg -- die Uebung zeigt noch auf das alte Bild.
            delete_exercise_image($neu);
            $fehlgeschlagen++;
            continue;
        } finally {
            imagedestroy($src);
        }

        // Erst jetzt, und keinen Schritt frueher: Bis hierher war das alte Bild
        // das einzige, auf das die Datenbank zeigte.
        delete_exercise_image($alt);

        $nachherBytes = (int)@filesize(uploads_path() . '/' . $neu)
            + (int)@filesize(uploads_path() . '/' . $thumb);

        // VORZEICHENBEHAFTET, und das ist Absicht: Ein zugeschnittenes Bild wird
        // nicht zwangslaeufig kleiner. Weisse Flaeche komprimiert sich fast zu
        // nichts; faellt sie weg, steht im Thumbnail mehr Motiv auf derselben
        // Kantenlaenge, und die Datei waechst. Beim ersten Lauf ueber 17
        // Testbilder waren es 17 KB mehr. Nur die Ersparnisse zu zaehlen ergaebe
        // eine Zahl, die immer nach Gewinn aussieht -- und dann meldet die Seite
        // "5,7 KB gespart", waehrend das Verzeichnis waechst.
        $bytes += $vorherBytes - $nachherBytes;
        $geaendert++;
    }

    return [
        'geaendert'      => $geaendert,
        'uebersprungen'  => $uebersprungen,
        'fehlgeschlagen' => $fehlgeschlagen,
        'bytes'          => $bytes,
    ];
}

/**
 * Die Uebungen mit Bild, in einer Reihenfolge, die sich nicht verschiebt.
 *
 * Archivierte sind ausdruecklich DABEI: Ihr Bild liegt genauso im
 * Verzeichnis, und wer sie reaktiviert, soll nicht das einzige ungeschnittene
 * Bild im Bestand vor sich haben.
 *
 * @return list<array{id:int,name_de:string,image_path:string}>
 */
function bestandsbilder_zeilen(): array {
    return db()->query(
        "SELECT id, name_de, image_path
           FROM exercises
          WHERE image_path IS NOT NULL AND image_path <> ''
          ORDER BY id"
    )->fetchAll();
}
