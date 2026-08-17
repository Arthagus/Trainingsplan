<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Benutzerverwaltung (§6.1).
 */

$benutzer = db()->query(
    'SELECT u.id, u.name, u.is_admin, u.must_change_password, u.blocked_at, u.created_at,
            (SELECT COUNT(*) FROM plans p            WHERE p.user_id = u.id) AS plaene,
            (SELECT COUNT(*) FROM sessions s         WHERE s.user_id = u.id) AS einheiten,
            (SELECT COUNT(*) FROM sessions s         WHERE s.user_id = u.id AND s.ended_at IS NULL) AS offen,
            (SELECT COUNT(*) FROM remember_tokens rt WHERE rt.user_id = u.id) AS geraete
       FROM users u
      ORDER BY u.name'
)->fetchAll();

$adminAnzahl = 0;
foreach ($benutzer as $b) {
    $adminAnzahl += (int)$b['is_admin'];
}

$pageTitle = 'Benutzer';
require __DIR__ . '/lib/view_header.php';
?>

<p class="matt">
    Es gibt keine Selbstregistrierung — Konten entstehen ausschließlich hier.
    Ein neu angelegtes oder zurückgesetztes Passwort muss der Benutzer beim
    ersten Login selbst ändern.
</p>

<p class="matt">
    <strong>Sperren statt löschen:</strong> Ein gesperrtes Konto kommt nicht
    mehr herein — weder über das Passwort noch über ein angemeldetes Gerät —,
    behält aber Pläne, Verlauf und Protokoll vollständig. Ein Entsperren stellt
    den vorherigen Zustand wieder her; nur anmelden muss sich der Benutzer neu.
    Das eigene Konto ist davon ausgenommen.
</p>

<details class="karte" id="neu-bereich">
    <summary class="summary-knopf">Neuen Benutzer anlegen</summary>

    <form id="neu-formular" novalidate>
        <p id="neu-fehler" class="feld-fehler" role="alert" hidden></p>

        <label for="name">Benutzername</label>
        <input type="text" id="name" name="name" autocapitalize="none" required>
        <p class="feld-fehler" data-fehler-fuer="name" hidden></p>

        <label for="password">Startpasswort</label>
        <input type="text" id="password" name="password" autocomplete="off" required>
        <p class="matt">
            Mindestens 8 Zeichen. Bewusst im Klartext sichtbar — es muss
            weitergegeben werden und wird beim ersten Login ohnehin ersetzt.
        </p>
        <p class="feld-fehler" data-fehler-fuer="password" hidden></p>

        <label class="zeile-wahl">
            <input type="checkbox" id="is_admin" name="is_admin">
            Darf verwalten (Admin)
        </label>

        <p><button type="submit">Benutzer anlegen</button></p>
    </form>
</details>

