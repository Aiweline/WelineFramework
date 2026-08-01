<?php

declare(strict_types=1);

// WLS release gates validate the primary runtime contract. SQLite remains
// available only through the ordinary explicit sandbox bootstrap.
if (!\defined('SANDBOX')) {
    \define('SANDBOX', false);
}

require_once \dirname(__DIR__, 4) . '/bootstrap_phpunit.php';

$databaseConfig = \Weline\Framework\App\Env::getInstance()->reload()->getDbConfig();
$databaseType = \strtolower(\trim((string)(
    $databaseConfig['master']['type']
    ?? $databaseConfig['default']
    ?? ''
)));
if ($databaseType !== 'pgsql') {
    throw new \RuntimeException(
        'WLS release tests require the primary PostgreSQL runtime; SQLite sandbox fallback is forbidden.',
    );
}
