<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeIndexPreviewInteractionTest extends TestCase
{
    private string $template;
    private string $activeScript;

    protected function setUp(): void
    {
        $templatePath = dirname(__DIR__, 6) . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
        $this->template = (string)file_get_contents($templatePath);
        $scriptOffset = strpos($this->template, "const BATCH_GENERATE_URL =");
        self::assertIsInt($scriptOffset);
        $this->activeScript = substr($this->template, $scriptOffset);
    }

    public function testSinglePreviewAcceptsFrameworkSuccessPayload(): void
    {
        self::assertStringContainsString('function isSuccessResponse(result)', $this->activeScript);
        self::assertStringContainsString('if (isSuccessResponse(result)) {', $this->activeScript);
        self::assertStringContainsString("result?.data?.image_url || result?.image_url || ''", $this->activeScript);
    }

    public function testModalDismissControlsWorkWithFallbackAdapter(): void
    {
        self::assertStringContainsString('[data-bs-dismiss="modal"], [data-dismiss="modal"]', $this->activeScript);
        self::assertStringContainsString('adapter.hide();', $this->activeScript);
    }
}
