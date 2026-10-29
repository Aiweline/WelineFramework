<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Shipping\Model\ServiceRegion;
use Weline\Shipping\Model\ShippingService;

/**
 * Request-scoped batch loader for ShippingService rows and their active lane
 * region country codes.
 *
 * Head rendering resolves the OfferShippingDetails of every rendered product by
 * walking a profile's service ids and, for each id, issuing two queries
 * (`w_shipping_services` + `w_shipping_service_regions`). On a storefront page
 * that produced 29 + 37 queries for the very same rows. This loader replaces
 * that per-service fan-out with at most one IN query per table per request and
 * memoizes the answer in the request context.
 *
 * Memo scope is the request, never the worker process: a service disabled
 * between two requests must be invisible to the next request. Where no request
 * context exists (CLI, unit tests) the memo is bypassed and each call queries,
 * trading speed for correctness instead of risking stale cross-request data.
 *
 * Disabled or missing ids are negatively cached so a profile referencing dead
 * services does not re-query them for every product of the same request.
 */
final class ShippingServiceBatchLoader
{
    private const CONTEXT_SERVICES = 'shipping.batch.active_services.v1';
    private const CONTEXT_REGION_CODES = 'shipping.batch.region_country_codes.v1';
    private const CONTEXT_PROFILE_SERVICE_IDS = 'shipping.batch.profile_service_ids.v1';

