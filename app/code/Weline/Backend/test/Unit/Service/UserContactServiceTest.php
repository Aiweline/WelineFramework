<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Service\UserContactService;
use Weline\Backend\Model\UserContact;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

class UserContactServiceTest extends TestCase
{
    private ?UserContactService $service = null;
    private ?BackendUser $userModel = null;
    private int $testUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ObjectManager::getInstance(UserContactService::class);
        $this->userModel = ObjectManager::getInstance(BackendUser::class);
        
        $user = $this->userModel->clearQuery()
            ->where(BackendUser::fields_is_enabled, 1)
            ->order(BackendUser::fields_ID)
            ->select()
            ->fetch();
        
        if ($user && $user->getId()) {
            $this->testUserId = (int) $user->getId();
        }
    }

    /**
     * 测试创建联系人
     */
    public function testCreateContact(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testValue = 'unittest_' . uniqid() . '@test.com';
        $contactId = $this->service->createContact(
            $this->testUserId,
            'email',
            $testValue,
            [
                'contact_name' => '单元测试联系人',
                'is_verified' => false,
            ]
        );
        
        $this->assertIsInt($contactId);
        $this->assertGreaterThan(0, $contactId);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($contactId);
        $this->assertEquals($testValue, $contactModel->getContactValue());
        
        $contactModel->delete()->fetch();
    }

    /**
     * 测试创建重复联系人返回已存在 ID
     */
    public function testCreateDuplicateContactReturnsExisting(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testValue = 'duplicate_test_' . uniqid() . '@test.com';
        
        $firstId = $this->service->createContact($this->testUserId, 'email', $testValue);
        $secondId = $this->service->createContact($this->testUserId, 'email', $testValue);
        
        $this->assertEquals($firstId, $secondId, '创建重复联系人应返回已存在的 ID');
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($firstId);
        $contactModel->delete()->fetch();
    }

    /**
     * 测试第一个联系人自动设为默认
     */
    public function testFirstContactIsDefault(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'webhook_' . uniqid();
        $testValue = 'https://test.webhook.com/' . uniqid();
        
        $contactId = $this->service->createContact(
            $this->testUserId,
            $testChannel,
            $testValue,
            ['is_default' => false]
        );
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($contactId);
        $this->assertTrue((bool) $contactModel->getIsDefault(), '第一个联系人应自动成为默认');
        
        $contactModel->delete()->fetch();
    }

    /**
     * 测试获取默认联系人
     */
    public function testGetDefaultContactByChannel(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'test_channel_' . uniqid();
        $testValue1 = 'value1_' . uniqid();
        $testValue2 = 'value2_' . uniqid();
        
        $id1 = $this->service->createContact($this->testUserId, $testChannel, $testValue1);
        $id2 = $this->service->createContact($this->testUserId, $testChannel, $testValue2);
        
        $defaultContact = $this->service->getDefaultContactByChannel($this->testUserId, $testChannel);
        
        $this->assertNotNull($defaultContact);
        $this->assertEquals($testValue1, $defaultContact['contact_value'], '第一个联系人应为默认');
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($id1);
        $contactModel->delete()->fetch();
        $contactModel->clearQuery()->load($id2);
        $contactModel->delete()->fetch();
    }

    /**
     * 测试设置默认联系人
     */
    public function testSetDefaultContact(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'default_test_' . uniqid();
        $id1 = $this->service->createContact($this->testUserId, $testChannel, 'contact1_' . uniqid());
        $id2 = $this->service->createContact($this->testUserId, $testChannel, 'contact2_' . uniqid());
        
        $result = $this->service->setDefaultContact($this->testUserId, $testChannel, $id2);
        $this->assertTrue($result);
        
        $defaultContact = $this->service->getDefaultContactByChannel($this->testUserId, $testChannel);
        $this->assertEquals($id2, (int) $defaultContact['contact_id']);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($id1);
        $contactModel->delete()->fetch();
        $contactModel->clearQuery()->load($id2);
        $contactModel->delete()->fetch();
    }

    /**
     * 测试获取用户所有联系人
     */
    public function testGetUserContacts(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $contacts = $this->service->getUserContacts($this->testUserId);
        
        $this->assertIsArray($contacts);
    }

    /**
     * 测试获取联系人按渠道分组
     */
    public function testGetUserContactsGrouped(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'grouped_test_' . uniqid();
        $id1 = $this->service->createContact($this->testUserId, $testChannel, 'grouped1_' . uniqid());
        
        $grouped = $this->service->getUserContactsGrouped($this->testUserId);
        
        $this->assertIsArray($grouped);
        $this->assertArrayHasKey($testChannel, $grouped);
        $this->assertCount(1, $grouped[$testChannel]);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($id1);
        $contactModel->delete()->fetch();
    }

    /**
     * 测试更新联系人
     */
    public function testUpdateContact(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'update_test_' . uniqid();
        $contactId = $this->service->createContact($this->testUserId, $testChannel, 'original_' . uniqid());
        
        $newName = '更新后的名称_' . uniqid();
        $result = $this->service->updateContact($contactId, [
            'contact_name' => $newName,
        ]);
        
        $this->assertTrue($result);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($contactId);
        $this->assertEquals($newName, $contactModel->getContactName());
        
        $contactModel->delete()->fetch();
    }

    /**
     * 测试删除联系人
     */
    public function testDeleteContact(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'delete_test_' . uniqid();
        $contactId = $this->service->createContact($this->testUserId, $testChannel, 'to_delete_' . uniqid());
        
        $result = $this->service->deleteContact($contactId);
        $this->assertTrue($result);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($contactId);
        $this->assertEmpty($contactModel->getId());
    }

    /**
     * 测试删除不存在的联系人
     */
    public function testDeleteNonexistentContact(): void
    {
        $result = $this->service->deleteContact(999999999);
        $this->assertFalse($result);
    }

    /**
     * 测试自动创建默认联系人
     */
    public function testCreateDefaultContactsForUser(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testEmail = 'autodefault_' . uniqid() . '@test.com';
        $testPhone = '138' . str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        
        $ids = $this->service->createDefaultContactsForUser($this->testUserId, $testEmail, $testPhone);
        
        $this->assertIsArray($ids);
        $this->assertArrayHasKey('email', $ids);
        $this->assertArrayHasKey('sms', $ids);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        foreach ($ids as $id) {
            $contactModel->clearQuery()->load($id);
            $contactModel->delete()->fetch();
        }
    }

    /**
     * 测试获取通知用联系人
     */
    public function testGetContactForNotification(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $testChannel = 'notify_test_' . uniqid();
        $testValue = 'notify_value_' . uniqid();
        $contactId = $this->service->createContact($this->testUserId, $testChannel, $testValue);
        
        $contact = $this->service->getContactForNotification($this->testUserId, $testChannel);
        
        $this->assertNotNull($contact);
        $this->assertArrayHasKey('contact_id', $contact);
        $this->assertArrayHasKey('contact_value', $contact);
        $this->assertArrayHasKey('contact_name', $contact);
        $this->assertArrayHasKey('extra_config', $contact);
        $this->assertEquals($testValue, $contact['contact_value']);
        
        $contactModel = ObjectManager::getInstance(UserContact::class);
        $contactModel->clearQuery()->load($contactId);
        $contactModel->delete()->fetch();
    }

    /**
     * 测试获取不存在渠道的联系人
     */
    public function testGetContactForNotificationNonexistent(): void
    {
        if (!$this->testUserId) {
            $this->markTestSkipped('没有可用的后台用户');
        }

        $contact = $this->service->getContactForNotification($this->testUserId, 'nonexistent_channel_' . uniqid());
        
        $this->assertNull($contact);
    }
}
