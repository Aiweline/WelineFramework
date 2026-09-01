<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Extends\Module\Weline_Framework\Query\PromotionAdminQueryProvider;
use Weline\Promotion\Model\PromotionCampaignRun;

final class PromotionAdminQueryProviderContractTest extends TestCase
{
    public function testDescriptorMatchesDeskAclAndOperations(): void
    {
        $provider = $this->providerWithoutConstructor(PromotionAdminQueryProvider::class);
        $descriptor = $provider->getDescriptor();
        $operations = $this->operationsByName($descriptor);

        self::assertSame('promotion_admin', $provider->getProviderName());
        self::assertSame('promotion_admin', $descriptor['provider']);
        self::assertSame('Weline_Promotion', $descriptor['module']);
        self::assertSame(
            ['deskSnapshot', 'listRuns', 'saveRun'],
            array_keys($operations),
        );

        foreach (['deskSnapshot', 'listRuns'] as $name) {
            self::assertSame('read', $operations[$name]['mode'] ?? null, $name . ' mode mismatch');
            self::assertSame('backend', $operations[$name]['auth'] ?? null, $name . ' auth mismatch');
            self::assertSame(
                [
                    'kind' => 'source',
                    'source_id' => PromotionAdminQueryProvider::ACL_SOURCE,
                ],
                $operations[$name]['backend_acl'] ?? null,
                $name . ' ACL mismatch',
            );
        }

        self::assertSame('write', $operations['saveRun']['mode'] ?? null);
        self::assertSame(
            PromotionAdminQueryProvider::ACL_SOURCE,
            $operations['saveRun']['backend_acl']['source_id'] ?? null,
        );
    }

    public function testMenuSourceMatchesDeskAcl(): void
    {
        $menuPath = dirname(__DIR__, 3) . '/etc/backend/menu.xml';
        self::assertFileExists($menuPath);
        $xml = simplexml_load_file($menuPath);
        self::assertNotFalse($xml);
        $menu = $xml->menu[0] ?? null;
        self::assertNotNull($menu);
        self::assertSame(PromotionAdminQueryProvider::ACL_SOURCE, (string)$menu['source']);
        self::assertSame('40', (string)$menu['order']);
        self::assertSame('Weline_Backend::marketing_group', (string)$menu['parent']);
    }

    public function testCampaignRunStatusesMatchLaunchGateCodes(): void
    {
        self::assertSame(
            [
                PromotionCampaignRun::STATUS_CONTINUE,
                PromotionCampaignRun::STATUS_PAUSE,
                PromotionCampaignRun::STATUS_REPAIR,
                PromotionCampaignRun::STATUS_REVIEW,
            ],
            PromotionCampaignRun::allowedStatuses(),
        );
    }

    public function testUnknownOperationFailsClosed(): void
    {
        $provider = $this->providerWithoutConstructor(PromotionAdminQueryProvider::class);
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
    private function providerWithoutConstructor(string $class): object
    {
        $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf($class, $provider);

        return $provider;
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, array<string, mixed>>
     */
    private function operationsByName(array $descriptor): array
    {
        self::assertIsArray($descriptor['operations'] ?? null);
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            self::assertIsArray($operation);
            $operations[(string)$operation['name']] = $operation;
        }

        return $operations;
    }
}
