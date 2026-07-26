<?php

declare(strict_types=1);

namespace Weline\Visitor\Console\Pixel;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Visitor\Service\PixelArchiveMigrateService;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * G07：像素热表 → pixel_archive 手动迁移（默认 dry-run；永不删热）。
 *
 * 用法：
 *   php bin/w pixel:archive-migrate
 *   php bin/w pixel:archive-migrate dry-run --before=2025-07-01 --limit=500
 *   php bin/w pixel:archive-migrate apply --enable-apply --before=2025-07-01 --website-id=1
 *   php bin/w pixel:archive-migrate help
 */
class ArchiveMigrate extends CommandAbstract
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

        /** @var PixelArchiveMigrateService $service */
        $service = ObjectManager::getInstance(PixelArchiveMigrateService::class);
        /** @var VisitorTrackingConfig $tracking */
        $tracking = ObjectManager::getInstance(VisitorTrackingConfig::class);

        if (!$tracking->isColdArchiveEnabled() && $action === 'apply') {
            $msg = 'BLOCKED: visitor/tracking/cold_archive_enabled=0 (G10); refuse archive write';
            $printing->printing($msg, 'error');

            return $msg;
        }

        $options = [
            'website_id' => $this->optionValue($args, 'website-id') ?? $this->optionValue($args, 'website_id'),
            'before' => $this->optionValue($args, 'before'),
            'after' => $this->optionValue($args, 'after'),
            'limit' => $this->optionValue($args, 'limit') ?? PixelArchiveMigrateService::DEFAULT_LIMIT,
            'offset' => $this->optionValue($args, 'offset') ?? 0,
            'hot_days' => $tracking->getHotRetentionDays(),
        ];
        if ($options['website_id'] === null || $options['website_id'] === '') {
            unset($options['website_id']);
        } else {
            $options['website_id'] = (int)$options['website_id'];
        }

        if ($action === 'apply') {
            if (!$this->hasFlag($args, 'enable-apply') && !$this->hasFlag($args, 'enable_apply')) {
                $msg = 'BLOCKED: apply requires --enable-apply (G07 never deletes hot rows)';
                $printing->printing($msg, 'error');

                return $msg;
            }
            $report = $service->migrate($options);
            return $this->printReport($printing, $report, 'apply');
        }

        $report = $service->dryRun($options);

        return $this->printReport($printing, $report, 'dry-run');
    }

    public function tip(): string
    {
        return 'Copy old w_pixel rows into pixel_archive (manual; never deletes hot; G07)';
    }

    public function help(): array|string
    {
        return [
            'command' => 'pixel:archive-migrate',
            'description' => $this->tip(),
            'usage' => [
                'php bin/w pixel:archive-migrate',
                'php bin/w pixel:archive-migrate dry-run --before=2025-07-01 --limit=500',
                'php bin/w pixel:archive-migrate apply --enable-apply --before=2025-07-01 --website-id=1',
                'php bin/w pixel:archive-migrate help',
            ],
            'options' => [
                '--before' => 'Archive rows with created_at < before (default: now-retention_hot_days UTC)',
                '--after' => 'Optional lower bound created_at >= after',
                '--website-id' => 'Optional website filter',
                '--limit' => 'Batch size (default 500, max 5000)',
                '--offset' => 'Scan offset (default 0)',
                '--enable-apply' => 'Required for apply (INSERT archive only)',
            ],
            'notes' => [
                'Default action is dry-run (no writes).',
                'This command NEVER deletes w_pixel rows; Retention deletes are G08 only.',
                'Idempotent: already archived pixel_id are skipped.',
                'Default before uses visitor/tracking/retention_hot_days (G10); apply blocked when cold_archive_enabled=0.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printReport(Printing $printing, array $report, string $action): string
    {
        $encoded = \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $line = 'PIXEL_ARCHIVE_MIGRATE ' . $action
            . ': candidates=' . (int)($report['candidates'] ?? 0)
            . ' already=' . (int)($report['already_archived'] ?? 0)
            . ' would_insert=' . (int)($report['would_insert'] ?? 0)
            . ' inserted=' . (int)($report['inserted'] ?? 0)
            . ' deletes_hot=' . (!empty($report['deletes_hot']) ? '1' : '0')
            . ' before=' . (string)($report['before'] ?? '');
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
