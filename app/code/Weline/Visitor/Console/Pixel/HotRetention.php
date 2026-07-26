<?php

declare(strict_types=1);

namespace Weline\Visitor\Console\Pixel;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Visitor\Service\PixelHotRetentionService;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * G08：热表 Retention（默认 dry-run；删热需双开关；job_log success 门禁）。
 *
 * 用法：
 *   php bin/w pixel:hot-retention
 *   php bin/w pixel:hot-retention dry-run --hot-days=365 --limit=500
 *   php bin/w pixel:hot-retention apply --enable-apply --enable-delete --website-id=1
 *   php bin/w pixel:hot-retention help
 */
class HotRetention extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $action = 'dry-run';
        foreach ($args as $arg) {
            $lower = strtolower(trim((string)$arg));
            if (\in_array($lower, ['help', 'dry-run', 'dry_run', 'apply'], true)) {
                $action = $lower === 'dry_run' ? 'dry-run' : $lower;
                break;
            }
        }

        if ($action === 'help' || $this->wantsCommandHelp($args)) {
            $help = $this->help();
            $encoded = \is_array($help)
                ? (\json_encode($help, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}')
                : (string)$help;
            $printing->printing($encoded, 'success');

            return $encoded;
        }

        /** @var PixelHotRetentionService $service */
        $service = ObjectManager::getInstance(PixelHotRetentionService::class);
        /** @var VisitorTrackingConfig $tracking */
        $tracking = ObjectManager::getInstance(VisitorTrackingConfig::class);

        if (!$tracking->isColdArchiveEnabled() && $action === 'apply') {
            $msg = 'BLOCKED: visitor/tracking/cold_archive_enabled=0 (G10); refuse archive+delete apply';
            $printing->printing($msg, 'error');

            return $msg;
        }

        $options = [
            'website_id' => $this->optionValue($args, 'website-id') ?? $this->optionValue($args, 'website_id'),
            'hot_days' => $this->optionValue($args, 'hot-days') ?? $this->optionValue($args, 'hot_days'),
            'limit' => $this->optionValue($args, 'limit') ?? PixelHotRetentionService::DEFAULT_LIMIT,
        ];
        if ($options['website_id'] === null || $options['website_id'] === '') {
            unset($options['website_id']);
        } else {
            $options['website_id'] = (int)$options['website_id'];
        }
        if ($options['hot_days'] === null || $options['hot_days'] === '') {
            $options['hot_days'] = $tracking->getHotRetentionDays();
        } else {
            $options['hot_days'] = (int)$options['hot_days'];
        }

        if ($action === 'apply') {
            $hasApply = $this->hasFlag($args, 'enable-apply') || $this->hasFlag($args, 'enable_apply');
            $hasDelete = $this->hasFlag($args, 'enable-delete') || $this->hasFlag($args, 'enable_delete');
            if (!$hasApply || !$hasDelete) {
                $msg = 'BLOCKED: apply requires --enable-apply AND --enable-delete (G08 job_log gate; failed days never deleted)';
                $printing->printing($msg, 'error');

                return $msg;
            }
            $report = $service->apply($options);

            return $this->printReport($printing, $report, 'apply');
        }

        $report = $service->dryRun($options);

        return $this->printReport($printing, $report, 'dry-run');
    }

    public function tip(): string
    {
        return 'Hot pixel retention with daily job_log=success gate (dry-run default; G08)';
    }

    public function help(): array|string
    {
        return [
            'command' => 'pixel:hot-retention',
            'description' => $this->tip(),
            'usage' => [
                'php bin/w pixel:hot-retention',
                'php bin/w pixel:hot-retention dry-run --hot-days=365 --limit=500',
                'php bin/w pixel:hot-retention apply --enable-apply --enable-delete --website-id=1',
                'php bin/w pixel:hot-retention help',
            ],
            'options' => [
                '--hot-days' => 'Keep hot rows newer than now-hot-days (default: SystemConfig retention_hot_days, else 365)',
                '--website-id' => 'Optional website filter',
                '--limit' => 'Max hot rows to process this run (default 500, max 5000)',
                '--enable-apply' => 'Required for apply (archive + delete path)',
                '--enable-delete' => 'Required for apply (explicit hot delete consent)',
            ],
            'notes' => [
                'Default action is dry-run (no archive write / no hot delete).',
                'Only days with pixel_stats_job_log daily status=success before cutoff are eligible.',
                'Failed or missing job_log days are skipped; never default-allow deletes.',
                'Default --hot-days comes from visitor/tracking/retention_hot_days (G10 SystemConfig).',
                'Apply is blocked when visitor/tracking/cold_archive_enabled=0.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printReport(Printing $printing, array $report, string $action): string
    {
        $encoded = \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $line = 'PIXEL_HOT_RETENTION ' . $action
            . ': eligible_days=' . (int)($report['eligible_days'] ?? 0)
            . ' skipped_days=' . (int)($report['skipped_days'] ?? 0)
            . ' candidate_rows=' . (int)($report['candidate_rows'] ?? 0)
            . ' would_delete=' . (int)($report['would_delete'] ?? 0)
            . ' deleted=' . (int)($report['deleted'] ?? 0)
            . ' cutoff=' . (string)($report['cutoff'] ?? '');
        $printing->printing($line, 'success');
        $printing->printing($encoded, 'success');

        return $line . "\n" . $encoded;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function wantsCommandHelp(array $args): bool
    {
        foreach ($args as $arg) {
            $lower = strtolower(trim((string)$arg));
            if (\in_array($lower, ['-h', '--help', 'help'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function hasFlag(array $args, string $flag): bool
    {
        $needle = '--' . ltrim($flag, '-');
        foreach ($args as $arg) {
            if (strtolower(trim((string)$arg)) === strtolower($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): mixed
    {
        $needle = '--' . ltrim($name, '-');
        foreach ($args as $arg) {
            $arg = trim((string)$arg);
            if (str_starts_with($arg, $needle . '=')) {
                return substr($arg, \strlen($needle) + 1);
            }
        }

        return null;
    }
}
