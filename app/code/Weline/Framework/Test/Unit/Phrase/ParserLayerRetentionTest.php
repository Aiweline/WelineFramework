<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;

final class ParserLayerRetentionTest extends TestCase
{
    private array $storage = [];
    private array $instances;
    private mixed $manager;
    private string $memoryLimit;
    private LayerRetentionGeneration $generation;
    private LayerRetentionDictionary $provider;

    protected function setUp(): void
    {
        $this->memoryLimit = (string)ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        Runtime::setMode('wls');
        Parser::clearWorkerCaches();
        $this->generation = new LayerRetentionGeneration();
        $this->provider = new LayerRetentionDictionary();
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->generation);
        $pool = $this->createMock(LayerRetentionPool::class);
        $pool->method('getMultiple')->willReturnCallback(fn(array $keys): array => array_intersect_key($this->storage, array_fill_keys($keys, true)));
        $pool->method('setMultiple')->willReturnCallback(function (array $values): bool { $this->storage = array_replace($this->storage, $values); return true; });
        $pool->method('remember')->willReturnCallback(function ($key, $ttl, $builder) { return $this->storage[$key] ??= $builder(); });
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $this->provider);
        $this->nextRequest('first');
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function testProgressiveDiscoveryRetainsLinearDictionaryStorage(): void
    {
        $modules = array_map(static fn(int $i): string => 'Weline_Retention' . $i, range(1, 24));
        $seed = [];
        foreach ($modules as $module) {
            $words = [];
            for ($i = 0; $i < 512; $i++) {
                $words[$module . ' word ' . $i] = 'en_US ' . $module . ' word ' . $i;
            }
            $seed['en_US'][$module] = $words;
        }
        $this->seedCsv($modules, $seed);
        $this->layers([]); // Warm request/locale plumbing before the measured dictionary workload.
        gc_collect_cycles();
        $before = memory_get_usage(false);
        for ($n = 1; $n <= count($modules); $n++) { $this->layers(array_slice($modules, 0, $n)); }
        $growth = memory_get_usage(false) - $before;
        self::assertLessThan(12 * 1024 * 1024, $growth, '24 incremental module scopes must share their word arrays rather than retain cumulative copies.');
        self::assertSame('en_US Weline_Retention24 word 511', $this->loaded('Weline_Retention24 word 511', $this->layers($modules)));
        self::assertSame([], $this->provider->batches, 'getLayeredWords must not hydrate global dictionary');
    }

    public function testLateModulesPreservePriorScopeAndTranslatedWordPriority(): void
    {
        $this->seedCsv(['Weline_A', 'Weline_B'], [
            'en_US' => [
                'Weline_A' => ['Shared' => 'A', 'Placeholder' => 'Real A'],
                'Weline_B' => ['Shared' => 'B', 'Placeholder' => 'Placeholder', 'Late' => 'Late translated'],
            ],
        ]);
        $old = $this->layers(['Weline_A']);
        $new = $this->layers(['Weline_A', 'Weline_B']);
        self::assertSame('A', $this->loaded('Shared', $old));
        self::assertSame('B', $this->loaded('Shared', $new));
        self::assertSame('Real A', $this->loaded('Placeholder', $new));
        self::assertNull($this->loaded('Late', $old));
        self::assertSame('Late translated', $this->loaded('Late', $new));
        $flat = (new ReflectionMethod(Parser::class, 'materializeLayeredWords'))->invoke(null, $new);
        self::assertSame('B', $flat['Shared']);
        // materializeLayeredWords 对 module_words 使用 array_merge（后模块覆盖），
        // 与 translationFromLoadedLayers 的「跳过原文=译文」策略不同。
        self::assertSame('Placeholder', $flat['Placeholder']);
    }

    public function testModuleCsvPriorityAndLocaleFallbackRemainUnchanged(): void
    {
        $this->seedCsv(['Weline_A', 'Weline_B'], [
            'en_US' => ['Weline_A' => ['Shared' => 'CSV A'], 'Weline_B' => ['Shared' => 'CSV B', 'Default' => 'Default EN']],
        ]);
        self::assertSame('CSV B', $this->loaded('Shared', $this->layers(['Weline_B', 'Weline_A'])));
        // 热路径只装目标语模块 CSV；中性回退不再合并进 module_words。
        self::assertSame('Default EN', $this->loaded('Default', $this->layers(['Weline_A', 'Weline_B'])));
    }

    public function testOverlayAndExclusiveDoNotPollutePublicResults(): void
    {
        $this->seedCsv(['Weline_A'], ['en_US' => ['Weline_A' => ['Shared' => 'Public']]]);
        $layers = $this->layers(['Weline_A']);
        $resolve = new ReflectionMethod(Parser::class, 'doTranslateWordFromLayers');
        self::assertSame('Public', $resolve->invoke(null, 'Shared', $layers));
        foreach (['overlay', 'exclusive'] as $mode) {
            RequestContext::set('phrase.event_dictionary.state', ['locale' => 'en_US', 'active' => true, 'mode' => $mode, 'scope_key' => 'fixture', 'words' => ['Shared' => 'Scoped'], 'keyed_words' => []]);
            self::assertSame('Scoped', $resolve->invoke(null, 'Shared', $layers));
        }
        RequestContext::set('phrase.event_dictionary.state', ['locale' => 'en_US', 'active' => false, 'mode' => 'overlay', 'words' => []]);
        self::assertSame('Public', $resolve->invoke(null, 'Shared', $layers));
    }

    public function testOldRequestGenerationAndLocaleKeepTheirOwnLayers(): void
    {
        $this->seedCsv(['Weline_A'], [
            'en_US' => ['Weline_A' => ['Shared' => 'Old']],
            'fr_FR' => ['Weline_A' => ['Shared' => 'FR']],
        ]);
        $oldFiber = new \Fiber(function (): array {
            $this->nextRequest('old-fiber');
            $this->seedCsv(['Weline_A'], [
                'en_US' => ['Weline_A' => ['Shared' => 'Old']],
                'fr_FR' => ['Weline_A' => ['Shared' => 'FR']],
            ]);
            $layers = $this->layers(['Weline_A']);
            \Fiber::suspend();
            try { return [$this->loaded('Shared', $layers), DictionaryCacheNamespace::fingerprint($layers['locales'])]; }
            finally { Context::leave(); }
        });
        $oldFiber->start();
        $this->generation->version = 2;
        $this->storage = []; // Different namespace generation has a different L2 authority.
        $this->nextRequest('new-generation');
        $this->seedCsv(['Weline_A'], [
            'en_US' => ['Weline_A' => ['Shared' => 'New']],
            'fr_FR' => ['Weline_A' => ['Shared' => 'FR']],
        ]);
        self::assertSame('New', $this->loaded('Shared', $this->layers(['Weline_A'])));
        self::assertSame('FR', $this->loaded('Shared', $this->layers(['Weline_A'], 'fr_FR')));
        $oldFiber->resume();
        self::assertSame(['Old', 'retention-generation-1'], $oldFiber->getReturn());
    }

    public function testConfirmedMissingWordUsesTheExistingWorkerResultCache(): void
    {
        $this->seedCsv(['Weline_A']);
        $layers = $this->layers(['Weline_A']);
        $resolve = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
        self::assertSame('Missing', $resolve->invoke(null, 'Missing', $layers));
        $cache = (new ReflectionProperty(Parser::class, 'workerTranslatedWordsCache'))->getValue();
        self::assertSame('Missing', $cache[$layers['cache_key'] . '|Missing'] ?? null);
        $reads = $this->provider->exactReads;
        self::assertSame('Missing', $resolve->invoke(null, 'Missing', $layers));
        self::assertSame($reads, $this->provider->exactReads);
    }

    private function nextRequest(string $id): void
    {
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId($id);
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->generation);
        State::setRequestLanguageOverride('en_US');
        (new ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null)->enabledCache = false;
    }

    private array $csvSeed = [];

    private function seedCsv(array $modules, array $words = []): void
    {
        $this->csvSeed = $words;
        $property = new ReflectionProperty(Parser::class, 'workerModuleWordsCache');
        $cache = $property->getValue();
        $chain = new ReflectionMethod(Parser::class, 'localeChain');
        foreach (['en_US', 'zh_Hans_CN', 'fr_FR'] as $locale) {
            $locales = $chain->invoke(null, $locale);
            foreach ($modules as $module) {
                $key = DictionaryCacheNamespace::cacheKey(
                    'locale_chain|' . implode(',', $locales) . '|' . $module,
                    $locales,
                );
                $cache[$key] = $words[$locale][$module] ?? [];
            }
        }
        $property->setValue(null, $cache);
    }

    private function layers(array $modules, string $locale = 'en_US'): array
    {
        $layers = (new ReflectionMethod(Parser::class, 'getLayeredWords'))->invoke(null, $locale, $modules);
        $moduleWords = [];
        foreach ((array)($layers['modules'] ?? []) as $module) {
            $moduleWords[$module] = $this->csvSeed[$locale][$module] ?? [];
        }
        $layers['module_words'] = $moduleWords;
        return $layers;
    }
    private function loaded(string $word, array $layers): ?string { return (new ReflectionMethod(Parser::class, 'translationFromLoadedLayers'))->invoke(null, $word, $layers); }
}

interface LayerRetentionPool extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class LayerRetentionGeneration implements NamespaceGenerationInterface
{
    public int $version = 1;
    public function fingerprint(array $namespaces): string { return 'retention-generation-' . $this->version; }
    public function bumpMany(array $namespaces): array { throw new \LogicException('Readonly fixture'); }
    public function bump(string $namespace): array { throw new \LogicException('Readonly fixture'); }
}

final class LayerRetentionDictionary implements GlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface
{
    public array $maps = [];
    public array $batches = [];
    public int $generatedWords = 0;
    public int $exactReads = 0;
    public function word(string $locale, string $word): ?string { $this->exactReads++; return null; }
    public function words(string $locale, array $modules = []): array { throw new \LogicException('Unexpected unscoped read'); }
    public function wordsByModule(string $locale, array $modules): array
    {
        $this->batches[] = [$locale, $modules];
        $result = [];
        foreach ($modules as $module) {
            $result[$module] = $this->maps[$locale][$module] ?? [];
            for ($i = 0; $i < $this->generatedWords; $i++) { $word = $module . ' word ' . $i; $result[$module][$word] = $locale . ' ' . $word; }
        }
        return $result;
    }
}
