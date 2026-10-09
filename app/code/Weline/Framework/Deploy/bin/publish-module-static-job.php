#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * deploy:upgrade / d:m:se prod module static copy worker.
 * Usage: php publish-module-static-job.php /abs/path/to/job.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "publish-module-static-job: CLI only\n");
    exit(2);
}

$jobPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($jobPath === '' || !is_file($jobPath)) {
    fwrite(STDERR, "publish-module-static-job: job json required\n");
    exit(2);
}

// …/app/code/Weline/Framework/Deploy/bin → …/app/bootstrap.php
$bootstrap = dirname(__DIR__, 5) . '/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "publish-module-static-job: bootstrap not found: {$bootstrap}\n");
    exit(2);
}

require $bootstrap;

use Weline\Framework\Console\Console\Deploy\Upgrade;
use Weline\Framework\Manager\ObjectManager;

try {
    $raw = file_get_contents($jobPath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('deploy_static_job_unreadable');
    }
    $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('deploy_static_job_invalid');
    }
    /** @var Upgrade $upgrade */
    $upgrade = ObjectManager::getInstance(Upgrade::class);
    if (method_exists($upgrade, '__init')) {
        $upgrade->__init();
    }
    $result = $upgrade->publishOneModuleJob($job);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'name' => (string)($result['name'] ?? ''),
        'tree_changed' => !empty($result['tree_changed']),
    ], JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'publish-module-static-job: ' . $e->getMessage() . "\n");
    exit(1);
}
