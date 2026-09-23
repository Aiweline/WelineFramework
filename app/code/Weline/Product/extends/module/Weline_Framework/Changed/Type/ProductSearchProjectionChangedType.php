<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class ProductSearchProjectionChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'product_search_projection';
    }

    public function description(): string
    {
        return '商品搜索投影变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete', 'publish', 'unpublish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $data = $change->toArray();
        $after = is_array($data['after'] ?? null) ? $data['after'] : [];
        $before = is_array($data['before'] ?? null) ? $data['before'] : [];
        $impact = is_array($data['impact'] ?? null) ? $data['impact'] : [];

        $urls = $this->stringList($impact['urls'] ?? ($after['urls'] ?? []));
        $previous = $this->stringList($impact['previous_urls'] ?? ($before['urls'] ?? []));
        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === []) {
            $code = $change->websiteCode();
            if ($code !== '') {
                $namespaces = ['website/' . $code . '/catalog'];
            }
        }

        return [
            'namespaces' => $namespaces,
            'previous_namespaces' => $this->stringList($impact['previous_namespaces'] ?? []),
            'urls' => $urls,
            'previous_urls' => $previous,
        ];
    }

    public function recipe(ResourceChange $change): array
    {
        if ($this->isPreview($change)) {
            return [
                new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
            ];
        }
        return [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
            new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT),
            new InvalidationEffect(InvalidationEffect::CODE_CDN_PURGE, InvalidationEffect::PHASE_AFTER_COMMIT),
        ];
    }

    private function isPreview(ResourceChange $change): bool
    {
        $data = $change->toArray();
        $after = $data['after'] ?? [];
        if (!is_array($after)) {
            return false;
        }
        $status = strtolower((string)($after['status'] ?? $after['visibility'] ?? ''));
        if (in_array($status, ['draft', 'preview'], true)
            || !empty($after['preview'])
            || !empty($after['is_preview'])
        ) {
            return true;
        }
        // Draft create/save has no public loc yet; Recipe still must not demand FPC/CDN purge urls.
        $impact = is_array($data['impact'] ?? null) ? $data['impact'] : [];
        $urls = $this->stringList($impact['urls'] ?? ($after['urls'] ?? []));
        $previous = $this->stringList($impact['previous_urls'] ?? []);
        return $urls === [] && $previous === [];
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
