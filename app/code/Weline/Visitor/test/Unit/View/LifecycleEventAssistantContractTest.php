<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class LifecycleEventAssistantContractTest extends TestCase
{
    public function testAssistantAndPanelToggleContract(): void
    {
        $root = \dirname(__DIR__, 3);
        $assistant = (string) \file_get_contents($root . '/view/statics/js/lifecycle-event-assistant.js');
        $panel = (string) \file_get_contents($root . '/view/statics/js/weline-panel-visitor.js');
        $bootstrap = (string) \file_get_contents($root . '/Service/VisitorPanelBootstrapHtmlService.php');

        self::assertStringContainsString('WelineLifecycleAssistant', $assistant);
        self::assertStringContainsString('weline:payment:anomaly', $assistant);
        self::assertStringContainsString('weline:checkout:anomaly', $assistant);
        self::assertStringContainsString('weline:dev-tool-panel:collapsed', $assistant);
        self::assertStringContainsString('sessionStorage', $assistant);
        self::assertStringContainsString('weline_lifecycle_assistant', $assistant);
        self::assertStringContainsString('HISTORY_KEY', $assistant);
        self::assertStringContainsString('weline_lifecycle_assistant_history_v1', $assistant);
        self::assertStringContainsString('hydratePageWindow', $assistant);
        self::assertStringContainsString('本页已触发事件', $assistant);
        self::assertStringContainsString('上一页事件', $assistant);
        self::assertStringContainsString('仅保留本页与上一页', $assistant);
        self::assertStringContainsString('POSITION_KEY', $assistant);
        self::assertStringContainsString('weline_lifecycle_assistant_pos_v1', $assistant);
        self::assertStringContainsString('data-wla-action="close"', $assistant);
        self::assertStringContainsString('bindChrome', $assistant);
        self::assertStringContainsString('writePosition', $assistant);
        self::assertStringContainsString('clearPosition', $assistant);
        self::assertStringContainsString('lifecycle-assistant', $panel);
        self::assertStringContainsString('loadLifecycleAssistant', $panel);
        self::assertStringContainsString('lifecycle-event-assistant.js', $panel);
        self::assertStringContainsString('20260910-lifecycle-assistant5', $bootstrap);
        self::assertStringContainsString('data-wla-bootstrap', $bootstrap);
        self::assertStringContainsString('weline_lifecycle_assistant_v1', $bootstrap);
        self::assertStringContainsString('lifecycle-event-assistant.js', $bootstrap);

        $bodyEnd = (string) \file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        self::assertStringContainsString('data-wla-bootstrap', $bodyEnd);
        self::assertStringContainsString('weline_lifecycle_assistant_v1', $bodyEnd);
        self::assertStringContainsString('lifecycle-event-assistant.js', $bodyEnd);
    }
}
