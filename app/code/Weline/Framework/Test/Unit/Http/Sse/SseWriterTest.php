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

    public function testIsAliveReturnsFalseAfterPeerDisconnect(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($server)) {
            $this->markTestSkipped('Unable to create local socket server: ' . $errstr . ' (' . $errno . ')');
        }
        $this->streams[] = $server;

        $endpoint = \stream_socket_get_name($server, false);
        $client = @\stream_socket_client('tcp://' . $endpoint, $errno, $errstr, 1.0);
        if (!\is_resource($client)) {
            $this->markTestSkipped('Unable to connect local socket client: ' . $errstr . ' (' . $errno . ')');
        }
        $this->streams[] = $client;

        $accepted = @\stream_socket_accept($server, 1.0);
        if (!\is_resource($accepted)) {
            $this->markTestSkipped('Unable to accept local socket connection.');
        }
        $this->streams[] = $accepted;

        \stream_set_blocking($accepted, false);

        SseContext::setConnection($accepted);
        $sse = new SseWriter();
        $this->assertTrue($sse->isAlive());

        \fclose($client);

        $disconnected = false;
        $deadline = \microtime(true) + 1.0;
        while (\microtime(true) < $deadline) {
            if (!$sse->isAlive()) {
                $disconnected = true;
                break;
            }
            \usleep(20_000);
        }

        $this->assertTrue($disconnected, 'Peer disconnect should be detected without waiting for the next SSE write.');
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
        $stream = \tmpfile();
        self::assertIsResource($stream);
        $meta = \stream_get_meta_data($stream);
        $reader = \fopen((string)($meta['uri'] ?? ''), 'r+');
        self::assertIsResource($reader);
        $this->streams[] = $stream;
        $this->streams[] = $reader;

        SseContext::setConnection($stream);

        $sse = new SseWriter();
        $sse->complete(['result' => 'success']);

        \rewind($reader);
        $content = \stream_get_contents($reader);

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
