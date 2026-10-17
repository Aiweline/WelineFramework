<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllPaymentBackendContractsPass().
 */
final class PaymentBackendContractsTest extends TestCase
{
    public function testAllPaymentBackendContractsPass(): void
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
            'DevRelayTemplatePath::testControllerFetchesExistingTemplatesPaths' => function () {
            $this->assert_DevRelayTemplatePath_testControllerFetchesExistingTemplatesPaths();
        },
            'PayPalAuthorizeRedirect::testConnectAuthorizeRethrowsTerminateAndUsesRedirectExceptionForOauth' => function () {
            $this->assert_PayPalAuthorizeRedirect_testConnectAuthorizeRethrowsTerminateAndUsesRedirectExceptionForOauth();
        },
            'PayPalAuthorizeRedirect::testLegacyPayPalAuthorizeIsThinAliasToConnect' => function () {
            $this->assert_PayPalAuthorizeRedirect_testLegacyPayPalAuthorizeIsThinAliasToConnect();
        },
            'PlatformSandboxSetupTemplate::testControllerFetchesExistingTemplatesPath' => function () {
            $this->assert_PlatformSandboxSetupTemplate_testControllerFetchesExistingTemplatesPath();
        },
        ];
    }

private function assert_DevRelayTemplatePath_testControllerFetchesExistingTemplatesPaths(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/DevRelay.php';
    $index = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/DevRelay/index.phtml';
    $console = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/DevRelay/console.phtml';
    self::assertFileExists($controller);
    self::assertFileExists($index);
    self::assertFileExists($console);
    $src = file_get_contents($controller);
    self::assertIsString($src);
    self::assertStringContainsString("Weline_Payment::templates/backend/DevRelay/index.phtml", $src);
    self::assertStringContainsString("Weline_Payment::templates/backend/DevRelay/console.phtml", $src);
    self::assertStringNotContainsString("Weline_Payment::backend/DevRelay/index.phtml", $src);
    self::assertStringNotContainsString("Weline_Payment::backend/DevRelay/console.phtml", $src);
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
    self::assertMatchesRegularExpression('#/Weline/Payment/view/statics/js/backend/dev-relay\.js\?v=#', $indexHtml);
}

private function assert_PayPalAuthorizeRedirect_testConnectAuthorizeRethrowsTerminateAndUsesRedirectExceptionForOauth(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/Connect.php';
    self::assertFileExists($controller);
    $src = (string) file_get_contents($controller);
    self::assertStringContainsString('use Weline\Framework\Http\RedirectException;', $src);
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertStringContainsString('throw new RedirectException((string) $started[\'authorization_url\'], 302);', $src);
    self::assertStringContainsString('catch (ResponseTerminateException $exception)', $src);
    self::assertStringContainsString('throw $exception;', $src);
    self::assertStringContainsString('catch (\Throwable $exception)', $src);
    self::assertStringContainsString('method_code', $src);
    self::assertStringContainsString('SystemConfigTargetScopeService', $src);
    self::assertStringContainsString('storage_scope', $src);
    self::assertStringContainsString('substr_count($scope, \'.\') === 2', $src);
    $terminatePos = strpos($src, 'catch (ResponseTerminateException $exception)');
    $throwablePos = strpos($src, 'catch (\Throwable $exception)');
    self::assertNotFalse($terminatePos);
    self::assertNotFalse($throwablePos);
    self::assertLessThan($throwablePos, $terminatePos);
}

private function assert_PayPalAuthorizeRedirect_testLegacyPayPalAuthorizeIsThinAliasToConnect(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/PayPal.php';
    $src = (string) file_get_contents($controller);
    self::assertStringContainsString('payment/backend/connect/', $src);
    self::assertStringContainsString("'method_code' => 'paypal'", $src);
    self::assertStringNotContainsString('PayPalOAuthService', $src);
    self::assertStringNotContainsString('throw new RedirectException', $src);
}

private function assert_PlatformSandboxSetupTemplate_testControllerFetchesExistingTemplatesPath(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/PlatformSandboxSetup.php';
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/view/templates/backend/PayPal/platform-sandbox-setup.phtml';
    self::assertFileExists($controller);
    self::assertFileExists($template);
    $src = file_get_contents($controller);
    self::assertIsString($src);
    self::assertStringContainsString("Weline_Payment::templates/backend/PayPal/platform-sandbox-setup.phtml", $src);
    self::assertStringNotContainsString("Weline_Payment::backend/PayPal/platform-sandbox-setup.phtml", $src);
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
    self::assertStringNotContainsString("平台 PayPal 沙箱凭据已就绪，可直接使用「沙箱一键授权」", $src);
    $paypalController = '/Users/weline/Project/Official/框架/app/code/Weline/Payment' . '/Controller/Backend/PayPal.php';
    $paypalSrc = (string) file_get_contents($paypalController);
    self::assertStringNotContainsString('Global 层不支持 PayPal 一键授权', $paypalSrc);
}
}
