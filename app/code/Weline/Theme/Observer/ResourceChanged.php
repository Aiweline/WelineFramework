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
        $result = $this->cacheCleaner->clearNonGlobalCaches(
            $themeId,
            'resource_change:' . $change->resourceType(),
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
