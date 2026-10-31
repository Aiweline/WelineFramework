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
 * 只服务 setup:upgrade：首次安装时就地固化布局模板（§0 R5/R6）。
 *
 * setup:upgrade 后只有两种动作：
 * - 主题还没有代次指针（首次安装 / 空站）→ 就地固化，保证升级后店面有产物可用；
 * - 所有绑定主题都已有代次指针 → 只写脏标记，不在维护窗内改线上主题字节。
 * setup.layout_solidify=never 不固化；dev 环境始终就地固化。
 *
 * core:update 与 deploy:upgrade 不再触发主题布局固化（本观察者不挂这两个事件）。
 * 主题产物由 `php bin/w theme:update` 负责（ThemeUpgradeCutoverService →
 * ThemeLayoutEntityUpgradeSolidifyService::cutover），外加上面说的首次安装就地固化。
 * （原每分钟轮询的 theme_update_auto_publish cron 已删除：发布由 SSE 同步收敛，不需要轮询兜底。）
 *
 * @see app/code/Weline/Theme/doc/布局固化与默认注入.md §0 · §3.4
 */
final class SetupUpgradeAfterPurgeLayoutEntities implements ObserverInterface
{
    /** 本观察者只挂 setup:upgrade，reason 固定（原 deploy/core 分支已随事件解绑删除）。 */
    private const REASON = 'setup_upgrade_layout_entities_solidified';

    public function __construct(
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidifyService,
        private readonly ThemeApplicationUsageService $themeApplicationUsage,
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($this->shouldDeferToThemeUpdate()) {
            $this->deferToThemeUpdate(self::REASON);

            return;
        }
        $this->solidifyService->runOnce(self::REASON);
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
}
