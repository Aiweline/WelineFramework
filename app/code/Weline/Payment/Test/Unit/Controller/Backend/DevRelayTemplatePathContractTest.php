<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * DevRelay 后台页必须指向 view/templates 下真实模板，避免「模板文件不存在」。
 */
final class DevRelayTemplatePathContractTest extends TestCase
{
    public function testControllerFetchesExistingTemplatesPaths(): void
    {
        $controller = dirname(__DIR__, 4) . '/Controller/Backend/DevRelay.php';
        $index = dirname(__DIR__, 4) . '/view/templates/Backend/DevRelay/index.phtml';
        $console = dirname(__DIR__, 4) . '/view/templates/Backend/DevRelay/console.phtml';

        self::assertFileExists($controller);
        self::assertFileExists($index);
        self::assertFileExists($console);

        $src = file_get_contents($controller);
        self::assertIsString($src);

        self::assertStringContainsString(
            "Weline_Payment::templates/Backend/DevRelay/index.phtml",
            $src
        );
        self::assertStringContainsString(
            "Weline_Payment::templates/Backend/DevRelay/console.phtml",
            $src
        );
        self::assertStringNotContainsString(
            "Weline_Payment::Backend/DevRelay/index.phtml",
            $src
        );
        self::assertStringNotContainsString(
            "Weline_Payment::Backend/DevRelay/console.phtml",
            $src
        );

        $indexHtml = (string) file_get_contents($index);
        self::assertStringContainsString('payment-dev-relay', $indexHtml);
        self::assertStringContainsString('payment-dev-relay-management', $indexHtml);
        self::assertStringContainsString('payment-dev-relay-guide', $indexHtml);
        self::assertStringContainsString('何时开启', $indexHtml);
        self::assertStringContainsString('支付怎么用', $indexHtml);
        self::assertStringContainsString('万能货源怎么用', $indexHtml);
        self::assertStringContainsString('dev-relay-save-settings', $indexHtml);
        self::assertStringContainsString('class="w-card"', $indexHtml);
        self::assertStringContainsString('class="w-button"', $indexHtml);
        self::assertStringContainsString('class="w-switch"', $indexHtml);
        self::assertStringNotContainsString('class="card payment-dev-relay"', $indexHtml);
        self::assertStringNotContainsString('btn btn-success', $indexHtml);
        self::assertStringContainsString('dev-relay.js', $indexHtml);
        self::assertMatchesRegularExpression(
            '#/Weline/Payment/view/statics/js/backend/dev-relay\.js\?v=#',
            $indexHtml
        );
    }
}
