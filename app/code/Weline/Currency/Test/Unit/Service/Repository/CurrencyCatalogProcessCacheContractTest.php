<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service\Repository;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\Repository\CurrencyCatalog;

final class CurrencyCatalogProcessCacheContractTest extends TestCase
{
    public function testClearProcessCacheIsPublicAndResetsActiveBucket(): void
    {
        $property = new \ReflectionProperty(CurrencyCatalog::class, 'activeCache');
        $property->setValue(null, []);
        self::assertIsArray($property->getValue(null));

        CurrencyCatalog::clearProcessCache();
        self::assertNull($property->getValue(null));
    }
}
