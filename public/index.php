<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

require_login();

header('Location: /wbss/public/gate.php', true, 302);
exit;
