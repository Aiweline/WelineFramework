<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;
use Weline\Theme\Service\Disk\ThemeDiskKeys;

/**
 * Recompile theme disk override CSS after setup:upgrade (sort=125).
 */
class SetupUpgradeAfterThemeDiskCompile implements ObserverInterface
{
    private static bool $hasRun = false;

    public function __construct(
        private readonly ThemeDiskCompileService $compileService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasRun) {
            return;
        }
        self::$hasRun = true;

        try {
            $this->printing->note((string)__('主题整盘合编开始…'));
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->clear()->select()->fetch()->getItems();
            $ok = 0;
            $fail = 0;
            $skip = 0;

            foreach ($themes as $theme) {
                if (!$theme instanceof WelineTheme || !(int)$theme->getId()) {
                    continue;
                }
                foreach (['frontend', 'backend'] as $area) {
                    try {
                        ThemeData::setCurrentTheme($theme);
                        ThemeData::setCurrentArea($area);
                        $bundleMap = ThemeData::getConfigList($area, 'disk_bundle', 'default');
                        $hash = (string)($bundleMap['default'] ?? '');
                        $hasCustom = $this->compileService->hasCustomActive($area, 'default');
                        if ($hash === '' && !$hasCustom) {
                            $skip++;
                            continue;
                        }
                        $this->compileService->compileBundle($theme, $area, 'default');
                        $ok++;
                    } catch (\Throwable $e) {
                        $fail++;
                        $this->printing->warning((string)__(
                            '主题整盘合编失败：theme=%{id} area=%{area} — %{msg}',
                            ['id' => (int)$theme->getId(), 'area' => $area, 'msg' => $e->getMessage()]
                        ));
                    }
                }
            }

            $this->printing->success((string)__(
                '主题整盘合编完成：成功 %{ok}，跳过 %{skip}，失败 %{fail}',
                ['ok' => $ok, 'skip' => $skip, 'fail' => $fail]
            ));
        } catch (\Throwable $e) {
            $this->printing->warning((string)__('主题整盘合编异常：%{msg}', ['msg' => $e->getMessage()]));
        }
    }

    /** @internal */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
