<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

/**
 * WLS Worker bootstrap only: hydrate MUST-IN registries into CompiledPhpRegistry.
 * FPM stays lazy (first load() per path). Runtime load() skips include when already warmed.
 */
final class CompiledRegistryWarmProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'compiled_registry_warm';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 5;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        // Only WLS Worker bootstrap — never force-hydrate on FPM request paths.
        if ($context->runtimeMode() !== \Weline\Framework\Runtime\RuntimeInterface::MODE_WLS) {
            return false;
        }
        $role = \strtolower(\trim($context->role()));

        return !\in_array($role, ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'], true);
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $stats = CompiledPhpRegistry::warmPaths();
        $items = (int)$stats['warmed'] + (int)$stats['skipped'] + (int)$stats['missing'];

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            $stats
        );
    }

    public function invalidationKeys(): array
    {
        $keys = [];
        foreach (CompiledPhpRegistry::mustInRuntimePaths() as $path) {
            $relative = \str_replace('\\', '/', $path);
            $pos = \strpos($relative, 'generated/');
            if ($pos !== false) {
                $keys[] = \substr($relative, $pos);
            }
        }

        return $keys;
    }
}
