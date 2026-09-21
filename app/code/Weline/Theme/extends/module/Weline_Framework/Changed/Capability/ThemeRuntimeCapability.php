<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Changed\Capability;

use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/**
 * 主题运行时缓存清理（原 Theme ResourceChanged 非 FPC 旁路迁入）。
 */
final class ThemeRuntimeCapability implements ChangedCapabilityInterface
{
    public function code(): string
    {
        return 'theme_runtime';
    }

    public function description(): string
    {
        return '主题/布局发布时清理主题运行时缓存';
    }

    public function supportedEffects(): array
    {
        return [InvalidationEffect::CODE_THEME_RUNTIME_CLEAR];
    }

    public function execute(InvalidationEffect $effect, ResourceChange $change): void
    {
        if ($effect->code !== InvalidationEffect::CODE_THEME_RUNTIME_CLEAR) {
            return;
        }
        $themeId = null;
        if (in_array($change->resourceType(), ['theme', 'theme_layout'], true)
            && ctype_digit($change->resourceId())) {
            $themeId = (int)$change->resourceId();
            if ($themeId <= 0) {
                $themeId = null;
            }
        }
        /** @var ThemeRuntimeCacheCleaner $cleaner */
        $cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
        $result = $cleaner->clearAllThemeRelatedCaches(
            $themeId,
            'changed:' . $change->resourceType(),
        );
        if (($result['failures'] ?? []) !== []) {
            throw new \RuntimeException(__('Theme 资源变更缓存刷新未全部成功'));
        }
    }
}
