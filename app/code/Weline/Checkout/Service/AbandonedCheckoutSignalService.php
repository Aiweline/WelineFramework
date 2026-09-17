<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Model\CheckoutSession;
use Weline\Framework\Manager\ObjectManager;

/**
 * Read-only quoted checkout facts for Marketing winback (no abandon TTL / no outreach).
 */
final class AbandonedCheckoutSignalService
{
    public const MAX_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly ?ContinueCheckoutUrlBuilder $urlBuilder = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{items:list<array<string,mixed>>,meta:array<string,mixed>}
     */
    public function listStaleQuotes(array $params = []): array
    {
        $lookbackHours = \max(1, (int)($params['lookback_hours'] ?? (self::MAX_LOOKBACK_DAYS * 24)));
        $maxHours = self::MAX_LOOKBACK_DAYS * 24;
        if ($lookbackHours > $maxHours) {
            $lookbackHours = $maxHours;
        }

        $createdAfter = \trim((string)($params['created_after'] ?? ''));
        $createdBefore = \trim((string)($params['created_before'] ?? ''));
        if ($createdAfter === '') {
            $createdAfter = \gmdate('Y-m-d H:i:s', \time() - ($lookbackHours * 3600));
        }
        $minAfter = \gmdate('Y-m-d H:i:s', \time() - ($maxHours * 3600));
        if ($createdAfter < $minAfter) {
            $createdAfter = $minAfter;
        }

        $websiteId = isset($params['website_id']) ? (int)$params['website_id'] : 0;
        $limit = \max(1, \min(500, (int)($params['limit'] ?? 100)));
        $now = \gmdate('Y-m-d H:i:s');

        /** @var CheckoutSession $model */
        $model = ObjectManager::getInstance(CheckoutSession::class);
        $model->clear()
            ->where(CheckoutSession::schema_fields_STATE, CheckoutSession::STATE_QUOTED)
            ->where(CheckoutSession::schema_fields_CREATED_AT, $createdAfter, '>=')
            ->where(CheckoutSession::schema_fields_EXPIRES_AT, $now, '>=');
        if ($createdBefore !== '') {
            $model->where(CheckoutSession::schema_fields_CREATED_AT, $createdBefore, '<=');
        }
        $model->order(CheckoutSession::schema_fields_CREATED_AT, 'ASC')
            ->limit(\min(500, $limit * 3))
            ->select()
            ->fetch();

        $items = [];
        foreach ($model->getItems() as $row) {
            if (!$row instanceof CheckoutSession) {
                continue;
            }
            $dto = $this->toDto($row);
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
                'created_after' => $createdAfter,
                'created_before' => $createdBefore,
                'max_lookback_days' => self::MAX_LOOKBACK_DAYS,
                'count' => \count($items),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function getStaleQuote(array $params = []): ?array
    {
        $token = \trim((string)($params['quote_token'] ?? ''));
        if ($token === '') {
            return null;
        }
        /** @var CheckoutSession $model */
        $model = ObjectManager::getInstance(CheckoutSession::class);
        $model->clear()
            ->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $token)
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return null;
        }
        $state = (string)$model->getData(CheckoutSession::schema_fields_STATE);
        if ($state !== CheckoutSession::STATE_QUOTED) {
            return null;
        }
        $expires = (string)$model->getData(CheckoutSession::schema_fields_EXPIRES_AT);
        if ($expires !== '' && \strtotime($expires . ' UTC') !== false && \strtotime($expires . ' UTC') < \time()) {
            return null;
        }

        return $this->toDto($model);
    }

