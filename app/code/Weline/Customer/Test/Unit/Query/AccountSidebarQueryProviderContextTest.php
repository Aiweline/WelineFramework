<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Extends\Module\Weline_Framework\Query\AccountQueryProvider;

final class AccountSidebarQueryProviderContextTest extends TestCase
{
    public function testSidebarHookReceivesTheAuthenticatedCustomerContext(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(AccountQueryProvider::class))->getFileName()
        );

        $setUserAt = strpos($source, '$template->setData(\'user\', $user);');
        $renderHookAt = strpos($source, '$template->getHook(\'account.sidebar.content\')');

        self::assertNotFalse($setUserAt);
        self::assertNotFalse($renderHookAt);
        self::assertLessThan($renderHookAt, $setUserAt);
    }

    public function testSidebarOperationAllowsAnOrderDetailUuid(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("'name' => 'getSidebarSection'", $source);
        self::assertStringContainsString("'order_uuid' => ['type' => 'string', 'max_length' => 64]", $source);
        self::assertStringContainsString("'scope_store' => ['type' => 'int', 'min' => 0]", $source);
        self::assertStringContainsString("'scope_channel' => ['type' => 'int', 'min' => 0]", $source);

        $pos = strpos($source, "'name' => 'getSidebarSection'");
        self::assertNotFalse($pos);
        $slice = substr($source, (int) $pos, 900);
        self::assertStringContainsString("'scope_store'", $slice);
        self::assertStringContainsString("'scope_channel'", $slice);
        self::assertStringContainsString("'cache_ttl' => 0", $slice);
    }

    public function testDefaultAccountTemplateForwardsOnlySupportedSidebarContext(): void
    {
        $accountIndexJs = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/account-index.js'
        );

        self::assertStringContainsString('var sidebarPayload = { section: sectionName };', $accountIndexJs);
        self::assertStringContainsString('sidebarPayload.order_uuid = sidebarQuery.order_uuid;', $accountIndexJs);
        self::assertStringContainsString('sidebarPayload.scope_store = sidebarQuery.scope_store;', $accountIndexJs);
        self::assertStringContainsString('sidebarPayload.scope_channel = sidebarQuery.scope_channel;', $accountIndexJs);
        self::assertStringContainsString('sanitizeAccountLocationSearch();', $accountIndexJs);
        self::assertStringContainsString('function ensureOrdersLocatedForOrderUuid(', $accountIndexJs);
        self::assertStringContainsString('function locateAccountOrder(', $accountIndexJs);
        self::assertStringContainsString('data-requested-order-uuid', $accountIndexJs);
        self::assertStringContainsString("targetId = 'orders';", $accountIndexJs);
        self::assertStringNotContainsString(
            'Object.assign({ section: sectionName }, parseAccountHash().query)',
            $accountIndexJs,
        );
    }
}
