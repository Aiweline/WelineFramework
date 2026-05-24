<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Router\Core as Router;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

final class RouteRegistryPreloadProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'route_registry';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 20;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        Router::preloadGeneratedRouterFiles();
        $files = \glob(BP . 'generated' . DIRECTORY_SEPARATOR . 'routers' . DIRECTORY_SEPARATOR . '*.php') ?: [];

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            \count($files),
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            ['files' => \count($files)]
        );
    }

    public function invalidationKeys(): array
    {
        return ['generated/routers'];
    }
}
