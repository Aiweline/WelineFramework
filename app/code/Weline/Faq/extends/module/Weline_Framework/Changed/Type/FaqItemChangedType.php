<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * FAQ 条目变更：与店面 Extra（cms+theme）对齐，bump 父级 cms（及可选 theme）。
 */
final class FaqItemChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'faq.item';
    }

    public function description(): string
    {
        return 'FAQ 条目变更';
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
            $code = $change->websiteCode();
            if ($code === '') {
                $code = 'default';
            }
            $namespaces = [
                'website/' . $code . '/cms',
                'website/' . $code . '/theme',
            ];
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
        $urls = array_merge(
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        );
        $effects = [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
        ];
        if ($urls !== []) {
            $effects[] = new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT);
            $effects[] = new InvalidationEffect(InvalidationEffect::CODE_CDN_PURGE, InvalidationEffect::PHASE_AFTER_COMMIT);
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