    /**
     * Whether a non-expired quoted checkout session exists for customer_id and/or cart_key.
     *
     * @param array<string, mixed> $params
     * @return array{has_quoted:bool}
     */
    public function hasQuotedSession(array $params = []): array
    {
        $customerId = isset($params['customer_id']) ? (int)$params['customer_id'] : 0;
        $cartKey = \trim((string)($params['cart_key'] ?? ''));
        $guestToken = '';

        if ($customerId <= 0 && $cartKey !== '' && \preg_match('/^customer:(\d+)$/i', $cartKey, $m) === 1) {
            $customerId = (int)$m[1];
        }
        if ($cartKey !== '' && \preg_match('/^guest:(.+)$/i', $cartKey, $m) === 1) {
            $guestToken = \trim($m[1]);
        }

        if ($customerId <= 0 && $guestToken === '' && $cartKey === '') {
            return ['has_quoted' => false];
        }

        $now = \gmdate('Y-m-d H:i:s');
        /** @var CheckoutSession $model */
        $model = ObjectManager::getInstance(CheckoutSession::class);
        $model->clear()
            ->where(CheckoutSession::schema_fields_STATE, CheckoutSession::STATE_QUOTED)
            ->where(CheckoutSession::schema_fields_EXPIRES_AT, $now, '>=')
            ->order(CheckoutSession::schema_fields_CREATED_AT, 'DESC')
            ->limit(200)
            ->select()
            ->fetch();

        foreach ($model->getItems() as $row) {
            if (!$row instanceof CheckoutSession) {
                continue;
            }
            if ($this->sessionMatchesIdentity($row, $customerId, $guestToken, $cartKey)) {
                return ['has_quoted' => true];
            }
        }

        return ['has_quoted' => false];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toDto(CheckoutSession $row): ?array
    {
        $token = \trim((string)$row->getData(CheckoutSession::schema_fields_QUOTE_TOKEN));
        if ($token === '') {
            return null;
        }
        $raw = (string)$row->getData(CheckoutSession::schema_fields_PAYLOAD_JSON);
        $payload = \json_decode($raw, true);
        if (!\is_array($payload)) {
            $payload = [];
        }
        $scope = \is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $websiteId = (int)($scope['website_id'] ?? $payload['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? $payload['store_id'] ?? 0);
        $locale = \trim((string)($scope['locale'] ?? $payload['locale'] ?? ''));
        $customerId = (int)($payload['customer_id'] ?? 0);
        $email = CheckoutSessionContact::extractEmail($payload);
        $totals = \is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $grandMinor = (int)($totals['grand_total_minor'] ?? 0);
        $currency = \trim((string)($row->getData(CheckoutSession::schema_fields_CURRENCY) ?: ($payload['currency'] ?? 'CNY')));
        $entry = \trim((string)($row->getData(CheckoutSession::schema_fields_CHECKOUT_ENTRY) ?: ($payload['checkout_entry'] ?? 'unknown')));

        $urlBuilder = $this->urlBuilder;
        if ($urlBuilder === null) {
            $urlBuilder = new ContinueCheckoutUrlBuilder();
        }
        $reachable = $email !== '';
        $continue = ['continue_checkout_url' => '', 'reachable' => false];
        if ($reachable) {
            $continue = $urlBuilder->build($token, $websiteId > 0 ? $websiteId : null, $locale);
            $reachable = !empty($continue['reachable']) && \trim((string)$continue['continue_checkout_url']) !== '';
        }

        return [
            'quote_token' => $token,
            'state' => CheckoutSession::STATE_QUOTED,
            'checkout_entry' => $entry !== '' ? $entry : 'unknown',
            'customer_id' => $customerId > 0 ? $customerId : null,
            'email' => $email,
            'customer_email' => $email,
            'customer_name' => CheckoutSessionContact::extractCustomerName($payload),
            'guest' => $customerId <= 0,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'locale' => $locale,
            'currency' => $currency !== '' ? $currency : 'CNY',
            'grand_total_minor' => $grandMinor,
            'grand_total' => $grandMinor > 0 ? \number_format($grandMinor / 100, 2, '.', '') : '',
            'created_at' => $this->resolveCreatedAtDisplay(
                (string)$row->getData(CheckoutSession::schema_fields_CREATED_AT),
                (string)$row->getData(\Weline\Framework\Database\AbstractModel::schema_fields_CREATE_TIME),
            ),
            'expires_at' => (string)$row->getData(CheckoutSession::schema_fields_EXPIRES_AT),
            'reachable' => $reachable,
            'continue_checkout_url' => $reachable ? (string)$continue['continue_checkout_url'] : '',
            'line_items' => $this->lineItemsFromPayload($payload, $currency !== '' ? $currency : 'CNY'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function lineItemsFromPayload(array $payload, string $currency): array
    {
        $rawLines = $payload['lines'] ?? null;
        if (!\is_array($rawLines)) {
            $quote = \is_array($payload['quote'] ?? null) ? $payload['quote'] : [];
            $rawLines = $quote['lines'] ?? [];
        }
        if (!\is_array($rawLines)) {
            return [];
        }
        $out = [];
        foreach ($rawLines as $line) {
            if (!\is_array($line)) {
                continue;
            }
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

    private function sessionMatchesIdentity(
        CheckoutSession $row,
        int $customerId,
        string $guestToken,
        string $cartKey,
    ): bool {
        $raw = (string)$row->getData(CheckoutSession::schema_fields_PAYLOAD_JSON);
        $payload = \json_decode($raw, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $payloadCustomerId = (int)($payload['customer_id'] ?? 0);
        if ($customerId > 0 && $payloadCustomerId === $customerId) {
            return true;
        }

        $payloadGuest = \trim((string)($payload['guest_token'] ?? ''));
        if ($guestToken !== '' && $payloadGuest !== '' && \hash_equals($payloadGuest, $guestToken)) {
            return true;
        }

        if ($cartKey === '') {
            return false;
        }

        $fingerprint = \trim((string)$row->getData(CheckoutSession::schema_fields_CART_FINGERPRINT));
        if ($fingerprint !== '' && \hash_equals($fingerprint, $cartKey)) {
            return true;
        }

        foreach (['cart_key', 'persistence_cart_key', 'cart_fingerprint'] as $field) {
            $v = \trim((string)($payload[$field] ?? ''));
            if ($v !== '' && \hash_equals($v, $cartKey)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCreatedAtDisplay(string $createdAt, string $createTime = ''): string
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