<ul id="benutzer-liste" class="liste-schlicht" data-admins="<?= $adminAnzahl ?>">
    <?php foreach ($benutzer as $b): ?>
        <?php
        $id          = (int)$b['id'];
        $istAdmin    = (int)$b['is_admin'] === 1;
        $selbst      = $id === current_user_id();
        $letzterAdmin = $istAdmin && $adminAnzahl <= 1;
        $einheiten   = (int)$b['einheiten'];
        $gesperrt    = $b['blocked_at'] !== null;
        ?>
        <li class="karte benutzer<?= $gesperrt ? ' ist-gesperrt' : '' ?>"
            data-id="<?= $id ?>" data-einheiten="<?= $einheiten ?>"
            data-gesperrt="<?= $gesperrt ? '1' : '0' ?>">
            <div class="gruppe-zeile">
                <div class="gruppe-felder">
                    <strong>
                        <?= h((string)$b['name']) ?>
                        <?php if ($selbst): ?><span class="abzeichen">Sie</span><?php endif; ?>
                        <?php if ($istAdmin): ?><span class="abzeichen abzeichen-admin">Admin</span><?php endif; ?>
                        <?php if ($gesperrt): ?>
                            <span class="abzeichen abzeichen-gesperrt">Gesperrt</span>
                        <?php endif; ?>
                        <?php if ((int)$b['must_change_password'] === 1): ?>
                            <span class="abzeichen abzeichen-archiv">Passwortwechsel offen</span>
                        <?php endif; ?>
                    </strong>
                    <p class="matt">
                        <?= (int)$b['plaene'] ?> Plan/Pläne ·
                        <?= $einheiten ?> Einheit<?= $einheiten === 1 ? '' : 'en' ?>
                        <?php if ((int)$b['offen'] > 0): ?>
                            (davon eine <strong>offen</strong>)
                        <?php endif; ?>
                        · <?= (int)$b['geraete'] ?> angemeldete(s) Gerät(e)
                        · seit <?= h(format_datetime($b['created_at'])) ?>
                        <?php if ($gesperrt): ?>
                            <br><strong>Gesperrt seit <?= h(format_datetime($b['blocked_at'])) ?></strong>
                            — Daten bleiben vollständig erhalten.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="gruppe-knoepfe">
                    <?php // Umbenennen ist auch fuer das eigene Konto erlaubt: Man
                          // kennt den neuen Namen und sperrt sich damit nicht aus --
                          // anders als beim Loeschen und beim Adminrecht. ?>
                    <button type="button" class="leise umbenennen">Umbenennen</button>
                    <button type="button" class="leise zuruecksetzen">Passwort zurücksetzen</button>

                    <?php if ($istAdmin): ?>
                        <button type="button" class="leise admin-aus"
                            <?php if ($selbst): ?>
                                disabled title="Das eigene Adminrecht lässt sich hier nicht entziehen"
                            <?php elseif ($letzterAdmin): ?>
                                disabled title="Letzter Administrator"
                            <?php endif; ?>>
                            Adminrecht entziehen
                        </button>
                    <?php else: ?>
                        <button type="button" class="leise admin-an">Zum Admin machen</button>
                    <?php endif; ?>

                    <?php // Sperren gilt fuer normale Benutzer UND Admins (§6.1) --
                          // ausgenommen ist allein das eigene Konto. Eine
                          // Letzter-Admin-Regel braucht es hier nicht: Wer sperrt,
                          // ist selbst ein aktiver Admin und bleibt es. ?>
                    <?php // Orange und nicht rot: Rot gehoert hier dem Loeschen, und die
                          // beiden Dinge sind gegensaetzlich -- das eine nimmt die Daten
                          // mit, das andere laesst sie ausdruecklich stehen. ?>
                    <?php if ($gesperrt): ?>
                        <button type="button" class="sperr-knopf entsperren">Entsperren</button>
                    <?php else: ?>
                        <button type="button" class="sperr-knopf sperren"
                            <?php if ($selbst): ?>
                                disabled title="Das eigene Konto lässt sich nicht sperren"
                            <?php endif; ?>>
                            Sperren
                        </button>
                    <?php endif; ?>

                    <button type="button" class="gefahr loeschen"
                        <?php if ($selbst): ?>
                            disabled title="Das eigene Konto lässt sich hier nicht löschen"
                        <?php elseif ($letzterAdmin): ?>
                            disabled title="Letzter Administrator"
                        <?php endif; ?>>
                        Löschen
                    </button>
                </div>
            </div>

            <form class="umbenennen-formular" novalidate hidden>
                <label for="nm<?= $id ?>">Neuer Benutzername für <?= h((string)$b['name']) ?></label>
                <div class="zeile-eingabe">
                    <?php // autocapitalize aus -- am Handy machte die Tastatur
                          // sonst aus „nele" ein „Nele", und das ist ein
                          // anderer Name. ?>
                    <input type="text" id="nm<?= $id ?>" class="neuer-name"
                           value="<?= h((string)$b['name']) ?>"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           maxlength="<?= BENUTZER_NAME_MAX ?>" required>
                    <button type="submit">Speichern</button>
                    <button type="button" class="leise abbrechen">Abbrechen</button>
                </div>
                <p class="matt">
                    Der Benutzer meldet sich danach mit dem neuen Namen an. Pläne,
                    Verlauf und angemeldete Geräte bleiben unberührt.
                </p>
            </form>

            <form class="reset-formular" novalidate hidden>
                <label for="pw<?= $id ?>">Neues Startpasswort für <?= h((string)$b['name']) ?></label>
                <div class="zeile-eingabe">
                    <input type="text" id="pw<?= $id ?>" class="neues-passwort"
                           autocomplete="off" required>
                    <button type="submit">Setzen</button>
                    <button type="button" class="leise abbrechen">Abbrechen</button>
                </div>
                <p class="matt">
                    Alle Geräte dieses Benutzers werden dabei abgemeldet.
                </p>
            </form>

            <p class="feld-fehler zeilen-fehler" role="alert" hidden></p>
        </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
