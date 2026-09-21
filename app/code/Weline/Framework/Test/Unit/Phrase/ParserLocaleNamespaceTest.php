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
use Weline\Framework\Cache\Contract\RemembererInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\ModuleGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;

final class ParserLocaleNamespaceTest extends TestCase
{
    private array $storage = [];
    private array $instances;
    private mixed $manager;
    private array $envConfig;
    private LocaleNamespaceAuthority $authority;
    private LocaleNamespaceDictionary $provider;
    private CachePoolInterface $pool;

    protected function setUp(): void
    {
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $this->envConfig = (new ReflectionProperty(Env::class, 'config'))->getValue(Env::getInstance());
        Runtime::setMode('wls');
        Parser::clearWorkerCaches();
        $this->authority = new LocaleNamespaceAuthority();
        $this->provider = new LocaleNamespaceDictionary();
        $raw = $this->createMock(LocaleNamespacePool::class);
        $raw->method('getMultiple')->willReturnCallback(fn(array $keys): array => array_intersect_key($this->storage, array_fill_keys($keys, true)));
        $raw->method('setMultiple')->willReturnCallback(function (array $values): bool { $this->storage = array_replace($this->storage, $values); return true; });
        $raw->method('remember')->willReturnCallback(function ($key, $ttl, $builder) { return $this->storage[$key] ??= $builder(); });
        $this->pool = new LocaleNamespaceScopedPool($raw, ['global/i18n'], $this->authority);
        $this->installParser();
        $this->nextRequest('first');
        $this->defaultLocale('zh_Hans_CN');
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
    }

    public function testNamespacePathsNormalizeAndKeepLegacyUnknownScopeConservative(): void
    {
        self::assertSame(['global/i18n/content'], DictionaryCacheNamespace::namespacePaths());
        self::assertSame(['global/i18n/en_US', 'global/i18n/fr_FR', 'global/i18n/zh_Hans_CN'],
            DictionaryCacheNamespace::namespacePaths(['fr-FR', 'en_US', 'zh_hans_cn', 'fr_FR']));
        foreach ([[''], ['unknown'], ['../en_US'], [null], ['en_US', '../bad']] as $unknown) {
            self::assertSame(['global/i18n/content'], DictionaryCacheNamespace::namespacePaths($unknown));
        }
    }

    public function testLocaleFingerprintPinsEachDependencyAndRetainsRootInvalidation(): void
    {
        $en = DictionaryCacheNamespace::fingerprint(['en_US']);
        $fr = DictionaryCacheNamespace::fingerprint(['fr_FR']);
        self::assertNotSame($en, $fr);
        $this->authority->bump('global/i18n/fr_FR');
        self::assertSame($fr, DictionaryCacheNamespace::fingerprint(['fr-FR']));
        $this->nextRequest('next');
        self::assertSame($en, DictionaryCacheNamespace::fingerprint(['en-US']));
        self::assertNotSame($fr, DictionaryCacheNamespace::fingerprint(['fr_FR']));
        $this->authority->bump('global/i18n');
        $this->nextRequest('root');
        self::assertNotSame($en, DictionaryCacheNamespace::fingerprint(['en_US']));
    }

    public function testLegacyNoArgumentReadersTrackAllContentWithoutInvalidatingLocaleLeaves(): void
    {
        $legacy = DictionaryCacheNamespace::fingerprint();
        $en = DictionaryCacheNamespace::fingerprint(['en_US']);
        $this->authority->bump('global/i18n/content');
        $this->authority->bump('global/i18n/fr_FR');
        $this->nextRequest('content-changed');
        self::assertNotSame($legacy, DictionaryCacheNamespace::fingerprint());
        self::assertSame($en, DictionaryCacheNamespace::fingerprint(['en_US']));
    }

    public function testExactPrefetchUsesPerLocaleL1AndL2AcrossWorkers(): void
    {
        $this->provider->exact = ['fr_FR' => ['Word' => 'FR'], 'en_US' => ['Word' => 'EN']];
        Parser::prefetchWords(['Word', 'Absent'], 'fr_FR');
        self::assertSame('FR', $this->exact('fr_FR', 'Word'));
        self::assertSame('EN', $this->exact('en_US', 'Word'));
        self::assertNull($this->exact('en_US', 'Absent'));
        $this->authority->bump('global/i18n/de_DE');
        $this->nextRequest('unrelated');
        Parser::clearWorkerCaches(); // Another Worker must reuse the same locale-scoped L2 records.
        $this->installParser();
        Parser::prefetchWords(['Word', 'Absent'], 'fr_FR');
        self::assertSame(['fr_FR' => 1, 'en_US' => 1, 'zh_Hans_CN' => 1], $this->provider->batchReads);
        $this->provider->exact['en_US']['Word'] = 'EN new';
        $this->authority->bump('global/i18n/en_US');
        $this->nextRequest('changed-en');
        Parser::prefetchWords(['Word', 'Absent'], 'fr_FR');
        self::assertSame('EN new', $this->exact('en_US', 'Word'));
        self::assertSame(['fr_FR' => 1, 'en_US' => 2, 'zh_Hans_CN' => 1], $this->provider->batchReads);
        self::assertSame(0, $this->provider->wordReads);
    }

