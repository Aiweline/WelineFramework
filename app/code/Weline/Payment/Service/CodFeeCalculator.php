<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentMethod;

/**
 * COD fee from PaymentMethod config.fee → cod_fee_amount_minor (not Local shipping).
 *
 * fee may be fixed major units (e.g. 5.00) or percent of base (e.g. "5%" / {"type":"percent","value":5}).
 */
final class CodFeeCalculator
{
    public const METHOD_CODE = 'cash_on_delivery';

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array<string, mixed> $config PaymentMethod.getConfigData()
     */
    public function fromConfig(array $config, int $baseMinor = 0, int $currencyPrecision = 2): int
    {
        if ($currencyPrecision < 0 || $currencyPrecision > 6) {
            $currencyPrecision = 2;
        }
        $scale = 10 ** $currencyPrecision;
        $fee = $config['fee'] ?? $config['cod_fee'] ?? null;
        if ($fee === null || $fee === '' || $fee === false) {
            return 0;
        }
        if (is_array($fee)) {
            $type = strtolower(trim((string)($fee['type'] ?? 'fixed')));
            $value = $fee['value'] ?? $fee['amount'] ?? 0;
            if ($type === 'percent' || $type === 'percentage') {
                return max(0, (int)round(max(0, $baseMinor) * ((float)$value) / 100));
            }
            return max(0, (int)round(((float)$value) * $scale));
        }
        if (is_string($fee)) {
            $trimmed = trim($fee);
            if ($trimmed !== '' && str_ends_with($trimmed, '%')) {
                $pct = (float)substr($trimmed, 0, -1);

                return max(0, (int)round(max(0, $baseMinor) * $pct / 100));
            }
            if (is_numeric($trimmed)) {
                return max(0, (int)round(((float)$trimmed) * $scale));
            }

            return 0;
        }
        if (is_int($fee) || is_float($fee)) {
            // Integers >= scale*10 treated as already-minor only when explicitly huge; prefer major units.
            return max(0, (int)round(((float)$fee) * $scale));
        }

        return 0;
    }

    public function forMethodCode(
        string $methodCode,
        int $baseMinor = 0,
        int $currencyPrecision = 2,
    ): int {
        $code = strtolower(trim($methodCode));
        if ($code === '' || !$this->isCodMethod($code)) {
            return 0;
        }
        $config = $this->loadConfig($code);
        if ($config === []) {
            return 0;
        }

        return $this->fromConfig($config, $baseMinor, $currencyPrecision);
    }

    public function isCodMethod(string $methodCode): bool
    {
        $code = strtolower(trim($methodCode));

        return $code === self::METHOD_CODE
            || $code === 'cod'
            || str_contains($code, 'cash_on_delivery')
            || str_ends_with($code, '_cod');
    }

    /** @return array<string, mixed> */
    private function loadConfig(string $code): array
    {
        try {
            /** @var PaymentMethod $model */
            $model = $this->objectManager->getInstance(PaymentMethod::class, [], false);
            $items = $model->reset()
                ->where(PaymentMethod::schema_fields_CODE, $code)
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($items) ? ($items[0] ?? null) : null;
            if ($row instanceof PaymentMethod && (int)$row->getId() > 0) {
                return $row->getConfigData();
            }
        } catch (\Throwable) {
        }

        return [];
    }
}
