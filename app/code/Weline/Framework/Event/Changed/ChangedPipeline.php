<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Runtime\RequestContext;

/**
 * Enricher → Recipe → Capability；sync 立即执行，after_commit 挂事务钩子。
 * 未注册 ChangedType 时跳过管线（仍由 w_changed 继续 dispatch 事件）。
 */
class ChangedPipeline
{
    private const STATE_KEY = 'framework.changed.pipeline.after_commit';

    /** @var list<string> */
    private const PURGE_ALL_MODULES = [
        'Weline_Framework',
        'Weline_Server',
        'Weline_Cdn',
        'Weline_Theme',
    ];

    public function __construct(
        private readonly ChangedTypeRegistry $types,
        private readonly ChangedCapabilityRegistry $capabilities,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly DbManagerFactory $dbManager,
        private readonly UrlMatrixExpander $urlMatrix,
    ) {
    }

    public function process(ResourceChange $change): ResourceChange
    {
        $type = $this->types->get($change->resourceType());
        if ($type === null) {
            // 无 ChangedType：仍执行 namespaces bump + cache_ops（Seo 等事件听众照旧）
            return $this->runOrphanEffects($change);
        }

        $action = $change->action();
        if (!in_array($action, $type->allowedActions(), true)) {
            throw new \LogicException(__(
                'ChangedType %{type} 不允许 action=%{action}',
                ['type' => $type->code(), 'action' => $action]
            ));
        }

        $explicit = $this->impactFromChange($change);
        $derived = $type->enrich($change);
        $merged = $this->mergeImpact($derived, $explicit);
        $change = $this->withImpact($change, $merged);

        $effects = $type->recipe($change);
        $ops = $merged['cache_ops'] ?? null;
        if (is_array($ops) && $ops !== []) {
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_CACHE_OPS_DELETE,
                InvalidationEffect::PHASE_AFTER_COMMIT,
                ['cache_ops' => $ops],
            );
        }
        $this->assertEffectsMaterial($effects, $change);
        $this->assertPurgeAllWhitelist($effects);

        $sync = [];
        $after = [];
        foreach ($effects as $effect) {
            if ($effect->phase === InvalidationEffect::PHASE_SYNC) {
                $sync[] = $effect;
            } else {
                $after[] = $effect;
            }
        }

        foreach ($sync as $effect) {
            $this->executeEffect($effect, $change);
        }

        if ($after !== []) {
            $this->scheduleAfterCommit($change, $after);
        }

