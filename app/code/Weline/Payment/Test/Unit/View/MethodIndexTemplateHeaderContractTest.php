<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 支付方式列表页由布局页头承载标题/面包屑，内容区不得再画重复页头标题。
 */
final class MethodIndexTemplateHeaderContractTest extends TestCase
{
    public function testIndexTemplateDoesNotDuplicatePageHeading(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Method/index.phtml';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);

        self::assertStringNotContainsString('w-backend-page__heading', $html);
        self::assertStringNotContainsString('w-backend-page__title', $html);
        self::assertDoesNotMatchRegularExpression(
            '/w-card__title[^>]*>[\s\S]*支付方式管理/',
            $html,
        );
        self::assertStringContainsString('data-testid="payment-method-management"', $html);
        self::assertStringContainsString('payment-method-sync-providers', $html);
        self::assertStringContainsString('payment-method-config-deeplink', $html);
        self::assertStringContainsString('配置深度链接', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('PaymentMethodConfigDeepLinkBuilder', $html);
        self::assertStringContainsString("api.resource('payment').registerProviders", $html);
        self::assertStringContainsString("api.resource('payment').reorderPaymentMethods", $html);
        self::assertStringContainsString('payment-method-sort-list', $html);
        self::assertStringContainsString('payment-method-sort-handle', $html);
        self::assertStringContainsString('draggable="true"', $html);
        self::assertStringContainsString('ordered_codes', $html);
        self::assertStringContainsString('w:config:embed', $html);
        self::assertStringContainsString('module="embedModule"', $html);
        self::assertStringContainsString('field="embedField"', $html);
        self::assertStringContainsString('field="environmentField"', $html);
        self::assertStringContainsString('payment/method/', $html);
        self::assertStringContainsString('/enabled', $html);
        self::assertStringContainsString('/environment', $html);

        $embedField = dirname(__DIR__, 4) . '/SystemConfig/view/templates/taglib/config-embed-field.phtml';
        self::assertFileExists($embedField);
        $embedHtml = (string)file_get_contents($embedField);
        self::assertStringContainsString('data-on-value="live"', $embedHtml);
        self::assertStringContainsString('data-off-value="sandbox"', $embedHtml);
        self::assertStringContainsString('config-embed-env-toggle', $embedHtml);
        self::assertStringContainsString('w-env-switch__track', $embedHtml);
        self::assertStringContainsString('沙盒', $embedHtml);
        self::assertStringContainsString('生产', $embedHtml);
        self::assertStringContainsString('layout="inline"', $html);
        self::assertStringNotContainsString('payment-method-enabled-toggle', $html);
        self::assertStringNotContainsString("api.resource('system_config').setScopedConfig", $html);
        self::assertStringNotContainsString('setMethodEnabled', $html);
        self::assertStringNotContainsString('can_toggle_enabled', $html);
        self::assertStringNotContainsString('$method->isActive()', $html);
    }
}
