<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesApiContractsPass().
 */
final class WebsitesApiContractsTest extends TestCase
{
    public function testAllWebsitesApiContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'RemoteTranslationCatalog::testThinShellQueriesWebsitesProvider' => function () {
            $this->assert_RemoteTranslationCatalog_testThinShellQueriesWebsitesProvider();
        },
        ];
    }

private function assert_RemoteTranslationCatalog_testThinShellQueriesWebsitesProvider(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Api/Rest/V1/RemoteTranslationCatalog.php');
    self::assertStringContainsString("w_query('websites'", $src);
    self::assertStringContainsString('getWebsiteList', $src);
    self::assertStringContainsString('getWebsiteLanguageCodes', $src);
    self::assertStringContainsString('Weline_Websites::rest_v1_remote_translation_catalog_websites', $src);
    self::assertStringContainsString('Weline_Websites::rest_v1_remote_translation_catalog_languages', $src);
    self::assertStringContainsString("'status' => 1", $src);
    self::assertStringNotContainsString('LocaleDictionary', $src);
}
}
