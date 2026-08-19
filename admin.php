<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/helpers.php';

bootstrap_session();
require_login();
require_admin();

/**
 * Einstieg in die Verwaltung -- eine Seite mit Kacheln, sonst nichts.
 *
 * Der Grund ist die Kopfzeile am Handy: Bis 1.2.1 standen dort acht Punkte
 * nebeneinander (Training, Verlauf, Splits, Plaene, Uebungen, Muskelgruppen,
 * Benutzer, Wartung), und die vier hinteren braucht im Studio niemand. Sie
 * liegen jetzt hinter EINEM Punkt, und die Kopfzeile traegt wieder das, was
 * man beim Trainieren tatsaechlich anfasst.
 *
 * Bewusst KEIN eigenes Koennen: Diese Seite verlinkt nur. Wer hier Funktionen
 * einbaut, hat eine zweite Verwaltungsoberflaeche neben den vieren, die es
 * schon gibt.
 *
 * Warum die Beschreibungen: Vier gleich aussehende Knoepfe sind keine
 * Orientierung. "Muskelgruppen" sagt einem Admin, der die App alle paar
 * Wochen anfasst, nicht von selbst, dass davon der Uebungstausch abhaengt.
 */

/** @var array<int, array{datei: string, titel: string, text: string}> */
$bereiche = [
    [
        'datei' => 'admin_exercises.php',
        'titel' => 'Übungen',
        'text'  => 'Der Übungsbestand: anlegen, bearbeiten, Bilder hochladen, '
                 . 'archivieren. Grundlage für Pläne und Tauschvorschläge.',
    ],
    [
        'datei' => 'admin_muscle_groups.php',
        'titel' => 'Muskelgruppen',
        'text'  => 'Das Vokabular hinter den Übungen — zweistufig. Der '
                 . 'Übungstausch vergleicht auf Ebene der Hauptgruppe, '
                 . 'die Unterteilung darf also fein werden.',
    ],
    [
        'datei' => 'admin_users.php',
        'titel' => 'Benutzer',
        'text'  => 'Konten anlegen, umbenennen, sperren und freigeben, '
                 . 'Passwörter zurücksetzen, Adminrechte vergeben.',
    ],
    [
        'datei' => 'maintenance.php',
        'titel' => 'Wartung',
        'text'  => 'Sicherungen anlegen, herunterladen und einspielen, dazu '
                 . 'der Zustand der Datenbank. Die wichtigste Seite, wenn '
                 . 'etwas schiefgeht.',
    ],
];

$pageTitle = 'Admin';
require __DIR__ . '/lib/view_header.php';
?>

<p class="matt">
    Alles, was man beim Training im Studio nicht braucht. Splits und Pläne
    stehen weiterhin oben im Menü — die verwaltet jeder Benutzer selbst.
</p>

<div class="admin-gitter">
    <?php foreach ($bereiche as $b): ?>
        <a class="admin-kachel" href="<?= h(base_path() . '/' . $b['datei']) ?>">
            <strong><?= h($b['titel']) ?></strong>
            <span class="matt"><?= h($b['text']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/lib/view_footer.php'; ?>
