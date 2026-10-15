<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * 启用域名 → 仅 ensure contact@；Smtp 与邮局拆分，启域不再开默认 Smtp。
 */
final class SetDomainStatusEnsuresContactContractTest extends TestCase
{
    public function testEnableDomainCallsEnsureContactOnlyNotDefaultSmtp(): void
    {
        $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('function postSetDomainStatus', $src);
        self::assertStringContainsString("\$status === 'active'", $src);
        self::assertStringContainsString('MailAccountEnsureService', $src);
        self::assertStringContainsString('ensureContactAccount', $src);
        self::assertStringContainsString('smtp_password_once', $src);

        $activeBlock = $this->extractActiveBlock($src);
        self::assertStringContainsString('ensureContactAccount', $activeBlock);
        self::assertStringNotContainsString('ensureDefaultSmtpAccount', $activeBlock);
    }

    public function testEnterpriseUiHintsContactOnlyAndSmtpModuleSplit(): void
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

    private function extractActiveBlock(string $src): string
    {
        $start = \strpos($src, "if (\$status === 'active')");
        self::assertNotFalse($start);
        $end = \strpos($src, 'return $this->respondFormResult(200, $message);', $start);
        self::assertNotFalse($end);

        return \substr($src, $start, $end - $start);
    }
}
