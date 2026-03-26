<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Service\AnalyticsAdminPageDataService;
use WeShop\Analytics\Service\AnalyticsConfigService;
use WeShop\Analytics\Service\AnalyticsSnippetService;

class AnalyticsAdminPageDataServiceTest extends TestCase
{
    public function testGetPageDataBuildsProviderCardsAndEditingProvider(): void
    {
        $configService = $this->createMock(AnalyticsConfigService::class);
        $snippetService = $this->createMock(AnalyticsSnippetService::class);

        $definitions = [
            AnalyticsConfigService::PROVIDER_GOOGLE => [
                'code' => AnalyticsConfigService::PROVIDER_GOOGLE,
                'label' => 'Google Analytics',
                'description' => 'GA4 measurement protocol events.',
                'fields' => [
                    ['name' => 'measurement_id', 'label' => 'Measurement ID', 'required' => true],
                ],
                'setup' => [
                    'integration_method' => 'GA4 Measurement Protocol',
                    'summary' => 'Google summary',
                    'steps' => ['Step 1'],
                    'quick_links' => [
                        ['label' => 'Open Google Analytics', 'url' => 'https://analytics.google.com/analytics/web/'],
                    ],
                ],
            ],
            AnalyticsConfigService::PROVIDER_FACEBOOK => [
                'code' => AnalyticsConfigService::PROVIDER_FACEBOOK,
                'label' => 'Facebook Pixel',
                'description' => 'Meta pixel and conversion API events.',
                'fields' => [
                    ['name' => 'pixel_id', 'label' => 'Pixel ID', 'required' => true],
                ],
            ],
        ];

        $configService->expects(self::once())
            ->method('getProviderDefinitions')
            ->willReturn($definitions);

        $configService->expects(self::exactly(3))
            ->method('getProviderConfig')
            ->willReturnMap([
                [AnalyticsConfigService::PROVIDER_GOOGLE, [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret',
                ]],
                [AnalyticsConfigService::PROVIDER_FACEBOOK, [
                    'enabled' => false,
                    'pixel_id' => '',
                    'access_token' => '',
                    'test_event_code' => '',
                ]],
                [AnalyticsConfigService::PROVIDER_GOOGLE, [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret',
                ]],
            ]);

        $configService->expects(self::once())
            ->method('getTrackedEvents')
            ->willReturn([
                ['code' => 'purchase', 'label' => 'Purchase'],
                ['code' => 'login', 'label' => 'Login'],
            ]);

        $configService->expects(self::exactly(3))
            ->method('isProviderReady')
            ->willReturnMap([
                [AnalyticsConfigService::PROVIDER_GOOGLE, [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret',
                ], true],
                [AnalyticsConfigService::PROVIDER_FACEBOOK, [
                    'enabled' => false,
                    'pixel_id' => '',
                    'access_token' => '',
                    'test_event_code' => '',
                ], false],
                [AnalyticsConfigService::PROVIDER_GOOGLE, [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret',
                ], true],
            ]);

        $configService->expects(self::once())
            ->method('getMissingRequiredFieldLabels')
            ->with(AnalyticsConfigService::PROVIDER_GOOGLE, [
                'enabled' => true,
                'measurement_id' => 'G-TEST123',
                'api_secret' => 'secret',
            ])
            ->willReturn([]);

        $snippetService->expects(self::exactly(2))
            ->method('getFrontendPixelSnippets')
            ->willReturn([
                ['provider' => AnalyticsConfigService::PROVIDER_GOOGLE, 'snippet' => '<script>ga</script>'],
            ]);

        $service = new AnalyticsAdminPageDataService($configService, $snippetService);
        $result = $service->getPageData(AnalyticsConfigService::PROVIDER_GOOGLE);

        self::assertSame(2, $result['summary']['total_providers']);
        self::assertSame(1, $result['summary']['enabled_providers']);
        self::assertSame(1, $result['summary']['ready_providers']);
        self::assertSame('Google Analytics', $result['editingProvider']['label']);
        self::assertSame('G-TEST123', $result['editingProvider']['config']['measurement_id']);
        self::assertSame('GA4 Measurement Protocol', $result['editingProvider']['setup']['integration_method']);
        self::assertTrue($result['editingProvider']['enabled']);
        self::assertTrue($result['editingProvider']['ready']);
        self::assertCount(2, $result['providers']);
        self::assertCount(2, $result['trackedEvents']);
        self::assertCount(1, $result['snippetPreview']);
    }
}
