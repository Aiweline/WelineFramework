<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Api\Quote\ShippingQuote;
use Weline\Shipping\Api\Quote\ShippingQuoteRequest;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;

/**
 * Scope-aware minor-unit Quote API（fail-closed）.
 * forTesting() uses an in-memory rate table；生产可叠加 var harness（E2E）。
 */
final class ScopedShippingQuoteService implements ShippingQuoteServiceInterface
{
    public const ERROR_CURRENCY = 'shipping_quote_currency_missing';
    public const ERROR_TEMPLATE = 'shipping_quote_template_missing';
    public const ERROR_VERSION = 'shipping_quote_version_mismatch';
    public const ERROR_SERVICE = 'shipping_quote_service_unavailable';
    public const ERROR_EMPTY_SHIPPABLE = 'shipping_quote_no_shippable_lines';

    /** @var array<string, array{amount_minor:int,label?:string,currencies?:list<string>}> */
    private readonly array $rates;

    private readonly string $activeConfigVersion;

    private readonly bool $useMemory;

    private ?ShippingServiceManager $serviceManager;

    /**
     * @param array<string, array{amount_minor:int,label?:string,currencies?:list<string>}> $rates service_code => rate
     */
    public function __construct(
        array $rates = [],
        string $activeConfigVersion = '1',
        bool $useMemory = false,
        ?ShippingServiceManager $serviceManager = null,
    ) {
        $this->rates = $rates;
        $this->activeConfigVersion = $activeConfigVersion;
        $this->useMemory = $useMemory;
        $this->serviceManager = $serviceManager;
    }

    /**
     * @param array<string, array{amount_minor:int,label?:string,currencies?:list<string>}> $rates
     */
    public static function forTesting(array $rates = [], string $configVersion = '1'): self
    {
        return new self($rates, $configVersion, useMemory: true);
    }

    public function activeConfigVersion(): string
    {
        return $this->effectiveConfigVersion();
    }

    public function listOptions(ShippingQuoteRequest $request): array
    {
        $this->assertRequestBasics($request);
        if ($this->shippableCount($request) === 0) {
            return [];
        }
        $options = [];
        if ($request->configVersion !== '' && $request->configVersion !== $this->activeConfigVersion()) {
            throw new ShippingQuoteConflictException(
                self::ERROR_VERSION,
                __('运费配置版本已变更，请重新报价'),
                [
                    'request_config_version' => $request->configVersion,
                    'active_config_version' => $this->activeConfigVersion(),
                ],
            );
        }
        foreach ($this->effectiveRates($request) as $code => $rate) {
            if (!$this->currencyAllowed($rate, $request->currency)) {
                continue;
            }
            $options[] = [
                'service_code' => $code,
                'label' => (string)($rate['label'] ?? $code),
                'amount_minor' => (int)$rate['amount_minor'],
                'currency' => $request->currency,
            ];
        }
        if ($options === []) {
            throw new ShippingQuoteConflictException(
                self::ERROR_TEMPLATE,
                __('无可用配送模板（币种/区域）'),
                ['currency' => $request->currency],
            );
        }

        return $options;
    }

