<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class B2BIdentityHubContractTest extends TestCase
{
    public function testIdentityHubPartialAndNavMarkers(): void
    {
        $root = dirname(__DIR__, 7);
        $hub = $root . '/app/code/Weline/B2B/view/templates/frontend/partials/account-identity-hub.phtml';
        $content = $root . '/app/code/Weline/B2B/view/hooks/account.sidebar.content.phtml';
        $nav = $root . '/app/code/Weline/B2B/view/hooks/account.sidebar.group.commerce.phtml';
        self::assertFileExists($hub);
        $hubSrc = (string)file_get_contents($hub);
        $contentSrc = (string)file_get_contents($content);
        $navSrc = (string)file_get_contents($nav);
        self::assertStringContainsString('data-testid="b2b-identity-hub"', $hubSrc);
        self::assertStringContainsString('listForCustomer', $hubSrc);
        self::assertStringContainsString('b2b-identity-hub__hint', $hubSrc);
        self::assertStringContainsString('挂单已完成', $hubSrc);
        self::assertStringContainsString('定金已付', $hubSrc);
        self::assertStringContainsString('尾款已结', $hubSrc);
        self::assertStringContainsString('查看订单', $hubSrc);
        self::assertStringContainsString('$chatUnread', $hubSrc);
        self::assertStringNotContainsString('data-testid="b2b-identity-open-chat"', $hubSrc);
        self::assertStringNotContainsString('data-b2b-open-order-chat', $hubSrc);
        self::assertStringContainsString('account-identity-hub.phtml', $contentSrc);
        self::assertStringContainsString('等级、挂单跟进与订单沟通', $navSrc);
        self::assertStringNotContainsString('data-testid="account-nav-b2b-order-chat"', $navSrc);

        $accordion = $root . '/app/code/Weline/B2B/view/templates/frontend/partials/order-chat-accordion.phtml';
        $accSrc = (string)file_get_contents($accordion);
        self::assertStringContainsString('b2b-order-chat-unread', $accSrc);
        self::assertStringContainsString('$chatUnread', $accSrc);
    }
}
