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
     * How long a caller with no request identity may reuse a frozen authority
     * clock before revalidating it against the database.
     *
     * A process that is not serving a request (Master control plane, CLI,
     * startup warmup) has no request id, so `state()` can never retain
     * anything for it. Re-reading `@clock` on every cache access there is pure
     * control-plane latency: one boot of the Master issued tens of thousands
     * of identical `@clock` SELECTs, and the control socket answered late
     * while it did. Local commits still revalidate immediately through
     * `advance()` / `replaceProcessSnapshot()`, so this window only bounds how
     * long a *cross-process* bump may go unnoticed outside a request.
     */
    private const PROCESS_SCOPED_CLOCK_TTL_SEC = 2.0;

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
     * Frozen vector for callers with no request identity, plus the time its
     * clock was read. Kept on the instance (one per process) so consecutive
     * cache accesses inside the control plane reuse one database read.
     *
     * @var array{request_id:?string,authority_clock:?int,generations:array<string,int>}
     */
    private array $processScopedState = [
        'request_id' => null,
        'authority_clock' => null,
        'generations' => [],
    ];

    private float $processScopedAt = 0.0;

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
        // With no request identity the request-scoped store can never retain
        // anything, so every access used to return to the database. Freeze the
        // resolved clock per process instead; local commits still revalidate
        // through `advance()` / `replaceProcessSnapshot()`, and the bounded
        // window revalidates against cross-process bumps.
        $processScoped = $requestId === null;
        $state = $processScoped
            ? $this->processScopedState()
            : $this->state($requestId);
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
                $this->fillMissingGenerations(
                    $state,
                    $ancestors,
                    $authorityClock,
                    $batchLoader,
                );
            }
            $state['authority_clock'] = $authorityClock;
            $state['request_id'] = $requestId;
        } elseif ($missing !== []) {
            $this->fillMissingGenerations(
                $state,
                $missing,
                \max(0, (int)($state['authority_clock'] ?? 0)),
                $batchLoader,
            );
        }

        // `fillMissingGenerations()` may publish a newer process snapshot, so
        // the write-back happens last and never clobbers a concurrent bump.
        if ($processScoped) {
            if ($this->processSnapshot['authority_clock'] <= \max(0, (int)($state['authority_clock'] ?? 0))) {
                $this->processScopedState = $state;
                $this->processScopedAt = \microtime(true);
            } else {
                $this->forgetProcessScopedState();
            }
        } elseif ($requestId !== null) {
            $this->store($state);
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
        // A newer reconciled snapshot outdates any frozen control-plane vector.
        $this->forgetProcessScopedState();
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

    /**
     * Frozen vector for callers with no request identity, or an empty state
     * once the bounded window has elapsed so the caller revalidates once.
     *
     * @return array{request_id:?string,authority_clock:?int,generations:array<string,int>}
     */
    private function processScopedState(): array
    {
        if (!\is_int($this->processScopedState['authority_clock'])) {
            return $this->emptyState();
        }
        if (self::PROCESS_SCOPED_CLOCK_TTL_SEC > 0.0
            && (\microtime(true) - $this->processScopedAt) >= self::PROCESS_SCOPED_CLOCK_TTL_SEC
        ) {
            return $this->emptyState();
        }

        return $this->processScopedState;
    }

    /** @return array{request_id:?string,authority_clock:?int,generations:array<string,int>} */
    private function emptyState(): array
    {
        return [
            'request_id' => null,
            'authority_clock' => null,
            'generations' => [],
        ];
    }

    /** Drop the frozen control-plane vector so the next lookup revalidates. */
    private function forgetProcessScopedState(): void
    {
        $this->processScopedState = $this->emptyState();
        $this->processScopedAt = 0.0;
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

    /**
     * 优先复用同代次进程快照，仅补查缺失成员。回填时保留等待期间的新代次和其他成员。
     *
     * @param array{request_id:?string,authority_clock:?int,generations:array<string,int>} $state
     * @param list<string> $namespaces
     * @param callable(list<string>):array<string,int> $batchLoader
     */
    private function fillMissingGenerations(
        array &$state,
        array $namespaces,
        int $authorityClock,
        callable $batchLoader,
    ): void {
        $process = $this->processSnapshot;
        $stillMissing = $namespaces;
        if ($authorityClock === $process['authority_clock']) {
            $filled = [];
            foreach ($namespaces as $namespace) {
                if (!\array_key_exists($namespace, $process['generations'])) {
                    continue;
                }
                $state['generations'][$namespace] = \max(0, (int)$process['generations'][$namespace]);
                $filled[] = $namespace;
            }
            $stillMissing = \array_values(\array_diff($namespaces, $filled));
        }

        if ($stillMissing === []) {
            return;
        }

        $loaded = $batchLoader($stillMissing);
        $loadedVector = [];
        foreach ($stillMissing as $namespace) {
            $generation = \max(0, (int)($loaded[$namespace] ?? 0));
            $state['generations'][$namespace] = $generation;
            $loadedVector[$namespace] = $generation;
        }

        // 数据库读取可能让出 Fiber；旧请求不能覆盖期间收到的失效通知。
        $process = $this->processSnapshot;
        if ($process['authority_clock'] > $authorityClock) {
            return;
        }
        if ($authorityClock === $process['authority_clock']) {
            $merged = $process['generations'];
            foreach ($loadedVector as $namespace => $generation) {
                $merged[$namespace] = \max((int)($merged[$namespace] ?? 0), $generation);
            }
            \ksort($merged, \SORT_STRING);
            $this->replaceProcessSnapshot($authorityClock, $merged);
        } else {
            \ksort($loadedVector, \SORT_STRING);
            $this->replaceProcessSnapshot($authorityClock, $loadedVector);
        }
    }
}
