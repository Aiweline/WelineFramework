<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Adapter\Notification\TelegramAdapter;
use Weline\Backend\Api\Notification\ChannelAdapterInterface;

class TelegramAdapterTest extends TestCase
{
    public function testImplementsChannelAdapterInterface(): void
    {
        $adapter = new TelegramAdapter();
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    public function testChannelCode(): void
    {
        $adapter = new TelegramAdapter();
        $this->assertSame('telegram', $adapter->getChannelCode());
    }

    public function testFormatMessage(): void
    {
        $adapter = new TelegramAdapter();

        $message = $adapter->formatMessage([
            'topic_code' => 'bt_server_health',
            'type' => 'error',
            'title' => 'BT 服务器不可访问',
            'content' => '服务器1 timeout',
            'recipient_name' => 'admin',
            'contact' => ['contact_name' => '运维群'],
        ]);

        $this->assertIsArray($message);
        $this->assertArrayHasKey('text', $message);
        $this->assertStringContainsString('BT 服务器不可访问', $message['text']);
        $this->assertStringContainsString('运维群', $message['text']);
    }

    public function testGetConfigFieldsContainsBotTokenAndChatId(): void
    {
        $adapter = new TelegramAdapter();
        $fields = $adapter->getConfigFields();
        $fieldNames = array_column($fields, 'name');

        $this->assertContains('bot_token', $fieldNames);
        $this->assertContains('chat_id', $fieldNames);
    }

    public function testSendReturnsFalseWhenConfigMissing(): void
    {
        $adapter = new TelegramAdapter();
        $this->assertFalse($adapter->send([
            'topic_code' => 'test',
            'type' => 'info',
            'title' => 'Test',
            'content' => 'Test',
        ], []));
    }
}
