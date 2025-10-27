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

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\Cache\TaglibCacheFactory;

class TagParser implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    public function __construct()
    {
        $this->cache = ObjectManager::getInstance(TaglibCacheFactory::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $frameworkTags = $event->getData('data')->getData('tags');
        # 查找所有标签
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = $this->cache->get($cache_key);
        if (empty($modules_tags)) {
            $modules_tags = [];
            $modules = Env::getInstance()->getActiveModules();
            foreach ($modules as $module) {
                $tags = glob($module['base_path'] . 'Taglib' . DS . '*.php');
                foreach ($tags as $tag) {
                    $tagF = rtrim($tag, '.php');
                    $tagClass = str_replace(DS, '\\', str_replace($module['base_path'], $module['namespace_path'] . '\\', $tagF));
                    $modules_tags[$module['name']][] = $tagClass;
                }
            }
            $this->cache->set($cache_key, $modules_tags);
        }
        
        // 收集所有标签数据
        $module_tags = [];
        foreach ($modules_tags as $module_name => $module_tag) {
            foreach ($module_tag as $item) {
                /**@var \Weline\Taglib\TaglibInterface $tagObject */
                $tagObject = ObjectManager::getInstance($item);
                if (!($tagObject instanceof \Weline\Taglib\TaglibInterface)) {
                    throw new \Exception(__('标签类{ %{1} }必须继承自：\Weline\Taglib\TaglibInterface 接口, 标签文件：%{2}', [$tagObject::class, $item]));
                }
                
                $tag_data = [];
                if ($tagObject::tag()) {
                    $tag_data['tag'] = $tagObject::tag();
                }
                if ($tagObject::attr()) {
                    $tag_data['attr'] = $tagObject::attr();
                }
                if ($tagObject::tag_start()) {
                    $tag_data['tag-start'] = $tagObject::tag_start();
                }
                if ($tagObject::tag_end()) {
                    $tag_data['tag-end'] = $tagObject::tag_end();
                }
                if ($tagObject::callback()) {
                    $tag_data['callback'] = $tagObject::callback();
                }
                if ($tagObject::tag_self_close()) {
                    $tag_data['tag-self-close'] = $tagObject::tag_self_close();
                }
                if ($tagObject::tag_self_close_with_attrs()) {
                    $tag_data['tag-self-close-with-attrs'] = $tagObject::tag_self_close_with_attrs();
                }
                
                if ($tag_data) {
                    $tag_data['is_custom'] = true;
                    $tag_data['module_name'] = $module_name;
                    $tag_data['doc'] = $tagObject::document();
                    $tag_data['class'] = $tagObject::class;
                    
                    // 检查是否有parent()方法
                    if (method_exists($tagObject, 'parent')) {
                        $parentTag = $tagObject::parent();
                        if ($parentTag) {
                            // 支持多个父标签，用逗号分隔
                            if (strpos($parentTag, ',') !== false) {
                                $parentTags = array_map('trim', explode(',', $parentTag));
                                $tag_data['parent'] = $parentTags;
                            } else {
                                $tag_data['parent'] = $parentTag;
                            }
                        }
                    }
                    
                    $module_tags[$tagObject::name()] = $tag_data;
                }
            }
        }
        
        // 对标签进行依赖排序
        $sortedTags = $this->sortTagsByDependencies($module_tags);
        
        if ($sortedTags) {
            $event->getData('data')->setData('tags', array_merge($frameworkTags, $sortedTags));
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
