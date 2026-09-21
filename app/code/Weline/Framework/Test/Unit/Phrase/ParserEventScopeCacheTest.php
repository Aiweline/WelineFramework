<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

final class ParserEventScopeCacheTest extends TestCase
{
    private PhraseScopeEventsFixture $events;
    private array $originalInstances;
    private mixed $originalObjectManager;

    protected function setUp(): void
    {
        require_once dirname((new \ReflectionClass(EventDictionary::class))->getFileName(), 2) . '/Common/functions.php';
        $manager = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->originalObjectManager = $manager->getValue();
        $manager->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        $this->originalInstances = ObjectManager::getInstances();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        RequestContext::setId('phrase-scope-a');
        Parser::clearWorkerCaches();
        $this->events = new PhraseScopeEventsFixture();
        $request = new PhraseScopeRequestFixture();
        ObjectManager::setInstance(EventsManager::class, $this->events);
        ObjectManager::setInstance(Request::class, $request);
        State::setRequestLanguageOverride('en_US');
        $this->setDictionary('website:one/store:main/channel:web', ['Scoped title' => 'Store A title']);
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        EventDictionary::refresh();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        $property = new ReflectionProperty(ObjectManager::class, 'instances');
        $property->setValue(null, $this->originalInstances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->originalObjectManager);
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
    }

    public function testEventTranslationDoesNotEscapeItsRequestScopeIntoSharedWorkerResults(): void
    {
        $layers = $this->publicLayers();
        self::assertSame('Store A title', $this->translate('Scoped title', $layers));

        RequestContext::setId('phrase-scope-b');
        $this->setDictionary('website:two/store:other/channel:app', ['Scoped title' => 'Store B title']);
        self::assertSame('Store B title', $this->translate('Scoped title', $layers));

        $this->setDictionary('', []);
        self::assertSame('Public title', $this->translate('Scoped title', $layers));
    }

    public function testWarmPublicTranslationStillAllowsARequestOverride(): void
    {
        $layers = $this->publicLayers();
        $this->setDictionary('', []);
        self::assertSame('Public title', $this->translate('Scoped title', $layers));

        $this->setDictionary('website:one/store:main/channel:web', ['Scoped title' => 'Store A title']);
        self::assertSame('Store A title', $this->translate('Scoped title', $layers));

        $this->setDictionary('', []);
        self::assertSame('Public title', $this->translate('Scoped title', $layers));
    }

    public function testExclusiveDictionaryCannotExposeOrReplaceWarmPublicLayers(): void
    {
        $layers = $this->publicLayers();
        $property = new ReflectionProperty(Parser::class, 'workerLayeredWordsCache');
        $property->setValue(null, [$layers['cache_key'] => $layers]);

        $this->setDictionary('exclusive-page', ['Scoped title' => 'Page title'], EventDictionary::MODE_EXCLUSIVE);
        $exclusive = $this->layeredWords();
        self::assertSame([], $exclusive['module_words']);
        self::assertSame([], $exclusive['locale_words']);
        self::assertSame('Page title', $this->translate('Scoped title', $exclusive));

        $this->setDictionary('', []);
        self::assertSame($layers, $this->layeredWords());
        self::assertSame('Public title', $this->translate('Scoped title', $layers));
    }

    public function testExclusiveWordsDoNotMaterializeOrReplaceTheSharedPublicDictionary(): void
    {
        $layers = $this->publicLayers();
        (new ReflectionProperty(Parser::class, 'workerLayeredWordsCache'))->setValue(null, [$layers['cache_key'] => $layers]);
        $this->setDictionary('', []);
        self::assertSame('Public title', Parser::getWords()['Scoped title']);

        $this->setDictionary('exclusive-page', ['Scoped title' => 'Page title'], EventDictionary::MODE_EXCLUSIVE);
        self::assertSame([], Parser::getWords());

        $this->setDictionary('', []);
        self::assertSame('Public title', Parser::getWords()['Scoped title']);
    }

    public function testRequestOverlayCanBeInstalledWithoutCollectEvent(): void
    {
        EventDictionary::refresh();
        $this->setDictionary('website:one/store:main/channel:web', [
            'Scoped title' => 'Collected page title',
        ]);

        self::assertSame('Collected page title', EventDictionary::translate('Scoped title', 'en_US'));
        self::assertTrue(EventDictionary::isActive('en_US'));
    }

    public function testRefreshingAnOverlayInTheSameRequestUpdatesThePublicParseResult(): void
    {
        $layers = $this->publicLayers();
        $property = new ReflectionProperty(Parser::class, 'workerLayeredWordsCache');
        $property->setValue(null, [$layers['cache_key'] => $layers]);

        $phrase = 'Scoped title';
        self::assertSame('Store A title', Parser::parse($phrase));

        EventDictionary::refresh();
        $this->setDictionary('website:one/store:main/channel:web', ['Scoped title' => 'Updated title']);
        $phrase = 'Scoped title';
        self::assertSame('Updated title', Parser::parse($phrase));
    }

    private function setDictionary(string $scope, array $words, string $mode = EventDictionary::MODE_OVERLAY): void
    {
        RequestContext::set('phrase.event_dictionary.state', [
            'active' => $scope !== '',
            'mode' => $mode,
            'scope_key' => $scope,
            'locale' => 'en_US',
            'owners' => $scope === '' ? [] : ['Weline_Test'],
            'words' => $words,
            'keyed_words' => [],
            'layer_hashes' => ['page' => sha1(json_encode($words))],
        ]);
    }

    private function publicLayers(): array
    {
        return [
            'cache_key' => (new ReflectionMethod(Parser::class, 'buildLayeredWordsCacheKey'))->invoke(null, 'en_US', ['Weline_Test']),
            'lang' => 'en_US',
            'modules' => ['Weline_Test'],
            'module_words' => ['Weline_Test' => ['Scoped title' => 'Public title']],
            'locale_words' => ['Shared word' => 'Shared translation'],
            'global_words' => [],
        ];
    }

    private function translate(string $word, array $layers): string
    {
        return (new ReflectionMethod(Parser::class, 'translateWordFromLayers'))->invoke(null, $word, $layers);
    }

    private function layeredWords(): array
    {
        return (new ReflectionMethod(Parser::class, 'getLayeredWords'))->invoke(null, 'en_US', ['Weline_Test']);
    }
}

final class PhraseScopeEventsFixture extends EventsManager
{
    public function __construct()
    {
    }

    public function hasObservers(string $eventName): bool
    {
        return false;
    }

    public function dispatch(string $eventName, mixed &$data = []): static
    {
        return $this;
    }
}

final class PhraseScopeRequestFixture extends Request
{
    public function __construct()
    {
    }

    public function getModules(): array
    {
        return ['Weline_Test'];
    }

    public function getModuleName(): string
    {
        return 'Weline_Test';
    }
}
