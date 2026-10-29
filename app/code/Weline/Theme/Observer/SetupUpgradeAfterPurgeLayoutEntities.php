<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;
use Weline\Theme\Service\ThemeApplicationUsageService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateBatchService;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime;

/**
 * setup:upgrade / deploy:upgrade / core:update 后：迁到 generated/ 并全主题预固化布局模板（§0 R5/R6）。
 *
 * 非 dev：已进入主题更新代次机制的站点不再就地固化（那会在维护窗内改线上主题字节），
 * 改为给绑定主题写脏标记，由发布流水线里的 `php bin/w theme:update` 构建新代次后翻指针。
 * （原每分钟轮询的 theme_update_auto_publish cron 已删除：发布由 SSE 同步收敛，不需要轮询兜底。）
 * 首次安装（主题还没有代次指针）仍就地固化，保证空站升级后店面有产物可用。
 *
 * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §0 · §3.4
 */
final class SetupUpgradeAfterPurgeLayoutEntities implements ObserverInterface
{
    public function __construct(
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidifyService,
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
    ) {
    }

    public function execute(Event &$event): void
    {
        $reason = $this->reasonForEvent($event);
        if ($this->shouldDeferToThemeUpdate()) {
            $this->deferToThemeUpdate($reason);

            return;
        }
        $this->solidifyService->runOnce($reason);
    }

    /** @internal */
    public static function resetHasRunFlag(): void
    {
        ThemeLayoutEntityUpgradeSolidifyService::resetHasRunFlag();
    }

    private function shouldDeferToThemeUpdate(): bool
    {
        try {
            $env = Env::getInstance();
            if ((string)$env->getConfig('setup.layout_solidify', 'auto') === 'never') {
                return false;
            }
            if ((string)(Env::system('deploy') ?? '') === 'dev') {
                return false;
            }

            return (bool)$env->getConfig('theme_defer_solidify_on_setup_upgrade', true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 所有绑定主题都已有代次指针时才延后；只要有一个还没进代次机制，就保持原有的就地固化。
     */
    private function deferToThemeUpdate(string $reason): void
    {
        $themes = $this->themeApplicationUsage->themesForDefaultUpgrade();
        if ($themes === []) {
            $this->solidifyService->runOnce($reason);

            return;
        }
        $themeIds = [];
        foreach ($themes as $theme) {
            $themeId = (int)$theme->getId();
            if ($themeId < 1 || !ThemeUpdateBatchService::hasPointerForTheme($themeId)) {
                $this->solidifyService->runOnce($reason);

                return;
            }
            $themeIds[] = $themeId;
        }
        foreach ($themeIds as $themeId) {
            ThemeUpdateRuntime::markDirty($themeId, $reason);
        }
    }

    private function reasonForEvent(Event $event): string
    {
        return match ($event->getName()) {
            'Weline_Framework_Deploy::upgrade_after' => 'deploy_upgrade_layout_entities_solidified',
            'Weline_Deploy::core_update_after' => 'core_update_layout_entities_solidified',
            default => 'setup_upgrade_layout_entities_solidified',
        };
    }
}
