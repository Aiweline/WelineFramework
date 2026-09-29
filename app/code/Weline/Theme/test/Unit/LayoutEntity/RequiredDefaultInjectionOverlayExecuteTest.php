<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Required placements are merged before PHTML generation; no post-render overlay remains. */
final class RequiredDefaultInjectionOverlayExecuteTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testRepeatedDefaultMergeRendersExactlyOneInstance(): void
    {
        $declarations=[$this->declaration('label','REQUIRED',0)];
        $first=RequiredDefaultInjectionContract::merge([], 'homepage',$declarations,[]);
        $again=RequiredDefaultInjectionContract::merge($first,'homepage',$declarations,[]);
        $html=$this->renderSlots($again,'<div data-wslot="main"></div>');
        self::assertSame(1,substr_count($html,'<b>REQUIRED</b>'));
        self::assertSame($first['main'][0]['node_uid'],$again['main'][0]['node_uid']);
    }
    public function testMultipleRequiredSiblingsKeepSavedOrder(): void
    {
        $this->registry->definitions['other']=$this->definition('other','<b><?= $this->getData("text") ?></b>');
        $slots=RequiredDefaultInjectionContract::merge([],'homepage',[$this->declaration('label','SECOND',2),$this->declaration('other','FIRST',1)],[]);
        $html=$this->renderSlots($slots,'<div data-wslot="main" data-wslot-multiple="true"></div>');
        self::assertSame(1,substr_count($html,'<b>FIRST</b>'));
        self::assertSame(1,substr_count($html,'<b>SECOND</b>'));
        self::assertLessThan(strpos($html,'SECOND'),strpos($html,'FIRST'));
    }
    public function testMissingSourceSlotDoesNotCreateGhostMarkup(): void
    {
        $slots=RequiredDefaultInjectionContract::merge([],'homepage',[$this->declaration('label','GHOST',0)],[]);
        self::assertSame('<main>BUSINESS</main>',$this->renderSlots($slots,'<main>BUSINESS</main>'));
    }
    private function declaration(string $code,string $text,int $order): array
    {
        return ['module'=>'Fixture','type'=>'content','code'=>$code,'default_injections'=>[['layout_type'=>'homepage','slot'=>'main','area'=>'content','required'=>true,'sort_order'=>$order,'config'=>['text'=>$text]]]];
    }
    private function renderSlots(array $slots,string $source): string
    {
        $nodes=[];foreach($slots as $items){foreach($items as $node){$nodes[$node['node_uid']]=$node;}}
        return (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent((new LayoutRelationCompiler($this->registry))->compile($source,$nodes));
    }
}
