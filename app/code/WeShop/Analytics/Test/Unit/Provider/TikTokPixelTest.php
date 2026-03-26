<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Provider\TikTokPixel;

class TikTokPixelTest extends TestCase
{
    public function testGetPixelCodeReturnsSnippetWhenEnabled(): void
    {
        $provider = new TikTokPixel('TT-PIXEL-1', 'token', true);

        $snippet = $provider->getPixelCode();
        self::assertStringContainsString('TikTok Pixel Code', $snippet);
        self::assertStringContainsString("ttq.load('TT-PIXEL-1')", $snippet);
    }

    public function testGetPixelHookSnippetsReturnsHeadOnly(): void
    {
        $provider = new TikTokPixel('TT-PIXEL-1', 'token', true);
        $snippets = $provider->getPixelHookSnippets();

        self::assertStringContainsString("ttq.load('TT-PIXEL-1')", $snippets['head']);
        self::assertSame('', $snippets['body']);
        self::assertSame('', $snippets['footer']);
    }

    public function testGetPixelCodeReturnsEmptyWhenDisabled(): void
    {
        $provider = new TikTokPixel('TT-PIXEL-1', 'token', false);

        self::assertSame('', $provider->getPixelCode());
    }

    public function testSendEventBuildsServerPayloadWhenTokenConfigured(): void
    {
        $provider = new class('TT-PIXEL-1', 'token-1', true, 'TEST-CODE') extends TikTokPixel {
            public ?string $capturedUrl = null;
            public ?array $capturedPayload = null;
            public ?array $capturedHeaders = null;

            protected function postJson(string $url, array $payload, array $headers = []): bool
            {
                $this->capturedUrl = $url;
                $this->capturedPayload = $payload;
                $this->capturedHeaders = $headers;

                return true;
            }
        };

        $result = $provider->sendEvent('purchase', [
            'event_id' => 'order-1001',
            'event_source_url' => 'https://example.com/checkout/success',
            'currency' => 'USD',
            'value' => 199.5,
            'email' => 'buyer@example.com',
            'items' => [
                ['product_id' => 101, 'name' => 'Bag', 'price' => 199.5, 'qty' => 1],
            ],
        ]);

        self::assertTrue($result);
        self::assertStringContainsString('/open_api/v1.3/pixel/track/', (string) $provider->capturedUrl);
        self::assertContains('Access-Token: token-1', $provider->capturedHeaders ?? []);
        self::assertSame('CompletePayment', $provider->capturedPayload['event'] ?? null);
        self::assertSame('order-1001', $provider->capturedPayload['event_id'] ?? null);
        self::assertSame('TEST-CODE', $provider->capturedPayload['test_event_code'] ?? null);
    }

    public function testSendEventReturnsFalseWithoutToken(): void
    {
        $provider = new TikTokPixel('TT-PIXEL-1', '', true);

        self::assertFalse($provider->sendEvent('purchase', ['value' => 10]));
    }
}
