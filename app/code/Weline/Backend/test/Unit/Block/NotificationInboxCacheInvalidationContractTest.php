<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Block;

use PHPUnit\Framework\TestCase;

/**
 * 顶栏通知 Block 不得在普通请求上读取 warmup 身份；标记已读必须失效 chrome 缓存。
 */
final class NotificationInboxCacheInvalidationContractTest extends TestCase
{
    public function testWarmupIdentityIsGuardedByInternalRequestMarker(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Block/System/Notification.php'
        );

        $guard = strpos($source, 'BackendWarmupContext::isInternalWarmupRequest($request)');
        $warmupRead = strpos($source, 'BackendWarmupContext::currentUserId()');
        $sessionRead = strpos($source, 'createBackendSession()');

        self::assertIsInt($guard);
        self::assertIsInt($warmupRead);
        self::assertIsInt($sessionRead);
        self::assertLessThan($warmupRead, $guard);
        self::assertLessThan($sessionRead, $warmupRead);
        self::assertStringContainsString('INBOX_REVISION_SESSION_KEY', $source);
        self::assertStringContainsString('public static function clearCache', $source);
    }

    public function testMarkAsReadInvalidatesInboxPresentation(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/NotificationService.php'
        );
        $partials = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Theme/Block/Partials.php'
        );
        $script = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/notification-center.js'
        );

        self::assertStringContainsString('invalidateInboxPresentation', $service);
        self::assertStringContainsString('NotificationBlock::clearCache', $service);
        self::assertStringContainsString('Partials::clearOutputCache', $service);
        self::assertStringContainsString('INBOX_REVISION_SESSION_KEY', $service);
        self::assertStringContainsString('backend_notification_inbox_rev', $partials);
        self::assertStringContainsString('clearNotificationBadges', $script);
        self::assertStringContainsString('w-notification-trigger__badge', $script);
    }
}
