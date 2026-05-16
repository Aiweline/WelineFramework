<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Frontend\Service\StorefrontShellDataService;
use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\App\State;

class BaseControllerShellDataTest extends TestCase
{
    public function testInitCommonDataAssignsSharedShellValuesAndUsesStoreCurrencyFormatting(): void
    {
        $shellDataService = $this->createMock(StorefrontShellDataService::class);
        $shellDataService->expects($this->once())
            ->method('build')
            ->willReturn([
                'store_name' => 'Global Hub',
                'store_currency' => 'eur',
                'cart_count' => 5,
                'cart_total' => 88.8,
            ]);

        $currencyRateService = $this->createMock(CurrencyRateService::class);
        $currencyRateService->expects($this->exactly(2))
            ->method('format')
            ->willReturnCallback(static function (float $price, ?string $sourceCurrency, ?string $targetCurrency): string {
                if ($targetCurrency === 'GBP') {
                    return '£' . number_format($price, 2);
                }

                return '¥' . number_format($price, 2);
            });

        $controller = new class($shellDataService, $currencyRateService) extends BaseController {
            /**
             * @var array<string, mixed>
             */
            public array $assignedData = [];

            public function __construct(
                private readonly StorefrontShellDataService $shellDataService,
                private readonly CurrencyRateService $currencyRateService
            )
            {
            }

            public function exposeInitCommonData(): void
            {
                $this->initCommonData();
            }

            public function exposeGetStoreName(): string
            {
                return $this->getStoreName();
            }

            public function exposeFormatPrice(float $price, string $currency = ''): string
            {
                return $this->formatPrice($price, $currency);
            }

            protected function getStorefrontShellDataService(): StorefrontShellDataService
            {
                return $this->shellDataService;
            }

            protected function resolveCurrencyRateService(): CurrencyRateService
            {
                return $this->currencyRateService;
            }

            protected function assign(array|string $tpl_var, mixed $value = null): static
            {
                if (is_array($tpl_var)) {
                    foreach ($tpl_var as $key => $item) {
                        $this->assignedData[(string) $key] = $item;
                    }

                    return $this;
                }

                $this->assignedData[$tpl_var] = $value;
                return $this;
            }
        };

        $controller->exposeInitCommonData();

        $this->assertSame('Global Hub', $controller->assignedData['store_name']);
        $this->assertSame('eur', $controller->assignedData['store_currency']);
        $this->assertSame(5, $controller->assignedData['cart_count']);
        $this->assertSame(88.8, $controller->assignedData['cart_total']);
        $this->assertSame(State::getLangLocal(), $controller->assignedData['locale']);
        $this->assertSame('Global Hub', $controller->exposeGetStoreName());
        $this->assertSame('¥12.30', $controller->exposeFormatPrice(12.3, ''));
        $this->assertSame('£12.30', $controller->exposeFormatPrice(12.3, 'gbp'));
    }
}
