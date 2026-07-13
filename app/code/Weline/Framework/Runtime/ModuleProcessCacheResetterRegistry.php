<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

final class ModuleProcessCacheResetterRegistry
{
    public const CAPABILITY_PREFIX = 'process_cache_resetter.';

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function reset(ProcessCacheResetContext $context): int
    {
        try {
            $implementations = $this->providers->implementationsWithPrefix(self::CAPABILITY_PREFIX);
        } catch (\Throwable $throwable) {
            $this->logFailure('registry', '', $throwable);
            return 0;
        }

        $cleared = 0;
        foreach ($implementations as $capability => $implementation) {
            try {
                $resetter = ObjectManager::getInstance($implementation);
                if (!$resetter instanceof ProcessCacheResetterInterface) {
                    throw new \RuntimeException(
                        "{$implementation} must implement " . ProcessCacheResetterInterface::class,
                    );
                }
                $cleared += \max(0, $resetter->resetProcessCaches($context));
            } catch (\Throwable $throwable) {
                $this->logFailure($capability, $implementation, $throwable);
            }
        }

        return $cleared;
    }

    private function logFailure(string $capability, string $implementation, \Throwable $throwable): void
    {
        if (!\function_exists('w_log_error')) {
            return;
        }

        w_log_error(
            "[ProcessCache] Reset failed for {$capability}: {$throwable->getMessage()}",
            ['implementation' => $implementation],
            'wls',
        );
    }
}
