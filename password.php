<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/training.php';

bootstrap_session();
require_login();

$benutzer = current_user();
$erzwungen = (int)($benutzer['must_change_password'] ?? 0) === 1;
$experte   = (int)($benutzer['expert_mode'] ?? 0) === 1;

// Umschalten geht nur ausserhalb eines Trainings (§7.4) -- die Begruendung
// steht in api/auth.php. Hier wird der Schalter deshalb gar nicht erst
// bedienbar angeboten: Ein Knopf, der sicher in ein 409 laeuft, waere unehrlich.
$laeuftEinheit = $erzwungen ? false : offene_einheit((int)$benutzer['id']) !== null;

$pageTitle = 'Konto';
require __DIR__ . '/lib/view_header.php';
?>

<?php if ($erzwungen): ?>
    <div class="karte hinweis-warnung">
        <strong>Bitte zuerst ein eigenes Passwort setzen.</strong>
        <p class="matt">
            Dieses Konto wurde mit einem Startpasswort angelegt. Bis es geändert
            ist, sind die übrigen Seiten gesperrt.
        </p>
    </div>
<?php endif; ?>

<?php // Der Benutzername steht bewusst NACH dem Passwort-Abschnitt, solange
      // ein Wechsel erzwungen ist -- dann entfällt er ganz. Bis das eigene
      // Passwort steht, ist diese Seite die einzige erreichbare (§7.1), und
      // dort gehört genau eine Aufgabe hin. ?>
<h2>Passwort ändern</h2>

<form id="passwort-formular" class="karte" autocomplete="on" novalidate>
    <p id="passwort-fehler" class="feld-fehler" role="alert" hidden></p>

    <label for="current">Aktuelles Passwort</label>
    <input type="password" id="current" name="current"
           autocomplete="current-password" required autofocus>
    <p class="feld-fehler" data-fehler-fuer="current" hidden></p>

    <label for="new">Neues Passwort</label>
    <input type="password" id="new" name="new" autocomplete="new-password" required>
    <p class="matt">Mindestens 8 Zeichen.</p>
    <p class="feld-fehler" data-fehler-fuer="new" hidden></p>

    <label for="new_repeat">Neues Passwort wiederholen</label>
    <input type="password" id="new_repeat" name="new_repeat"
           autocomplete="new-password" required>
    <p class="feld-fehler" data-fehler-fuer="new_repeat" hidden></p>

    <p class="matt">
        Nach der Änderung werden alle anderen Geräte abgemeldet; dieses bleibt
        angemeldet.
    </p>

    <p>
        <button type="submit" id="speichern">Passwort ändern</button>
    </p>
</form>

<?php // Solange das Startpasswort gilt, ist dieser Abschnitt gar nicht da.
      // api/auth.php lehnt die Aktion ohnehin ab (require_passwort_gesetzt_api),
      // aber ein Formular anzubieten, das sicher scheitert, wäre unehrlich. ?>
<?php if (!$erzwungen): ?>

    <h2>Benutzername ändern</h2>

    <form id="name-formular" class="karte" autocomplete="off" novalidate>
        <p id="name-fehler" class="feld-fehler" role="alert" hidden></p>

        <p class="matt">
            Aktuell: <strong><?= h((string)$benutzer['name']) ?></strong>
        </p>

        <label for="new_name">Neuer Benutzername</label>
        <?php // autocapitalize aus: Am Handy macht die Tastatur sonst aus
              // „oliver" ein „Oliver" — und das wäre ein anderer Name. ?>
        <input type="text" id="new_name" name="new_name" autocapitalize="none"
               autocorrect="off" spellcheck="false"
               maxlength="<?= BENUTZER_NAME_MAX ?>"
               value="<?= h((string)$benutzer['name']) ?>" required>
        <p class="matt">Höchstens <?= BENUTZER_NAME_MAX ?> Zeichen.</p>
        <p class="feld-fehler" data-fehler-fuer="new_name" hidden></p>

        <label for="name_password">Aktuelles Passwort</label>
        <input type="password" id="name_password" name="name_password"
               autocomplete="current-password" required>
        <p class="matt">
            Zur Bestätigung nötig: Der Benutzername ist Ihre Anmeldekennung.
        </p>
        <p class="feld-fehler" data-fehler-fuer="name_password" hidden></p>

        <p class="matt">
            <strong>Sie melden sich danach mit dem neuen Namen an.</strong>
            Pläne, Trainingsverlauf und angemeldete Geräte bleiben unberührt —
            es ändert sich nur die Anzeige und die Anmeldung.
        </p>

        <p>
            <button type="submit" id="name-speichern">Benutzernamen ändern</button>
        </p>
    </form>

    <h2>Trainingsansicht</h2>

    <div class="karte" id="experte-karte">
        <p id="experte-fehler" class="feld-fehler" role="alert" hidden></p>

        <label class="zeile-wahl">
            <input type="checkbox" id="experte" <?= $experte ? 'checked' : '' ?>
                   <?= $laeuftEinheit ? 'disabled' : '' ?>>
            Sätze einzeln erfassen (Expertenmodus)
        </label>

        <p class="matt">
            Statt einem Gewicht je Übung wird jeder Satz mit Wiederholungen und
            Gewicht eingetragen — etwa 12×40, 10×40, 9×45. Beim Hinzufügen eines
            Satzes steht schon drin, was du beim letzten Mal gemacht hast.
        </p>
        <p class="matt">
            Bereits protokollierte Trainings bleiben erhalten und lesbar. Im
            Verlauf steht als Gewicht einer Übung weiterhin eine Zahl — im
            Expertenmodus der schwerste Satz.
        </p>

        <?php if ($laeuftEinheit): ?>
            <p class="hinweis-warnung">
                <strong>Gerade läuft ein Training.</strong>
                Umschalten geht erst, wenn es beendet ist — sonst gingen Werte
                verloren, die noch auf dem Weg zum Server sind.
            </p>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
