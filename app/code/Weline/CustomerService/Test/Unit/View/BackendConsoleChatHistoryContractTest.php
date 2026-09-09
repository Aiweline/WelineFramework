<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendConsoleChatHistoryContractTest extends TestCase
{
    public function testConsoleLoadsLatestThenOlderOnScroll(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/backend-console.js');
        $ctrl = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Console.php');

        $this->assertStringContainsString('正在加载最新消息', $js);
        $this->assertStringContainsString('before_id', $js);
        $this->assertStringContainsString('loadOlderMessages', $js);
        $this->assertStringContainsString('onChatMessagesScroll', $js);
        $this->assertStringContainsString('hasMoreHistory', $js);
        $this->assertStringContainsString("getParam('before_id'", $ctrl);
        $this->assertStringContainsString('getMessagesBefore', $ctrl);
        $this->assertStringContainsString("'has_more'", $ctrl);
    }
}
