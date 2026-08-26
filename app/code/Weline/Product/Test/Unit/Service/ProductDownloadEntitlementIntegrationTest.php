<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Service\DatabaseTransactionRunner;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Api\Data\OrderReadResult;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Product\Api\ProductDownloadEntitlementException;
use Weline\Product\Model\DownloadEntitlement;
use Weline\Product\Model\DownloadEntitlementAudit;
use Weline\Product\Service\ProductDownloadEntitlementService;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class ProductDownloadEntitlementIntegrationTest extends TestCase
{
    private const ORDER_UUID = '55555555-5555-4555-8555-555555555555';
    private const GROUP_UUID = '66666666-6666-4666-8666-666666666666';
    private const PRODUCT_UUID = '11111111-1111-4111-8111-111111111111';
    private const OFFER_UUID = '22222222-2222-4222-8222-222222222222';
    private const ASSET_GUIDE = '33333333-3333-4333-8333-333333333333';
    private const ASSET_BONUS = '44444444-4444-4444-8444-444444444444';

    public function testPaidOrderGrantAndPrivateDownloadLifecycleUsesDurableRows(): void
    {
        self::assertContains(
            'sqlite',
            PDO::getAvailableDrivers(),
            'Download entitlement acceptance requires pdo_sqlite.',
        );

        $dbPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'weline_product_download_'
            . bin2hex(random_bytes(8))
            . '.sqlite';
        $connectionFactory = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));
        $connector = $connectionFactory->getConnector();
        $this->createTables($connector);

        $entitlementFactory = static function () use ($connectionFactory): DownloadEntitlement {
            $model = new DownloadEntitlement();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };
        $auditFactory = static function () use ($connectionFactory): DownloadEntitlementAudit {
            $model = new DownloadEntitlementAudit();
            $model->setConnection($connectionFactory);
            $model->__init();
            return $model;
        };

        $orderItems = [$this->downloadOrderLine()];
        $orders = $this->createMock(OrderFacadeInterface::class);
        $orders->method('get')->willReturnCallback(
            static function (string $orderUuid) use (&$orderItems): OrderReadResult {
                self::assertSame(self::ORDER_UUID, $orderUuid);
                return new OrderReadResult(
                    orderUuid: self::ORDER_UUID,
                    checkoutGroupUuid: self::GROUP_UUID,
                    status: 'paid',
                    currency: 'CNY',
                    websiteId: 0,
                    storeId: 9,
                    items: $orderItems,
                    customerId: 77,
                );
            },
        );

        $assetRows = [
            self::ASSET_GUIDE => $this->fileAsset(self::ASSET_GUIDE, 7),
            self::ASSET_BONUS => $this->fileAsset(self::ASSET_BONUS, 9),
        ];
        $resolvedCalls = [];
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->method('get')->willReturnCallback(
            static function (string $assetId) use ($assetRows): FileAsset {
                if (!isset($assetRows[$assetId])) {
                    throw new \RuntimeException('asset_not_found');
                }
                return $assetRows[$assetId];
            },
        );
        $assets->method('resolveUrl')->willReturnCallback(
            static function (
                string $assetId,
                FileAccessContext $context,
                ?StorageUrlOptions $options = null,
            ) use (&$resolvedCalls): ResolvedStorageUrl {
                self::assertSame(77, $context->actorId);
                self::assertSame(['product_download'], $context->roles);
                self::assertSame('product_download', $context->purpose);
                self::assertSame(4, $context->policyRevision);
                self::assertNotNull($options);
                self::assertSame(StorageUrlOptions::KIND_TEMPORARY, $options->kind);
                self::assertSame(300, $options->ttlSeconds);
                $resolvedCalls[] = $assetId;
                return new ResolvedStorageUrl(
                    '/download-temp/' . rawurlencode($assetId),
                    StorageUrlOptions::KIND_TEMPORARY,
                    false,
                    time() + 300,
                );
            },
        );

        $service = new ProductDownloadEntitlementService(
            $connectionFactory,
            new DatabaseTransactionRunner(new TransactionCoordinator()),
            $orders,
            $assets,
            $entitlementFactory,
            $auditFactory,
        );

        try {
            $granted = $service->grantForPaidOrder(self::ORDER_UUID);
            self::assertCount(2, $granted);
            self::assertSame([false, false], array_column($granted, 'replayed'));
            $byName = [];
            foreach ($granted as $row) {
                $byName[(string)$row['name']] = $row;
            }
            self::assertSame(['Bonus.zip', 'Guide.pdf'], array_keys(array_filter(
                ['Bonus.zip' => $byName['Bonus.zip'] ?? null, 'Guide.pdf' => $byName['Guide.pdf'] ?? null],
            )));
            $guideUuid = (string)$byName['Guide.pdf']['entitlement_uuid'];
            $bonusUuid = (string)$byName['Bonus.zip']['entitlement_uuid'];

            $replayed = $service->grantForPaidOrder(self::ORDER_UUID);
            self::assertSame([true, true], array_column($replayed, 'replayed'));
            self::assertSame(2, $this->countRows($connector, DownloadEntitlement::schema_table));
            self::assertSame(
                2,
                $this->countWhere(
                    $connector,
                    DownloadEntitlementAudit::schema_table,
                    "action = 'grant' AND result_code = 'ok'",
                ),
            );

            $orderItems[0]['fulfillment_metadata']['digital_download']['assets'][0]['name']
                = 'Changed Guide.pdf';
            self::assertSame(
                'download_entitlement_replay_conflict',
                $this->capture(
                    static fn () => $service->grantForPaidOrder(self::ORDER_UUID),
                )->errorCode(),
            );
            $orderItems[0]['fulfillment_metadata']['digital_download']['assets'][0]['name']
                = 'Guide.pdf';
            self::assertSame(2, $this->countRows($connector, DownloadEntitlement::schema_table));

            $scope = ScopeIdentity::store(
                0,
                'default',
                'default',
                ScopeIdentity::MODE_TEST,
            );
            self::assertSame(
                'download_entitlement_forbidden',
                $this->capture(
                    static fn () => $service->consume($guideUuid, 78, $scope),
                )->errorCode(),
            );
            $wrongWebsite = ScopeIdentity::store(
                1,
                'child',
                'default',
                ScopeIdentity::MODE_TEST,
            );
            self::assertSame(
                'download_entitlement_website_mismatch',
                $this->capture(
                    static fn () => $service->consume($guideUuid, 77, $wrongWebsite),
                )->errorCode(),
            );

            $first = $service->consume($guideUuid, 77, $scope, 'zh_CN');
            self::assertSame('/download-temp/' . self::ASSET_GUIDE, $first['url']);
            self::assertSame(1, $first['download_count']);
            self::assertSame(2, $first['download_limit']);
            $second = $service->consume($guideUuid, 77, $scope);
            self::assertSame(2, $second['download_count']);
            self::assertSame(
                'download_limit_exceeded',
                $this->capture(
                    static fn () => $service->consume($guideUuid, 77, $scope),
                )->errorCode(),
            );
            self::assertSame([self::ASSET_GUIDE, self::ASSET_GUIDE], $resolvedCalls);

            $connector->query(
                "UPDATE " . DownloadEntitlement::schema_table
                . " SET expires_at = '2000-01-01 00:00:00'"
                . " WHERE entitlement_uuid = '" . $bonusUuid . "'",
            )->fetch();
            self::assertSame(
                'download_entitlement_expired',
                $this->capture(
                    static fn () => $service->consume($bonusUuid, 77, $scope),
                )->errorCode(),
            );

            $mine = $service->listMine(77, 0);
            self::assertCount(2, $mine);
            foreach ($mine as $row) {
                self::assertArrayNotHasKey('asset_id', $row);
                self::assertArrayNotHasKey('url', $row);
                self::assertStringNotContainsString('/download-temp/', (string)$row['download_url']);
            }

            self::assertSame(
                2,
                $this->countWhere(
                    $connector,
                    DownloadEntitlementAudit::schema_table,
                    "action = 'download' AND result_code = 'ok'",
                ),
            );
            foreach ([
                'download_entitlement_forbidden',
                'download_entitlement_website_mismatch',
                'download_limit_exceeded',
                'download_entitlement_expired',
            ] as $code) {
                self::assertSame(
                    1,
                    $this->countWhere(
                        $connector,
                        DownloadEntitlementAudit::schema_table,
                        "action = 'download_denied' AND result_code = '" . $code . "'",
                    ),
                    $code,
                );
            }
            self::assertSame(8, $this->countRows($connector, DownloadEntitlementAudit::schema_table));
        } finally {
            $connector->close();
            $connectionFactory->close();
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }

        self::assertFileDoesNotExist($dbPath);
    }

    /** @return array<string,mixed> */
    private function downloadOrderLine(): array
    {
        return [
            'line_uuid' => 'download-line-1',
            'name' => 'Download Bundle',
            'fulfillment_metadata' => [
                'digital_download' => [
                    'schema_version' => 'product-download.v1',
                    'global_product_uuid' => self::PRODUCT_UUID,
                    'global_offer_uuid' => self::OFFER_UUID,
                    'assets' => [
                        [
                            'asset_id' => self::ASSET_GUIDE,
                            'asset_revision' => 7,
                            'policy_revision' => 4,
                            'name' => 'Guide.pdf',
                        ],
                        [
                            'asset_id' => self::ASSET_BONUS,
                            'asset_revision' => 9,
                            'policy_revision' => 4,
                            'name' => 'Bonus.zip',
                        ],
                    ],
                    'entitlement_policy' => [
                        'download_limit' => 2,
                        'expires_after_days' => 30,
                    ],
                ],
            ],
        ];
    }

    private function fileAsset(string $assetId, int $revision): FileAsset
    {
        return (new FileAsset())->setData([
            FileAsset::schema_fields_ID => $assetId,
            FileAsset::schema_fields_DEFAULT_LOCALE => 'zh_CN',
            FileAsset::schema_fields_VISIBILITY => FileAsset::VISIBILITY_PRIVATE,
            FileAsset::schema_fields_LIFECYCLE_STATE => FileAsset::STATE_READY,
            FileAsset::schema_fields_ASSET_REVISION => $revision,
            FileAsset::schema_fields_DELETED_AT => null,
        ]);
    }

    private function capture(callable $callback): ProductDownloadEntitlementException
    {
        try {
            $callback();
        } catch (ProductDownloadEntitlementException $exception) {
            return $exception;
        }
        self::fail('Expected ProductDownloadEntitlementException.');
    }

    private function countRows(ConnectorInterface $connector, string $table): int
    {
        $rows = $connector->query('SELECT COUNT(*) AS total FROM ' . $table)->fetch();
        return (int)($rows[0]['total'] ?? -1);
    }

    private function countWhere(ConnectorInterface $connector, string $table, string $where): int
    {
        $rows = $connector->query(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $where,
        )->fetch();
        return (int)($rows[0]['total'] ?? -1);
    }

    private function createTables(ConnectorInterface $connector): void
    {
        $connector->query(
            'CREATE TABLE ' . DownloadEntitlement::schema_table . ' ('
            . 'entitlement_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'entitlement_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'grant_key CHAR(64) NOT NULL UNIQUE, '
            . 'snapshot_hash CHAR(64) NOT NULL, '
            . 'order_uuid VARCHAR(36) NOT NULL, '
            . 'order_line_key VARCHAR(128) NOT NULL, '
            . 'customer_id INTEGER NOT NULL, '
            . 'website_id INTEGER NOT NULL DEFAULT 0, '
            . 'store_id INTEGER NOT NULL DEFAULT 0, '
            . 'global_product_uuid VARCHAR(36) NOT NULL, '
            . 'global_offer_uuid VARCHAR(36) NOT NULL, '
            . 'asset_id VARCHAR(36) NOT NULL, '
            . 'asset_revision INTEGER NOT NULL DEFAULT 1, '
            . 'policy_revision INTEGER NOT NULL DEFAULT 1, '
            . "asset_name VARCHAR(255) NOT NULL DEFAULT '', "
            . 'download_limit INTEGER NULL, '
            . 'download_count INTEGER NOT NULL DEFAULT 0, '
            . 'expires_at DATETIME NULL, '
            . "status VARCHAR(24) NOT NULL DEFAULT 'active', "
            . 'version INTEGER NOT NULL DEFAULT 1, '
            . 'granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'last_download_at DATETIME NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
        $connector->query(
            'CREATE TABLE ' . DownloadEntitlementAudit::schema_table . ' ('
            . 'audit_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'entitlement_uuid VARCHAR(36) NOT NULL, '
            . 'owner_customer_id INTEGER NULL, '
            . 'actor_customer_id INTEGER NOT NULL, '
            . 'website_id INTEGER NOT NULL DEFAULT 0, '
            . 'action VARCHAR(32) NOT NULL, '
            . 'result_code VARCHAR(64) NOT NULL, '
            . 'details_json TEXT NULL, '
            . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')',
        )->fetch();
    }
}
