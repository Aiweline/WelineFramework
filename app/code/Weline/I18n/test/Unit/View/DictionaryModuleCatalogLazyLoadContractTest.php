<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Dictionary module catalog must not SSR-scan all CSVs (nginx 502 / worker kill).
 * Shell list + batched describe/stats is the required loading path.
 */
final class DictionaryModuleCatalogLazyLoadContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testDictionaryPageNeverSsrsFullListModules(): void
    {
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/Dictionary.php');
        $service = $this->read('app/code/Weline/I18n/Service/DictionaryModuleCatalogService.php');
        $template = $this->read('app/code/Weline/I18n/view/templates/backend/Dictionary.phtml');
        $provider = $this->read('app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');

        self::assertStringContainsString("assign('module_catalog', [])", $controller);
        self::assertStringContainsString("assign('module_catalog_loaded', false)", $controller);
        self::assertStringContainsString('listModulesShell', $controller);
        self::assertStringContainsString('getModuleCatalogStats', $controller);
        self::assertStringContainsString('describeModule', $controller);

        if (\preg_match('/function get\(\)\s*\{[\s\S]*?\n    function /', $controller, $getMethod) === 1) {
            self::assertStringNotContainsString('listModules(', $getMethod[0]);
        } else {
            self::fail('Dictionary::get() method body not found');
        }

        if (\preg_match('/function getModuleCatalog\(\)\s*\{[\s\S]*?\n    (?:public |private |protected )?function /', $controller, $catalogMethod) === 1) {
            self::assertStringContainsString('listModulesShell', $catalogMethod[0]);
            self::assertStringNotContainsString('listModules(', $catalogMethod[0]);
        } else {
            self::fail('getModuleCatalog method body not found');
        }

        self::assertStringContainsString('function listModulesShell', $service);
        self::assertStringContainsString("'stats_ready' => false", $service);
        self::assertStringContainsString("'stats_ready' => true", $service);

        self::assertStringContainsString('dictionary-module-catalog-stats', $provider);
        self::assertStringContainsString("'dictionary-module-catalog-stats' => 'Weline_I18n::i18n_dictionaries'", $provider);
        self::assertStringContainsString('getModuleCatalogStats', $provider);

        self::assertStringContainsString('moduleCatalogStats', $template);
        self::assertStringContainsString('hydrateModuleCatalogStats', $template);
        self::assertStringContainsString('ensureModuleCatalogLoaded', $template);
        self::assertStringContainsString('stats_ready', $template);
    }

    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }
}
