<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Adapter\Notification\EmailAdapter;
use Weline\Backend\Adapter\Notification\FeishuAdapter;
use Weline\Backend\Adapter\Notification\DingtalkAdapter;
use Weline\Backend\Adapter\Notification\WebhookAdapter;
use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Framework\Manager\ObjectManager;

class NotificationAdapterTest extends TestCase
{
    /**
     * 测试 EmailAdapter 实现接口
     */
    public function testEmailAdapterImplementsInterface(): void
    {
        $adapter = ObjectManager::getInstance(EmailAdapter::class);
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 测试 EmailAdapter 渠道代码
     */
    public function testEmailAdapterChannelCode(): void
    {
        $adapter = ObjectManager::getInstance(EmailAdapter::class);
        $this->assertEquals('email', $adapter->getChannelCode());
    }

    /**
     * 测试 EmailAdapter 消息格式化
     */
    public function testEmailAdapterFormatMessage(): void
    {
        $adapter = ObjectManager::getInstance(EmailAdapter::class);
        
        $notification = [
            'topic_code' => 'system_info',
            'type' => 'warning',
            'title' => '测试标题',
            'content' => '测试内容',
        ];
        
        $message = $adapter->formatMessage($notification);
        
        $this->assertIsArray($message);
        $this->assertArrayHasKey('subject', $message);
        $this->assertArrayHasKey('body', $message);
        $this->assertStringContainsString('测试标题', $message['subject']);
        $this->assertStringContainsString('测试内容', $message['body']);
    }

    /**
     * 测试 EmailAdapter 配置字段
     */
    public function testEmailAdapterConfigFields(): void
    {
        $adapter = ObjectManager::getInstance(EmailAdapter::class);
        $fields = $adapter->getConfigFields();
        
        $this->assertIsArray($fields);
        $this->assertNotEmpty($fields);
        
        $fieldNames = array_column($fields, 'name');
        $this->assertContains('to_email', $fieldNames);
    }

    /**
     * 测试 FeishuAdapter 实现接口
     */
    public function testFeishuAdapterImplementsInterface(): void
    {
        $adapter = ObjectManager::getInstance(FeishuAdapter::class);
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 测试 FeishuAdapter 渠道代码
     */
    public function testFeishuAdapterChannelCode(): void
    {
        $adapter = ObjectManager::getInstance(FeishuAdapter::class);
        $this->assertEquals('feishu', $adapter->getChannelCode());
    }

    /**
     * 测试 FeishuAdapter 消息格式化
     */
    public function testFeishuAdapterFormatMessage(): void
    {
        $adapter = ObjectManager::getInstance(FeishuAdapter::class);
        
        $notification = [
            'topic_code' => 'system_info',
            'type' => 'error',
            'title' => '错误测试',
            'content' => '这是一条错误消息',
        ];
        
        $message = $adapter->formatMessage($notification);
        
        $this->assertIsArray($message);
        $this->assertArrayHasKey('msg_type', $message);
        $this->assertEquals('interactive', $message['msg_type']);
        $this->assertArrayHasKey('card', $message);
        $this->assertArrayHasKey('header', $message['card']);
        $this->assertArrayHasKey('elements', $message['card']);
    }

    /**
     * 测试 FeishuAdapter 配置字段
     */
    public function testFeishuAdapterConfigFields(): void
    {
        $adapter = ObjectManager::getInstance(FeishuAdapter::class);
        $fields = $adapter->getConfigFields();
        
        $this->assertIsArray($fields);
        $fieldNames = array_column($fields, 'name');
        $this->assertContains('webhook_url', $fieldNames);
    }

    /**
     * 测试 DingtalkAdapter 实现接口
     */
    public function testDingtalkAdapterImplementsInterface(): void
    {
        $adapter = ObjectManager::getInstance(DingtalkAdapter::class);
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 测试 DingtalkAdapter 渠道代码
     */
    public function testDingtalkAdapterChannelCode(): void
    {
        $adapter = ObjectManager::getInstance(DingtalkAdapter::class);
        $this->assertEquals('dingtalk', $adapter->getChannelCode());
    }

    /**
     * 测试 DingtalkAdapter 消息格式化
     */
    public function testDingtalkAdapterFormatMessage(): void
    {
        $adapter = ObjectManager::getInstance(DingtalkAdapter::class);
        
        $notification = [
            'topic_code' => 'security_alert',
            'type' => 'urgent',
            'title' => '安全警告',
            'content' => '检测到异常登录',
        ];
        
        $message = $adapter->formatMessage($notification);
        
        $this->assertIsArray($message);
        $this->assertArrayHasKey('msgtype', $message);
        $this->assertEquals('markdown', $message['msgtype']);
        $this->assertArrayHasKey('markdown', $message);
        $this->assertArrayHasKey('title', $message['markdown']);
        $this->assertArrayHasKey('text', $message['markdown']);
    }

    /**
     * 测试 DingtalkAdapter 配置字段
     */
    public function testDingtalkAdapterConfigFields(): void
    {
        $adapter = ObjectManager::getInstance(DingtalkAdapter::class);
        $fields = $adapter->getConfigFields();
        
        $this->assertIsArray($fields);
        $fieldNames = array_column($fields, 'name');
        $this->assertContains('webhook_url', $fieldNames);
        $this->assertContains('secret', $fieldNames);
    }

    /**
     * 测试 WebhookAdapter 实现接口
     */
    public function testWebhookAdapterImplementsInterface(): void
    {
        $adapter = ObjectManager::getInstance(WebhookAdapter::class);
        $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
    }

    /**
     * 测试 WebhookAdapter 渠道代码
     */
    public function testWebhookAdapterChannelCode(): void
    {
        $adapter = ObjectManager::getInstance(WebhookAdapter::class);
        $this->assertEquals('webhook', $adapter->getChannelCode());
    }

    /**
     * 测试 WebhookAdapter 消息格式化
     */
    public function testWebhookAdapterFormatMessage(): void
    {
        $adapter = ObjectManager::getInstance(WebhookAdapter::class);
        
        $notification = [
            'topic_code' => 'system_info',
            'type' => 'success',
            'title' => '操作成功',
            'content' => '数据已同步完成',
            'priority' => 5,
            'metadata' => ['key' => 'value'],
        ];
        
        $message = $adapter->formatMessage($notification);
        
        $this->assertIsArray($message);
        $this->assertArrayHasKey('topic_code', $message);
        $this->assertArrayHasKey('type', $message);
        $this->assertArrayHasKey('title', $message);
        $this->assertArrayHasKey('content', $message);
        $this->assertArrayHasKey('timestamp', $message);
    }

    /**
     * 测试适配器无效配置不发送
     */
    public function testEmailAdapterEmptyToEmailReturnsFalse(): void
    {
        $adapter = ObjectManager::getInstance(EmailAdapter::class);
        
        $result = $adapter->send(
            ['topic_code' => 'test', 'type' => 'info', 'title' => 'Test', 'content' => 'Test'],
            []
        );
        
        $this->assertFalse($result);
    }

    /**
     * 测试 FeishuAdapter 空 webhook 返回 false
     */
    public function testFeishuAdapterEmptyWebhookReturnsFalse(): void
    {
        $adapter = ObjectManager::getInstance(FeishuAdapter::class);
        
        $result = $adapter->send(
            ['topic_code' => 'test', 'type' => 'info', 'title' => 'Test', 'content' => 'Test'],
            []
        );
        
        $this->assertFalse($result);
    }

    /**
     * 测试 DingtalkAdapter 空 webhook 返回 false
     */
    public function testDingtalkAdapterEmptyWebhookReturnsFalse(): void
    {
        $adapter = ObjectManager::getInstance(DingtalkAdapter::class);
        
        $result = $adapter->send(
            ['topic_code' => 'test', 'type' => 'info', 'title' => 'Test', 'content' => 'Test'],
            []
        );
        
        $this->assertFalse($result);
    }

    /**
     * 测试 WebhookAdapter 空 URL 返回 false
     */
    public function testWebhookAdapterEmptyUrlReturnsFalse(): void
    {
        $adapter = ObjectManager::getInstance(WebhookAdapter::class);
        
        $result = $adapter->send(
            ['topic_code' => 'test', 'type' => 'info', 'title' => 'Test', 'content' => 'Test'],
            []
        );
        
        $this->assertFalse($result);
    }

    /**
     * 测试所有适配器都能单独获取
     */
    public function testAllAdaptersCanBeInstantiated(): void
    {
        $adapterClasses = [
            EmailAdapter::class,
            FeishuAdapter::class,
            DingtalkAdapter::class,
            WebhookAdapter::class,
        ];
        
        $channelCodes = [];
        foreach ($adapterClasses as $class) {
            $adapter = ObjectManager::getInstance($class);
            $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
            $channelCodes[] = $adapter->getChannelCode();
        }
        
        $this->assertContains('email', $channelCodes);
        $this->assertContains('feishu', $channelCodes);
        $this->assertContains('dingtalk', $channelCodes);
        $this->assertContains('webhook', $channelCodes);
    }
}
