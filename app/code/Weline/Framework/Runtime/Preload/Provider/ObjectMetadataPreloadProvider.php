<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Manager\Di\ReflectionArtifactContract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

final class ObjectMetadataPreloadProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'object_metadata';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 10;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $stats = ObjectManager::preloadRuntimeMetadata();
        $items = 0;
        foreach ($stats as $key => $value) {
            if (\is_int($value) && !\str_ends_with((string)$key, '_reject_reason')) {
                $items += $value;
            }
        }

        $rejectParts = [];
        $factoriesReject = $stats['compiled_factories_reject_reason'] ?? null;
        $metadataReject = $stats['reflection_metadata_reject_reason'] ?? null;
        if (\is_string($factoriesReject) && $factoriesReject !== '') {
            $rejectParts[] = 'compiled_factories=' . $factoriesReject;
        }
        if (\is_string($metadataReject) && $metadataReject !== '') {
            $rejectParts[] = 'reflection_metadata=' . $metadataReject;
        }
        if ($rejectParts !== [] && \function_exists('w_log_warning')) {
            \w_log_warning(
                '[WorkerPreload] object_metadata artifact rejected: ' . \implode('; ', $rejectParts)
                . ' (legacy flat or format mismatch — run php bin/w reflection:compile)'
            );
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        // Present-but-invalid envelopes (legacy flat / format / php version) fail closed.
        // Missing artifacts stay warmed(0) so first-boot before reflection:compile can proceed.
        $factoriesCount = (int)($stats['compiled_factories'] ?? 0);
        $metadataCount = (int)($stats['reflection_metadata'] ?? 0);
        $hardRejects = \array_values(\array_filter(
            [$factoriesReject, $metadataReject],
            static fn($reason): bool => \is_string($reason)
                && $reason !== ''
                && $reason !== ReflectionArtifactContract::REJECT_MISSING
        ));
        if ($hardRejects !== [] && $factoriesCount === 0 && $metadataCount === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $context->phase(),
                'artifact_rejected:' . \implode(',', $hardRejects),
                $durationMs,
                $memoryDelta
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            $durationMs,
            $memoryDelta,
            $stats
        );
    }

    public function invalidationKeys(): array
    {
        return ['generated/reflection_metadata.php', 'generated/compiled_factories.php', 'generated/plugins.php'];
    }
}
