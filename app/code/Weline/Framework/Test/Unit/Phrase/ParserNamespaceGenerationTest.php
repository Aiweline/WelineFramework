<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Context;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\GlobalDictionaryProviderInterface;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ParserNamespaceGenerationTest extends TestCase
{
    private array $instances;
    private mixed $manager;
    private DictionaryNamespaceAuthorityFixture $authority;
    private DictionaryNamespaceProviderFixture $provider;

    protected function setUp(): void
    {
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Parser::clearWorkerCaches();
        $this->authority = new DictionaryNamespaceAuthorityFixture();
        ObjectManager::setInstance(NamespaceGenerationInterface::class, $this->authority);
        $this->provider = new DictionaryNamespaceProviderFixture();
        (new ReflectionProperty(Parser::class, 'globalDictionaryProviderInstance'))->setValue(null, $this->provider);
        $pool = $this->createMock(DictionaryNamespacePoolFixture::class);
        $pool->method('remember')->willReturnCallback(static fn($key, $ttl, $builder) => $builder());
        (new ReflectionProperty(Parser::class, 'sharedPhraseCachePool'))->setValue(null, $pool);
        $this->nextRequest();
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
    }

    public function testExactHitAndMissRefreshAfterCommittedGenerationChanges(): void
    {
        self::assertSame('Old title', $this->exact('Title'));
        self::assertNull($this->exact('Missing'));
        $this->authority->version = 2;
        $this->provider->title = 'New title';
        $this->provider->missing = 'Added title';
        $this->nextRequest();
        self::assertSame('New title', $this->exact('Title'));
        self::assertSame('Added title', $this->exact('Missing'));
        self::assertSame(2, $this->authority->reads);
    }

    public function testOldRequestPublicationCannotReplaceTheNewGeneration(): void
    {
        $old = Context::current();
        $new = null;
        $this->provider->beforeReturn = function () use (&$new, $old): void {
            $this->authority->version = 2;
            $this->provider->title = 'New title';
            Context::enter(new Context());
            $this->nextRequest();
            $new = Context::current();
            self::assertSame('New title', $this->exact('Title'));
            Context::enter($old);
        };
        self::assertSame('Old title', $this->exact('Title'));
        Context::enter($new);
        self::assertSame('New title', $this->exact('Title'));
        self::assertSame(2, $this->provider->reads);
    }

    public function testPublicParseDoesNotFreezeTheTranslatedResultAcrossGenerations(): void
    {
        $request = new class {
            public function getModules(): array { return ['Weline_NamespaceFixture']; }
            public function getModuleName(): string { return 'Weline_NamespaceFixture'; }
        };
        ObjectManager::setInstance(Request::class, $request);
        $word = 'Title';
        self::assertSame('Old title', Parser::parse($word));
        $this->authority->version = 2;
        $this->provider->title = 'New title';
        $this->nextRequest();
        $word = 'Title';
        self::assertSame('New title', Parser::parse($word));
    }

    public function testTransactionReadsDoNotReuseOrPublishPublicTranslations(): void
    {
        self::assertSame('Old title', $this->exact('Title'));
        $query = $this->createStub(\Weline\Framework\Database\Connection\Api\Sql\QueryInterface::class);
        RequestContext::set('framework.database.transaction_states', [
            'main' => new \Weline\Framework\Database\Transaction\TransactionState($query, 1, true),
        ]);
        $this->provider->title = 'Uncommitted title';
        self::assertSame('Uncommitted title', $this->exact('Title'));
        $this->provider->title = 'Further uncommitted title';
        self::assertSame('Further uncommitted title', $this->exact('Title'));
        RequestContext::remove('framework.database.transaction_states');
        self::assertSame('Old title', $this->exact('Title'));
    }

    public function testUnavailableAuthorityDoesNotPublishFallbackOrPreventNextRequestRecovery(): void
    {
        $this->authority->unavailable = true;
        self::assertSame('Old title', $this->exact('Title'));
        $this->provider->title = 'Uncached title';
        self::assertSame('Uncached title', $this->exact('Title'));
        $this->authority->unavailable = false;
        $this->authority->version = 2;
        $this->nextRequest();
        self::assertSame('Uncached title', $this->exact('Title'));
        self::assertSame(2, $this->authority->reads);
    }

    public function testVersionedLocalCacheEvictsOldEntriesWithinItsOwnerLimit(): void
    {
        $cache = ['generation-1|Title' => 'First', 'generation-2|Title' => 'Second', 'generation-3|Title' => 'Latest'];
        $view = &\Weline\Framework\Phrase\DictionaryCacheNamespace::localCache($cache, 2);
        self::assertLessThanOrEqual(2, count($view));
        self::assertSame('Latest', $view['generation-3|Title']);
    }

    public function testEvictedDictionarySnapshotIsNotMistakenForAnEmptyLoadedModule(): void
    {
        $load = new ReflectionMethod(Parser::class, 'loadGlobalDictionaryScopeWords');
        self::assertSame(['Title' => 'Old title'], $load->invoke(null, 'en_US', ['Weline_NamespaceFixture']));
        (new ReflectionProperty(Parser::class, 'workerGlobalDictionaryLocaleWords'))->setValue(null, []);
        self::assertSame(['Title' => 'Old title'], $load->invoke(null, 'en_US', ['Weline_NamespaceFixture']));
    }

    public function testEvictingModuleDictionaryAlsoDropsItsCompletenessMarker(): void
    {
        $load = new ReflectionMethod(Parser::class, 'loadGlobalDictionaryScopeWords');
        $load->invoke(null, 'en_US', ['Weline_A']);
        (new ReflectionProperty(Parser::class, 'workerGlobalDictionaryLocaleWords'))->setValue(null, []);
        $load->invoke(null, 'en_US', ['Weline_A']);
        $load->invoke(null, 'en_US', ['Weline_A', 'Weline_B']);
        self::assertSame([['Weline_A'], ['Weline_A'], ['Weline_B']], $this->provider->batchCalls);
    }

    private function nextRequest(): void
    {
        RequestContext::init();
        State::setRequestLanguageOverride('en_US');
        RequestContext::set('phrase.event_dictionary.state', [
            'locale' => 'en_US', 'active' => false, 'mode' => 'overlay',
            'scope_key' => '', 'words' => [], 'keyed_words' => [],
        ]);
    }

    private function exact(string $word): mixed
    {
        return (new ReflectionMethod(Parser::class, 'loadGlobalDictionaryWord'))->invoke(null, 'en_US', $word);
    }
}

