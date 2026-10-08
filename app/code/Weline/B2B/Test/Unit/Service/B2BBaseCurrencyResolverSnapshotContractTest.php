<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class B2BBaseCurrencyResolverSnapshotContractTest extends TestCase
{
    public function testReadWebsiteDefaultUsesWebsiteDataSnapshot(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/B2BBaseCurrencyResolver.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src);
        self::assertStringNotContainsString('Website::schema_fields_ID', $src);
        self::assertStringNotContainsString('->load(Website::', $src);
    }
}
