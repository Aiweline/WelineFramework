<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

final class ModuleRequestResetterRegistry implements RequestResetterInterface
{
    public const CAPABILITY_PREFIX = 'request_resetter.';

    public function __construct(
        private readonly ServiceProviderRegistry $providers,
    ) {
    }

    public function resetRequest(): void
    {
        foreach ($this->providers->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $capability => $implementation) {
            try {
                $resetter = ObjectManager::getInstance($implementation);
                if (!$resetter instanceof RequestResetterInterface) {
                    throw new \RuntimeException("{$implementation} must implement " . RequestResetterInterface::class);
                }
                $resetter->resetRequest();
            } catch (\Throwable $throwable) {
                if (function_exists('w_log_error')) {
                    w_log_error(
                        "[StateManager] Module request resetter {$capability} failed: {$throwable->getMessage()}",
                        ['implementation' => $implementation],
                        'wls',
                    );
                }
            }
        }
    }
}
