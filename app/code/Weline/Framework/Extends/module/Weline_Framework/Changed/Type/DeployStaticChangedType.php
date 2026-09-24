<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * 部署静态 / 呈现世代变更：bump global/storefront/deploy；Mode\Set prod 叠加 purge_fpc_all。
 */
final class DeployStaticChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'deploy_static';
    }

    public function description(): string
    {
        return '部署静态与店面呈现世代（deploy stamp / FPC）';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'publish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === []) {
            $namespaces = [DeployFpcInvalidation::NS_STOREFRONT_DEPLOY];
        }

        return [
            'namespaces' => $namespaces,
            'previous_namespaces' => $this->stringList($impact['previous_namespaces'] ?? []),
            'urls' => $this->stringList($impact['urls'] ?? []),
            'previous_urls' => $this->stringList($impact['previous_urls'] ?? []),
        ];
    }

    public function recipe(ResourceChange $change): array
    {
        $effects = [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
        ];
        $after = $change->toArray()['after'] ?? [];
        $purge = is_array($after) && !empty($after['purge_fpc_all']);
        if ($purge) {
            $reason = is_array($after) ? (string)($after['reason'] ?? 'deploy_static') : 'deploy_static';
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_PURGE_FPC_ALL,
                InvalidationEffect::PHASE_AFTER_COMMIT,
                [
                    'source_module' => 'Weline_Framework',
                    'reason' => $reason !== '' ? $reason : 'deploy_static',
                ],
            );
        }

        return $effects;
    }

    /** @param mixed $values @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $out[$v] = $v;
            }
        }

        return array_values($out);
    }
}
