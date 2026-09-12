<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EventSandboxMonitorContractTest extends TestCase
{
    public function testSandboxBusAndMonitorContract(): void
    {
        $root = \dirname(__DIR__, 3);
        $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
        $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
        $monitor = (string) \file_get_contents($root . '/view/statics/js/event-sandbox-monitor.js');
        $panel = (string) \file_get_contents($root . '/view/statics/js/weline-panel-visitor.js');
        $bootstrap = (string) \file_get_contents($root . '/Service/VisitorPanelBootstrapHtmlService.php');
        $bodyEnd = (string) \file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');

        self::assertStringContainsString('WelineEventSandbox', $pixel);
        self::assertStringContainsString('normalizeEnvelope', $pixel);
        self::assertStringContainsString('subscribe', $pixel);
        self::assertStringContainsString('ensureLifecycleBridge', $pixel);
        self::assertStringContainsString('__publishSandboxClickPassthrough', $pixel);
        self::assertStringContainsString('__sandboxIsCustomEventHit', $pixel);
        self::assertStringContainsString('sandbox_passthrough', $pixel);
        self::assertStringContainsString('this.emit(detail', $pixel);

        self::assertStringContainsString('WelineEventSandbox', $phtml);
        self::assertStringContainsString('normalizeEnvelope', $phtml);
        self::assertStringContainsString('subscribe', $phtml);
        self::assertStringContainsString('__publishSandboxClickPassthrough', $phtml);
        self::assertStringContainsString('__sandboxIsCustomEventHit', $phtml);

        self::assertStringContainsString('WelineEventSandboxMonitor', $monitor);
        self::assertStringContainsString('data-tone="hit-system"', $monitor);
        self::assertStringContainsString('data-tone="hit-custom"', $monitor);
        self::assertStringContainsString('data-tone="anomaly"', $monitor);
        self::assertStringContainsString("return 'hit-system'", $monitor);
        self::assertStringContainsString("return 'hit-custom'", $monitor);
        self::assertStringContainsString("return 'anomaly'", $monitor);
        self::assertStringContainsString('--weline-color-success', $monitor);
        self::assertStringContainsString('--weline-color-info', $monitor);
        self::assertStringContainsString('--weline-color-primary', $monitor);
        self::assertStringContainsString('绿=系统命中，蓝=自定义命中', $monitor);
        self::assertStringContainsString('全量数据流（含参数）', $monitor);
        self::assertStringContainsString('系统命中', $monitor);
        self::assertStringContainsString('自定义命中', $monitor);
        self::assertStringContainsString('data-wesm-tab="stream"', $monitor);
        self::assertStringContainsString('data-wesm-tab="system"', $monitor);
        self::assertStringContainsString('data-wesm-tab="custom"', $monitor);
        self::assertStringContainsString('data-wesm-tab="previous"', $monitor);
        self::assertStringContainsString('data-wesm-tab="chain"', $monitor);
        self::assertStringContainsString('累积链', $monitor);
        self::assertStringContainsString('上一页', $monitor);
        self::assertStringContainsString('CHAIN_KEY', $monitor);
        self::assertStringContainsString('chainRows', $monitor);
        self::assertStringContainsString('hitAggregate', $monitor);
        self::assertStringContainsString('data-wesm-copy', $monitor);
        self::assertStringContainsString('wesm-kv__key', $monitor);
        self::assertStringContainsString('wesm-kv__val', $monitor);
        self::assertStringContainsString('function copyText', $monitor);
        self::assertStringContainsString('wesm-copy-tip', $monitor);
        self::assertStringContainsString('已复制', $monitor);
        self::assertStringContainsString('wesm-row__bar', $monitor);
        self::assertStringContainsString('点事件栏展开', $monitor);
        self::assertStringContainsString('再点事件栏收起', $monitor);
        self::assertStringContainsString("closest('.wesm-row__params')", $monitor);
        self::assertStringContainsString('weline_event_sandbox_monitor_v1', $monitor);
        self::assertStringContainsString('WelineLifecycleAssistant', $monitor);

        self::assertStringContainsString('relayToAdminSession', $pixel);
        self::assertStringContainsString("sandbox_stream', '1'", $pixel);
        self::assertStringContainsString('/visitor/analytics/event-picker/observe', $pixel);
        self::assertStringNotContainsString("websiteId === '0'", $pixel);
        self::assertStringContainsString('relayToAdminSession', $phtml);
        self::assertStringContainsString("sandbox_stream', '1'", $phtml);
        self::assertStringNotContainsString("websiteId === '0'", $phtml);
        $pickerCtl = (string) \file_get_contents($root . '/Controller/Analytics/EventPicker.php');
        self::assertStringContainsString('function postStream', $pickerCtl);
        self::assertStringContainsString('sandbox_stream', $pickerCtl);
        self::assertStringContainsString('pushAccumulate', $pickerCtl);
        self::assertStringContainsString('if ($websiteId < 0)', $pickerCtl);

        $tvCtl = (string) \file_get_contents($root . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('function getLiveEvents', $tvCtl);
        self::assertStringContainsString('function postClearSessionSandbox', $tvCtl);
        self::assertStringContainsString('listAccumulateSince', $tvCtl);
        self::assertStringContainsString('sandbox_only', $tvCtl);
        self::assertStringContainsString('clearAccumulate', $tvCtl);
        self::assertStringContainsString('cleared_website_ids', $tvCtl);
        $pickerSvc = (string) \file_get_contents($root . '/Service/EventPickerTokenService.php');
        self::assertStringContainsString('SharedBufferStateInterface', $pickerSvc);
        self::assertStringContainsString('visitor.pixel.picker_acc', $pickerSvc);
        self::assertStringContainsString('withBufferMutation', $pickerSvc);
        self::assertStringContainsString('bufferGeneration', $pickerSvc);
        self::assertStringNotContainsString('withBufferLock', $pickerSvc);
        self::assertStringContainsString("'generation' =>", $tvCtl);
        self::assertStringContainsString("'changed' =>", $tvCtl);
        // 沙盒会话流：店面已继电入缓冲，后台只做 since 即时读；禁止服务端长轮询堵 WLS worker。
        if (\preg_match('/function getLiveEvents\(\): string\s*\{(.*?)\n    public function /s', $tvCtl, $m)) {
            $liveBody = (string)($m[1] ?? '');
        } else {
            $liveBody = $tvCtl;
        }
        self::assertStringNotContainsString('\\usleep(', $liveBody);
        self::assertStringNotContainsString('usleep(', $liveBody);
        self::assertStringNotContainsString('while ($waitMs', $liveBody);
        self::assertStringContainsString("'mode' => 'short_poll'", $liveBody);

        self::assertStringContainsString('event-sandbox-monitor.js', $panel);
        self::assertStringContainsString('开启事件监视', $panel);
        self::assertStringContainsString('WelineEventSandbox', $panel);

        self::assertStringContainsString('event-sandbox-monitor.js', $bootstrap);
        self::assertStringContainsString('20260911-event-sandbox-monitor9', $bootstrap);
        self::assertStringContainsString('weline_event_sandbox_monitor_v1', $bootstrap);

        self::assertStringContainsString('event-sandbox-monitor.js', $bodyEnd);
        self::assertStringContainsString('weline_event_sandbox_monitor_v1', $bodyEnd);
    }
}
