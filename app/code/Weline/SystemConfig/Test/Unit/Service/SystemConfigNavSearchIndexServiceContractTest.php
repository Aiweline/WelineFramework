<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SystemConfigNavSearchIndexServiceContractTest extends TestCase
{
    public function testServiceBuildsSearchableConfigFieldHitsWithDeeplinkShape(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SystemConfigNavSearchIndexService.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('class SystemConfigNavSearchIndexService', $src);
        self::assertStringContainsString('function getItems', $src);
        self::assertStringContainsString('guide_key', $src);
        self::assertStringContainsString('guide_locate', $src);
        self::assertStringContainsString('weline_systemconfig/backend/config', $src);
        self::assertStringContainsString('search_text', $src);
    }

    public function testBackendSearchProviderUsesIndexServiceAndBackendArea(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Search/Searcher/SystemConfigSearchProvider.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('class SystemConfigSearchProvider', $src);
        self::assertStringContainsString("return 'system_config'", $src);
        self::assertStringContainsString("return ['backend']", $src);
        self::assertStringContainsString('SystemConfigNavSearchIndexService', $src);
        self::assertStringContainsString('entityType: \'system_config_field\'', $src);
        self::assertStringContainsString("'breadcrumb'", $src);
        self::assertStringContainsString("'template_title'", $src);
        self::assertStringContainsString("'group'", $src);
    }
}
