<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Controller\Frontend\Chat;

final class ChatRuntimeRefactorTest extends TestCase
{
    public function testChatHasNoConnectionBoundSendOrStreamEndpoint(): void
    {
        self::assertFalse(method_exists(Chat::class, 'send'));
        self::assertFalse(method_exists(Chat::class, 'stream'));
    }

    public function testFrontendStartsAndSubscribesThroughTheRuntimeApiOnly(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $template = (string)file_get_contents($moduleRoot . '/view/templates/Frontend/Chat/index.phtml');
        $provider = (string)file_get_contents($moduleRoot . '/Extends/module/Weline_Framework/Query/AiQueryProvider.php');

        self::assertStringContainsString("resource('runtime_task')", $template);
        self::assertStringContainsString('createStream(', $template);
        self::assertStringNotContainsString('EventSource', $template);
        self::assertStringNotContainsString('aiApi.chat', $template);
        self::assertStringNotContainsString("'chat' => $this->chat", $provider);
        self::assertStringNotContainsString("'name' => 'chat'", $provider);
    }
}
