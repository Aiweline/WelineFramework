<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Capability\FpcCapability;
use Weline\Framework\Http\Fpc\FpcBypassEvaluator;
use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Http\Fpc\FpcStoreAdapterRegistry;
use Weline\Theme\Extends\Module\Weline_Framework\Fpc\Bypass\ThemeEditorFpcBypassProvider;
use Weline\Server\Extends\Module\Weline_Framework\Fpc\Bypass\WlsTransportFpcBypassProvider;

final class FpcBypassAndStoreContractTest extends TestCase
{
    protected function tearDown(): void
    {
        FpcBypassEvaluator::clearCache();
        parent::tearDown();
    }

    public function testEvaluatorBypassesEditorQueryAndLivePreviewPath(): void
    {
        FpcBypassEvaluator::clearCache();
        $rules = FpcBypassEvaluator::builtinFallbackRules();

        self::assertTrue(FpcBypassEvaluator::shouldBypass([
            'query' => ['editor_mode' => '1'],
        ], $rules));
        self::assertTrue(FpcBypassEvaluator::shouldBypass([
            'query' => ['nocache' => '1'],
        ], $rules));
        // Legacy preview cookie alone must NOT bypass — identity is /~preview/{token}/ or query/header.
        self::assertFalse(FpcBypassEvaluator::shouldBypass([
            'cookie_header' => 'a=1; weline_preview_token_w0=abc; b=2',
        ], $rules));
        self::assertTrue(FpcBypassEvaluator::shouldBypass([
            'headers' => ['x-wls-fpc-bypass' => '1'],
        ], $rules));
        self::assertTrue(FpcBypassEvaluator::shouldBypass([
            'request_path' => '/~preview/pv_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG/~site/grocery/',
        ], $rules));
        self::assertFalse(FpcBypassEvaluator::shouldBypass([
            'query' => ['q' => 'hanfu'],
            'headers' => ['cache-control' => 'no-cache'],
        ], $rules));
    }

    public function testThemeAndWlsProvidersShareBuiltinFallbackIds(): void
    {
        $themeIds = \array_column((new ThemeEditorFpcBypassProvider())->rules(), 'id');
        $wlsIds = \array_column((new WlsTransportFpcBypassProvider())->rules(), 'id');
        $builtinIds = \array_column(FpcBypassEvaluator::builtinFallbackRules(), 'id');

        foreach ($themeIds as $id) {
            self::assertContains($id, $builtinIds);
        }
        foreach ($wlsIds as $id) {
            self::assertContains($id, $builtinIds);
        }
        self::assertContains('theme.live_preview_path_prefix', $themeIds);
    }

    public function testCollectorNormalizesUriPathPrefixes(): void
    {
        $collector = new \ReflectionClass(\Weline\Framework\Http\Fpc\FpcBypassCollector::class);
        $method = $collector->getMethod('normalizeRule');
        $method->setAccessible(true);
        $instance = $collector->newInstanceWithoutConstructor();
        $normalized = $method->invoke($instance, [
            'id' => 'theme.live_preview_path_prefix',
            'match' => ['uri_path_prefixes' => ['~preview/', '/~preview/']],
            'effect' => 'bypass_serve_and_publish',
        ]);
        self::assertIsArray($normalized);
        self::assertSame(['/~preview/'], $normalized['match']['uri_path_prefixes']);
    }

    public function testFpcCapabilitySourceDoesNotTouchCacheManagerPools(): void
    {
        $path = \dirname(__DIR__, 4) . \DIRECTORY_SEPARATOR . 'Extends' . \DIRECTORY_SEPARATOR
            . 'module' . \DIRECTORY_SEPARATOR . 'Weline_Framework' . \DIRECTORY_SEPARATOR
            . 'Changed' . \DIRECTORY_SEPARATOR . 'Capability' . \DIRECTORY_SEPARATOR . 'FpcCapability.php';
        self::assertFileExists($path, 'expected at ' . $path . ' from __DIR__=' . __DIR__);
        $src = (string)\file_get_contents($path);
        self::assertStringNotContainsString('use Weline\\Framework\\Cache\\CacheManager', $src);
        self::assertStringNotContainsString("->pool('fpc')", $src);
        self::assertStringNotContainsString('router-fpc-payloads', $src);
        self::assertStringContainsString('FpcStoreAdapterRegistry', $src);
        self::assertSame('fpc', (new FpcCapability())->code());
    }

    public function testStoreAdapterInterfacePrefixAndWlsCode(): void
    {
        self::assertStringContainsString('fpc/store', FpcStoreAdapterInterface::EXTENDS_RELATIVE_PREFIX);
        self::assertTrue(\class_exists(FpcStoreAdapterRegistry::class));
    }

    public function testEvaluatorEmptyRulesDoesNotBypassGuestTraffic(): void
    {
        self::assertFalse(FpcBypassEvaluator::shouldBypass([
            'query' => ['q' => 'shoes'],
            'headers' => ['cache-control' => 'max-age=0'],
        ], []));
    }
}
