<?php

declare(strict_types=1);

namespace Weline\Feed\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Feed\Service\FeedScopeMailboxService;
use Weline\Mail\Model\MailAccount;

final class FeedScopeMailboxContractTest extends TestCase
{
    public function testFeedMailboxIsASystemMailbox(): void
    {
        self::assertSame('feed-', MailAccount::SYSTEM_LOCAL_FEED_PREFIX);
        self::assertTrue(MailAccount::isFeedMailboxLocalPart('feed-default'));
        self::assertTrue(MailAccount::isGeneratedSystemLocalPart('feed-default'));
        self::assertFalse(MailAccount::isReservedSystemLocalPart('feed-default'));
        self::assertFalse(MailAccount::isFeedMailboxLocalPart('feed'));
    }

    public function testFeedMailboxServiceIsScopedByWebsite(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/Service/FeedScopeMailboxService.php'
        );
        self::assertStringContainsString("LOCAL_PREFIX = 'feed'", $source);
        self::assertStringContainsString('function getScopeFeedMailbox', $source);
        self::assertStringContainsString('function ensureScopeFeedMailbox', $source);
        self::assertStringContainsString('function resetScopeFeedMailboxPassword', $source);
        self::assertStringContainsString("w_query('mail', 'ensureMailbox'", $source);
        self::assertStringContainsString("w_query('mail', 'resetMailboxPassword'", $source);
        self::assertStringContainsString('website_id', $source);
        self::assertTrue(\class_exists(FeedScopeMailboxService::class));
    }

    public function testCatalogExposesFeedMailboxOperations(): void
    {
        $provider = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/extends/module/Weline_Framework/Query/FeedAdminQueryProvider.php'
        );
        foreach (['getScopeFeedMailbox', 'ensureScopeFeedMailbox', 'resetScopeFeedMailboxPassword'] as $operation) {
            self::assertStringContainsString("'" . $operation . "'", $provider);
        }

        $template = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/view/templates/backend/Catalog/index.phtml'
        );
        self::assertStringContainsString('data-testid="feed-scope-mailbox"', $template);
        self::assertStringContainsString('getScopeFeedMailbox', $template);
        self::assertStringContainsString('ensureScopeFeedMailbox', $template);

        $script = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/view/statics/backend/feed-catalog.js'
        );
        self::assertStringContainsString('data-feed-scope-mailbox', $script);
        self::assertStringContainsString("callOp('ensureScopeFeedMailbox'", $script);
        self::assertStringContainsString("callOp('resetScopeFeedMailboxPassword'", $script);
    }
}
