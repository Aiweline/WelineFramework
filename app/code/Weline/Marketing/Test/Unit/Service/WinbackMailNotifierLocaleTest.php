<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\WinbackMailNotifier;

final class WinbackMailNotifierLocaleTest extends TestCase
{
    public function testSnapshotLocaleOverridesWebsiteFallback(): void
    {
        $notifier = new WinbackMailNotifier();
        // website_id=0 → built-in zh_Hans_CN fallback, then snapshot wins
        $ctx = $notifier->resolveScopeLocale(0, 'en_US');
        self::assertSame('en_US', $ctx['locale']);
    }

    public function testEmptyOrDefaultSnapshotKeepsFallback(): void
    {
        $notifier = new WinbackMailNotifier();
        self::assertSame('zh_Hans_CN', $notifier->resolveScopeLocale(0, '')['locale']);
        self::assertSame('zh_Hans_CN', $notifier->resolveScopeLocale(0, 'default')['locale']);
        self::assertSame('zh_Hans_CN', $notifier->resolveScopeLocale(0, 'DEFAULT')['locale']);
    }

    public function testNotifyPassesSnapshotLocaleIntoSendPayload(): void
    {
        $captured = [];
        $notifier = new class($captured) extends WinbackMailNotifier {
            /** @var array<string, mixed> */
            public array $capturedSend;

            /** @param array<string, mixed> $captured */
            public function __construct(array &$captured)
            {
                $this->capturedSend = &$captured;
            }

            public function notifyUnpaidReminder(array $orderDto, array $extraVars = []): array
            {
                $email = trim((string)($orderDto['email'] ?? ''));
                if ($email === '') {
                    return ['success' => false, 'message' => 'no_customer_email', 'skipped' => true];
                }
                $scopeCtx = $this->resolveScopeLocale(
                    (int)($orderDto['website_id'] ?? 0),
                    (string)($orderDto['locale'] ?? ''),
                );
                $this->capturedSend = [
                    'channel' => self::CHANNEL_UNPAID_ORDER_REMINDER,
                    'to' => $email,
                    'locale' => $scopeCtx['locale'],
                ];

                return ['success' => true, 'message' => ''];
            }
        };

        $result = $notifier->notifyUnpaidReminder([
            'email' => 'buyer@example.com',
            'website_id' => 0,
            'locale' => 'en_US',
            'order_uuid' => 'ord-1',
        ]);

        self::assertTrue($result['success']);
        self::assertSame('en_US', $notifier->capturedSend['locale']);
        self::assertSame('Weline_Marketing::unpaid_order_reminder', $notifier->capturedSend['channel']);
    }
}
