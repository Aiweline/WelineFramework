<?php

declare(strict_types=1);

namespace Weline\Payment\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\PaymentMethodIncentiveQuoteInterface;

/**
 * Weline_Payment::checkout::available_methods::enrich — 注入列表扁字段激励价。
 */
final class CheckoutAvailableMethodsIncentiveEnrichObserver implements ObserverInterface
{
    /** @var list<string> 零小数货币（与 PaymentQueryProvider::amountMinor 对齐） */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public function execute(Event &$event): void
    {
        $methods = $event->getData('methods');
        if (!\is_array($methods) || $methods === []) {
            return;
        }

        $context = $event->getData('context');
        if (!\is_array($context)) {
            $context = [];
        }

        $currency = strtoupper(trim((string) (
            $context['currency']
            ?? $context['currency_code']
            ?? ((\is_array($context['totals'] ?? null) ? ($context['totals']['currency'] ?? null) : null))
            ?? 'CNY'
        )));
        if ($currency === '') {
            $currency = 'CNY';
        }

        $baseMinor = self::resolveBaseAmountMinor($context, $currency);

        try {
            /** @var PaymentMethodIncentiveQuoteInterface $quoteService */
            $quoteService = ObjectManager::getInstance(PaymentMethodIncentiveQuoteInterface::class);
        } catch (\Throwable) {
            return;
        }

        foreach ($methods as $idx => $method) {
            if (!\is_array($method)) {
                continue;
            }
            $code = strtolower(trim((string) ($method['code'] ?? '')));
            $runtime = \is_array($method['runtime_config'] ?? null) ? $method['runtime_config'] : [];
            $available = !empty($method['enabled']);
            $quote = $quoteService->quote($code, $runtime, $baseMinor, $currency, $available);
            $fields = $quoteService->toListPayloadFields($quote);
            $methods[$idx] = array_replace($method, $fields);
        }

        $event->setData('methods', $methods);
    }

    /**
     * 结账 context → amount_minor。
     * 显式优先 minor 字段；否则 major × 精度。禁止在 ?? 链中间用 ?? 0 阻断后续 major 换算。
     *
     * @param array<string, mixed> $context
     */
    public static function resolveBaseAmountMinor(array $context, string $currencyCode = 'CNY'): int
    {
        if (isset($context['amount_minor']) && is_numeric($context['amount_minor'])) {
            return max(0, (int) $context['amount_minor']);
        }
        if (isset($context['grand_total_minor']) && is_numeric($context['grand_total_minor'])) {
            return max(0, (int) $context['grand_total_minor']);
        }

        $totals = \is_array($context['totals'] ?? null) ? $context['totals'] : [];
        if (isset($totals['grand_total_minor']) && is_numeric($totals['grand_total_minor'])) {
            return max(0, (int) $totals['grand_total_minor']);
        }

        $currencyCode = strtoupper(trim($currencyCode));
        $minorUnit = \in_array($currencyCode, self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;

        foreach (['grand_total', 'total_amount', 'amount', 'subtotal'] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                return max(0, (int) round(((float) $context[$key]) * $minorUnit));
            }
            if (isset($totals[$key]) && is_numeric($totals[$key])) {
                return max(0, (int) round(((float) $totals[$key]) * $minorUnit));
            }
        }

        return 0;
    }
}
