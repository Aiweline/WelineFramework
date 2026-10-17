<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Extends\Module\Weline_Framework\Query\PromotionAdminQueryProvider;
use Weline\Promotion\Model\PromotionCampaignRun;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionQueryContractsPass().
 */
final class PromotionQueryContractsTest extends TestCase
{
    public function testAllPromotionQueryContractsPass(): void
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
            'PromotionAdminQueryProvider::testDescriptorMatchesDeskAclAndOperations' => function () {
            $this->assert_PromotionAdminQueryProvider_testDescriptorMatchesDeskAclAndOperations();
        },
            'PromotionAdminQueryProvider::testMenuSourceMatchesDeskAcl' => function () {
            $this->assert_PromotionAdminQueryProvider_testMenuSourceMatchesDeskAcl();
        },
            'PromotionAdminQueryProvider::testCampaignRunStatusesMatchLaunchGateCodes' => function () {
            $this->assert_PromotionAdminQueryProvider_testCampaignRunStatusesMatchLaunchGateCodes();
        },
            'PromotionAdminQueryProvider::testUnknownOperationFailsClosed' => function () {
            $this->assert_PromotionAdminQueryProvider_testUnknownOperationFailsClosed();
        },
        ];
    }

private function assert_PromotionAdminQueryProvider_testDescriptorMatchesDeskAclAndOperations(): void
{
    $provider = $this->providerWithoutConstructor_PromotionAdminQueryProvider(PromotionAdminQueryProvider::class);
    $descriptor = $provider->getDescriptor();
    $operations = $this->operationsByName_PromotionAdminQueryProvider($descriptor);
    self::assertSame('promotion_admin', $provider->getProviderName());
    self::assertSame('promotion_admin', $descriptor['provider']);
    self::assertSame('Weline_Promotion', $descriptor['module']);
    self::assertSame(['deskSnapshot', 'listRuns', 'saveRun'], array_keys($operations));
    foreach (['deskSnapshot', 'listRuns'] as $name) {
        self::assertSame('read', $operations[$name]['mode'] ?? null, $name . ' mode mismatch');
        self::assertSame('backend', $operations[$name]['auth'] ?? null, $name . ' auth mismatch');
        self::assertSame(['kind' => 'source', 'source_id' => PromotionAdminQueryProvider::ACL_SOURCE], $operations[$name]['backend_acl'] ?? null, $name . ' ACL mismatch');
    }
    self::assertSame('write', $operations['saveRun']['mode'] ?? null);
    self::assertSame(PromotionAdminQueryProvider::ACL_SOURCE, $operations['saveRun']['backend_acl']['source_id'] ?? null);
}

private function assert_PromotionAdminQueryProvider_testMenuSourceMatchesDeskAcl(): void
{
    $menuPath = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/etc/backend/menu.xml';
    self::assertFileExists($menuPath);
    $xml = simplexml_load_file($menuPath);
    self::assertNotFalse($xml);
    $menu = $xml->menu[0] ?? null;
    self::assertNotNull($menu);
    self::assertSame(PromotionAdminQueryProvider::ACL_SOURCE, (string) $menu['source']);
    self::assertSame('40', (string) $menu['order']);
    self::assertSame('Weline_Backend::marketing_group', (string) $menu['parent']);
}

private function assert_PromotionAdminQueryProvider_testCampaignRunStatusesMatchLaunchGateCodes(): void
{
    self::assertSame([PromotionCampaignRun::STATUS_CONTINUE, PromotionCampaignRun::STATUS_PAUSE, PromotionCampaignRun::STATUS_REPAIR, PromotionCampaignRun::STATUS_REVIEW], PromotionCampaignRun::allowedStatuses());
}

private function assert_PromotionAdminQueryProvider_testUnknownOperationFailsClosed(): void
{
    $provider = $this->providerWithoutConstructor_PromotionAdminQueryProvider(PromotionAdminQueryProvider::class);
    $result = $provider->execute('takeover', ['force' => true]);
    self::assertIsArray($result);
    self::assertFalse($result['success']);
    self::assertNotEmpty($result['message']);
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
private function providerWithoutConstructor_PromotionAdminQueryProvider(string $class): object
{
    $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    self::assertInstanceOf($class, $provider);
    return $provider;
}

/**
 * @param array<string, mixed> $descriptor
 * @return array<string, array<string, mixed>>
 */
private function operationsByName_PromotionAdminQueryProvider(array $descriptor): array
{
    self::assertIsArray($descriptor['operations'] ?? null);
    $operations = [];
    foreach ($descriptor['operations'] as $operation) {
        self::assertIsArray($operation);
        $operations[(string) $operation['name']] = $operation;
    }
    return $operations;
}
}
