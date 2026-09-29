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

final class ChromeRemovalBakePersistenceTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testAutomaticRebakeCannotReviveCanonicalRemovalWithANewUid(): void
    {
        $removed=$this->removed();
        $slots=RequiredDefaultInjectionContract::merge(['footer-help-links'=>[$removed]],'homepage',[$this->declaration()],[]);
        self::assertCount(1,$slots['footer-help-links']);
        self::assertSame($removed['node_uid'],$slots['footer-help-links'][0]['node_uid']);
        self::assertStringNotContainsString('REMOVED',$this->renderSlots($slots));
    }
    public function testOmittedPlacementStaysEmptyAcrossRepeatedGeneration(): void
    {
        $omission=['slot_id'=>'footer-help-links','widget_module'=>'Fixture','widget_code'=>'label'];
        $slots=RequiredDefaultInjectionContract::merge([],'homepage',[$this->declaration()],[$omission]);
        $again=RequiredDefaultInjectionContract::merge($slots,'homepage',[$this->declaration()],[$omission]);
        self::assertSame('<footer data-wslot="footer-help-links"></footer>',$this->renderSlots($again));
    }
    public function testCanonicalDeletionBeatsAStaleActiveFlag(): void
    {
        $node=$this->removed();$node['is_active']=true;
        self::assertStringNotContainsString('REMOVED',$this->renderSlots(['footer-help-links'=>[$node]]));
    }
    public function testOrdinaryInactiveNodeIsNotInventedAsUserRemoval(): void
    {
        $node=$this->removed();$node['source']='manual';
        $slots=RequiredDefaultInjectionContract::merge(['footer-help-links'=>[$node]],'homepage',[$this->declaration()],[]);
        self::assertSame('manual',$slots['footer-help-links'][0]['source']);
        self::assertStringNotContainsString('REMOVED',$this->renderSlots($slots));
        $slots['footer-help-links'][0]['is_active']=true;
        self::assertStringContainsString('<b>REMOVED</b>',$this->renderSlots($slots));
    }
    public function testFullPayloadOmittingCanonicalDeletionCannotReinjectDefault(): void
    {
        $removed=$this->removed(); $before=['nodes'=>[$removed['node_uid']=>$removed]];
        $commands=(new \Weline\Theme\Service\Scoped\ThemeLayoutPayloadDiffer())->diff($before,['nodes'=>[]]);
        $payload=(new \Weline\Theme\Service\Scoped\ThemePatchEngine())->apply($before,$commands);
        $slots=RequiredDefaultInjectionContract::merge(['footer-help-links'=>array_values($payload['nodes'])],'homepage',[$this->declaration()],[]);
        self::assertStringNotContainsString('<b>REMOVED</b>',$this->renderSlots($slots),'A full snapshot omission must not erase the prior manual uninstall intent.');
    }
    private function removed(): array
    {
        return $this->node('a','footer-help-links','REMOVED',0)+['area'=>'footer','is_active'=>false,'source'=>'user_deleted'];
    }
    private function declaration(): array
    {
        return ['module'=>'Fixture','type'=>'content','code'=>'label','default_injections'=>[['layout_type'=>'homepage','slot'=>'footer-help-links','area'=>'footer','required'=>true,'config'=>['text'=>'REMOVED']]]];
    }
    private function renderSlots(array $slots): string
    {
        $nodes=[];foreach($slots as $items){foreach($items as $node){$nodes[$node['node_uid']]=$node;}}
        return (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent((new LayoutRelationCompiler($this->registry))->compile('<footer data-wslot="footer-help-links"></footer>',$nodes));
    }
}
