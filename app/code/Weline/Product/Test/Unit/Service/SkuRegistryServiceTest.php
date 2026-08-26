<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Model\SkuAlias;
use Weline\Product\Model\SkuRegistry;
use Weline\Product\Service\SkuIdentityConflictException;
use Weline\Product\Service\SkuRegistryService;

/**
 * TEST-P2A-03（逻辑层）：同 SKU 异 hash 冲突；同 hash 幂等；ref_count 保护孤儿清理。
 */
final class SkuRegistryServiceTest extends TestCase
{
    public function testNormalizeSkuRejectsEmpty(): void
    {
        $service = $this->service(fn (): SkuRegistry => $this->registryMock([]));
        $this->expectException(\InvalidArgumentException::class);
        $service->normalizeSku('  ');
    }

    public function testClaimLockedIdempotentSameHash(): void
    {
        $hash = str_repeat('ab', 32);
        $existing = $this->registryMock([
            'registry_id' => 1,
            'sku' => 'SKU-1',
            'global_product_uuid' => 'p-uuid',
            'global_offer_uuid' => 'o-uuid',
            'request_hash' => $hash,
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);

        $service = $this->service(fn (): SkuRegistry => $existing);
        $first = $service->claimLocked('SKU-1', $hash);
        $second = $service->claimLocked('SKU-1', $hash);
        self::assertSame($first->registryId, $second->registryId);
        self::assertSame('SKU-1', $second->sku);
        self::assertSame($hash, $second->requestHash);
    }

    public function testClaimLockedConflictsOnDifferentHash(): void
    {
        $existing = $this->registryMock([
            'registry_id' => 2,
            'sku' => 'SKU-2',
            'global_product_uuid' => 'p2',
            'global_offer_uuid' => 'o2',
            'request_hash' => str_repeat('11', 32),
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);

        $service = $this->service(fn (): SkuRegistry => $existing);
        $this->expectException(SkuIdentityConflictException::class);
        $service->claimLocked('SKU-2', str_repeat('22', 32));
    }

    public function testClaimLockedRecoversUniqueRaceAndReturnsWinner(): void
    {
        $hash = str_repeat('44', 32);
        $missing = $this->registryMock([]);
        $loser = $this->registryMock(
            [],
            new \RuntimeException('simulated unique race'),
        );
        $winner = $this->registryMock([
            'registry_id' => 4,
            'sku' => 'SKU-RACE',
            'global_product_uuid' => 'race-product',
            'global_offer_uuid' => 'race-offer',
            'request_hash' => $hash,
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $queue = [$missing, $loser, $winner];

        $service = $this->service(
            static function () use (&$queue): SkuRegistry {
                return array_shift($queue);
            },
            fn (): SkuAlias => $this->aliasMock(),
        );

        $identity = $service->claimLocked('SKU-RACE', $hash);

        self::assertSame(4, $identity->registryId);
        self::assertSame('SKU-RACE', $identity->sku);
        self::assertSame([], $queue);
    }

    public function testClaimLockedRejectsTombstonedCanonicalIdentity(): void
    {
        $hash = str_repeat('55', 32);
        $tombstoned = $this->registryMock([
            'registry_id' => 5,
            'sku' => 'SKU-DEAD',
            'global_product_uuid' => 'dead-product',
            'global_offer_uuid' => 'dead-offer',
            'request_hash' => $hash,
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_TOMBSTONED,
        ]);
        $service = $this->service(fn (): SkuRegistry => $tombstoned);

        try {
            $service->claimLocked('SKU-DEAD', $hash);
            self::fail('expected tombstone conflict');
        } catch (SkuIdentityConflictException $e) {
            self::assertSame('sku_identity_tombstoned', $e->errorCode());
        }
    }

    public function testClaimLockedRejectsHashAboveDeclaredMaximum(): void
    {
        $service = $this->service(fn (): SkuRegistry => $this->registryMock([]));
        $this->expectException(\InvalidArgumentException::class);
        $service->claimLocked('SKU-HASH', str_repeat('a', 129));
    }

    public function testIncrementRefCountRetriesAfterCasMiss(): void
    {
        $firstAttemptToken = str_repeat('a', 64);
        $secondAttemptToken = str_repeat('b', 64);
        $otherWriterToken = str_repeat('c', 64);
        $before = $this->registryMock([
            'registry_id' => 6,
            'ref_count' => 0,
            'cas_token' => '',
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $casMiss = $this->registryMock([], null, false);
        $afterCasMiss = $this->registryMock([
            'registry_id' => 6,
            'ref_count' => 1,
            'cas_token' => $otherWriterToken,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $retryRead = $this->registryMock([
            'registry_id' => 6,
            'ref_count' => 1,
            'cas_token' => $otherWriterToken,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $casSuccess = $this->registryMock([], null, true);
        $verified = $this->registryMock([
            'registry_id' => 6,
            'ref_count' => 2,
            'cas_token' => $secondAttemptToken,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $queue = [$before, $casMiss, $afterCasMiss, $retryRead, $casSuccess, $verified];
        $tokenQueue = [$firstAttemptToken, $secondAttemptToken];

        $service = $this->service(
            static function () use (&$queue): SkuRegistry {
                return array_shift($queue);
            },
            null,
            static function () use (&$tokenQueue): string {
                return array_shift($tokenQueue);
            },
        );

        self::assertSame(2, $service->incrementRefCount(6));
        self::assertSame([], $queue);
        self::assertSame([], $tokenQueue);
    }

    public function testCleanupCasMissFailsClosedWhenReferenceAppears(): void
    {
        $attemptToken = str_repeat('d', 64);
        $before = $this->registryMock([
            'registry_id' => 7,
            'sku' => 'SKU-CLEANUP-RACE',
            'ref_count' => 0,
            'cas_token' => '',
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $casMiss = $this->registryMock([], null, false);
        $verificationRead = $this->registryMock([
            'registry_id' => 7,
            'sku' => 'SKU-CLEANUP-RACE',
            'ref_count' => 1,
            'cas_token' => str_repeat('e', 64),
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $conflictRead = $this->registryMock([
            'registry_id' => 7,
            'sku' => 'SKU-CLEANUP-RACE',
            'ref_count' => 1,
            'cas_token' => str_repeat('e', 64),
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $queue = [$before, $casMiss, $verificationRead, $conflictRead];

        $service = $this->service(
            static function () use (&$queue): SkuRegistry {
                return array_shift($queue);
            },
            null,
            static fn (): string => $attemptToken,
        );

        try {
            $service->cleanupOrphanBySku('SKU-CLEANUP-RACE');
            self::fail('expected cleanup/reference conflict');
        } catch (SkuIdentityConflictException $e) {
            self::assertSame('sku_identity_still_referenced', $e->errorCode());
            self::assertSame(1, $e->context()['ref_count']);
        }
    }

    public function testRenameNormalizesConcurrentTargetWinner(): void
    {
        $source = $this->registryMock([
            'registry_id' => 8,
            'sku' => 'SKU-FROM',
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $targetMissing = $this->registryMock([]);
        $targetWinner = $this->registryMock([
            'registry_id' => 9,
            'sku' => 'SKU-TO',
            'ref_count' => 0,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $registryQueue = [$source, $targetMissing, $targetWinner];
        $aliasQueue = [
            $this->aliasMock(),
            $this->aliasMock([], new \RuntimeException('simulated unique race')),
        ];
        $service = $this->service(
            static function () use (&$registryQueue): SkuRegistry {
                return array_shift($registryQueue);
            },
            static function () use (&$aliasQueue): SkuAlias {
                return array_shift($aliasQueue);
            },
        );

        try {
            $service->renameSku('SKU-FROM', 'SKU-TO');
            self::fail('expected rename target conflict');
        } catch (SkuIdentityConflictException $e) {
            self::assertSame('sku_rename_target_taken', $e->errorCode());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testCleanupOrphanRefusesPositiveRefCount(): void
    {
        $existing = $this->registryMock([
            'registry_id' => 3,
            'sku' => 'SKU-3',
            'global_product_uuid' => 'p3',
            'global_offer_uuid' => 'o3',
            'request_hash' => str_repeat('33', 32),
            'ref_count' => 2,
            'status' => SkuRegistry::STATUS_ACTIVE,
        ]);
        $alias = $this->aliasMock();

        $service = $this->service(
            fn (): SkuRegistry => $existing,
            fn (): SkuAlias => $alias,
        );
        try {
            $service->cleanupOrphanBySku('SKU-3');
            self::fail('expected conflict');
        } catch (SkuIdentityConflictException $e) {
            self::assertSame('sku_identity_still_referenced', $e->errorCode());
        }
    }

    public function testProductIdentityDtoShape(): void
    {
        $dto = new ProductIdentity(1, 'A', 'pu', 'ou', str_repeat('aa', 32), 0);
        self::assertSame(1, $dto->toArray()['registry_id']);
    }

    /**
     * @param (\Closure(): SkuRegistry)|null $registryFactory
     * @param (\Closure(): SkuAlias)|null $aliasFactory
     */
    private function service(
        ?callable $registryFactory = null,
        ?callable $aliasFactory = null,
        ?callable $casTokenFactory = null,
    ): SkuRegistryService
    {
        $connection = $this->createMock(ConnectionFactory::class);
        $tx = $this->createMock(DatabaseTransactionRunnerInterface::class);
        $tx->method('run')->willReturnCallback(
            static function (ConnectionFactory $c, callable $cb): mixed {
                return $cb();
            }
        );

        $policy = $this->createStub(ProductIdentityCutoverPolicyInterface::class);
        $policy->method('mode')->willReturn(ProductIdentityCutoverPolicyInterface::MODE_LEGACY);
        $policy->method('legacyWritesAllowed')->willReturn(true);

        return new SkuRegistryService(
            $connection,
            $tx,
            $policy,
            $registryFactory,
            $aliasFactory,
            $casTokenFactory,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registryMock(
        array $data,
        ?\Throwable $saveError = null,
        mixed $queryData = null,
    ): SkuRegistry
    {
        $row = $this->getMockBuilder(SkuRegistry::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId',
                'getData',
                'setData',
                'save',
                'delete',
                'clear',
                'getQuery',
            ])
            ->addMethods(['where', 'find', 'fetch', 'select', 'fetchArray', 'order', 'fields'])
            ->getMock();
        $row->method('getId')->willReturn($data['registry_id'] ?? null);
        $row->method('getData')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($data): mixed {
                return $data[$key] ?? $default;
            }
        );
        $row->method('clear')->willReturnSelf();
        $row->method('where')->willReturnSelf();
        $row->method('find')->willReturnSelf();
        $row->method('fetch')->willReturnSelf();
        $row->method('setData')->willReturnSelf();
        if ($queryData !== null) {
            $query = $this->createMock(QueryInterface::class);
            $query->method('where')->willReturnSelf();
            $query->method('update')->willReturnSelf();
            $query->method('fetch')->willReturn($queryData);
            $row->method('getQuery')->willReturn($query);
        }
        if ($saveError !== null) {
            $row->method('save')->willThrowException($saveError);
        } else {
            $row->method('save')->willReturn(true);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function aliasMock(array $data = [], ?\Throwable $saveError = null): SkuAlias
    {
        $alias = $this->getMockBuilder(SkuAlias::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getData', 'setData', 'save', 'delete', 'clear'])
            ->addMethods(['where', 'find', 'fetch', 'select', 'fetchArray'])
            ->getMock();
        $alias->method('clear')->willReturnSelf();
        $alias->method('where')->willReturnSelf();
        $alias->method('find')->willReturnSelf();
        $alias->method('fetch')->willReturnSelf();
        $alias->method('setData')->willReturnSelf();
        $alias->method('getId')->willReturn($data['alias_id'] ?? null);
        $alias->method('getData')->willReturnCallback(
            static function (string $key, mixed $default = null) use ($data): mixed {
                return $data[$key] ?? $default;
            }
        );
        if ($saveError !== null) {
            $alias->method('save')->willThrowException($saveError);
        } else {
            $alias->method('save')->willReturn(true);
        }
        return $alias;
    }
}