interface DictionaryNamespacePoolFixture extends CachePoolInterface
{
    public function remember(string $key, int $ttl, callable $builder, mixed $options = null): mixed;
}

final class DictionaryNamespaceAuthorityFixture implements NamespaceGenerationInterface
{
    public int $version = 1;
    public int $reads = 0;
    public bool $unavailable = false;
    public function fingerprint(array $namespaces): string
    {
        TestCase::assertNotEmpty($namespaces);
        foreach ($namespaces as $namespace) {
            TestCase::assertContains($namespace, ['global/i18n/content', 'global/i18n/en_US', 'global/i18n/zh_Hans_CN']);
        }
        ++$this->reads;
        if ($this->unavailable) { throw new \RuntimeException('Authority unavailable'); }
        return 'generation-' . $this->version;
    }
    public function bumpMany(array $namespaces): array { throw new \LogicException('Read-only fixture'); }
    public function bump(string $namespace): array { throw new \LogicException('Read-only fixture'); }
}

final class DictionaryNamespaceProviderFixture implements GlobalDictionaryProviderInterface
{
    public string $title = 'Old title';
    public ?string $missing = null;
    public int $reads = 0;
    public ?\Closure $beforeReturn = null;
    public array $batchCalls = [];
    public function word(string $locale, string $word): ?string
    {
        ++$this->reads;
        $value = $word === 'Title' ? $this->title : $this->missing;
        if ($this->beforeReturn !== null) {
            $callback = $this->beforeReturn;
            $this->beforeReturn = null;
            $callback();
        }
        return $value;
    }
    public function words(string $locale, array $modules = []): array
    {
        $this->batchCalls[] = $modules;
        return ['Title' => $this->title];
    }
}
