<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Service\AnalyticsSnippetService;

class AnalyticsSnippetServiceTest extends TestCase
{
    public function testGetFrontendPixelSnippetsReturnsEnabledSnippetsOnly(): void
    {
        $google = new class() implements PixelProviderInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                return true;
            }

            public function getPixelCode(): string
            {
                return '<script>google</script>';
            }
        };

        $facebook = new class() implements PixelProviderInterface {
            public function isEnabled(): bool
            {
                return false;
            }

            public function sendEvent(string $eventName, array $eventData): bool
            {
                return false;
            }

            public function getPixelCode(): string
            {
                return '<script>facebook</script>';
            }
        };

        $service = new class($google, $facebook) extends AnalyticsSnippetService {
            public function __construct(
                PixelProviderInterface $google,
                PixelProviderInterface $facebook
            ) {
                $this->googleAnalytics = $google;
                $this->facebookPixel = $facebook;
            }
        };

        $snippets = $service->getFrontendPixelSnippets();

        self::assertCount(1, $snippets);
        self::assertSame('google', $snippets[0]['provider']);
        self::assertStringContainsString('google', $snippets[0]['snippet']);
    }
}
