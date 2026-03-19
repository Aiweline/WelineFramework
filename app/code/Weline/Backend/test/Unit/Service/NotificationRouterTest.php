<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\ContactService;
use Weline\Backend\Service\NotificationRouter;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\UserNotificationSubscription;
use Weline\Backend\Model\NotificationChannel;
use Weline\Backend\Model\SystemNotification;
use Weline\Framework\Manager\ObjectManager;

class NotificationRouterTest extends TestCase
{
    private ?NotificationRouter $router = null;
    private ?ContactService $contactService = null;
    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = ObjectManager::getInstance(NotificationRouter::class);
        $this->contactService = ObjectManager::getInstance(ContactService::class);
        
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->order(BackendUser::schema_fields_ID)
            ->select()
            ->fetch();
        
        if ($user && $user->getId()) {
            $this->testUserId = (int) $user->getId();
        }
    }

    /**
     * 测试路由方法存在且可调用
     */
    public function testRouteMethodExists(): void
    {
        $this->assertTrue(method_exists($this->router, 'route'));
    }

    /**
     * 测试用户订阅检查
     */
    public function testIsUserSubscribed(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $result = $this->router->isUserSubscribed(
            $this->testUserId,
            'system_info',
            'email',
            'info'
        );
        
        $this->assertIsBool($result);
    }

    /**
     * 测试获取用户订阅渠道
     */
    public function testGetUserSubscribedChannels(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $channels = $this->router->getUserSubscribedChannels(
            $this->testUserId,
            'system_info',
            'info'
        );
        
        $this->assertIsArray($channels);
    }

    /**
     * 测试通知路由完整流程
     */
    public function testRouteNotificationFlow(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        $testTitle = '路由测试通知_' . uniqid();
        
        w_msg(
            'system_info',
            'warning',
            $testTitle,
            '这是一条测试路由的通知消息',
            [
                'notify_users' => [$this->testUserId]
            ]
        );
        
        $notification = $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $testTitle)
            ->select()
            ->fetch();
        
        $this->assertNotNull($notification);
        $this->assertNotEmpty($notification->getId());
    }

    /**
     * 测试消息级别过滤
     */
    public function testMessageTypeFiltering(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $subscriptionModel = ObjectManager::getInstance(UserNotificationSubscription::class);
        $testTopic = 'filter_test_' . uniqid();
        
        $subscription = clone $subscriptionModel;
        $subscription->clearQuery()
            ->setUserId($this->testUserId)
            ->setTopicCode($testTopic)
            ->setChannel('email')
            ->setMinType('warning')
            ->setIsEnabled(true);
        $subscription->save();
        
        $this->assertFalse(
            $this->router->isUserSubscribed($this->testUserId, $testTopic, 'email', 'info'),
            'info 应该被 warning 级别过滤掉'
        );
        
        $this->assertTrue(
            $this->router->isUserSubscribed($this->testUserId, $testTopic, 'email', 'warning'),
            'warning 应该满足 warning 最低级别'
        );
        
        $this->assertTrue(
            $this->router->isUserSubscribed($this->testUserId, $testTopic, 'email', 'error'),
            'error 应该满足 warning 最低级别'
        );
        
        $subscription->delete()->fetch();
    }

    /**
     * 测试渠道配置影响路由
     */
    public function testChannelConfigAffectsRouting(): void
    {
        $channelModel = ObjectManager::getInstance(NotificationChannel::class);
        
        $channels = $channelModel->clearQuery()
            ->where(NotificationChannel::schema_fields_is_enabled, 1)
            ->select()
            ->fetchArray();
        
        $this->assertIsArray($channels);
    }

    /**
     * 测试联系人集成到路由
     */
    public function testContactIntegrationInRouting(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'routing_contact_' . uniqid();
        $testValue = 'route_contact_value_' . uniqid();
        
        $contactId = $this->contactService->createContact($this->testUserId, $testChannel, $testValue);
        $this->assertGreaterThan(0, $contactId);
        
        $contact = $this->contactService->getContactForNotification($this->testUserId, $testChannel);
        $this->assertNotNull($contact);
        $this->assertEquals($testValue, $contact['contact_value']);
        
        $this->contactService->deleteContact($contactId);
    }
}
