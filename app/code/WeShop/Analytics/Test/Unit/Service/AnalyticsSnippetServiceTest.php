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

    public function testGetFrontendPixelSnippetsBySlotUsesProviderHookSnippets(): void
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

            /**
             * @return array{head:string,body:string,footer:string}
             */
            public function getPixelHookSnippets(): array
            {
                return [
                    'head' => '<script>google-head</script>',
                    'body' => '',
                    'footer' => '',
                ];
            }
        };

        $facebook = new class() implements PixelProviderInterface {
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
                return '<script>facebook</script>';
            }

            /**
             * @return array{head:string,body:string,footer:string}
             */
            public function getPixelHookSnippets(): array
            {
                return [
                    'head' => '<script>facebook-head</script>',
                    'body' => '<noscript>facebook-body</noscript>',
                    'footer' => '<script>facebook-footer</script>',
                ];
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

        $headSnippets = $service->getFrontendPixelSnippetsBySlot('head');
        self::assertCount(2, $headSnippets);
        self::assertStringContainsString('google-head', $headSnippets[0]['snippet']);
        self::assertStringContainsString('facebook-head', $headSnippets[1]['snippet']);

        $bodySnippets = $service->getFrontendPixelSnippetsBySlot('body');
        self::assertCount(1, $bodySnippets);
        self::assertSame('facebook', $bodySnippets[0]['provider']);
        self::assertStringContainsString('facebook-body', $bodySnippets[0]['snippet']);

        $footerSnippets = $service->getFrontendPixelSnippetsBySlot('footer');
        self::assertCount(1, $footerSnippets);
        self::assertSame('facebook', $footerSnippets[0]['provider']);
        self::assertStringContainsString('facebook-footer', $footerSnippets[0]['snippet']);
    }

    public function testGetFrontendPixelSnippetsBySlotFallsBackToLegacySnippetSplit(): void
    {
        $legacyProvider = new class() implements PixelProviderInterface {
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
                return "<script>legacy-head</script>\n<noscript>legacy-body</noscript>";
            }
        };

        $service = new class($legacyProvider) extends AnalyticsSnippetService {
            public function __construct(PixelProviderInterface $google)
            {
                $this->googleAnalytics = $google;
                $this->facebookPixel = null;
            }
        };

        $headSnippets = $service->getFrontendPixelSnippetsBySlot('head');
        self::assertCount(1, $headSnippets);
        self::assertStringContainsString('legacy-head', $headSnippets[0]['snippet']);
        self::assertStringNotContainsString('legacy-body', $headSnippets[0]['snippet']);

        $bodySnippets = $service->getFrontendPixelSnippetsBySlot('body');
        self::assertCount(1, $bodySnippets);
        self::assertStringContainsString('legacy-body', $bodySnippets[0]['snippet']);
    }

    public function testGetFrontendPixelSnippetsBySlotReturnsEmptyForInvalidSlot(): void
    {
        $service = new AnalyticsSnippetService();

        self::assertSame([], $service->getFrontendPixelSnippetsBySlot('unsupported'));
    }
}
