<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Interface\PaymentConfigTesterInterface;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Service\PaymentConfigValidationService;
use Weline\Payment\Service\PaymentScopeConfigService;

class PaymentConfigValidationServiceTest extends TestCase
{
    public function testEnvironmentSpecificCredentialsSatisfyBaseRequiredFields(): void
    {
        $service = new PaymentConfigValidationService();

        $result = $service->validateMethod(
            [
                'code' => 'stripe_checkout',
                'required_config' => ['secret_key'],
                'flow' => 'offline',
            ],
            [
                'environment' => 'sandbox',
                'sandbox_secret_key' => 'sk_test_123',
            ],
            null,
            ['environment' => 'sandbox']
        );

        self::assertTrue($result['success']);
        self::assertSame(PaymentMethodConfig::TEST_STATUS_PASSED, $result['status']);
    }

    public function testRemoteProviderWithoutTesterCannotPassEnableGate(): void
    {
        $service = new PaymentConfigValidationService();

        $result = $service->validateMethod(
            [
                'code' => 'remote_gateway',
                'required_config' => ['api_key'],
                'flow' => 'redirect',
            ],
            ['api_key' => 'key_123'],
            null,
            []
        );

        self::assertFalse($result['success']);
        self::assertSame(PaymentMethodConfig::TEST_STATUS_FAILED, $result['status']);
    }

    public function testProviderTesterResultIsUsed(): void
    {
        $service = new PaymentConfigValidationService();
        $provider = new class() implements PaymentConfigTesterInterface {
            public function testConfig(array $config, array $context = []): array
            {
                return [
                    'success' => ($config['api_key'] ?? '') === 'valid',
                    'message' => 'tester called',
                    'details' => ['scope_key' => (string) ($context['scope_key'] ?? '')],
                ];
            }
        };

        $result = $service->validateMethod(
            [
                'code' => 'remote_gateway',
                'required_config' => ['api_key'],
                'flow' => 'redirect',
            ],
            ['api_key' => 'valid'],
            $provider,
            ['scope_key' => 'website:1']
        );

        self::assertTrue($result['success']);
        self::assertSame('tester called', $result['message']);
        self::assertSame('website:1', $result['details']['scope_key']);
    }

    public function testScopeServiceNormalizesScopeKeys(): void
    {
        $scope = (new PaymentScopeConfigService())->resolveScope([
            'scope' => 'site_01.demo',
            'environment' => 'live',
        ]);

        self::assertSame('site_01.demo.default', $scope['scope']);
        self::assertSame('live', $scope['environment']);
        self::assertSame('site_01.demo.default', $scope['scope_key']);
    }
}
