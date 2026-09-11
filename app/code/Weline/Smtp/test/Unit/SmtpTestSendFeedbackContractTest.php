<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 测试发送须对 XHR 返回 JSON；前端须展示真实反馈。
 */
final class SmtpTestSendFeedbackContractTest extends TestCase
{
    public function testPostTestReturnsJsonForAjaxAndFrontShowsFeedback(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $controller = (string)file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
        $template = (string)file_get_contents($moduleRoot . '/view/Backend/Config.phtml');

        self::assertStringContainsString('wantsJsonResponse', $controller);
        self::assertStringContainsString('jsonSuccess', $controller);
        self::assertStringContainsString('jsonError', $controller);
        self::assertStringContainsString('markSetupConfirmed', $controller);

        self::assertStringContainsString('application/json', $template);
        self::assertStringContainsString('smtpTestFeedback', $template);
        self::assertStringContainsString('btnDoTest', $template);
        self::assertStringContainsString('dialog.close', $template);
        self::assertStringContainsString('testSuccess', $template);
        self::assertStringNotContainsString("setTimeout(function() { window.location.reload(); }, 500);", $template);
        self::assertStringContainsString('<w:icon name="close"', $template);
    }
}
