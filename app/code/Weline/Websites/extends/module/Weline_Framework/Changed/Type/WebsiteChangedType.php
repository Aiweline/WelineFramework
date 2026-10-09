<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * Website 资源变更：bump 本地 namespace，并 recipe CDN / FPC 清理。
 */
final class WebsiteChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'website';
    }

    public function description(): string
    {
        return '网站资源变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete'];
    }

    public function enrich(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];

        return [
            'namespaces' => $this->stringList($impact['namespaces'] ?? []),
            'previous_namespaces' => $this->stringList($impact['previous_namespaces'] ?? []),
            'urls' => $this->stringList($impact['urls'] ?? []),
            'previous_urls' => $this->stringList($impact['previous_urls'] ?? []),
        ];
    }

    public function recipe(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $urls = array_merge(
            $this->stringList($impact['urls'] ?? []),
            $this->stringList($impact['previous_urls'] ?? []),
        );

        $effects = [
            new InvalidationEffect(
                InvalidationEffect::CODE_BUMP_NAMESPACES,
                InvalidationEffect::PHASE_SYNC,
            ),
        ];
        if ($urls !== []) {
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_PURGE_FPC_URLS,
                InvalidationEffect::PHASE_AFTER_COMMIT,
            );
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_CDN_PURGE,
                InvalidationEffect::PHASE_AFTER_COMMIT,
            );
        } else {
            // 无 URL 材料时仍进入 CDN 清理入口（本机可观测触发；无绑定域名则 skipped）
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_CDN_PURGE,
                InvalidationEffect::PHASE_AFTER_COMMIT,
                ['allow_empty' => true],
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