        return $change;
    }

    private function runOrphanEffects(ResourceChange $change): ResourceChange
    {
        $impact = $change->toArray()['impact'] ?? [];
        $effects = [];
        $paths = array_values(array_unique(array_merge(
            is_array($impact['namespaces'] ?? null) ? $impact['namespaces'] : [],
            is_array($impact['previous_namespaces'] ?? null) ? $impact['previous_namespaces'] : [],
        )));
        if ($paths !== []) {
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_BUMP_NAMESPACES,
                InvalidationEffect::PHASE_SYNC,
                ['namespaces' => $paths],
            );
        }
        $ops = $impact['cache_ops'] ?? null;
        if (is_array($ops) && $ops !== []) {
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_CACHE_OPS_DELETE,
                InvalidationEffect::PHASE_AFTER_COMMIT,
                ['cache_ops' => $ops],
            );
        }
        if ($effects === []) {
            return $change;
        }

        $sync = [];
        $after = [];
        foreach ($effects as $effect) {
            if ($effect->phase === InvalidationEffect::PHASE_SYNC) {
                $sync[] = $effect;
            } else {
                $after[] = $effect;
            }
        }
        foreach ($sync as $effect) {
            $this->executeEffect($effect, $change);
        }
        if ($after !== []) {
            $this->scheduleAfterCommit($change, $after);
        }
        return $change;
    }

    /** @param list<InvalidationEffect> $effects */
    private function assertEffectsMaterial(array $effects, ResourceChange $change): void
    {
        $impact = $change->toArray()['impact'] ?? [];
        foreach ($effects as $effect) {
            if ($effect->code !== InvalidationEffect::CODE_PURGE_FPC_URLS
                && $effect->code !== InvalidationEffect::CODE_CDN_PURGE) {
                continue;
            }
            $urls = array_merge(
                is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
                is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
            );
            $payloadUrls = is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [];
            if ($urls === [] && $payloadUrls === [] && empty($effect->payload['allow_empty'])) {
                throw new \LogicException(__(
                    'ChangedType %{type} Recipe 需要 urls，Enricher/impact 仍为空',
                    ['type' => $change->resourceType()]
                ));
            }
        }
    }

    /** @param list<InvalidationEffect> $effects */
    private function assertPurgeAllWhitelist(array $effects): void
    {
        foreach ($effects as $effect) {
            if ($effect->code !== InvalidationEffect::CODE_PURGE_FPC_ALL) {
                continue;
            }
            $module = trim((string)($effect->payload['source_module'] ?? ''));
            if ($module === '' || !in_array($module, self::PURGE_ALL_MODULES, true)) {
                throw new \LogicException(__(
                    'purge_fpc_all 仅允许平台模块：%{modules}',
                    ['modules' => implode(', ', self::PURGE_ALL_MODULES)]
                ));
            }
        }
    }

    private function executeEffect(InvalidationEffect $effect, ResourceChange $change): void
    {
        $capability = $this->capabilities->forEffect($effect->code);
        if ($capability === null) {
            throw new \LogicException(__(
                '无 Capability 可执行 Effect %{code}',
                ['code' => $effect->code]
            ));
        }
        if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_URLS) {
            $effect = $this->expandUrlMatrix($effect, $change);
        }
        $capability->execute($effect, $change);
    }

    private function expandUrlMatrix(InvalidationEffect $effect, ResourceChange $change): InvalidationEffect
    {
        $impact = $change->toArray()['impact'] ?? [];
        $base = array_values(array_unique(array_merge(
            is_array($effect->payload['urls'] ?? null) ? $effect->payload['urls'] : [],
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        )));
        $expanded = $this->urlMatrix->expand($base, $change->websiteId());
        $payload = $effect->payload;
        $payload['urls'] = $expanded['urls'];
        $payload['degraded'] = $expanded['degraded'];
        return new InvalidationEffect($effect->code, $effect->phase, $payload);
    }

    /**
     * @param list<InvalidationEffect> $effects
     */
    private function scheduleAfterCommit(ResourceChange $change, array $effects): void
    {
        $connection = $this->dbManager->create();
        $eventId = $change->eventId();
        $run = function () use ($effects, $change): void {
            foreach ($effects as $effect) {
                $this->executeEffect($effect, $change);
            }
        };

        if (!$this->transactions->isActive($connection)) {
            $run();
            return;
        }

        $pending = RequestContext::get(self::STATE_KEY, []);
        $pending = is_array($pending) ? $pending : [];
        $pending[$eventId] = ['change' => $change, 'effects' => $effects];
        RequestContext::set(self::STATE_KEY, $pending);

        $this->transactions->afterRollback(
            $connection,
            'framework_changed_pipeline_rollback',
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
            'framework_changed_pipeline_commit',
            function (): void {
                $pending = RequestContext::get(self::STATE_KEY, []);
                RequestContext::remove(self::STATE_KEY);
                if (!is_array($pending) || $pending === []) {
                    return;
                }
                foreach ($pending as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $change = $row['change'] ?? null;
                    $effects = $row['effects'] ?? null;
                    if (!$change instanceof ResourceChange || !is_array($effects)) {
                        continue;
                    }
                    foreach ($effects as $effect) {
                        if ($effect instanceof InvalidationEffect) {
                            $this->executeEffect($effect, $change);
                        }
                    }
                }
            },
        );
    }

    /** @return array<string, mixed> */
    private function impactFromChange(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        return is_array($impact) ? $impact : [];
    }

    /**
     * 显式 impact 覆盖推导（逃逸舱）。
     *
     * @param array<string, mixed> $derived
     * @param array<string, mixed> $explicit
     * @return array<string, mixed>
     */
    private function mergeImpact(array $derived, array $explicit): array
    {
        $out = $derived;
        foreach (['namespaces', 'previous_namespaces', 'urls', 'previous_urls', 'cache_ops'] as $key) {
            if (!array_key_exists($key, $explicit)) {
                continue;
            }
            $val = $explicit[$key];
            if (is_array($val) && $val !== []) {
                $out[$key] = $val;
            } elseif (!array_key_exists($key, $out)) {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $impact */
    private function withImpact(ResourceChange $change, array $impact): ResourceChange
    {
        $data = $change->toArray();
        foreach (['namespaces', 'previous_namespaces', 'urls', 'previous_urls'] as $key) {
            $list = [];
            foreach ((array)($impact[$key] ?? []) as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $list[$item] = $item;
                }
            }
            $sorted = array_values($list);
            sort($sorted, SORT_STRING);
            $data['impact'][$key] = $sorted;
        }
        if (array_key_exists('cache_ops', $impact)) {
            $data['impact']['cache_ops'] = $impact['cache_ops'];
        }
        return ResourceChange::fromArray($data);
    }
}
