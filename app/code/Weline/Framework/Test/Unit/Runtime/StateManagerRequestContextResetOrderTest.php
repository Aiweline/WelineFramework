<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;

final class StateManagerRequestContextResetOrderTest extends TestCase
{
    public function testRequestContextCleanupRunsAfterScopeBoundResetters(): void
    {
        StateManager::registerFrameworkResets();

        $property = new \ReflectionProperty(StateManager::class, 'resetCallbacks');
        $callbacks = $property->getValue();
        self::assertIsArray($callbacks);

        $order = \array_flip(\array_keys($callbacks));
        self::assertArrayHasKey('request_context', $order);
        self::assertArrayHasKey('template_instance', $order);
        self::assertArrayHasKey('module_request_resetters', $order);
        self::assertArrayHasKey('session_shutdown_queue', $order);

        self::assertGreaterThan($order['template_instance'], $order['request_context']);
        self::assertGreaterThan($order['module_request_resetters'], $order['request_context']);
        self::assertGreaterThan($order['session_shutdown_queue'], $order['request_context']);
    }

    public function testConcurrentFiberCleanupDoesNotOmitRequestScopedTemplate(): void
    {
        self::assertNotContains(
            'template_instance',
            WlsConcurrency::callbackNamesOmittableWithPeerFibers()
        );
    }
}
