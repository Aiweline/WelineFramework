<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Service\AnalyticsConfigService;

class AnalyticsConfigServiceTest extends TestCase
{
    public function testProviderDefinitionsExposeSensitiveFieldMetadata(): void
    {
        $service = new AnalyticsConfigService();

        $definitions = $service->getProviderDefinitions();
        $googleFields = $definitions[AnalyticsConfigService::PROVIDER_GOOGLE]['fields'] ?? [];
        $apiSecretField = array_values(array_filter(
            $googleFields,
            static fn(array $field): bool => ($field['name'] ?? null) === 'api_secret'
        ));

        self::assertCount(1, $apiSecretField);
        self::assertTrue((bool) ($apiSecretField[0]['sensitive'] ?? false));
        self::assertSame('password', $apiSecretField[0]['input_type'] ?? null);
        self::assertSame('Paste your Measurement Protocol API secret', $apiSecretField[0]['placeholder'] ?? null);
        self::assertSame('Open Google Analytics', $definitions[AnalyticsConfigService::PROVIDER_GOOGLE]['setup']['quick_links'][0]['label'] ?? null);
    }

    public function testGetProviderConfigMergesStoredValuesWithDefaults(): void
    {
        $service = new class() extends AnalyticsConfigService {
            protected array $configStore = [
                'analytics.google' => [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret-123',
                ],
            ];

            protected function readEnvConfig(string $path, mixed $default = []): mixed
            {
                return $this->configStore[$path] ?? $default;
            }
        };

        $config = $service->getProviderConfig(AnalyticsConfigService::PROVIDER_GOOGLE);

        self::assertTrue($config['enabled']);
        self::assertSame('G-TEST123', $config['measurement_id']);
        self::assertSame('secret-123', $config['api_secret']);
        self::assertArrayHasKey('event_mapping', $config);
    }

    public function testSaveProviderConfigNormalizesPayloadAndPersistsByEnvPath(): void
    {
        $service = new class() extends AnalyticsConfigService {
            public array $writes = [];

            protected function writeEnvConfig(string $path, mixed $value): bool
            {
                $this->writes[$path] = $value;

                return true;
            }
        };

        $saved = $service->saveProviderConfig([
            'provider' => AnalyticsConfigService::PROVIDER_FACEBOOK,
            'enabled' => '1',
            'pixel_id' => ' 123456 ',
            'access_token' => ' token-value ',
            'test_event_code' => ' TEST42 ',
        ]);

        self::assertSame('123456', $saved['pixel_id']);
        self::assertSame('token-value', $saved['access_token']);
        self::assertSame('TEST42', $saved['test_event_code']);
        self::assertTrue($saved['enabled']);
        self::assertSame($saved, $service->writes['analytics.facebook'] ?? null);
    }

    public function testSaveProviderConfigKeepsStoredSensitiveValuesWhenBlank(): void
    {
        $service = new class() extends AnalyticsConfigService {
            protected array $configStore = [
                'analytics.google' => [
                    'enabled' => true,
                    'measurement_id' => 'G-TEST123',
                    'api_secret' => 'secret-123',
                ],
            ];
            public array $writes = [];

            protected function readEnvConfig(string $path, mixed $default = []): mixed
            {
                return $this->configStore[$path] ?? $default;
            }

            protected function writeEnvConfig(string $path, mixed $value): bool
            {
                $this->writes[$path] = $value;

                return true;
            }
        };

        $saved = $service->saveProviderConfig([
            'provider' => AnalyticsConfigService::PROVIDER_GOOGLE,
            'enabled' => '1',
            'measurement_id' => ' G-UPDATED ',
            'api_secret' => '',
        ]);

        self::assertSame('G-UPDATED', $saved['measurement_id']);
        self::assertSame('secret-123', $saved['api_secret']);
        self::assertSame('secret-123', $service->writes['analytics.google']['api_secret'] ?? null);
    }

    public function testSaveProviderConfigRejectsMissingRequiredFieldsWhenEnabled(): void
    {
        $service = new AnalyticsConfigService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Measurement ID');

        $service->saveProviderConfig([
            'provider' => AnalyticsConfigService::PROVIDER_GOOGLE,
            'enabled' => true,
            'measurement_id' => '',
            'api_secret' => '',
        ]);
    }

    public function testIsProviderReadyRequiresEnabledProvider(): void
    {
        $service = new AnalyticsConfigService();

        self::assertFalse($service->isProviderReady(AnalyticsConfigService::PROVIDER_GOOGLE, [
            'enabled' => false,
            'measurement_id' => 'G-TEST123',
            'api_secret' => 'secret-123',
        ]));
        self::assertTrue($service->isProviderReady(AnalyticsConfigService::PROVIDER_GOOGLE, [
            'enabled' => true,
            'measurement_id' => 'G-TEST123',
            'api_secret' => 'secret-123',
        ]));
    }

    public function testGetMissingRequiredFieldLabelsReturnsOnlyMissingMandatoryFields(): void
    {
        $service = new AnalyticsConfigService();

        self::assertSame(
            ['API Secret'],
            $service->getMissingRequiredFieldLabels(AnalyticsConfigService::PROVIDER_GOOGLE, [
                'enabled' => true,
                'measurement_id' => 'G-TEST123',
                'api_secret' => '',
            ])
        );
    }

    public function testProviderDefinitionsIncludeTiktokAndBingDisabledByDefault(): void
    {
        $service = new AnalyticsConfigService();
        $definitions = $service->getProviderDefinitions();

        self::assertArrayHasKey(AnalyticsConfigService::PROVIDER_TIKTOK, $definitions);
        self::assertArrayHasKey(AnalyticsConfigService::PROVIDER_BING, $definitions);

        self::assertFalse((bool) ($definitions[AnalyticsConfigService::PROVIDER_TIKTOK]['defaults']['enabled'] ?? true));
        self::assertFalse((bool) ($definitions[AnalyticsConfigService::PROVIDER_BING]['defaults']['enabled'] ?? true));

        self::assertSame(
            'pixel_id',
            $definitions[AnalyticsConfigService::PROVIDER_TIKTOK]['fields'][0]['name'] ?? null
        );
        self::assertSame(
            'access_token',
            $definitions[AnalyticsConfigService::PROVIDER_TIKTOK]['fields'][1]['name'] ?? null
        );
        self::assertSame(
            'uet_tag_id',
            $definitions[AnalyticsConfigService::PROVIDER_BING]['fields'][0]['name'] ?? null
        );
        self::assertSame(
            'api_token',
            $definitions[AnalyticsConfigService::PROVIDER_BING]['fields'][1]['name'] ?? null
        );
    }

    public function testSaveProviderConfigRejectsMissingRequiredTiktokAndBingFieldsWhenEnabled(): void
    {
        $service = new AnalyticsConfigService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Access Token');
        $service->saveProviderConfig([
            'provider' => AnalyticsConfigService::PROVIDER_TIKTOK,
            'enabled' => true,
            'pixel_id' => 'TT-PIXEL-1',
            'access_token' => '',
        ]);
    }

    public function testSaveProviderConfigRejectsMissingRequiredBingApiTokenWhenEnabled(): void
    {
        $service = new AnalyticsConfigService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API Token');
        $service->saveProviderConfig([
            'provider' => AnalyticsConfigService::PROVIDER_BING,
            'enabled' => true,
            'uet_tag_id' => '12345678',
            'api_token' => '',
        ]);
    }
}
