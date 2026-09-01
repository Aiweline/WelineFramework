<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\UrlInterface;
use Weline\Payment\Service\PaymentMethodConfigDeepLinkBuilder;

final class PaymentMethodConfigDeepLinkBuilderTest extends TestCase
{
    public function testBuildPaypalDeepLinkIncludesGuideLocateAndScope(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with(
                'weline_systemconfig/backend/config',
                self::callback(static function (array $params): bool {
                    return ($params['q'] ?? '') === 'paypal'
                        && ($params['search'] ?? '') === 'paypal'
                        && ($params['target_scope'] ?? '') === 'default.default.default'
                        && ($params['scope'] ?? '') === 'default.default.default'
                        && ($params['guide_key'] ?? '') === 'adapter:paypal.sandbox.authorize'
                        && ($params['guide_locate'] ?? '') === 'adapter:paypal.sandbox.authorize'
                        && ($params['module'] ?? '') === 'Weline_Payment';
                }),
                false,
            )
            ->willReturn('https://example.test/admin/weline_systemconfig/backend/config?q=paypal');

        $builder = new PaymentMethodConfigDeepLinkBuilder($url);
        $built = $builder->build('paypal', 'global');
        self::assertStringContainsString('weline_systemconfig/backend/config', $built);
    }

    public function testBuildFakeCardIncludesGuideLocateOnEnabledField(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with(
                'weline_systemconfig/backend/config',
                self::callback(static function (array $params): bool {
                    return ($params['q'] ?? '') === 'fake_card'
                        && ($params['search'] ?? '') === 'fake_card'
                        && ($params['guide_key'] ?? '') === 'payment/method/fake_card/enabled'
                        && ($params['guide_locate'] ?? '') === 'payment/method/fake_card/enabled';
                }),
                false,
            )
            ->willReturn('https://example.test/config');

        $builder = new PaymentMethodConfigDeepLinkBuilder($url);
        self::assertNotSame('', $builder->build('fake_card', 'shop.default.default', ['guide' => true]));
    }

    public function testGuideCanBeDisabled(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with(
                'weline_systemconfig/backend/config',
                self::callback(static function (array $params): bool {
                    return !isset($params['guide_locate']) && !isset($params['guide_key']);
                }),
                false,
            )
            ->willReturn('https://example.test/config');

        $builder = new PaymentMethodConfigDeepLinkBuilder($url);
        self::assertNotSame('', $builder->build('fake_card', 'default.default.default', ['guide' => false]));
    }
}
