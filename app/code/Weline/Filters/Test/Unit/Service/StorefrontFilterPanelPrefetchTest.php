<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Filters\Service\StorefrontFilterPanelService;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class StorefrontFilterPanelPrefetchTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::init();
        $scope = ScopeIdentity::channel(3, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            $scope, 'en_US', 'CNY', hash('sha256', 'filters'), hash('sha256', 'filters-key'), true,
        ));
        Context::current()->set('state.lang_local_cache', ['key' => 'en_US|CNY', 'value' => 'en_US']);
        Parser::clearWorkerCaches();
        StorefrontScopeHotCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        StorefrontScopeHotCache::resetProcessCache();
        RequestContext::cleanup();
    }

    public function testCachedPanelWarmsOnlyDisplayedWordsInBoundedBatchesAndReusesThem(): void
    {
        $panel = $this->panel();
        $provider = $this->installProvider();
        $service = $this->serviceWithSharedPanel($panel);

        self::assertSame($panel, $service->buildPanel([], '/products', [], 3));
        $words = array_merge(['Price label', 'Facet name'], array_column($panel['attributes'][0]['options'], 'label'));
        $translated = [];
        $lookup = new ReflectionMethod(Parser::class, 'loadGlobalDictionaryWord');
        foreach ($words as $word) {
            $translated[$word] = $lookup->invoke(null, 'en_US', $word);
        }

        self::assertSame('Global option', $translated['Option 1']);
        self::assertNull($translated['Option 2']);
        self::assertSame(0, $provider->wordCalls, 'Cached panels must prefetch before template per-word translation.');
        self::assertSame([200, 7], array_map('count', $provider->batches['en_US'] ?? []));
        $prefetched = array_merge(...$provider->batches['en_US']);
        sort($words, SORT_STRING);
        self::assertSame($words, $prefetched, 'Do not prefetch identifiers, URLs, department names or an entire dictionary.');

        self::assertSame($panel, $service->buildPanel([], '/products', [], 3));
        self::assertSame(2, count($provider->batches['en_US']));
        self::assertSame(0, $provider->wordCalls);
    }

    public function testPrefetchedPanelKeepsModuleAndRequestOverridePrecedence(): void
    {
        $provider = $this->installProvider();
        $this->serviceWithSharedPanel($this->panel())->buildPanel([], '/products', [], 3);
        self::assertNotEmpty($provider->batches);
        $layers = [
            'cache_key' => 'filter-panel-test', 'lang' => 'en_US', 'modules' => ['Weline_Filters'],
            'module_words' => ['Weline_Filters' => ['Option 1' => 'Module option']], 'locale_words' => [],
        ];
        $translate = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
        self::assertSame('Module option', $translate->invoke(null, 'Option 1', $layers));
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => 'en_US', 'active' => true, 'mode' => 'overlay', 'scope_key' => 'filter-test',
            'words' => ['Option 1' => 'Request option'], 'keyed_words' => [],
        ]);
        self::assertSame('Request option', $translate->invoke(null, 'Option 1', $layers));
    }

    public function testPanelKeyIgnoresCardOnlyFieldsButTracksFacetIdentity(): void
    {
        $service = (new ReflectionClass(StorefrontFilterPanelService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(StorefrontFilterPanelService::class, 'buildPanelLogicalKey');
        $method->setAccessible(true);

        $base = [[
            'product_id' => 42,
            'unit_price_minor' => 17820,
            'quote_only' => false,
            'combination' => ['size' => ['L', 'M']],
            'image' => '/media/first.jpg',
            'name' => 'First rendering',
        ]];
        $cardOnlyChange = [[
            ...$base[0],
            'image' => '/media/updated.jpg',
            'name' => 'Updated rendering',
        ]];
        $differentProduct = [[...$base[0], 'product_id' => 43]];

        $baseKey = $method->invoke($service, $base, '/category/hanfu', [], 3, false);
        self::assertSame(
            $baseKey,
            $method->invoke($service, $cardOnlyChange, '/category/hanfu', [], 3, false),
        );
        self::assertNotSame(
            $baseKey,
            $method->invoke($service, $differentProduct, '/category/hanfu', [], 3, false),
        );
    }

    private function panel(): array
    {
        return [
            'departments' => [['name' => 'Department already displayed directly', 'url' => '/category/1']],
            'price' => [['label' => 'Price label', 'url' => '/products?price=low']],
            'attributes' => [[
                'name' => 'Facet name', 'code' => 'not-a-label',
                'options' => array_map(static fn(int $id): array => [
                    'label' => 'Option ' . $id, 'value' => 'value-' . $id, 'url' => '/products?af_size=' . $id,
                ], range(1, 205)),
            ]],
        ];
    }

    private function serviceWithSharedPanel(array $panel): StorefrontFilterPanelService
    {
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->method('getCustom')->willReturn([
            'payload' => $panel, 'fresh_until' => microtime(true) + 120,
            'stale_until' => microtime(true) + 900, 'version' => 1,
        ]);
        $manager = $this->createMock(CacheManager::class);
        $manager->method('registerPolicy')->willReturnArgument(0);
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturn('stable-generation');
        $hotCache = new StorefrontScopeHotCache($manager, $generations);
        // A cache-hit read must not need any of the panel's DB builder dependencies.
        $service = (new ReflectionClass(StorefrontFilterPanelService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(StorefrontFilterPanelService::class, 'hotCache'))->setValue($service, $hotCache);
        return $service;
    }

    private function installProvider(): FilterPanelWordProvider
    {
        $provider = new FilterPanelWordProvider();
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $provider);
        $pool = $this->createMock(FilterPanelPhraseCache::class);
        $pool->method('remember')->willReturnCallback(static fn($key, $ttl, $builder) => $builder());
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        return $provider;
    }
}

interface FilterPanelPhraseCache extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class FilterPanelWordProvider implements GlobalDictionaryProviderInterface, BatchGlobalDictionaryProviderInterface
{
    public int $wordCalls = 0;
    public array $batches = [];

    public function word(string $locale, string $word): ?string
    {
        ++$this->wordCalls;
        return $locale === 'en_US' && $word === 'Option 1' ? 'Global option' : null;
    }

    public function words(string $locale, array $modules = []): array
    {
        return [];
    }

    public function exactWords(string $locale, array $words): array
    {
        $this->batches[$locale][] = $words;
        return $locale === 'en_US' && in_array('Option 1', $words, true) ? ['Option 1' => 'Global option'] : [];
    }
}
