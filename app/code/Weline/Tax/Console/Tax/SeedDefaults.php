<?php

declare(strict_types=1);

namespace Weline\Tax\Console\Tax;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Tax\Service\TaxDefaultSeedService;

/**
 * Idempotent seed of default-site Tax classes/rules + rollout allowlist.
 */
class SeedDefaults extends CommandAbstract
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

        $websiteId = TaxDefaultSeedService::WEBSITE_ID;
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, '--website=')) {
                $websiteId = (int)substr($arg, strlen('--website='));
            }
        }
        if ($websiteId < 0) {
            $printing->printing('website_id must be >= 0', 'error');

            return 2;
        }

        try {
            /** @var TaxDefaultSeedService $seed */
            $seed = ObjectManager::getInstance(TaxDefaultSeedService::class);
            $result = $seed->ensureDefaults($websiteId);
        } catch (\Throwable $exception) {
            $printing->printing('tax:seed-defaults failed: ' . $exception->getMessage(), 'error');

            return 2;
        }

        $printing->printing(
            'tax:seed-defaults: ' . (json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'),
            'success',
        );

        return 0;
    }

    public function tip(): string
    {
        return (string)__('幂等 upsert 默认可上线多国税类/税率种子，并开启 tax rollout allowlist（website:N）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'tax:seed-defaults',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                '--website=' => __('Website ID，默认 0'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w tax:seed-defaults',
                'php bin/w tax:seed-defaults --website=0',
            ],
        );
    }
}
