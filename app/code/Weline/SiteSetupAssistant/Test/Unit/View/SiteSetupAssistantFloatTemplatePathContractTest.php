<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Linux 区分大小写：fetch/Widget 字面路径必须与磁盘目录一致（backend 小写）。
 */
final class SiteSetupAssistantFloatTemplatePathContractTest extends TestCase
{
    public function testFloatTemplateExistsAtDeclaredLowercaseBackendPath(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $relative = 'view/templates/backend/widgets/site-setup-assistant-float.phtml';
        $absolute = $moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        self::assertFileExists($absolute);

        $hook = $moduleRoot . '/view/hooks/Weline_Theme/backend/layouts/base/body-end.phtml';
        $widget = $moduleRoot . '/extends/module/Weline_Widget/Weline_SiteSetupAssistant/widget.php';
        self::assertFileExists($hook);
        self::assertFileExists($widget);

        $hookSrc = (string)file_get_contents($hook);
        $widgetSrc = (string)file_get_contents($widget);
        self::assertStringContainsString(
            "templates/backend/widgets/site-setup-assistant-float.phtml",
            $hookSrc
        );
        self::assertStringContainsString(
            "templates/backend/widgets/site-setup-assistant-float.phtml",
            $widgetSrc
        );
        self::assertStringNotContainsString(
            "templates/Backend/widgets/site-setup-assistant-float.phtml",
            $hookSrc
        );

        $floatSrc = (string)file_get_contents($absolute);
        self::assertStringContainsString('collectGlobalOverview', $floatSrc);
        self::assertStringContainsString('ssa-float-capsules', $floatSrc);
        self::assertStringContainsString('全站', $floatSrc);
        self::assertStringNotContainsString('dashboard_website_id', $floatSrc);
        // 根节点不得默认 hidden：hook 未 bake 时 JS 不跑会永久不可见
        self::assertDoesNotMatchRegularExpression(
            '/id="ssa-float-root"[^>]*\bhidden\b/',
            $floatSrc
        );
        self::assertStringContainsString('data-ssa-panel hidden', $floatSrc);

        // Hook 直出须显式挂 CSS/JS（对齐客服浮层），不能只 fetch 模板
        self::assertStringContainsString('fetchTagSource', $hookSrc);
        self::assertStringContainsString('widget-site-setup-assistant-float.css', $hookSrc);
        self::assertStringContainsString('widget-assets-runtime.js', $hookSrc);
        self::assertStringContainsString('widget-site-setup-assistant-float-0.js', $hookSrc);
        self::assertStringContainsString('data-weline-ssa-float', $hookSrc);

        $floatJs = (string)file_get_contents(
            $moduleRoot . '/view/statics/js/widgets/widget-site-setup-assistant-float-0.js'
        );
        self::assertStringContainsString('stored === null', $floatJs);
    }
}
