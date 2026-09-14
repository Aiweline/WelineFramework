<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ScheduleWindowFormTemplateContractTest extends TestCase
{
    public function testCampaignFormDocumentsWebsiteTimezoneUtcStorage(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/campaign/form.phtml');
        self::assertIsString($src);
        self::assertStringContainsString('datetime-local', $src);
        self::assertStringContainsString('按站点时区', $src);
        self::assertStringContainsString('utcSqlToLocalInput', $src);
        self::assertStringContainsString('data-testid="marketing-campaign-start"', $src);
    }

    public function testRuleAndCouponFormsExposeWindows(): void
    {
        $rule = file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/rule/form.phtml');
        $coupon = file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/coupon/form.phtml');
        self::assertIsString($rule);
        self::assertIsString($coupon);
        self::assertStringContainsString('marketing-rule-start', $rule);
        self::assertStringContainsString('按站点时区', $rule);
        self::assertStringContainsString('marketing-coupon-start', $coupon);
        self::assertStringContainsString('按站点时区', $coupon);
    }
}
