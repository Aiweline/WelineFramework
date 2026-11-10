<?php

declare(strict_types=1);

namespace Weline\CacheManager\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Test\TestCore;

class StorefrontWarmupQueryProviderTest extends TestCore
{
    public function testStatusOperationReturnsLedgerSnapshot(): void
    {
        $provider = new CacheManagerAdminQueryProvider();
        $result = $provider->execute('storefrontWarmupStatus');

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['status']);
        $this->assertArrayHasKey('verdict', $result['status']);
        $this->assertArrayHasKey('phases', $result['status']);
        $this->assertArrayHasKey('load', $result['status']);
        $this->assertSame(['A', 'B', 'C', 'D'], \array_keys($result['status']['phases']));
    }

    public function testSaveOperationRejectsAbortBelowStartWithoutWriting(): void
    {
        $provider = new CacheManagerAdminQueryProvider();
        $result = $provider->execute('saveStorefrontWarmupSettings', [
            'busy_start_pct' => 90,
            'busy_abort_pct' => 10,
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testDescriptorDeclaresBackendAclForBothWarmupOperations(): void
    {
        $descriptor = (new CacheManagerAdminQueryProvider())->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        $this->assertSame('read', $operations['storefrontWarmupStatus']['mode']);
        $this->assertSame('write', $operations['saveStorefrontWarmupSettings']['mode']);
        foreach (['storefrontWarmupStatus', 'saveStorefrontWarmupSettings'] as $name) {
            $this->assertTrue($operations[$name]['frontend']);
            $this->assertTrue($operations[$name]['backend']);
            $this->assertSame('backend', $operations[$name]['auth']);
            $this->assertSame('source', $operations[$name]['backend_acl']['kind']);
            $this->assertStringStartsWith('Weline_CacheManager::', $operations[$name]['backend_acl']['source_id']);
        }
    }
}
