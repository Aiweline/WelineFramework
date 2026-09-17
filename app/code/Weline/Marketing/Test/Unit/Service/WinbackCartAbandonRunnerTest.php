<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Winback\WinbackSendLog;
use Weline\Marketing\Service\WinbackCartAbandonRunner;

final class WinbackCartAbandonRunnerTest extends TestCase
{
    public function testSkipsWhenNotYetAbandoned(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $mails = [];
        $runner = new WinbackCartAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleCarts: static fn (): array => [
                'items' => [[
                    'cart_key' => 'customer:1',
                    'updated_at' => '2026-09-14 11:00:00',
                    'email' => 'a@example.com',
                    'has_email' => true,
                ]],
            ],
            getStaleCart: static fn (): ?array => null,
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
            writeLog: static function (): void {
            },
            hasQuotedSession: static fn (): bool => false,
        );

        $stats = $runner->run();
        self::assertSame(1, $stats['processed']);
        self::assertSame(0, $stats['sent']);
        self::assertSame(1, $stats['skipped']);
        self::assertSame([], $mails);
    }

    public function testSkipsActiveCheckout(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $dto = [
            'cart_key' => 'customer:9',
            'customer_id' => 9,
            'email' => 'c@example.com',
            'has_email' => true,
            'updated_at' => '2026-09-12 12:00:00',
            'continue_cart_url' => 'https://shop.test/cart',
            'reachable' => true,
        ];
        $runner = new WinbackCartAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleCarts: static fn (): array => ['items' => [$dto]],
            getStaleCart: static fn (): ?array => $dto,
            sendMail: static fn (): array => ['success' => true, 'message' => ''],
            loadCampaigns: static fn (): array => [[
                'id' => 2,
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
            hasQuotedSession: static fn (): bool => true,
        );

        $stats = $runner->run();
        self::assertSame(0, $stats['sent']);
        self::assertSame(1, $stats['skipped']);
        self::assertSame('active_checkout', $logs[0]['reason'] ?? '');
        self::assertSame('cart:customer:9', $logs[0]['order_uuid'] ?? '');
    }

    public function testSendsWithCartPrefixedLogKey(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $dto = [
            'cart_key' => 'customer:3',
            'customer_id' => 3,
            'email' => 'c@example.com',
            'has_email' => true,
            'updated_at' => '2026-09-12 12:00:00',
            'continue_cart_url' => 'https://shop.test/cart',
            'reachable' => true,
            'line_items' => [['name' => '汉服', 'qty' => 1]],
        ];
        $runner = new WinbackCartAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleCarts: static fn (): array => ['items' => [$dto]],
            getStaleCart: static fn (): ?array => $dto,
            sendMail: static function (array $cart) use (&$mails): array {
                $mails[] = $cart;

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
            hasQuotedSession: static fn (): bool => false,
        );

        $stats = $runner->run();
        self::assertSame(1, $stats['sent']);
        self::assertCount(1, $mails);
        self::assertSame(WinbackSendLog::STATUS_SENT, $logs[0]['status'] ?? '');
        self::assertSame('cart:customer:3', $logs[0]['order_uuid'] ?? '');
    }

    public function testUnreachableKeepsCartKeyInLog(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $runner = new WinbackCartAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleCarts: static fn (): array => ['items' => [[
                'cart_key' => 'guest:tok',
                'updated_at' => '2026-09-12 12:00:00',
                'has_email' => false,
            ]]],
            getStaleCart: static fn (): ?array => [
                'cart_key' => 'guest:tok',
                'has_email' => false,
                'email' => '',
                'reachable' => false,
                'continue_cart_url' => '',
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
            hasQuotedSession: static fn (): bool => false,
        );

        $stats = $runner->run();
        self::assertSame(0, $stats['sent']);
        self::assertSame('unreachable', $logs[0]['reason'] ?? '');
        self::assertSame('cart:guest:tok', $logs[0]['order_uuid'] ?? '');
    }
}
