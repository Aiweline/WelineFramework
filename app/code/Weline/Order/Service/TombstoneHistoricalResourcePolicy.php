<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\HistoricalResourceAudit;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * DEC-009 Store tombstone historical-resource authorization.
 *
 * Store lifecycle is read exclusively through the Websites public catalog.
 * Production decisions are persisted with a deterministic correlation key.
 */
final class TombstoneHistoricalResourcePolicy
{
    public const MODE_ACTIVE = 'active';
    public const MODE_TOMBSTONE = 'tombstone';
    public const RESOURCE_MODE_NORMAL = 'normal';
    public const RESOURCE_MODE_HISTORICAL_ONLY = 'historical_only';

    public const ACTION_REFUND = 'refund';
    public const ACTION_INVOICE = 'invoice';
    public const ACTION_FULFILLMENT = 'fulfillment';
    public const ACTION_PAYMENT_QUERY = 'payment_query';
    public const ACTION_PAYMENT_RECONCILE = 'payment_reconcile';
    public const ACTION_WEBHOOK_VERIFY = 'webhook_verify';
    public const ACTION_INDEX = 'index';
    public const ACTION_SEO = 'seo';
    public const ACTION_CATALOG_WRITE = 'catalog_write';
    public const ACTION_NEW_TRADE = 'new_trade';
    public const ACTION_CONFIG_DISTRIBUTE = 'config_distribute';

    public const ERROR_DENIED = 'tombstone_historical_action_denied';
    public const ERROR_STORE_UNKNOWN = 'tombstone_store_unknown';
    public const ERROR_STORE_INVALID = 'tombstone_store_state_invalid';

    /** @var list<string> */
    private const WHITELIST = [
        self::ACTION_REFUND,
        self::ACTION_INVOICE,
        self::ACTION_FULFILLMENT,
        self::ACTION_PAYMENT_QUERY,
        self::ACTION_PAYMENT_RECONCILE,
        self::ACTION_WEBHOOK_VERIFY,
    ];

    /** @var list<array<string, mixed>> */
    private array $memoryAudit = [];

    public function __construct(
        private readonly StoreCatalogInterface $stores,
        private readonly ?ObjectManager $objectManager = null,
        private readonly bool $persistAudit = true,
    ) {
    }

    public static function forTesting(StoreCatalogInterface $stores): self
    {
        return new self($stores, null, false);
    }

    public function isWhitelisted(string $action): bool
    {
        return \in_array($action, self::WHITELIST, true);
    }

    /**
     * @return array{ok:bool,allowed:bool,store_id:int,store_code?:string,resource_mode?:string,error_code?:string,urgent?:bool,audit_code?:string}
     */
    public function assertAllowed(
        int $storeId,
        string $action,
        string $correlationKey = 'manual',
    ): array {
        $action = trim($action);
        $correlationKey = trim($correlationKey);
        if ($storeId <= 0 || $action === '' || $correlationKey === '') {
            return [
                'ok' => false,
                'allowed' => false,
                'store_id' => $storeId,
                'error_code' => self::ERROR_STORE_UNKNOWN,
            ];
        }

        $store = $this->stores->byId($storeId);
        if (!$store instanceof StoreSummary) {
            return [
                'ok' => false,
                'allowed' => false,
                'store_id' => $storeId,
                'error_code' => self::ERROR_STORE_UNKNOWN,
            ];
        }

        if ($store->lifecycleStatus === self::MODE_ACTIVE) {
            if ($store->tombstonedAt !== null) {
                return $this->invalidStore($store);
            }

            return [
                'ok' => true,
                'allowed' => true,
                'store_id' => $store->id,
                'store_code' => $store->code,
                'resource_mode' => self::RESOURCE_MODE_NORMAL,
            ];
        }
        if ($store->lifecycleStatus !== self::MODE_TOMBSTONE
            || $store->tombstonedAt === null
            || $store->enabled
        ) {
            return $this->invalidStore($store);
        }

        $allowed = $this->isWhitelisted($action);
        $urgent = !$allowed;
        $errorCode = $allowed ? null : self::ERROR_DENIED;
        $auditCode = $this->persistDecision(
            $store,
            $action,
            $correlationKey,
            $allowed,
            $urgent,
            $errorCode,
        );

        return [
            'ok' => $allowed,
            'allowed' => $allowed,
            'store_id' => $store->id,
            'store_code' => $store->code,
            'resource_mode' => self::RESOURCE_MODE_HISTORICAL_ONLY,
            ...($errorCode !== null ? ['error_code' => $errorCode] : []),
            'urgent' => $urgent,
            'audit_code' => $auditCode,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function audit(): array
    {
        if (!$this->persistAudit) {
            return $this->memoryAudit;
        }

        return $this->newAudit()
            ->order(HistoricalResourceAudit::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /** @return list<array<string, mixed>> */
    public function urgent(): array
    {
        return array_values(array_filter(
            $this->audit(),
            static fn (array $row): bool => (int)($row[
                HistoricalResourceAudit::schema_fields_URGENT
            ] ?? 0) === 1,
        ));
    }

    /** @return list<string> */
    public static function whitelist(): array
    {
        return self::WHITELIST;
    }

    /** @return array{ok:false,allowed:false,store_id:int,store_code:string,error_code:string,urgent:true} */
    private function invalidStore(StoreSummary $store): array
    {
        return [
            'ok' => false,
            'allowed' => false,
            'store_id' => $store->id,
            'store_code' => $store->code,
            'error_code' => self::ERROR_STORE_INVALID,
            'urgent' => true,
        ];
    }

    private function persistDecision(
        StoreSummary $store,
        string $action,
        string $correlationKey,
        bool $allowed,
        bool $urgent,
        ?string $errorCode,
    ): string {
        $decisionKey = hash(
            'sha256',
            implode('|', [$store->id, $store->code, $action, $correlationKey]),
        );
        $row = [
            HistoricalResourceAudit::schema_fields_DECISION_KEY => $decisionKey,
            HistoricalResourceAudit::schema_fields_STORE_ID => $store->id,
            HistoricalResourceAudit::schema_fields_STORE_CODE => $store->code,
            HistoricalResourceAudit::schema_fields_ACTION => $action,
            HistoricalResourceAudit::schema_fields_CORRELATION_KEY => $correlationKey,
            HistoricalResourceAudit::schema_fields_ALLOWED => $allowed ? 1 : 0,
            HistoricalResourceAudit::schema_fields_RESOURCE_MODE
                => self::RESOURCE_MODE_HISTORICAL_ONLY,
            HistoricalResourceAudit::schema_fields_URGENT => $urgent ? 1 : 0,
            HistoricalResourceAudit::schema_fields_ERROR_CODE => $errorCode,
            HistoricalResourceAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ];

        if (!$this->persistAudit) {
            foreach ($this->memoryAudit as $existing) {
                if (($existing[HistoricalResourceAudit::schema_fields_DECISION_KEY] ?? '')
                    === $decisionKey
                ) {
                    return $decisionKey;
                }
            }
            $this->memoryAudit[] = $row;

            return $decisionKey;
        }

        $existing = $this->newAudit()
            ->where(HistoricalResourceAudit::schema_fields_DECISION_KEY, $decisionKey)
            ->find()
            ->fetch();
        if (!$existing->getId()) {
            $existing->setData($row)->save();
        }

        return $decisionKey;
    }

    private function newAudit(): HistoricalResourceAudit
    {
        $manager = $this->objectManager ?? ObjectManager::getInstance();

        return $manager->getInstance(HistoricalResourceAudit::class, [], false);
    }
}
