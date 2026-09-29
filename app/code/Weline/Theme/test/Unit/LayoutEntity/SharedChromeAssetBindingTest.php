<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class SharedChromeAssetBindingTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testFrozenChromeKeepsItsExplicitLayoutAssetsDespiteNewDefaults(): void
    {
        $this->registry->definitions['label']=new ThemeComponentDefinition(module:'Fixture',type:'header',code:'label',name:'label',renderMode:ThemeRenderable::MODE_TEMPLATE_CONTENT,
            defaultConfig:['_layout_source'=>'Weline_Theme::css/widgets/header-search-amazon.css'],templateContent:'<b><?= $this->getData("text") ?></b>');
        $node=$this->node('a','generic-actions','SAVED',0);
        $node['widget_type']='header';
        $node['layout_source']='Weline_Theme::css/widgets/widget-content-text-block-default.css';
        $phtml=(new LayoutRelationCompiler($this->registry))->compile('<header data-wslot="generic-actions"></header>',[$node]);
        $html=(new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($phtml);
        self::assertStringContainsString('<b>SAVED</b>',$html);
        self::assertStringContainsString('widget-content-text-block-default.css',$html);
        self::assertStringNotContainsString('header-search-amazon.css',$html);
    }
}
