<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\Provider\CompiledRegistryWarmProvider;
use Weline\Framework\Runtime\Preload\Provider\CriticalRuntimeClassPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\EventDescriptorPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\ExtensionRegistryPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\ObjectMetadataPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\RouteRegistryPreloadProvider;

final class WorkerPreloadManager
{
    /**
     * Capability prefix modules use in etc/module.php `provides` to contribute a
     * preload provider, e.g.
     *
     *   'worker_preload_provider.Weline_Theme' => ThemePreloadProvider::class
     *
     * Providers discovered this way are appended to the framework's built-in
     * list; ordering is decided by dependsOn() + priority(), never by
     * registration order. This keeps module-owned warm-up inside the module:
     * the framework runtime only schedules, it does not know module internals.
     */
    public const PROVIDER_CAPABILITY_PREFIX = 'worker_preload_provider.';

    /**
     * @param list<WorkerPreloadProviderInterface> $providers
     */
    public function __construct(
        private array $providers
    ) {
    }

    public static function createDefault(): self
    {
        return new self(\array_merge(
            [
                new CompiledRegistryWarmProvider(),
                new ObjectMetadataPreloadProvider(),
                new RouteRegistryPreloadProvider(),
                new ExtensionRegistryPreloadProvider(),
                new EventDescriptorPreloadProvider(),
                new CriticalRuntimeClassPreloadProvider(),
            ],
            self::moduleProvidedProviders(),
        ));
    }

    /**
     * Collect providers contributed by modules through etc/module.php provides.
     *
     * Fail-open: a missing compiled registry (cold bootstrap before the first
     * framework:compile) or an unbuildable class must never block worker start —
     * the built-in providers still run.
     *
     * @return list<WorkerPreloadProviderInterface>
     */
    private static function moduleProvidedProviders(): array
    {
        try {
            if (!\class_exists(\Weline\Framework\Compilation\ServiceProviderRegistry::class)) {
                return [];
            }
            $registry = ObjectManager::getInstance(
                \Weline\Framework\Compilation\ServiceProviderRegistry::class
            );
            $implementations = $registry->implementationsWithPrefix(self::PROVIDER_CAPABILITY_PREFIX);
        } catch (\Throwable) {
            return [];
        }

        $providers = [];
        foreach ($implementations as $class) {
            if (!\is_string($class) || !\class_exists($class)) {
                continue;
            }
            try {
                $provider = ObjectManager::getInstance($class);
            } catch (\Throwable) {
                continue;
            }
            if ($provider instanceof WorkerPreloadProviderInterface) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return list<WorkerPreloadResult>
     */
    public function runPhase(string $phase, WorkerPreloadContext $context): array
    {
        $providers = \array_values(\array_filter(
            $this->providers,
            static fn(WorkerPreloadProviderInterface $provider): bool => $provider->phase() === $phase
        ));
        $providers = $this->orderByDependencies($providers);

        $results = [];
        foreach ($providers as $provider) {
            if (!$provider->isEnabled($context)) {
                $results[] = WorkerPreloadResult::skipped($provider->code(), $phase, 'disabled');
                continue;
            }

            $start = \microtime(true);
            $memoryStart = \memory_get_usage(true);
            try {
                $result = $provider->preload($context);
            } catch (\Throwable $e) {
                $result = WorkerPreloadResult::failed(
                    $provider->code(),
                    $phase,
                    $e->getMessage(),
                    \round((\microtime(true) - $start) * 1000, 2),
                    \memory_get_usage(true) - $memoryStart
                );
            }

            $this->logResult($result);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Order providers by declared dependencies, then by priority.
     *
     * A provider always runs after every provider it names in dependsOn().
     * Unrelated providers keep priority() order. Dependencies naming an unknown
     * code are ignored (that provider may not be registered in this build), and
     * a dependency cycle cannot dead-lock boot: providers still queued when no
     * progress is possible are appended in priority order.
     *
     * @param list<WorkerPreloadProviderInterface> $providers
     * @return list<WorkerPreloadProviderInterface>
     */
    private function orderByDependencies(array $providers): array
    {
        if (\count($providers) < 2) {
            return $providers;
        }

        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()] = $provider;
        }

        $remaining = $byCode;
        $ordered = [];
        // Guard: at most one pass per provider; a cycle exits early below.
        $limit = \count($remaining) + 1;
        while ($remaining !== [] && $limit-- > 0) {
            $ready = [];
            foreach ($remaining as $code => $provider) {
                $pending = false;
                foreach ($provider->dependsOn() as $dependency) {
                    if (isset($remaining[$dependency])) {
                        $pending = true;
                        break;
                    }
                }
                if (!$pending) {
                    $ready[] = $provider;
                }
            }
            if ($ready === []) {
                break;
            }
            \usort(
                $ready,
                static fn(WorkerPreloadProviderInterface $a, WorkerPreloadProviderInterface $b): int
                    => $a->priority() <=> $b->priority()
            );
            foreach ($ready as $provider) {
                $ordered[] = $provider;
                unset($remaining[$provider->code()]);
            }
        }

        if ($remaining !== []) {
            // Dependency cycle (or a dependency on an already-consumed code):
            // append the rest in priority order so boot still completes.
            $leftovers = \array_values($remaining);
            \usort(
                $leftovers,
                static fn(WorkerPreloadProviderInterface $a, WorkerPreloadProviderInterface $b): int
                    => $a->priority() <=> $b->priority()
            );
            foreach ($leftovers as $provider) {
                $ordered[] = $provider;
            }
        }

        return $ordered;
    }

    private function logResult(WorkerPreloadResult $result): void
    {
        if (!\function_exists('w_log_info') && !\function_exists('w_log_warning')) {
            return;
        }

        $message = '[WorkerPreload] phase=' . $result->phase()
            . ' provider=' . $result->provider()
            . ' status=' . $result->status()
            . ' items=' . $result->items()
            . ' duration_ms=' . $result->durationMs()
            . ' memory_delta=' . $result->memoryDelta();
        if ($result->message() !== '') {
            $message .= ' message=' . $result->message();
        }

        if ($result->status() === 'failed' && \function_exists('w_log_warning')) {
            \w_log_warning($message);
            return;
        }

        if (\function_exists('w_log_info')) {
            \w_log_info($message);
        }
    }
}
