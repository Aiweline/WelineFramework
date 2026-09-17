<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Winback\WinbackSendLog;
use Weline\Marketing\Service\WinbackUnpaidOrderRunner;

final class WinbackUnpaidMultiStepIncentiveTest extends TestCase
{
    public function testStep2IssuesCouponAndUsesPrefixedLogKey(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $issued = [];
        $sentSteps = [1 => true];
        $sentAt = [1 => '2026-09-13 12:00:00'];
        $dto = [
            'order_uuid' => 'ord-ok',
            'customer_id' => 7,
            'email' => 'c@example.com',
            'created_at' => '2026-09-10 12:00:00',
            'continue_pay_url' => 'https://shop.test/pay',
            'reachable' => true,
        ];

        $runner = new WinbackUnpaidOrderRunner(
            nowTs: static fn (): int => $now,
            listUnpaid: static fn (): array => ['items' => [$dto]],
            getUnpaid: static fn (): ?array => $dto,
            sendMail: static function (array $order) use (&$mails): array {
                $mails[] = $order;

                return ['success' => true, 'message' => ''];
            },
            loadCampaigns: static fn (): array => [[
                'id' => 3,
                'abandon_after_hours' => 1,
                'max_steps' => 2,
                'step_interval_hours' => 24,
                'cooldown_hours' => 0,
                'website_id' => 0,
                'incentive_rule_id' => 42,
                'segment_id' => 0,
            ]],
            hasStepLog: static fn (int $cid, string $uuid, int $step): bool => !empty($sentSteps[$step]),
            lastSentAt: static fn (int $cid, string $uuid, int $step): ?string => $sentAt[$step] ?? null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
            matchesSegment: static fn (): bool => true,
            issueCoupon: static function (int $ruleId, array $ctx) use (&$issued): array {
                $issued[] = ['rule' => $ruleId, 'ctx' => $ctx];

                return ['coupon_code' => 'WINBACK42', 'coupon_id' => 1];
            },
        );

        $stats = $runner->run();
        self::assertSame(1, $stats['sent']);
        self::assertSame(2, (int)($logs[0]['step'] ?? 0));
        self::assertSame('order:ord-ok', $logs[0]['order_uuid'] ?? '');
        self::assertSame('WINBACK42', $logs[0]['coupon_code'] ?? '');
        self::assertSame('WINBACK42', $mails[0]['coupon_code'] ?? '');
        self::assertSame(42, $issued[0]['rule'] ?? 0);
        self::assertSame(WinbackSendLog::STATUS_SENT, $logs[0]['status'] ?? '');
    }

    public function testSkipsSegmentMismatch(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $dto = [
            'order_uuid' => 'ord-seg',
            'customer_id' => 9,
            'email' => 'c@example.com',
            'created_at' => '2026-09-10 12:00:00',
            'continue_pay_url' => 'https://shop.test/pay',
            'reachable' => true,
        ];
        $runner = new WinbackUnpaidOrderRunner(
            nowTs: static fn (): int => $now,
            listUnpaid: static fn (): array => ['items' => [$dto]],
            getUnpaid: static fn (): ?array => $dto,
            sendMail: static fn (): array => ['success' => true, 'message' => ''],
            loadCampaigns: static fn (): array => [[
                'id' => 5,
                'abandon_after_hours' => 1,
                'max_steps' => 1,
                'step_interval_hours' => 24,
                'cooldown_hours' => 0,
                'website_id' => 0,
                'segment_id' => 99,
            ]],
            hasStepLog: static fn (): bool => false,
            lastSentAt: static fn (): ?string => null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
            matchesSegment: static fn (): bool => false,
        );

        $stats = $runner->run();
        self::assertSame(0, $stats['sent']);
        self::assertSame('segment_mismatch', $logs[0]['reason'] ?? '');
    }
}
