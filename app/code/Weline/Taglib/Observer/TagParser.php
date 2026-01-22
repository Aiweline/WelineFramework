<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/1 09:53:03
 */

namespace Weline\Taglib\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\TaglibRegistry;

class TagParser implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $frameworkTags = $event->getData('data')->getData('tags');
        
        // 从 generated/taglibs.php 直接读取标签配置（不扫描文件系统）
        /** @var TaglibRegistry $registry */
        $registry = ObjectManager::getInstance(TaglibRegistry::class);
        $tags = $registry->getTags();
        
        if (empty($tags)) {
            // 如果 generated/taglibs.php 为空，说明还没有运行 setup:upgrade
            // 不再在请求生命周期中收集标签
            return;
        }
        
        // 动态获取 callback（因为 Closure 无法序列化到文件中）
        foreach ($tags as $tagName => &$tagData) {
            if (isset($tagData['class']) && !isset($tagData['callback'])) {
                $tagClass = $tagData['class'];
                if (class_exists($tagClass) && method_exists($tagClass, 'callback')) {
                    $tagData['callback'] = $tagClass::callback();
                }
            }
            
            // 检查是否有 parent() 方法，设置依赖关系
            if (isset($tagData['class'])) {
                $tagClass = $tagData['class'];
                if (class_exists($tagClass) && method_exists($tagClass, 'parent')) {
                    $parentTag = $tagClass::parent();
                    if ($parentTag) {
                        $tagData['parent'] = $parentTag;
                    }
                }
            }
        }
        unset($tagData); // 解除引用
        
        // 如果存在 widget 标签，让 hook 标签依赖于 widget（确保 widget 在 hook 之前处理）
        // 这样 widget 内部的前中后周期 hook 才能正常工作
        if (isset($tags['widget'])) {
            // 为框架内置的 hook 标签添加 parent 依赖
            if (isset($frameworkTags['hook']) && !isset($frameworkTags['hook']['parent'])) {
                $frameworkTags['hook']['parent'] = 'widget';
            }
        }
        
        // 合并框架标签和自定义标签，然后进行依赖排序
        $allTags = array_merge($frameworkTags, $tags);
        
        // 对标签进行依赖排序
        $sortedTags = $this->sortTagsByDependencies($allTags);
        
        if ($sortedTags) {
            $event->getData('data')->setData('tags', $sortedTags);
        }
    }

    /**
     * 根据依赖关系对标签进行排序
     * @param array $allTags 所有标签数据
     * @return array 排序后的标签数据
     */
    private function sortTagsByDependencies(array $allTags): array
    {
        if (empty($allTags)) {
            return $allTags;
        }

        $sorted = [];
        $visited = [];
        $recursionStack = [];

        // 对每个标签进行拓扑排序
        foreach (array_keys($allTags) as $tagName) {
            if (!isset($visited[$tagName])) {
                $this->topologicalSort($tagName, $allTags, $visited, $recursionStack, $sorted);
            }
        }

        // 构建排序后的标签数组
        $result = [];
        foreach ($sorted as $tagName) {
            if (isset($allTags[$tagName])) {
                $result[$tagName] = $allTags[$tagName];
            }
        }

        // 添加没有依赖关系的标签
        foreach ($allTags as $tagName => $tagData) {
            if (!in_array($tagName, $sorted)) {
                $result[$tagName] = $tagData;
            }
        }

        return $result;
    }

    /**
     * 拓扑排序算法
     * @param string $tagName 当前标签名
     * @param array $allTags 所有标签数据
     * @param array $visited 已访问的标签
     * @param array $recursionStack 递归栈（用于检测循环依赖）
     * @param array $sorted 排序结果
     */
    private function topologicalSort(
        string $tagName, 
        array $allTags, 
        array &$visited, 
        array &$recursionStack, 
        array &$sorted
    ): void {
        // 检测循环依赖
        if (isset($recursionStack[$tagName])) {
            $cycle = implode(' -> ', array_keys($recursionStack)) . ' -> ' . $tagName;
            throw new \Exception(__('检测到标签循环依赖：%{1}', [$cycle]));
        }

        // 如果已经访问过，直接返回
        if (isset($visited[$tagName])) {
            return;
        }

        // 标记为正在访问
        $recursionStack[$tagName] = true;

        // 如果有依赖的父标签，先处理父标签
        if (isset($allTags[$tagName]['parent'])) {
            $parentTags = $allTags[$tagName]['parent'];
            
            // 支持多个父标签
            if (is_array($parentTags)) {
                foreach ($parentTags as $parentTag) {
                    if (isset($allTags[$parentTag])) {
                        $this->topologicalSort($parentTag, $allTags, $visited, $recursionStack, $sorted);
                    }
                }
            } else {
                // 单个父标签
                if (isset($allTags[$parentTags])) {
                    $this->topologicalSort($parentTags, $allTags, $visited, $recursionStack, $sorted);
                }
            }
        }

        // 标记为已访问
        $visited[$tagName] = true;
        unset($recursionStack[$tagName]);

        // 添加到排序结果
        $sorted[] = $tagName;
    }

    /**
     * 验证依赖关系的完整性
     * @param array $allTags 所有标签数据
     * @return array 验证结果
     */
    private function validateDependencies(array $allTags): array
    {
        $errors = [];
        $warnings = [];

        foreach ($allTags as $childTag => $tagData) {
            // 检查父标签是否存在
            if (isset($tagData['parent'])) {
                $parentTags = $tagData['parent'];
                
                if (is_array($parentTags)) {
                    // 多个父标签
                    foreach ($parentTags as $parentTag) {
                        if (!isset($allTags[$parentTag])) {
                            $errors[] = __("标签 '%{1}' 依赖的父标签 '%{2}' 不存在", [$childTag, $parentTag]);
                        }
                        
                        // 检查父标签是否也有parent()方法
                        if (isset($allTags[$parentTag]) && !isset($allTags[$parentTag]['parent'])) {
                            $warnings[] = __("标签 '%{1}' 依赖的父标签 '%{2}' 没有parent()方法，可能不是预期的父标签", [$childTag, $parentTag]);
                        }
                    }
                } else {
                    // 单个父标签
                    if (!isset($allTags[$parentTags])) {
                        $errors[] = __("标签 '%{1}' 依赖的父标签 '%{2}' 不存在", [$childTag, $parentTags]);
                    }
                    
                    // 检查父标签是否也有parent()方法
                    if (isset($allTags[$parentTags]) && !isset($allTags[$parentTags]['parent'])) {
                        $warnings[] = __("标签 '%{1}' 依赖的父标签 '%{2}' 没有parent()方法，可能不是预期的父标签", [$childTag, $parentTags]);
                    }
                }
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}
