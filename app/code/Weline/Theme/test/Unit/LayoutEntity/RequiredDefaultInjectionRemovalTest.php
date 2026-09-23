<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionBakeMerger;

final class RequiredDefaultInjectionRemovalTest extends TestCase
{
    public function testLayoutOptionsDoNotInjectIntoAnotherOption(): void
    {
        $merger = new RequiredDefaultInjectionBakeMerger();
        $items = [['layout_option' => 'default', 'slot' => 'default-slot'], ['layout_option' => 'wide', 'slot' => 'wide-slot'], ['layout_option' => '*', 'slot' => 'common']];
        self::assertSame(['wide-slot', 'common'], array_column($merger->injectionsForLayoutOption($items, 'wide'), 'slot'));
        self::assertSame(['default-slot', 'common'], array_column($merger->injectionsForLayoutOption($items, 'default'), 'slot'));
    }

    public function testLegacyOwnershipIsReportedInsteadOfSilentlyDeleting(): void
    {
        $node = ['node_uid' => 'legacy', 'slot_id' => 'hero', 'widget_module' => 'Weline_Test', 'widget_code' => 'hero'];
        $changes = [['widget_identity' => ['module' => 'Weline_Test', 'code' => 'hero', 'type' => 'test'], 'before' => [['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true]], 'after' => []]];
        $merger = new RequiredDefaultInjectionBakeMerger();
        self::assertSame(['legacy' => $node], $merger->removeRetiredAutomaticNodes(['legacy' => $node], 'homepage', $changes));
        self::assertSame('automatic_ownership_unknown', $merger->unresolvedRetiredNodes(['legacy' => $node], 'homepage', $changes)[0]['reason']);
    }

    public function testAutomaticSlotChangePreservesInstanceAndConfiguration(): void
    {
        $node = ['node_uid' => 'auto', 'slot_id' => 'hero', 'area' => 'content', 'sort_order' => 0, 'widget_module' => 'Weline_Test', 'widget_code' => 'hero', 'source' => 'auto', 'config' => ['title' => 'custom']];
        $changes = [['widget_identity' => ['module' => 'Weline_Test', 'code' => 'hero', 'type' => 'test'], 'before' => [['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true]], 'after' => [['layout_type' => 'homepage', 'slot' => 'bottom', 'sort_order' => 9, 'required' => true]]]];
        $result = (new RequiredDefaultInjectionBakeMerger())->removeRetiredAutomaticNodes(['auto' => $node], 'homepage', $changes);
        self::assertSame('bottom', $result['auto']['slot_id'] ?? null);
        self::assertSame(9, $result['auto']['sort_order']);
        self::assertSame(['title' => 'custom'], $result['auto']['config']);
    }

    public function testRemovedDeclarationOnlyRemovesItsAutomaticPlacement(): void
    {
        $node = ['node_uid' => 'auto', 'slot_id' => 'hero', 'widget_module' => 'Weline_Test', 'widget_code' => 'hero', 'source' => 'auto'];
        $manual = array_replace($node, ['node_uid' => 'manual', 'source' => 'manual']);
        $changes = [['widget_identity' => ['module' => 'Weline_Test', 'code' => 'hero', 'type' => 'test'], 'before' => [['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true]], 'after' => []]];
        $result = (new RequiredDefaultInjectionBakeMerger())->removeRetiredAutomaticNodes(['auto' => $node, 'manual' => $manual], 'homepage', $changes);
        self::assertSame(['manual' => $manual], $result);
    }

    public function testUnrelatedPageAndUnchangedDeclarationKeepAutomaticPlacement(): void
    {
        $node = ['node_uid' => 'auto', 'slot_id' => 'hero', 'widget_module' => 'Weline_Test', 'widget_code' => 'hero', 'source' => 'auto'];
        $item = ['layout_type' => 'homepage', 'slot' => 'hero', 'required' => true];
        $change = ['widget_identity' => ['module' => 'Weline_Test', 'code' => 'hero', 'type' => 'test'], 'before' => [$item], 'after' => [$item]];
        $merger = new RequiredDefaultInjectionBakeMerger();
        self::assertSame(['auto' => $node], $merger->removeRetiredAutomaticNodes(['auto' => $node], 'homepage', [$change]));
        $change['after'] = [];
        self::assertSame(['auto' => $node], $merger->removeRetiredAutomaticNodes(['auto' => $node], 'product', [$change]));
    }
}
