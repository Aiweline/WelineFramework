<?php

declare(strict_types=1);

namespace WeShop\Tax\Controller\Frontend\Tax;

use WeShop\Tax\Service\TaxService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function __construct(
        private readonly TaxService $taxService,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function index(): string
    {
        $country = $this->request->getParam('country', null);
        $region = $this->request->getParam('region', null);
        $subtotal = (float) ($this->request->getParam('subtotal', 0) ?? 0);

        $context = $this->buildTaxContext();

        $taxRate = $this->taxService->getTaxRate($country, $region, $context);
        $taxBreakdown = $this->taxService->calculateTaxBreakdown($subtotal, $country, $region, $context);

        $this->assign([
            'title' => __('Tax Information'),
            'tax_rate' => $taxRate,
            'tax_breakdown' => $taxBreakdown,
            'subtotal' => $subtotal,
            'country' => $country,
            'region' => $region,
            'customer_logged_in' => $this->customerSession->isLoggedIn(),
            'calculate_url' => $this->getUrl('*/frontend/tax'),
        ]);

        return $this->fetch();
    }

    public function calculate(): string
    {
        try {
            $subtotal = (float) ($this->request->getParam('subtotal', 0) ?? 0);
            $country = $this->request->getParam('country', null);
            $region = $this->request->getParam('region', null);
            $shippingAmount = (float) ($this->request->getParam('shipping_amount', 0) ?? 0);
            $discount = (float) ($this->request->getParam('discount', 0) ?? 0);

            if ($subtotal < 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Subtotal cannot be negative.'),
                ]);
            }

            $context = $this->buildTaxContext();
            if ($shippingAmount > 0) {
                $context['shipping_amount'] = $shippingAmount;
            }
            if ($discount > 0) {
                $context['discount'] = $discount;
            }

            $taxBreakdown = $this->taxService->calculateTaxBreakdown($subtotal, $country, $region, $context);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Tax calculated successfully.'),
                'data' => [
                    'tax_amount' => $taxBreakdown['tax_amount'],
                    'tax_rate' => $taxBreakdown['tax_rate'],
                    'taxable_amount' => $taxBreakdown['taxable_amount'],
                    'included_tax' => $taxBreakdown['included_tax'],
                    'chargeable_tax' => $taxBreakdown['chargeable_tax'],
                    'apply_to_shipping' => $taxBreakdown['apply_to_shipping'],
                    'prices_include_tax' => $taxBreakdown['prices_include_tax'],
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function post(): string
    {
        return $this->calculate();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTaxContext(): array
    {
        $context = [];

        $applyToShipping = $this->request->getParam('apply_to_shipping', null);
        if ($applyToShipping !== null) {
            $context['apply_to_shipping'] = (bool) $applyToShipping;
        }

        $pricesIncludeTax = $this->request->getParam('prices_include_tax', null);
        if ($pricesIncludeTax !== null) {
            $context['prices_include_tax'] = (bool) $pricesIncludeTax;
        }

        return $context;
    }
}
