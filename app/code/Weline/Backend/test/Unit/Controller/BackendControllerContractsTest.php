<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendControllerContractsPass().
 */
final class BackendControllerContractsTest extends TestCase
{
    public function testAllBackendControllerContractsPass(): void
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
            'NotificationDetailMarksRead::testDetailGuardsPrefetchAndExposesClientMarkFallback' => function () {
            $this->assert_NotificationDetailMarksRead_testDetailGuardsPrefetchAndExposesClientMarkFallback();
        },
        ];
    }

private function assert_NotificationDetailMarksRead_testDetailGuardsPrefetchAndExposesClientMarkFallback(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Controller/Backend/Notification.php');
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/view/templates/backend/Notification/detail.phtml');
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/view/statics/js/notification-center.js');
    $detailMethod = $this->extractMethodBody_NotificationDetailMarksRead($source, 'detail');
    self::assertNotSame('', $detailMethod);
    self::assertStringContainsString('allowsNotificationReadMutation', $detailMethod);
    self::assertStringContainsString('pushJustMarkedReadFlash', $detailMethod);
    self::assertStringContainsString('pullJustMarkedReadFlash', $detailMethod);
    self::assertStringContainsString('isRecentlyMarkedRead', $detailMethod);
    self::assertStringContainsString("\$notification['was_unread']", $detailMethod);
    self::assertStringContainsString("\$notification['just_marked_read']", $detailMethod);
    self::assertStringContainsString("\$notification['server_marked_read']", $detailMethod);
    self::assertStringContainsString('function allowsNotificationReadMutation', $source);
    self::assertStringContainsString('JUST_MARKED_SESSION_KEY', $source);
    self::assertStringContainsString('HTTP_SEC_PURPOSE', $source);
    self::assertStringContainsString('prefetch', $source);
    self::assertStringContainsString('prerender', $source);
    self::assertStringContainsString('HTTP_SEC_FETCH_DEST', $source);
    self::assertStringContainsString("'empty'", $source);
    self::assertStringContainsString("\$notification['was_unread']", $template);
    self::assertStringContainsString('data-w-was-unread', $template);
    self::assertStringContainsString('data-w-server-marked', $template);
    self::assertStringContainsString('data-w-mark-url', $template);
    self::assertStringContainsString('rel="nofollow"', $template);
    self::assertStringContainsString('w-notification-detail__read-badge', $template);
    self::assertStringContainsString("'→'", $template);
    self::assertStringContainsString('markDetailViewIfNeeded', $script);
    self::assertStringContainsString('data-w-was-unread', $script);
    self::assertStringContainsString('data-w-server-marked', $script);
    self::assertStringContainsString('notification_id', $script);
    self::assertStringContainsString('applyReadTransitionBadge', $script);
}

private function extractMethodBody_NotificationDetailMarksRead(string $source, string $method): string
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
}
