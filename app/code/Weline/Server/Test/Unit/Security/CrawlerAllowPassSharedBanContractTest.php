<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Contract: crawler-policy allow pass skips shared_ban / path_scan IP poison.
 */
final class CrawlerAllowPassSharedBanContractTest extends TestCase
{
    public function testKernelSkipsSharedBanWhenCrawlerAllowPass(): void
    {
        $path = \dirname(__DIR__, 3) . '/Security/WorkerPolicyKernel.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('isCrawlerAllowPass', $src);
        self::assertStringContainsString('CrawlerBlockCatalog::isAllowPass', $src);

        $banPos = \strpos($src, "return \$this->deny(\$parsed, \$clientIp, \$trustedProxy, 403, 'shared_ban')");
        self::assertNotFalse($banPos);
        $window = \substr($src, \max(0, $banPos - 500), 600);
        self::assertStringContainsString('isCrawlerAllowPass', $window);

        $pathScanPos = \strpos($src, "pathScan['enabled']");
        self::assertNotFalse($pathScanPos);
        $pathWindow = \substr($src, $pathScanPos, 700);
        self::assertStringContainsString('isCrawlerAllowPass', $pathWindow);
        self::assertStringContainsString('allowUniquePath', $pathWindow);

        // Attack deny may still happen; IP ban must gate on allow pass.
        self::assertStringContainsString('never poison IP ban list', $src);
    }

    public function testCatalogExposesIsAllowPass(): void
    {
        $path = \dirname(__DIR__, 3) . '/Security/CrawlerBlockCatalog.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('function isAllowPass', $src);
        self::assertStringContainsString('Hard floor', $src);
        self::assertStringContainsString('not an Origin-Token', $src);
    }
}