    public function testLayerScopeRefreshesOnlyForItsActualLocaleDependencies(): void
    {
        $this->provider->maps = ['fr_FR' => ['Weline_A' => ['Target' => 'FR']], 'en_US' => ['Weline_A' => ['Neutral' => 'EN']], 'zh_Hans_CN' => ['Weline_A' => ['Default' => 'ZH']]];
        $this->seedCsv();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        $before = $this->layers();
        $this->authority->bump('global/i18n/de_DE');
        $this->nextRequest('unrelated');
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        $same = $this->layers();
        self::assertSame($before['cache_key'], $same['cache_key']);
        self::assertCount(3, $this->provider->moduleReads);
        $this->provider->maps['zh_Hans_CN']['Weline_A']['Default'] = 'ZH new';
        $this->authority->bump('global/i18n/zh_Hans_CN');
        $this->nextRequest('fallback');
        $this->seedCsv();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        $next = $this->layers();
        self::assertNotSame($before['cache_key'], $next['cache_key']);
        self::assertSame('ZH new', $this->loaded('Default', $next));
        self::assertSame('ZH', $this->loaded('Default', $before));
        self::assertCount(4, $this->provider->moduleReads);
        $this->authority->bump('global/i18n');
        $this->nextRequest('root');
        $this->seedCsv();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        self::assertNotSame($next['cache_key'], $this->layers()['cache_key']);
        self::assertCount(7, $this->provider->moduleReads);
    }

    public function testDefaultLocaleChangesRequestIdentityAndFrozenFallbackChain(): void
    {
        $this->provider->maps = ['zh_Hans_CN' => ['Weline_A' => ['Default' => 'ZH']], 'de_DE' => ['Weline_A' => ['Default' => 'DE']]];
        $this->provider->exact = ['zh_Hans_CN' => ['Dynamic' => 'ZH dynamic'], 'de_DE' => ['Dynamic' => 'DE dynamic']];
        $this->seedCsv();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        Parser::prefetchWords(['Dynamic'], 'fr_FR');
        $old = $this->currentLayers();
        $this->defaultLocale('de_DE');
        $this->nextRequest('default-de');
        $this->seedCsv();
        Parser::prefetchGlobalDictionaryModules(['Weline_A']);
        Parser::prefetchWords(['Dynamic'], 'fr_FR');
        $next = $this->currentLayers();
        self::assertNotSame($old['cache_key'], $next['cache_key']);
        self::assertSame(['fr_FR', 'en_US', 'zh_Hans_CN'], $old['locales']);
        self::assertSame(['fr_FR', 'en_US', 'de_DE'], $next['locales']);
        self::assertSame('ZH', $this->loaded('Default', $old));
        self::assertSame('DE', $this->loaded('Default', $next));
        self::assertSame('ZH dynamic', (new ReflectionMethod(Parser::class, 'doTranslateWordFromLayers'))->invoke(null, 'Dynamic', $old));
        self::assertSame('DE dynamic', (new ReflectionMethod(Parser::class, 'doTranslateWordFromLayers'))->invoke(null, 'Dynamic', $next));
    }

    public function testChineseChainDoesNotDependOnNeutralOrWebsiteDefaultLocale(): void
    {
        $this->seedCsv();
        $old = $this->layers('zh_Hans_CN');
        $this->authority->bump('global/i18n/en_US');
        $this->nextRequest('neutral-changed');
        $this->defaultLocale('de_DE');
        self::assertSame($old['cache_key'], $this->layers('zh_Hans_CN')['cache_key']);
        self::assertSame(['zh_Hans_CN'], $old['locales']);
        // 热路径不再因 getLayeredWords 触发全局词典 hydrate。
        self::assertSame([], $this->provider->moduleReads);
    }

