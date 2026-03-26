<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class BackendAnalyticsFormContractTest extends TestCase
{
    public function testBackendAnalyticsFormSupportsEnabledAwareRequiredRulesAndSecretMasking(): void
    {
        $path = BP . 'app/code/WeShop/Analytics/view/backend/templates/analytics/index.phtml';
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('data-analytics-provider-form', $content);
        self::assertStringContainsString('data-analytics-quick-link=', $content);
        self::assertStringContainsString('Configuration Guide', $content);
        self::assertStringContainsString('Missing Required Fields', $content);
        self::assertStringContainsString('data-analytics-required=', $content);
        self::assertStringContainsString('data-analytics-sensitive=', $content);
        self::assertStringContainsString('toggleAnalyticsProviderFields', $content);
        self::assertStringContainsString("input.required = enabled && input.dataset.analyticsRequired === '1';", $content);
        self::assertStringContainsString("Leave blank to keep existing value", $content);
    }
}
