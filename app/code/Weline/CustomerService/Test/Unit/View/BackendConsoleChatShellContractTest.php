<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendConsoleChatShellContractTest extends TestCase
{
    public function testChatShellLocksViewportAndScrollsMessages(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/backend-console.css';
        $protoFile = dirname(__DIR__, 3) . '/view/statics/prototype/console-chat-shell.html';
        $css = (string)file_get_contents($cssFile);
        $proto = (string)file_get_contents($protoFile);

        $this->assertStringContainsString('--cs-layout-offset', $css);
        $this->assertStringContainsString('100dvh - var(--cs-layout-offset)', $css);
        $this->assertStringContainsString('.chat-messages', $css);
        $this->assertMatchesRegularExpression('/\.chat-messages\s*\{[^}]*min-height:\s*0/s', $css);
        $this->assertMatchesRegularExpression('/\.chat-messages\s*\{[^}]*overflow-y:\s*auto/s', $css);
        $this->assertMatchesRegularExpression('/\.chat-header\s*\{[^}]*flex-shrink:\s*0/s', $css);
        $this->assertMatchesRegularExpression('/\.chat-input-area\s*\{[^}]*flex-shrink:\s*0/s', $css);
        $this->assertStringContainsString('PROTOTYPE', $proto);
        $this->assertStringContainsString('variant=A', $proto);
    }
}
