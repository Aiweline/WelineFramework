<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Namespace;

use Weline\Framework\Runtime\RequestContext;

/**
 * Fiber/request-local immutable snapshot store.
 *
 * The first lookup fixes the DB @clock, then either copies a complete process
 * vector at that clock or loads the requested ancestors. Later lookups in the
 * same request load only ancestors not yet frozen. With no request identity,
 * every lookup rechecks DB authority before serving a raw Worker fast path.
 */
final class NamespaceGenerationSnapshot
{
    private const STORAGE_KEY = 'framework.cache.namespace_generation_snapshot';

    /**
     * Process-local immutable value object. Every update replaces the complete
     * array; request Fibers only ever copy from it.
     *
     * @var array{authority_clock:int,generations:array<string,int>}
     */
    private array $processSnapshot = [
        'authority_clock' => 0,
        'generations' => [],
    ];

    /**
     * @param list<string> $ancestors
     * @param callable(list<string>):array<string,int> $batchLoader
     * @return array{authority_clock:int,generations:array<string,int>}
     */
    public function resolve(array $ancestors, callable $batchLoader): array
    {
        if ($ancestors === []) {
            throw new \InvalidArgumentException(__('命名空间祖先向量不能为空'));
        }

        $requestId = RequestContext::getId();
        $requestId = \is_string($requestId) && $requestId !== '' ? $requestId : null;
        $state = $this->state($requestId);
        $hasClock = \is_int($state['authority_clock']);
        $missing = array_values(array_diff($ancestors, array_keys($state['generations'])));

        if (!$hasClock) {
            $clockRow = $batchLoader([NamespacePath::AUTHORITY_CLOCK]);
            $authorityClock = \max(0, (int)($clockRow[NamespacePath::AUTHORITY_CLOCK] ?? 0));
            $process = $this->processSnapshot;
            $hasCompleteProcessVector = $authorityClock === $process['authority_clock']
                && \array_diff($ancestors, \array_keys($process['generations'])) === [];
            if ($hasCompleteProcessVector) {
                foreach ($ancestors as $ancestor) {
                    $state['generations'][$ancestor] = \max(
                        0,
                        (int)($process['generations'][$ancestor] ?? 0),
                    );
                }
            } else {
                $loaded = $batchLoader($ancestors);
                $loadedVector = [];
                foreach ($ancestors as $ancestor) {
                    $loadedVector[$ancestor] = \max(0, (int)($loaded[$ancestor] ?? 0));
                    $state['generations'][$ancestor] = $loadedVector[$ancestor];
                }
                \ksort($loadedVector, \SORT_STRING);
                if ($authorityClock === $process['authority_clock']) {
                    $loadedVector = \array_replace($process['generations'], $loadedVector);
                    \ksort($loadedVector, \SORT_STRING);
                }
                $this->replaceProcessSnapshot($authorityClock, $loadedVector);
            }
            $state['authority_clock'] = $authorityClock;
            $state['request_id'] = $requestId;
            if ($requestId !== null) {
                $this->store($state);
            }
        } elseif ($missing !== []) {
            $loaded = $batchLoader($missing);
            foreach ($missing as $ancestor) {
                $state['generations'][$ancestor] = max(0, (int)($loaded[$ancestor] ?? 0));
            }
            if ($requestId !== null) {
                $this->store($state);
            }
        }

        $vector = [];
        foreach ($ancestors as $ancestor) {
            $vector[$ancestor] = max(0, (int)($state['generations'][$ancestor] ?? 0));
        }
        ksort($vector, SORT_STRING);

        return [
            'authority_clock' => max(0, (int)($state['authority_clock'] ?? 0)),
            'generations' => $vector,
        ];
    }

