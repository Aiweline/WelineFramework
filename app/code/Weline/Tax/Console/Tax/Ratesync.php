<?php

declare(strict_types=1);

namespace Weline\Tax\Console\Tax;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Tax\Service\RateSync\TaxRateAggregateSyncService;

/**
 * Offline multi-source tax rate sync (free union + optional professional overlay).
 *
 * CLI name: tax:ratesync
 */
class Ratesync extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);
        if (in_array('-h', $args, true) || in_array('--help', $args, true) || in_array('help', $args, true)) {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string)$help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        $websiteId = 0;
        $force = false;
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, '--website=')) {
                $websiteId = (int)substr($arg, strlen('--website='));
            }
            if ($arg === '--force' || $arg === '-f') {
                $force = true;
            }
        }
        if ($websiteId < 0) {
            $printing->printing('website_id must be >= 0', 'error');

            return 2;
        }

        try {
            /** @var TaxRateAggregateSyncService $sync */
            $sync = ObjectManager::getInstance(TaxRateAggregateSyncService::class);
            $result = $sync->sync($websiteId, $force);
        } catch (\Throwable $exception) {
            $printing->printing('tax:ratesync failed: ' . $exception->getMessage(), 'error');

            return 2;
        }

        $printing->printing(
            'tax:ratesync: ' . (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'),
            !empty($result['ok']) ? 'success' : 'error',
        );

        return !empty($result['ok']) ? 0 : 2;
    }

    public function tip(): string
    {
        return (string)__('多源聚合税率同步（免费并集取高 + 可选专业覆盖）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'tax:ratesync',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                '--website=' => __('Website ID，默认 0'),
                '--force, -f' => __('忽略 manual_only 模式强制同步'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w tax:ratesync',
                'php bin/w tax:ratesync --website=0',
                'php bin/w tax:ratesync --force',
            ],
        );
    }
}
