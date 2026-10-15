<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * 启用域名 → 自动 ensure contact@ + 默认 Smtp 提交账号；后台可改绑。
 */
final class SetDomainStatusEnsuresContactContractTest extends TestCase
{
    public function testEnableDomainCallsEnsureContactAndDefaultSmtp(): void
    {
        $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('function postSetDomainStatus', $src);
        self::assertStringContainsString("\$status === 'active'", $src);
        self::assertStringContainsString('MailAccountEnsureService', $src);
        self::assertStringContainsString('ensureContactAccount', $src);
        self::assertStringContainsString('ensureDefaultSmtpAccount', $src);
        self::assertStringContainsString('smtp_password_once', $src);
        self::assertStringContainsString('默认 Smtp 提交账号', $src);
        self::assertStringContainsString('function postSetDefaultSmtpAccount', $src);
    }

    public function testEnterpriseUiHintsAutoContactAndDefaultSmtpOnEnable(): void
    {
        $path = BP . '/app/code/Weline/Mail/view/templates/backend/Index/enterprise.phtml';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('mail-enterprise__domain-lifecycle', $src);
        self::assertStringContainsString('自动开通 contact@', $src);
        self::assertStringContainsString('默认 Smtp 提交账号', $src);
        self::assertStringContainsString('set-domain-status', $src);
        self::assertStringContainsString('set-default-smtp-account', $src);
        self::assertStringContainsString('mail-enterprise-acme-tls', $src);
        self::assertStringContainsString('smtp_cname', $src);
        self::assertStringContainsString('默认必需', $src);
    }
}
