<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\VisitorPanelBootstrapHtmlService;

final class VisitorPanelBootstrapHtmlServiceTest extends TestCase
{
    public function testFullVisitorPanelBundleIsDeferredUntilTheTabIsActivated(): void
    {
        $service = new class extends VisitorPanelBootstrapHtmlService {
            public function shouldInject(): bool
            {
                return true;
            }
        };

        $html = $service->render();

        self::assertStringContainsString('data-weline-panel-visitor-bootstrap="true"', $html);
        self::assertStringContainsString('data-wesm-bootstrap="1"', $html);
        self::assertStringContainsString('weline_event_sandbox_monitor_v1', $html);
        self::assertStringContainsString('event-sandbox-monitor.js', $html);
        self::assertStringNotContainsString('<script src=', $html);
        self::assertStringContainsString('function loadVisitorPanel()', $html);
        self::assertStringContainsString("id: 'visitor'", $html);
        self::assertStringContainsString('activate: function (context)', $html);
        self::assertStringContainsString('return loadVisitorPanel()', $html);
        self::assertStringContainsString("script.src = panelScriptUrl", $html);
        self::assertStringContainsString('window.__WELINE_PANEL_TAB_QUEUE__', $html);
    }

    public function testDeniedDeveloperAccessDoesNotPublishTheLazyManifest(): void
    {
        $service = new class extends VisitorPanelBootstrapHtmlService {
            public function shouldInject(): bool
            {
                return false;
            }
        };

        self::assertSame('', $service->render());
    }
}
