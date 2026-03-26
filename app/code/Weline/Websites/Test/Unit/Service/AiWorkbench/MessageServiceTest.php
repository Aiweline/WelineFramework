<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\MessageService;

class MessageServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    private MessageService $messageService;

    public function setUp(): void
    {
        parent::setUp();
        $this->messageService = ObjectManager::getInstance(MessageService::class);
    }

    public function testAppendAndListMessagesForSession(): void
    {
        $session = $this->createTrackedSession();

        $this->assertTrue($this->messageService->appendMessage($session->getId(), 1, 'assistant', 'hello', 'message'));
        $this->assertTrue($this->messageService->appendMessage(
            $session->getId(),
            1,
            'tool',
            'domain lookup',
            'tool_result',
            ['tool' => 'check_domain', 'available' => true]
        ));

        $messages = $this->messageService->listForSession($session->getId(), 1, 10);

        $this->assertCount(2, $messages);
        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('hello', $messages[0]['content']);
        $this->assertSame('tool_result', $messages[1]['message_type']);
        $this->assertSame(
            ['tool' => 'check_domain', 'available' => true],
            $messages[1]['tool_payload']
        );
    }
}
