<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Service\ScopedNotificationWriter;
use Weline\Backend\Service\ScopedUrgentNotifier;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * TEST-ACL-02：scoped urgent 去重、零广播审计、显式授权路由。
 */
final class ScopedUrgentNotificationContractTest extends TestCase
{
    public function testRequiresExplicitRecipientsForScopedUrgent(): void
    {
        /** @var SystemNotification $model */
        $model = ObjectManager::getInstance(SystemNotification::class);
        $writer = new ScopedNotificationWriter($model);
        $scope = ScopeIdentity::website(0, 'default');
        $hash = ScopedNotificationWriter::hashScopeIdentity($scope);

        self::assertTrue($writer->requiresExplicitRecipients('urgent', $hash, ['scoped' => true]));
        self::assertTrue($writer->requiresExplicitRecipients('urgent', $hash, []));
        self::assertFalse($writer->requiresExplicitRecipients('urgent', SystemNotification::SCOPE_HASH_GLOBAL, []));
        self::assertFalse($writer->requiresExplicitRecipients('info', $hash, []));
    }

    public function testScopedUrgentWithoutRecipientsWritesAuditAndSkipsBroadcast(): void
    {
        $scope = ScopeIdentity::website(0, 'default');
        $dedupe = 'test-acl-02-audit-' . \uniqid('', true);
        $title = 'ACL02审计_' . \uniqid('', true);

        /** @var ScopedUrgentNotifier $notifier */
        $notifier = ObjectManager::getInstance(ScopedUrgentNotifier::class);
        $notifier->emit(
            'test_acl_02_scoped_urgent',
            $title,
            '无权用户不得收到敏感摘要广播',
            $scope,
            $dedupe,
            ['source_module' => 'Weline_Backend'],
            [], // 显式空：仅审计
        );

        /** @var SystemNotification $notificationModel */
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        $row = (clone $notificationModel)->clearData()->reset()
            ->where(SystemNotification::schema_fields_title, $title)
            ->find()
            ->fetch();
        self::assertGreaterThan(0, (int)$row->getId());
        self::assertSame('urgent', $row->getType());
        self::assertSame(ScopedNotificationWriter::hashScopeIdentity($scope), $row->getScopeHash());
        self::assertSame($dedupe, $row->getDedupeKey());
        self::assertSame(1, $row->getOccurrenceCount());

        /** @var UserNotificationStatus $statusModel */
        $statusModel = ObjectManager::getInstance(UserNotificationStatus::class);
        $statuses = (clone $statusModel)->clearData()->reset()
            ->where(UserNotificationStatus::schema_fields_notification_id, (int)$row->getId())
            ->select()
            ->fetchArray();
        self::assertSame([], $statuses, '无授权收件人时不得广播用户状态');
    }

    public function testDedupeIncrementsOccurrenceWithoutSecondBroadcastRow(): void
    {
        $scope = ScopeIdentity::website(0, 'default');
        $dedupe = 'test-acl-02-dedupe-' . \uniqid('', true);
        $title1 = 'ACL02去重A_' . \uniqid('', true);
        $title2 = 'ACL02去重B_' . \uniqid('', true);

        /** @var ScopedUrgentNotifier $notifier */
        $notifier = ObjectManager::getInstance(ScopedUrgentNotifier::class);
        $notifier->emit('test_acl_02_dedupe', $title1, 'first', $scope, $dedupe, [], []);
        $notifier->emit('test_acl_02_dedupe', $title2, 'second', $scope, $dedupe, [], []);

        /** @var SystemNotification $notificationModel */
        $notificationModel = ObjectManager::getInstance(SystemNotification::class);
        $rows = (clone $notificationModel)->clearData()->reset()
            ->where(SystemNotification::schema_fields_topic_code, 'test_acl_02_dedupe')
            ->where(SystemNotification::schema_fields_SCOPE_HASH, ScopedNotificationWriter::hashScopeIdentity($scope))
            ->where(SystemNotification::schema_fields_dedupe_key, $dedupe)
            ->select()
            ->fetchArray();
        self::assertCount(1, $rows);
        self::assertSame(2, (int)($rows[0][SystemNotification::schema_fields_OCCURRENCE_COUNT] ?? 0));
        self::assertSame($title2, (string)($rows[0][SystemNotification::schema_fields_title] ?? ''));
    }
}
