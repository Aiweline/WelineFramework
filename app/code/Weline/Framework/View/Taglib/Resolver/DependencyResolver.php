<?php

declare(strict_types=1);

/**
 * Weline Framework - Taglib Dependency Resolver
 *
 * @DESC          | 标签依赖解析器，实现拓扑排序
 * @Author        | Weline Framework
 * @Package       | Weline\Framework\View\Taglib\Resolver
 */

namespace Weline\Framework\View\Taglib\Resolver;

use Weline\Framework\View\Taglib\Registry\TagDefinition;

/**
 * 依赖解析器
 *
 * 使用 Kahn 算法实现拓扑排序，确保标签按正确的依赖顺序处理
 * 同时支持优先级排序（同级别内按 priority 排序）
 */
final class DependencyResolver
{
    /**
     * 已解析的标签缓存
     * @var array<string, TagDefinition>|null
     */
    private ?array $resolvedCache = null;

    /**
     * 解析标签定义，返回按依赖和优先级排序的结果
     *
     * @param array<string, TagDefinition> $definitions 标签定义数组
     * @return array<string, TagDefinition> 排序后的标签定义
     * @throws CircularDependencyException 检测到循环依赖时抛出
     */
    public function resolve(array $definitions): array
    {
        if (empty($definitions)) {
            return [];
        }

        // 1. 构建依赖图
        $graph = $this->buildDependencyGraph($definitions);

        // 2. 计算入度
        $inDegree = $this->calculateInDegree($definitions, $graph);

        // 3. Kahn 算法拓扑排序
        $sorted = $this->topologicalSort($definitions, $graph, $inDegree);

        // 4. 同级别内按优先级排序
        return $this->sortByPriority($sorted);
    }

    /**
     * 构建依赖图
     *
     * @param array<string, TagDefinition> $definitions
     * @return array<string, array<string>> 邻接表表示的依赖图
     */
    private function buildDependencyGraph(array $definitions): array
    {
        $graph = [];

        foreach ($definitions as $name => $def) {
            if (!isset($graph[$name])) {
                $graph[$name] = [];
            }

            // 如果 A 依赖 B，则 B 必须在 A 之前处理
            // 在图中表示为 B -> A（B 指向 A）
            foreach ($def->dependencies as $dep) {
                if (isset($definitions[$dep])) {
                    if (!isset($graph[$dep])) {
                        $graph[$dep] = [];
                    }
                    $graph[$dep][] = $name;
                }
            }
        }

        return $graph;
    }

    /**
     * 计算每个节点的入度
     *
     * @param array<string, TagDefinition> $definitions
     * @param array<string, array<string>> $graph
     * @return array<string, int>
     */
    private function calculateInDegree(array $definitions, array $graph): array
    {
        $inDegree = [];

        // 初始化所有节点入度为 0
        foreach ($definitions as $name => $def) {
            $inDegree[$name] = 0;
        }

        // 计算入度
        foreach ($graph as $from => $neighbors) {
            foreach ($neighbors as $to) {
                if (isset($inDegree[$to])) {
                    $inDegree[$to]++;
                }
            }
        }

        return $inDegree;
    }

    /**
     * Kahn 算法拓扑排序
     *
     * @param array<string, TagDefinition> $definitions
     * @param array<string, array<string>> $graph
     * @param array<string, int> $inDegree
     * @return array<string, TagDefinition>
     * @throws CircularDependencyException
     */
    private function topologicalSort(array $definitions, array $graph, array $inDegree): array
    {
        $queue = [];
        $sorted = [];

        // 将入度为 0 的节点加入队列
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        // 按优先级对初始队列排序
        usort($queue, function (string $a, string $b) use ($definitions): int {
            return $definitions[$a]->priority <=> $definitions[$b]->priority;
        });

        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[$current] = $definitions[$current];

            // 减少邻居的入度
            foreach ($graph[$current] ?? [] as $neighbor) {
                if (isset($inDegree[$neighbor])) {
                    $inDegree[$neighbor]--;
                    if ($inDegree[$neighbor] === 0) {
                        $queue[] = $neighbor;
                    }
                }
            }

            // 保持队列按优先级排序
            usort($queue, function (string $a, string $b) use ($definitions): int {
                return $definitions[$a]->priority <=> $definitions[$b]->priority;
            });
        }

        // 检测循环依赖
        if (count($sorted) !== count($definitions)) {
            $remaining = array_diff(array_keys($definitions), array_keys($sorted));
            throw new CircularDependencyException(
                '检测到循环依赖，涉及标签：' . implode(', ', $remaining)
            );
        }

        return $sorted;
    }

    /**
     * 按优先级对标签进行排序
     *
     * 拓扑排序已经考虑了依赖关系，这里确保同级别内按优先级排序
     *
     * @param array<string, TagDefinition> $definitions
     * @return array<string, TagDefinition>
     */
    private function sortByPriority(array $definitions): array
    {
        // 将标签按优先级分组
        $groups = [];
        foreach ($definitions as $name => $def) {
            $priority = $def->priority;
            if (!isset($groups[$priority])) {
                $groups[$priority] = [];
            }
            $groups[$priority][$name] = $def;
        }

        // 按优先级排序组
        ksort($groups);

        // 合并所有组
        $result = [];
        foreach ($groups as $group) {
            foreach ($group as $name => $def) {
                $result[$name] = $def;
            }
        }

        return $result;
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->resolvedCache = null;
    }

    /**
     * 检查是否存在循环依赖
     *
     * @param array<string, TagDefinition> $definitions
     * @return bool
     */
    public function hasCircularDependency(array $definitions): bool
    {
        try {
            $this->resolve($definitions);
            return false;
        } catch (CircularDependencyException) {
            return true;
        }
    }

    /**
     * 获取标签的所有依赖（包括间接依赖）
     *
     * @param string $tagName 标签名
     * @param array<string, TagDefinition> $definitions 所有标签定义
     * @return array<string> 依赖的标签名列表
     */
    public function getAllDependencies(string $tagName, array $definitions): array
    {
        if (!isset($definitions[$tagName])) {
            return [];
        }

        $visited = [];
        $result = [];

        $this->collectDependencies($tagName, $definitions, $visited, $result);

        return $result;
    }

    /**
     * 递归收集依赖
     */
    private function collectDependencies(
        string $tagName,
        array $definitions,
        array &$visited,
        array &$result
    ): void {
        if (isset($visited[$tagName])) {
            return;
        }

        $visited[$tagName] = true;

        $def = $definitions[$tagName] ?? null;
        if ($def === null) {
            return;
        }

        foreach ($def->dependencies as $dep) {
            if (!isset($visited[$dep])) {
                $result[] = $dep;
                $this->collectDependencies($dep, $definitions, $visited, $result);
            }
        }
    }
}
