<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\NotificationService;
use Weline\Backend\Service\NotificationRouter;
use Weline\Backend\Service\UserContactService;
use Weline\Backend\Service\TopicCollector;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Model\UserNotificationSubscription;
use Weline\Backend\Model\NotificationTopic;
use Weline\Backend\Model\NotificationChannel;
use Weline\Backend\Model\UserContact;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

class NotificationServiceTest extends TestCase
{
    private ?NotificationService $service = null;
    private ?NotificationRouter $router = null;
    private ?UserContactService $contactService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ObjectManager::getInstance(NotificationService::class);
        $this->router = ObjectManager::getInstance(NotificationRouter::class);
        $this->contactService = ObjectManager::getInstance(UserContactService::class);
    }

    /**
     * 测试 w_msg() 函数发送通知
     */
    public function testWMsgFunctionSendsNotification(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        $initialCount = $notificationModel->clearQuery()->total();
        
        $testTitle = '单元测试通知_' . uniqid();
        w_msg(
            'system_info',
            'info',
            $testTitle,
            '这是来自 PHPUnit 单元测试的通知消息。',
            [
                'metadata' => ['test' => true, 'test_id' => uniqid('test_')]
            ]
        );
        
        $afterCount = $notificationModel->clearQuery()->total();
        $this->assertGreaterThan($initialCount, $afterCount, 'w_msg() 应该创建一条新通知');
        
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $testTitle)
            ->find()
            ->fetch();
        
        $this->assertNotEmpty($notificationModel->getId(), '通知应该被创建');
        $this->assertEquals($testTitle, $notificationModel->getTitle());
        $this->assertEquals('system_info', $notificationModel->getTopicCode());
        $this->assertEquals('info', $notificationModel->getType());
    }

    /**
     * 测试不同消息类型
     */
    public function testWMsgDifferentTypes(): void
    {
        $types = ['info', 'success', 'warning', 'error', 'urgent'];
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        
        foreach ($types as $type) {
            $testTitle = "测试类型_{$type}_" . uniqid();
            w_msg(
                'system_info',
                $type,
                $testTitle,
                "测试 {$type} 类型的通知",
                ['metadata' => ['test_type' => $type]]
            );
            
            $notificationModel->clearQuery()
                ->where(SystemNotification::schema_fields_title, $testTitle)
                ->find()
                ->fetch();
            
            $this->assertNotEmpty($notificationModel->getId(), "通知应该被创建: {$testTitle}");
            $this->assertEquals($type, $notificationModel->getType(), "消息类型应为 {$type}");
        }
    }

    /**
     * 测试优先级自动设定
     */
    public function testWMsgAutoPriority(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        
        $urgentTitle = '紧急测试_' . uniqid();
        $infoTitle = '普通测试_' . uniqid();
        
        w_msg('system_info', 'urgent', $urgentTitle, '测试紧急消息优先级');
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $urgentTitle)
            ->find()
            ->fetch();
        $urgentPriority = $notificationModel->getPriority();
        $this->assertNotEmpty($notificationModel->getId(), 'urgent 通知应该被创建');
        
        w_msg('system_info', 'info', $infoTitle, '测试普通消息优先级');
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $infoTitle)
            ->find()
            ->fetch();
        $infoPriority = $notificationModel->getPriority();
        $this->assertNotEmpty($notificationModel->getId(), 'info 通知应该被创建');
        
        $this->assertGreaterThan(
            $infoPriority,
            $urgentPriority,
            'urgent 类型应该有更高优先级'
        );
    }

    /**
     * 测试手动设置优先级
     */
    public function testWMsgManualPriority(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        
        $testTitle = '自定义优先级测试_' . uniqid();
        w_msg('system_info', 'info', $testTitle, '测试手动优先级', [
            'priority' => 10
        ]);
        
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $testTitle)
            ->find()
            ->fetch();
        
        $this->assertNotEmpty($notificationModel->getId(), '通知应该被创建');
        $this->assertEquals(10, $notificationModel->getPriority());
    }

    /**
     * 测试 NotificationService 获取用户通知
     */
    public function testGetUserNotifications(): void
    {
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->order(BackendUser::schema_fields_ID)
            ->select()
            ->fetch();
        
        if (!$user || !$user->getId()) {
            $this->markTestSkipped('没有可用的后台用户');
        }
        
        $userId = (int) $user->getId();
        $result = $this->service->getUserNotifications($userId, 1, 10);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    /**
     * 测试获取未读数量
     */
    public function testGetUnreadCount(): void
    {
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->order(BackendUser::schema_fields_ID)
            ->select()
            ->fetch();
        
        if (!$user || !$user->getId()) {
            $this->markTestSkipped('没有可用的后台用户');
        }
        
        $userId = (int) $user->getId();
        $count = $this->service->getUnreadCount($userId);
        
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * 测试获取分组主题
     */
    public function testGetTopicsGrouped(): void
    {
        $topics = $this->service->getTopicsGrouped();
        
        $this->assertIsArray($topics);
    }

    /**
     * 测试获取渠道列表
     */
    public function testGetChannels(): void
    {
        $channels = $this->service->getChannels();
        
        $this->assertIsArray($channels);
    }
}
