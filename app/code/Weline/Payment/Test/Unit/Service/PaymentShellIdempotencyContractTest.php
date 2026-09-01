<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Documents and contracts shell idempotency boundaries (webhook vs browser return).
 */
final class PaymentShellIdempotencyContractTest extends TestCase
{
    public function testOauthCompleteKeepsCompletedResultForReplay(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/PayPalOAuthService.php');
        self::assertStringContainsString('completed_result', $src);
        self::assertStringContainsString('markStateCompleted', $src);
        self::assertStringContainsString('peekState', $src);
    }

    public function testWebhookDocCrossLinksShellIdempotency(): void
    {
        $webhook = (string) file_get_contents(dirname(__DIR__, 3) . '/doc/webhook.md');
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/doc/payment-shell.md');
        self::assertStringContainsString('payment-shell.md', $webhook);
        self::assertStringContainsString('provider_event_id', $shell);
        self::assertStringContainsString('idempotency', strtolower($shell));
    }

    public function testShellDocRequiresCheckoutIdempotencyKey(): void
    {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/doc/payment-shell.md');
        self::assertStringContainsString('idempotency key', strtolower($shell));
        self::assertStringContainsString('浏览器 return', $shell);
    }
}
