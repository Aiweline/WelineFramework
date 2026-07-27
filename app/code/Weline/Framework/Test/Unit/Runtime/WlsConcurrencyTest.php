<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;

final class WlsConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        parent::tearDown();
    }

    public function testCountZeroWhenNoProvider(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }

    public function testProviderValueIsReturnedAndClamped(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => 2);
        self::assertSame(2, WlsConcurrency::getOtherSuspendedRequestFiberCount());

        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => -1);
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }

    public function testProviderThrowableYieldsZero(): void
    {
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static function (): int {
            throw new \RuntimeException('fail');
        });
        self::assertSame(0, WlsConcurrency::getOtherSuspendedRequestFiberCount());
    }

    public function testPeerFiberOmitListContainsOnlyRegisteredEntryBaselineCallbacks(): void
    {
        StateManager::registerFrameworkResets();

        $callbacks = (new ReflectionProperty(StateManager::class, 'resetCallbacks'))->getValue();
        self::assertIsArray($callbacks);

        $omit = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        self::assertSame([
            'request_scoped_objects',
            'state_instance',
            'router_core_instance',
            'controller_instances',
            'model_instances',
            'observer_instances',
            'message_manager_request_state',
            'events_manager_observer_cache',
            'view_hook_runtime_cache',
            'process_url_cache_static',
        ], $omit);

        foreach ($omit as $callbackName) {
            self::assertArrayHasKey(
                $callbackName,
                $callbacks,
                "Peer-Fiber omit callback '{$callbackName}' is not registered by StateManager."
            );
        }
    }
}
