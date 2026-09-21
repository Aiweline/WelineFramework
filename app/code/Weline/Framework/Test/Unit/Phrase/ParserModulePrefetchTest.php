<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Http\Request;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;

require_once __DIR__ . '/ParserLocaleNamespaceTest.php';

final class ParserModulePrefetchTest extends TestCase
{
    private array $storage = [];
    private array $instances;
    private mixed $manager;
    private array $envConfig;
    private string $memoryLimit;
    private LocaleNamespaceAuthority $authority;
    private ModulePrefetchDictionary $provider;
    private CachePoolInterface $pool;
    private object $request;
    private int $poolReads = 0;
    private int $poolWrites = 0;

    protected function setUp(): void
    {
        $this->memoryLimit = (string)ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        $this->instances = ObjectManager::getInstances();
        $manager = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $manager->getValue();
        $manager->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $this->envConfig = (new ReflectionProperty(Env::class, 'config'))->getValue(Env::getInstance());
        $config = $this->envConfig;
        $config['website']['language'] = 'zh_Hans_CN';
        (new ReflectionProperty(Env::class, 'config'))->setValue(Env::getInstance(), $config);
        Runtime::setMode('wls');
        Parser::clearWorkerCaches();
        $this->authority = new LocaleNamespaceAuthority();
        $this->provider = new ModulePrefetchDictionary();
        $raw = $this->createMock(LocaleNamespacePool::class);
        $raw->method('getMultiple')->willReturnCallback(function (array $keys): array {
            $this->poolReads++;
            return array_intersect_key($this->storage, array_fill_keys($keys, true));
        });
        $raw->method('setMultiple')->willReturnCallback(function (array $values): bool {
            $this->poolWrites++;
            $this->storage = array_replace($this->storage, $values);
            return true;
        });
        $raw->method('remember')->willReturnCallback(function ($key, $ttl, $builder) {
            $this->poolReads++;
            return $this->storage[$key] ??= $builder();
        });
        $this->pool = new LocaleNamespaceScopedPool($raw, ['global/i18n'], $this->authority);
        $this->request = new class {
            public array $modules = [];
            public function getModules(): array { return $this->modules; }
            public function getModuleName(): string { return ''; }
        };
        $this->installParser();
        $this->nextRequest('first');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Env::class, 'config'))->setValue(Env::getInstance(), $this->envConfig);
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function testPrefetchDoesNotActivateModulesAndLaterConsumptionUsesNoIo(): void
    {
        $empty = $this->currentLayers();
        $stateMethod = new ReflectionMethod(Parser::class, 'requestState');
        $stateMethod->setAccessible(true);
        $signature = $stateMethod->invoke(null)->layeredWordsSignature;
        Parser::prefetchGlobalDictionaryModules(['Weline_B', 'Weline_A', 'Weline_B', '', null]);
        self::assertSame([], Parser::resolveRequestModules());
        self::assertSame($signature, $stateMethod->invoke(null)->layeredWordsSignature);
        self::assertSame($empty, $this->currentLayers());
        self::assertSame([], (new ReflectionProperty(Parser::class, 'workerGlobalDictionaryLoadedModules'))->getValue());
        self::assertSame([['en_US', ['Weline_A', 'Weline_B']], ['zh_Hans_CN', ['Weline_A', 'Weline_B']]], $this->provider->batches);
        $this->seedCsv(['Weline_A', 'Weline_B']);
        $io = [$this->poolReads, $this->poolWrites];
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        $this->request->modules = ['Weline_A'];
        $old = $this->currentLayers();
        self::assertSame('A translation', $this->loaded('Shared', $old));
        $this->request->modules[] = 'Weline_B';
        $next = $this->currentLayers();
        self::assertSame('B translation', $this->loaded('Shared', $next));
        self::assertSame('A real', $this->loaded('Placeholder', $next));
        self::assertSame('A translation', $this->loaded('Shared', $old));
        self::assertNull($this->loaded('Late', $old));
        self::assertSame('Late translation', $this->loaded('Late', $next));
        self::assertSame($io, [$this->poolReads, $this->poolWrites]);
        self::assertCount(2, $this->provider->batches);
    }

    public function testPeerReusesAtomicMapsFromWlsAndOnlyChangedLocaleReloads(): void
    {
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        Parser::clearWorkerCaches();
        $this->installParser();
        $this->nextRequest('peer');
        Parser::prefetchGlobalDictionaryModules(['Weline_B', 'Weline_A']);
        self::assertCount(2, $this->provider->batches);
        $this->authority->bump('global/i18n/fr_FR');
        $this->nextRequest('unrelated');
        $io = [$this->poolReads, $this->poolWrites];
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        self::assertSame($io, [$this->poolReads, $this->poolWrites]);
        $this->authority->bump('global/i18n/en_US');
        $this->nextRequest('target');
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        self::assertCount(3, $this->provider->batches);
        self::assertSame('en_US', $this->provider->batches[2][0]);
        $this->authority->bump('global/i18n/zh_Hans_CN');
        $this->nextRequest('fallback');
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        self::assertCount(4, $this->provider->batches);
        self::assertSame('zh_Hans_CN', $this->provider->batches[3][0]);
        $this->authority->bump('global/i18n');
        $this->nextRequest('root');
        Parser::prefetchGlobalDictionaryModules(['Weline_A', 'Weline_B']);
        self::assertCount(6, $this->provider->batches);
    }

    public function testFrozenLanguageChainIsUsedWithoutCreatingOrChangingContext(): void
    {
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(null, 'en_US', 'CNY', null, str_repeat('a', 64), false, defaultLocale: 'de_DE'));
        $context = StorefrontCacheKeyContext::current();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        self::assertSame([['en_US', ['Weline_A']], ['de_DE', ['Weline_A']]], $this->provider->batches);
        self::assertSame($context, StorefrontCacheKeyContext::current());
        State::setRequestLanguageOverride('zh_Hans_CN');
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        self::assertSame(['zh_Hans_CN', ['Weline_A']], $this->provider->batches[2]);
        self::assertSame($context, StorefrontCacheKeyContext::current());
        $this->nextRequest('no-frozen-context');
        self::assertNull(StorefrontCacheKeyContext::current());
        Parser::prefetchGlobalDictionaryModules(['Weline_B']);
        self::assertNull(StorefrontCacheKeyContext::current());
    }

    public function testEmptyModulesAndFailedPrefetchDoNotPublishCompletion(): void
    {
        Parser::prefetchGlobalDictionaryModules([]);
        Parser::prefetchGlobalDictionaryModules(['', null]);
        self::assertSame([], $this->provider->batches);
        $this->provider->failures = 1;
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        self::assertSame([], (new ReflectionProperty(Parser::class, 'workerGlobalDictionaryLoadedModules'))->getValue());
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        self::assertSame(3, count($this->provider->batches));
        $this->seedCsv(['Weline_A']);
        $this->request->modules = ['Weline_A'];
        self::assertSame('A translation', $this->loaded('Shared', $this->currentLayers()));
        self::assertSame([], $this->provider->legacyCalls);
    }

    public function testPrefetchedAtomicMapsAndProgressiveLayersShareWordStorage(): void
    {
        $modules = array_map(static fn(int $i): string => 'Weline_Prefetch' . $i, range(1, 24));
        $this->provider->generatedWords = 512;
        $this->seedCsv($modules);
        $this->currentLayers();
        gc_collect_cycles();
        $before = memory_get_usage(false);
        Parser::prefetchGlobalDictionaryModules($modules);
        for ($n = 1; $n <= count($modules); $n++) {
            $this->request->modules = array_slice($modules, 0, $n);
            $this->currentLayers();
        }
        self::assertLessThan(24 * 1024 * 1024, memory_get_usage(false) - $before);
        self::assertCount(2, $this->provider->batches);
        self::assertSame('en_US Weline_Prefetch24 word 511', $this->loaded('Weline_Prefetch24 word 511', $this->currentLayers()));
    }

    private function nextRequest(string $id): void
    {
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId($id);
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->authority);
        ObjectManager::setInstance(Request::class, $this->request);
        State::setRequestLanguageOverride('en_US');
        (new ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null)->enabledCache = false;
    }

    private function installParser(): void
    {
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $this->pool);
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $this->provider);
    }

    private function seedCsv(array $modules): void
    {
        $property = new ReflectionProperty(Parser::class, 'workerModuleWordsCache');
        $cache = $property->getValue();
        $chain = new ReflectionMethod(Parser::class, 'localeChain');
        foreach (['en_US', 'zh_Hans_CN', 'de_DE'] as $locale) {
            $locales = $chain->invoke(null, $locale);
            foreach ($modules as $module) {
                $key = DictionaryCacheNamespace::cacheKey(
                    'locale_chain|' . implode(',', $locales) . '|' . $module,
                    $locales,
                );
                $cache[$key] = [];
            }
        }
        $property->setValue(null, $cache);
    }

    private function currentLayers(): array { return (new ReflectionMethod(Parser::class, 'getCurrentLayeredWords'))->invoke(null); }
    private function loaded(string $word, array $layers): ?string { return (new ReflectionMethod(Parser::class, 'translationFromLoadedLayers'))->invoke(null, $word, $layers); }
}

final class ModulePrefetchDictionary implements GlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface
{
    public array $batches = [];
    public array $legacyCalls = [];
    public int $failures = 0;
    public int $generatedWords = 0;

    public function word(string $locale, string $word): ?string { return null; }
    public function words(string $locale, array $modules = []): array { $this->legacyCalls[] = [$locale, $modules]; return []; }
    public function wordsByModule(string $locale, array $modules): array
    {
        $this->batches[] = [$locale, $modules];
        if ($this->failures > 0) { $this->failures--; throw new \RuntimeException('Recoverable fixture failure'); }
        $maps = [];
        foreach ($modules as $module) {
            $maps[$module] = $locale !== 'en_US' ? [] : match ($module) {
                'Weline_A' => ['Shared' => 'A translation', 'Placeholder' => 'A real'],
                'Weline_B' => ['Shared' => 'B translation', 'Placeholder' => 'Placeholder', 'Late' => 'Late translation'],
                default => [],
            };
            for ($i = 0; $i < $this->generatedWords; $i++) {
                $word = $module . ' word ' . $i;
                $maps[$module][$word] = $locale . ' ' . $word;
            }
        }
        return $maps;
    }
}
