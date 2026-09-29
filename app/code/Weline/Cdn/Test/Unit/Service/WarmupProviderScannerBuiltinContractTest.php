<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;

final class WarmupProviderScannerBuiltinContractTest extends TestCase
{
    public function testScanProvidersIncludesBuiltinFpcProvider(): void
    {
        $scanner = \Weline\Framework\Manager\ObjectManager::getInstance(WarmupProviderScanner::class);
        $providers = $scanner->scanProviders(true);
        $this->assertContains(FpcExtraDeclaredUrls::class, $providers);
    }
}
