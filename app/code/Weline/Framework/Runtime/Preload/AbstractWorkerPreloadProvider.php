<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

/**
 * Convenience base for preload providers.
 *
 * Supplies the no-dependency default so providers that do not read another
 * provider's warm-up output stay a two-method implementation (code + preload).
 * Providers with ordering needs override {@see self::dependsOn()}.
 */
abstract class AbstractWorkerPreloadProvider implements WorkerPreloadProviderInterface
{
    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 100;
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function invalidationKeys(): array
    {
        return [];
    }
}
