<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutSessionContact;
use Weline\Checkout\Service\ContinueCheckoutUrlBuilder;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;

final class AbandonedCheckoutSignalContractTest extends TestCase
{
    public function testContactExtractsGuestAndAddressEmail(): void
    {
        self::assertSame('g@example.com', CheckoutSessionContact::extractEmail([
            'guest_email' => 'g@example.com',
        ]));
        self::assertSame('a@example.com', CheckoutSessionContact::extractEmail([
            'address' => ['email' => 'a@example.com'],
        ]));
        self::assertSame('', CheckoutSessionContact::extractEmail(['address' => ['city' => 'x']]));
    }

    public function testContinueCheckoutUrlShape(): void
    {
        $b = new ContinueCheckoutUrlBuilder('https://shop.test.weline.com:9555');
        $r = $b->build('tok-abc', 1, 'en_US');
        self::assertTrue($r['reachable']);
        self::assertStringContainsString('/en_US/checkout?', $r['continue_checkout_url']);
        self::assertStringContainsString('quote_token=tok-abc', $r['continue_checkout_url']);
        self::assertStringNotContainsString('resumePayment', $r['continue_checkout_url']);
    }

    public function testQuotedWithEmailGetsExtendedTtl(): void
    {
        $store = new InMemoryCheckoutSessionStore();
        $store->put('t-email', [
            'state' => CheckoutSession::STATE_QUOTED,
            'guest_email' => 'buyer@example.com',
            'cart_fingerprint' => 'fp1',
        ]);
        $row = (new \ReflectionClass($store))->getProperty('rows');
        $row->setAccessible(true);
        /** @var array<string,mixed> $rows */
        $rows = $row->getValue($store);
        $expires = (string)($rows['t-email']['expires_at'] ?? '');
        $ts = strtotime($expires . ' UTC');
        self::assertNotFalse($ts);
        self::assertGreaterThan(time() + 86400, $ts);

        $store2 = new InMemoryCheckoutSessionStore();
        $store2->put('t-no', [
            'state' => CheckoutSession::STATE_QUOTED,
            'cart_fingerprint' => 'fp2',
        ]);
        $rows2 = $row->getValue($store2);
        $expires2 = (string)($rows2['t-no']['expires_at'] ?? '');
        $ts2 = strtotime($expires2 . ' UTC');
        self::assertNotFalse($ts2);
        self::assertLessThan(time() + 7200, $ts2);
    }

    public function testProviderContractSource(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutSignalsQueryProvider.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("return 'checkout_signals'", $src);
        self::assertStringContainsString('list_stale_quotes', $src);
        self::assertStringContainsString('get_stale_quote', $src);
        self::assertStringContainsString('AbandonedCheckoutSignalService', $src);
        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AbandonedCheckoutSignalService.php');
        self::assertStringContainsString("'line_items'", $svc);
        self::assertStringContainsString('lineItemsFromPayload', $svc);
    }
}
