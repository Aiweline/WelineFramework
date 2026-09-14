<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipFreightProviderInterface;
use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;

/**
 * Checkout overlay: identify freight-capable dropship lines and replace shipping with provider quotes.
 */
class DropshipCheckoutFreightService
{
    public const ERROR_FREIGHT_UNAVAILABLE = 'dropship_freight_unavailable';

    public function __construct(
        private readonly ?DropshipFreightAggregator $aggregator = null,
        private readonly ?DropshipChannelManager $channels = null,
        private readonly ?DropshipPricingService $pricing = null,
        private readonly ?DropshipListing $listingModel = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $address
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>> $splitPackages
     * @return array{
     *   applied:bool,
     *   degraded?:bool,
     *   degrade_reason?:string,
     *   amount_minor:int,
     *   currency:string,
     *   segments:list<array<string,mixed>>,
     *   split_packages:list<array<string,mixed>>,
     *   error:?string
     * }
     */
    public function overlayAmount(
        int $localAmountMinor,
        array $lines,
        array $address,
        array $scope,
        string $currency,
        array $splitPackages = [],
    ): array {
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $resolved = $this->resolveFreightLines($lines);
        if ($resolved === []) {
            return [
                'applied' => false,
                'amount_minor' => $localAmountMinor,
                'currency' => $currency,
                'segments' => [],
                'split_packages' => $splitPackages,
                'error' => null,
            ];
        }

        $quote = $this->quoteResolved($resolved, $address, $scope, $currency);
        if (($quote['error'] ?? null) !== null) {
            $mode = $this->resolveOnFailureMode($resolved);
            if ($mode === DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT) {
                return [
                    'applied' => true,
                    'degraded' => false,
                    'amount_minor' => $localAmountMinor,
                    'currency' => $currency,
                    'segments' => [],
                    'split_packages' => $splitPackages,
                    'error' => self::ERROR_FREIGHT_UNAVAILABLE,
                    'failure_mode' => $mode,
                ];
            }

            return [
                'applied' => false,
                'degraded' => true,
                'degrade_reason' => (string)$quote['error'],
                'amount_minor' => $localAmountMinor,
                'currency' => $currency,
                'segments' => [],
                'split_packages' => $splitPackages,
                'error' => null,
                'failure_mode' => DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL,
            ];
        }

        $providerMinor = (int)($quote['total_minor'] ?? 0);
        $segments = is_array($quote['segments'] ?? null) ? $quote['segments'] : [];
        $shippable = $this->shippableLines($lines);
        $allDropship = $shippable !== [] && count($shippable) === count($resolved);

        $packages = $splitPackages;
        $amount = $localAmountMinor;
        if ($packages !== []) {
            [$amount, $packages] = $this->replacePackageAmounts(
                $packages,
                $resolved,
                $providerMinor,
                $localAmountMinor,
            );
        } elseif ($allDropship) {
            $amount = $providerMinor;
        } else {
            // Mixed cart without packages: keep local for unknown non-dropship share unavailable →
            // fail-closed prefer provider+local would double-charge; use provider for dropship share
            // only when we can identify — without packages, replace entire amount with provider
            // when dropship lines dominate qty, else local + provider (documented heuristic: add).
            $amount = $localAmountMinor + $providerMinor;
        }

        return [
            'applied' => true,
            'amount_minor' => max(0, $amount),
            'currency' => $currency,
            'segments' => $segments,
            'split_packages' => $packages,
            'error' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $methods
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $address
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function overlayMethods(
        array $methods,
        array $lines,
        array $address,
        array $scope,
        string $currency,
    ): array {
        $resolved = $this->resolveFreightLines($lines);
        if ($resolved === []) {
            return $methods;
        }

        $quote = $this->quoteResolved($resolved, $address, $scope, $currency);
        if (($quote['error'] ?? null) !== null) {
            $mode = $this->resolveOnFailureMode($resolved);
            if ($mode === DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT) {
                return [];
            }

            return $methods;
        }

        $providerMinor = (int)($quote['total_minor'] ?? 0);
        $shippable = $this->shippableLines($lines);
        $allDropship = $shippable !== [] && count($shippable) === count($resolved);

        $out = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $local = (int)($method['amount_minor'] ?? 0);
            $amount = $allDropship ? $providerMinor : ($local + $providerMinor);
            $method['amount_minor'] = max(0, $amount);
            if (array_key_exists('amount', $method)) {
                $method['amount'] = $method['amount_minor'] / 100;
            }
            if (array_key_exists('fee', $method)) {
                $method['fee'] = $method['amount_minor'] / 100;
            }
            $method['description'] = $amount === 0
                ? (string)($method['description'] ?? '')
                : '';
            $method['dropship_freight'] = [
                'applied' => true,
                'provider_total_minor' => $providerMinor,
                'segments' => $quote['segments'] ?? [],
            ];
            $out[] = $method;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function resolveFreightLines(array $lines): array
    {
        $out = [];
        $listingModel = $this->listing();
        $channels = $this->channels();
        foreach ($lines as $i => $line) {
            if (!is_array($line) || !(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $offerId = (int)($line['offer_id'] ?? $line['product_offer_id'] ?? 0);
            if ($offerId <= 0) {
                continue;
            }
            $listing = $listingModel->clear()
                ->where(DropshipListing::schema_fields_LOCAL_OFFER_ID, $offerId)
                ->find()
                ->fetch();
            if (!$listing || !$listing->getId()) {
                continue;
            }
            $providerCode = trim((string)$listing->getData(DropshipListing::schema_fields_PROVIDER_CODE));
            if ($providerCode === '') {
                continue;
            }
            $provider = $channels->getProvider($providerCode);
            if (!$provider instanceof DropshipFreightProviderInterface) {
                continue;
            }
            $caps = $provider->getCapabilities();
            if (empty($caps['freight'])) {
                continue;
            }
            $qty = $this->lineQty($line);
            $out[] = [
                'line_key' => 'offer-' . $offerId . '-' . $i,
                'offer_id' => $offerId,
                'provider_code' => $providerCode,
                'external_sku' => (string)$listing->getData(DropshipListing::schema_fields_EXTERNAL_SKU),
                'external_spu' => (string)$listing->getData(DropshipListing::schema_fields_EXTERNAL_SPU),
                'external_vid' => (string)$listing->getData(DropshipListing::schema_fields_EXTERNAL_SKU),
                'remote_country' => strtoupper(trim((string)$listing->getData(DropshipListing::schema_fields_REMOTE_COUNTRY))),
                'qty' => $qty,
                'split_key' => (string)($line['split_key'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Any involved provider choosing block_checkout wins; else fallback_local.
     *
     * @param list<array<string, mixed>> $resolved
     */
    public function resolveOnFailureMode(array $resolved): string
    {
        $channels = $this->channels();
        $seen = [];
        foreach ($resolved as $row) {
            $code = trim((string)($row['provider_code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $provider = $channels->getProvider($code);
            if (!$provider instanceof DropshipFreightProviderInterface) {
                continue;
            }
            $mode = DropshipFreightPolicy::normalize($provider->freightOnFailure());
            if ($mode === DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT) {
                return DropshipFreightPolicy::ON_FAILURE_BLOCK_CHECKOUT;
            }
        }

        return DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL;
    }

    /**
     * @param list<array<string, mixed>> $resolved
     * @param array<string, mixed> $address
     * @param array<string, mixed> $scope
     * @return array{total_minor:int,currency:string,segments:list<array<string,mixed>>,error:?string}
     */
    private function quoteResolved(array $resolved, array $address, array $scope, string $currency): array
    {
        $byProvider = [];
        foreach ($resolved as $row) {
            $code = (string)($row['provider_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $byProvider[$code][] = $row;
        }
        if ($byProvider === []) {
            return ['total_minor' => 0, 'currency' => $currency, 'segments' => [], 'error' => self::ERROR_FREIGHT_UNAVAILABLE];
        }

        $segments = [];
        $total = 0;
        foreach ($byProvider as $code => $rows) {
            $start = 'CN';
            foreach ($rows as $row) {
                $rc = strtoupper(trim((string)($row['remote_country'] ?? '')));
                if ($rc !== '') {
                    $start = $rc;
                    break;
                }
            }
            $end = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? '')));
            if ($end === '') {
                $end = 'CN';
            }
            $products = [];
            foreach ($rows as $row) {
                $products[] = [
                    'external_vid' => (string)($row['external_vid'] ?? ''),
                    'external_sku' => (string)($row['external_sku'] ?? ''),
                    'external_spu' => (string)($row['external_spu'] ?? ''),
                    'vid' => (string)($row['external_vid'] ?? ''),
                    'qty' => max(1, (int)($row['qty'] ?? 1)),
                    'quantity' => max(1, (int)($row['qty'] ?? 1)),
                ];
            }
            $request = [
                'start_country_code' => $start,
                'end_country_code' => $end,
                'startCountryCode' => $start,
                'endCountryCode' => $end,
                'zip' => (string)($address['postal_code'] ?? $address['postcode'] ?? ''),
                'products' => $products,
                'currency' => $currency,
                'scope' => $scope,
                'address' => $address,
            ];
            $opts = $this->aggregator()->quoteForProviders([$code], $request);
            $providerSegments = is_array($opts['segments'] ?? null) ? $opts['segments'] : [];
            if ($providerSegments === []) {
                return [
                    'total_minor' => 0,
                    'currency' => $currency,
                    'segments' => [],
                    'error' => self::ERROR_FREIGHT_UNAVAILABLE,
                ];
            }
            $best = null;
            foreach ($providerSegments as $seg) {
                if (!is_array($seg)) {
                    continue;
                }
                $minor = $this->segmentMinorInCurrency($seg, $currency);
                if ($best === null || $minor < $best['amount_minor']) {
                    $best = array_merge($seg, [
                        'amount_minor' => $minor,
                        'currency' => $currency,
                        'provider_code' => $code,
                    ]);
                }
            }
            if ($best === null) {
                return [
                    'total_minor' => 0,
                    'currency' => $currency,
                    'segments' => [],
                    'error' => self::ERROR_FREIGHT_UNAVAILABLE,
                ];
            }
            $segments[] = $best;
            $total += (int)$best['amount_minor'];
        }

        return [
            'total_minor' => $total,
            'currency' => $currency,
            'segments' => $segments,
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $seg
     */
    private function segmentMinorInCurrency(array $seg, string $targetCurrency): int
    {
        $minor = (int)($seg['amount_minor'] ?? 0);
        $from = strtoupper(trim((string)($seg['currency'] ?? 'USD'))) ?: 'USD';
        $to = strtoupper(trim($targetCurrency)) ?: 'CNY';
        if ($from === $to) {
            return max(0, $minor);
        }

        return $this->pricing()->convertOriginMinor($minor, $from, $to);
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @param list<array<string, mixed>> $resolved
     * @return array{0:int,1:list<array<string,mixed>>}
     */
    private function replacePackageAmounts(
        array $packages,
        array $resolved,
        int $providerMinor,
        int $localTotal,
    ): array {
        $dropshipKeys = [];
        foreach ($resolved as $row) {
            $sk = trim((string)($row['split_key'] ?? ''));
            if ($sk !== '') {
                $dropshipKeys[$sk] = true;
            }
            $dropshipKeys['offer:' . (int)($row['offer_id'] ?? 0)] = true;
        }

        $localDropshipShare = 0;
        $dropshipPackageIndexes = [];
        foreach ($packages as $i => $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $key = trim((string)($pkg['split_key'] ?? $pkg['package_key'] ?? ''));
            $isDropship = $key !== '' && isset($dropshipKeys[$key]);
            if (!$isDropship) {
                $pkgLines = is_array($pkg['lines'] ?? null) ? $pkg['lines'] : [];
                foreach ($pkgLines as $pl) {
                    if (!is_array($pl)) {
                        continue;
                    }
                    $offer = (int)($pl['offer_id'] ?? $pl['product_offer_id'] ?? 0);
                    if ($offer > 0 && isset($dropshipKeys['offer:' . $offer])) {
                        $isDropship = true;
                        break;
                    }
                }
            }
            if ($isDropship) {
                $localDropshipShare += (int)($pkg['amount_minor'] ?? 0);
                $dropshipPackageIndexes[] = $i;
            }
        }

        if ($dropshipPackageIndexes === []) {
            // Packages present but none matched → treat as all-dropship replace of total when all lines resolved
            return [max(0, $providerMinor), $packages];
        }

        $nonDropship = max(0, $localTotal - $localDropshipShare);
        $per = intdiv($providerMinor, count($dropshipPackageIndexes));
        $rem = $providerMinor - ($per * count($dropshipPackageIndexes));
        foreach ($dropshipPackageIndexes as $n => $idx) {
            $packages[$idx]['amount_minor'] = $per + ($n === 0 ? $rem : 0);
            $packages[$idx]['dropship_freight'] = true;
        }

        return [$nonDropship + $providerMinor, $packages];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function shippableLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (is_array($line) && (bool)($line['requires_shipping'] ?? true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineQty(array $line): int
    {
        foreach (['qty', 'qty_ordered', 'qty_minor'] as $k) {
            if (isset($line[$k]) && (int)$line[$k] > 0) {
                return (int)$line[$k];
            }
        }

        return 1;
    }

    private function aggregator(): DropshipFreightAggregator
    {
        if ($this->aggregator instanceof DropshipFreightAggregator) {
            return $this->aggregator;
        }

        return ObjectManager::getInstance(DropshipFreightAggregator::class);
    }

    private function channels(): DropshipChannelManager
    {
        if ($this->channels instanceof DropshipChannelManager) {
            return $this->channels;
        }

        return ObjectManager::getInstance(DropshipChannelManager::class);
    }

    private function pricing(): DropshipPricingService
    {
        if ($this->pricing instanceof DropshipPricingService) {
            return $this->pricing;
        }

        return ObjectManager::getInstance(DropshipPricingService::class);
    }

    private function listing(): DropshipListing
    {
        if ($this->listingModel instanceof DropshipListing) {
            return $this->listingModel;
        }

        return ObjectManager::getInstance(DropshipListing::class);
    }
}
