<?php
declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * 启用域名 → 自动 ensure contact@（含一次性密码提示）。
 */
final class SetDomainStatusEnsuresContactContractTest extends TestCase
{
    public function testEnableDomainCallsEnsureContactAccount(): void
    {
        $path = BP . '/app/code/Weline/Mail/Controller/Backend/Index.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('function postSetDomainStatus', $src);
        self::assertStringContainsString("\$status === 'active'", $src);
        self::assertStringContainsString('MailAccountEnsureService', $src);
        self::assertStringContainsString('ensureContactAccount', $src);
        self::assertStringContainsString('smtp_password_once', $src);
        self::assertStringContainsString('仅显示一次', $src);
    }

    public function testEnterpriseUiHintsAutoContactOnEnable(): void
    {
        $path = BP . '/app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('mail-enterprise__domain-lifecycle', $src);
        self::assertStringContainsString('自动开通 contact@', $src);
        self::assertStringContainsString('set-domain-status', $src);
    }
}
