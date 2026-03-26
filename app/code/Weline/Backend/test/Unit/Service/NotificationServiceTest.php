<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Service\NotificationRouter;
use Weline\Backend\Service\NotificationService;
use Weline\Backend\Service\UserContactService;
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

    public function testWMsgFunctionSendsNotification(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        $testTitle = 'unit_notification_' . uniqid();

        w_msg(
            'system_info',
            'info',
            $testTitle,
            'Notification created by PHPUnit.',
            [
                'metadata' => ['test' => true, 'test_id' => uniqid('test_')],
            ]
        );

        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $testTitle)
            ->find()
            ->fetch();

        $this->assertNotEmpty($notificationModel->getId(), 'Notification should be created.');
        $this->assertSame($testTitle, $notificationModel->getTitle());
        $this->assertSame('system_info', $notificationModel->getTopicCode());
        $this->assertSame('info', $notificationModel->getType());
    }

    public function testWMsgDifferentTypes(): void
    {
        $types = ['info', 'success', 'warning', 'error', 'urgent'];
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);

        foreach ($types as $type) {
            $testTitle = "type_{$type}_" . uniqid();
            w_msg(
                'system_info',
                $type,
                $testTitle,
                "Notification for {$type}.",
                ['metadata' => ['test_type' => $type]]
            );

            $notificationModel->clearQuery()
                ->where(SystemNotification::schema_fields_title, $testTitle)
                ->find()
                ->fetch();

            $this->assertNotEmpty($notificationModel->getId(), "Notification should be created for {$type}.");
            $this->assertSame($type, $notificationModel->getType(), "Notification type should match {$type}.");
        }
    }

    public function testWMsgAutoPriority(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);

        $urgentTitle = 'urgent_test_' . uniqid();
        $infoTitle = 'info_test_' . uniqid();

        w_msg('system_info', 'urgent', $urgentTitle, 'Urgent notification priority test.');
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $urgentTitle)
            ->find()
            ->fetch();
        $urgentPriority = $notificationModel->getPriority();
        $this->assertNotEmpty($notificationModel->getId(), 'Urgent notification should be created.');

        w_msg('system_info', 'info', $infoTitle, 'Info notification priority test.');
        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $infoTitle)
            ->find()
            ->fetch();
        $infoPriority = $notificationModel->getPriority();
        $this->assertNotEmpty($notificationModel->getId(), 'Info notification should be created.');

        $this->assertGreaterThan(
            $infoPriority,
            $urgentPriority,
            'Urgent notification should have a higher priority.'
        );
    }

    public function testWMsgManualPriority(): void
    {
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);

        $testTitle = 'manual_priority_' . uniqid();
        w_msg(
            'system_info',
            'info',
            $testTitle,
            'Manual priority notification test.',
            ['priority' => 10]
        );

        $notificationModel->clearQuery()
            ->where(SystemNotification::schema_fields_title, $testTitle)
            ->find()
            ->fetch();

        $this->assertNotEmpty($notificationModel->getId(), 'Notification should be created.');
        $this->assertSame(10, $notificationModel->getPriority());
    }

    public function testGetUserNotifications(): void
    {
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->order(BackendUser::schema_fields_ID)
            ->select()
            ->fetch();

        if (!$user || !$user->getId()) {
            $this->markTestSkipped('No enabled backend user is available.');
        }

        $result = $this->service->getUserNotifications((int) $user->getId(), 1, 10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function testGetUnreadCount(): void
    {
        $userModel = ObjectManager::getInstance(BackendUser::class);
        $user = $userModel->clearQuery()
            ->where(BackendUser::schema_fields_is_enabled, 1)
            ->order(BackendUser::schema_fields_ID)
            ->select()
            ->fetch();

        if (!$user || !$user->getId()) {
            $this->markTestSkipped('No enabled backend user is available.');
        }

        $count = $this->service->getUnreadCount((int) $user->getId());

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGetTopicsGrouped(): void
    {
        $topics = $this->service->getTopicsGrouped();

        $this->assertIsArray($topics);
    }

    public function testGetChannels(): void
    {
        $channels = $this->service->getChannels();

        $this->assertIsArray($channels);
    }
}
