<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App;
use Weline\Framework\Runtime\RequestPipeline;
use Weline\Framework\Runtime\WlsRuntime;

final class RequestPipelineDispatchOnceTest extends TestCase
{
    public function testFpmApplicationPipelineDispatchesRouterExactlyOnce(): void
    {
        $pipeline = $this->methodSource(RequestPipeline::class, 'execute');
        $app = $this->methodSource(App::class, 'runPipeline');

        self::assertSame(1, substr_count($pipeline, '$app->runRouter('));
        self::assertSame(1, substr_count($app, 'new RequestPipeline())->execute('));
        self::assertStringNotContainsString('runRouter(', $app);
        self::assertLessThan(
            strpos($pipeline, '$app->runRouter('),
            strpos($pipeline, '$app->startSessionIfNeeded('),
            'Session initialization must occur before the single router dispatch.',
        );
    }

    public function testWlsPipelineDispatchesRouterExactlyOnce(): void
    {
        $source = $this->methodSource(WlsRuntime::class, 'handle');

        self::assertSame(1, substr_count($source, '$this->requestPipeline()->execute('));
        self::assertStringNotContainsString('$app->runRouter(', $source);
        self::assertStringNotContainsString('account.index.timing', $source);
        self::assertStringNotContainsString('category.view.profile', $source);
        self::assertStringNotContainsString('product.view.profile', $source);
    }

    public function testStorefrontScopeGateRunsBeforeAnyFpcLookup(): void
    {
        $source = $this->methodSource(App::class, 'applyParsedUrl');
        $scopeInstall = strpos($source, '$this->installStorefrontNavigationScope(');
        $scopeGate = strpos($source, 'Weline_Framework::App::storefront_scope_ready_gate');
        $cacheContext = strpos($source, 'StorefrontCacheKeyContextResolver::class');
        $fpcFastPath = strpos($source, '$this->tryPersistentFpcFastPath()');

        self::assertIsInt($scopeInstall);
        self::assertIsInt($scopeGate);
        self::assertIsInt($cacheContext);
        self::assertIsInt($fpcFastPath);
        self::assertGreaterThan($scopeInstall, $scopeGate);
        self::assertLessThan($cacheContext, $scopeGate);
        self::assertLessThan($fpcFastPath, $scopeGate);
    }

    public function testParsedLocalizationIsSynchronizedBeforeStorefrontCacheContext(): void
    {
        $source = $this->methodSource(App::class, 'applyParsedUrl');
        $websiteContext = strpos($source, "WelineEnv::set('website.currency'");
        $provisionalLocalization = strpos(
            $source,
            '$this->synchronizeParsedLocalization($parse, $rawRequestUri);',
        );
        $authoritativeLocalization = strrpos(
            $source,
            '$this->synchronizeParsedLocalization($parse, $rawRequestUri);',
        );
        $scopeInstall = strpos($source, '$this->installStorefrontNavigationScope(');
        $cacheContext = strpos($source, 'StorefrontCacheKeyContextResolver::class');
        $contextRebuild = strpos($source, 'self::syncCurrentContextFromGlobals();');

        self::assertIsInt($websiteContext);
        self::assertIsInt($provisionalLocalization);
        self::assertIsInt($authoritativeLocalization);
        self::assertNotSame($provisionalLocalization, $authoritativeLocalization);
        self::assertIsInt($scopeInstall);
        self::assertIsInt($cacheContext);
        self::assertIsInt($contextRebuild);
        self::assertGreaterThan($websiteContext, $provisionalLocalization);
        self::assertGreaterThan($provisionalLocalization, $scopeInstall);
        self::assertGreaterThan($scopeInstall, $authoritativeLocalization);
        self::assertGreaterThan($authoritativeLocalization, $cacheContext);
        self::assertGreaterThan($cacheContext, $contextRebuild);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
