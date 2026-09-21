<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;
use Weline\Framework\App\State;

final class ParserGlobalDictionaryModuleCacheTest extends TestCase
{
    private array $storage = [];
    private CachePoolInterface $pool;
    private string $memoryLimit;

    protected function setUp(): void
    {
        $this->memoryLimit = (string)ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        RequestContext::init();
        $this->pool = $this->createMock(ModuleDictionaryCacheFixtureInterface::class);
        $this->pool->method('remember')->willReturnCallback(function ($key, $ttl, $builder) {
            return $this->storage[$key] ??= $builder();
        });
        $this->pool->method('getMultiple')->willReturnCallback(fn(array $keys): array => array_intersect_key($this->storage, array_fill_keys($keys, true)));
        $this->pool->method('setMultiple')->willReturnCallback(function (array $values, int $ttl): bool {
            $this->storage = array_replace($this->storage, $values);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        Runtime::resetModeCache();
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function testTranslatePathDoesNotHitGlobalDictionaryExactWord(): void
    {
        $provider = new class implements GlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface {
            public array $legacyCalls = [];
            public array $batchCalls = [];
            public array $wordCalls = [];
            public function word(string $locale, string $word): ?string
            {
                $this->wordCalls[] = [$locale, $word];
                return $locale === 'en_US' && $word === 'Early source' ? 'Early exact translation' : null;
            }
            public function words(string $locale, array $modules = []): array
            {
                $this->legacyCalls[] = [$locale, $modules];
                return ['Unrelated source' => 'Unrelated full-locale translation'];
            }
            public function wordsByModule(string $locale, array $modules): array
            {
                $this->batchCalls[] = [$locale, $modules];
                $maps = array_fill_keys($modules, []);
                if ($locale === 'en_US' && isset($maps['Weline_LateFixture'])) {
                    $maps['Weline_LateFixture'] = ['Late source' => 'Late module translation'];
                }
                return $maps;
            }
        };
        $this->startWorker($provider);
        RequestContext::setId('early-request-without-modules');
        $translate = new ReflectionMethod(Parser::class, 'translateWordFromLayers');

        $earlyLayers = $this->loadLayers([], 'en_US');
        self::assertSame('Early source', $translate->invoke(null, 'Early source', $earlyLayers));
        self::assertSame([], $provider->wordCalls, '__()/translate must not call global dictionary word()');
        self::assertSame([], $provider->legacyCalls);
        self::assertSame([], $provider->batchCalls);

        $moduleMaps = $this->load(['Weline_LateFixture'], 'en_US', 'loadGlobalDictionaryScopeWords');
        self::assertSame(['Late source' => 'Late module translation'], $moduleMaps);
        self::assertNotEmpty($provider->batchCalls);
        $moduleLayers = $this->loadLayers(['Weline_LateFixture'], 'en_US');
        $moduleLayers['locale_word_layers'] = [$moduleMaps];
        self::assertSame('Late module translation', $translate->invoke(null, 'Late source', $moduleLayers));
    }

    public function testPeersReuseIndependentModuleMapsAcrossDifferentGrowthOrders(): void
    {
        $first = new ModuleDictionaryProviderFixture();
        $this->startWorker($first);
        self::assertSame(['A word' => 'A translation', 'B word' => 'B translation'], $this->load(['Weline_A', 'Weline_B']));
        $peer = new ModuleDictionaryProviderFixture();
        $this->startWorker($peer);
        self::assertSame(['A word' => 'A translation'], $this->load(['Weline_A']));
        self::assertSame(['A word' => 'A translation', 'B word' => 'B translation'], $this->load(['Weline_A', 'Weline_B']));
        self::assertSame([['en_US', ['Weline_A', 'Weline_B']]], $first->batchCalls);
        self::assertSame([], $peer->batchCalls);
        self::assertSame([], $first->legacyCalls);
        self::assertSame([], $peer->legacyCalls);
        self::assertCount(2, $this->storage);
    }

    public function testConfirmedEmptyModuleMapIsSharedAndLocaleRemainsSeparate(): void
    {
        $first = new ModuleDictionaryProviderFixture();
        $this->startWorker($first);
        self::assertSame([], $this->load(['Weline_Empty']));
        $peer = new ModuleDictionaryProviderFixture();
        $this->startWorker($peer);
        self::assertSame([], $this->load(['Weline_Empty']));
        self::assertSame([], $peer->batchCalls);
        self::assertSame(['A word' => 'FR A'], $this->load(['Weline_A'], 'fr_FR'));
        self::assertSame([['fr_FR', ['Weline_A']]], $peer->batchCalls);
        self::assertCount(2, $this->storage);
    }

    public function testFailedModuleBatchDoesNotFreezeTheOuterLocaleCache(): void
    {
        $provider = new ModuleDictionaryProviderFixture();
        $provider->failures = 1;
        $this->startWorker($provider);
        self::assertSame([], $this->load(['Weline_A'], 'en_US', 'loadGlobalDictionaryScopeWords'));
        self::assertSame([], array_filter($this->storage, 'is_array'));
        self::assertSame(['A word' => 'A translation'], $this->load(['Weline_A'], 'en_US', 'loadGlobalDictionaryScopeWords'));
        self::assertCount(2, $provider->batchCalls);
    }

    public function testLegacyProviderAndExplicitFullDictionaryKeepExistingWordsPath(): void
    {
        $legacy = new class implements GlobalDictionaryProviderInterface {
            public array $calls = [];
            public function word(string $locale, string $word): ?string { return null; }
            public function words(string $locale, array $modules = []): array { $this->calls[] = $modules; return ['legacy' => 'Legacy']; }
        };
        $this->startWorker($legacy);
        self::assertSame(['legacy' => 'Legacy'], $this->load(['Weline_A', 'Weline_B']));
        self::assertSame([['Weline_A', 'Weline_B']], $legacy->calls);
        $this->storage = [];
        $provider = new ModuleDictionaryProviderFixture();
        $this->startWorker($provider);
        Runtime::setMode(RuntimeInterface::MODE_FPM);
        self::assertSame(['all' => 'All translations'], $this->load([]));
        self::assertSame([[]], $provider->legacyCalls);
        self::assertSame([], $provider->batchCalls);
    }

    public function testPublicParseDoesNotHydrateGlobalDictionaryOnMiss(): void
    {
        $originalInstances = ObjectManager::getInstances();
        $manager = new ReflectionProperty(ObjectManager::class, 'instance');
        $originalManager = $manager->getValue();
        $manager->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $request = new class {
            public function getModules(): array { return ['Weline_A']; }
            public function getModuleName(): string { return 'Weline_A'; }
        };
        ObjectManager::setInstance(Request::class, $request);
        $provider = new ModuleDictionaryProviderFixture();
        $this->startWorker($provider);
        RequestContext::setId('module-dictionary-readonly');
        State::setRequestLanguageOverride('en_US');
        try {
            $word = 'A word';
            self::assertSame('A word', Parser::parse($word));
            self::assertSame([], $provider->batchCalls);
            self::assertSame([], $provider->legacyCalls);
        } finally {
            (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $originalInstances);
            $manager->setValue(null, $originalManager);
            State::resetRequestPathLocalizationCache();
        }
    }

    public function testSingleFlightOwnerRechecksAfterAConcurrentBatchHasPublished(): void
    {
        $provider = new ModuleDictionaryProviderFixture();
        $this->startWorker($provider);
        $pool = $this->createMock(ModuleDictionaryCacheFixtureInterface::class);
        $reads = 0;
        $pool->method('getMultiple')->willReturnCallback(static function (array $keys) use (&$reads): array {
            if (++$reads === 1) { return []; }
            return array_combine($keys, [['A word' => 'A translation'], ['B word' => 'B translation']]);
        });
        $pool->expects(self::once())->method('remember')->willReturnCallback(static function ($key, $ttl, $builder, $options): mixed {
            self::assertTrue($options->singleFlight);
            self::assertFalse($options->computeOnSingleFlightTimeout);
            return $builder();
        });
        $pool->expects(self::never())->method('setMultiple');
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        self::assertSame(['A word' => 'A translation', 'B word' => 'B translation'], $this->load(['Weline_A', 'Weline_B']));
        self::assertSame([], $provider->batchCalls);
    }

    public function testInterleavedModulePublicationDoesNotOverwriteTheOtherFiberSnapshot(): void
    {
        $provider = new ModuleDictionaryProviderFixture();
        $this->startWorker($provider);
        $provider->onBatch = function (): void {
            self::assertSame(['B word' => 'B translation'], $this->load(['Weline_B']));
        };
        self::assertSame(['B word' => 'B translation', 'A word' => 'A translation'], $this->load(['Weline_A']));
        self::assertSame(['B word' => 'B translation', 'A word' => 'A translation'], $this->load(['Weline_A', 'Weline_B']));
        self::assertCount(2, $provider->batchCalls);
    }

    private function startWorker(GlobalDictionaryProviderInterface $provider): void
    {
        Parser::clearWorkerCaches();
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $this->pool);
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $provider);
    }

    private function load(array $modules, string $locale = 'en_US', string $method = 'loadGlobalDictionaryScopeWords'): array
    {
        $ref = new ReflectionMethod(Parser::class, $method);
        if ($method === 'getLayeredWords') {
            return $ref->invoke(null, $locale, $modules);
        }
        if ($method === 'loadLocaleWords') {
            return $ref->invoke(null, $locale, $modules);
        }

        return $ref->invoke(null, $locale, $modules);
    }

    private function loadLayers(array $modules, string $locale = 'en_US'): array
    {
        return (new ReflectionMethod(Parser::class, 'getLayeredWords'))->invoke(null, $locale, $modules);
    }
}

interface ModuleDictionaryCacheFixtureInterface extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class ModuleDictionaryProviderFixture implements GlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface
{
    public array $batchCalls = [];
    public array $legacyCalls = [];
    public int $failures = 0;
    public ?\Closure $onBatch = null;
    public function word(string $locale, string $word): ?string { return null; }
    public function words(string $locale, array $modules = []): array
    {
        $this->legacyCalls[] = $modules;
        if ($locale === 'en_US' && $this->failures-- > 0) { throw new \RuntimeException('temporary dictionary failure'); }
        return $modules === [] ? ['all' => 'All translations'] : array_merge(...array_values($this->maps($locale, $modules)));
    }
    public function wordsByModule(string $locale, array $modules): array
    {
        $this->batchCalls[] = [$locale, $modules];
        if ($locale === 'en_US' && $this->failures-- > 0) { throw new \RuntimeException('temporary dictionary failure'); }
        if ($this->onBatch !== null) {
            $hook = $this->onBatch;
            $this->onBatch = null;
            $hook();
        }
        return $this->maps($locale, $modules);
    }
    private function maps(string $locale, array $modules): array
    {
        $maps = array_fill_keys($modules, []);
        if (!in_array($locale, ['en_US', 'fr_FR'], true)) { return $maps; }
        foreach ($modules as $module) {
            if ($module === 'Weline_A') { $maps[$module] = ['A word' => $locale === 'fr_FR' ? 'FR A' : 'A translation']; }
            if ($module === 'Weline_B') { $maps[$module] = ['B word' => 'B translation']; }
        }
        return $maps;
    }
}
