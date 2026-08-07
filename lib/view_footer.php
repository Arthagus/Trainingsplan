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
     warten zu muessen -- und nach app.js laeuft, das ebenfalls defer traegt. -->
<script src="<?= h($base . '/' . $jsDatei) ?>" defer></script>
<?php endif; ?>

</body>
</html>
