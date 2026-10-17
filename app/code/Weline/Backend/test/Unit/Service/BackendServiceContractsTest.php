<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Model\SystemNotification;
use Weline\Backend\Model\UserNotificationStatus;
use Weline\Backend\Service\ScopedNotificationWriter;
use Weline\Backend\Service\ScopedUrgentNotifier;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendServiceContractsPass().
 */
final class BackendServiceContractsTest extends TestCase
{
    public function testAllBackendServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'NotificationIsReadFlag::testServiceSourceUsesNormalizeHelperNotBoolCast' => function () {
            $this->assert_NotificationIsReadFlag_testServiceSourceUsesNormalizeHelperNotBoolCast();
        },
            'NotificationMarkAllReadUnboundedSelect::testMarkAllAndTopicReadUseBulkSafeFetchPaths' => function () {
            $this->assert_NotificationMarkAllReadUnboundedSelect_testMarkAllAndTopicReadUseBulkSafeFetchPaths();
        },
            'NotificationPagination::testNotificationServiceUsesPaginationStateNotHtmlRenderer' => function () {
            $this->assert_NotificationPagination_testNotificationServiceUsesPaginationStateNotHtmlRenderer();
        },
            'NotificationRouterSubscribeHydrate::testIsUserSubscribedUsesFetchArrayNotModelGetId' => function () {
            $this->assert_NotificationRouterSubscribeHydrate_testIsUserSubscribedUsesFetchArrayNotModelGetId();
        },
            'ScopedNotificationSkipIfUnread::testWriterImplementsSkipIfUnreadBranch' => function () {
            $this->assert_ScopedNotificationSkipIfUnread_testWriterImplementsSkipIfUnreadBranch();
        },
            'ScopedUrgentNotification::testRequiresExplicitRecipientsForScopedUrgent' => function () {
            $this->assert_ScopedUrgentNotification_testRequiresExplicitRecipientsForScopedUrgent();
        },
            'ScopedUrgentNotification::testScopedUrgentWithoutRecipientsWritesAuditAndSkipsBroadcast' => function () {
            $this->assert_ScopedUrgentNotification_testScopedUrgentWithoutRecipientsWritesAuditAndSkipsBroadcast();
        },
            'ScopedUrgentNotification::testDedupeIncrementsOccurrenceWithoutSecondBroadcastRow' => function () {
            $this->assert_ScopedUrgentNotification_testDedupeIncrementsOccurrenceWithoutSecondBroadcastRow();
        },
        ];
    }

private function assert_NotificationIsReadFlag_testServiceSourceUsesNormalizeHelperNotBoolCast(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Service/NotificationService.php');
    self::assertStringContainsString('normalizeIsReadFlag', $source);
    self::assertStringContainsString('private static function normalizeIsReadFlag', $source);
    self::assertStringNotContainsString("'is_read'           => (bool)", $source);
    self::assertStringContainsString("\$raw === '1'", $source);
}

private function assert_NotificationMarkAllReadUnboundedSelect_testMarkAllAndTopicReadUseBulkSafeFetchPaths(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Service/NotificationService.php');
    $markAll = $this->extractMethodBody_NotificationMarkAllReadUnboundedSelect($source, 'markAllAsRead');
    $markTopic = $this->extractMethodBody_NotificationMarkAllReadUnboundedSelect($source, 'markByTopicAsRead');
    $bulkHelper = $this->extractMethodBody_NotificationMarkAllReadUnboundedSelect($source, 'bulkMarkStatusIdsAsRead');
    self::assertNotSame('', $markAll);
    self::assertNotSame('', $markTopic);
    self::assertNotSame('', $bulkHelper);
    self::assertStringContainsString('->update([', $markAll);
    self::assertStringContainsString('->total()', $markAll);
    self::assertStringNotContainsString('fetchArray()', $markAll);
    self::assertStringNotContainsString('->save()', $markAll);
    self::assertStringContainsString('fetchIterator()', $markTopic);
    self::assertStringContainsString('bulkMarkStatusIdsAsRead', $markTopic);
    self::assertStringNotContainsString('fetchArray()', $markTopic);
    self::assertStringNotContainsString('->save()', $markTopic);
    self::assertStringContainsString('->update([', $bulkHelper);
    self::assertStringContainsString("'IN'", $bulkHelper);
}

private function extractMethodBody_NotificationMarkAllReadUnboundedSelect(string $source, string $method): string
{
    $needle = 'function ' . $method . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    return '';
}

