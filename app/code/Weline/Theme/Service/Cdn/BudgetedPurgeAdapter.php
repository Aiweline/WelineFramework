<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 给任意 purge 适配器套上预算闸门，并记录观测数据。
 *
 * 预算耗尽时返回 false（不假装成功），规划器据此留下续跑点；
 * 观测记录里能看出「这次是预算挡下的，不是 CDN 拒绝的」。
 */
final class BudgetedPurgeAdapter implements CdnPurgeAdapterInterface
{
    /** @var list<array{zone:string,stage:string,allowed:bool,reason:string,count:int}> */
    private array $observations = [];

    public function __construct(
        private readonly CdnPurgeAdapterInterface $inner,
        private readonly PurgeBudgetGate $budget,
    ) {
    }

    public function purge(string $zone, array $targets): bool
    {
        if (!$this->budget->allow($zone)) {
            $this->observations[] = [
                'zone' => $zone,
                'stage' => 'budget',
                'allowed' => false,
                'reason' => 'budget_exhausted',
                'count' => \count($targets),
            ];

            return false;
        }

        $ok = $this->inner->purge($zone, $targets);
        $this->observations[] = [
            'zone' => $zone,
            'stage' => 'purge',
            'allowed' => true,
            'reason' => $ok ? 'purged' : 'purge_failed',
            'count' => \count($targets),
        ];

        return $ok;
    }

    /** @return list<array{zone:string,stage:string,allowed:bool,reason:string,count:int}> */
    public function observations(): array
    {
        return $this->observations;
    }
}
