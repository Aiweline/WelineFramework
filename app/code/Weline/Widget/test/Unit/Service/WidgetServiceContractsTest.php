<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Widget\Cache\WidgetOutputCache;
use Weline\Widget\Service\DefaultInjectionPlanRepository;
use Weline\Widget\Service\WidgetTemplateParser;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetServiceContractsPass().
 */
final class WidgetServiceContractsTest extends TestCase
{
    public function testAllWidgetServiceContractsPass(): void
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
            'DefaultInjectionPlanRepository::testRepositoryReadsLedgerNotScanner' => function () {
            $this->assert_DefaultInjectionPlanRepository_testRepositoryReadsLedgerNotScanner();
        },
            'SlimWidgetPhpPathOnly::testAllModuleWidgetPhpListingsArePathOnly' => function () {
            $this->assert_SlimWidgetPhpPathOnly_testAllModuleWidgetPhpListingsArePathOnly();
        },
            'WidgetCacheMetaParse::testParserTreatsCacheValueAsTtlSecondsAndRejectsLegacy' => function () {
            $this->assert_WidgetCacheMetaParse_testParserTreatsCacheValueAsTtlSecondsAndRejectsLegacy();
        },
            'WidgetCacheMetaParse::testTaglibSpecCarriesCacheAndUsesSharedOutputCache' => function () {
            $this->assert_WidgetCacheMetaParse_testTaglibSpecCarriesCacheAndUsesSharedOutputCache();
        },
        ];
    }

private function assert_DefaultInjectionPlanRepository_testRepositoryReadsLedgerNotScanner(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Service/DefaultInjectionPlanRepository.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('final class DefaultInjectionPlanRepository', $src);
    self::assertStringContainsString('function listDeclarations', $src);
    self::assertStringContainsString('function clearMemo', $src);
    self::assertStringContainsString('WidgetRegistryEntry', $src);
    self::assertStringContainsString('HAS_DEFAULT_INJECTIONS', $src);
    self::assertStringContainsString('IS_ACTIVE', $src);
    self::assertStringContainsString('DEFAULT_INJECTIONS_JSON', $src);
    self::assertStringNotContainsString('WidgetScanner', $src);
    self::assertStringNotContainsString('getRegistry()', $src);
    self::assertStringNotContainsString('ThemeComponentCatalog', $src);
    self::assertTrue(class_exists(DefaultInjectionPlanRepository::class));
}

private function assert_SlimWidgetPhpPathOnly_testAllModuleWidgetPhpListingsArePathOnly(): void
{
    $root = \defined('BP') ? \rtrim((string) BP, '/\\') : '/Users/weline/Project/Official/框架';
    $pattern = $root . '/app/code/*/*/extends/module/Weline_Widget/*/widget.php';
    $files = glob($pattern) ?: [];
    self::assertNotEmpty($files, 'expected slim widget.php listings under app/code');
    foreach ($files as $file) {
        /** @var mixed $entries */
        $entries = require $file;
        self::assertIsArray($entries, $file);
        self::assertNotEmpty($entries, $file);
        foreach ($entries as $index => $entry) {
            self::assertIsString($entry, $file . ' index ' . $index . ' must be a template path string (no array overrides)');
            self::assertStringContainsString('::', $entry, $file);
            self::assertStringEndsWith('.phtml', $entry, $file);
        }
        $src = (string) file_get_contents($file);
        self::assertStringContainsString('只登记模板路径', $src, $file);
        self::assertStringNotContainsString('必要简化覆盖', $src, $file);
    }
}

private function assert_WidgetCacheMetaParse_testParserTreatsCacheValueAsTtlSecondsAndRejectsLegacy(): void
{
    $parser = new WidgetTemplateParser((new \ReflectionClass(Template::class))->newInstanceWithoutConstructor());
    $method = new \ReflectionMethod(WidgetTemplateParser::class, 'normalizeCacheMeta');
    $method->setAccessible(true);
    $on = $method->invoke($parser, ['cache' => '300']);
    self::assertSame(300, $on['cache']);
    self::assertArrayNotHasKey('cache_ttl', $on);
    self::assertArrayNotHasKey('share', $on);
    $shared = $method->invoke($parser, ['cache' => '3600', 'share' => 'true']);
    self::assertSame(3600, $shared['cache']);
    self::assertTrue($shared['share']);
    $shareOnly = $method->invoke($parser, ['share' => true]);
    self::assertTrue($shareOnly['share']);
    self::assertArrayNotHasKey('cache', $shareOnly);
    $legacy = $method->invoke($parser, ['cache' => 'static']);
    self::assertArrayNotHasKey('cache', $legacy);
    $onWord = $method->invoke($parser, ['cache' => 'on']);
    self::assertArrayNotHasKey('cache', $onWord);
    $off = $method->invoke($parser, ['cache' => 'off']);
    self::assertArrayNotHasKey('cache', $off);
    $zero = $method->invoke($parser, ['cache' => 0]);
    self::assertArrayNotHasKey('cache', $zero);
    $poisoned = $method->invoke($parser, ['cache' => 60, 'cache_ttl' => 999]);
    self::assertSame(60, $poisoned['cache']);
    self::assertArrayNotHasKey('cache_ttl', $poisoned);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Service/WidgetTemplateParser.php');
    self::assertStringContainsString('@widget.cache {300}', $src);
    self::assertStringContainsString('@widget.share', $src);
    self::assertStringNotContainsString('@widget.cache_ttl', $src);
    self::assertStringNotContainsString("token === 'static'", $src);
    self::assertStringContainsString('WidgetOutputCache::normalizeTtl', $src);
    self::assertSame(0, WidgetOutputCache::normalizeTtl('static'));
    self::assertSame(300, WidgetOutputCache::normalizeTtl(300));
}

private function assert_WidgetCacheMetaParse_testTaglibSpecCarriesCacheAndUsesSharedOutputCache(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Widget' . '/Taglib/Widget.php');
    self::assertStringContainsString("'cache' => \\Weline\\Widget\\Cache\\WidgetOutputCache::normalizeTtl", $src);
    self::assertStringContainsString('normalizeShare', $src);
    self::assertStringContainsString("'share'", $src);
    self::assertStringContainsString('WidgetOutputCache::remember', $src);
    self::assertStringNotContainsString('isCacheableWidgetHtml', $src);
    self::assertStringNotContainsString('function renderCache', $src);
    self::assertStringNotContainsString('function cacheRender', $src);
}
}
