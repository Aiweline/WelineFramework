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
        $this->assertStringContainsString('connecting', $html);
        $this->assertStringContainsString('连接重试中', $html);
    }
}
