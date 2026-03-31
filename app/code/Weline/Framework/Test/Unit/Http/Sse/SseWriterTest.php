<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Sse;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Runtime\SchedulerSystem;

final class SseWriterTest extends TestCase
{
    /** @var array<int, resource> */
    private array $streams = [];

    protected function setUp(): void
    {
        SseContext::reset();
        SchedulerSystem::disableScheduler();
    }

    protected function tearDown(): void
    {
        SseContext::reset();
        SchedulerSystem::disableScheduler();

        foreach ($this->streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
        $this->streams = [];
    }

    public function testSendEventSendsCorrectFormat(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->sendEvent('test', ['message' => 'hello']);

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString('event: test', $content);
        $this->assertStringContainsString('data: {"message":"hello"}', $content);
    }

    public function testSendDataSendsCorrectFormat(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->sendData(['progress' => 50]);

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString('data: {"progress":50}', $content);
    }

    public function testSendCommentSendsColonPrefix(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->sendComment('heartbeat');

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString(': heartbeat', $content);
    }

    public function testSendHeartbeatSendsComment(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->sendHeartbeat();

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString(': heartbeat', $content);
    }

    public function testIsAliveReturnsConnectionStatus(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();

        $this->assertTrue($sse->isAlive());
    }

    public function testYieldAfterSendDoesNotThrowWithoutScheduler(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);
        SchedulerSystem::disableScheduler();

        $sse = new SseWriter();
        $sse->sendEvent('test', ['data' => 'value']);

        // 不应抛出异常
        $sse->yieldAfterSend();

        $this->assertTrue(true);
    }

    public function testSetCooperativeYieldConfiguresBehavior(): void
    {
        $sse = new SseWriter();

        $result = $sse->setCooperativeYield(true, 5);

        $this->assertSame($sse, $result);
    }

    public function testSendEventAndYieldSendsEventAndYields(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);
        SchedulerSystem::disableScheduler();

        $sse = new SseWriter();
        $sse->sendEventAndYield('chunk', ['content' => 'test']);

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString('event: chunk', $content);
        $this->assertStringContainsString('data: {"content":"test"}', $content);
    }

    public function testSendDataAndYieldSendsDataAndYields(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);
        SchedulerSystem::disableScheduler();

        $sse = new SseWriter();
        $sse->sendDataAndYield(['progress' => 100]);

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString('data: {"progress":100}', $content);
    }

    public function testCompleteSendsDoneEventAndCloses(): void
    {
        $stream = $this->createStream();
        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->complete(['result' => 'success']);

        \rewind($stream);
        $content = \stream_get_contents($stream);

        $this->assertStringContainsString('event: done', $content);
        $this->assertStringContainsString('data: {"result":"success"}', $content);
        $this->assertStringContainsString(': stream closed', $content);
    }

    /**
     * @return resource
     */
    private function createStream()
    {
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $this->streams[] = $stream;

        return $stream;
    }
}
