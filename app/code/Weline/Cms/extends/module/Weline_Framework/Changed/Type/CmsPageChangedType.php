<?php

declare(strict_types=1);

namespace Weline\Cms\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class CmsPageChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'cms_page';
    }

    public function description(): string
    {
        return 'CMS 页面变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete', 'publish', 'unpublish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $data = $change->toArray();
        $impact = is_array($data['impact'] ?? null) ? $data['impact'] : [];
        $after = is_array($data['after'] ?? null) ? $data['after'] : [];
        $before = is_array($data['before'] ?? null) ? $data['before'] : [];

        $urls = $this->stringList($impact['urls'] ?? []);
        $previous = $this->stringList($impact['previous_urls'] ?? []);
        if ($urls === [] && isset($after['url'])) {
            $urls = $this->stringList([(string)$after['url']]);
        }
        if ($previous === [] && isset($before['url'])) {
            $previous = $this->stringList([(string)$before['url']]);
        }

        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === []) {
            $code = $change->websiteCode();
            $pageId = $change->resourceId();
            if ($code !== '') {
                $namespaces = [
                    'website/' . $code . '/cms',
                    'website/' . $code . '/cms/' . $pageId,
                ];
            }
        } else {
            // Extra 读路径挂 website/{code}/cms；写路径必须同时 bump 父级。
            $code = $change->websiteCode();
            if ($code !== '') {
                $parent = 'website/' . $code . '/cms';
                if (!in_array($parent, $namespaces, true)) {
                    array_unshift($namespaces, $parent);
                }
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
        $after = $change->toArray()['after'] ?? [];
        if (!is_array($after)) {
            return false;
        }
        $status = strtolower((string)($after['status'] ?? ''));
        return in_array($status, ['draft', 'preview'], true)
            || !empty($after['preview'])
            || !empty($after['is_preview']);
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
