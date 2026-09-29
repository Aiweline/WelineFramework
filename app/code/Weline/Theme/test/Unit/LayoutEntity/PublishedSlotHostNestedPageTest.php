<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\RuntimeTemplateMaterializer;
require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

/** Historical nested-slot regressions now execute source PHTML rather than rendered-fragment splicing. */
final class PublishedSlotHostNestedPageTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public function testMultipleNestedLevelsRenderEachSavedChildOnce(): void
    {
        $this->registry->definitions['middle'] = $this->definition('middle', '<section data-wslot="deep" data-wslot-exclusive="true">OLD DEEP</section>', ['deep']);
        $outer = $this->node('a', 'main', '', 0, 'box');
        $middle = $this->node('b', 'inside', '', 0, 'middle'); $middle['parent_uid'] = $outer['node_uid'];
        $leaf = $this->node('c', 'deep', 'DEEP ACTION', 0); $leaf['parent_uid'] = $middle['node_uid'];
        $html = $this->renderNodes('<div data-wslot="main"></div>', [$outer, $middle, $leaf]);
        self::assertSame(1, substr_count($html, '<b>DEEP ACTION</b>'));
        self::assertStringNotContainsString('OLD DEEP', $html);
        self::assertStringNotContainsString('<em>default</em>', $html);
    }

    public function testExplicitEmptyChildSuppressesItsOldDefault(): void
    {
        $parent = $this->node('a', 'main', '', 0, 'box');
        $child = $this->node('b', 'inside', 'DELETED', 0); $child['parent_uid'] = $parent['node_uid']; $child['is_active'] = false; $child['source'] = 'user_deleted';
        $html = $this->renderNodes('<div data-wslot="main"></div>', [$parent, $child]);
        self::assertStringContainsString('<article data-wslot="inside"', $html);
        self::assertStringNotContainsString('<em>default</em>', $html);
        self::assertStringNotContainsString('DELETED', $html);
    }

    public function testExplicitEmptyParentDoesNotRestoreItsChildOrSourceFallback(): void
    {
        $parent = $this->node('a', 'main', '', 0, 'box'); $parent['source'] = 'user_deleted';
        $child = $this->node('b', 'inside', 'ORPHAN', 0); $child['parent_uid'] = $parent['node_uid'];
        $html = $this->renderNodes('<div data-wslot="main" data-wslot-exclusive="true"><strong>OLD PARENT</strong></div>', [$parent, $child]);
        self::assertStringNotContainsString('OLD PARENT', $html);
        self::assertStringNotContainsString('ORPHAN', $html);
    }

    public function testSavedDefaultCallDoesNotDuplicateTheSameWidget(): void
    {
        $node = $this->node('a', 'main', 'SAVED', 0);
        $html = $this->renderNodes('<w:slot id="main"><w:widget module="Fixture" type="content" name="label" params=\'{"text":"OLD"}\'/></w:slot>', [$node]);
        self::assertSame(1, substr_count($html, 'data-widget-code="label"'));
        self::assertSame(1, substr_count($html, '<b>SAVED</b>'));
        self::assertStringNotContainsString('<b>OLD</b>', $html);
    }

    public function testIndependentSlotsFollowSourceOrderRatherThanNodeMapOrder(): void
    {
        $source = '<main><w:slot id="homepage-hero"/><w:slot id="homepage-bestsellers"/></main>';
        $html = $this->renderNodes($source, [$this->node('a','homepage-bestsellers','BEST',0),$this->node('b','homepage-hero','HERO',0)]);
        self::assertLessThan(strpos($html,'<b>BEST</b>'),strpos($html,'<b>HERO</b>'));
    }

    public function testParentCycleCannotRepeatWidgetsOrRecurseForever(): void
    {
        $a=$this->node('a','inside','A',0,'box'); $b=$this->node('b','inside','B',0,'box');
        $a['parent_uid']=$b['node_uid']; $b['parent_uid']=$a['node_uid'];
        self::assertSame('<main></main>', $this->renderNodes('<main></main>',[$a,$b]));
    }

    private function renderNodes(string $source,array $nodes): string
    {
        return (new RuntimeTemplateMaterializer(ObjectManager::getInstance(Template::class)))->renderContent((new LayoutRelationCompiler($this->registry))->compile($source,$nodes));
    }
}
