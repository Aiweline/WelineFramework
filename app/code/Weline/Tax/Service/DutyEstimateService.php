<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

/**
 * Phase-1 checkout duty / import-tax estimate (Tax-owned).
 *
 * Shipping only emits duty_notice (Incoterm); amounts live here.
 * DDU + cross-border → charge estimate into checkout totals.
 * DAP → notice only (not collected at checkout).
 * DDP → seller-included; do not charge buyer again.
 * Same-country → zero (domestic lanes may still carry a DDU notice string).
 */
final class DutyEstimateService
{
    /** Mirror ShippingIncotermService::NOTICE_* without requiring Shipping. */
    public const NOTICE_DDP = 'duty_included_by_seller_estimate_separate';
    public const NOTICE_DAP = 'duties_may_apply_at_delivery';
    public const NOTICE_DDU = 'duties_taxes_not_included_in_shipping';

    public const REASON_NO_NOTICE = 'no_duty_notice';
    public const REASON_DOMESTIC = 'domestic_no_duty';
    public const REASON_DAP_AT_DELIVERY = 'dap_not_collected_at_checkout';
    public const REASON_DDP_SELLER = 'ddp_seller_included';
    public const REASON_ESTIMATED = 'ddu_cross_border_estimate';
    public const REASON_NO_RATE = 'no_rate_for_destination';

    /**
     * Destination ISO2 → duty_bps + vat_bps (basis points).
     * Phase-1 table; replace with HS/rule engine later without Checkout coupling.
     *
     * @var array<string, array{duty_bps:int, vat_bps:int}>
     */
    private const DEFAULT_RATES = [
        // EU apparel-ish baseline (phase-1; HS refinement later)
        'DE' => ['duty_bps' => 1200, 'vat_bps' => 1900],
        'FR' => ['duty_bps' => 1200, 'vat_bps' => 2000],
        'IT' => ['duty_bps' => 1200, 'vat_bps' => 2200],
        'ES' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'NL' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'BE' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'AT' => ['duty_bps' => 1200, 'vat_bps' => 2000],
        'IE' => ['duty_bps' => 1200, 'vat_bps' => 2300],
        'SE' => ['duty_bps' => 1200, 'vat_bps' => 2500],
        'PL' => ['duty_bps' => 1200, 'vat_bps' => 2300],
        'PT' => ['duty_bps' => 1200, 'vat_bps' => 2300],
        'FI' => ['duty_bps' => 1200, 'vat_bps' => 2550],
        'DK' => ['duty_bps' => 1200, 'vat_bps' => 2500],
        'GR' => ['duty_bps' => 1200, 'vat_bps' => 2400],
        'CZ' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'HU' => ['duty_bps' => 1200, 'vat_bps' => 2700],
        'RO' => ['duty_bps' => 1200, 'vat_bps' => 1900],
        'BG' => ['duty_bps' => 1200, 'vat_bps' => 2000],
        'HR' => ['duty_bps' => 1200, 'vat_bps' => 2500],
        'SK' => ['duty_bps' => 1200, 'vat_bps' => 2300],
        'SI' => ['duty_bps' => 1200, 'vat_bps' => 2200],
        'LT' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'LV' => ['duty_bps' => 1200, 'vat_bps' => 2100],
        'EE' => ['duty_bps' => 1200, 'vat_bps' => 2200],
        'LU' => ['duty_bps' => 1200, 'vat_bps' => 1700],
        'MT' => ['duty_bps' => 1200, 'vat_bps' => 1800],
        'CY' => ['duty_bps' => 1200, 'vat_bps' => 1900],
        'GB' => ['duty_bps' => 1000, 'vat_bps' => 2000],
        'CH' => ['duty_bps' => 500, 'vat_bps' => 810],
        'NO' => ['duty_bps' => 1000, 'vat_bps' => 2500],
        'IS' => ['duty_bps' => 1000, 'vat_bps' => 2400],
        'US' => ['duty_bps' => 800, 'vat_bps' => 0],
        'CA' => ['duty_bps' => 800, 'vat_bps' => 500],
        'MX' => ['duty_bps' => 1000, 'vat_bps' => 1600],
        'BR' => ['duty_bps' => 1500, 'vat_bps' => 1700],
        'CL' => ['duty_bps' => 600, 'vat_bps' => 1900],
        'AR' => ['duty_bps' => 1500, 'vat_bps' => 2100],
        'AU' => ['duty_bps' => 500, 'vat_bps' => 1000],
        'NZ' => ['duty_bps' => 500, 'vat_bps' => 1500],
        'JP' => ['duty_bps' => 600, 'vat_bps' => 1000],
        'KR' => ['duty_bps' => 800, 'vat_bps' => 1000],
        'SG' => ['duty_bps' => 0, 'vat_bps' => 900],
        'IN' => ['duty_bps' => 1000, 'vat_bps' => 1800],
        'TH' => ['duty_bps' => 1000, 'vat_bps' => 700],
        'MY' => ['duty_bps' => 800, 'vat_bps' => 800],
        'ID' => ['duty_bps' => 1000, 'vat_bps' => 1100],
        'PH' => ['duty_bps' => 1000, 'vat_bps' => 1200],
        'VN' => ['duty_bps' => 1000, 'vat_bps' => 1000],
        'AE' => ['duty_bps' => 500, 'vat_bps' => 500],
        'SA' => ['duty_bps' => 500, 'vat_bps' => 1500],
        'TR' => ['duty_bps' => 1000, 'vat_bps' => 2000],
        'IL' => ['duty_bps' => 800, 'vat_bps' => 1700],
        'ZA' => ['duty_bps' => 1000, 'vat_bps' => 1500],
    ];

