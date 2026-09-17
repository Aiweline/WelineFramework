<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Model\Winback\WinbackSendLog;
use Weline\Marketing\Service\WinbackCheckoutAbandonRunner;

final class WinbackCheckoutAbandonRunnerTest extends TestCase
{
    public function testSkipsWhenNotYetAbandoned(): void
    {
        $now = strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $runner = new WinbackCheckoutAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleQuotes: static fn (): array => [
                'items' => [[
                    'quote_token' => 'qt-early',
                    'created_at' => '2026-09-14 11:00:00',
                    'email' => 'a@example.com',
                ]],
            ],
            getStaleQuote: static fn (): ?array => null,
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

    public function testSkipsUnreachableWithoutCheckoutUrl(): void
    {
        $now = \strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $runner = new WinbackCheckoutAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleQuotes: static fn (): array => ['items' => [[
                'quote_token' => 'qt-4',
                'created_at' => '2026-09-12 12:00:00',
            ]]],
            getStaleQuote: static fn (): ?array => [
                'quote_token' => 'qt-4',
                'email' => 'c@example.com',
                'reachable' => false,
                'continue_checkout_url' => '',
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
        self::assertSame(1, $stats['skipped']);
        self::assertSame('unreachable', $logs[0]['reason'] ?? '');
        self::assertSame('qt:qt-4', $logs[0]['order_uuid'] ?? '');
    }

    public function testSendsWhenAbandonedAndStillReachable(): void
    {
        $now = strtotime('2026-09-14 12:00:00 UTC');
        $logs = [];
        $mails = [];
        $dto = [
            'quote_token' => 'qt-ok',
            'email' => 'c@example.com',
            'created_at' => '2026-09-12 12:00:00',
            'continue_checkout_url' => 'https://shop.test/checkout?quote_token=qt-ok',
            'reachable' => true,
        ];
        $runner = new WinbackCheckoutAbandonRunner(
            nowTs: static fn (): int => $now,
            listStaleQuotes: static fn (): array => ['items' => [$dto]],
            getStaleQuote: static fn (): ?array => $dto,
            sendMail: static function (array $quote) use (&$mails): array {
                $mails[] = $quote;

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
        self::assertSame('qt-ok', $mails[0]['quote_token']);
        self::assertSame(WinbackSendLog::STATUS_SENT, $logs[0]['status']);
        self::assertSame('qt:qt-ok', $logs[0]['order_uuid']);
    }
}
