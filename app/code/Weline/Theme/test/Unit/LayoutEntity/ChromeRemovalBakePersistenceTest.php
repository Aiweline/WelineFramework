<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;

require_once dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';

final class ChromeRemovalBakePersistenceTest extends TestCase
{
    private function merge(array $incoming, array $current): array
    {
        $class = new ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
        return $class->getMethod('preserveChromeUserRemovals')->invoke($class->newInstanceWithoutConstructor(), $incoming, $current);
    }

    private function removed(): array
    {
        return ['node_uid' => str_repeat('a', 32), 'widget_module' => 'Weline_Test', 'widget_type' => 'footer', 'widget_code' => 'help', 'area' => 'footer', 'slot_id' => 'footer-help-links', 'is_active' => false, 'source' => 'user_deleted'];
    }

    public function testAutomaticRebakeCannotReviveRemovedPlacementWithANewUid(): void
    {
        $removed = $this->removed();
        $automatic = array_replace($removed, ['node_uid' => str_repeat('b', 32), 'source' => 'default_injection', 'is_active' => true]);
        $otherModule = array_replace($automatic, ['node_uid' => str_repeat('c', 32), 'widget_module' => 'Weline_Other']);
        self::assertSame([$otherModule['node_uid'] => $otherModule, $removed['node_uid'] => $removed], $this->merge([$automatic['node_uid'] => $automatic, $otherModule['node_uid'] => $otherModule], [$removed['node_uid'] => $removed]));
    }

    public function testOmittedRemovalSurvivesFullPayloadBake(): void
    {
        $removed = $this->removed();
        self::assertSame([$removed['node_uid'] => $removed], $this->merge([], [$removed['node_uid'] => $removed]));
    }

    public function testStaleWorkspaceActiveFlagIsNotAnExplicitRestoreCommand(): void
    {
        $removed = $this->removed();
        foreach (['', 'manual', 'user_deleted'] as $source) {
            $stale = array_replace($removed, ['is_active' => true, 'source' => $source]);
            self::assertSame([$removed['node_uid'] => $removed], $this->merge([$removed['node_uid'] => $stale], [$removed['node_uid'] => $removed]));
        }
    }

    public function testOrdinaryInactiveNodeIsNotInventedAsUserRemoval(): void
    {
        $node = array_replace($this->removed(), ['source' => 'manual']);
        self::assertSame([], $this->merge([], [$node['node_uid'] => $node]));
    }
}
