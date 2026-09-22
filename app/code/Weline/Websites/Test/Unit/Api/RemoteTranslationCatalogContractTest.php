<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api;

use PHPUnit\Framework\TestCase;

final class RemoteTranslationCatalogContractTest extends TestCase
{
    public function testThinShellQueriesWebsitesProvider(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Rest/V1/RemoteTranslationCatalog.php');
        self::assertStringContainsString("w_query('websites'", $src);
        self::assertStringContainsString('getWebsiteList', $src);
        self::assertStringContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringContainsString('Weline_Websites::rest_v1_remote_translation_catalog_websites', $src);
        self::assertStringContainsString('Weline_Websites::rest_v1_remote_translation_catalog_languages', $src);
        self::assertStringContainsString("'status' => 1", $src);
        self::assertStringNotContainsString('LocaleDictionary', $src);
    }
}
