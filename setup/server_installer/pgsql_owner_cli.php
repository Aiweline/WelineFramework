<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'PgsqlProjectOwnership.php';

$projectRoot = dirname(__DIR__, 2);
$command = (string)($argv[1] ?? 'describe');
$ownership = new PgsqlProjectOwnership($projectRoot);
$target = $ownership->targetFromEnvPhp();
$classification = $ownership->classifyEnvTarget($target);

if ($command === 'server-can-manage') {
    if ($target !== null) {
        $ownership->printTargetSummary($target, 'env.php db.master');
    }
    echo $classification['message'] . PHP_EOL;
    if (($classification['error'] ?? false) === true) {
        exit(1);
    }
    if (($classification['skip_pgsql'] ?? false) === true) {
        exit(2);
    }
    exit(0);
}

if ($command === 'describe') {
    $ownership->printTargetSummary($target, 'env.php db.master');
    echo 'PostgreSQL mode: ' . ($classification['mode'] ?? 'unknown') . PHP_EOL;
    echo $classification['message'] . PHP_EOL;
    exit(($classification['error'] ?? false) === true ? 1 : 0);
}

if ($command === 'sync-env-port') {
    $port = (int)($argv[2] ?? 0);
    if ($port <= 0 || $port > 65535) {
        fwrite(STDERR, "Invalid port.\n");
        exit(1);
    }
    if ($target === null) {
        exit(0);
    }
    if (($classification['manageable'] ?? false) !== true) {
        exit(0);
    }
    exit($ownership->updateEnvPhpDbPort($port) ? 0 : 1);
}

fwrite(STDERR, "Usage: php setup/server_installer/pgsql_owner_cli.php [describe|server-can-manage|sync-env-port PORT]\n");
exit(1);