    /**
     * @param (\Closure(list<int>): list<array<string,mixed>>)|null $serviceRowsFetcher
     *        test seam for the batched service query
     * @param (\Closure(list<int>): list<array<string,mixed>>)|null $regionRowsFetcher
     *        test seam for the batched region query
     * @param (\Closure(int): list<int>)|null $profileServiceIdsFetcher
     *        test seam for the profile -> service id query
     */
    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?\Closure $serviceRowsFetcher = null,
        private readonly ?\Closure $regionRowsFetcher = null,
        private readonly ?\Closure $profileServiceIdsFetcher = null,
    ) {
    }

    /**
     * Active services for the given ids, keyed by service_id.
     *
     * @param list<int> $serviceIds
     * @return array<int, ShippingService>
     */
    public function loadActiveServices(array $serviceIds): array
    {
        $requested = self::normalizeIds($serviceIds);
        if ($requested === []) {
            return [];
        }
        $memo = $this->readMemo(self::CONTEXT_SERVICES);
        $missing = [];
        foreach ($requested as $id) {
            if (!\array_key_exists($id, $memo)) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            // Negative cache first: any id the query does not return is proven
            // absent-or-inactive for this request.
            foreach ($missing as $id) {
                $memo[$id] = null;
            }
            foreach ($this->fetchServiceRows($missing) as $row) {
                $id = (int)($row[ShippingService::schema_fields_ID] ?? 0);
                if ($id <= 0 || !\array_key_exists($id, $memo)) {
                    continue;
                }
                $model = $this->om()->getInstance(ShippingService::class, [], false);
                $model->setData($row);
                $memo[$id] = $model;
            }
            $this->writeMemo(self::CONTEXT_SERVICES, $memo);
        }

        $out = [];
        foreach ($requested as $id) {
            if (($memo[$id] ?? null) instanceof ShippingService) {
                $out[$id] = $memo[$id];
            }
        }

        return $out;
    }

    /**
     * Active lane region country codes per service, upper-cased, de-duplicated
     * and kept in the same row order the per-service query returned.
     *
     * @param list<int> $serviceIds
     * @return array<int, list<string>>
     */
    public function loadActiveRegionCountryCodes(array $serviceIds): array
    {
        $requested = self::normalizeIds($serviceIds);
        if ($requested === []) {
            return [];
        }
        $memo = $this->readMemo(self::CONTEXT_REGION_CODES);
        $missing = [];
        foreach ($requested as $id) {
            if (!\array_key_exists($id, $memo)) {
                $missing[] = $id;
            }
        }
        if ($missing !== []) {
            foreach ($missing as $id) {
                $memo[$id] = [];
            }
            $grouped = [];
            foreach ($this->fetchRegionRows($missing) as $row) {
                $id = (int)($row[ServiceRegion::schema_fields_SERVICE_ID] ?? 0);
                if ($id <= 0 || !\array_key_exists($id, $memo)) {
                    continue;
                }
                $code = \strtoupper(\trim((string)($row[ServiceRegion::schema_fields_COUNTRY_CODE] ?? '')));
                if ($code === '') {
                    continue;
                }
                $grouped[$id][$code] = true;
            }
            foreach ($grouped as $id => $codes) {
                $memo[$id] = \array_keys($codes);
            }
            $this->writeMemo(self::CONTEXT_REGION_CODES, $memo);
        }

        $out = [];
        foreach ($requested as $id) {
            $codes = $memo[$id] ?? [];
            $out[$id] = \is_array($codes) ? \array_values($codes) : [];
        }

        return $out;
    }

    /**
     * Service ids attached to a shipping profile, memoized per request.
     *
     * @return list<int>
     */
    public function serviceIdsForProfile(int $profileId): array
    {
        if ($profileId <= 0) {
            return [];
        }
        $memo = $this->readMemo(self::CONTEXT_PROFILE_SERVICE_IDS);
        if (\array_key_exists($profileId, $memo) && \is_array($memo[$profileId])) {
            return \array_values($memo[$profileId]);
        }
        $ids = $this->fetchProfileServiceIds($profileId);
        $memo[$profileId] = $ids;
        $this->writeMemo(self::CONTEXT_PROFILE_SERVICE_IDS, $memo);

        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function fetchServiceRows(array $ids): array
    {
        if ($this->serviceRowsFetcher !== null) {
            return \array_values((array)($this->serviceRowsFetcher)($ids));
        }
        $rows = $this->om()->getInstance(ShippingService::class, [], false)
            ->reset()
            ->where(ShippingService::schema_fields_ID, $ids, 'IN')
            ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
            ->order(ShippingService::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    /**
     * The per-service query this replaces had no ORDER BY but walked
     * idx_service_lane_coverage(service_id, is_active), i.e. rows ordered by
     * service_id then primary key. Ordering the batch the same way keeps the
     * resulting country-code order identical for multi-country lanes.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function fetchRegionRows(array $ids): array
    {
        if ($this->regionRowsFetcher !== null) {
            return \array_values((array)($this->regionRowsFetcher)($ids));
        }
        $rows = $this->om()->getInstance(ServiceRegion::class, [], false)
            ->reset()
            ->where(ServiceRegion::schema_fields_SERVICE_ID, $ids, 'IN')
            ->where(ServiceRegion::schema_fields_IS_ACTIVE, 1)
            ->order(ServiceRegion::schema_fields_SERVICE_ID, 'ASC')
            ->order(ServiceRegion::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    /** @return list<int> */
    private function fetchProfileServiceIds(int $profileId): array
    {
        if ($this->profileServiceIdsFetcher !== null) {
            return self::normalizeIds((array)($this->profileServiceIdsFetcher)($profileId));
        }
        /** @var ShippingProfileResolver $resolver */
        $resolver = $this->om()->getInstance(ShippingProfileResolver::class);

        return self::normalizeIds($resolver->serviceIdsForProfile($profileId));
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !isset($out[$id])) {
                $out[$id] = true;
            }
        }

        return \array_keys($out);
    }

    /** @return array<int|string, mixed> */
    private function readMemo(string $key): array
    {
        $value = RequestContext::get($key, []);

        return \is_array($value) ? $value : [];
    }

    /** @param array<int|string, mixed> $value */
    private function writeMemo(string $key, array $value): void
    {
        if (!Context::hasCurrent()) {
            // Never materialize a request context just to hold a cache entry.
            return;
        }
        RequestContext::set($key, $value);
    }
}
