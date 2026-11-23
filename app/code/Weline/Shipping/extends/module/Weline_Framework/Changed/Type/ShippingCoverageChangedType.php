<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * 配送覆盖变更：bump shipping/coverage 命名空间，并 purge 政策页与 Feed 文档 slug。
 *
 * 保存配送服务/国家覆盖/费率后立即生效，不依赖部件缓存 TTL 与政策页 1 年 FPC。
 */
final class ShippingCoverageChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'shipping_coverage';
    }

    public function description(): string
    {
        return '配送覆盖变更（政策页 + Feed）';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete'];
    }

    public function enrich(ResourceChange $change): array
    {
        $data = $change->toArray();
        $impact = is_array($data['impact'] ?? null) ? $data['impact'] : [];
        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === []) {
            $namespaces = ['global/shipping/coverage'];
        }
        $slugs = $this->stringList($impact['slugs'] ?? []);

        return [
            'namespaces' => $namespaces,
            'previous_namespaces' => $this->stringList($impact['previous_namespaces'] ?? []),
            'urls' => $this->toUrls($slugs),
            'previous_urls' => $this->stringList($impact['previous_urls'] ?? []),
        ];
    }

    public function recipe(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $slugs = $this->toUrls($this->stringList(is_array($impact) ? ($impact['slugs'] ?? []) : []));
        $effects = [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
        ];
        if ($slugs !== []) {
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_PURGE_FPC_URLS,
                InvalidationEffect::PHASE_AFTER_COMMIT,
                ['slugs' => $slugs],
            );
            $effects[] = new InvalidationEffect(
                InvalidationEffect::CODE_THEME_RUNTIME_CLEAR,
                InvalidationEffect::PHASE_AFTER_COMMIT,
            );
        }

        return $effects;
    }

    /**
     * @param list<string> $slugs
     * @return list<string>
     */
    private function toUrls(array $slugs): array
    {
        return array_map(static fn(string $slug): string => '/' . ltrim($slug, '/'), $slugs);
    }

    /** @param mixed $values @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $out[$value] = $value;
            }
        }

        return array_values($out);
    }
}
