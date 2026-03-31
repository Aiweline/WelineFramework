<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Provider\BingAds;

class BingAdsTest extends TestCase
{
    public function testGetPixelCodeReturnsSnippetWhenEnabled(): void
    {
        $provider = new BingAds('12345678', 'token', true);

        $snippet = $provider->getPixelCode();
        self::assertStringContainsString('Microsoft Advertising UET', $snippet);
        self::assertStringContainsString('ti: "12345678"', $snippet);
    }

    public function testGetPixelHookSnippetsReturnsHeadOnly(): void
    {
        $provider = new BingAds('12345678', 'token', true);
        $snippets = $provider->getPixelHookSnippets();

        self::assertStringContainsString('https://bat.bing.com/bat.js', $snippets['head']);
        self::assertSame('', $snippets['body']);
        self::assertSame('', $snippets['footer']);
    }

    public function testGetPixelCodeSanitizesUetTagIdInSnippet(): void
    {
        $provider = new BingAds("1234\");alert(1);//", 'token', true);
        $snippet = $provider->getPixelCode();

        self::assertStringContainsString('ti: "1234alert1"', $snippet);
        self::assertStringNotContainsString('alert(', $snippet);
    }

    public function testGetPixelCodeReturnsEmptyWhenDisabled(): void
    {
        $provider = new BingAds('12345678', 'token', false);

        self::assertSame('', $provider->getPixelCode());
    }

    public function testSendEventBuildsConversionsApiPayloadWhenTokenConfigured(): void
    {
        $provider = new class('12345678', 'token-1', true) extends BingAds {
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
            'event_id' => 'purchase-1001',
            'event_source_url' => 'https://example.com/checkout/success',
            'transaction_id' => 'WS1001',
            'currency' => 'USD',
            'value' => 88.6,
            'items' => [
                ['product_id' => 11, 'name' => 'Bag', 'price' => 88.6, 'qty' => 1],
            ],
        ]);

        self::assertTrue($result);
        self::assertStringContainsString('/v1/12345678/events', (string) $provider->capturedUrl);
        self::assertContains('Authorization: Bearer token-1', $provider->capturedHeaders ?? []);
        self::assertSame('custom', $provider->capturedPayload['data'][0]['eventType'] ?? null);
        self::assertSame('purchase', $provider->capturedPayload['data'][0]['eventName'] ?? null);
        self::assertSame('WS1001', $provider->capturedPayload['data'][0]['customData']['transactionId'] ?? null);
    }

    public function testSendEventReturnsFalseWithoutToken(): void
    {
        $provider = new BingAds('12345678', '', true);

        self::assertFalse($provider->sendEvent('purchase', ['value' => 10]));
    }
}