    /**
     * @param array{
     *   goods_subtotal_minor?:int,
     *   shipping_amount_minor?:int,
     *   destination_country?:string,
     *   origin_country?:string,
     *   duty_notice?:string,
     *   currency?:string,
     *   charge_customs_duty?:bool,
     *   charge_import_vat?:bool
     * } $input
     * @return array{
     *   charged_minor:int,
     *   duty_amount_minor:int,
     *   import_tax_amount_minor:int,
     *   duty_notice:string,
     *   reason:string,
     *   cross_border:bool,
     *   duty_bps:int,
     *   vat_bps:int,
     *   currency:string,
     *   lines:list<array<string,mixed>>
     * }
     */
    public function estimate(array $input): array
    {
        $dutyNotice = trim((string)($input['duty_notice'] ?? ''));
        $dest = $this->iso2((string)($input['destination_country'] ?? ''));
        $origin = $this->iso2((string)($input['origin_country'] ?? 'CN')) ?: 'CN';
        $currency = strtoupper(trim((string)($input['currency'] ?? 'CNY'))) ?: 'CNY';
        $goods = max(0, (int)($input['goods_subtotal_minor'] ?? 0));
        $shipping = max(0, (int)($input['shipping_amount_minor'] ?? 0));
        $chargeCustoms = array_key_exists('charge_customs_duty', $input)
            ? (bool)$input['charge_customs_duty']
            : true;
        $chargeImportVat = array_key_exists('charge_import_vat', $input)
            ? (bool)$input['charge_import_vat']
            : true;
        $crossBorder = $dest !== '' && $dest !== $origin;

        $empty = [
            'charged_minor' => 0,
            'duty_amount_minor' => 0,
            'import_tax_amount_minor' => 0,
            'duty_notice' => $dutyNotice,
            'reason' => self::REASON_NO_NOTICE,
            'cross_border' => $crossBorder,
            'duty_bps' => 0,
            'vat_bps' => 0,
            'currency' => $currency,
            'lines' => [],
        ];

        if ($dutyNotice === '') {
            return $empty;
        }

        if ($dutyNotice === self::NOTICE_DAP) {
            return array_merge($empty, [
                'reason' => self::REASON_DAP_AT_DELIVERY,
                'duty_notice' => $dutyNotice,
            ]);
        }

        if ($dutyNotice === self::NOTICE_DDP) {
            return array_merge($empty, [
                'reason' => self::REASON_DDP_SELLER,
                'duty_notice' => $dutyNotice,
            ]);
        }

        if ($dutyNotice !== self::NOTICE_DDU) {
            return array_merge($empty, ['duty_notice' => $dutyNotice]);
        }

        if (!$crossBorder) {
            // Domestic lanes must not keep DDU/DAP copy — that reads as import duty on 国内标快.
            return array_merge($empty, [
                'reason' => self::REASON_DOMESTIC,
                'duty_notice' => '',
            ]);
        }

        $rates = self::DEFAULT_RATES[$dest] ?? null;
        if ($rates === null) {
            return array_merge($empty, [
                'reason' => self::REASON_NO_RATE,
                'duty_notice' => $dutyNotice,
            ]);
        }

        $dutyBps = $chargeCustoms ? max(0, (int)$rates['duty_bps']) : 0;
        $vatBps = $chargeImportVat ? max(0, (int)$rates['vat_bps']) : 0;
        $cif = $goods + $shipping;
        $dutyMinor = $this->halfUp($cif, $dutyBps);
        $vatMinor = $this->halfUp($cif + $dutyMinor, $vatBps);
        $charged = $dutyMinor + $vatMinor;

        $lines = [];
        if ($dutyMinor > 0) {
            $lines[] = [
                'line_id' => 'duty:customs',
                'kind' => 'customs_duty',
                'tax_class_code' => 'customs_duty',
                'tax_amount_minor' => $dutyMinor,
                'taxable_amount_minor' => $cif,
                'rate_bps' => $dutyBps,
            ];
        }
        if ($vatMinor > 0) {
            $lines[] = [
                'line_id' => 'duty:import_vat',
                'kind' => 'import_tax',
                'tax_class_code' => 'import_vat',
                'tax_amount_minor' => $vatMinor,
                'taxable_amount_minor' => $cif + $dutyMinor,
                'rate_bps' => $vatBps,
            ];
        }

        return [
            'charged_minor' => $charged,
            'duty_amount_minor' => $dutyMinor,
            'import_tax_amount_minor' => $vatMinor,
            'duty_notice' => $dutyNotice,
            'reason' => $charged > 0 ? self::REASON_ESTIMATED : self::REASON_NO_RATE,
            'cross_border' => true,
            'duty_bps' => $dutyBps,
            'vat_bps' => $vatBps,
            'currency' => $currency,
            'lines' => $lines,
        ];
    }

    private function iso2(string $raw): string
    {
        $v = strtoupper(trim($raw));

        return preg_match('/^[A-Z]{2}$/', $v) === 1 ? $v : '';
    }

    private function halfUp(int $baseMinor, int $bps): int
    {
        if ($baseMinor <= 0 || $bps <= 0) {
            return 0;
        }

        return (int) intdiv(($baseMinor * $bps) + 5000, 10000);
    }
}
