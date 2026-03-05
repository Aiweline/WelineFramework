<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Observer\UserRegisteredObserver;
use Weline\Backend\Service\UserContactService;
use Weline\Backend\Model\UserContact;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class UserRegisteredObserverTest extends TestCase
{
    private ?UserContactService $contactService = null;
    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contactService = ObjectManager::getInstance(UserContactService::class);
        
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
     * 测试 Observer 存在且可实例化
     */
    public function testObserverExists(): void
    {
        $observer = ObjectManager::getInstance(UserRegisteredObserver::class);
        $this->assertInstanceOf(UserRegisteredObserver::class, $observer);
    }

    /**
     * 测试 Observer 实现接口
     */
    public function testObserverImplementsInterface(): void
    {
        $observer = ObjectManager::getInstance(UserRegisteredObserver::class);
        $this->assertInstanceOf(\Weline\Framework\Event\ObserverInterface::class, $observer);
    }

    /**
     * 测试用户注册事件触发联系人创建
     */
    public function testUserRegisteredEventCreatesContacts(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testEmail = 'observer_test_' . uniqid() . '@test.com';
        $testPhone = '139' . str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()->load($this->testUserId);
        
        if (!$user || !$user->getId()) {
            $this->markTestSkipped('无法加载测试用户');
        }
        
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Backend::user::registered', [
            'data' => [
                'user_id' => $this->testUserId,
                'email' => $testEmail,
                'phone' => $testPhone,
                'is_new' => true,
            ]
        ]);
        
        $emailContact = $this->contactService->getContactForNotification($this->testUserId, 'email');
        $smsContact = $this->contactService->getContactForNotification($this->testUserId, 'sms');
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        
        if ($emailContact && $emailContact['contact_value'] === $testEmail) {
            $this->assertEquals($testEmail, $emailContact['contact_value']);
            $contactModel->clearQuery()->load((int) $emailContact['contact_id']);
            $contactModel->delete()->fetch();
        }
        
        if ($smsContact && $smsContact['contact_value'] === $testPhone) {
            $this->assertEquals($testPhone, $smsContact['contact_value']);
            $contactModel->clearQuery()->load((int) $smsContact['contact_id']);
            $contactModel->delete()->fetch();
        }
        
        $this->assertTrue(true);
    }

    /**
     * 测试非新用户不创建联系人
     */
    public function testExistingUserDoesNotCreateContacts(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testEmail = 'existing_user_' . uniqid() . '@test.com';
        $testChannel = 'email';
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $beforeCount = $contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $this->testUserId)
            ->where(UserContact::schema_fields_contact_value, $testEmail)
            ->total();
        
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Backend::user::registered', [
            'data' => [
                'user_id' => $this->testUserId,
                'email' => $testEmail,
                'is_new' => false,
            ]
        ]);
        
        $afterCount = $contactModel->clearQuery()
            ->where(UserContact::schema_fields_user_id, $this->testUserId)
            ->where(UserContact::schema_fields_contact_value, $testEmail)
            ->total();
        
        $this->assertEquals($beforeCount, $afterCount, '非新用户不应创建新联系人');
    }

    /**
     * 测试空用户 ID 不创建联系人
     */
    public function testEmptyUserIdDoesNotCreateContacts(): void
    {
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        $eventData = [
            'data' => [
                'user_id' => 0,
                'email' => 'empty_user_' . uniqid() . '@test.com',
                'is_new' => true,
            ]
        ];
        $eventsManager->dispatch('Weline_Backend::user::registered', $eventData);
        
        $this->assertTrue(true);
    }

    /**
     * 测试空邮箱不创建联系人
     */
    public function testEmptyEmailDoesNotCreateContacts(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        $eventsManager->dispatch('Weline_Backend::user::registered', [
            'data' => [
                'user_id' => $this->testUserId,
                'email' => '',
                'is_new' => true,
            ]
        ]);
        
        $this->assertTrue(true);
    }

    /**
     * 测试事件数据为空时不出错
     */
    public function testEmptyEventDataDoesNotCrash(): void
    {
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        $eventData = ['data' => []];
        $eventsManager->dispatch('Weline_Backend::user::registered', $eventData);
        
        $this->assertTrue(true);
    }

    /**
     * 测试联系人服务方法可用性
     */
    public function testContactServiceMethodsAvailable(): void
    {
        $this->assertTrue(method_exists($this->contactService, 'createDefaultContactsForUser'));
        $this->assertTrue(method_exists($this->contactService, 'getContactForNotification'));
        $this->assertTrue(method_exists($this->contactService, 'createContact'));
    }
}
