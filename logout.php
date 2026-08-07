<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

/**
 * Abmelden.
 *
 * Bewusst als GET-Link erreichbar und ohne CSRF-Token: ein erzwungenes
 * Abmelden ist laestig, aber kein Schaden -- und ein Abmeldeweg, der an einem
 * abgelaufenen Token scheitert, waere schlimmer. Die Seite ist auch dann
 * erreichbar, wenn ein Passwortwechsel erzwungen ist (siehe require_login()).
 */

bootstrap_session();
logout();

redirect('login.php');
