<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

header('Location: /wbss/public/gate.php', true, 302);
exit;
