<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestResetException;
use Weline\Framework\Runtime\StateManager;

final class StateManagerFailurePropagationTest extends TestCase
{
    private const FAILURE_CALLBACK = '__state_manager_failure_probe__';
    private const AFTER_CALLBACK = '__state_manager_after_failure_probe__';

    protected function tearDown(): void
    {
        StateManager::unregisterResetCallback(self::FAILURE_CALLBACK);
        StateManager::unregisterResetCallback(self::AFTER_CALLBACK);
        RequestContext::cleanup();
        Context::leave();
        HeaderCollector::reset();
        parent::tearDown();
    }

    public function testResetCompletesRemainingCleanupBeforeReportingFailure(): void
    {
        StateManager::registerFrameworkResets();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::set('probe', 'stale');
        HeaderCollector::getInstance()->setHeader('X-Reset-Probe', 'stale');
        $requestObject = new \stdClass();
        ObjectManager::setInstance(self::class, $requestObject);

        $afterFailureCalls = 0;
        StateManager::registerResetCallback(
            self::FAILURE_CALLBACK,
            static fn () => throw new \RuntimeException('state reset probe failed'),
        );
        StateManager::registerResetCallback(self::AFTER_CALLBACK, static function () use (&$afterFailureCalls): void {
            ++$afterFailureCalls;
        });

        try {
            StateManager::reset();
            self::fail('Expected StateManager to report its reset failure.');
        } catch (RequestResetException $exception) {
            self::assertStringContainsString('callback:' . self::FAILURE_CALLBACK, $exception->getMessage());
        }

        self::assertSame(1, $afterFailureCalls);
        self::assertFalse(RequestContext::has('probe'));
        self::assertArrayNotHasKey(self::class, ObjectManager::getInstances());
        self::assertNull(HeaderCollector::getInstance()->getHeader('X-Reset-Probe'));
    }
}
