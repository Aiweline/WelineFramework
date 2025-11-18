<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Extends\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Extends\ExtendsRegistry;

/**
 * 循环依赖检测器
 * 检测模块间的循环扩展依赖，发现循环时抛出严重异常
 */
class CircularDependencyDetector
{
    private ExtendsRegistry $extendsRegistry;

    public function __construct(ExtendsRegistry $extendsRegistry)
    {
        $this->extendsRegistry = $extendsRegistry;
    }

    /**
     * 检测所有模块的循环依赖
     *
     * @return array 返回循环依赖列表，空数组表示无循环
     * @throws Exception 如果发现循环依赖，抛出严重异常
     */
    public function detectAll(): array
    {
        $registry = $this->extendsRegistry->getRegistry();
        $cycles = [];

        foreach ($registry as $moduleName => $data) {
            $visited = [];
            $recStack = [];
            $cycle = $this->detectCycle($moduleName, $registry, $visited, $recStack, []);

            if (!empty($cycle)) {
                $cycles[] = $cycle;
            }
        }

        if (!empty($cycles)) {
            $message = "检测到循环依赖！\n\n";
            foreach ($cycles as $index => $cycle) {
                $message .= "循环 " . ($index + 1) . ": " . implode(' -> ', $cycle) . " -> " . $cycle[0] . "\n";
            }
            throw new Exception($message);
        }

        return $cycles;
    }

    /**
     * 使用 DFS 检测循环
     *
     * @param string $moduleName 当前模块名
     * @param array $registry 注册表
     * @param array &$visited 已访问节点
     * @param array &$recStack 递归栈
     * @param array $path 当前路径
     * @return array 循环路径，空数组表示无循环
     */
    private function detectCycle(string $moduleName, array $registry, array &$visited, array &$recStack, array $path): array
    {
        $visited[$moduleName] = true;
        $recStack[$moduleName] = true;
        $path[] = $moduleName;

        // 获取该模块扩展的其他模块
        $extendedBy = $registry[$moduleName]['extended_by'] ?? [];
        foreach ($extendedBy as $sourceModule => $extendList) {
            // 如果源模块在递归栈中，说明存在循环
            if (isset($recStack[$sourceModule]) && $recStack[$sourceModule]) {
                // 找到循环起点
                $cycleStart = array_search($sourceModule, $path);
                if ($cycleStart !== false) {
                    return array_slice($path, $cycleStart);
                }
            }

            // 如果未访问，继续递归
            if (!isset($visited[$sourceModule])) {
                $cycle = $this->detectCycle($sourceModule, $registry, $visited, $recStack, $path);
                if (!empty($cycle)) {
                    return $cycle;
                }
            }
        }

        // 回溯
        $recStack[$moduleName] = false;
        array_pop($path);

        return [];
    }

    /**
     * 检测特定模块的循环依赖
     *
     * @param string $moduleName 模块名
     * @return array 循环路径，空数组表示无循环
     */
    public function detectModule(string $moduleName): array
    {
        $registry = $this->extendsRegistry->getRegistry();
        if (!isset($registry[$moduleName])) {
            return [];
        }

        $visited = [];
        $recStack = [];
        return $this->detectCycle($moduleName, $registry, $visited, $recStack, []);
    }
}

