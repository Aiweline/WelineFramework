<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMailControllerContractsPass().
 */
final class MailControllerContractsTest extends TestCase
{
    public function testAllMailControllerContractsPass(): void
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
            'ConfigureCloudflareDnsRedirectTerminate::testPostConfigureCloudflareDnsRethrowsResponseTerminateException' => function () {
            $this->assert_ConfigureCloudflareDnsRedirectTerminate_testPostConfigureCloudflareDnsRethrowsResponseTerminateException();
        },
            'SetDomainStatusEnsuresContact::testEnableDomainCallsEnsureContactOnlyNotDefaultSmtp' => function () {
            $this->assert_SetDomainStatusEnsuresContact_testEnableDomainCallsEnsureContactOnlyNotDefaultSmtp();
        },
            'SetDomainStatusEnsuresContact::testEnterpriseUiHintsContactOnlyAndSmtpModuleSplit' => function () {
            $this->assert_SetDomainStatusEnsuresContact_testEnterpriseUiHintsContactOnlyAndSmtpModuleSplit();
        },
        ];
    }

private function assert_ConfigureCloudflareDnsRedirectTerminate_testPostConfigureCloudflareDnsRethrowsResponseTerminateException(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
    self::assertStringContainsString('use Weline\Framework\Http\ResponseTerminateException;', $src);
    self::assertStringContainsString('catch (ResponseTerminateException $terminate)', $src);
    self::assertStringContainsString('throw $terminate;', $src);
    self::assertLessThan((int) strpos($src, 'Cloudflare DNS 配置失败：%{1}'), (int) strpos($src, 'catch (ResponseTerminateException $terminate)'));
}

private function assert_SetDomainStatusEnsuresContact_testEnableDomainCallsEnsureContactOnlyNotDefaultSmtp(): void
{
    $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('function postSetDomainStatus', $src);
    self::assertStringContainsString("\$status === 'active'", $src);
    self::assertStringContainsString('MailAccountEnsureService', $src);
    self::assertStringContainsString('ensureContactAccount', $src);
    self::assertStringContainsString('smtp_password_once', $src);
    $activeBlock = $this->extractActiveBlock_SetDomainStatusEnsuresContact($src);
    self::assertStringContainsString('ensureContactAccount', $activeBlock);
    self::assertStringNotContainsString('ensureDefaultSmtpAccount', $activeBlock);
}

private function assert_SetDomainStatusEnsuresContact_testEnterpriseUiHintsContactOnlyAndSmtpModuleSplit(): void
{
    $path = BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('mail-enterprise__domain-lifecycle', $src);
    self::assertStringContainsString('自动开通 contact@', $src);
    self::assertStringContainsString('Smtp 配置', $src);
    self::assertStringContainsString('set-domain-status', $src);
    self::assertStringNotContainsString('set-default-smtp-account', $src);
    self::assertStringNotContainsString('保存默认中继', $src);
    self::assertStringContainsString('mail-enterprise-acme-tls', $src);
    self::assertStringContainsString('smtp_cname', $src);
    self::assertStringContainsString('默认必需', $src);
}

private function extractActiveBlock_SetDomainStatusEnsuresContact(string $src): string
{
    $start = \strpos($src, "if (\$status === 'active')");
    self::assertNotFalse($start);
    $end = \strpos($src, 'return $this->respondFormResult(200, $message);', $start);
    self::assertNotFalse($end);
    return \substr($src, $start, $end - $start);
}
}
