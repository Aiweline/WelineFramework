<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Taglib\SseTerminal;

class SseTerminalTaglibTest extends TestCore
{
    public function testSseTerminalMarkupIncludesReconnectAwareTransportStateHandling(): void
    {
        $callback = SseTerminal::callback();

        $html = $callback(
            'single',
            [],
            [''],
            [
                'id' => 'demo-terminal',
                'title' => 'Demo',
                'show-start-toggle' => 'false',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('getTransportState', $html);
        $this->assertStringContainsString('setStatus: setStatus', $html);
        $this->assertStringContainsString('keepStatus', $html);
        $this->assertStringContainsString('manualStopRequested', $html);
        $this->assertStringContainsString('terminalCompleted', $html);
        $this->assertStringContainsString("setStatus('connecting'", $html);
        $this->assertStringContainsString(
            "if (eventName === 'error' && (typeof e.data !== 'string' || e.data === ''))",
            $html
        );
        $this->assertStringContainsString("if (e && typeof e.data === 'string' && e.data !== '')", $html);
        $this->assertStringContainsString("setStatus('error', String(msg ||", $html);
        $this->assertStringContainsString('if (terminalCompleted)', $html);
    }

    public function testSseTerminalMarkupRoutesEventsThroughSingleDispatchPath(): void
    {
        $callback = SseTerminal::callback();

        $html = $callback(
            'single',
            [],
            [''],
            [
                'id' => 'demo-terminal',
                'title' => 'Demo',
                'show-start-toggle' => 'false',
            ]
        );

        $this->assertIsString($html);
        $this->assertStringContainsString('function dispatchSseEvent(eventName, data, rawEvent)', $html);
        $this->assertStringContainsString('var callbackEvent = rawEvent || { data: JSON.stringify(data) };', $html);
        $this->assertStringContainsString('dispatchSseEvent(eventName, data, e);', $html);
        $this->assertStringNotContainsString('dispatchSseEvent(eventName, data);', $html);
        $this->assertStringContainsString('"total"', $html);
        $this->assertStringContainsString('.weline-sse-terminal-line.total', $html);
        $this->assertStringContainsString("var shouldFinalizeStream = eventName === 'done';", $html);
        $this->assertStringContainsString('finally {', $html);
        $this->assertStringContainsString('stop({ internal: true });', $html);
        $this->assertStringNotContainsString(
            "if (eventName === 'done' || eventName === 'failed' || eventName === 'error')",
            $html
        );
    }
}
