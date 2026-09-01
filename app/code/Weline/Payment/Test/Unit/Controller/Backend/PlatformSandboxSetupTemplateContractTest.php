<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * 平台沙箱初始化页必须指向 view/templates 下真实模板，避免 500。
 */
final class PlatformSandboxSetupTemplateContractTest extends TestCase
{
    public function testControllerFetchesExistingTemplatesPath(): void
    {
        $controller = dirname(__DIR__, 4) . '/Controller/Backend/PlatformSandboxSetup.php';
        $template = dirname(__DIR__, 4) . '/view/templates/Backend/PayPal/platform-sandbox-setup.phtml';
        self::assertFileExists($controller);
        self::assertFileExists($template);

        $src = file_get_contents($controller);
        self::assertIsString($src);
        self::assertStringContainsString(
            "Weline_Payment::templates/Backend/PayPal/platform-sandbox-setup.phtml",
            $src
        );
        self::assertStringNotContainsString(
            "Weline_Payment::Backend/PayPal/platform-sandbox-setup.phtml",
            $src
        );
        self::assertStringContainsString('name="client_id"', (string) file_get_contents($template));
        self::assertStringContainsString('name="client_secret"', (string) file_get_contents($template));
        self::assertStringContainsString('w-card', (string) file_get_contents($template));
        self::assertStringContainsString('w-input', (string) file_get_contents($template));
        self::assertStringContainsString('w-button', (string) file_get_contents($template));
        self::assertStringContainsString("assign('title'", $src);
        self::assertStringNotContainsString('class="card"', (string) file_get_contents($template));
        self::assertStringNotContainsString('form-control', (string) file_get_contents($template));
        self::assertStringContainsString('credentials_ready', $src);
        self::assertStringContainsString('config_url_default_website', $src);
        self::assertStringContainsString('去默认 Website 一键授权', (string) file_get_contents($template));
        self::assertStringContainsString('去 Global 配置 / 授权', (string) file_get_contents($template));
        self::assertStringContainsString('Log in with PayPal', (string) file_get_contents($template));
        self::assertStringContainsString('Return URL', (string) file_get_contents($template));
        self::assertStringContainsString('Sandbox Webhooks', (string) file_get_contents($template));
        self::assertStringContainsString('只登记下面这一条', (string) file_get_contents($template));
        // 凭据已存在时不得再强制跳走，必须继续渲染可编辑表单。
        self::assertStringNotContainsString(
            "平台 PayPal 沙箱凭据已就绪，可直接使用「沙箱一键授权」",
            $src
        );

        $paypalController = dirname(__DIR__, 4) . '/Controller/Backend/PayPal.php';
        $paypalSrc = (string) file_get_contents($paypalController);
        self::assertStringNotContainsString('Global 层不支持 PayPal 一键授权', $paypalSrc);
    }
}
