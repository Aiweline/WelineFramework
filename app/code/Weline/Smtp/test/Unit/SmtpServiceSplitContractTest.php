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
    }

    public function testControllerHasEnableActions(): void
    {
        $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Controller/Backend/Config.php');
        self::assertStringContainsString('function postEnableService', $src);
        self::assertStringContainsString('function postCreateSmtpAccount', $src);
        self::assertStringContainsString('SmtpServiceEnableService', $src);
    }
}
