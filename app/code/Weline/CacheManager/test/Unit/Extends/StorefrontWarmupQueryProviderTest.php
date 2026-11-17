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
        $this->assertArrayHasKey('scope', $result['status']);
        $this->assertArrayHasKey('sites', $result['status']);
        $this->assertTrue($result['status']['scope']['is_all']);
    }

    public function testStatusOperationAcceptsScopeParam(): void
    {
        $provider = new CacheManagerAdminQueryProvider();
        $result = $provider->execute('storefrontWarmupStatus', ['scope' => 'default']);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['status']['scope']['is_all']);
        $this->assertSame('default', $result['status']['scope']['value']);
    }

    public function testDescriptorDeclaresScopeParam(): void
    {
        $descriptor = (new CacheManagerAdminQueryProvider())->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        $this->assertSame('read', $operations['storefrontWarmupStatus']['mode']);
        $this->assertSame('write', $operations['saveStorefrontWarmupSettings']['mode']);
        $params = $operations['storefrontWarmupStatus']['params'];
        $this->assertContains('scope', \array_column($params, 'name'));
    }
}
