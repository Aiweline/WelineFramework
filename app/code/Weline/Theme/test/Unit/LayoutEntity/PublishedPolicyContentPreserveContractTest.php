<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class PublishedPolicyContentPreserveContractTest extends TestCase
{
    use ResolvedPhtmlFixture;
    public function testSparseContentAdditionKeepsPolicyBodyAndHomepageDefaultTree(): void
    {
        foreach (['<div class="policy-main"><p><?= $this->getData("body") ?></p></div>','<div data-wslot="homepage-hero"><h1>HERO</h1></div><div data-wslot="homepage-featured">FEATURED</div>'] as $body) {
            $source='<w:slot id="content">'.$body.'</w:slot>';
            $compiled=(new LayoutRelationCompiler($this->registry))->compile($source,[$this->node('a','content','NEWSLETTER',0)]);
            $html=(new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent($compiled,['body'=>'PRIVACY TERMS']);
            self::assertSame(1,substr_count($html,'<b>NEWSLETTER</b>'));
            if(str_contains($body,'policy-main')){self::assertStringContainsString('<p>PRIVACY TERMS</p>',$html);}
            else{self::assertStringContainsString('<h1>HERO</h1>',$html);self::assertStringContainsString('FEATURED',$html);}
        }
    }
    public function testPolicyLayoutsLinkCookieNotCookies(): void
    {
        // Defaults live in Helper after policy-document extraction (layouts no longer hardcode @url).
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/PolicyDocumentDefaults.php');
        self::assertStringNotContainsString("'url_key' => 'cookies'", $src);
        self::assertStringContainsString("'url_key' => 'cookie'", $src);
    }
}
