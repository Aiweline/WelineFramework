<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\ChatService;

final class ChatMessagesHistoryContractTest extends TestCase
{
    public function testGetMessagesBeforeExistsAndGetMessagesOrdersById(): void
    {
        $ref = new \ReflectionClass(ChatService::class);
        $this->assertTrue($ref->hasMethod('getMessagesBefore'));
        $this->assertTrue($ref->hasMethod('getMessages'));

        $src = (string)file_get_contents($ref->getFileName());
        $this->assertStringContainsString("order(ChatMessage::schema_fields_ID, 'DESC')", $src);
        $this->assertStringContainsString('function getMessagesBefore', $src);
    }
}
