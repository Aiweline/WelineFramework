<?php
declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SmtpServiceCatalogContractTest extends TestCase
{
    public function testSmtpServiceModelHasSelfAndExternalModes(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Model/SmtpService.php';
        self::assertFileExists($path);
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('weline_smtp_service', $src);
        self::assertStringContainsString("MODE_SELF = 'self'", $src);
        self::assertStringContainsString("MODE_EXTERNAL = 'external'", $src);
    }

    public function testCatalogServiceCreatesBothModes(): void
    {
        $src = (string) \file_get_contents(
            BP . '/app/code/Weline/Smtp/Service/SmtpServiceCatalogService.php'
        );
        self::assertStringContainsString('function createSelfService', $src);
        self::assertStringContainsString('function createExternalService', $src);
        self::assertStringContainsString('ensurePublicDnsHostnamesInPool', $src);
        self::assertStringContainsString('MODE_EXTERNAL', $src);
    }

    public function testSmtpAccountBelongsToService(): void
    {
        $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Model/SmtpAccount.php');
        self::assertStringContainsString('smtp_service_id', $src);
    }
}
