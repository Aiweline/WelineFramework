<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * REQ-THEME-0014 greenfield contract for required-only auto install + decision dimensions.
 */
final class WidgetDefaultInjectionRequiredContractTest extends TestCase
{
    private function serviceSource(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
    }

    private function editorSource(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
    }

    public function testReconcileAndApplyRequiredApisExist(): void
    {
        $src = $this->serviceSource();
        self::assertStringContainsString('function reconcileRequiredDefaultsForIdentity(', $src);
        self::assertStringContainsString('function applyRequiredMissingForIdentity(', $src);
        self::assertStringContainsString('function applyInjectionByKeyResult(', $src);
        self::assertStringContainsString('function resolveInstallBlocker(', $src);
        self::assertStringContainsString("bool \$requiredOnly = true", $src);
    }

    public function testDecisionLookupIncludesSlotAndArea(): void
    {
        $src = $this->serviceSource();
        $pos = strpos($src, 'function findWidgetDecisionRow(');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 2800);
        self::assertStringContainsString('schema_fields_SLOT_ID', $snippet);
        self::assertStringContainsString('schema_fields_AREA', $snippet);
    }

    public function testRegistryCreateOrUpdateAutoPathRequiresRequiredFlag(): void
    {
        $src = $this->serviceSource();
        $pos = strpos($src, 'function applyInstalledWidgetsForAvailableThemes(');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 2200);
        self::assertStringContainsString('empty($item[\'required\'])', $snippet);
        self::assertStringContainsString('hasUserDeletedDecision', $snippet);
    }

    public function testInitSlotOnlyBackfillsRequired(): void
    {
        $src = $this->serviceSource();
        $pos = strpos($src, 'function initSlotDefaultInjections(');
        self::assertNotFalse($pos);
        $snippet = substr($src, $pos, 2500);
        self::assertStringContainsString('empty($item[\'required\'])', $snippet);
    }

    public function testApplicationTabUsesPreviewCanvasAndDefaultInstallLabel(): void
    {
        $js = $this->editorSource();
        self::assertStringContainsString('widget-preview-canvas', $js);
        self::assertStringContainsString("'默认安装'", $js);
        self::assertStringContainsString("'推荐'", $js);
        self::assertStringContainsString('一键安装默认项', $js);
        self::assertStringContainsString('reconcileRequiredDefaultsAfterDraftReady', $js);
        self::assertStringNotContainsString("'强烈推荐'", $js);
    }

    public function testFooterCustomerDeclarationsAreRequired(): void
    {
        $widgetPhp = dirname(__DIR__, 4) . '/Customer/extends/module/Weline_Widget/Weline_Customer/widget.php';
        self::assertFileExists($widgetPhp);
        $src = (string)file_get_contents($widgetPhp);
        self::assertStringContainsString("'required' => true", $src);
        self::assertStringContainsString('footer-my-account-link', $src);
    }
}
