<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AttributeMetadataProcessCacheContractTest extends TestCase
{
    public function testCatalogOwnsProcessBag(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AttributeMetadataCatalog.php');
        self::assertStringContainsString('$processBag', $src);
        self::assertStringContainsString('function clearProcessCache', $src);
    }

    public function testProcessCacheResetterIsRegistered(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Eav\Api\Runtime\ProcessCacheResetter::class,
            $module['provides']['process_cache_resetter.Weline_Eav'] ?? null,
        );
    }
}
