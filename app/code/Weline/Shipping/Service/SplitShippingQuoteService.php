<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\FulfillmentSplitPlanInterface;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\SplitShippingQuote;
use Weline\Shipping\Api\Quote\SplitShippingQuoteServiceInterface;
use Weline\Shipping\Api\WarehouseShippingOriginInterface;
use Weline\Shipping\Model\Carrier;

/**
 * 多仓拆单报价：按仓规划 → 方案家族内最具体航线 → 运费相加。
 */
final class SplitShippingQuoteService implements SplitShippingQuoteServiceInterface
{
    public const ERROR_PROFILE = 'shipping_profile_conflict';
    public const ERROR_FAMILY = 'shipping_split_family_unavailable';
    public const ERROR_ORIGIN = WarehouseShippingOriginInterface::ERROR_MISSING;
    public const ERROR_PLAN = 'inventory_split_plan_unfulfillable';

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ShippingServiceManager $serviceManager,
        private readonly WarehouseShippingOriginInterface $origins,
        private readonly ?FulfillmentSplitPlanInterface $splitPlan = null,
    ) {
    }

    public function listSplitOptions(ShippingQuoteRequest $request): array
    {
        $families = $this->discoverFamilyCodes($request);
        $options = [];
        foreach ($families as $familyCode => $label) {
            try {
                $quote = $this->quoteSplit($request, $familyCode);
            } catch (\Throwable) {
                continue;
            }
            $options[] = [
                'family_code' => $familyCode,
                'label' => $label,
                'total_amount_minor' => $quote->totalAmountMinor,
                'currency' => $quote->currency,
                'packages' => $quote->packages,
                'is_free' => $quote->isFree,
            ];
        }
        if ($options === []) {
            throw new ShippingQuoteConflictException(
                ScopedShippingQuoteService::ERROR_TEMPLATE,
                __('无可用配送方案（多仓拆单）'),
                ['currency' => $request->currency],
            );
        }

        return $options;
    }

    public function quoteSplit(ShippingQuoteRequest $request, string $familyCode): SplitShippingQuote
    {
        $familyCode = trim($familyCode);
        if ($familyCode === '') {
            throw new ShippingQuoteConflictException(self::ERROR_FAMILY, __('方案家族不能为空'));
        }
        $websiteId = (int)($request->scope['website_id'] ?? 0);
        $storeId = (int)($request->scope['store_id'] ?? 0);
        $context = $this->quoteContext($request->scope);
        $orderSubtotal = $this->linesSubtotalMinor($request->lines);

        $packagesPlan = $this->planPackages($websiteId, $storeId, $request->lines);
        if ($packagesPlan === []) {
            return new SplitShippingQuote(
                familyCode: $familyCode,
                packages: [],
                totalAmountMinor: 0,
                currency: $request->currency,
                currencyPrecision: $request->currencyPrecision,
                configVersion: $this->serviceManager->activeQuoteConfigVersion($context),
                requestHash: SplitShippingQuote::buildRequestHash($request, $familyCode, [], 0),
                isFree: true,
                freeReason: 'virtual_only',
                quoteId: $this->newQuoteId(),
            );
        }

        $quotedPackages = [];
        $allFree = true;
        $freeReason = '';
        foreach ($packagesPlan as $pkg) {
            $warehouseId = (int)($pkg['warehouse_id'] ?? 0);
            $splitKey = (string)($pkg['split_key'] ?? ('wh:' . $warehouseId));
            $lines = is_array($pkg['lines'] ?? null) ? $pkg['lines'] : [];
            $originId = 0;
            if ($warehouseId > 0) {
                try {
                    $originId = $this->origins->requireShippingAddressId($websiteId, $warehouseId);
                } catch (\Throwable $e) {
                    throw new ShippingQuoteConflictException(
                        self::ERROR_ORIGIN,
                        __('仓库未绑定发货地址，无法报价'),
                        ['warehouse_id' => $warehouseId, 'website_id' => $websiteId],
                        $e,
                    );
                }
            } else {
                $originId = $this->objectManager->getInstance(ServiceLaneMatchService::class)->resolveDefaultOriginId();
                if ($originId <= 0) {
                    throw new ShippingQuoteConflictException(
                        self::ERROR_ORIGIN,
                        __('未配置默认发货地址，无法报价'),
                        ['warehouse_id' => 0],
                    );
                }
            }

            $picked = $this->pickFamilyService(
                $request,
                $lines,
                $context,
                $originId,
                $familyCode,
                $orderSubtotal,
            );
            $amount = (int)$picked['amount_minor'];
            if ($amount > 0) {
                $allFree = false;
            } elseif ($freeReason === '' && ($picked['free_reason'] ?? '') !== '') {
                $freeReason = (string)$picked['free_reason'];
            }
            $quotedPackages[] = [
                'split_key' => $splitKey,
                'warehouse_id' => $warehouseId,
                'shipping_address_id' => $originId,
                'service_code' => (string)$picked['service_code'],
                'amount_minor' => $amount,
                'label' => (string)($picked['label'] ?? ''),
                'lines' => $lines,
            ];
        }

        // 整单免邮：任一包因整单规则免邮则清零各包
        if ($allFree || $this->orderLevelFreeApplies($quotedPackages, $freeReason)) {
            foreach ($quotedPackages as &$pkg) {
                $pkg['amount_minor'] = 0;
            }
            unset($pkg);
            $allFree = true;
        }

        $total = 0;
        foreach ($quotedPackages as $pkg) {
            $total += (int)$pkg['amount_minor'];
        }
        $hash = SplitShippingQuote::buildRequestHash($request, $familyCode, $quotedPackages, $total);

        return new SplitShippingQuote(
            familyCode: $familyCode,
            packages: $quotedPackages,
            totalAmountMinor: $total,
            currency: $request->currency,
            currencyPrecision: $request->currencyPrecision,
            configVersion: $this->serviceManager->activeQuoteConfigVersion($context),
            requestHash: $hash,
            isFree: $total === 0,
            freeReason: $total === 0 ? ($freeReason !== '' ? $freeReason : 'zero_rate') : '',
            quoteId: $this->newQuoteId(),
        );
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array{service_code:string,amount_minor:int,label:string,free_reason?:string}
     */
    private function pickFamilyService(
        ShippingQuoteRequest $request,
        array $lines,
        ?array $context,
        int $originId,
        string $familyCode,
        int $orderSubtotalMinor,
    ): array {
        $dest = $request->address;
        $country = strtoupper(trim((string)($dest['country_code'] ?? $dest['country'] ?? 'CN'))) ?: 'CN';
        $services = $this->serviceManager->getAvailableServices(
            $country,
            trim((string)($dest['province'] ?? $dest['region'] ?? '')),
            trim((string)($dest['city'] ?? '')),
            trim((string)($dest['district'] ?? '')),
            $context,
            $dest,
            $originId,
        );
        $familyServices = [];
        foreach ($services as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $carrierId = (int)($summary['carrier_id'] ?? 0);
            $serviceCode = trim((string)($summary['service_code'] ?? ''));
            $code = $this->carrierFamilyCode($carrierId);
            // Checkout getData still lists concrete service_code options; freeze/express pass that
            // value into quoteSplit. Accept both carrier-family codes and direct service codes.
            if (
                $code === $familyCode
                || ('carrier:' . $carrierId) === $familyCode
                || ($serviceCode !== '' && $serviceCode === $familyCode)
            ) {
                $familyServices[] = $summary;
            }
        }
        if ($familyServices === []) {
            throw new ShippingQuoteConflictException(
                self::ERROR_FAMILY,
                __('方案家族在该仓无可达航线：%{1}', [$familyCode]),
                ['family_code' => $familyCode, 'origin_shipping_address_id' => $originId],
            );
        }
        usort($familyServices, static function (array $a, array $b): int {
            $sa = (int)($a['lane_specificity'] ?? 0);
            $sb = (int)($b['lane_specificity'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp((string)($a['service_code'] ?? ''), (string)($b['service_code'] ?? ''));
        });

        try {
            $rates = $this->serviceManager->quoteRates(
                $dest,
                $lines,
                $request->currency,
                $request->currencyPrecision,
                $context,
                $originId,
                $orderSubtotalMinor,
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'shipping_profile_conflict') {
                throw new ShippingQuoteConflictException(
                    self::ERROR_PROFILE,
                    __('商品配送档案与仓航线无交集，无法报价'),
                    ['family_code' => $familyCode],
                    $e,
                );
            }
            throw $e;
        }

        foreach ($familyServices as $summary) {
            $serviceCode = trim((string)($summary['service_code'] ?? ''));
            if ($serviceCode === '' || !isset($rates[$serviceCode])) {
                continue;
            }
            $rate = $rates[$serviceCode];

            return [
                'service_code' => $serviceCode,
                'amount_minor' => (int)$rate['amount_minor'],
                'label' => (string)($rate['label'] ?? $serviceCode),
                'free_reason' => (string)($rate['free_reason'] ?? ''),
            ];
        }

        $preferred = $this->serviceManager->preferredShippingProfileCodes($lines);
        if ($preferred !== []) {
            throw new ShippingQuoteConflictException(
                self::ERROR_PROFILE,
                __('商品配送档案与仓航线无交集，无法报价'),
                ['family_code' => $familyCode, 'profiles' => array_keys($preferred)],
            );
        }

        throw new ShippingQuoteConflictException(
            self::ERROR_FAMILY,
            __('方案家族无可报价服务：%{1}', [$familyCode]),
            ['family_code' => $familyCode],
        );
    }

    /**
     * @return array<string,string> family_code => label
     */
    private function discoverFamilyCodes(ShippingQuoteRequest $request): array
    {
        $websiteId = (int)($request->scope['website_id'] ?? 0);
        $storeId = (int)($request->scope['store_id'] ?? 0);
        $context = $this->quoteContext($request->scope);
        $packages = $this->planPackages($websiteId, $storeId, $request->lines);
        $families = [];
        $dest = $request->address;
        $country = strtoupper(trim((string)($dest['country_code'] ?? $dest['country'] ?? 'CN'))) ?: 'CN';
        $origins = [];
        if ($packages === []) {
            $origins[] = null;
        } else {
            foreach ($packages as $pkg) {
                $wid = (int)($pkg['warehouse_id'] ?? 0);
                $origins[] = $this->origins->findShippingAddressId($websiteId, $wid);
            }
        }
        foreach ($origins as $originId) {
            $services = $this->serviceManager->getAvailableServices(
                $country,
                trim((string)($dest['province'] ?? $dest['region'] ?? '')),
                trim((string)($dest['city'] ?? '')),
                trim((string)($dest['district'] ?? '')),
                $context,
                $dest,
                $originId,
            );
            foreach ($services as $summary) {
                if (!is_array($summary)) {
                    continue;
                }
                $carrierId = (int)($summary['carrier_id'] ?? 0);
                if ($carrierId <= 0) {
                    continue;
                }
                $code = $this->carrierFamilyCode($carrierId);
                if ($code === '') {
                    continue;
                }
                $families[$code] = $this->carrierLabel($carrierId, $code);
            }
        }
        ksort($families);

        return $families;
    }

    private function carrierFamilyCode(int $carrierId): string
    {
        if ($carrierId <= 0) {
            return '';
        }
        /** @var Carrier $carrier */
        $carrier = $this->objectManager->getInstance(Carrier::class, [], false)->load($carrierId);
        if (!(int)$carrier->getId()) {
            return 'carrier:' . $carrierId;
        }
        $code = trim((string)$carrier->getData(Carrier::schema_fields_CARRIER_CODE));

        return $code !== '' ? $code : ('carrier:' . $carrierId);
    }

    private function carrierLabel(int $carrierId, string $fallback): string
    {
        /** @var Carrier $carrier */
        $carrier = $this->objectManager->getInstance(Carrier::class, [], false)->load($carrierId);
        if ((int)$carrier->getId()) {
            $name = trim((string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME));
            if ($name !== '') {
                return $name;
            }
        }

        return $fallback;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function planPackages(int $websiteId, int $storeId, array $lines): array
    {
        $plan = $this->splitPlan;
        if (!$plan instanceof FulfillmentSplitPlanInterface) {
            try {
                $plan = $this->objectManager->getInstance(FulfillmentSplitPlanInterface::class);
            } catch (\Throwable) {
                $plan = null;
            }
        }
        if (!$plan instanceof FulfillmentSplitPlanInterface) {
            // Inventory 不可用：单包兼容（warehouse_id=0 用默认发货地址）
            $shippable = [];
            foreach ($lines as $line) {
                if (is_array($line) && (bool)($line['requires_shipping'] ?? true)) {
                    $shippable[] = $line;
                }
            }
            if ($shippable === []) {
                return [];
            }

            return [[
                'split_key' => 'wh:0',
                'warehouse_id' => 0,
                'lines' => $shippable,
            ]];
        }
        try {
            return $plan->planPackages($websiteId, $storeId, $lines);
        } catch (\Throwable $e) {
            throw new ShippingQuoteConflictException(
                self::ERROR_PLAN,
                __('无法完成多仓履约规划'),
                ['website_id' => $websiteId, 'store_id' => $storeId],
                $e,
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $packages
     */
    private function orderLevelFreeApplies(array $packages, string $freeReason): bool
    {
        if ($packages === []) {
            return true;
        }
        if ($freeReason === '') {
            return false;
        }
        foreach ($packages as $pkg) {
            if ((int)($pkg['amount_minor'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $scope */
    private function quoteContext(array $scope): array
    {
        return [
            'website_id' => (int)($scope['website_id'] ?? 0),
            'store_id' => (int)($scope['store_id'] ?? 0),
            'channel_id' => (int)($scope['channel_id'] ?? 0),
        ];
    }

    /** @param list<array<string,mixed>> $lines */
    private function linesSubtotalMinor(array $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            if (!is_array($line) || !(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            if (array_key_exists('row_total_minor', $line)) {
                $sum += max(0, (int)$line['row_total_minor']);
                continue;
            }
            $sum += max(0, (int)($line['qty_minor'] ?? 0) * (int)($line['unit_price_minor'] ?? 0));
        }

        return $sum;
    }

    private function newQuoteId(): string
    {
        return 'sq_' . bin2hex(random_bytes(8));
    }
}