    private function nextRequest(string $id): void
    {
        Context::enter(new Context()); RequestContext::init(); RequestContext::setId($id);
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->authority);
        ObjectManager::setInstance(Request::class, new class { public function getModules(): array { return ['Weline_A']; } public function getModuleName(): string { return 'Weline_A'; } });
        State::setRequestLanguageOverride('fr_FR');
        (new ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null)->enabledCache = false;
    }
    private function installParser(): void
    {
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $this->pool);
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $this->provider);
    }
    private function defaultLocale(string $locale): void
    {
        $config = $this->envConfig; $config['website']['language'] = $locale;
        (new ReflectionProperty(Env::class, 'config'))->setValue(Env::getInstance(), $config);
    }
    private function seedCsv(): void
    {
        $p = new ReflectionProperty(Parser::class, 'workerModuleWordsCache');
        $cache = $p->getValue();
        $chain = new ReflectionMethod(Parser::class, 'localeChain');
        foreach (['fr_FR', 'en_US', 'zh_Hans_CN', 'de_DE'] as $locale) {
            $locales = $chain->invoke(null, $locale);
            $key = DictionaryCacheNamespace::cacheKey(
                'locale_chain|' . implode(',', $locales) . '|Weline_A',
                $locales,
            );
            $cache[$key] = [];
        }
        $p->setValue(null, $cache);
    }
    private function layers(string $locale = 'fr_FR'): array { return (new ReflectionMethod(Parser::class, 'getLayeredWords'))->invoke(null, $locale, ['Weline_A']); }
    private function currentLayers(): array { return (new ReflectionMethod(Parser::class, 'getCurrentLayeredWords'))->invoke(null); }
    private function loaded(string $word, array $layers): ?string { return (new ReflectionMethod(Parser::class, 'translationFromLoadedLayers'))->invoke(null, $word, $layers); }
    private function exact(string $locale, string $word): mixed { return (new ReflectionMethod(Parser::class, 'loadGlobalDictionaryWord'))->invoke(null, $locale, $word); }
}

interface LocaleNamespacePool extends CachePoolInterface, RemembererInterface {}

/** Exercise the real namespace pool; only its generation repository boundary is replaced. */
final class LocaleNamespaceScopedPool extends NamespaceScopedCachePool
{
    public function __construct(CachePoolInterface $pool, private array $fixturePaths, private LocaleNamespaceAuthority $authority)
    {
        parent::__construct($pool, [], null, new \Weline\Framework\Cache\Namespace\NamespaceKeyDecorator());
    }
    public function withNamespaces(array $namespaces): \Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface
    {
        return new self($this->pool, array_merge($this->fixturePaths, $namespaces), $this->authority);
    }
    public function getNamespaceFingerprint(): string { return $this->authority->fingerprint($this->fixturePaths); }
}

final class LocaleNamespaceAuthority implements NamespaceGenerationInterface
{
    public array $versions = [];
    public function fingerprint(array $namespaces): string
    {
        $snapshot = RequestContext::get('fixture.locale_namespace_vector', []);
        $vector = [];
        foreach ((new NamespacePath())->expandAncestors($namespaces) as $namespace) {
            if (!array_key_exists($namespace, $snapshot)) { $snapshot[$namespace] = $this->versions[$namespace] ?? 0; }
            $vector[$namespace] = $snapshot[$namespace];
        }
        RequestContext::set('fixture.locale_namespace_vector', $snapshot);
        return (new \Weline\Framework\Cache\Namespace\NamespaceKeyDecorator())->fingerprint($vector);
    }
    public function bumpMany(array $namespaces): array { foreach ($namespaces as $namespace) { $this->versions[$namespace] = ($this->versions[$namespace] ?? 0) + 1; } return $this->versions; }
    public function bump(string $namespace): array { return $this->bumpMany([$namespace]); }
}

final class LocaleNamespaceDictionary implements GlobalDictionaryProviderInterface, ModuleGlobalDictionaryProviderInterface, BatchGlobalDictionaryProviderInterface
{
    public array $maps = []; public array $exact = []; public array $moduleReads = []; public array $batchReads = []; public int $wordReads = 0;
    public function word(string $locale, string $word): ?string { $this->wordReads++; return $this->exact[$locale][$word] ?? null; }
    public function words(string $locale, array $modules = []): array { throw new \LogicException('Unexpected unscoped read'); }
    public function wordsByModule(string $locale, array $modules): array { $this->moduleReads[] = [$locale, $modules]; return array_intersect_key($this->maps[$locale] ?? [], array_fill_keys($modules, true)) + array_fill_keys($modules, []); }
    public function exactWords(string $locale, array $words): array { $this->batchReads[$locale] = ($this->batchReads[$locale] ?? 0) + 1; return array_intersect_key($this->exact[$locale] ?? [], array_fill_keys($words, true)); }
}
