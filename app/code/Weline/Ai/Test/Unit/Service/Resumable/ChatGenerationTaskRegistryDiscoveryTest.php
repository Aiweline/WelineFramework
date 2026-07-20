<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Resumable\ChatGenerationTaskHandler;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Runtime\ResumableTaskHandlerRegistry;

final class ChatGenerationTaskRegistryDiscoveryTest extends TestCase
{
    public function testTheEnabledAiModuleDiscoversItsRegisteredResumableHandler(): void
    {
        $registry = ObjectManager::getInstance(ResumableTaskHandlerRegistry::class);
        self::assertInstanceOf(ResumableTaskHandlerRegistry::class, $registry);
        $definitions = $registry->definitions();

        self::assertArrayHasKey('ai.chat_generation', $definitions);
        self::assertSame('Weline_Ai', $definitions['ai.chat_generation']->module);
        self::assertSame(ChatGenerationTaskHandler::class, $definitions['ai.chat_generation']->handlerClass);
    }
}
