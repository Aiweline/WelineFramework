#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Pipeline compile worker: Taglib-compile one promoted identity (nested locale pool off).
 * Usage: php compile-identity-job.php /abs/path/to/compile-job.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "compile-identity-job: CLI only\n");
    exit(2);
}

$jobPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($jobPath === '' || !is_file($jobPath)) {
    fwrite(STDERR, "compile-identity-job: job json required\n");
    exit(2);
}

// …/app/code/Weline/Theme/bin → …/app/bootstrap.php
$bootstrap = dirname(__DIR__, 4) . '/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "compile-identity-job: bootstrap not found: {$bootstrap}\n");
    exit(2);
}

putenv('WELINE_TEMPLATE_COMPILE_NESTED=1');
$_ENV['WELINE_TEMPLATE_COMPILE_NESTED'] = '1';

require $bootstrap;

use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyCompilePipeline;

try {
    $raw = file_get_contents($jobPath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('theme_layout_compile_job_unreadable');
    }
    $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('theme_layout_compile_job_invalid');
    }
    $result = ThemeLayoutEntitySolidifyCompilePipeline::runCompileJob($job);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'compile-identity-job: ' . $e->getMessage() . "\n");
    exit(1);
}
