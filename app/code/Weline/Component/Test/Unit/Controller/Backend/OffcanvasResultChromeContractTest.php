<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class OffcanvasResultChromeContractTest extends TestCase
{
    public function testOffcanvasResultPagesSuppressBlankLayoutPageHeader(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Backend/Offcanvas.php',
        );

        self::assertStringContainsString('suppressPageChromeForResult', $source);
        self::assertStringContainsString("assign('layoutShowPageHeader', false)", $source);
        self::assertStringContainsString("assign('layoutShowMessages', false)", $source);
        self::assertStringContainsString('$this->suppressPageChromeForResult()', $source);
        self::assertStringContainsString('applyOffcanvasLayout', $source);
    }

    public function testOffcanvasResultTemplateDoesNotRenderBackendPageHeading(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Offcanvas/result.phtml',
        );

        self::assertStringContainsString('data-w-offcanvas-result', $source);
        self::assertStringContainsString('操作成功', $source);
        self::assertStringNotContainsString('w-backend-page__heading', $source);
    }
}
