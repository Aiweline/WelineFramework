<?php

declare(strict_types=1);

namespace Weline\Agent\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Agent\Service\FlowRunner;

final class FlowRunnerTopologyTest extends TestCase
{
    public function testTopoSortOrdersChainAndHandlesCycleFallback(): void
    {
        $runner = (new \ReflectionClass(FlowRunner::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FlowRunner::class, 'topoSort');
        $method->setAccessible(true);

        $nodes = [
            ['node_key' => 'a'],
            ['node_key' => 'b'],
            ['node_key' => 'c'],
        ];
        $edges = [
            ['from_node_key' => 'a', 'to_node_key' => 'b'],
            ['from_node_key' => 'b', 'to_node_key' => 'c'],
        ];
        $this->assertSame(['a', 'b', 'c'], $method->invoke($runner, $nodes, $edges));

        // 分支：a→b, a→c ⇒ a 先，b/c 保持原序
        $edges2 = [
            ['from_node_key' => 'a', 'to_node_key' => 'b'],
            ['from_node_key' => 'a', 'to_node_key' => 'c'],
        ];
        $this->assertSame(['a', 'b', 'c'], $method->invoke($runner, $nodes, $edges2));

        // 环兜底：不死循环，全部节点仍输出
        $cycle = [
            ['from_node_key' => 'a', 'to_node_key' => 'b'],
            ['from_node_key' => 'b', 'to_node_key' => 'a'],
        ];
        $order = $method->invoke($runner, $nodes, $cycle);
        $this->assertCount(3, $order);
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $order);
    }
}
