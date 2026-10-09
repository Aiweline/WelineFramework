#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pipeline solidify worker: promote one ThemeVersionIdentity (no Taglib compile).
 * Usage: php solidify-identity-job.php /abs/path/to/job.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "solidify-identity-job: CLI only\n");
    exit(2);
}

$jobPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($jobPath === '' || !is_file($jobPath)) {
    fwrite(STDERR, "solidify-identity-job: job json required\n");
    exit(2);
}

// …/app/code/Weline/Theme/bin → …/app/bootstrap.php
$bootstrap = dirname(__DIR__, 4) . '/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "solidify-identity-job: bootstrap not found: {$bootstrap}\n");
    exit(2);
}

require $bootstrap;

use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyCompilePipeline;

try {
    $raw = file_get_contents($jobPath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('theme_layout_solidify_job_unreadable');
    }
    $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('theme_layout_solidify_job_invalid');
    }
    $result = ThemeLayoutEntitySolidifyCompilePipeline::runSolidifyJob($job);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'solidify-identity-job: ' . $e->getMessage() . "\n");
    exit(1);
}
