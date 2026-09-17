<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Model\Cart;
use Weline\Framework\Manager\ObjectManager;

/**
 * Read-only non-empty cart facts for Marketing winback (no abandon TTL / no outreach).
 */
final class AbandonedCartSignalService
{
    public const MAX_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly ?ContinueCartUrlBuilder $urlBuilder = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function listStaleCarts(array $params = []): array
    {
        $lookbackHours = \max(1, (int)($params['lookback_hours'] ?? (self::MAX_LOOKBACK_DAYS * 24)));
        $maxHours = self::MAX_LOOKBACK_DAYS * 24;
        if ($lookbackHours > $maxHours) {
            $lookbackHours = $maxHours;
        }

        $updatedAfter = \trim((string)($params['updated_after'] ?? $params['created_after'] ?? ''));
        $updatedBefore = \trim((string)($params['updated_before'] ?? $params['created_before'] ?? ''));
        if ($updatedAfter === '') {
            $updatedAfter = \gmdate('Y-m-d H:i:s', \time() - ($lookbackHours * 3600));
        }
        $minAfter = \gmdate('Y-m-d H:i:s', \time() - ($maxHours * 3600));
        if ($updatedAfter < $minAfter) {
            $updatedAfter = $minAfter;
        }

        $websiteId = isset($params['website_id']) ? (int)$params['website_id'] : 0;
        $limit = \max(1, \min(500, (int)($params['limit'] ?? 100)));
        $excludeEmpty = !\array_key_exists('exclude_empty', $params) || (bool)$params['exclude_empty'];
        $excludeExpired = !\array_key_exists('exclude_expired', $params) || (bool)$params['exclude_expired'];
        $now = \gmdate('Y-m-d H:i:s');

        /** @var Cart $model */
        $model = ObjectManager::getInstance(Cart::class);
        $model->clear()
            ->where(Cart::schema_fields_UPDATED_AT, $updatedAfter, '>=');
        if ($updatedBefore !== '') {
            $model->where(Cart::schema_fields_UPDATED_AT, $updatedBefore, '<=');
        }
        $model->order(Cart::schema_fields_UPDATED_AT, 'ASC')
            ->limit(\min(500, $limit * 3))
            ->select()
            ->fetch();

        $items = [];
        foreach ($model->getItems() as $row) {
            if (!$row instanceof Cart) {
                continue;
            }
            if ($excludeExpired && $this->isExpiredRow($row, $now)) {
                continue;
            }
            $dto = $this->toDto($row, $excludeEmpty);
            if ($dto === null) {
                continue;
            }
            if ($websiteId > 0 && (int)($dto['website_id'] ?? 0) !== $websiteId) {
                continue;
            }
            $items[] = $dto;
            if (\count($items) >= $limit) {
                break;
            }
        }

        return [
            'items' => $items,
            'meta' => [
                'lookback_hours' => $lookbackHours,
                'updated_after' => $updatedAfter,
                'updated_before' => $updatedBefore,
                'max_lookback_days' => self::MAX_LOOKBACK_DAYS,
                'exclude_empty' => $excludeEmpty,
                'exclude_expired' => $excludeExpired,
                'count' => \count($items),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function getStaleCart(array $params = []): ?array
    {
        $cartKey = \trim((string)($params['cart_key'] ?? ''));
        $cartId = isset($params['cart_id']) ? (int)$params['cart_id'] : 0;
        $excludeEmpty = !\array_key_exists('exclude_empty', $params) || (bool)$params['exclude_empty'];
        $excludeExpired = !\array_key_exists('exclude_expired', $params) || (bool)$params['exclude_expired'];
        $now = \gmdate('Y-m-d H:i:s');

        /** @var Cart $model */
        $model = ObjectManager::getInstance(Cart::class);
        $model->clear();
        if ($cartId > 0) {
            $model->where(Cart::schema_fields_ID, $cartId);
        } elseif ($cartKey !== '') {
            if (\preg_match('/^(customer|guest):(.+)$/i', $cartKey, $m) === 1) {
                $model->where(Cart::schema_fields_OWNER_KIND, \strtolower($m[1]))
                    ->where(Cart::schema_fields_OWNER_ID, \trim($m[2]));
            } else {
                $model->where(Cart::schema_fields_CART_KEY, $cartKey);
            }
        } else {
            return null;
        }
        $model->order(Cart::schema_fields_UPDATED_AT, 'DESC')
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        if ($excludeExpired && $this->isExpiredRow($model, $now)) {
            return null;
        }

        return $this->toDto($model, $excludeEmpty);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toDto(Cart $row, bool $excludeEmpty): ?array
    {
        $persistenceKey = \trim((string)$row->getData(Cart::schema_fields_CART_KEY));
        if ($persistenceKey === '') {
            return null;
        }
        $raw = (string)$row->getData(Cart::schema_fields_PAYLOAD_JSON);
        $payload = \json_decode($raw, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $items = $this->rawLinesFromPayload($payload);
        if ($excludeEmpty && $items === []) {
            return null;
        }

        $ownerKind = \strtolower(\trim((string)($row->getData(Cart::schema_fields_OWNER_KIND)
            ?: ($payload['owner_kind'] ?? CartService::OWNER_GUEST))));
        if ($ownerKind === '') {
            $ownerKind = CartService::OWNER_GUEST;
        }
        $ownerId = \trim((string)($row->getData(Cart::schema_fields_OWNER_ID)
            ?: ($payload['owner_id'] ?? '')));
        $guestToken = \trim((string)($row->getData(Cart::schema_fields_GUEST_TOKEN)
            ?: ($payload['guest_token'] ?? '')));
        if ($guestToken === '' && $ownerKind === CartService::OWNER_GUEST) {
            $guestToken = $ownerId;
        }
        $customerId = $ownerKind === CartService::OWNER_CUSTOMER ? (int)$ownerId : 0;

        $scope = $this->resolveScope($payload, (string)$row->getData(Cart::schema_fields_SCOPE_KEY));
        $websiteId = (int)($scope['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? 0);
        $locale = \trim((string)($scope['locale'] ?? ''));

        $currency = \trim((string)($row->getData(Cart::schema_fields_CURRENCY)
            ?: ($payload['currency'] ?? 'CNY')));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $email = $this->resolveEmail($ownerKind, $customerId, $payload);
        $hasEmail = $email !== '';

        $urlBuilder = $this->urlBuilder ?? new ContinueCartUrlBuilder();
        $continue = $urlBuilder->build($websiteId > 0 ? $websiteId : null, $locale);
        $continueUrl = \trim((string)($continue['continue_cart_url'] ?? ''));
        $reachable = $hasEmail && $continueUrl !== '';

        $lineItems = $this->lineItemsFromPayload($payload, $currency);
        $totals = $this->totalsFromPayload($payload, $lineItems);

        $signalKey = $this->signalCartKey($ownerKind, $customerId, $guestToken, $persistenceKey, (int)$row->getId());

        return [
            'cart_key' => $signalKey,
            'persistence_cart_key' => $persistenceKey,
            'cart_id' => (int)$row->getId(),
            'owner_kind' => $ownerKind,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'email' => $email,
            'has_email' => $hasEmail,
            'reachable' => $reachable,
            'continue_cart_url' => $reachable ? $continueUrl : '',
            'line_items' => $lineItems,
            'currency' => $currency,
            'totals' => $totals,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'locale' => $locale,
            'updated_at' => $this->normalizeDateTime((string)$row->getData(Cart::schema_fields_UPDATED_AT)),
            'expires_at' => $this->normalizeDateTime((string)$row->getData(Cart::schema_fields_EXPIRES_AT)),
            'created_at' => $this->resolveCreatedAtDisplay(
                (string)$row->getData(Cart::schema_fields_CREATED_AT),
                (string)$row->getData(\Weline\Framework\Database\AbstractModel::schema_fields_CREATE_TIME),
            ),
        ];
    }

    private function signalCartKey(
        string $ownerKind,
        int $customerId,
        string $guestToken,
        string $persistenceKey,
        int $cartId,
    ): string {
        if ($ownerKind === CartService::OWNER_CUSTOMER && $customerId > 0) {
            return 'customer:' . $customerId;
        }
        if ($ownerKind === CartService::OWNER_GUEST && $guestToken !== '') {
            return 'guest:' . $guestToken;
        }
        if ($persistenceKey !== '') {
            return $persistenceKey;
        }

        return $cartId > 0 ? (string)$cartId : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveEmail(string $ownerKind, int $customerId, array $payload): string
    {
        $fromPayload = $this->extractPayloadEmail($payload);
        if ($ownerKind === CartService::OWNER_CUSTOMER && $customerId > 0) {
            $fromCustomer = $this->customerEmail($customerId);
            if ($fromCustomer !== '') {
                return $fromCustomer;
            }
        }

        return $fromPayload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractPayloadEmail(array $payload): string
    {
        foreach (['email', 'customer_email', 'guest_email'] as $key) {
            $v = \trim((string)($payload[$key] ?? ''));
            if ($v !== '' && \filter_var($v, \FILTER_VALIDATE_EMAIL)) {
                return \strtolower($v);
            }
        }
        $contact = \is_array($payload['contact'] ?? null) ? $payload['contact'] : [];
        $v = \trim((string)($contact['email'] ?? ''));
        if ($v !== '' && \filter_var($v, \FILTER_VALIDATE_EMAIL)) {
            return \strtolower($v);
        }

        return '';
    }

    private function customerEmail(int $customerId): string
    {
        if ($customerId <= 0 || !\class_exists(\Weline\Customer\Model\Customer::class)) {
            return '';
        }
        try {
            /** @var \Weline\Customer\Model\Customer $customer */
            $customer = ObjectManager::getInstance(\Weline\Customer\Model\Customer::class);
            $customer->load($customerId);
            if (!$customer->getId()) {
                return '';
            }
            $email = \trim((string)$customer->getEmail());
            if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                return \strtolower($email);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{website_id:int,store_id:int,locale:string}
     */
    private function resolveScope(array $payload, string $scopeKey): array
    {
        $scope = \is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $websiteId = (int)($scope['website_id'] ?? $payload['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? $payload['store_id'] ?? 0);
        $locale = \trim((string)($scope['locale'] ?? $payload['locale'] ?? ''));
        if ($websiteId <= 0 && $scopeKey !== '') {
            $parts = \explode('|', $scopeKey);
            // kind|websiteId|websiteCode|store|channel|mode|v1
            if (isset($parts[1]) && \ctype_digit($parts[1])) {
                $websiteId = (int)$parts[1];
            }
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'locale' => $locale,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function rawLinesFromPayload(array $payload): array
    {
        $rawLines = $payload['items'] ?? null;
        if (!\is_array($rawLines)) {
            $rawLines = $payload['lines'] ?? null;
        }
        if (!\is_array($rawLines)) {
            $quote = \is_array($payload['quote'] ?? null) ? $payload['quote'] : [];
            $rawLines = $quote['lines'] ?? [];
        }
        if (!\is_array($rawLines)) {
            return [];
        }
        $out = [];
        foreach ($rawLines as $line) {
            if (\is_array($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function lineItemsFromPayload(array $payload, string $currency): array
    {
        $out = [];
        foreach ($this->rawLinesFromPayload($payload) as $line) {
            $name = \trim((string)($line['name'] ?? $line['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qtyMinor = (int)($line['qty_minor'] ?? 0);
            $qty = $qtyMinor > 0 ? ($qtyMinor / 1000) : (float)($line['qty'] ?? $line['quantity'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $unitMinor = (int)($line['unit_price_minor'] ?? $line['price_minor'] ?? 0);
            if ($unitMinor <= 0 && isset($line['price']) && \is_numeric($line['price'])) {
                $unitMinor = (int)\round(((float)$line['price']) * 100);
            }
            $rowMinor = (int)($line['row_total_minor'] ?? 0);
            if ($rowMinor <= 0) {
                $rowMinor = (int)\round($unitMinor * $qty);
            }
            $optionsText = '';
            if (\is_array($line['options'] ?? null)) {
                $parts = [];
                foreach ($line['options'] as $opt) {
                    if (!\is_array($opt)) {
                        continue;
                    }
                    $label = \trim((string)($opt['label'] ?? $opt['code'] ?? ''));
                    $value = \trim((string)($opt['value_label'] ?? $opt['value'] ?? ''));
                    if ($label !== '' && $value !== '') {
                        $parts[] = $label . ': ' . $value;
                    } elseif ($value !== '') {
                        $parts[] = $value;
                    }
                }
                $optionsText = \implode(' · ', $parts);
            }
            $image = \trim((string)($line['image'] ?? $line['image_url'] ?? $line['image_src'] ?? $line['thumbnail'] ?? ''));
            $out[] = [
                'name' => $name,
                'sku' => \trim((string)($line['sku'] ?? $line['product_sku'] ?? '')),
                'qty' => $qty,
                'unit_price' => \number_format($unitMinor / 100, 2, '.', ''),
                'row_total' => \number_format($rowMinor / 100, 2, '.', ''),
                'options_text' => $optionsText,
                'image_url' => $image,
                'currency' => $currency,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $lineItems
     * @return array<string, mixed>
     */
    private function totalsFromPayload(array $payload, array $lineItems): array
    {
        $totals = \is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $subtotalMinor = (int)($totals['subtotal_minor'] ?? $payload['subtotal_minor'] ?? 0);
        $grandMinor = (int)($totals['grand_total_minor'] ?? $payload['grand_total_minor'] ?? 0);
        if ($subtotalMinor <= 0 || $grandMinor <= 0) {
            $sum = 0;
            foreach ($lineItems as $line) {
                $sum += (int)\round(((float)($line['row_total'] ?? 0)) * 100);
            }
            if ($subtotalMinor <= 0) {
                $subtotalMinor = $sum;
            }
            if ($grandMinor <= 0) {
                $grandMinor = $sum;
            }
        }

        return [
            'subtotal_minor' => $subtotalMinor,
            'grand_total_minor' => $grandMinor,
            'subtotal' => $subtotalMinor > 0 ? \number_format($subtotalMinor / 100, 2, '.', '') : '0.00',
            'grand_total' => $grandMinor > 0 ? \number_format($grandMinor / 100, 2, '.', '') : '0.00',
        ];
    }

    private function isExpiredRow(Cart $row, string $now): bool
    {
        $expires = $row->getData(Cart::schema_fields_EXPIRES_AT);
        if ($expires === null || $expires === '') {
            return false;
        }
        $raw = \trim((string)$expires);
        if ($raw === '' || \str_starts_with($raw, '0000-00-00')) {
            return false;
        }
        $ts = \strtotime($raw . ' UTC');

        return $ts !== false && $ts < \strtotime($now . ' UTC');
    }

    private function normalizeDateTime(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '' || \str_starts_with($raw, '0000-00-00')) {
            return '';
        }
        if (\preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }

        return $raw;
    }

    private function resolveCreatedAtDisplay(string $createdAt, string $createTime = ''): string
    {
        $raw = \trim($createTime) !== '' ? \trim($createTime) : \trim($createdAt);

        return $this->normalizeDateTime($raw);
    }
}
