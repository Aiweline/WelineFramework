<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WidgetAdminIndexContractTest extends TestCase
{
    public function testAdminIndexShowsTypeCodeNameAndGroupedList(): void
    {
        $path = BP . '/app/code/Weline/Widget/view/templates/backend/Widget/index.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-testid="widget-admin-list"', $source);
        self::assertStringContainsString('data-testid="widget-admin-row"', $source);
        self::assertStringContainsString('widget-admin-group', $source);
        self::assertStringContainsString('widget-admin-code__label', $source);
        self::assertStringContainsString("__('Code')", $source);
        self::assertStringContainsString("__('类型')", $source);
        self::assertStringContainsString('data-widget-code=', $source);
    }

    public function testAdminIndexWiresVisualPreviewStageAndSafePreviewGate(): void
    {
        $path = BP . '/app/code/Weline/Widget/view/templates/backend/Widget/index.phtml';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("@backend-url{'widget/backend/preview/widget'}", $source);
        self::assertStringContainsString('data-testid="widget-admin-preview"', $source);
        self::assertStringContainsString('widgetAdminPreviewStage', $source);
        self::assertStringContainsString('preview_safe === true', $source);
        self::assertStringContainsString('renderPreviewFrame', $source);
        self::assertStringContainsString("__('预览')", $source);
        self::assertStringContainsString("__('视觉预览')", $source);
        self::assertStringContainsString("__('加载预览…')", $source);
        // Weline.UI.dialog.open requires w-dialog + data-w-component=dialog (not Bootstrap modal).
        self::assertStringContainsString("modalEl.className = 'w-dialog'", $source);
        self::assertStringContainsString("data-w-component', 'dialog'", $source);
        self::assertStringNotContainsString("modalEl.className = 'modal fade'", $source);
        // Controller JSON must use fetch — adminRequest('widget') is BinQuery and denied.
        self::assertStringContainsString('function backendJsonGet', $source);
        self::assertStringContainsString('backendJsonGet(url)', $source);
        self::assertStringNotContainsString("Weline.adminRequest('widget'", $source);
    }

    public function testPreviewControllerUsesWidgetPreviewServiceRender(): void
    {
        $path = BP . '/app/code/Weline/Widget/Controller/Backend/Preview.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('previewService->render(', $source);
        self::assertStringNotContainsString('ReflectionClass', $source);
        self::assertStringNotContainsString('renderWidget', $source);
    }

    public function testDetailOmitsTemplateContentFromAdminPayload(): void
    {
        $path = BP . '/app/code/Weline/Widget/Controller/Backend/Widget.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("unset(\$payload['template_content'])", $source);
    }
}
