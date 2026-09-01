<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Search\Service\SearchProviderIndexService;

final class SearchProviderIndexServiceContractTest extends TestCase
{
    public function testServiceClassExistsAndExposesRebuildApi(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SearchProviderIndexService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('function rebuild(', $source);
        self::assertStringContainsString('function rebuildAll(', $source);
        self::assertTrue(class_exists(SearchProviderIndexService::class));
    }
}
