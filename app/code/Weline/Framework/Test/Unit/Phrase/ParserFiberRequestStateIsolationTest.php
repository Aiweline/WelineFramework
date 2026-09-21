<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Phrase\ParserRequestState;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\Runtime;

/**
 * Concurrent WLS Fibers must not share Phrase request bags (title×locale bleed).
 */
final class ParserFiberRequestStateIsolationTest extends TestCase
{
    private array $instances;
    private mixed $manager;

    protected function setUp(): void
    {
        $this->instances = ObjectManager::getInstances();
        $property = new ReflectionProperty(ObjectManager::class, 'instance');
        $this->manager = $property->getValue();
        $property->setValue(null, (new \ReflectionClass(ObjectManager::class))->newInstanceWithoutConstructor());
        Runtime::setMode('wls');
        Parser::clearWorkerCaches();
        ObjectManager::setInstance(NamespaceGenerationInterface::class, new class implements NamespaceGenerationInterface {
            public function fingerprint(array $namespaces): string
            {
                return 'fiber-isolation-gen';
            }

            public function bumpMany(array $namespaces): array
            {
                throw new \LogicException('Readonly fixture');
            }

            public function bump(string $namespace): array
            {
                throw new \LogicException('Readonly fixture');
            }
        });
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::cleanup();
        State::resetRequestPathLocalizationCache();
        Runtime::resetModeCache();
        (new ReflectionProperty(ObjectManager::class, 'instances'))->setValue(null, $this->instances);
        (new ReflectionProperty(ObjectManager::class, 'instance'))->setValue(null, $this->manager);
        while (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testPeerFibersKeepDistinctCurrentLayeredWordsAcrossSuspend(): void
    {
        $getLayers = new ReflectionMethod(Parser::class, 'getCurrentLayeredWords');
        $getLayers->setAccessible(true);

        $zhFiber = new \Fiber(function () use ($getLayers): array {
            $this->bootRequest('zh-req', 'zh_Hans_CN');
            $before = $getLayers->invoke(null);
            \Fiber::suspend('zh-ready');
            $after = $getLayers->invoke(null);

            return [
                $before['lang'] ?? null,
                $after['lang'] ?? null,
                $this->requestState()->layeredWordsSignature,
                \spl_object_id($this->requestState()),
            ];
        });
        $enFiber = new \Fiber(function () use ($getLayers): array {
            $this->bootRequest('en-req', 'en_US');
            $before = $getLayers->invoke(null);
            \Fiber::suspend('en-ready');
            $after = $getLayers->invoke(null);

            return [
                $before['lang'] ?? null,
                $after['lang'] ?? null,
                $this->requestState()->layeredWordsSignature,
                \spl_object_id($this->requestState()),
            ];
        });

        self::assertSame('zh-ready', $zhFiber->start());
        self::assertSame('en-ready', $enFiber->start());
        // Resume zh first while en still holds its bag — classic static-bag race window.
        $zhFiber->resume();
        $enFiber->resume();

        [$zhBefore, $zhAfter, $zhSig, $zhStateId] = $zhFiber->getReturn();
        [$enBefore, $enAfter, $enSig, $enStateId] = $enFiber->getReturn();

        self::assertSame('zh_Hans_CN', $zhBefore);
        self::assertSame('zh_Hans_CN', $zhAfter);
        self::assertSame('en_US', $enBefore);
        self::assertSame('en_US', $enAfter);
        self::assertNotSame($zhSig, $enSig);
        self::assertNotSame($zhStateId, $enStateId);
        self::assertStringContainsString('zh_Hans_CN', (string)$zhSig);
        self::assertStringContainsString('en_US', (string)$enSig);
    }

    public function testRequestStateLivesInRequestContextNotProcessStatic(): void
    {
        $this->bootRequest('ctx-a', 'fr_FR');
        $stateA = $this->requestState();
        $stateA->layeredWordsSignature = 'fr-marker';
        self::assertInstanceOf(ParserRequestState::class, RequestContext::get('phrase.parser.request_state'));

        $fiber = new \Fiber(function (): string {
            $this->bootRequest('ctx-b', 'en_US');
            $stateB = $this->requestState();
            $stateB->layeredWordsSignature = 'en-marker';
            \Fiber::suspend();

            return (string)$stateB->layeredWordsSignature;
        });
        $fiber->start();
        self::assertSame('fr-marker', $this->requestState()->layeredWordsSignature);
        $fiber->resume();
        self::assertSame('en-marker', $fiber->getReturn());
        self::assertSame('fr-marker', $this->requestState()->layeredWordsSignature);
    }

    private function bootRequest(string $id, string $locale): void
    {
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId($id);
        State::setRequestLanguageOverride($locale);
        (new ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null)->enabledCache = false;
    }

    private function requestState(): ParserRequestState
    {
        $method = new ReflectionMethod(Parser::class, 'requestState');
        $method->setAccessible(true);
        $state = $method->invoke(null);
        self::assertInstanceOf(ParserRequestState::class, $state);

        return $state;
    }
}
