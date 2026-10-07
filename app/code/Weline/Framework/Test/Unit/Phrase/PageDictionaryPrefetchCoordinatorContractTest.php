<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Phrase\PageDictionaryPrefetchCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StorefrontWidgetRuntimeSchedule;

final class PageDictionaryPrefetchCoordinatorContractTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('page-dict-test');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testCollectHookNamesFromCompiledPhp(): void
    {
        $php = <<<'PHP'
<?= $this->getHook('seo::body') ?>
<?= $template->getHook("Weline_Theme::frontend::layouts::base::body-end") ?>
PHP;
        $names = PageDictionaryPrefetchCoordinator::collectHookNamesFromPhp($php);
        self::assertContains('seo::body', $names);
        self::assertContains('Weline_Theme::frontend::layouts::base::body-end', $names);
    }

    public function testResolveStorefrontChromeModulesFailOpen(): void
    {
        $modules = PageDictionaryPrefetchCoordinator::resolveStorefrontChromeModules();
        self::assertNotSame([], $modules);
        foreach ($modules as $module) {
            self::assertIsString($module);
            self::assertNotSame('', $module);
        }
    }

    public function testParserExposesLocaleBatchPrefetchForColdLocalePrime(): void
    {
        $parserSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Phrase/Parser.php');
        self::assertStringContainsString('function prefetchGlobalDictionaryModulesForLocales', $parserSrc);
        self::assertStringContainsString('prefetchGlobalDictionaryModulesForLocales($modules, $locales)', $parserSrc);
    }

    public function testCoversModulesUsesRequestLatch(): void
    {
        self::assertFalse(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Seo']));
        RequestContext::set(PageDictionaryPrefetchCoordinator::PRIMED_KEY, true);
        RequestContext::set(PageDictionaryPrefetchCoordinator::MODULES_KEY, ['Weline_Seo', 'Weline_Visitor']);
        self::assertTrue(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Seo']));
        self::assertFalse(PageDictionaryPrefetchCoordinator::coversModules(['Weline_Missing']));
    }

    public function testScheduleDelegatesPageDictionaryAndHotCachePrefetch(): void
    {
        $scheduleSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/StorefrontWidgetRuntimeSchedule.php'
        );
        self::assertStringContainsString('delegatePageDictionaryPrefetch', $scheduleSrc);
        self::assertStringContainsString('PageDictionaryPrefetchCoordinator', $scheduleSrc);
        self::assertStringContainsString('delegateHotCachePagePrefetch', $scheduleSrc);
        self::assertStringContainsString('StorefrontHotCachePagePrefetch', $scheduleSrc);
        $hotPos = strpos($scheduleSrc, 'delegateHotCachePagePrefetch($template');
        $scanPos = strpos($scheduleSrc, 'scanTemplateRefs($template');
        self::assertNotFalse($hotPos);
        self::assertNotFalse($scanPos);
        self::assertLessThan($scanPos, $hotPos);
        self::assertNotSame(
            StorefrontWidgetRuntimeSchedule::LATCH_KEY,
            PageDictionaryPrefetchCoordinator::PRIMED_KEY
        );

        $templateSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/View/Template.php'
        );
        self::assertStringContainsString('PageDictionaryPrefetchCoordinator::coversModules', $templateSrc);
    }
}
