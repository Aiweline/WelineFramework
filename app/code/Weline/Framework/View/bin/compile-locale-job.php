#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker entry for TemplateCompileService locale process pool.
 * Usage: php compile-locale-job.php /abs/path/to/job.json
 *
 * Progress lines on STDERR; exit 0 on success, non-zero on failure.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "compile-locale-job: CLI only\n");
    exit(2);
}

$jobPath = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($jobPath === '' || !is_file($jobPath)) {
    fwrite(STDERR, "compile-locale-job: job json required\n");
    exit(2);
}

// …/app/code/Weline/Framework/View/bin → …/app/bootstrap.php
$bootstrap = dirname(__DIR__, 5) . '/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "compile-locale-job: bootstrap not found: {$bootstrap}\n");
    exit(2);
}

require $bootstrap;

use Weline\Framework\View\TemplateCompileLocaleJob;

try {
    $raw = file_get_contents($jobPath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('template_compile_job_unreadable');
    }
    $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('template_compile_job_invalid_json');
    }
    $compiled = TemplateCompileLocaleJob::run(
        $job,
        static function (int $done, int $total, string $locale, string $label): void {
            TemplateCompileLocaleJob::emitProgressLine($done, $total, $locale, $label);
        },
    );
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'compiled' => count($compiled),
        'paths' => array_values($compiled),
    ], JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'compile-locale-job: ' . $e->getMessage() . "\n");
    exit(1);
}
