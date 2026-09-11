<?php

declare(strict_types=1);

namespace Weline\Framework\Event\ResourceChange\Observer;

use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Runtime\RequestContext;

/**
 * Deletes declared cache pool keys after the owning DB commit.
 *
 * Delivery SLA: accelerate eviction of bare keys; correctness for namespaced
 * readers remains with CacheNamespaceObserver bump. Millisecond dirty windows
 * are acceptable. Broadcast handlers must never re-enter this path.
 */
final class CacheImpactObserver implements ObserverInterface
{
    private const STATE_KEY = 'framework.resource_change.cache_ops';

    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly DbManagerFactory $dbManager,
    ) {
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new \InvalidArgumentException(__('资源变更缓存 Observer 只接受 ResourceChange v1'));
        }

        $impact = $change->toArray()['impact'] ?? [];
        $ops = $impact['cache_ops'] ?? null;
        if (!is_array($ops) || $ops === []) {
            return;
        }

        $connection = $this->dbManager->create();
        $eventId = $change->eventId();
        $apply = static function () use ($ops): void {
            self::applyCacheOps($ops);
        };

        if (!$this->transactions->isActive($connection)) {
            $apply();
            return;
        }

        $pending = RequestContext::get(self::STATE_KEY, []);
        $pending = is_array($pending) ? $pending : [];
        $pending[$eventId] = $ops;
        RequestContext::set(self::STATE_KEY, $pending);

        $this->transactions->afterRollback(
            $connection,
            'framework_cache_impact_rollback',
            static function () use ($eventId): bool {
                $pending = RequestContext::get(self::STATE_KEY, []);
                if (is_array($pending)) {
                    unset($pending[$eventId]);
                    if ($pending === []) {
                        RequestContext::remove(self::STATE_KEY);
                    } else {
                        RequestContext::set(self::STATE_KEY, $pending);
                    }
                }
                return true;
            },
        );

        $this->transactions->afterCommit(
            $connection,
            'framework_cache_impact_commit',
            static function (): void {
                $pending = RequestContext::get(self::STATE_KEY, []);
                RequestContext::remove(self::STATE_KEY);
                if (!is_array($pending) || $pending === []) {
                    return;
                }
                foreach ($pending as $ops) {
                    if (is_array($ops)) {
                        self::applyCacheOps($ops);
                    }
                }
            },
        );
    }

    /** @param list<mixed> $ops */
    private static function applyCacheOps(array $ops): void
    {
        $byPool = [];
        foreach ($ops as $op) {
            if (!is_array($op)) {
                continue;
            }
            $pool = trim((string)($op['pool'] ?? ''));
            $keys = $op['keys'] ?? null;
            if ($pool === '' || !is_array($keys) || $keys === []) {
                continue;
            }
            foreach ($keys as $key) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                $byPool[$pool][$key] = $key;
            }
        }

        foreach ($byPool as $pool => $keys) {
            $cache = w_cache($pool);
            foreach ($keys as $key) {
                $cache->delete($key);
            }
        }
    }
}
