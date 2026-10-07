<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * 详情读态：真实打开显示「未读→已读」；prefetch/prerender 不得落库标读，
 * 否则点「下一条」只会看到「已读」。
 */
final class NotificationDetailMarksReadContractTest extends TestCase
{
    public function testDetailGuardsPrefetchAndExposesClientMarkFallback(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Notification.php'
        );
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Notification/detail.phtml'
        );
        $script = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/notification-center.js'
        );

        $detailMethod = $this->extractMethodBody($source, 'detail');
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

    private function extractMethodBody(string $source, string $method): string
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
