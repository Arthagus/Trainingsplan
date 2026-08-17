<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Fuss jeder Seite.
 *
 * Bindet automatisch das gleichnamige Seiten-Skript ein: index.php laedt
 * index.js, admin_plans.php laedt admin_plans.js. Damit bleibt die Regel
 * "Seite = .php + gleichnamige .js" eingehalten, ohne dass jede Seite die
 * script-Zeile selbst mitschleppt. Fehlt die Datei, wird nichts eingebunden --
 * eine Seite ganz ohne JavaScript ist zulaessig.
 */

$base   = base_path();
$script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
$jsDatei = $script . '.js';
$jsVorhanden = $script !== '' && is_file(dirname(__DIR__) . '/' . $jsDatei);
?>
</main>

<?php if ($jsVorhanden): ?>
<!-- defer, damit das Skript das DOM fertig vorfindet, ohne auf DOMContentLoaded
     warten zu muessen -- und nach app.js laeuft, das ebenfalls defer traegt.

     ?v= aus denselben Gruenden wie im Header. Die Seiten-Skripte liegen im
     Wurzelverzeichnis und werden vom Service Worker gar nicht gecacht (er
     nimmt nur /assets/) -- der HTTP-Cache des Browsers greift hier aber
     genauso, und der allein hat gereicht, um 1.1.7 am Handy alt aussehen zu
     lassen. -->
<script src="<?= h($base . '/' . $jsDatei) ?>?v=<?= h(app_version()) ?>" defer></script>
<?php endif; ?>

</body>
</html>