    public function quote(ShippingQuoteRequest $request, string $serviceCode): ShippingQuote
    {
        $this->assertRequestBasics($request);
        $activeConfigVersion = $this->effectiveConfigVersion();
        if ($request->configVersion !== $activeConfigVersion) {
            throw new ShippingQuoteConflictException(
                self::ERROR_VERSION,
                __('运费配置版本已变更，请重新报价'),
                [
                    'request_config_version' => $request->configVersion,
                    'active_config_version' => $activeConfigVersion,
                ],
            );
        }
        if ($this->shippableCount($request) === 0) {
            return new ShippingQuote(
                quoteId: $this->newQuoteId(),
                serviceCode: trim($serviceCode) ?: 'none',
                amountMinor: 0,
                currency: $request->currency,
                currencyPrecision: $request->currencyPrecision,
                configVersion: $activeConfigVersion,
                requestHash: $request->requestHash(),
                isFree: true,
                freeReason: 'virtual_only',
                expiresAt: gmdate('c', time() + 1800),
            );
        }
        $serviceCode = trim($serviceCode);
        if ($serviceCode === '') {
            throw new ShippingQuoteConflictException(self::ERROR_SERVICE, __('service_code 不能为空'));
        }
        $rate = $this->effectiveRates($request)[$serviceCode] ?? null;
        if ($rate === null) {
            throw new ShippingQuoteConflictException(
                self::ERROR_SERVICE,
                __('配送服务不可用：%{1}', [$serviceCode]),
                ['service_code' => $serviceCode],
            );
        }
        if (!$this->currencyAllowed($rate, $request->currency)) {
            throw new ShippingQuoteConflictException(
                self::ERROR_CURRENCY,
                __('配送服务不支持币种：%{1}', [$request->currency]),
                ['service_code' => $serviceCode, 'currency' => $request->currency],
            );
        }
        $amount = max(0, (int)$rate['amount_minor']);

        return new ShippingQuote(
            quoteId: $this->newQuoteId(),
            serviceCode: $serviceCode,
            amountMinor: $amount,
            currency: $request->currency,
            currencyPrecision: $request->currencyPrecision,
            configVersion: $activeConfigVersion,
            requestHash: $request->requestHash(),
            isFree: $amount === 0,
            freeReason: $amount === 0 ? (string)($rate['free_reason'] ?? 'zero_rate') : '',
            expiresAt: gmdate('c', time() + 1800),
        );
    }

    /**
     * @return array<string, array{amount_minor:int,label?:string,currencies?:list<string>}>
     */
    private function effectiveRates(ShippingQuoteRequest $request): array
    {
        $harness = ShippingQuoteHarnessCatalog::load();
        if ($harness !== null) {
            return $harness['rates'];
        }
        if (!$this->useMemory) {
            return $this->manager()->quoteRates(
                $request->address,
                $request->lines,
                $request->currency,
                $request->currencyPrecision,
            );
        }

        return $this->rates;
    }

    private function effectiveConfigVersion(): string
    {
        $harness = ShippingQuoteHarnessCatalog::load();
        if ($harness !== null) {
            return $harness['config_version'];
        }
        if (!$this->useMemory) {
            return $this->manager()->activeQuoteConfigVersion();
        }

        return $this->activeConfigVersion;
    }

    private function manager(): ShippingServiceManager
    {
        if ($this->serviceManager instanceof ShippingServiceManager) {
            return $this->serviceManager;
        }
        $resolved = \Weline\Framework\Manager\ObjectManager::getInstance(ShippingServiceManager::class);
        if (!$resolved instanceof ShippingServiceManager) {
            throw new ShippingQuoteConflictException(
                self::ERROR_TEMPLATE,
                __('配送配置服务不可用'),
            );
        }

        return $this->serviceManager = $resolved;
    }

    private function assertRequestBasics(ShippingQuoteRequest $request): void
    {
        if (trim($request->currency) === '') {
            throw new ShippingQuoteConflictException(
                self::ERROR_CURRENCY,
                __('结账币种缺失'),
            );
        }
        if ($request->currencyPrecision < 0 || $request->currencyPrecision > 6) {
            throw new ShippingQuoteConflictException(
                self::ERROR_CURRENCY,
                __('币种精度非法'),
            );
        }
    }

    /** @param array{amount_minor:int,label?:string,currencies?:list<string>} $rate */
    private function currencyAllowed(array $rate, string $currency): bool
    {
        $allowed = $rate['currencies'] ?? null;
        if ($allowed === null) {
            return true;
        }

        return in_array($currency, $allowed, true);
    }

    private function shippableCount(ShippingQuoteRequest $request): int
    {
        $n = 0;
        foreach ($request->lines as $line) {
            if ((bool)($line['requires_shipping'] ?? true)) {
                $n++;
            }
        }

        return $n;
    }

    private function newQuoteId(): string
    {
        return 'sq_' . bin2hex(random_bytes(8));
    }
}
