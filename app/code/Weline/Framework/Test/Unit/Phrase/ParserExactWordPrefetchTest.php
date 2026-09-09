<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Phrase\BatchGlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;

final class ParserExactWordPrefetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::init();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::remove('phrase.event_dictionary.state');
        RequestContext::cleanup();
        parent::tearDown();
    }

    public function testPrefetchCachesExactHitsAndMissesWithoutPerWordQueries(): void
    {
        $provider = $this->installProvider();
        Parser::prefetchWords(['Menu hit', 'Menu miss', 'Menu hit'], 'en_US');

        self::assertSame('Translated menu', $this->exactWord('Menu hit'));
        self::assertNull($this->exactWord('Menu miss'));
        self::assertSame(0, $provider->wordCalls);
        self::assertSame(1, $provider->batchCalls['en_US'] ?? 0);

        Parser::prefetchWords(['Menu hit', 'Menu miss'], 'en_US');
        self::assertSame(1, $provider->batchCalls['en_US'] ?? 0);
    }

    public function testFailedBatchDoesNotTurnUnknownWordsIntoCachedMisses(): void
    {
        $provider = $this->installProvider();
        $provider->failBatch = true;
        Parser::prefetchWords(['Menu hit'], 'en_US');

        self::assertSame('Translated menu', $this->exactWord('Menu hit'));
        self::assertSame(1, $provider->wordCalls);
    }

    public function testNewWorkerReusesSharedExactHitsAndConfirmedMisses(): void
    {
        $storage = [];
        $pool = $this->createMock(ExactWordPrefetchCacheFixtureInterface::class);
        $pool->method('getMultiple')->willReturnCallback(
            static function (array $keys) use (&$storage): array {
                return \array_intersect_key($storage, \array_fill_keys($keys, true));
            },
        );
        $pool->method('setMultiple')->willReturnCallback(
            static function (array $values, int $ttl) use (&$storage): bool {
                self::assertGreaterThan(0, $ttl);
                $storage = \array_replace($storage, $values);
                return true;
            },
        );
        $first = $this->installProvider();
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        Parser::prefetchWords(['Menu hit', 'Menu miss'], 'en_US');
        self::assertSame(1, $first->batchCalls['en_US'] ?? 0);

        $peer = $this->installProvider();
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        Parser::prefetchWords(['Menu hit', 'Menu miss'], 'en_US');

        self::assertSame([], $peer->batchCalls);
        self::assertSame('Translated menu', $this->exactWord('Menu hit'));
        self::assertNull($this->exactWord('Menu miss'));
        self::assertSame(0, $peer->wordCalls);
    }

    public function testPrefetchLeavesModuleAndRequestOverridePrecedenceIntact(): void
    {
        $this->installProvider();
        Parser::prefetchWords(['Menu hit'], 'en_US');
        $layers = [
            'cache_key' => 'prefetch-test', 'lang' => 'en_US',
            'modules' => ['Weline_Test'],
            'module_words' => ['Weline_Test' => ['Menu hit' => 'Module title']],
            'locale_words' => [],
        ];
        $this->setOverlay([]);
        $translate = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
        self::assertSame('Module title', $translate->invoke(null, 'Menu hit', $layers));
        $this->setOverlay(['Menu hit' => 'Website override']);
        self::assertSame('Website override', $translate->invoke(null, 'Menu hit', $layers));
    }

    public function testPrefetchPreservesLocaleFallbackWithoutSingleWordQueries(): void
    {
        $provider = $this->installProvider();
        Parser::prefetchWords(['Menu hit'], 'fr_FR');
        $this->setOverlay([], 'fr_FR');
        $layers = [
            'cache_key' => 'prefetch-fallback', 'lang' => 'fr_FR',
            'modules' => [], 'module_words' => [], 'locale_words' => [],
        ];
        self::assertSame('Translated menu',
            (new ReflectionMethod(Parser::class, 'translateWordFromLayers'))->invoke(null, 'Menu hit', $layers),
        );
        self::assertSame(0, $provider->wordCalls);
        self::assertSame(1, $provider->batchCalls['fr_FR'] ?? 0);
        self::assertSame(1, $provider->batchCalls['en_US'] ?? 0);
    }

    public function testExclusiveDictionaryDoesNotReadPublicWordsDuringPrefetch(): void
    {
        $provider = $this->installProvider();
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => 'en_US', 'active' => true, 'mode' => EventDictionary::MODE_EXCLUSIVE,
            'scope_key' => 'exclusive-test', 'words' => ['Menu hit' => 'Page title'], 'keyed_words' => [],
        ]);

        Parser::prefetchWords(['Menu hit', 'Menu miss'], 'en_US');

        self::assertSame([], $provider->batchCalls);
        self::assertSame(0, $provider->wordCalls);
        self::assertSame([], (new ReflectionProperty(Parser::class, 'workerGlobalDictionaryWordCache'))->getValue());
    }

    private function installProvider(): ExactWordPrefetchProviderFixture
    {
        Parser::clearWorkerCaches();
        $provider = new ExactWordPrefetchProviderFixture();
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $provider);
        $pool = $this->createMock(ExactWordPrefetchCacheFixtureInterface::class);
        $pool->method('remember')->willReturnCallback(static fn($key, $ttl, $builder) => $builder());
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        return $provider;
    }

    private function exactWord(string $word): mixed
    {
        return (new ReflectionMethod(Parser::class, 'loadGlobalDictionaryWord'))->invoke(null, 'en_US', $word);
    }

    private function setOverlay(array $words, string $locale = 'en_US'): void
    {
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => $locale, 'active' => $words !== [], 'mode' => 'overlay',
            'scope_key' => 'test', 'words' => $words, 'keyed_words' => [],
        ]);
    }
}

interface ExactWordPrefetchCacheFixtureInterface extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class ExactWordPrefetchProviderFixture implements GlobalDictionaryProviderInterface, BatchGlobalDictionaryProviderInterface
{
    public array $batchCalls = [];
    public int $wordCalls = 0;
    public bool $failBatch = false;

    public function word(string $locale, string $word): ?string
    {
        $this->wordCalls++;
        return $locale === 'en_US' && $word === 'Menu hit' ? 'Translated menu' : null;
    }

    public function words(string $locale, array $modules = []): array
    {
        return [];
    }

    public function exactWords(string $locale, array $words): array
    {
        $this->batchCalls[$locale] = ($this->batchCalls[$locale] ?? 0) + 1;
        if ($this->failBatch) {
            throw new \RuntimeException('Transient dictionary failure');
        }
        return $locale === 'en_US' ? ['Menu hit' => 'Translated menu'] : [];
    }
}
