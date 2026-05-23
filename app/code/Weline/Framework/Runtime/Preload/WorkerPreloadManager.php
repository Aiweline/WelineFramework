<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

use Weline\Framework\Runtime\Preload\Provider\EventDescriptorPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\ExtensionRegistryPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\ObjectMetadataPreloadProvider;
use Weline\Framework\Runtime\Preload\Provider\RouteRegistryPreloadProvider;

final class WorkerPreloadManager
{
    /**
     * @param list<WorkerPreloadProviderInterface> $providers
     */
    public function __construct(
        private array $providers
    ) {
    }

    public static function createDefault(): self
    {
        return new self([
            new ObjectMetadataPreloadProvider(),
            new RouteRegistryPreloadProvider(),
            new ExtensionRegistryPreloadProvider(),
            new EventDescriptorPreloadProvider(),
        ]);
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
        \usort(
            $providers,
            static fn(WorkerPreloadProviderInterface $a, WorkerPreloadProviderInterface $b): int => $a->priority() <=> $b->priority()
        );

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
