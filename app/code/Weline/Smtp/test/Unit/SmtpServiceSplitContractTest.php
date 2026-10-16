<?php
declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SmtpServiceSplitContractTest extends TestCase
{
    public function testSmtpAccountModelExists(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Model/SmtpAccount.php';
        self::assertFileExists($path);
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('weline_smtp_account', $src);
        self::assertStringContainsString('ROLE_RELAY', $src);
    }

    public function testConfigUiHasEnableCard(): void
    {
        $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/view/backend/Config.phtml');
        self::assertStringContainsString('smtp-service-enable-card', $src);
        self::assertStringContainsString('启用并入池出证', $src);
        self::assertStringContainsString('domain_mode', $src);
        self::assertStringContainsString('与企业邮局是否启域无关', $src);
        self::assertStringNotContainsString('smtp-ensure-mail-btn', $src);
        self::assertStringNotContainsString('smtp-route-mailbox-card', $src);
        self::assertStringNotContainsString('用自建邮局一键配置', $src);
    }

    public function testControllerHasEnableActions(): void
    {
        $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Controller/Backend/Config.php');
        self::assertStringContainsString('function postEnableService', $src);
        self::assertStringContainsString('function postCreateSmtpAccount', $src);
        self::assertStringContainsString('SmtpServiceEnableService', $src);
        self::assertStringContainsString("assign('mail_bootstrap_ready', false)", $src);
    }

    public function testBootstrapNeverWaitsOnMailDomain(): void
    {
        $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Service/MailSmtpBootstrapService.php');
        $start = strpos($src, 'function canEnsure');
        self::assertNotFalse($start);
        $end = strpos($src, 'function ', $start + 10);
        $body = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
        self::assertStringContainsString('return false', $body);
        self::assertStringNotContainsString('resolveActiveMailDomain', $body);
    }
}
