<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Provider\GoogleAnalytics;

class GoogleAnalyticsTest extends TestCase
{
    public function testSendEventBuildsMeasurementProtocolPayload(): void
    {
        $provider = new class('G-TEST123', 'secret', true) extends GoogleAnalytics {
            public ?string $capturedUrl = null;
            public ?array $capturedPayload = null;

            protected function postJson(string $url, array $payload): bool
            {
                $this->capturedUrl = $url;
                $this->capturedPayload = $payload;
                return true;
            }
        };

        $result = $provider->sendEvent('purchase', [
            'client_id' => 'cid-1',
            'transaction_id' => 'WS1001',
            'currency' => 'USD',
            'value' => 88.6,
            'items' => [
                ['product_id' => 11, 'name' => 'Bag', 'price' => 88.6, 'qty' => 1],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertStringContainsString('measurement_id=G-TEST123', (string) $provider->capturedUrl);
        $this->assertSame('purchase', $provider->capturedPayload['events'][0]['name'] ?? null);
        $this->assertSame('WS1001', $provider->capturedPayload['events'][0]['params']['transaction_id'] ?? null);
    }

    public function testGetPixelCodeReturnsEmptyStringWhenDisabled(): void
    {
        $provider = new GoogleAnalytics('G-TEST123', 'secret', false);

        $this->assertSame('', $provider->getPixelCode());
    }

    public function testGetPixelHookSnippetsReturnsHeadOnly(): void
    {
        $provider = new GoogleAnalytics('G-TEST123', 'secret', true);
        $snippets = $provider->getPixelHookSnippets();

        self::assertStringContainsString("id=G-TEST123", $snippets['head']);
        self::assertSame('', $snippets['body']);
        self::assertSame('', $snippets['footer']);
    }

    public function testGetPixelCodeSanitizesMeasurementIdInSnippet(): void
    {
        $provider = new GoogleAnalytics("G-123');alert(1);//", 'secret', true);
        $snippet = $provider->getPixelCode();

        self::assertStringContainsString("gtag('config', 'G-123alert1')", $snippet);
        self::assertStringNotContainsString('alert(', $snippet);
    }
}
