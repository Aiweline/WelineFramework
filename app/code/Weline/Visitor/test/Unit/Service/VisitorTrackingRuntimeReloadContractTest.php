<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 事件编辑 → 清缓存 + 版本标记 → 沙盒见变自行重载。
 */
final class VisitorTrackingRuntimeReloadContractTest extends TestCase
{
    public function test_visitor_tracking_config_exposes_revision_and_custom_events(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/VisitorTrackingConfig.php');
        self::assertStringContainsString('KEY_RUNTIME_REVISION', $src);
        self::assertStringContainsString('function invalidateAfterMutation', $src);
        self::assertStringContainsString('clearStorefrontFullPageCaches', $src);
        self::assertStringContainsString("'customEvents'", $src);
        self::assertStringContainsString("'configRevision'", $src);
        self::assertStringContainsString('function resolveStorageScopeForRuntime', $src);
        self::assertStringContainsString('default.default.default', $src);
        self::assertStringContainsString("'storageScope'", $src);
    }

    public function test_mutation_services_bump_runtime_revision(): void
    {
        $ann = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/EventAnnotationService.php');
        $disc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        $chain = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/EventChainService.php');
        $tv = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        $picker = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');

        self::assertStringContainsString('invalidateAfterMutation', $ann);
        self::assertStringContainsString('invalidateAfterMutation', $disc);
        self::assertStringContainsString('invalidateAfterMutation', $chain);
        self::assertStringContainsString('withRuntimeReloadMeta', $tv);
        self::assertStringContainsString('currentRuntimeRevisionMeta', $picker);
        self::assertStringContainsString('bumpRuntimeRevisionMeta', $picker);
        self::assertStringContainsString('只要录入（persist）成功就发新版本', $picker);
        self::assertStringContainsString('凡店面提交响应都带当前 config_revision', $picker);
    }

    public function test_sandbox_notes_revision_then_reloads_runtime(): void
    {
        $pixel = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        $phtml = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml');
        $admin = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml');

        self::assertStringContainsString('noteConfigRevision', $pixel);
        self::assertStringContainsString('__noteServerConfigRevision', $pixel);
        self::assertStringContainsString('rebuildVendorFrames', $pixel);
        self::assertStringContainsString('runtime_config=1', $pixel);
        self::assertStringContainsString('__matchCustomEventsByConditions', $pixel);
        self::assertStringContainsString('__trackMatchedCustomEvents', $pixel);
        self::assertStringContainsString('__extractParamsForCustomEvent', $pixel);
        self::assertStringContainsString('__normalizeParamMappings', $pixel);
        self::assertStringContainsString('__resolveParamTemplate', $pixel);
        self::assertStringContainsString('__resolveNestedValue', $pixel);
        self::assertStringContainsString('__sandboxCompactParams', $pixel);
        self::assertStringContainsString('event_hit: false', $pixel);
        self::assertStringContainsString('param_mappings', $pixel);
        self::assertStringContainsString('extract_params', $pixel);
        self::assertStringContainsString('storage_scope=', $pixel);
        self::assertStringContainsString('custom_match', $pixel);
        self::assertStringNotContainsString('__probeVisitorTrackingRevision', $pixel);
        self::assertStringNotContainsString('setInterval', $pixel);

        self::assertStringContainsString('noteConfigRevision', $phtml);
        self::assertStringContainsString('__noteServerConfigRevision', $phtml);
        self::assertStringContainsString('__matchCustomEventsByConditions', $phtml);
        self::assertStringContainsString('__trackMatchedCustomEvents', $phtml);
        self::assertStringContainsString('__extractParamsForCustomEvent', $phtml);
        self::assertStringContainsString('__normalizeParamMappings', $phtml);
        self::assertStringContainsString('__resolveParamTemplate', $phtml);
        self::assertStringNotContainsString('__probeVisitorTrackingRevision', $phtml);
        self::assertStringNotContainsString('setInterval', $phtml);

        self::assertStringContainsString('broadcastTrackingReload', $admin);
        self::assertStringContainsString('weline-visitor-tracking-config', $admin);
    }
}