private function assert_NotificationPagination_testNotificationServiceUsesPaginationStateNotHtmlRenderer(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Service/NotificationService.php');
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/view/templates/backend/Notification/index.phtml');
    self::assertStringContainsString('->getPaginationState()', $service);
    self::assertStringNotContainsString('$this->statusModel->getPagination()', $service);
    self::assertStringContainsString("\$pagination['totalSize']", $service);
    self::assertStringContainsString("\$pagination['lastPage']", $service);
    self::assertStringContainsString('w-notification-center__pagination', $template);
    self::assertStringContainsString('$totalPages > 1', $template);
}

private function assert_NotificationRouterSubscribeHydrate_testIsUserSubscribedUsesFetchArrayNotModelGetId(): void
{
    $path = BP . '/app/code/Weline/Backend/Service/NotificationRouter.php';
    $this->assertFileExists($path);
    $src = (string) file_get_contents($path);
    $methodPos = strpos($src, 'function isUserSubscribed');
    $this->assertNotFalse($methodPos);
    $next = strpos($src, 'function getUserSubscribedChannels', $methodPos);
    $this->assertNotFalse($next);
    $body = substr($src, $methodPos, $next - $methodPos);
    $this->assertStringContainsString('fetchArray()', $body);
    $this->assertStringNotContainsString('->fetch()', $body);
    $this->assertStringNotContainsString('getId()', $body);
}

private function assert_ScopedNotificationSkipIfUnread_testWriterImplementsSkipIfUnreadBranch(): void
{
    $writer = '/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Service/ScopedNotificationWriter.php';
    $observer = '/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Observer/SystemNotificationObserver.php';
    self::assertFileExists($writer);
    self::assertFileExists($observer);
    $writerSrc = (string) \file_get_contents($writer);
    $observerSrc = (string) \file_get_contents($observer);
    self::assertStringContainsString('skip_if_unread', $writerSrc);
    self::assertStringContainsString('hasUnreadRecipients', $writerSrc);
    self::assertStringContainsString("'skipped' => true", $writerSrc);
    self::assertStringContainsString('setDedupeKey(null)', $writerSrc);
    self::assertStringContainsString("!empty(\$result['skipped'])", $observerSrc);
}

private function assert_ScopedUrgentNotification_testRequiresExplicitRecipientsForScopedUrgent(): void
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

private function assert_ScopedUrgentNotification_testScopedUrgentWithoutRecipientsWritesAuditAndSkipsBroadcast(): void
{
    $scope = ScopeIdentity::website(0, 'default');
    $dedupe = 'test-acl-02-audit-' . \uniqid('', true);
    $title = 'ACL02审计_' . \uniqid('', true);
    /** @var ScopedUrgentNotifier $notifier */
    $notifier = ObjectManager::getInstance(ScopedUrgentNotifier::class);
    $notifier->emit('test_acl_02_scoped_urgent', $title, '无权用户不得收到敏感摘要广播', $scope, $dedupe, ['source_module' => 'Weline_Backend'], []);
    /** @var SystemNotification $notificationModel */
    $notificationModel = ObjectManager::getInstance(SystemNotification::class);
    $row = (clone $notificationModel)->clearData()->reset()->where(SystemNotification::schema_fields_title, $title)->find()->fetch();
    self::assertGreaterThan(0, (int) $row->getId());
    self::assertSame('urgent', $row->getType());
    self::assertSame(ScopedNotificationWriter::hashScopeIdentity($scope), $row->getScopeHash());
    self::assertSame($dedupe, $row->getDedupeKey());
    self::assertSame(1, $row->getOccurrenceCount());
    /** @var UserNotificationStatus $statusModel */
    $statusModel = ObjectManager::getInstance(UserNotificationStatus::class);
    $statuses = (clone $statusModel)->clearData()->reset()->where(UserNotificationStatus::schema_fields_notification_id, (int) $row->getId())->select()->fetchArray();
    self::assertSame([], $statuses, '无授权收件人时不得广播用户状态');
}

private function assert_ScopedUrgentNotification_testDedupeIncrementsOccurrenceWithoutSecondBroadcastRow(): void
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
    $rows = (clone $notificationModel)->clearData()->reset()->where(SystemNotification::schema_fields_topic_code, 'test_acl_02_dedupe')->where(SystemNotification::schema_fields_SCOPE_HASH, ScopedNotificationWriter::hashScopeIdentity($scope))->where(SystemNotification::schema_fields_dedupe_key, $dedupe)->select()->fetchArray();
    self::assertCount(1, $rows);
    self::assertSame(2, (int) ($rows[0][SystemNotification::schema_fields_OCCURRENCE_COUNT] ?? 0));
    self::assertSame($title2, (string) ($rows[0][SystemNotification::schema_fields_title] ?? ''));
}
}
