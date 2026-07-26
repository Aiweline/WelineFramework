<?php

declare(strict_types=1);

namespace Weline\Visitor\Console\Pixel;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Visitor\Service\PixelAttributionBackfillService;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * A13a/A13b：像素归因扁平列回填（默认 dry-run；apply 需显式开关）。
 *
 * 用法：
 *   php bin/w pixel:attribution-backfill
 *   php bin/w pixel:attribution-backfill dry-run --limit=500
 *   php bin/w pixel:attribution-backfill apply --enable-apply --limit=500 [--mark-done]
 *   php bin/w pixel:attribution-backfill mark-done --enable-mark
 *   php bin/w pixel:attribution-backfill status
 *   php bin/w pixel:attribution-backfill help
 */
class AttributionBackfill extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = 'dry-run';
        foreach ($args as $arg) {
            $lower = strtolower(trim((string)$arg));
            if (\in_array($lower, ['help', 'dry-run', 'dry_run', 'apply', 'mark-done', 'mark_done', 'status'], true)) {
                $action = match ($lower) {
                    'dry_run' => 'dry-run',
                    'mark_done' => 'mark-done',
                    default => $lower,
                };
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

        /** @var PixelAttributionBackfillService $service */
        $service = ObjectManager::getInstance(PixelAttributionBackfillService::class);
        /** @var VisitorTrackingConfig $tracking */
        $tracking = ObjectManager::getInstance(VisitorTrackingConfig::class);

        if ($action === 'status') {
            $payload = [
                'attribution_backfill_done' => $tracking->isAttributionBackfillDone(),
                'columns_ready' => $service->hasAttributionColumns(),
                'config_key' => VisitorTrackingConfig::CONFIG_KEY_ATTRIBUTION_BACKFILL_DONE,
            ];
            $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $line = 'PIXEL_ATTRIBUTION_BACKFILL status: done='
                . (!empty($payload['attribution_backfill_done']) ? '1' : '0')
                . ' columns_ready=' . (!empty($payload['columns_ready']) ? '1' : '0');
            $printing->printing($line, 'success');
            $printing->printing($encoded, 'success');

            return $line . "\n" . $encoded;
        }

        if ($action === 'mark-done') {
            if (!$this->hasFlag($args, 'enable-mark') && !$this->hasFlag($args, 'enable_mark')) {
                $msg = 'BLOCKED: mark-done requires --enable-mark';
                $printing->printing($msg, 'error');

                return $msg;
            }
            $report = $service->markDone(true);
            $encoded = \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $line = 'PIXEL_ATTRIBUTION_BACKFILL mark-done: ok=' . (!empty($report['ok']) ? '1' : '0')
                . ' done=' . (!empty($report['attribution_backfill_done']) ? '1' : '0');
            $printing->printing($line, !empty($report['ok']) ? 'success' : 'error');
            $printing->printing($encoded, !empty($report['ok']) ? 'success' : 'error');

            return $line . "\n" . $encoded;
        }

        $common = [
            'website_id' => (int)($this->optionValue($args, 'website-id') ?? $this->optionValue($args, 'website_id') ?? 0),
            'limit' => (int)($this->optionValue($args, 'limit') ?? 500),
            'offset' => (int)($this->optionValue($args, 'offset') ?? 0),
            'sample_limit' => (int)($this->optionValue($args, 'sample-limit') ?? $this->optionValue($args, 'sample_limit') ?? 5),
        ];

        if ($action === 'apply') {
            if (!$this->hasFlag($args, 'enable-apply') && !$this->hasFlag($args, 'enable_apply')) {
                $msg = 'BLOCKED: apply requires --enable-apply (A13b write path)';
                $printing->printing($msg, 'error');

                return $msg;
            }
            $common['mark_done'] = $this->hasFlag($args, 'mark-done') || $this->hasFlag($args, 'mark_done');
            $report = $service->apply($common);
            $encoded = \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $line = 'PIXEL_ATTRIBUTION_BACKFILL apply: scanned='
                . (int)($report['scanned'] ?? 0)
                . ' would_update=' . (int)($report['would_update'] ?? 0)
                . ' updated=' . (int)($report['updated'] ?? 0)
                . ' sample_has_values=' . (!empty($report['sample_has_values']) ? '1' : '0')
                . ' done=' . (!empty($report['attribution_backfill_done']) ? '1' : '0')
                . ' columns_ready=' . (!empty($report['columns_ready']) ? '1' : '0');
            if (!empty($report['error'])) {
                $line .= ' error=' . (string)$report['error'];
                $printing->printing($line, 'warning');
                $printing->printing($encoded, 'warning');

                return $line . "\n" . $encoded;
            }
            $printing->printing($line, 'success');
            $printing->printing($encoded, 'success');

            return $line . "\n" . $encoded;
        }

        $report = $service->dryRun($common);
        $encoded = \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $line = 'PIXEL_ATTRIBUTION_BACKFILL dry-run: scanned='
            . (int)($report['scanned'] ?? 0)
            . ' would_update=' . (int)($report['would_update'] ?? 0)
            . ' skipped=' . (int)($report['skipped'] ?? 0)
            . ' sample_has_values=' . (!empty($report['sample_has_values']) ? '1' : '0')
            . ' done=' . (!empty($report['attribution_backfill_done']) ? '1' : '0')
            . ' columns_ready=' . (!empty($report['columns_ready']) ? '1' : '0');
        if (!empty($report['error'])) {
            $line .= ' error=' . (string)$report['error'];
            $printing->printing($line, 'warning');
            $printing->printing($encoded, 'warning');

            return $line . "\n" . $encoded;
        }

        $printing->printing($line, 'success');
        $printing->printing($encoded, 'success');

        return $line . "\n" . $encoded;
    }

    public function tip(): string
    {
        return 'Pixel attribution flat-column backfill (A13a dry-run / A13b apply+mark)';
    }

    public function help(): array|string
    {
        return [
            'command' => 'pixel:attribution-backfill',
            'description' => $this->tip(),
            'usage' => [
                'php bin/w pixel:attribution-backfill',
                'php bin/w pixel:attribution-backfill dry-run --limit=500 --website-id=1',
                'php bin/w pixel:attribution-backfill apply --enable-apply --limit=500 --mark-done',
                'php bin/w pixel:attribution-backfill mark-done --enable-mark',
                'php bin/w pixel:attribution-backfill status',
                'php bin/w pixel:attribution-backfill help',
            ],
            'options' => [
                '--limit' => 'Max rows to scan (default 500, max 10000)',
                '--offset' => 'Scan offset (default 0)',
                '--website-id' => 'Optional website filter (0 = all)',
                '--sample-limit' => 'Sample change rows in report (default 5)',
                '--enable-apply' => 'Required for apply (writes flat columns)',
                '--mark-done' => 'With apply: set visitor/tracking/attribution_backfill_done=1',
                '--enable-mark' => 'Required for mark-done action',
            ],
            'notes' => [
                'Default action is dry-run (no writes).',
                'A13b Done: sample_has_values=1 or attribution_backfill_done=1.',
                'Does not run Retention.',
            ],
        ];
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
    private function hasFlag(array $args, string $name): bool
    {
        $needle = '--' . $name;
        foreach ($args as $arg) {
            $raw = (string)$arg;
            if ($raw === $needle || $raw === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function optionValue(array $args, string $name): ?string
    {
        $needle = '--' . $name;
        foreach ($args as $index => $arg) {
            $raw = trim((string)$arg);
            if (str_starts_with($raw, $needle . '=')) {
                return substr($raw, strlen($needle) + 1);
            }
            if (strtolower($raw) === strtolower($needle) && isset($args[(int)$index + 1])) {
                return (string)$args[(int)$index + 1];
            }
        }

        return null;
    }
}
