<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserCallbackTokenService;

final class PaymentBrowserCallbackTokenServiceTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $service = new PaymentBrowserCallbackTokenService();
        $token = $service->encode('paypal', 'TXN-1001', 'default.default.default', 3600);
        self::assertStringStartsWith('v1.', $token);

        $decoded = $service->decode($token);
        self::assertNotNull($decoded);
        self::assertSame('paypal', $decoded['method_code']);
        self::assertSame('TXN-1001', $decoded['transaction_no']);
        self::assertSame('default.default.default', $decoded['target_scope']);
    }

    public function testTamperedTokenRejected(): void
    {
        $service = new PaymentBrowserCallbackTokenService();
        $token = $service->encode('paypal', 'TXN-1001', 'default.default.default', 3600);
        $tampered = substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');
        self::assertNull($service->decode($tampered));
    }
}
