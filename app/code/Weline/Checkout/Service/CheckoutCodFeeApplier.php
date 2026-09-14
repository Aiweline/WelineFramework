<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\CodFeeCalculator;

/**
 * Apply COD fee into checkout totals / freeze payload (Payment config.fee).
 */
final class CheckoutCodFeeApplier
{
    public function __construct(private readonly ?ObjectManager $objectManager = null)
    {
    }

    /**
     * @param array<string, mixed>|null $configOverride for tests
     * @return array{cod_fee_amount_minor:int,grand_total_minor:int}
     */
    public function apply(
        string $paymentMethod,
        int $baselineGrandMinor,
        int $currencyPrecision = 2,
        ?array $configOverride = null,
    ): array {
        $baseline = max(0, $baselineGrandMinor);
        $fee = 0;
        $method = strtolower(trim($paymentMethod));
        if ($method === '') {
            return ['cod_fee_amount_minor' => 0, 'grand_total_minor' => $baseline];
        }
        try {
            $om = $this->objectManager ?? ObjectManager::getInstance();
            /** @var CodFeeCalculator $calc */
            $calc = $om->getInstance(CodFeeCalculator::class);
            if (!$calc->isCodMethod($method)) {
                return ['cod_fee_amount_minor' => 0, 'grand_total_minor' => $baseline];
            }
            $fee = $configOverride !== null
                ? $calc->fromConfig($configOverride, $baseline, $currencyPrecision)
                : $calc->forMethodCode($method, $baseline, $currencyPrecision);
        } catch (\Throwable) {
            $fee = 0;
        }

        return [
            'cod_fee_amount_minor' => max(0, $fee),
            'grand_total_minor' => $baseline + max(0, $fee),
        ];
    }
}
