<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();
require_login();

/**
 * Geraeteverwaltung (§7.7): die Oberflaeche zu der in §5 zugesagten
 * serverseitigen Widerrufbarkeit der Remember-Me-Tokens.
 */

purge_expired_remember_tokens();

$stmt = db()->prepare(
    'SELECT id, selector, created_at, last_used_at, expires_at, user_agent
       FROM remember_tokens
      WHERE user_id = ?
      ORDER BY COALESCE(last_used_at, created_at) DESC'
);
$stmt->execute([current_user_id()]);
$geraete = $stmt->fetchAll();

// Das aktuelle Geraet erkennt man am Selector im eigenen Cookie.
$eigenerSelector = explode(':', (string)($_COOKIE[REMEMBER_COOKIE] ?? ''), 2)[0];

$pageTitle = 'Geräte';
require __DIR__ . '/lib/view_header.php';
?>

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
            <?php $eigenes = $eigenerSelector !== '' && hash_equals((string)$g['selector'], $eigenerSelector); ?>
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

<?php require __DIR__ . '/lib/view_footer.php'; ?>
