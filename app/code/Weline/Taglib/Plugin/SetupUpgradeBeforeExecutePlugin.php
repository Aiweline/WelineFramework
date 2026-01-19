<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Taglib\Plugin;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Taglib\Cache\TaglibCacheFactory;
use Weline\Taglib\TaglibInterface;

/**
 * Setup升级执行前插件
 * 在setup:upgrade执行前收集标签注册表信息
 */
class SetupUpgradeBeforeExecutePlugin
{
    /**
     * Setup升级执行前的拦截方法
     * 收集标签注册表信息
     */
    public function beforeExecute($subject, ...$args): void
    {
        try {
            // 获取 Printing 实例用于输出信息
            /** @var Printing $printing */
            $printing = ObjectManager::getInstance(Printing::class);
            
            $printing->note(__('正在收集标签注册表...'));
            
            // 收集所有模块的标签库
            $this->collectAllTaglibs($printing);
            
            $printing->success(__('✓ 标签注册表已收集完成。'));
        } catch (\Exception $e) {
            // 标签收集失败不影响主流程，只记录警告
            try {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
                $printing->warning(__('标签注册表收集时发生错误：%{1}，但将继续执行升级流程。', [$e->getMessage()]));
            } catch (\Exception $printException) {
                // 如果 Printing 也不可用，只记录错误日志
                error_log("标签注册表收集失败: " . $e->getMessage());
            }
        }
    }
    
    /**
     * 收集所有模块的标签库
     * @param Printing $printing
     * @throws \Exception
     */
    private function collectAllTaglibs(Printing $printing): void
    {
        $cache = ObjectManager::getInstance(TaglibCacheFactory::class);
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = [];
        $modules = Env::getInstance()->getActiveModules();
        
        $totalModules = count($modules);
        $currentModule = 0;
        
        foreach ($modules as $module) {
            $currentModule++;
            $tags = glob($module['base_path'] . 'Taglib' . DS . '*.php');
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $tagF = rtrim($tag, '.php');
                    $tagClass = str_replace(DS, '\\', str_replace($module['base_path'], $module['namespace_path'] . '\\', $tagF));
                    $modules_tags[$module['name']][] = $tagClass;
                }
            }
        }
        
        // 验证和收集标签数据
        $module_tags = [];
        $totalTags = 0;
        foreach ($modules_tags as $module_name => $module_tag) {
            foreach ($module_tag as $item) {
                try {
                    // 检查类是否存在并实现 TaglibInterface
                    if (!class_exists($item)) {
                        continue;
                    }
                    
                    $refClass = new \ReflectionClass($item);
                    if (!$refClass->implementsInterface(TaglibInterface::class)) {
                        continue; // 跳过不符合接口的类
                    }
                    
                    // 检查是否为静态类，如果是，使用反射获取静态方法信息
                    $isStaticClass = false;
                    $constructor = $refClass->getConstructor();
                    if (!$constructor || !$constructor->isPublic()) {
                        // 检查所有公共方法是否都是静态的
                        $methods = $refClass->getMethods(\ReflectionMethod::IS_PUBLIC);
                        $allStatic = true;
                        foreach ($methods as $method) {
                            if (in_array($method->getName(), ['__construct', '__destruct', '__clone', '__wakeup', '__sleep'])) {
                                continue;
                            }
                            if (!$method->isStatic()) {
                                $allStatic = false;
                                break;
                            }
                        }
                        if ($allStatic && !empty($methods)) {
                            $isStaticClass = true;
                        }
                    }
                    
                    // 对于静态类，直接使用类名调用静态方法；对于非静态类，尝试实例化
                    if ($isStaticClass) {
                        // 静态类：直接使用类名调用静态方法
                        $tag_data = [];
                        if ($item::tag()) {
                            $tag_data['tag'] = $item::tag();
                        }
                        if ($item::attr()) {
                            $tag_data['attr'] = $item::attr();
                        }
                        if ($item::tag_start()) {
                            $tag_data['tag-start'] = $item::tag_start();
                        }
                        if ($item::tag_end()) {
                            $tag_data['tag-end'] = $item::tag_end();
                        }
                        if ($item::callback()) {
                            $tag_data['callback'] = $item::callback();
                        }
                        if ($item::tag_self_close()) {
                            $tag_data['tag-self-close'] = $item::tag_self_close();
                        }
                        if ($item::tag_self_close_with_attrs()) {
                            $tag_data['tag-self-close-with-attrs'] = $item::tag_self_close_with_attrs();
                        }
                        
                        if ($tag_data) {
                            $tag_data['is_custom'] = true;
                            $tag_data['module_name'] = $module_name;
                            $tag_data['doc'] = $item::document();
                            $tag_data['class'] = $item;
                            
                            // 检查是否有parent()方法
                            if (method_exists($item, 'parent')) {
                                $parentTag = $item::parent();
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
                            
                            $module_tags[$item::name()] = $tag_data;
                            $totalTags++;
                        }
                    } else {
                        // 非静态类：尝试实例化
                        /**@var TaglibInterface $tagObject */
                        $tagObject = ObjectManager::getInstance($item);
                        if (!($tagObject instanceof TaglibInterface)) {
                            continue; // 跳过不符合接口的类
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
                            $totalTags++;
                        }
                    }
                } catch (\Exception $e) {
                    // 跳过加载失败的标签类
                    if (CLI && DEV) {
                        $printing->warning(__('跳过标签类 %{1}：%{2}', [$item, $e->getMessage()]));
                    }
                    continue;
                }
            }
        }
        
        // 保存到缓存
        $cache->set($cache_key, $modules_tags);
    }
}
