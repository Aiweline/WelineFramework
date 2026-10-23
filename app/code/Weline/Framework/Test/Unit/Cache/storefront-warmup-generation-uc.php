<?php

declare(strict_types=1);

/**
 * Real-machine use case: a finished warmup ledger one authority clock behind
 * the live namespace clock must be marked and restarted by the cron slice.
 *
 * Uses the normal application bootstrap (not the PHPUnit sandbox database).
 * Restores the live progress file before exiting.
 *
 * Usage: php storefront-warmup-generation-uc.php /path/to/evidence.json
 */

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupOrchestrator;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;
use Weline\Framework\Manager\ObjectManager;

if (\PHP_SAPI !== 'cli') {
    \fwrite(\STDERR, "CLI only\n");
    exit(1);
}

$root = \dirname(__DIR__, 7);
if (!\defined('BP')) {
    \define('BP', $root . \DIRECTORY_SEPARATOR);
}

require BP . 'app/bootstrap.php';

$evidencePath = $argv[1] ?? (BP . 'var/cache/uc-evidence/storefront-warmup-generation-uc.json');
$ledger = new StorefrontWarmupProgressLedger();
$livePath = $ledger->path();
$backupPath = \sys_get_temp_dir() . '/storefront-warmup-progress-uc-' . \getmypid() . '.json';
$restored = false;

$restore = static function () use (&$restored, $backupPath, $livePath): bool {
    if ($restored) {
        return true;
    }
    if (!\is_file($backupPath)) {
        return true;
    }
    $dir = \dirname($livePath);
    if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
        \fwrite(\STDERR, "restore directory failed: {$dir}\n");
        return false;
    }
    if (!\copy($backupPath, $livePath)) {
        \fwrite(\STDERR, "restore failed: {$backupPath}\n");
        return false;
    }
    \unlink($backupPath);
    $restored = true;

    return true;
};

try {
    if (\is_file($livePath) && !\copy($livePath, $backupPath)) {
        throw new \RuntimeException('live warmup ledger backup failed');
    }

    $clock = ObjectManager::getInstance(NamespaceGenerationRepository::class)->authorityClock();
    if ($clock <= 0) {
        throw new \RuntimeException('real authority clock must already exist, got ' . $clock);
    }
    $planted = $clock - 1;
    $plantedFingerprint = 'stale-storefront-fingerprint-' . $clock;
    $ledger->write([
        'phase' => StorefrontWarmupProgressLedger::PHASE_DONE,
        'generation_authority_clock' => $planted,
        'storefront_generation_fingerprint' => $plantedFingerprint,
        'needs_rewarm' => false,
        'ok' => 99999,
        'skip' => 0,
        'fail' => 0,
        'loc_cursor' => 40,
        'locs' => ['/stale-generation-marker'],
        'last_url' => '/stale-generation-marker',
        'last_status' => 'done',
        'last_reason' => 'stale_generation_marker',
    ]);
    $plantedState = $ledger->read();
    if ((int)$plantedState['generation_authority_clock'] !== $planted
        || (string)$plantedState['storefront_generation_fingerprint'] !== $plantedFingerprint
        || $plantedState['phase'] !== StorefrontWarmupProgressLedger::PHASE_DONE
        || $plantedState['needs_rewarm'] !== false
    ) {
        throw new \RuntimeException('failed to plant stale warmup generation');
    }

    $orchestrator = new StorefrontWarmupOrchestrator();
    $report = ['reason' => 'lock_busy'];
    $deadline = \microtime(true) + 90;
    do {
        $report = $orchestrator->runSlice(['source' => 'cron']);
        if (($report['reason'] ?? '') !== 'lock_busy') {
            break;
        }
        \usleep(200000);
    } while (\microtime(true) < $deadline);

    $after = $ledger->read();
    $attempts = (int)($report['warmed'] ?? 0) + (int)($report['failed'] ?? 0) + (int)($report['skipped'] ?? 0);
    $evidence = [
        'planted_clock' => $planted,
        'planted_fingerprint' => $plantedFingerprint,
        'authority_clock_before' => $clock,
        'ledger_clock_after' => (int)$after['generation_authority_clock'],
        'ledger_fingerprint_after' => (string)$after['storefront_generation_fingerprint'],
        'phase_after' => (string)$after['phase'],
        'needs_rewarm' => (bool)$after['needs_rewarm'],
        'reason' => (string)($report['reason'] ?? ''),
        'generation_changed' => (bool)($report['generation_changed'] ?? false),
        'warmed' => (int)($report['warmed'] ?? 0),
        'failed' => (int)($report['failed'] ?? 0),
        'skipped' => (int)($report['skipped'] ?? 0),
        'attempts' => $attempts,
        'ok_after' => (int)$after['ok'],
        'last_url' => (string)$after['last_url'],
    ];
    $dir = \dirname($evidencePath);
    if (!\is_dir($dir)) {
        \mkdir($dir, 0775, true);
    }
    \file_put_contents(
        $evidencePath,
        \json_encode($evidence, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT)
    );

    if (($report['reason'] ?? '') === 'lock_busy') {
        throw new \RuntimeException('warmup slice stayed locked');
    }
    if ((string)$after['storefront_generation_fingerprint'] === $plantedFingerprint || (int)$after['ok'] === 99999) {
        throw new \RuntimeException('stale generation marker was still the live warmup ledger');
    }
    if (\in_array('/stale-generation-marker', $after['locs'], true)) {
        throw new \RuntimeException('stale loc marker was not cleared');
    }
    if ((int)$after['generation_authority_clock'] <= $planted) {
        throw new \RuntimeException('ledger clock did not move onto the current authority');
    }
    if ($after['phase'] === StorefrontWarmupProgressLedger::PHASE_DONE) {
        throw new \RuntimeException('warmup stayed finished on the old generation');
    }
    if (($report['reason'] ?? '') !== 'generation_changed' || ($report['generation_changed'] ?? false) !== true) {
        throw new \RuntimeException('slice did not report generation_changed: ' . (string)($report['reason'] ?? ''));
    }
    if ($attempts <= 0) {
        throw new \RuntimeException('generation change was marked but no rewarm request was sent');
    }
    if ((int)$report['generation_authority_clock'] !== (int)$after['generation_authority_clock']) {
        throw new \RuntimeException('slice clock and ledger clock diverged');
    }

    \fwrite(\STDOUT, \json_encode($evidence, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
    if (!$restore()) {
        throw new \RuntimeException('failed to restore live warmup ledger');
    }
    exit(0);
} catch (\Throwable $e) {
    $restore();
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}
