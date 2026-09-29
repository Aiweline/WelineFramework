<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;
use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInputResolver;
final class ThemeLayoutEntityInputResolverTest extends TestCase
{
    public function testSavedBeforeAnchorControlsCompiledOrderWithoutChangingChildSlots(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityInputResolver::class));
        $a = str_repeat('a',32); $b = str_repeat('b',32); $child = str_repeat('c',32);
        $nodes = [$a => ['node_uid'=>$a,'sort_order'=>0,'slot_id'=>'content'],
            $b => ['node_uid'=>$b,'sort_order'=>10,'slot_id'=>'content','anchor_uid'=>$a,'position'=>'before'],
            $child => ['node_uid'=>$child,'sort_order'=>0,'slot_id'=>'cta','parent_uid'=>$a,'position'=>'inside']];
        $resolved = (new ThemeLayoutEntityInputResolver())->placements($nodes);
        self::assertLessThan($resolved[$a]['sort_order'], $resolved[$b]['sort_order']);
        self::assertSame('cta', $resolved[$child]['slot_id']);
        self::assertSame($a, $resolved[$child]['parent_uid']);
    }
    public function testLocaleDotPathsReplaceOnlyTheirTargetAndKeepCompleteDefaults(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityInputResolver::class));
        $base=['title'=>'Default','slides'=>[['image'=>'before','link'=>'kept']],'enabled'=>true];
        $config=(new ThemeLayoutEntityInputResolver())->localizedConfig($base,['slides.0.image'=>null,'enabled'=>false]);
        self::assertSame(['title'=>'Default','slides'=>[['image'=>null,'link'=>'kept']],'enabled'=>false],$config);
        self::assertSame([], (new ThemeLayoutEntityInputResolver())->localizedConfig($base,['slides'=>[]])['slides']);
    }
    public function testFrozenOmissionsFollowOnlyThisRevisionRemovalAndExplicitReinstall(): void
    {
        $node = ['slot_id'=>'footer','widget_module'=>'Fixture','widget_code'=>'label','is_active'=>true];
        $resolver = new ThemeLayoutEntityInputResolver();
        $old = $resolver->omissionsForNodes(['uid'=>$node], [], []);
        self::assertSame([['slot_id'=>'footer','widget_module'=>'Fixture','widget_code'=>'label']], $old);
        self::assertSame([], $resolver->omissionsForNodes([], ['uid'=>$node], $old));
        self::assertCount(1, $old, 'Advancing to a reinstall must not mutate the old revision decision.');
    }
    public function testRemovingLastLocalNodeRetainsEmptySlotIntentUntilExplicitInherit(): void
    {
        $uid = str_repeat('b', 32); $parentUid = str_repeat('a', 32);
        $node = ['slot_id'=>'content','widget_module'=>'Fixture','widget_code'=>'label','is_active'=>true];
        $engine = new \Weline\Theme\Service\Scoped\ThemePatchEngine();
        $command = \Weline\Theme\Api\Scoped\ThemePatchCommand::class;
        $owned = $engine->mergeOwnedCommands([$command::fromArray(['op'=>'add_node','path'=>'/nodes/'.$uid,'node_uid'=>$uid,'value'=>$node])],
            [$command::fromArray(['op'=>'remove_node','path'=>'/nodes/'.$uid,'node_uid'=>$uid])]);
        $base = ['nodes'=>[$parentUid=>$node]];
        $empty = $engine->apply($base, $owned);
        self::assertFalse(isset($empty['nodes'][$parentUid]), 'Removing the last local placement is not a request to inherit the parent slot.');
        self::assertSame('user_deleted', $empty['nodes'][$uid]['source']);
        $reset = $engine->mergeOwnedCommands($owned, [$command::fromArray(['op'=>'inherit','path'=>'/nodes/'.$uid])]);
        self::assertSame($node + ['node_uid'=>$parentUid], $engine->apply($base, $reset)['nodes'][$parentUid]);
    }
}
