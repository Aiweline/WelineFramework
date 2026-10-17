<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSiteSetupAssistantViewContractsPass().
 */
final class SiteSetupAssistantViewContractsTest extends TestCase
{
    public function testAllSiteSetupAssistantViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'SiteSetupAssistantFloatTemplatePath::testFloatTemplateExistsAtDeclaredLowercaseBackendPath' => function () {
            $this->assert_SiteSetupAssistantFloatTemplatePath_testFloatTemplateExistsAtDeclaredLowercaseBackendPath();
        },
        ];
    }

private function assert_SiteSetupAssistantFloatTemplatePath_testFloatTemplateExistsAtDeclaredLowercaseBackendPath(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/SiteSetupAssistant';
    $relative = 'view/templates/backend/widgets/site-setup-assistant-float.phtml';
    $absolute = $moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    self::assertFileExists($absolute);
    $hook = $moduleRoot . '/view/hooks/Weline_Theme/backend/layouts/base/body-end.phtml';
    $widget = $moduleRoot . '/extends/module/Weline_Widget/Weline_SiteSetupAssistant/widget.php';
    self::assertFileExists($hook);
    self::assertFileExists($widget);
    $hookSrc = (string) file_get_contents($hook);
    $widgetSrc = (string) file_get_contents($widget);
    self::assertStringContainsString("templates/backend/widgets/site-setup-assistant-float.phtml", $hookSrc);
    self::assertStringContainsString("templates/backend/widgets/site-setup-assistant-float.phtml", $widgetSrc);
    self::assertStringNotContainsString("templates/backend/widgets/site-setup-assistant-float.phtml", $hookSrc);
    $floatSrc = (string) file_get_contents($absolute);
    self::assertStringContainsString('collectGlobalOverview', $floatSrc);
    self::assertStringContainsString('ssa-float-capsules', $floatSrc);
    self::assertStringContainsString('全站', $floatSrc);
    self::assertStringNotContainsString('dashboard_website_id', $floatSrc);
    // 根节点不得默认 hidden：hook 未 bake 时 JS 不跑会永久不可见
    self::assertDoesNotMatchRegularExpression('/id="ssa-float-root"[^>]*\bhidden\b/', $floatSrc);
    self::assertStringContainsString('data-ssa-panel hidden', $floatSrc);
    // Hook 直出须显式挂 CSS/JS（对齐客服浮层），不能只 fetch 模板
    self::assertStringContainsString('fetchTagSource', $hookSrc);
    self::assertStringContainsString('widget-site-setup-assistant-float.css', $hookSrc);
    self::assertStringContainsString('widget-assets-runtime.js', $hookSrc);
    self::assertStringContainsString('widget-site-setup-assistant-float-0.js', $hookSrc);
    self::assertStringContainsString('data-weline-ssa-float', $hookSrc);
    $floatJs = (string) file_get_contents($moduleRoot . '/view/statics/js/widgets/widget-site-setup-assistant-float-0.js');
    self::assertStringContainsString('stored === null', $floatJs);
}
}
