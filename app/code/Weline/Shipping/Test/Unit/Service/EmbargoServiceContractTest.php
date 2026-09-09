<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\EmbargoService;

final class EmbargoServiceContractTest extends TestCase
{
    public function testEvaluateAgainstRulesUsesUnionAndReportsProvinceLevel(): void
    {
        /** @var EmbargoService $service */
        $service = (new \ReflectionClass(EmbargoService::class))->newInstanceWithoutConstructor();
        $rules = [
            [
                'scope_type' => 'website',
                'region_type' => 'province',
                'country_code' => 'AU',
                'region_id' => 0,
                'region_code' => 'AU-NSW',
                'street_id' => 0,
            ],
            [
                'scope_type' => 'channel',
                'region_type' => 'country',
                'country_code' => 'NZ',
                'region_id' => 0,
                'region_code' => 'NZ',
                'street_id' => 0,
            ],
        ];

        $provinceHit = $service->evaluateAgainstRules([
            'country_code' => 'AU',
            'province_code' => 'AU-NSW',
        ], $rules);
        self::assertTrue($provinceHit['blocked']);
        self::assertSame('province', $provinceHit['level']);
        self::assertNotSame('', (string)$provinceHit['message']);

        $otherProvince = $service->evaluateAgainstRules([
            'country_code' => 'AU',
            'province_code' => 'AU-VIC',
        ], $rules);
        self::assertFalse($otherProvince['blocked']);

        $countryHit = $service->evaluateAgainstRules([
            'country_code' => 'NZ',
        ], $rules);
        self::assertTrue($countryHit['blocked']);
        self::assertSame('country', $countryHit['level']);
        self::assertSame('channel', $countryHit['scope_type']);
    }

    public function testRegionProviderExposesEmbargoOperations(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/RegionQueryProvider.php');
        self::assertStringContainsString("'embargo_evaluate'", $src);
        self::assertStringContainsString("'embargo_countries'", $src);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Region.php');
        self::assertStringContainsString('embargo_evaluate', $controller);
        $addressJs = (string)file_get_contents(dirname(__DIR__, 4) . '/Theme/view/statics/js/address.js');
        self::assertStringContainsString('evaluateEmbargo', $addressJs);
        self::assertStringContainsString('is-embargoed', $addressJs);
        self::assertStringContainsString('embargoCoversControl', $addressJs);
        self::assertStringContainsString('markChildrenInheritedEmbargo', $addressJs);
    }
}
