<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;

final class ThemeLayoutStructureIdentityTest extends TestCase
{
    public function testConfigurationAndPublicationDoNotChangeStructureIdentity(): void
    {
        $nodes = [$this->node()];
        $original = $this->key($nodes, 11, null);
        $nodes[0]['config']['title'] = '更新后的标题';
        self::assertSame($original, $this->key($nodes, 12, null));
        self::assertSame($original, $this->key($nodes, 12, 42));
    }

    public function testMovingOrRemovingWidgetChangesStructureIdentity(): void
    {
        $node = $this->node();
        $original = $this->key([$node], 11, null);
        $node['slot_id'] = 'sidebar';
        self::assertNotSame($original, $this->key([$node], 11, null));
        self::assertNotSame($original, $this->key([], 11, null));
    }

    public function testContentConfigDoesNotTouchUnchangedChromeInFullPayload(): void
    {
        $class = new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
        $coordinator = $class->newInstanceWithoutConstructor();
        $chrome = (new \ReflectionClass(\Weline\Theme\Service\SharedChromeService::class))->newInstanceWithoutConstructor();
        $class->getProperty('sharedChrome')->setValue($coordinator, $chrome);
        $content = $this->node();
        $header = array_replace($content, ['node_uid' => str_repeat('b', 32), 'area' => 'header', 'slot_id' => 'header']);
        self::assertFalse($class->getMethod('commandsTouchChrome')->invoke($coordinator,
            [['op' => 'set', 'path' => '/nodes/' . $content['node_uid'] . '/config/title', 'value' => '新标题']],
            [$content['node_uid'] => $content, $header['node_uid'] => $header],
        ));
    }

    private function node(): array
    {
        return ['node_uid' => str_repeat('a', 32), 'area' => 'content', 'slot_id' => 'content',
            'widget_module' => 'Weline_Theme', 'widget_type' => 'content', 'widget_code' => 'text',
            'sort_order' => 1, 'is_active' => true, 'config' => ['title' => '原标题']];
    }

    private function key(array $nodes, int $revision, ?int $release): string
    {
        $class = new \ReflectionClass(ThemeLayoutEntityBakeCoordinator::class);
        return $class->getMethod('structureKeyForNodes')->invoke(
            $class->newInstanceWithoutConstructor(), $nodes, $revision, $release,
        );
    }
}
