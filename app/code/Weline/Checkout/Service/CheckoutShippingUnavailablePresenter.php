<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

/**
 * Maps shipping quote diagnostics to a storefront empty-state.
 * Visible copy stays customer-friendly; machine reason stays in reason_code only.
 */
final class CheckoutShippingUnavailablePresenter
{
    /**
     * @param array<string, mixed> $diagnostics
     * @param list<array<string, mixed>> $quoteLines Lines already weight-enriched for quote
     * @return array{title:string,message:string,reason_code:string}
     */
    public function present(array $diagnostics, array $quoteLines = [], string $countryCode = ''): array
    {
        $countryCode = strtoupper(trim($countryCode !== ''
            ? $countryCode
            : (string)($diagnostics['country_code'] ?? '')));
        $reasons = [];
        foreach ((array)($diagnostics['unavailable_reasons'] ?? []) as $reason) {
            $reason = strtolower(trim((string)$reason));
            if ($reason !== '') {
                $reasons[] = $reason;
            }
        }
        if (!empty($diagnostics['missing_weight']) && !in_array('missing_weight', $reasons, true)) {
            $reasons[] = 'missing_weight';
        }
        if (!empty($diagnostics['fx_skipped']) && !in_array('fx_rate_missing', $reasons, true)) {
            $reasons[] = 'fx_rate_missing';
        }
        if ($reasons === []) {
            foreach ($quoteLines as $line) {
                if (!(bool)($line['requires_shipping'] ?? true)) {
                    continue;
                }
                if ((int)($line['weight_minor'] ?? 0) <= 0) {
                    $reasons[] = 'missing_weight';
                    break;
                }
            }
        }
        $primary = $reasons[0] ?? 'no_shipping_option';
        $destHint = $countryCode !== ''
            ? (string)__('收货地 %{1} 本身通常可送达时，原因一般不在国家。', [$countryCode])
            : '';

        return match ($primary) {
            'missing_weight' => [
                'title' => (string)__('暂时无法计算运费'),
                'message' => trim((string)__(
                    '购物车中有商品缺少重量，国际运费需按重量计算，因此目前无法报价。请联系客服协助补全商品重量后再结账。',
                ) . ($destHint !== '' ? ' ' . $destHint : '')),
                'reason_code' => 'missing_weight',
            ],
            'missing_dims' => [
                'title' => (string)__('暂时无法计算运费'),
                'message' => (string)__(
                    '需配送的商品缺少长宽高尺寸，体积重量无法计算。请联系客服补全尺寸后再试。',
                ),
                'reason_code' => 'missing_dims',
            ],
            'over_max_weight' => [
                'title' => (string)__('超出可配送重量'),
                'message' => (string)__(
                    '订单计费重量超过当前配送方案上限。请减少件数、拆成多笔订单，或联系客服改用重货方案。',
                ),
                'reason_code' => 'over_max_weight',
            ],
            'fx_rate_missing' => [
                'title' => (string)__('暂时无法计算运费'),
                'message' => (string)__(
                    '当前结账币种暂时无法换算运费。请切换币种，或联系客服协助处理。',
                ),
                'reason_code' => 'fx_rate_missing',
            ],
            'no_matched_lane' => [
                'title' => (string)__('当前地址暂无可用配送'),
                'message' => trim((string)__(
                    '收货地址暂未匹配到可用的配送服务。请确认国家或地区是否正确，或联系客服确认是否可送达。',
                ) . ($countryCode !== '' ? ' ' . (string)__('当前收货国家：%{1}。', [$countryCode]) : '')),
                'reason_code' => 'no_matched_lane',
            ],
            default => [
                'title' => (string)__('暂无可用配送方式'),
                'message' => (string)__(
                    '系统暂未返回可用的配送方案。请确认收货地址与商品信息，或联系客服了解具体原因（常见如商品缺重量/尺寸，或目的地限制）。',
                ),
                'reason_code' => $primary,
            ],
        };
    }
}
