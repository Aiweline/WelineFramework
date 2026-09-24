<?php
declare(strict_types=1);
namespace Weline\Shipping\Test\Unit\Service;
use PHPUnit\Framework\TestCase;
use Weline\Shipping\Service\PublicTariffTableCompiler;
use Weline\Shipping\Service\RateCalculationService;
use Weline\Shipping\Model\RateTemplate;
final class PublicTariffTableCompilerTest extends TestCase
{
    public function testMaximumUsesEachCarrierUpperWeightAndAppliesMarkupOnce(): void
    {
        $common=['source_url'=>'https://example.test/tariff','effective_date'=>'2026-01-01','currency'=>'CNY','volume_divisor'=>5000];
        $offers=[
            $common+['source_id'=>'a','product'=>'standard','rows'=>[['upper_kg'=>0.5,'cost_cny'=>'10.01'],['upper_kg'=>1,'cost_cny'=>'15.00']]],
            $common+['source_id'=>'b','product'=>'standard','rows'=>[['upper_kg'=>1,'cost_cny'=>'12.00']]],
        ];
        $compiler=new PublicTariffTableCompiler();
        $out=$compiler->compileCountry('US',$offers);
        self::assertSame(['18.00','22.50'],array_column($out['brackets'],'price'));
        self::assertSame('b',$out['audit'][0]['selected_source_id']);
        self::assertSame($out,$compiler->compileCountry('US',$offers));
        $offers=[$common+['source_id'=>'c','product'=>'standard','rows'=>[['upper_kg'=>1,'cost_cny'=>'10.01']]]];
        self::assertSame('15.02',$compiler->compileCountry('US',$offers)['brackets'][0]['price']);
    }
    public function testPublicWeightUpperBoundaryIsInclusiveWithoutChangingLegacyTables(): void
    {
        $tpl=new RateTemplate();
        $tpl->setData(['is_active'=>1,'calculation_type'=>'weight_table','max_weight_kg'=>1,'rate_brackets'=>json_encode([['min'=>0,'max'=>0.5,'price'=>'18.00'],['min'=>0.5,'max'=>1,'price'=>'22.50']]),'mixed_config'=>json_encode(['public_tariff'=>['upper_inclusive'=>true]])]);
        $calc=new RateCalculationService($this->createMock(\Weline\Framework\Manager\ObjectManager::class));
        self::assertSame(1800,$calc->calculateTemplateMinor($tpl,[['weight_minor'=>500,'qty_minor'=>1]],2));
        self::assertSame(2250,$calc->calculateTemplateMinor($tpl,[['weight_minor'=>501,'qty_minor'=>1]],2));
        $tpl->setData('mixed_config',null);
        self::assertSame(2250,$calc->calculateTemplateMinor($tpl,[['weight_minor'=>500,'qty_minor'=>1]],2));
    }
    public function testDifferentVolumeDivisorsCannotBeCollapsedIntoOneWeightTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PublicTariffTableCompiler())->compileCountry('US',[['source_id'=>'a','source_url'=>'https://example.test','effective_date'=>'2026-01-01','product'=>'standard','currency'=>'CNY','volume_divisor'=>6000,'rows'=>[['upper_kg'=>1,'cost_cny'=>'10']]]]);
    }
    public function testOfficialSnapshotGoldenAndWeightBoundaries(): void
    {
        $data=\Weline\Shipping\Service\PublicTariffSeedService::loadOfficialSnapshot();
        $compiler=new PublicTariffTableCompiler();
        foreach (['US'=>'1622.97','CA'=>'1622.97','JP'=>'1167.72','DE'=>'1761.82','GB'=>'1761.82','AU'=>'1402.88'] as $cc=>$price) {
            self::assertSame($price,$compiler->quote($data['countries'][$cc]['mixed_config']['public_tariff']['offers'],1)['retail_cny']);
        }
        $offers=$data['countries']['US']['mixed_config']['public_tariff']['offers'];
        $sf=array_values(array_filter($offers,static fn(array $o): bool=>$o['source_id']==='sf_2026_GE+'));
        foreach ([19.5,20.0,20.01,21.0] as $weight) {
            $quote=$compiler->quote($sf,$weight);
            self::assertSame($weight<=19.5 ? 19.5 : (float)ceil($weight),$quote['candidates'][0]['quoted_upper_kg']);
        }
        self::assertSame('7586.25',$compiler->quote($sf,20)['retail_cny']);
        self::assertSame('7965.56',$compiler->quote($sf,20.01)['retail_cny']);
        self::assertSame(1001.0,$compiler->quote($sf,1000.01)['candidates'][0]['quoted_upper_kg']);
        // A finite DHL source must never extend its final fixed or per-kg price past coverage.
        $dhl=array_values(array_filter($offers,static fn(array $o): bool=>$o['source_id']==='dhl_2026_express_worldwide'));
        self::assertSame(31.0,$compiler->quote($dhl,30.01)['candidates'][0]['quoted_upper_kg']);
        $this->expectException(\Weline\Shipping\Exception\ShippingRateUnavailableException::class);
        $compiler->quote($dhl,3000.01);
    }

    public function testRuntimeTailAndDimensionWeightUseAuditedRates(): void
    {
        $data=\Weline\Shipping\Service\PublicTariffSeedService::loadOfficialSnapshot()['countries']['US'];
        $tpl=new RateTemplate();
        $tpl->setData(['is_active'=>1,'calculation_type'=>'weight_table','max_weight_kg'=>null,'rate_brackets'=>json_encode($data['brackets']),'mixed_config'=>json_encode($data['mixed_config'])]);
        $calc=new RateCalculationService($this->createMock(\Weline\Framework\Manager\ObjectManager::class));
        self::assertSame(162297,$calc->calculateTemplateMinor($tpl,[['weight_minor'=>500,'qty_minor'=>1,'length_cm'=>20,'width_cm'=>25,'height_cm'=>10]],2));
        $expected=(new PublicTariffTableCompiler())->quote($data['mixed_config']['public_tariff']['offers'],31)['retail_cny'];
        self::assertSame((int)round((float)$expected*100),$calc->calculateTemplateMinor($tpl,[['weight_minor'=>31000,'qty_minor'=>1]],2));
    }
}
