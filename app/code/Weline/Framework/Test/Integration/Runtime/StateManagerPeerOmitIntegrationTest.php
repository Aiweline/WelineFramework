<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Integration\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;

/**
 * 挂起 Fiber 场景下 {@see WlsConcurrency::callbackNamesOmittableWithPeerFibers} 不得包含
 * request_context / sse_context / session_instances，否则 finally 会漏清关键状态。
 */
final class StateManagerPeerOmitIntegrationTest extends TestCase
{
    /**
     * PHPUnit 默认不经 WlsRuntime::bootstrap()，不会自动注册 reset 回调；
     * 本用例依赖与生产一致的回调表（含 request_context、sse_context）。
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        StateManager::registerFrameworkResets();
    }

    protected function tearDown(): void
    {
        SseContext::reset();
        RequestContext::cleanup();
        parent::tearDown();
    }

    public function testOmitListExcludesCriticalCallbacks(): void
    {
        $omit = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        self::assertNotContains('request_context', $omit);
        self::assertNotContains('sse_context', $omit);
        self::assertNotContains('session_instances', $omit);
        self::assertNotContains('session_shutdown_queue', $omit);
        self::assertNotContains('db_connection_cleanup', $omit);
        self::assertNotContains('request_instance', $omit);
    }

    public function testResetWithOmitStillRunsRequestContextCleanup(): void
    {
        RequestContext::init();
        self::assertNotNull(RequestContext::getId());

        StateManager::reset(WlsConcurrency::callbackNamesOmittableWithPeerFibers());

        self::assertNull(RequestContext::getId());
    }

    public function testResetWithOmitStillResetsSseContext(): void
    {
        SseContext::reset();
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        SseContext::setConnection($stream);
        SseContext::enableSse();

        StateManager::reset(WlsConcurrency::callbackNamesOmittableWithPeerFibers());

        self::assertNull(SseContext::getConnection());
        self::assertFalse(SseContext::isSseEnabled());
        \fclose($stream);
    }
}
