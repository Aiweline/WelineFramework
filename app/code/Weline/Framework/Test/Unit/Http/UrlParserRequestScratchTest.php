<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;
use Weline\Framework\Runtime\Runtime;

final class UrlParserRequestScratchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // UrlParserRequestState lives in Url.php (same compile unit).
        \class_exists(Url::class);
        Url::resetParserRequestCaches();
    }

    private function newParserState(): object
    {
        $class = \Weline\Framework\Http\UrlParserRequestState::class;

        return new $class();
    }

    protected function tearDown(): void
    {
        Url::resetParserRequestCaches();
        Runtime::resetModeCache();
        parent::tearDown();
    }

    public function testHardResetClearsUrlSplitAndSiteMatchBags(): void
    {
        Url::$parserUrlCache = ['u' => ['path' => '/x']];
        Url::$splitUrlCache = ['s' => ['path' => '/y']];
        Url::$parserSiteMatchs = ['site' => ['website_id' => 1]];
        Url::$parserCache = ['c' => ['ok' => true]];
        Url::$parserMatchs = ['m' => ['ok' => true]];
        Url::$parserServer = ['HTTP_HOST' => 'keep-or-clear.test'];

        $mainProp = new \ReflectionProperty(Url::class, 'mainParserState');
        $mainProp->setAccessible(true);
        $state = $this->newParserState();
        $state->parserUrlCache = ['state-u' => []];
        $state->splitUrlCache = ['state-s' => []];
        $mainProp->setValue(null, $state);

        Url::resetParserRequestCaches();

        self::assertSame([], Url::$parserUrlCache);
        self::assertSame([], Url::$splitUrlCache);
        self::assertSame([], Url::$parserSiteMatchs);
        self::assertSame([], Url::$parserCache);
        self::assertSame([], Url::$parserMatchs);
        self::assertSame([], Url::$parserServer);
        self::assertSame([], $state->parserUrlCache);
        self::assertSame([], $state->splitUrlCache);
    }

    public function testStoreCappedArrayItemDropsOldestOnOverflow(): void
    {
        $method = new \ReflectionMethod(Url::class, 'storeCappedArrayItem');
        $method->setAccessible(true);
        $bag = [];
        $method->invokeArgs(null, [&$bag, 'a', 1, 2]);
        $method->invokeArgs(null, [&$bag, 'b', 2, 2]);
        $method->invokeArgs(null, [&$bag, 'c', 3, 2]);

        self::assertSame(['b' => 2, 'c' => 3], $bag);
    }

    public function testHardResetOnOneFiberDoesNotClearPeerFiberState(): void
    {
        Runtime::setMode('wls');
        $parserState = new \ReflectionMethod(Url::class, 'parserState');
        $parserState->setAccessible(true);

        $peerCache = null;
        $fiberA = new \Fiber(static function () use ($parserState, &$peerCache): array {
            $state = $parserState->invoke(null);
            $state->parserUrlCache = ['fiber-a' => ['ok' => true]];
            \Fiber::suspend();
            $peerCache = $state->parserUrlCache;

            return $state->parserUrlCache;
        });
        $fiberA->start();

        $fiberB = new \Fiber(static function () use ($parserState): array {
            $state = $parserState->invoke(null);
            $state->parserUrlCache = ['fiber-b' => ['ok' => true]];
            Url::resetParserRequestCaches();

            return $state->parserUrlCache;
        });
        $fiberB->start();
        self::assertTrue($fiberB->isTerminated());
        self::assertSame([], $fiberB->getReturn());

        $fiberA->resume();
        self::assertTrue($fiberA->isTerminated());
        self::assertSame(['fiber-a' => ['ok' => true]], $fiberA->getReturn());
        self::assertSame(['fiber-a' => ['ok' => true]], $peerCache);
    }

    public function testNewRequestHardResetClearsCachesThatSoftRestoreWouldKeep(): void
    {
        \class_exists(Url::class);
        $stateClass = \Weline\Framework\Http\UrlParserRequestState::class;
        $state = new $stateClass();
        $state->parserCache = ['prev-req' => ['server' => ['HTTP_HOST' => 'old.test']]];
        $state->parserUrlCache = ['prev-url' => []];

        $mainProp = new \ReflectionProperty(Url::class, 'mainParserState');
        $mainProp->setAccessible(true);
        $mainProp->setValue(null, $state);

        // Soft restore (same-request resume) would keep these:
        Url::projectWlsFiberParserScratchAfterRestore();
        self::assertSame(['prev-req' => ['server' => ['HTTP_HOST' => 'old.test']]], Url::$parserCache);

        // New-request enter MUST hard-clear (worker enter / Event enter contract).
        Url::resetParserRequestCaches();
        self::assertSame([], Url::$parserCache);
        self::assertSame([], Url::$parserUrlCache);
        self::assertSame([], $state->parserCache);
        self::assertSame([], $state->parserUrlCache);
    }

    public function testResetWebsiteParserSitesClearsAllFiberRequestScratch(): void
    {
        Runtime::setMode('wls');
        $parserState = new \ReflectionMethod(Url::class, 'parserState');
        $parserState->setAccessible(true);

        $fiber = new \Fiber(static function () use ($parserState): void {
            $state = $parserState->invoke(null);
            $state->parserCache = ['stale' => ['x' => 1]];
            $state->parserUrlCache = ['stale-url' => []];
            \Fiber::suspend();
        });
        $fiber->start();

        Url::resetWebsiteParserSites();

        $fiberProp = new \ReflectionProperty(Url::class, 'fiberParserStates');
        $fiberProp->setAccessible(true);
        $fiberStates = $fiberProp->getValue(null);
        self::assertInstanceOf(\WeakMap::class, $fiberStates);
        $found = false;
        foreach ($fiberStates as $state) {
            self::assertInstanceOf(\Weline\Framework\Http\UrlParserRequestState::class, $state);
            self::assertSame([], $state->parserCache);
            self::assertSame([], $state->parserUrlCache);
            $found = true;
        }
        self::assertTrue($found, 'expected fiber parser state to remain addressable in WeakMap');
        $fiber->resume();
    }
}
