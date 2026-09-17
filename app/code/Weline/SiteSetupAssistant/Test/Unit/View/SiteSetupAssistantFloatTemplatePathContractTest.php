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
    }
}
