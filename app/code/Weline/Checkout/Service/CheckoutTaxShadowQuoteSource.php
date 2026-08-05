<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Model\CheckoutSession;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Api\TaxShadowQuoteSourceInterface;

/**
 * Checkout-owned adapter exposing persisted quote facts to Tax migration.
 *
 * It returns only normalized Tax inputs. Customer identity, cart metadata and
 * raw address fields never cross the module boundary.
 */
final class CheckoutTaxShadowQuoteSource implements TaxShadowQuoteSourceInterface
{
    private const MAX_WINDOW = 1000;
    private const MAX_SCAN = 5000;

    public function __construct(
        private readonly CheckoutSession $session = new CheckoutSession(),
    ) {
    }

    public function observationWindow(
        int $websiteId,
        int $storeId,
        int $channelId,
        int $limit,
    ): array {
        if ($websiteId < 0 || $storeId < 1 || $channelId < 1) {
            throw new \InvalidArgumentException('tax_shadow_scope_tuple_invalid');
        }
        if ($limit < 1 || $limit > self::MAX_WINDOW) {
            throw new \InvalidArgumentException('tax_shadow_window_limit_invalid');
        }

        $scanLimit = min(self::MAX_SCAN, max(500, $limit * 10));
        $rows = (clone $this->session)
            ->clear()
            ->order(CheckoutSession::schema_fields_ID, 'DESC')
            ->limit($scanLimit)
            ->select()
            ->fetchArray();

        $requests = [];
        $requestHashes = [];
        $seen = [];
        $rejected = 0;
        $duplicates = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $rejected++;
                continue;
            }
            $payload = $this->decodePayload(
                (string)($row[CheckoutSession::schema_fields_PAYLOAD_JSON] ?? ''),
            );
            if ($payload === null) {
                $rejected++;
                continue;
            }
            $scope = $payload['scope'] ?? null;
            if (!is_array($scope)
                || (int)($scope['website_id'] ?? -1) !== $websiteId
                || (int)($scope['store_id'] ?? -1) !== $storeId
                || (int)($scope['channel_id'] ?? -1) !== $channelId
            ) {
                continue;
            }

            $request = $this->normalizeRequest($payload, $websiteId, $storeId);
            if ($request === null) {
                $rejected++;
                continue;
            }
            $hash = $this->hashPayload($request);
            if (isset($seen[$hash])) {
                $duplicates++;
                continue;
            }
            $seen[$hash] = true;
            $requests[] = $request;
            $requestHashes[] = $hash;
            if (count($requests) >= $limit) {
                break;
            }
        }

        return [
            'requests' => $requests,
            'scanned_count' => count($rows),
            'rejected_count' => $rejected,
            'duplicate_count' => $duplicates,
            'request_hashes' => $requestHashes,
        ];
    }

    /** @return array<string,mixed>|null */
    private function decodePayload(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        try {
            $payload = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalizeRequest(array $payload, int $websiteId, int $storeId): ?array
    {
        $orders = $payload['orders'] ?? null;
        $address = $payload['address'] ?? null;
        $currency = strtoupper(trim((string)($payload['currency'] ?? '')));
        if (!is_array($orders) || !is_array($address) || $currency === '') {
            return null;
        }

        $lines = [];
        $seenLines = [];
        foreach ($orders as $order) {
            if (!is_array($order) || !is_array($order['items'] ?? null)) {
                return null;
            }
            foreach ($order['items'] as $item) {
                if (!is_array($item)) {
                    return null;
                }
                $lineId = trim((string)($item['line_uuid'] ?? ''));
                if ($lineId === '' || isset($seenLines[$lineId])) {
                    return null;
                }
                $seenLines[$lineId] = true;
                $lines[] = [
                    'line_id' => $lineId,
                    'tax_class_code' => (string)($item['tax_class_code'] ?? 'standard'),
                    'taxable_amount_minor' => (int)($item['row_total_minor'] ?? 0),
                ];
            }
        }
        if ($lines === []) {
            return null;
        }

        $country = strtoupper(trim((string)($address['country'] ?? $address['country_code'] ?? 'CN')));
        $region = strtoupper(trim((string)($address['region'] ?? $address['region_code'] ?? '')));

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'currency' => $currency,
            'jurisdiction_key' => $country . '|' . $region,
            'rule_schema_version' => TaxEngineInterface::SCHEMA_VERSION,
            'lines' => $lines,
        ];
    }

    private function hashPayload(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
