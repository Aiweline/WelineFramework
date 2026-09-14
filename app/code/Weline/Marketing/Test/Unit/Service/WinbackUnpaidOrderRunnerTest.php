<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Winback\WinbackSendLog;
use Weline\Marketing\Service\WinbackUnpaidOrderRunner;

final class WinbackUnpaidOrderRunnerTest extends TestCase
{
    public function testSkipsWhenNotYetAbandoned(): void
    {
        $now = strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $runner = new WinbackUnpaidOrderRunner(
            nowTs: static fn (): int => $now,
            listUnpaid: static fn (): array => [
                'items' => [[
                    'order_uuid' => 'ord-early',
                    'created_at' => '2026-09-14 11:00:00',
                    'email' => 'a@example.com',
                ]],
            ],
            getUnpaid: static fn (): ?array => null,
            sendMail: static function (array $dto) use (&$mails): array {
                $mails[] = $dto;

                return ['success' => true, 'message' => ''];
            },
            loadCampaigns: static fn (): array => [[
                'id' => 1,
                'abandon_after_hours' => 24,
                'max_steps' => 1,
                'cooldown_hours' => 0,
                'website_id' => 0,
            ]],
            hasStepLog: static fn (): bool => false,
            lastSentAt: static fn (): ?string => null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
        );

        $stats = $runner->run();
        self::assertSame(1, $stats['processed']);
        self::assertSame(0, $stats['sent']);
        self::assertSame(1, $stats['skipped']);
        self::assertSame([], $mails);
        self::assertSame([], $logs);
    }

    public function testSkipsWhenAlreadyPaidOnRecheck(): void
    {
        $now = strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $runner = new WinbackUnpaidOrderRunner(
            nowTs: static fn (): int => $now,
            listUnpaid: static fn (): array => [
                'items' => [[
                    'order_uuid' => 'ord-paid',
                    'created_at' => '2026-09-12 12:00:00',
                    'email' => 'b@example.com',
                ]],
            ],
            getUnpaid: static fn (): ?array => null,
            sendMail: static fn (): array => ['success' => true, 'message' => ''],
            loadCampaigns: static fn (): array => [[
                'id' => 9,
                'abandon_after_hours' => 1,
                'max_steps' => 1,
                'cooldown_hours' => 0,
                'website_id' => 0,
            ]],
            hasStepLog: static fn (): bool => false,
            lastSentAt: static fn (): ?string => null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
        );

        $stats = $runner->run();
        self::assertSame(0, $stats['sent']);
        self::assertSame(1, $stats['skipped']);
        self::assertCount(1, $logs);
        self::assertSame(WinbackSendLog::STATUS_SKIPPED, $logs[0]['status']);
        self::assertSame('already_paid_or_ineligible', $logs[0]['reason']);
    }

    public function testSendsWhenAbandonedAndStillUnpaid(): void
    {
        $now = strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $dto = [
            'order_uuid' => 'ord-ok',
            'order_number' => 'N1',
            'email' => 'c@example.com',
            'created_at' => '2026-09-12 12:00:00',
            'continue_pay_url' => 'https://shop.test/checkout/success?checkout_token=t',
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
                'abandon_after_hours' => 24,
                'max_steps' => 1,
                'cooldown_hours' => 0,
                'website_id' => 0,
            ]],
            hasStepLog: static fn (): bool => false,
            lastSentAt: static fn (): ?string => null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
        );

        $stats = $runner->run();
        self::assertSame(1, $stats['sent']);
        self::assertCount(1, $mails);
        self::assertSame('ord-ok', $mails[0]['order_uuid']);
        self::assertSame(WinbackSendLog::STATUS_SENT, $logs[0]['status']);
    }

    public function testSkipsUnreachableWithoutPayUrl(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $runner = new WinbackUnpaidOrderRunner(
            nowTs: static fn (): int => $now,
            listUnpaid: static fn (): array => ['items' => [[
                'order_uuid' => 'ord-4',
                'created_at' => '2026-09-12 12:00:00',
            ]]],
            getUnpaid: static fn (): ?array => [
                'order_uuid' => 'ord-4',
                'email' => 'c@example.com',
                'reachable' => false,
                'continue_pay_url' => '',
            ],
            sendMail: static fn (): array => ['success' => true, 'message' => ''],
            loadCampaigns: static fn (): array => [[
                'id' => 4,
                'abandon_after_hours' => 1,
                'max_steps' => 1,
                'cooldown_hours' => 0,
                'website_id' => 0,
            ]],
            hasStepLog: static fn (): bool => false,
            lastSentAt: static fn (): ?string => null,
            writeLog: static function (array $row) use (&$logs): void {
                $logs[] = $row;
            },
        );

        $stats = $runner->run();
        self::assertSame(0, $stats['sent']);
        self::assertSame('unreachable', $logs[0]['reason'] ?? '');
    }
}
