<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

/**
 * 校验 WLS 启动握手协议（建议 2 stage A、建议 3 双 ACK、建议 8 trace_id）相关的
 * ControlMessage 编解码契约。
 */
final class ControlMessageStartupHandshakeTest extends TestCase
{
    public function testAckReadyDefaultsToStageAWhenDispatcherNotConfirmed(): void
    {
        $raw = ControlMessage::ackReady(
            workerId: 7,
            dispatcherConfirmed: false,
            port: 19001,
            msgId: '',
            slotId: 'worker#1',
            leaseId: 'lease-abc',
            generation: 3,
            traceId: 'trace-aaa'
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_ACK_READY, $data['type'] ?? null);
        self::assertSame(7, $data['worker_id'] ?? null);
        self::assertFalse((bool)($data['dispatcher_confirmed'] ?? true));
        self::assertSame(ControlMessage::ACK_READY_STAGE_A, $data['stage'] ?? null);
        self::assertSame('trace-aaa', $data['trace_id'] ?? null);
        self::assertSame('trace-aaa', $data['msg_id'] ?? null);
        self::assertSame(19001, $data['port'] ?? null);
        self::assertSame('worker#1', $data['slot_id'] ?? null);
        self::assertSame('lease-abc', $data['lease_id'] ?? null);
        self::assertSame(3, $data['generation'] ?? null);
    }

    public function testAckReadyDefaultsToStageBWhenDispatcherConfirmed(): void
    {
        $raw = ControlMessage::ackReady(7, true, 19001, '', 'worker#1', 'lease-abc', 3, 'trace-bbb');
        $data = $this->decode($raw);
        self::assertTrue((bool)($data['dispatcher_confirmed'] ?? false));
        self::assertSame(ControlMessage::ACK_READY_STAGE_B, $data['stage'] ?? null);
        self::assertSame('trace-bbb', $data['trace_id'] ?? null);
    }

    public function testAckReadyExplicitStageOverridesDefault(): void
    {
        $raw = ControlMessage::ackReady(
            workerId: 7,
            dispatcherConfirmed: true,
            stage: ControlMessage::ACK_READY_STAGE_A
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::ACK_READY_STAGE_A, $data['stage'] ?? null);
    }

    public function testAckReadyFallsBackToMsgIdWhenTraceIdMissing(): void
    {
        $raw = ControlMessage::ackReady(7, false, 0, 'legacy-msg-001');
        $data = $this->decode($raw);
        self::assertSame('legacy-msg-001', $data['trace_id'] ?? null);
        self::assertSame('legacy-msg-001', $data['msg_id'] ?? null);
    }

    public function testAddWorkerReceivedEncodesPortsAndTraceId(): void
    {
        $raw = ControlMessage::addWorkerReceived([19001, 19002], ControlMessage::ROLE_WORKER, 'trace-add-1');
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_ADD_WORKER_RECEIVED, $data['type'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $data['role'] ?? null);
        self::assertSame([19001, 19002], $data['ports'] ?? null);
        self::assertSame('trace-add-1', $data['trace_id'] ?? null);
        self::assertSame('trace-add-1', $data['msg_id'] ?? null);
    }

    public function testSetWorkerPoolReceivedEncodesPortsAndTraceId(): void
    {
        $raw = ControlMessage::setWorkerPoolReceived([19001], ControlMessage::ROLE_MAINTENANCE, 'trace-set-1');
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_SET_WORKER_POOL_RECEIVED, $data['type'] ?? null);
        self::assertSame(ControlMessage::ROLE_MAINTENANCE, $data['role'] ?? null);
        self::assertSame([19001], $data['ports'] ?? null);
        self::assertSame('trace-set-1', $data['trace_id'] ?? null);
    }

    public function testAddWorkerCarriesTraceIdEndToEnd(): void
    {
        $raw = ControlMessage::addWorker(
            [19001],
            ControlMessage::ROLE_WORKER,
            [],
            0,
            'trace-end-to-end'
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_ADD_WORKER, $data['type'] ?? null);
        self::assertSame([19001], $data['ports'] ?? null);
        self::assertSame('trace-end-to-end', $data['trace_id'] ?? null);
        self::assertSame('trace-end-to-end', $data['msg_id'] ?? null);
    }

    public function testSetWorkerPoolCarriesTraceIdEndToEnd(): void
    {
        $raw = ControlMessage::setWorkerPool(
            [19001, 19002],
            ControlMessage::ROLE_WORKER,
            [],
            0,
            'trace-set-pool'
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_SET_WORKER_POOL, $data['type'] ?? null);
        self::assertSame([19001, 19002], $data['ports'] ?? null);
        self::assertSame('trace-set-pool', $data['trace_id'] ?? null);
    }

    public function testWorkerPoolAckCarriesTraceIdWhenProvided(): void
    {
        $raw = ControlMessage::workerPoolAck(
            19001,
            true,
            ControlMessage::ROLE_WORKER,
            'worker#1',
            'lease-x',
            2,
            'trace-pool-ack'
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_WORKER_POOL_ACK, $data['type'] ?? null);
        self::assertSame('trace-pool-ack', $data['trace_id'] ?? null);
        self::assertSame('trace-pool-ack', $data['msg_id'] ?? null);
        self::assertSame('worker#1', $data['slot_id'] ?? null);
        self::assertSame('lease-x', $data['lease_id'] ?? null);
        self::assertSame(2, $data['generation'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = \json_decode(\trim($raw), true);
        self::assertIsArray($decoded, '消息应可被 json_decode 为数组');
        return $decoded;
    }
}
