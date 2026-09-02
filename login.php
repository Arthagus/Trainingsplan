<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();

// Hier steht bewusst kein require_login() -- das ist die Seite, auf die es
// umleitet. Wer schon angemeldet ist, hat hier nichts verloren.
if (is_logged_in()) {
    redirect('index.php');
}

$pageTitle = 'Anmelden';
$showNav   = false;
require __DIR__ . '/lib/view_header.php';
?>

<form id="anmelde-formular" class="karte" autocomplete="on" novalidate>
    <p id="anmelde-fehler" class="feld-fehler" role="alert" hidden></p>

    <label for="name">Benutzername</label>
    <?php // KEIN autofocus -- der Fokus wird in login.js gesetzt, und nur am
          // Zeigegeraet. Firefox auf Android stellt fuer ein Feld, das schon
          // beim Laden den Fokus hat, gar keine Autofill-Anfrage: Der
          // Passwortmanager bekommt nie eine Gelegenheit, sich zu melden.
          // Gemeldet am 2026-09-02 (Proton Pass auf einem Pixel 10) -- es kam
          // nicht einmal das Symbol ueber der Tastatur, waehrend es auf
          // anderen Seiten erscheint. Am Desktop faellt es nicht auf, weil
          // dort die Erweiterung ins DOM sieht statt am Fokus zu haengen. ?>
    <input type="text" id="name" name="name" autocomplete="username"
           autocapitalize="none" autocorrect="off" required>
    <p class="feld-fehler" data-fehler-fuer="name" hidden></p>

    <label for="password">Passwort</label>
    <input type="password" id="password" name="password"
           autocomplete="current-password" required>
    <p class="feld-fehler" data-fehler-fuer="password" hidden></p>

    <?php if (remember_me_available()): ?>
        <label class="zeile-wahl">
            <input type="checkbox" id="remember" name="remember" checked>
            Angemeldet bleiben
        </label>
        <p class="matt">
            Auf diesem Gerät bleibt die Anmeldung 90 Tage bestehen. Über
            „Geräte“ lässt sie sich jederzeit widerrufen.
        </p>
    <?php else: ?>
        <p class="matt">
            „Angemeldet bleiben“ steht nicht zur Verfügung, weil
            <code>APP_SECRET</code> nicht gesetzt ist.
        </p>
    <?php endif; ?>

    <p>
        <button type="submit" id="anmelden">Anmelden</button>
    </p>
</form>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
