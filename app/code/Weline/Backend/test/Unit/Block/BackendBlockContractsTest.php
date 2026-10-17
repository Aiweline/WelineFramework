<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Block;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendBlockContractsPass().
 */
final class BackendBlockContractsTest extends TestCase
{
    public function testAllBackendBlockContractsPass(): void
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
            'NotificationInboxCacheInvalidation::testWarmupIdentityIsGuardedByInternalRequestMarker' => function () {
            $this->assert_NotificationInboxCacheInvalidation_testWarmupIdentityIsGuardedByInternalRequestMarker();
        },
            'NotificationInboxCacheInvalidation::testMarkAsReadInvalidatesInboxPresentation' => function () {
            $this->assert_NotificationInboxCacheInvalidation_testMarkAsReadInvalidatesInboxPresentation();
        },
        ];
    }

private function assert_NotificationInboxCacheInvalidation_testWarmupIdentityIsGuardedByInternalRequestMarker(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Block/System/Notification.php');
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

private function assert_NotificationInboxCacheInvalidation_testMarkAsReadInvalidatesInboxPresentation(): void
{
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Service/NotificationService.php');
    $partials = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Block/Partials.php');
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/view/statics/js/notification-center.js');
    self::assertStringContainsString('invalidateInboxPresentation', $service);
    self::assertStringContainsString('NotificationBlock::clearCache', $service);
    self::assertStringContainsString('Partials::clearBackendPartialAuthContextMemo', $service);
    self::assertStringContainsString('Partials::clearOutputCache', $service);
    self::assertStringContainsString('INBOX_REVISION_SESSION_KEY', $service);
    self::assertStringContainsString('backend_notification_inbox_rev', $partials);
    self::assertStringContainsString('clearBackendPartialAuthContextMemo', $partials);
    self::assertStringContainsString('theme.backend_partial_auth_context.user', $partials);
    self::assertStringContainsString('clearNotificationBadges', $script);
    self::assertStringContainsString('w-notification-trigger__badge', $script);
    $bumpRev = strpos($service, 'INBOX_REVISION_SESSION_KEY');
    $clearAuthMemo = strpos($service, 'Partials::clearBackendPartialAuthContextMemo');
    $clearOutput = strpos($service, 'Partials::clearOutputCache');
    self::assertIsInt($bumpRev);
    self::assertIsInt($clearAuthMemo);
    self::assertIsInt($clearOutput);
    // Same-response chrome miss requires: bump rev → drop auth memo → clear HTML.
    self::assertLessThan($clearAuthMemo, $bumpRev);
    self::assertLessThan($clearOutput, $clearAuthMemo);
}
}
