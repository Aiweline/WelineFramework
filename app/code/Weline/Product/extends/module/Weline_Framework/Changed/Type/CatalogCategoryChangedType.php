<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * Storefront category / catalog nav surfaces → URL-scoped FPC + CDN purge.
 */
final class CatalogCategoryChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'catalog_category';
    }

    public function description(): string
    {
        return '店面分类/导航变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete', 'publish', 'unpublish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $data = $change->toArray();
        $impact = \is_array($data['impact'] ?? null) ? $data['impact'] : [];
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
            'urls' => $this->stringList($impact['urls'] ?? []),
            'previous_urls' => $this->stringList($impact['previous_urls'] ?? []),
        ];
    }

    public function recipe(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $urls = \array_merge(
            $this->stringList($impact['urls'] ?? []),
            $this->stringList($impact['previous_urls'] ?? []),
        );
        $effects = [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
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
        }

        return $effects;
    }

    /** @param mixed $values @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            $v = \trim((string)$v);
            if ($v !== '') {
                $out[$v] = $v;
            }
        }

        return \array_values($out);
    }
}
