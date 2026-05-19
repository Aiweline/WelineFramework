<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

/**
 * B-i 阶段：版本化路由表三件套（SET_ROUTE_TABLE / ROUTE_TABLE_ACK / ROUTE_OBSERVATION）契约测试。
 *
 * 测试目标：
 * - 验证消息构造器输出的 NDJSON 可被解析、字段齐全；
 * - 验证 SET_ROUTE_TABLE 的 checksum 在「同输入 → 同输出」上稳定；
 * - 验证 ports 自动规范化（去重 + 升序）；
 * - 验证 ROUTE_TABLE_ACK 三种状态（applied / duplicate / rejected）字段一致；
 * - 验证 ROUTE_OBSERVATION 在仅事件名 + role 的最小载荷下也能编码。
 */
final class ControlMessageRouteTableTest extends TestCase
{
    public function testSetRouteTableEncodesFullPayloadAndStableChecksum(): void
    {
        $workers = [
            ['role' => ControlMessage::ROLE_WORKER, 'slot_id' => 'worker#2', 'lease_id' => 'lease-b', 'generation' => 1, 'port' => 19002, 'state' => 'ready'],
            ['role' => ControlMessage::ROLE_WORKER, 'slot_id' => 'worker#1', 'lease_id' => 'lease-a', 'generation' => 1, 'port' => 19001, 'state' => 'ready'],
        ];
        $raw = ControlMessage::setRouteTable(
            [19002, 19001],
            ControlMessage::ROLE_WORKER,
            $workers,
            42,
            7,
            'trace-route-1'
        );
        $data = $this->decode($raw);

        self::assertSame(ControlMessage::TYPE_SET_ROUTE_TABLE, $data['type'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $data['role'] ?? null);
        self::assertSame([19001, 19002], $data['ports'] ?? null, 'ports 必须升序');
        self::assertSame(42, $data['route_version'] ?? null);
        self::assertSame(7, $data['epoch'] ?? null);
        self::assertSame('trace-route-1', $data['trace_id'] ?? null);
        self::assertNotEmpty($data['checksum'] ?? '', 'SET_ROUTE_TABLE 必须携带 checksum');

        // checksum 在「同输入 → 同输出」上稳定（重新构造一次取同样的字段，应得到相同的 checksum）。
        $raw2 = ControlMessage::setRouteTable(
            [19001, 19002], // 顺序不同
            ControlMessage::ROLE_WORKER,
            $workers,
            42,
            7,
            'trace-route-1'
        );
        $data2 = $this->decode($raw2);
        self::assertSame($data['checksum'], $data2['checksum'], '同 (role, version, epoch, ports, workers) 应得到相同 checksum');
    }

    public function testSetRouteTableChecksumChangesWhenWorkersChange(): void
    {
        $workersA = [
            ['role' => ControlMessage::ROLE_WORKER, 'slot_id' => 'worker#1', 'lease_id' => 'lease-a', 'generation' => 1, 'port' => 19001, 'state' => 'ready'],
        ];
        $workersB = [
            ['role' => ControlMessage::ROLE_WORKER, 'slot_id' => 'worker#1', 'lease_id' => 'lease-a', 'generation' => 2, 'port' => 19001, 'state' => 'ready'],
        ];

        $rawA = ControlMessage::setRouteTable([19001], ControlMessage::ROLE_WORKER, $workersA, 10, 5);
        $rawB = ControlMessage::setRouteTable([19001], ControlMessage::ROLE_WORKER, $workersB, 10, 5);

        $checksumA = $this->decode($rawA)['checksum'] ?? '';
        $checksumB = $this->decode($rawB)['checksum'] ?? '';

        self::assertNotEmpty($checksumA);
        self::assertNotEmpty($checksumB);
        self::assertNotSame($checksumA, $checksumB, 'generation 变化必须导致 checksum 改变');
    }

    public function testComputeRouteTableChecksumIsDeterministic(): void
    {
        $workers = [
            ['role' => ControlMessage::ROLE_WORKER, 'slot_id' => 'worker#1', 'lease_id' => 'lease-a', 'generation' => 1, 'port' => 19001, 'state' => 'ready'],
        ];
        $normalizedWorkers = ControlMessage::normalizeWorkerDescriptors($workers);

        $checksum1 = ControlMessage::computeRouteTableChecksum(
            ControlMessage::ROLE_WORKER,
            42,
            7,
            [19001],
            $normalizedWorkers
        );
        $checksum2 = ControlMessage::computeRouteTableChecksum(
            ControlMessage::ROLE_WORKER,
            42,
            7,
            [19001],
            $normalizedWorkers
        );
        self::assertSame($checksum1, $checksum2);
        self::assertSame(40, \strlen($checksum1), 'sha1 hex 长度应为 40');
    }

    public function testRouteTableAckEncodesAppliedStatus(): void
    {
        $raw = ControlMessage::routeTableAck(
            42,
            \str_repeat('a', 40),
            'applied',
            ControlMessage::ROLE_WORKER,
            7,
            '',
            'trace-ack-applied'
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_ROUTE_TABLE_ACK, $data['type'] ?? null);
        self::assertSame(42, $data['route_version'] ?? null);
        self::assertSame(\str_repeat('a', 40), $data['checksum'] ?? null);
        self::assertSame('applied', $data['status'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $data['role'] ?? null);
        self::assertSame(7, $data['epoch'] ?? null);
        self::assertSame('trace-ack-applied', $data['trace_id'] ?? null);
        self::assertArrayNotHasKey('reason', $data, 'reason 应为空时不写入');
    }

    public function testRouteTableAckEncodesDuplicateAndRejectedStatuses(): void
    {
        $duplicate = $this->decode(ControlMessage::routeTableAck(42, 'csum-1', 'duplicate', ControlMessage::ROLE_WORKER, 7, 'same_version_and_checksum'));
        self::assertSame('duplicate', $duplicate['status'] ?? null);
        self::assertSame('same_version_and_checksum', $duplicate['reason'] ?? null);

        $rejected = $this->decode(ControlMessage::routeTableAck(42, 'csum-2', 'rejected', ControlMessage::ROLE_WORKER, 7, 'checksum_mismatch'));
        self::assertSame('rejected', $rejected['status'] ?? null);
        self::assertSame('checksum_mismatch', $rejected['reason'] ?? null);
    }

    public function testRouteObservationMinimalPayload(): void
    {
        $raw = ControlMessage::routeObservation(
            'identity_mismatch',
            ControlMessage::ROLE_WORKER
        );
        $data = $this->decode($raw);
        self::assertSame(ControlMessage::TYPE_ROUTE_OBSERVATION, $data['type'] ?? null);
        self::assertSame('identity_mismatch', $data['event'] ?? null);
        self::assertSame(ControlMessage::ROLE_WORKER, $data['role'] ?? null);
        self::assertArrayNotHasKey('port', $data);
        self::assertArrayNotHasKey('slot_id', $data);
        self::assertArrayNotHasKey('lease_id', $data);
        self::assertArrayNotHasKey('generation', $data);
        self::assertArrayNotHasKey('detail', $data);
    }

    public function testRouteObservationFullPayload(): void
    {
        $raw = ControlMessage::routeObservation(
            'lease_drift',
            ControlMessage::ROLE_WORKER,
            'worker#3',
            'lease-x',
            5,
            19003,
            'observed_generation=5_expected=4',
            'trace-obs-1'
        );
        $data = $this->decode($raw);
        self::assertSame('lease_drift', $data['event'] ?? null);
        self::assertSame(19003, $data['port'] ?? null);
        self::assertSame('worker#3', $data['slot_id'] ?? null);
        self::assertSame('lease-x', $data['lease_id'] ?? null);
        self::assertSame(5, $data['generation'] ?? null);
        self::assertSame('observed_generation=5_expected=4', $data['detail'] ?? null);
        self::assertSame('trace-obs-1', $data['trace_id'] ?? null);
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
