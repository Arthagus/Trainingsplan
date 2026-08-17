<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

/**
 * Kopf jeder Seite. Bewusst ein Partial und nicht in jede Datei kopiert --
 * das ist die Abweichung von Body-Fat-Tracker und Speisekarte, die ihren
 * <head> und ihre Navigation duplizieren.
 *
 * Erwartet optional vor dem Einbinden:
 *   $pageTitle  string  Titel in der Titelleiste und Kopfzeile
 *   $showNav    bool    Navigation anzeigen (Standard: nur wenn angemeldet)
 */

$pageTitle = $pageTitle ?? 'Trainingsplan';
$user      = current_user();
$showNav   = $showNav ?? ($user !== null);
$base      = base_path();
$aktiv     = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

/**
 * Rendert einen Navigationspunkt und hebt den aktuellen hervor.
 *
 * $geplant kennzeichnet Seiten, die es zwar gibt, die aber nur ein Platzhalter
 * sind. Sie stehen bewusst im Menue: So ist auf einen Blick sichtbar, was noch
 * aussteht -- ein Punkt, der ins Leere fuehrt, waere dagegen schlimmer als
 * keiner.
 */
function nav_item(
    string $datei,
    string $beschriftung,
    string $aktiv,
    string $base,
    bool $geplant = false
): string {
    $klassen = [];
    if ($datei === $aktiv) { $klassen[] = 'aktiv'; }
    if ($geplant)          { $klassen[] = 'geplant'; }

    $attr = $klassen === [] ? '' : ' class="' . implode(' ', $klassen) . '"';
    $titel = $geplant ? ' title="Noch nicht umgesetzt"' : '';

    return '<a href="' . h($base . '/' . $datei) . '"' . $attr . $titel . '>'
         . h($beschriftung) . ($geplant ? ' <span aria-hidden="true">·</span>' : '')
         . '</a>';
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<!-- viewport-fit und user-scalable: die Handy-Ansicht ist der Hauptfall. -->
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="theme-color" content="#1f2933">
<title><?= h($pageTitle) ?> — Trainingsplan</title>
<link rel="manifest" href="<?= h($base) ?>/assets/manifest.json">
<link rel="icon" href="<?= h($base) ?>/assets/icon-192.png">
<link rel="apple-touch-icon" href="<?= h($base) ?>/assets/icon-192.png">
<?php // Die Versionsnummer haengt an JEDER Asset-URL, und das ist keine Kosmetik.
      //
      // Bis 1.1.7 hiess die Datei immer "assets/style.css". Eine Adresse, die
      // sich nie aendert, ist fuer jeden Cache dieselbe Datei -- und davon gibt
      // es ZWEI hintereinander: den Service-Worker-Cache und den HTTP-Cache des
      // Browsers. Das Hochzaehlen von CACHE in sw.js erreicht nur den ersten.
      //
      // Genau daran ist 1.1.7 am Handy gescheitert: Der Server liefert kein
      // Cache-Control, also darf der Browser heuristisch cachen (ueblich sind
      // 10 % der Zeit seit Last-Modified). cache.addAll() im Service Worker
      // laeuft durch diesen HTTP-Cache -- der frische v20-Cache wurde also mit
      // der ALTEN style.css befuellt, und activate() loeschte v19 hinterher.
      // Ergebnis: neues HTML, altes Stylesheet, und kein Weg zurueck ausser
      // Cache von Hand leeren.
      //
      // Mit ?v=1.1.8 ist es eine ANDERE Adresse. Ein alter Eintrag kann gar
      // nicht mehr getroffen werden, in keinem der beiden Caches. Das ist die
      // einzige Loesung, die nicht davon abhaengt, dass sich jemand richtig
      // verhaelt.
      $v = app_version(); ?>
<link rel="stylesheet" href="<?= h($base) ?>/assets/style.css?v=<?= h($v) ?>">
<script src="<?= h($base) ?>/assets/app.js?v=<?= h($v) ?>" defer></script>
</head>
<body>

<?php // Der gemeinsame Behaelter fuer ALLE Leisten, die oben kleben sollen:
      // die Trainingsleiste (nur index.php bei laufender Einheit) und darunter
      // die Verbindungsleiste (aus assets/app.js, auf jeder Seite).
      //
      // Die REIHENFOLGE ist Fachlichkeit, nicht Geschmack: Was dauerhaft
      // dasteht, gehoert nach oben; was im Sekundentakt kommt und geht, nach
      // unten. Andersherum -- so war es in 1.1.14 -- schiebt die kurz
      // aufblitzende Verbindungsleiste bei JEDEM Abhaken die Trainingsleiste
      // nach unten und gleich wieder hinauf. Wer also eine weitere Leiste
      // ergaenzt, sortiert sie nach Bestaendigkeit ein.
      //
      // Der Stapel ist sticky, die Leisten darin sind es NICHT. Zwei Elemente
      // mit `top: 0` legen sich sonst uebereinander, und ein fester Versatz
      // fuer die zweite waere falsch: Die Verbindungsleiste ist meistens gar
      // nicht da und kann auf schmalen Geraeten zweizeilig werden.
      //
      // Der eigentliche Gewinn steckt aber woanders: zurAktivenSpringen() in
      // index.js muss beim Weiterspringen die Hoehe dessen abziehen, was oben
      // klebt (Fallstrick 19). Mit dem Stapel ist das EINE Messung, die von
      // selbst stimmt -- egal wie viele Leisten gerade sichtbar sind. Vorher
      // war es eine feste Liste, und die haette man beim Ergaenzen vergessen.
      //
      // Steht vor <header>, weil der Kopf mitscrollt und die Leisten nicht. ?>
<div id="leisten" class="leisten-stapel"><?= $leisteOben ?? '' ?></div>

<?php // Symbolvorrat und Beschriftungen der Trainingsgeraete. Steht hier und
      // nicht je Seite, weil ihn drei Seiten brauchen und ein <use href="#...">
      // nur innerhalb desselben Dokuments wirkt. ?>
<?php require __DIR__ . '/view_geraet_symbole.php'; ?>

<?php if ($showNav): ?>
<header class="kopf">
    <?php // Der Benutzername steht in der Marke ganz links, nicht zwischen
          // Navigation und Kontolinks: dort ging er zwischen den Menuepunkten
          // unter und wirkte wie einer von ihnen. ?>
    <a class="marke" href="<?= h($base) ?>/index.php">Trainingsplan<?php
        if ($user !== null) {
            echo ' für <span class="marke-name">' . h((string)$user['name']) . '</span>';
        }
    ?></a>
    <nav class="haupt-nav">
        <?= nav_item('index.php', 'Training', $aktiv, $base) ?>
        <?= nav_item('history.php', 'Verlauf', $aktiv, $base) ?>
        <?php if ($user !== null && (int)$user['is_admin'] === 1): ?>
            <?= nav_item('admin_plans.php', 'Pläne', $aktiv, $base) ?>
            <?= nav_item('admin_exercises.php', 'Übungen', $aktiv, $base) ?>
            <?= nav_item('admin_muscle_groups.php', 'Muskelgruppen', $aktiv, $base) ?>
            <?= nav_item('admin_users.php', 'Benutzer', $aktiv, $base) ?>
            <?= nav_item('maintenance.php', 'Wartung', $aktiv, $base) ?>
        <?php endif; ?>
    </nav>
    <div class="konto">
        <?php if ($user !== null): ?>
            <?= nav_item('devices.php', 'Geräte', $aktiv, $base) ?>
            <?php // "Konto" statt "Passwort": Die Seite trägt seit dem
                  // Benutzernamen-Wechsel zwei Aufgaben (§7.7). ?>
            <?= nav_item('password.php', 'Konto', $aktiv, $base) ?>
            <a href="<?= h($base) ?>/logout.php">Abmelden</a>
        <?php endif; ?>
    </div>
</header>
<?php endif; ?>

<main class="inhalt">
<h1><?= h($pageTitle) ?></h1>
