<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Provider\FacebookPixel;

class FacebookPixelTest extends TestCase
{
    public function testSendEventBuildsConversionsApiPayload(): void
    {
        $provider = new class('123456', 'token', true, 'TESTCODE') extends FacebookPixel {
            public ?string $capturedUrl = null;
            public ?array $capturedPayload = null;

            protected function postJson(string $url, array $payload): bool
            {
                $this->capturedUrl = $url;
                $this->capturedPayload = $payload;
                return true;
            }
        };

        $result = $provider->sendEvent('add_to_cart', [
            'currency' => 'USD',
            'value' => 24.9,
            'email' => 'buyer@example.com',
            'customer_id' => 18,
            'items' => [
                ['product_id' => 100, 'qty' => 2, 'price' => 12.45],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertStringContainsString('/123456/events', (string) $provider->capturedUrl);
        $this->assertSame('AddToCart', $provider->capturedPayload['data'][0]['event_name'] ?? null);
        $this->assertSame('TESTCODE', $provider->capturedPayload['test_event_code'] ?? null);
        $this->assertArrayHasKey('em', $provider->capturedPayload['data'][0]['user_data'] ?? []);
    }

    public function testGetPixelCodeReturnsSnippetWhenEnabled(): void
    {
        $provider = new FacebookPixel('123456', 'token', true);

        $this->assertStringContainsString("fbq('init', '123456')", $provider->getPixelCode());
    }
}
