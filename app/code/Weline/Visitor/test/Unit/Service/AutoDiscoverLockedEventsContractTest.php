<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 自动发现事件：本地 LS + 后台锁定注册契约。
 */
final class AutoDiscoverLockedEventsContractTest extends TestCase
{
    public function test_annotation_service_exposes_origin_and_lock(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/EventAnnotationService.php');
        self::assertStringContainsString("ORIGIN_AUTO_DISCOVERED = 'auto_discovered'", $src);
        self::assertStringContainsString("ORIGIN_MANUAL = 'manual'", $src);
        self::assertStringContainsString("'deletable'", $src);
        self::assertStringContainsString('function isLockedMeta', $src);
        self::assertStringContainsString('function isDeletable', $src);
        self::assertStringContainsString('promote', $src);
        self::assertStringContainsString('origin_label', $src);
        self::assertStringContainsString('自动发现', $src);
        self::assertStringContainsString('function removeMeta', $src);
    }

    public function test_test_residual_purge_helpers(): void
    {
        $disc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        self::assertStringContainsString('function isTestResidualAutoDiscoverName', $disc);
        self::assertStringContainsString('function purgeTestResiduals', $disc);
        self::assertStringContainsString('(?:browser_)?auto_disc_\\d+', $disc);
        self::assertStringContainsString('e2e_(?:auto_disc|sf_auto)_\\d+', $disc);
        self::assertStringContainsString('removeMeta', $disc);
        self::assertStringContainsString("'promote' => 1", $disc);

        $svc = new \Weline\Visitor\Service\DiscoveredEventService(
            new \Weline\Visitor\Service\EventDictionaryService()
        );
        self::assertTrue($svc->isTestResidualAutoDiscoverName('browser_auto_disc_1789115306348'));
        self::assertTrue($svc->isTestResidualAutoDiscoverName('browser_auto_disc_1789115306348_direct'));
        self::assertTrue($svc->isTestResidualAutoDiscoverName('auto_disc_1789115085'));
        self::assertTrue($svc->isTestResidualAutoDiscoverName('e2e_auto_disc_1789117910212'));
        self::assertTrue($svc->isTestResidualAutoDiscoverName('e2e_sf_auto_1789117845068'));
        self::assertFalse($svc->isTestResidualAutoDiscoverName('banner_title'));
        self::assertFalse($svc->isTestResidualAutoDiscoverName('buy_now'));
        self::assertFalse($svc->isTestResidualAutoDiscoverName('add_to_wishlist'));
    }

    public function test_discovered_remove_and_controller_refuse_locked(): void
    {
        $disc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        $tv = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('isLockedMeta', $disc);
        self::assertStringContainsString('locked_auto_discovered', $tv);
        self::assertStringContainsString('isDeletable', $tv);
        self::assertStringContainsString('403', $tv);
    }

    public function test_register_auto_sandbox_gate_and_idempotent(): void
    {
        $picker = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');
        self::assertStringContainsString('function postRegisterAuto', $picker);
        self::assertStringContainsString('function wantsRegisterAuto', $picker);
        self::assertStringContainsString('register_auto', $picker);
        self::assertStringContainsString('sandbox_required', $picker);
        self::assertStringContainsString("sandbox !== '1'", $picker);
        self::assertStringContainsString("'already' => true", $picker);
        self::assertStringContainsString('ORIGIN_AUTO_DISCOVERED', $picker);
        self::assertStringContainsString('invalidateAfterMutation', $picker);
        self::assertStringContainsString('rate_limited', $picker);
        self::assertStringContainsString('generic_name', $picker);
        self::assertStringContainsString('dictionary', $picker);
        self::assertStringContainsString("'event_name'", $picker);
        self::assertStringContainsString('private, no-store', $picker);
    }

    public function test_admin_ui_badge_and_delete_disabled(): void
    {
        $admin = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml');
        self::assertStringContainsString('tv-badge-auto-discovered', $admin);
        self::assertStringContainsString('自动发现', $admin);
        self::assertStringContainsString('data-locked="1"', $admin);
        self::assertStringContainsString('不可删', $admin);
        self::assertStringContainsString('locked_auto_discovered', $admin);
    }

    public function test_pixel_local_cache_and_sandbox_only_register(): void
    {
        $pixel = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        $phtml = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml');
        foreach ([$pixel, $phtml] as $src) {
            self::assertStringContainsString('weline-visitor-custom-events:', $src);
            self::assertStringContainsString('pending_register', $src);
            self::assertStringContainsString('__syncCustomEventsLocalCacheFromRuntime', $src);
            self::assertStringContainsString('__maybeRegisterAutoDiscoveredEvent', $src);
            self::assertStringContainsString('__isSandboxMonitorActiveForAutoRegister', $src);
            self::assertStringContainsString('register_auto', $src);
            self::assertStringContainsString("sandbox', '1'", $src);
            self::assertStringNotContainsString('setInterval', $src);
            // 不走 stream/observe 写池
            self::assertStringNotContainsString('sandbox_stream=1', $src);
        }
    }
}
