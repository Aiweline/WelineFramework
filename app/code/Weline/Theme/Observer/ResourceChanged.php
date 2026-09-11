<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

final class ResourceChanged implements AsyncObserverInterface
{
    public function __construct(private readonly ThemeRuntimeCacheCleaner $cacheCleaner)
    {
    }

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'resource_change_contract_mismatch',
                __('Theme ResourceChange Observer 只接受 v1 契约'),
            );
        }
        if (!$this->affectsTheme($change)) {
            return;
        }

        $themeId = in_array($change->resourceType(), ['theme', 'theme_layout'], true)
            && ctype_digit($change->resourceId())
            ? (int)$change->resourceId()
            : null;
        $reason = 'resource_change:' . $change->resourceType();
        // Theme publish / layout publish must flush ALL theme-related caches.
        // Scoped-only generation bumps leave chrome/FPC/template/view pools stale,
        // so editor/storefront keep serving pre-publish widget HTML (e.g. unavailable tips).
        $result = $this->cacheCleaner->clearAllThemeRelatedCaches(
            $themeId !== null && $themeId > 0 ? $themeId : null,
            $reason,
        );
        if (($result['failures'] ?? []) !== []) {
            throw new \RuntimeException(__('Theme 资源变更缓存刷新未全部成功'));
        }
    }

    private function affectsTheme(ResourceChange $change): bool
    {
        if (in_array($change->resourceType(), ['theme', 'theme_layout'], true)) {
            return true;
        }
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $after = $change->toArray()['after'] ?? null;
        return is_array($after) && ($after['module'] ?? '') === 'Weline_Theme';
    }
}
