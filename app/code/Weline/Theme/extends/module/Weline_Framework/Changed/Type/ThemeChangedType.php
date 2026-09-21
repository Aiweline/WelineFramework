<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Changed\Type;

use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class ThemeChangedType implements ChangedTypeInterface
{
    public function code(): string
    {
        return 'theme';
    }

    public function description(): string
    {
        return '主题资源变更';
    }

    public function allowedActions(): array
    {
        return ['upsert', 'delete', 'publish', 'unpublish'];
    }

    public function enrich(ResourceChange $change): array
    {
        $impact = $change->toArray()['impact'] ?? [];
        $namespaces = $this->stringList($impact['namespaces'] ?? []);
        if ($namespaces === []) {
            $code = $change->websiteCode();
            $namespaces = $code !== ''
                ? ['website/' . $code . '/theme', 'website/' . $code]
                : ['theme'];
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
        if ($this->isPreview($change)) {
            return [
                new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
            ];
        }
        $action = $change->action();
        if ($action === 'publish' || $action === 'unpublish') {
            return [
                new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
                new InvalidationEffect(
                    InvalidationEffect::CODE_PURGE_FPC_ALL,
                    InvalidationEffect::PHASE_AFTER_COMMIT,
                    ['source_module' => 'Weline_Theme', 'reason' => 'theme_' . $action],
                ),
                new InvalidationEffect(InvalidationEffect::CODE_THEME_RUNTIME_CLEAR, InvalidationEffect::PHASE_AFTER_COMMIT),
                new InvalidationEffect(
                    InvalidationEffect::CODE_CDN_PURGE,
                    InvalidationEffect::PHASE_AFTER_COMMIT,
                    ['allow_empty' => true],
                ),
            ];
        }
        $effects = [
            new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC),
            new InvalidationEffect(InvalidationEffect::CODE_THEME_RUNTIME_CLEAR, InvalidationEffect::PHASE_AFTER_COMMIT),
        ];
        $impact = $change->toArray()['impact'] ?? [];
        $urls = array_merge(
            is_array($impact['urls'] ?? null) ? $impact['urls'] : [],
            is_array($impact['previous_urls'] ?? null) ? $impact['previous_urls'] : [],
        );
        if ($urls !== []) {
            $effects[] = new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT);
            $effects[] = new InvalidationEffect(InvalidationEffect::CODE_CDN_PURGE, InvalidationEffect::PHASE_AFTER_COMMIT);
        }
        return $effects;
    }

    private function isPreview(ResourceChange $change): bool
    {
        $after = $change->toArray()['after'] ?? [];
        return is_array($after) && (!empty($after['preview']) || !empty($after['is_preview'])
            || strtolower((string)($after['status'] ?? '')) === 'draft');
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
