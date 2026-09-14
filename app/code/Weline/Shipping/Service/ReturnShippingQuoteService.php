<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Exception\ShippingRateUnavailableException;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingCommercePolicy;

/**
 * Return shipping quote — independent of forward free-shipping.
 */
final class ReturnShippingQuoteService
{
    public const TPL_DOMESTIC = 'SEED_TPL_RETURN_DOMESTIC';
    public const TPL_INTL = 'SEED_TPL_RETURN_INTL';

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly RateCalculationService $rateCalculationService,
        private readonly ShippingCommercePolicyService $commercePolicy,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $address
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{amount_minor:int,currency:string,return_policy:string,template_code:string,reason?:string}
     */
    public function quote(
        array $lines,
        array $address,
        string $currency = 'CNY',
        int $currencyPrecision = 2,
        ?array $context = null,
    ): array {
        $policy = $this->commercePolicy->resolve($context);
        $returnPolicy = $policy['return_policy'];
        $country = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? 'CN')));
        $tplCode = $country === 'CN' ? self::TPL_DOMESTIC : self::TPL_INTL;
        $scopeType = (string)($context['scope_type'] ?? RateTemplate::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);

        if ($returnPolicy === ShippingCommercePolicy::RETURN_SELLER) {
            return [
                'amount_minor' => 0,
                'currency' => strtoupper($currency),
                'return_policy' => $returnPolicy,
                'template_code' => $tplCode,
                'reason' => 'seller_pays',
            ];
        }

        $template = $this->loadTemplate($tplCode, $scopeType, $scopeId);
        if ($template === null) {
            throw new \RuntimeException('return_template_missing:' . $tplCode);
        }

        try {
            $base = $this->rateCalculationService->calculateTemplateMinor(
                $template,
                $lines,
                $currencyPrecision,
            );
        } catch (ShippingRateUnavailableException $e) {
            throw new \RuntimeException('return_rate_unavailable:' . $e->getMessage(), 0, $e);
        }

        if ($returnPolicy === ShippingCommercePolicy::RETURN_SPLIT_50) {
            $base = intdiv($base, 2);
        }

        return [
            'amount_minor' => max(0, $base),
            'currency' => strtoupper($currency),
            'return_policy' => $returnPolicy,
            'template_code' => $tplCode,
        ];
    }

    private function loadTemplate(string $code, string $scopeType, int $scopeId): ?RateTemplate
    {
        /** @var RateTemplate $model */
        $model = $this->objectManager->getInstance(RateTemplate::class, [], false);
        foreach ([[$scopeType, $scopeId], [RateTemplate::SCOPE_WEBSITE, 0]] as [$st, $sid]) {
            $items = $model->reset()
                ->where(RateTemplate::schema_fields_SCOPE_TYPE, $st)
                ->where(RateTemplate::schema_fields_SCOPE_ID, $sid)
                ->where(RateTemplate::schema_fields_TEMPLATE_CODE, $code)
                ->where(RateTemplate::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();
            $row = is_array($items) ? ($items[0] ?? null) : null;
            if ($row instanceof RateTemplate && (int)$row->getId() > 0) {
                return $row;
            }
        }

        return null;
    }
}
