<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Weline 开发面板必须盖过店面常驻浮层（进店音乐头像 / 客服球），
 * 否则同底高时会被压住无法操作时间线与详情侧栏。
 */
final class DevToolPanelStackingContractTest extends TestCase
{
    public function testDevToolPanelStacksAboveStorefrontResidentFloats(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $panel = (string)file_get_contents($moduleRoot . '/view/hooks/dev-tool-panel.phtml');
        self::assertSame(
            1,
            preg_match('/#dev-tool-panel\s*\{[^}]*?z-index:\s*(\d+)/s', $panel, $panelMatch),
            '#dev-tool-panel 必须声明数值 z-index',
        );
        $panelZ = (int)$panelMatch[1];

        $musicCss = (string)file_get_contents(
            dirname($moduleRoot) . '/StoreMusic/view/statics/css/store-music.css',
        );
        self::assertSame(1, preg_match('/--w-store-music-z-widget:\s*(\d+)/', $musicCss, $musicMatch));
        $musicZ = (int)$musicMatch[1];
        self::assertGreaterThan(
            $musicZ,
            $panelZ,
            '开发面板必须高于进店音乐浮层，否则头像会挡在时间线/详情上',
        );

        $csCss = (string)file_get_contents(
            dirname($moduleRoot) . '/CustomerService/view/statics/css/customer-service.css',
        );
        self::assertSame(
            1,
            preg_match(
                '/\.customer-service-widget\.has-unread[\s\S]*?z-index:\s*(\d+)/',
                $csCss,
                $csMatch,
            ),
        );
        $csZ = (int)$csMatch[1];
        self::assertGreaterThan(
            $csZ,
            $panelZ,
            '开发面板必须高于客服未读/打开态，否则客服球会挡面板',
        );

        // 仍须低于主题编辑器 / 预览工具浮层（2147483100+）与 token 对话框（2147483600）。
        self::assertLessThan(2147483100, $panelZ, '开发面板层不应越过主题编辑器/预览工具浮层');
        self::assertLessThan(2147483600, $panelZ, '开发面板层应低于 panel-token 对话框');
    }
}
