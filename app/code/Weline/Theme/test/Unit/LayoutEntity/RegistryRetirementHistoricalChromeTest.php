<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Code upgrades may rebuild historical derived templates, while their saved intent remains immutable. */
final class RegistryRetirementHistoricalChromeTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testRetirementRebuildDoesNotRewriteHistoricalIntentOrManualRemoval(): void
    {
        $this->registry->definitions['manual']=$this->definition('manual','<b><?= $this->getData("text") ?></b>');
        $auto=$this->node('a','main','AUTO',0)+['source'=>'default_injection'];
        $manual=$this->node('b','main','MANUAL',1,'manual')+['source'=>'manual'];
        $deleted=$this->node('c','main','DELETED',2)+['source'=>'user_deleted','is_active'=>false];
        $intent=[$auto['node_uid']=>$auto,$manual['node_uid']=>$manual,$deleted['node_uid']=>$deleted];
        $before=serialize($intent);
        $changes=[['definition_retired'=>true,'widget_identity'=>['module'=>'Fixture','type'=>'content','code'=>'label'],'before'=>[['layout_type'=>'homepage','slot'=>'main','required'=>true]],'after'=>[]]];
        $derived=(new RequiredDefaultInjectionBakeMerger())->removeRetiredAutomaticNodes($intent,'homepage',$changes);
        $html=(new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent((new LayoutRelationCompiler($this->registry))->compile('<div data-wslot="main"></div>',$derived));
        self::assertSame($before,serialize($intent));
        self::assertArrayHasKey($deleted['node_uid'],$derived);
        self::assertSame('user_deleted',$derived[$deleted['node_uid']]['source']);
        self::assertStringContainsString('<b>MANUAL</b>',$html);
        self::assertStringNotContainsString('<b>AUTO</b>',$html);
        self::assertStringNotContainsString('<b>DELETED</b>',$html);
    }
}
