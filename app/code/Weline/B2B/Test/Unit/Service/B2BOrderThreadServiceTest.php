<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\B2BOrderMessageRecord;
use Weline\B2B\Model\B2BOrderThreadRecord;
use Weline\B2B\Service\B2BOrderThreadService;

final class B2BOrderThreadServiceTest extends TestCase
{
    public function testOpenSendMarkSeenUnread(): void
    {
        $svc = B2BOrderThreadService::forTesting(static fn (): int => 1_700_000_300);
        $thread = $svc->openOrCreate('ord-chat-1', '9', 1);
        self::assertNotSame('', $thread['thread_id'] ?? '');
        $svc->send($thread['thread_id'], B2BOrderMessageRecord::ROLE_MERCHANT, 'hello buyer');
        self::assertSame(1, $svc->countUnreadForCustomer(9, 1));
        $svc->markSeen($thread['thread_id'], B2BOrderMessageRecord::ROLE_CUSTOMER, '9');
        self::assertSame(0, $svc->countUnreadForCustomer(9, 1));
        $msgs = $svc->listMessages($thread['thread_id'], 0);
        self::assertCount(1, $msgs);
        self::assertSame('hello buyer', $msgs[0]['body_text'] ?? null);
    }

    public function testSignalAndQueryAndHookContracts(): void
    {
        $provider = dirname(__DIR__, 3) . '/extends/module/Weline_Customer/AccountMenuSignalProvider/B2BOrderChatMenuSignalProvider.php';
        self::assertFileExists($provider);
        $src = (string) file_get_contents($provider);
        self::assertStringContainsString('b2b.order_chat', $src);
        self::assertSame('b2b.order_chat', B2BOrderThreadRecord::MENU_SIGNAL_CODE);

        $query = (string) file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/B2BQueryProvider.php');
        self::assertStringContainsString("'orderChat.open'", $query);
        self::assertStringContainsString("'orderChat.messages'", $query);
        self::assertStringContainsString("'orderChat.send'", $query);
        self::assertStringContainsString("'orderChat.markSeen'", $query);

        $sidebar = (string) file_get_contents(dirname(__DIR__, 3) . '/view/hooks/account.sidebar.group.commerce.phtml');
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml');
        foreach ([$sidebar, $header] as $hook) {
            self::assertStringContainsString('data-account-menu-signal', $hook);
            self::assertDoesNotMatchRegularExpression('/data-account-menu-signal-badge[^>]*>\s*\d+/', $hook);
        }
    }
}
