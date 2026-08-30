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

// Die Geraeteverwaltung (§7.7) lag bis 1.2.2 auf einer eigenen Seite
// devices.php mit eigenem Menuepunkt. Sie steht jetzt hier: Es ist dieselbe
// Frage -- was gehoert zu meinem Konto --, und die Kopfzeile traegt am Handy
// nur, was man beim Trainieren braucht.
$geraete = [];
if (!$erzwungen) {
    purge_expired_remember_tokens();

    $stmt = db()->prepare(
        'SELECT id, selector, created_at, last_used_at, expires_at, user_agent
           FROM remember_tokens
          WHERE user_id = ?
          ORDER BY COALESCE(last_used_at, created_at) DESC'
    );
    $stmt->execute([(int)$benutzer['id']]);
    $geraete = $stmt->fetchAll();
}

// Das aktuelle Geraet erkennt man am Selector im eigenen Cookie.
$eigenerSelector = explode(':', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''), 2)[0];

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

    <?php // Bis 1.4.2 stand hier zusaetzlich der Schalter "Saetze einzeln
          // erfassen (Expertenmodus)" und diese Auswahl war ohne ihn
          // abgeblendet. Den einfachen Modus gibt es nicht mehr (§7.4) --
          // damit ist die Vorbelegung die einzige Einstellung, die diese
          // Ueberschrift noch traegt, und sie gilt immer.
          //
          // Die Karte bleibt trotzdem eine Karte und die Ueberschrift steht
          // ueber ihr: Kommt hier je eine zweite Einstellung dazu, ist der
          // Platz da, und die Gliederung ist dieselbe wie auf allen anderen
          // Seiten. ?>
    <div class="karte" id="vorlage-karte">
        <fieldset class="vorlage-wahl">
            <legend>Vorbelegung neuer Sätze</legend>

            <p class="matt">
                Tippst du auf „+ Satz“, steht schon etwas in den Feldern. Woher
                dieser Vorschlag kommt, wählst du hier.
            </p>

            <p class="matt vorlage-beispiel">
                Beispiel — letztes Training dieser Übung:
                <strong>12×40 · 10×40 · 9×45</strong>
            </p>

            <?php foreach (SATZ_VORLAGE as $schluessel => $beschriftung): ?>
                <?php
                // Die Beispielwerte je Verfahren. Sie stehen hier und nicht in
                // der Codeliste: Die Liste beantwortet "welche Werte gibt es",
                // nicht "wie erklaert man sie".
                $beispiel = $schluessel === 'letzter_satz'
                    ? ['12×40', '12×40', '12×40']
                    : ['12×40', '10×40', '9×45'];
                ?>
                <label class="zeile-wahl vorlage-zeile">
                    <input type="radio" name="satz_vorlage" value="<?= h($schluessel) ?>"
                           <?= satz_vorlage_normalisieren($benutzer['satz_vorlage'] ?? null) === $schluessel ? 'checked' : '' ?>>
                    <span>
                        <strong><?= h($beschriftung) ?></strong>
                        <span class="vorlage-saetze">
                            <?php foreach ($beispiel as $i => $wert): ?>
                                <span><span class="matt">Satz <?= $i + 1 ?></span> <?= h($wert) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </span>
                </label>
            <?php endforeach; ?>

            <p class="matt">
                Der erste Satz kommt in beiden Fällen vom letzten Training — der
                Unterschied beginnt ab Satz 2. „Wie der Satz davor“ übernimmt
                immer, was du gerade eingetragen hast: Korrigierst du Satz 2 auf
                10×40, schlägt Satz 3 ebenfalls 10×40 vor.
            </p>

            <p id="vorlage-fehler" class="feld-fehler" role="alert" hidden></p>
        </fieldset>

    </div>

    <h2>Geräte</h2>

    <p class="matt">
        Hier stehen die Geräte, auf denen „Angemeldet bleiben“ aktiv ist. Ein
        abgemeldetes Gerät verlangt beim nächsten Aufruf wieder das Passwort.
    </p>

    <?php if ($geraete === []): ?>
        <div class="karte">
            <p>Kein Gerät ist dauerhaft angemeldet.</p>
            <p class="matt">
                Beim nächsten Anmelden lässt sich „Angemeldet bleiben“ ankreuzen —
                dann erscheint das Gerät hier.
            </p>
        </div>
    <?php else: ?>
        <ul id="geraete-liste" class="liste-schlicht">
            <?php foreach ($geraete as $g): ?>
                <?php $eigenes = $eigenerSelector !== ''
                                 && hash_equals((string)$g['selector'], $eigenerSelector); ?>
                <li class="karte" data-token="<?= (int)$g['id'] ?>">
                    <div class="geraet-kopf">
                        <strong><?= h(geraete_bezeichnung($g['user_agent'])) ?></strong>
                        <?php if ($eigenes): ?>
                            <span class="abzeichen">dieses Gerät</span>
                        <?php endif; ?>
                    </div>
                    <p class="matt">
                        Angemeldet am <?= h(format_datetime($g['created_at'])) ?>
                        <?php if (!empty($g['last_used_at'])): ?>
                            · zuletzt genutzt <?= h(format_datetime($g['last_used_at'])) ?>
                        <?php endif; ?>
                        · gültig bis <?= h(format_datetime($g['expires_at'])) ?>
                    </p>
                    <p>
                        <button type="button" class="leise geraet-abmelden">
                            <?= $eigenes ? 'Dieses Gerät abmelden' : 'Abmelden' ?>
                        </button>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>

        <p>
            <button type="button" id="alle-abmelden" class="gefahr">
                Auf allen Geräten abmelden
            </button>
        </p>
    <?php endif; ?>

<?php endif; ?>

<?php // Abmelden steht AUSSERHALB des !$erzwungen-Blocks, und das ist kein
      // Versehen: Wer ein Startpasswort hat, kommt auf keine andere Seite --
      // ohne diesen Link säße er fest, seit der Punkt nicht mehr in der
      // Kopfzeile steht. Weiter unten als alles andere, weil man sich nicht
      // ständig abmeldet; als leiser Knopf, nicht als Gefahr — es geht nichts
      // verloren. ?>
<h2>Abmelden</h2>

<div class="karte">
    <p class="matt">
        Meldet dich auf <em>diesem</em> Gerät ab. „Angemeldet bleiben“ wird dabei
        für dieses Gerät widerrufen; andere Geräte bleiben angemeldet.
    </p>
    <p>
        <a class="knopf zweit" href="<?= h(base_path()) ?>/logout.php">Abmelden</a>
    </p>
</div>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
