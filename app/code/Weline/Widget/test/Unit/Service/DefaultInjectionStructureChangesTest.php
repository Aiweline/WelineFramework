<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Widget\Service\DefaultInjectionStructureChanges;

require_once dirname(__DIR__, 3) . '/Service/DefaultInjectionStructureChanges.php';

final class DefaultInjectionStructureChangesTest extends TestCase
{
    private function widget(array $injections): array
    {
        return ['area' => 'frontend', 'module' => 'Weline_Test', 'type' => 'test', 'code' => 'test', 'default_injections' => $injections];
    }

    public function testConfigurationAndDescriptionChangesDoNotChangeStructure(): void
    {
        $before = $this->widget([['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true, 'config' => ['title' => 'old']]]);
        $after = $before;
        $after['description'] = 'new';
        $after['default_injections'][0]['config']['title'] = 'new';
        self::assertNull(DefaultInjectionStructureChanges::between($before, $after));
    }

    public function testRemovalOfLastDeclarationRetainsOldTarget(): void
    {
        $before = $this->widget([['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true]]);
        $change = DefaultInjectionStructureChanges::between($before, $this->widget([]));
        self::assertSame('homepage', $change['before'][0]['layout_type']);
        self::assertSame([], $change['after']);
    }

    public function testOptionalDeclarationsAreReportedWithoutBecomingRequired(): void
    {
        $change = DefaultInjectionStructureChanges::between($this->widget([]), $this->widget([['slot' => 'hero', 'required' => false]]));
        self::assertFalse($change['after'][0]['required']);
        self::assertSame([], DefaultInjectionStructureChanges::between($this->widget([['slot' => 'hero']]), $this->widget([]))['after']);
    }

    public function testLegacyPageLayoutsNormalizeToExplicitTargets(): void
    {
        $legacy = $this->widget([['slot' => 'all-menu', 'page_layouts' => ['product', 'homepage']]]);
        $modern = $this->widget([['slot' => 'all-menu', 'layout_type' => 'homepage'], ['slot' => 'all-menu', 'layout_type' => 'product']]);
        self::assertNull(DefaultInjectionStructureChanges::between($legacy, $modern));
        $change = DefaultInjectionStructureChanges::between($this->widget([]), $legacy);
        self::assertSame(['homepage', 'product'], array_column($change['after'], 'layout_type'));
        self::assertFalse($change['after'][0]['required']);
        self::assertNull(DefaultInjectionStructureChanges::between($this->widget([['slot' => 'all-menu', 'page_layouts' => ['*']]]), $this->widget([['slot' => 'all-menu', 'layout_type' => '*']])));
    }

    public function testDeclarationOrderingIsCanonical(): void
    {
        $a = ['slot' => 'a', 'required' => true];
        $b = ['slot' => 'b', 'required' => true];
        self::assertNull(DefaultInjectionStructureChanges::between($this->widget([$a, $b]), $this->widget([$b, $a])));
    }

    public function testSlotMoveAndDisableChangeStructure(): void
    {
        $before = $this->widget([['layout_type' => 'homepage', 'slot' => 'a', 'required' => true]]);
        $after = $before;
        $after['default_injections'][0]['slot'] = 'b';
        self::assertSame('b', DefaultInjectionStructureChanges::between($before, $after)['after'][0]['slot']);
        $after = $before;
        $after['disabled'] = true;
        self::assertSame([], DefaultInjectionStructureChanges::between($before, $after)['after']);
    }
}
