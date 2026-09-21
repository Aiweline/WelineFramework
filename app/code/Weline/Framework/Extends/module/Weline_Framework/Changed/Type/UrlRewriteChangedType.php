<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * 可选：url_rewrite 与 urls 材料同源时顺带接入。
 */
final class UrlRewriteChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'url_rewrite';
    }

    public function description(): string
    {
        return 'URL 重写变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete', 'publish', 'unpublish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $after = $change->toArray()['after'] ?? [];
        $before = $change->toArray()['before'] ?? [];
        $urls = $this->stringList($impact['urls'] ?? []);
        $previous = $this->stringList($impact['previous_urls'] ?? []);
        if ($urls === [] && is_array($after)) {
            foreach (['request_path', 'url', 'target_path'] as $k) {
                if (!empty($after[$k])) {
                    $urls[] = (string)$after[$k];
                }
            }
            $urls = $this->stringList($urls);
        }
        if ($previous === [] && is_array($before)) {
            foreach (['request_path', 'url', 'target_path'] as $k) {
                if (!empty($before[$k])) {
                    $previous[] = (string)$before[$k];
                }
            }
            $previous = $this->stringList($previous);
        }
        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === [] && $change->websiteCode() !== '') {
            $namespaces = ['website/' . $change->websiteCode() . '/url'];
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
        return [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
            new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT),
            new InvalidationEffect(InvalidationEffect::CODE_CDN_PURGE, InvalidationEffect::PHASE_AFTER_COMMIT),
        ];
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
