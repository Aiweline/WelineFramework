<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * 地区策略：EEA/GB/CH 与未知地区强制默认拒绝；其余地区才看全局开关。
 */
final class VisitorConsentRegionPolicyTest extends TestCase
{
    public function testForcedRegions(): void
    {
        $deny = ['EEA', 'GB', 'CH'];
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('DE', $deny), 'EU 成员国');
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('IS', $deny), 'EEA 非 EU');
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('GB', $deny));
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('CH', $deny));
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('', $deny), '无法判定 fail-closed');
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('XX', $deny), '未知码 fail-closed');
        self::assertFalse(VisitorTrackingConfig::isForcedDefaultDenyRegion('US', $deny));
        self::assertFalse(VisitorTrackingConfig::isForcedDefaultDenyRegion('JP', $deny));
    }

    public function testCustomRegionListOverridesEeaGroup(): void
    {
        $deny = ['US'];
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('US', $deny));
        self::assertFalse(VisitorTrackingConfig::isForcedDefaultDenyRegion('DE', $deny), '移除 EEA 组后不再强制');
        self::assertTrue(VisitorTrackingConfig::isForcedDefaultDenyRegion('', $deny), '未知仍 fail-closed');
    }

    public function testEffectiveDecisionCombinesRegionAndGlobalSwitch(): void
    {
        $deny = ['EEA', 'GB', 'CH'];
        // 全局关闭默认拒绝时：非强制地区可放行，强制地区与未知地区仍拒绝。
        self::assertFalse(VisitorTrackingConfig::effectiveDefaultDeny('US', $deny, false));
        self::assertTrue(VisitorTrackingConfig::effectiveDefaultDeny('DE', $deny, false));
        self::assertTrue(VisitorTrackingConfig::effectiveDefaultDeny('GB', $deny, false));
        self::assertTrue(VisitorTrackingConfig::effectiveDefaultDeny('', $deny, false));
        // 全局开启时：所有地区都拒绝。
        self::assertTrue(VisitorTrackingConfig::effectiveDefaultDeny('US', $deny, true));
    }

    public function testFrontendUsesRegionAwareDecision(): void
    {
        foreach ([
            'app/code/Weline/Visitor/view/statics/js/pixel.js',
            'app/code/Weline/Visitor/view/taglib/js/pixel.phtml',
        ] as $relative) {
            $source = (string)\file_get_contents(BP . $relative);
            self::assertStringContainsString('__visitorRegionHint', $source, $relative);
            self::assertStringContainsString('__visitorConsentDenyRegions', $source, $relative);
            self::assertStringContainsString('effectiveDefaultDeny ? grantedNow : true', $source, $relative);
            self::assertStringContainsString('weline_country', $source, $relative);
        }
    }

    public function testBackendConfigExposesRegionFieldsWithWarning(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Visitor/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml',
        );
        self::assertStringContainsString('visitor/tracking/consent_default_deny"', $source);
        self::assertStringContainsString('visitor/tracking/consent_default_deny_regions"', $source);
        self::assertStringContainsString('EEA', $source);
        self::assertStringContainsString('必须事先同意', $source);
    }
}
