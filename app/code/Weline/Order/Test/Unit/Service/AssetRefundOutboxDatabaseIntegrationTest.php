<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Order\Api\RefundAssetReturnCapabilityInterface;
use Weline\Order\Model\RefundCase;
use Weline\Order\Model\RefundOutbox;
use Weline\Order\Service\OrderRefundCoordinator;

/**
 * Development portability coverage. PostgreSQL financial acceptance is run
 * separately on the registered disposable clone.
 */
final class AssetRefundOutboxDatabaseIntegrationTest extends TestCase
{
    public function testAssetReturnFailureRetriesIndependentlyOfCashRefund(): void
    {
        [$path, $connection, $connector] = $this->database();
        try {
            $assetReturns = new class implements RefundAssetReturnCapabilityInterface {
                public int $calls = 0;
                public int $credits = 0;

                public function returnCommittedAllocations(
                    string $refundCaseUuid,
                    array $allocations,
                    string $effectKey,
                ): array {
                    ++$this->calls;
                    if ($this->calls === 1) {
                        throw new \RuntimeException('asset_return_first_failure');
                    }
                    ++$this->credits;
                    return ['ok' => true, 'allocations' => $allocations];
                }
            };
            $coordinator = new OrderRefundCoordinator(
                transactions: new TransactionCoordinator(),
                assetReturns: $assetReturns,
                modelFactory: fn (string $class) => $this->model(
                    $class,
                    $connection,
                ),
            );

            $failed = $coordinator->processOneOutbox('outbox-asset-return');
            self::assertFalse($failed['ok']);
            self::assertSame('asset_return_first_failure', $failed['error_code']);
            self::assertSame(RefundOutbox::STATUS_PENDING, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_outbox"
                    . " WHERE outbox_code='outbox-asset-return'",
                'status',
            ));
            self::assertSame(RefundCase::STATUS_SUCCEEDED, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_case"
                    . " WHERE refund_case_uuid='refund-case-asset'",
                'status',
            ));

            $succeeded = $coordinator->processOneOutbox('outbox-asset-return');
            $replayed = $coordinator->processOneOutbox('outbox-asset-return');

            self::assertTrue($succeeded['ok']);
            self::assertTrue($replayed['ok']);
            self::assertTrue($replayed['replayed']);
            self::assertSame(2, $assetReturns->calls);
            self::assertSame(1, $assetReturns->credits);
            self::assertSame(2, $this->integer(
                $connector,
                "SELECT attempt_count FROM weline_order_refund_outbox"
                    . " WHERE outbox_code='outbox-asset-return'",
                'attempt_count',
            ));
            self::assertSame(RefundOutbox::STATUS_DONE, $this->text(
                $connector,
                "SELECT status FROM weline_order_refund_outbox"
                    . " WHERE outbox_code='outbox-asset-return'",
                'status',
            ));
        } finally {
            $connector->close();
            $connection->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array{0:string,1:ConnectionFactory,2:ConnectorInterface}
     */
    private function database(): array
    {
        self::assertContains('sqlite', PDO::getAvailableDrivers());
        $path = sys_get_temp_dir() . '/weline_p4d_asset_refund_'
            . bin2hex(random_bytes(8)) . '.sqlite';
        $connection = ConnectionFactory::getInstance(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $path,
            'persistent' => false,
        ]));
        $connector = $connection->getConnector();
        $connector->query(
            'CREATE TABLE weline_order_refund_case ('
            . 'refund_case_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'refund_case_uuid VARCHAR(36) NOT NULL UNIQUE, '
            . 'order_uuid VARCHAR(36) NOT NULL, payment_refund_code VARCHAR(96), '
            . 'idempotency_key VARCHAR(128), request_hash VARCHAR(64), '
            . 'amount_minor INTEGER NOT NULL DEFAULT 0, '
            . 'cash_amount_minor INTEGER NOT NULL DEFAULT 0, '
            . 'asset_amount_minor INTEGER NOT NULL DEFAULT 0, '
            . 'asset_allocations_json TEXT, currency VARCHAR(3) NOT NULL, '
            . 'items_json TEXT, shipping_refund_minor INTEGER NOT NULL DEFAULT 0, '
            . 'status VARCHAR(32) NOT NULL, customer_view VARCHAR(24) NOT NULL, '
            . 'version INTEGER NOT NULL DEFAULT 0, reason VARCHAR(255), '
            . 'steps_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)'
        )->fetch();
        $connector->query(
            'CREATE TABLE weline_order_refund_outbox ('
            . 'outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'outbox_code VARCHAR(96) NOT NULL UNIQUE, '
            . 'effect_key VARCHAR(191) NOT NULL UNIQUE, '
            . 'refund_case_uuid VARCHAR(36) NOT NULL, '
            . 'refund_code VARCHAR(96) NOT NULL, operation VARCHAR(48) NOT NULL, '
            . 'provider_request_key VARCHAR(160), status VARCHAR(32) NOT NULL, '
            . 'payload_json TEXT, result_json TEXT, error_code VARCHAR(96), '
            . 'attempt_count INTEGER NOT NULL DEFAULT 0, '
            . "claim_token VARCHAR(64) NOT NULL DEFAULT '', claimed_at DATETIME, "
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME)'
        )->fetch();
        $allocations = [[
            'allocation_code' => 'allocation-1',
            'reservation_id' => 'reservation-1',
            'payment_refund_amount_minor' => 100,
            'asset_return_amount_minor' => 100,
            'cumulative_payment_refunded_minor' => 100,
        ]];
        $allocationJson = $connector->quote(json_encode(
            $allocations,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $connector->query(
            'INSERT INTO weline_order_refund_case '
            . '(refund_case_uuid,order_uuid,payment_refund_code,amount_minor,'
            . 'cash_amount_minor,asset_amount_minor,asset_allocations_json,'
            . 'currency,status,customer_view,steps_json) VALUES '
            . "('refund-case-asset','order-asset','payment-refund-1',300,200,100,"
            . $allocationJson . ",'CNY','succeeded','succeeded',"
            . '\'{"refund:refund-case-asset:asset:return:v1":{"status":"pending"}}\')'
        )->fetch();
        $payload = $connector->quote(json_encode(
            ['allocations' => $allocations],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $connector->query(
            'INSERT INTO weline_order_refund_outbox '
            . '(outbox_code,effect_key,refund_case_uuid,refund_code,operation,'
            . 'status,payload_json) VALUES '
            . "('outbox-asset-return','refund:refund-case-asset:asset:return:v1',"
            . "'refund-case-asset','payment-refund-1','asset_return','pending',"
            . $payload . ')'
        )->fetch();

        return [$path, $connection, $connector];
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function model(string $class, ConnectionFactory $connection): object
    {
        $instance = new $class();
        $instance->setConnection($connection);
        $instance->__init();

        return $instance;
    }

    private function integer(
        ConnectorInterface $connector,
        string $sql,
        string $field,
    ): int {
        return (int)($connector->query($sql)->fetch()[0][$field] ?? 0);
    }

    private function text(
        ConnectorInterface $connector,
        string $sql,
        string $field,
    ): string {
        return (string)($connector->query($sql)->fetch()[0][$field] ?? '');
    }
}
