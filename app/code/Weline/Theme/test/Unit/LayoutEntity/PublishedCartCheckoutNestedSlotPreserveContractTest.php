<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class PublishedCartCheckoutNestedSlotPreserveContractTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testSparsePlacementKeepsCartAndCheckoutControllerContentAndNestedSlots(): void
    {
        foreach (['cart'=>['cart-content','cart-recommendations'],'checkout'=>['checkout-content','checkout-trust']] as $page=>$slots) {
            $source='<w:slot id="content"><section data-layout="'.$page.'"><div data-wslot="'.$slots[0].'"><?= $this->getData("controller_body") ?></div><aside data-wslot="'.$slots[1].'"></aside></section></w:slot>';
            $compiled=(new LayoutRelationCompiler($this->registry))->compile($source,[$this->node('a','content','SPARSE',0)]);
            $html=(new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($compiled,['controller_body'=>'<form>BUSINESS FORM</form>']);
            self::assertSame(1,substr_count($html,'<form>BUSINESS FORM</form>'));
            self::assertSame(1,substr_count($html,'<b>SPARSE</b>'));
            foreach($slots as $slot){self::assertStringContainsString('data-wslot="'.$slot.'"',$html);}
        }
    }
}
