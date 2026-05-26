<?php

declare(strict_types=1);

namespace WeShop\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Payment\Service\PaymentCatalogueService;

class PaymentCatalogueServiceTest extends TestCase
{
    public function testCountryOptionsCoverIsoCountries(): void
    {
        $catalogue = new PaymentCatalogueService();
        $codes = array_column($catalogue->getCountryOptions(), 'code');

        $this->assertContains('US', $codes);
        $this->assertContains('CN', $codes);
        $this->assertContains('BR', $codes);
        $this->assertGreaterThanOrEqual(240, count($codes));
    }

    public function testCountryTagsUseStableCountryCodesOnly(): void
    {
        $catalogue = new PaymentCatalogueService();

        foreach ($catalogue->getMethodRegistry() as $code => $method) {
            foreach ((array) ($method['country_tags'] ?? []) as $tag) {
                $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', (string) $tag, $code . ' has invalid country tag');
            }
        }
    }
}
