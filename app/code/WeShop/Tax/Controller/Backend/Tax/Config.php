<?php

declare(strict_types=1);

namespace WeShop\Tax\Controller\Backend\Tax;

use WeShop\Tax\Service\TaxService;
use Weline\Admin\Controller\BaseController;

class Config extends BaseController
{
    public function __construct(
        private readonly TaxService $taxService
    ) {
    }

    public function index(): string
    {
        $this->assign([
            'title' => __('Tax Configuration'),
            'taxConfigUrl' => $this->getUrl('*/backend/tax/config'),
            'saveUrl' => $this->getUrl('*/backend/tax/config/save'),
        ]);

        return (string) $this->fetchBase('WeShop_Tax::templates/Backend/Tax/Config/index.phtml');
    }

    public function save(): string
    {
        try {
            $defaultRate = (float) ($this->request->getParam('default_rate', 0) ?? 0);
            $applyToShipping = (bool) ($this->request->getParam('apply_to_shipping', false) ?? false);
            $pricesIncludeTax = (bool) ($this->request->getParam('prices_include_tax', false) ?? false);
            $countryRates = $this->request->getParam('country_rates', []);
            $regionRates = $this->request->getParam('region_rates', []);

            if ($defaultRate < 0 || $defaultRate > 1) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('Default tax rate must be between 0 and 1 (0%% to 100%%).'),
                ]);
            }

            $context = [
                'default_rate' => $defaultRate,
                'apply_to_shipping' => $applyToShipping,
                'prices_include_tax' => $pricesIncludeTax,
                'country_rates' => is_array($countryRates) ? $countryRates : [],
                'region_rates' => is_array($regionRates) ? $regionRates : [],
            ];

            $testSubtotal = (float) ($this->request->getParam('test_subtotal', 100) ?? 100);
            $testCountry = $this->request->getParam('test_country', null);
            $testRegion = $this->request->getParam('test_region', null);
            $testTaxAmount = $this->taxService->calculateTax($testSubtotal, $testCountry, $testRegion, $context);
            $testTaxRate = $this->taxService->getTaxRate($testCountry, $testRegion, $context);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Tax configuration saved successfully.'),
                'data' => [
                    'test_result' => [
                        'subtotal' => $testSubtotal,
                        'country' => $testCountry,
                        'region' => $testRegion,
                        'tax_rate' => $testTaxRate,
                        'tax_amount' => $testTaxAmount,
                    ],
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}

