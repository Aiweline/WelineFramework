<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * Read-only unpaid order facts for Marketing winback (no abandon semantics).
 */
final class UnpaidOrderSignalService
{
    public const MAX_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly ?ContinuePayUrlBuilder $continuePayUrlBuilder = null,
        private readonly ?UnpaidOrderMailLineProjector $mailLineProjector = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function listUnpaid(array $params = []): array
    {
        $lookbackHours = max(1, (int)($params['lookback_hours'] ?? (self::MAX_LOOKBACK_DAYS * 24)));
        $maxHours = self::MAX_LOOKBACK_DAYS * 24;
        if ($lookbackHours > $maxHours) {
            $lookbackHours = $maxHours;
        }

        $createdAfter = trim((string)($params['created_after'] ?? ''));
        $createdBefore = trim((string)($params['created_before'] ?? ''));
        if ($createdAfter === '') {
            $createdAfter = gmdate('Y-m-d H:i:s', time() - ($lookbackHours * 3600));
        }
        $minAfter = gmdate('Y-m-d H:i:s', time() - ($maxHours * 3600));
        if ($createdAfter < $minAfter) {
            $createdAfter = $minAfter;
        }

        $websiteId = isset($params['website_id']) ? (int)$params['website_id'] : 0;
        $storeId = isset($params['store_id']) ? (int)$params['store_id'] : 0;
        $limit = max(1, min(500, (int)($params['limit'] ?? 100)));

        /** @var Order $model */
        $model = ObjectManager::getInstance(Order::class);
        $model->clear();
        $model->where(Order::schema_fields_PAYMENT_STATUS, [Order::PAYMENT_STATUS_PENDING, Order::PAYMENT_STATUS_PARTIAL], 'in')
            ->where(Order::schema_fields_STATUS, Order::STATUS_CANCELLED, '!=')
            ->where(Order::schema_fields_STATUS, 'canceled', '!=')
            ->where(Order::schema_fields_CUSTOMER_EMAIL, '', '!=')
            ->where(Order::schema_fields_CUSTOMER_EMAIL, null, 'is not null')
            ->where(Order::schema_fields_CREATED_AT, $createdAfter, '>=');
        if ($createdBefore !== '') {
            $model->where(Order::schema_fields_CREATED_AT, $createdBefore, '<=');
        }
        if ($websiteId > 0) {
            $model->where(Order::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($storeId > 0) {
            $model->where(Order::schema_fields_STORE_ID, $storeId);
        }
        // Phase 1: retail only — exclude tob / hang wholesale & hang types.
        $model->where(Order::schema_fields_ORDER_TYPE, ['tob', 'hang'], 'not in');
        $model->order(Order::schema_fields_CREATED_AT, 'ASC')
            ->limit($limit)
            ->select()
            ->fetch();

        $items = [];
        foreach ($model->getItems() as $row) {
            if (!$row instanceof Order) {
                continue;
            }
            $dto = $this->toDto($row);
            if ($dto !== null) {
                $items[] = $dto;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'lookback_hours' => $lookbackHours,
                'created_after' => $createdAfter,
                'created_before' => $createdBefore,
                'max_lookback_days' => self::MAX_LOOKBACK_DAYS,
                'count' => count($items),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function getUnpaid(array $params = []): ?array
    {
        $orderUuid = trim((string)($params['order_uuid'] ?? ''));
        if ($orderUuid === '') {
            return null;
        }
        /** @var Order $model */
        $model = ObjectManager::getInstance(Order::class);
        $model->clear()
            ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        $payment = strtolower(trim((string)$model->getData(Order::schema_fields_PAYMENT_STATUS)));
        $status = strtolower(trim((string)$model->getData(Order::schema_fields_STATUS)));
        if (!in_array($payment, [Order::PAYMENT_STATUS_PENDING, Order::PAYMENT_STATUS_PARTIAL], true)) {
            return null;
        }
        if (in_array($status, [Order::STATUS_CANCELLED, 'canceled'], true)) {
            return null;
        }
        $orderType = strtolower(trim((string)($model->getData(Order::schema_fields_ORDER_TYPE) ?: 'toc')));
        if (in_array($orderType, ['tob', 'hang'], true)) {
            return null;
        }

        return $this->toDto($model);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toDto(Order $row): ?array
    {
        $email = trim((string)$row->getData(Order::schema_fields_CUSTOMER_EMAIL));
        if ($email === '') {
            return null;
        }
        $orderUuid = trim((string)$row->getData(Order::schema_fields_ORDER_UUID));
        if ($orderUuid === '') {
            return null;
        }
        $customerId = (int)$row->getData(Order::schema_fields_CUSTOMER_ID);
        $websiteId = (int)$row->getData(Order::schema_fields_WEBSITE_ID);
        $builder = $this->continuePayUrlBuilder ?? new ContinuePayUrlBuilder();
        $pay = $builder->build($orderUuid, $customerId > 0 ? $customerId : null, $websiteId > 0 ? $websiteId : null);
        $grandTotal = (float)$row->getData(Order::schema_fields_GRAND_TOTAL);
        $money = json_decode((string)$row->getData(Order::schema_fields_MONEY_SNAPSHOT_JSON), true);
        $fromSnapshot = is_array($money) ? (int)($money['grand_total_minor'] ?? 0) : 0;
        $grandTotalMinor = $fromSnapshot > 0 ? $fromSnapshot : (int)round($grandTotal * 100);
        $projector = $this->mailLineProjector ?? new UnpaidOrderMailLineProjector();
        $lineItems = $projector->project($row);

        return [
            'order_uuid' => $orderUuid,
            'order_number' => (string)$row->getData(Order::schema_fields_ORDER_NUMBER),
            'customer_id' => $customerId > 0 ? $customerId : null,
            'email' => $email,
            'customer_name' => (string)$row->getData(Order::schema_fields_CUSTOMER_NAME),
            'grand_total' => $grandTotal,
            'grand_total_minor' => $grandTotalMinor,
            'currency' => strtoupper(trim((string)$row->getData(Order::schema_fields_CURRENCY))) ?: 'CNY',
            'checkout_entry' => strtolower(trim((string)($row->getData(Order::schema_fields_CHECKOUT_ENTRY) ?: 'unknown'))) ?: 'unknown',
            // Live rows often leave created_at empty; framework create_time is populated.
            'created_at' => self::resolveCreatedAtDisplay(
                (string)$row->getData(Order::schema_fields_CREATED_AT),
                (string)$row->getData(\Weline\Framework\Database\AbstractModel::schema_fields_CREATE_TIME),
            ),
            'website_id' => $websiteId,
            'store_id' => (int)$row->getData(Order::schema_fields_STORE_ID),
            'order_type' => strtolower(trim((string)($row->getData(Order::schema_fields_ORDER_TYPE) ?: 'toc'))) ?: 'toc',
            'payment_status' => strtolower(trim((string)$row->getData(Order::schema_fields_PAYMENT_STATUS))),
            'status' => strtolower(trim((string)$row->getData(Order::schema_fields_STATUS))),
            // Checkout-time language from scope snapshot (Marketing mail must prefer this).
            'locale' => self::localeFromScopeSnapshotJson(
                (string)$row->getData(Order::schema_fields_SCOPE_SNAPSHOT_JSON)
            ),
            'continue_pay_url' => (string)$pay['continue_pay_url'],
            'reachable' => !empty($pay['reachable']),
            'line_items' => $lineItems,
        ];
    }

    /**
     * Prefer scope_snapshot.locale (alias: language); ignore empty / "default".
     */
    public static function localeFromScopeSnapshotJson(string $json): string
    {
        $snapshot = json_decode($json, true);
        if (!\is_array($snapshot)) {
            return '';
        }
        $locale = \trim((string)($snapshot['locale'] ?? $snapshot['language'] ?? ''));
        if ($locale === '' || \strtolower($locale) === 'default') {
            return '';
        }

        return $locale;
    }

    /**
     * Mail/admin display time: prefer framework create_time when created_at is blank.
     */
    public static function resolveCreatedAtDisplay(string $createdAt, string $createTime = ''): string
    {
        $raw = \trim($createTime) !== '' ? \trim($createTime) : \trim($createdAt);
        if ($raw === '' || \str_starts_with($raw, '0000-00-00')) {
            return '';
        }
        if (\preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $raw;
    }
}
