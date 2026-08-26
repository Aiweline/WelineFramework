<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductAdminResult;
use Weline\Product\Api\Data\ProductAdminSnapshot;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Extends\Module\Weline_Framework\Query\ProductAdminQueryProvider;

final class ProductAdminQueryProviderTest extends TestCase
{
    public function testDescriptorIsBackendAclProtectedResource(): void
    {
        $provider = new ProductAdminQueryProvider(
            $this->createStub(ProductAdminReadInterface::class),
            $this->createStub(ProductAdminCommandInterface::class),
        );

        $descriptor = $provider->getDescriptor();

        self::assertSame('product_admin', $descriptor['provider']);
        self::assertCount(4, $descriptor['operations']);
        foreach ($descriptor['operations'] as $operation) {
            self::assertTrue($operation['frontend']);
            self::assertTrue($operation['backend']);
            self::assertFalse($operation['external']);
            self::assertSame('backend', $operation['auth']);
            self::assertSame(
                ProductAdminQueryProvider::ACL_SOURCE,
                $operation['backend_acl']['source_id'],
            );
        }
        $modes = array_column($descriptor['operations'], 'mode', 'name');
        self::assertSame('read', $modes['search']);
        self::assertSame('write', $modes['command']);
    }

    public function testDelegatesReadsAndNormalizesCommand(): void
    {
        $snapshot = new ProductAdminSnapshot(
            websiteId: 2,
            identity: ['global_product_uuid' => '10000000-0000-4000-8000-000000000001'],
            product: [],
            offers: [],
            attributes: [],
            attributeCatalog: [],
            prices: [],
            categories: [],
            media: [],
            stores: [],
            provider: [],
            diagnostics: [],
            permissions: [],
        );
        $reader = $this->createMock(ProductAdminReadInterface::class);
        $reader->expects(self::once())
            ->method('search')
            ->with(2, ['status' => 'draft'])
            ->willReturn([['product_id' => 7]]);
        $reader->expects(self::once())
            ->method('snapshot')
            ->willReturn($snapshot);

        $commands = $this->createMock(ProductAdminCommandInterface::class);
        $commands->expects(self::once())
            ->method('execute')
            ->with(self::callback(static fn($command): bool => $command->action === 'validate'
                && $command->websiteId === 2
                && $command->actorId === 0
                && $command->globalProductUuid === '10000000-0000-4000-8000-000000000001'
            ))
            ->willReturn(ProductAdminResult::ok(['diagnostics' => ['valid' => true]]));

        $provider = new ProductAdminQueryProvider($reader, $commands);
        self::assertSame(
            [['product_id' => 7]],
            $provider->execute('search', [
                'website_id' => '2',
                'filters' => ['status' => 'draft'],
            ])['items'],
        );
        self::assertSame(
            2,
            $provider->execute('snapshot', [
                'website_id' => 2,
                'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
            ])['snapshot']['website_id'],
        );
        $result = $provider->execute('command', ['command' => [
            'action' => 'validate',
            'website_id' => 2,
            'global_product_uuid' => '10000000-0000-4000-8000-000000000001',
            'expected_version' => 1,
            'request_hash' => str_repeat('a', 64),
            'actor_id' => 999,
            'payload' => [],
        ]]);
        self::assertTrue($result['success']);
        self::assertTrue($result['data']['diagnostics']['valid']);
    }
}