    /** @param array<string,int> $generations */
    public function advance(int $authorityClock, array $generations): void
    {
        if ($authorityClock < 0) {
            throw new \InvalidArgumentException(__('命名空间权威时钟不能为负数'));
        }
        $process = $this->processSnapshot;
        // A clock jump proves at least one invalidation was not observed by
        // this process. Drop unknown vector members so their next access must
        // return to the DB instead of reusing a stale generation under a new
        // clock. A consecutive clock contains one complete delta and may keep
        // unaffected members.
        $nextGenerations = $authorityClock > ($process['authority_clock'] + 1)
            ? []
            : $process['generations'];
        foreach ($generations as $namespace => $generation) {
            if (!is_string($namespace) || $namespace === '' || !is_int($generation) || $generation < 0) {
                throw new \InvalidArgumentException(__('提交后的命名空间代际包含无效成员'));
            }
            $nextGenerations[$namespace] = \max((int)($nextGenerations[$namespace] ?? 0), $generation);
        }
        \ksort($nextGenerations, \SORT_STRING);
        $this->replaceProcessSnapshot(
            \max($process['authority_clock'], $authorityClock),
            $nextGenerations,
        );

        $requestId = RequestContext::getId();
        $requestId = \is_string($requestId) && $requestId !== '' ? $requestId : null;
        if ($requestId === null) {
            return;
        }
        $state = $this->state($requestId);
        $state['authority_clock'] = max((int)($state['authority_clock'] ?? 0), $authorityClock);
        foreach ($generations as $namespace => $generation) {
            if (!is_string($namespace) || $namespace === '' || !is_int($generation) || $generation < 0) {
                throw new \InvalidArgumentException(__('提交后的命名空间代际包含无效成员'));
            }
            $state['generations'][$namespace] = max(
                (int)($state['generations'][$namespace] ?? 0),
                $generation
            );
        }
        $this->store($state);
    }

    /** Replace a DB-reconciled process snapshot as one immutable value. */
    public function replaceProcessSnapshot(int $authorityClock, array $generations): void
    {
        if ($authorityClock < 0) {
            throw new \InvalidArgumentException(__('命名空间权威时钟不能为负数'));
        }
        $normalized = [];
        foreach ($generations as $namespace => $generation) {
            if (!\is_string($namespace)
                || $namespace === ''
                || !\is_int($generation)
                || $generation < 0
            ) {
                throw new \InvalidArgumentException(__('进程命名空间代际快照包含无效成员'));
            }
            $normalized[$namespace] = $generation;
        }
        \ksort($normalized, \SORT_STRING);
        $this->processSnapshot = [
            'authority_clock' => $authorityClock,
            'generations' => $normalized,
        ];
    }

    /** @return array{authority_clock:int,generations:array<string,int>} */
    public function processSnapshot(): array
    {
        return $this->processSnapshot;
    }

    /** @return list<string> */
    public function knownNamespaces(): array
    {
        return \array_keys($this->processSnapshot['generations']);
    }

    public function beginRequest(): void
    {
        $this->clear();
    }

    public function endRequest(): void
    {
        $this->clear();
    }

    public function clear(): void
    {
        RequestContext::remove(self::STORAGE_KEY);
    }

    /** @return array{request_id:?string,authority_clock:?int,generations:array<string,int>} */
    private function state(?string $requestId): array
    {
        $state = RequestContext::get(self::STORAGE_KEY, []);
        if (!is_array($state)) {
            $state = [];
        }
        $storedRequestId = \is_string($state['request_id'] ?? null) ? $state['request_id'] : null;
        if ($requestId === null || $storedRequestId !== $requestId) {
            $state = [];
        }
        $generations = $state['generations'] ?? [];
        return [
            'request_id' => $requestId,
            'authority_clock' => isset($state['authority_clock']) && is_int($state['authority_clock'])
                ? $state['authority_clock']
                : null,
            'generations' => is_array($generations) ? $generations : [],
        ];
    }

    /** @param array{request_id:?string,authority_clock:?int,generations:array<string,int>} $state */
    private function store(array $state): void
    {
        RequestContext::set(self::STORAGE_KEY, $state);
    }
}
