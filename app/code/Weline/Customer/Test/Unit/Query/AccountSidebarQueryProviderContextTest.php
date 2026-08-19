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
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(AccountQueryProvider::class))->getFileName()
        );

        self::assertMatchesRegularExpression(
            "/'name' => 'getSidebarSection'.*?'cache_ttl' => 0.*?'params' => \\[.*?'section'.*?'order_uuid' => \\['type' => 'string', 'max_length' => 64\\]/s",
            $source,
        );
    }

    public function testDefaultAccountTemplateForwardsOnlySupportedSidebarContext(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml'
        );

        self::assertStringContainsString('var sidebarQuery = parseAccountHash().query;', $template);
        self::assertStringContainsString('sidebarPayload.order_uuid = sidebarQuery.order_uuid;', $template);
        self::assertStringNotContainsString(
            'Object.assign({ section: sectionName }, parseAccountHash().query)',
            $template,
        );
    }
}
