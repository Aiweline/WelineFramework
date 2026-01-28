<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Taglib\Console\Taglib;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Taglib\Cache\TaglibCacheFactory;
use Weline\Taglib\TaglibInterface;

class Collect implements CommandInterface
{
    private Printing $printing;
    private CacheInterface $cache;

    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
        $this->cache = ObjectManager::getInstance(TaglibCacheFactory::class);
    }

    /**
     * @inheritDoc
     * 
     * 参数说明：
     * - $data['skip_template_cache_clear']: bool - 是否跳过模板缓存清理（默认false）
     *   在系统升级流程中使用，因为 Upgrade.php 已经清理过模板缓存，无需重复清理
     */
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否跳过模板缓存清理（在系统升级流程中，Upgrade.php 已清理过）
        $skipTemplateCacheClear = $data['skip_template_cache_clear'] ?? false;
        
        // 检查是否指定了模块名
        $moduleName = $args['module'] ?? $args['m'] ?? $args[1] ?? null;
        
        if ($moduleName) {
            // 收集指定模块的 taglib
            $moduleName = trim($moduleName);
            $this->printing->note(__('正在收集模块 %{1} 的标签库...', [$moduleName]));
            try {
                $this->collectModuleTaglibs($moduleName);
                $this->printing->success(__('模块 %{1} 标签库收集成功！', [$moduleName]));
            } catch (\Exception $e) {
                $this->printing->error(__('模块 %{1} 标签库收集失败：%{2}', [$moduleName, $e->getMessage()]));
                return;
            }
        } else {
            // 收集所有模块的 taglib
            $this->printing->note(__('正在收集所有模块的标签库...'));
            try {
                $this->collectAllTaglibs();
                $this->printing->success(__('所有标签库收集成功！'));
            } catch (\Exception $e) {
                $this->printing->error(__('标签库收集失败：%{1}', [$e->getMessage()]));
                return;
            }
        }
        
        // 收集完成后，清理标签库缓存，确保新标签生效
        $this->printing->note(__('正在清理标签库缓存...'));
        try {
            $this->cache->clear();
            $this->printing->success(__('标签库缓存清理成功！'));
        } catch (\Exception $e) {
            $this->printing->warning(__('标签库缓存清理失败：%{1}，但标签收集已完成', [$e->getMessage()]));
        }
        
        // 清理模板缓存（标签在模板中使用）
        // 在系统升级流程中跳过，因为 Upgrade.php 已经清理过
        if (!$skipTemplateCacheClear) {
            $this->printing->note(__('清理模板缓存...'));
            try {
                /** @var \Weline\Framework\Cache\Console\Template\Clear $templateCacheClear */
                $templateCacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Template\Clear::class);
                $templateCacheClear->execute([], ['silent' => true]);
                $this->printing->success(__('模板缓存清理完成！'));
            } catch (\Exception $e) {
                $this->printing->warning(__('模板缓存清理失败：%{1}', [$e->getMessage()]));
            }
        }
        
        $this->printing->success(__('标签库收集和缓存清理完成！'));
    }

    /**
     * 收集所有模块的标签库
     * @throws \Exception
     */
    private function collectAllTaglibs(): void
    {
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = [];
        $modules = Env::getInstance()->getActiveModules();
        
        $totalModules = count($modules);
        $modulesWithTags = 0;
        
        // 批量扫描所有模块的标签，减少输出
        foreach ($modules as $module) {
            $tags = glob($module['base_path'] . 'Taglib' . DS . '*.php');
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $tagF = rtrim($tag, '.php');
                    $tagClass = str_replace(DS, '\\', str_replace($module['base_path'], $module['namespace_path'] . '\\', $tagF));
                    $modules_tags[$module['name']][] = $tagClass;
                }
                $modulesWithTags++;
            }
        }
        
        // 只输出汇总信息
        $this->printing->note(__('扫描完成: %{1} 个模块中有 %{2} 个模块包含标签', [$totalModules, $modulesWithTags]));
        
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
                    
                    // 检查是否为静态类
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
                            $this->printing->warning(__('标签类 %{1} 必须实现 TaglibInterface 接口', [$item]));
                            continue;
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
                    $this->printing->warning(__('加载标签类 %{1} 失败：%{2}', [$item, $e->getMessage()]));
                }
            }
        }
        
        // 保存到缓存
        $this->cache->set($cache_key, $modules_tags);
        
        // 生成 generated/taglibs.php 文件
        $this->generateTaglibsFile($module_tags);
        
        $this->printing->success(__('共收集到 %{1} 个标签', [$totalTags]));
    }

    /**
     * 生成 generated/taglibs.php 文件
     * @param array $module_tags 标签数据
     * @return void
     */
    private function generateTaglibsFile(array $module_tags): void
    {
        $registryFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'taglibs.php';
        $generatedDir = dirname($registryFile);
        
        // 确保 generated 目录存在
        if (!is_dir($generatedDir)) {
            mkdir($generatedDir, 0755, true);
        }
        
        // 处理标签数据：移除 callback 闭包（无法序列化），在运行时动态获取
        $serializableTags = [];
        foreach ($module_tags as $tagName => $tagData) {
            $serializableTagData = $tagData;
            // 移除 callback 闭包，保留 class 信息以便运行时获取
            if (isset($serializableTagData['callback'])) {
                unset($serializableTagData['callback']);
            }
            $serializableTags[$tagName] = $serializableTagData;
        }
        
        // 生成文件内容
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "/*\n";
        $content .= " * 本文件由标签库收集器自动生成，请勿手动修改\n";
        $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "return [\n";
        $content .= "    'tags' => " . var_export($serializableTags, true) . ",\n";
        $content .= "];\n";
        
        // 写入文件
        file_put_contents($registryFile, $content);
    }

    /**
     * 收集指定模块的标签库
     * @param string $moduleName 模块名称
     * @throws \Exception
     */
    private function collectModuleTaglibs(string $moduleName): void
    {
        $cache_key = 'Weline_Taglib_module_tags';
        
        // 先获取现有的缓存
        $modules_tags = $this->cache->get($cache_key) ?? [];
        
        $modules = Env::getInstance()->getActiveModules();
        $targetModule = null;
        
        // 查找目标模块
        foreach ($modules as $module) {
            if ($module['name'] === $moduleName) {
                $targetModule = $module;
                break;
            }
        }
        
        if (!$targetModule) {
            throw new \Exception(__('未找到模块：%{1}', [$moduleName]));
        }
        
        $this->printing->printing(__('扫描模块 %{1} 的标签库...', [$moduleName]));
        
        // 清空该模块的旧标签
        unset($modules_tags[$moduleName]);
        $modules_tags[$moduleName] = [];
        
        $tags = glob($targetModule['base_path'] . 'Taglib' . DS . '*.php');
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $tagF = rtrim($tag, '.php');
                $tagClass = str_replace(DS, '\\', str_replace($targetModule['base_path'], $targetModule['namespace_path'] . '\\', $tagF));
                $modules_tags[$moduleName][] = $tagClass;
            }
            $this->printing->printing(__('找到 %{1} 个标签', [count($tags)]));
        }
        
        // 验证和收集标签数据
        $validTags = 0;
        $module_tags = [];
        foreach ($modules_tags[$moduleName] as $item) {
            try {
                // 检查类是否存在并实现 TaglibInterface
                if (!class_exists($item)) {
                    continue;
                }
                
                $refClass = new \ReflectionClass($item);
                if (!$refClass->implementsInterface(TaglibInterface::class)) {
                    continue; // 跳过不符合接口的类
                }
                
                // 检查是否为静态类
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
                        $tag_data['module_name'] = $moduleName;
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
                        $validTags++;
                    }
                } else {
                    // 非静态类：尝试实例化
                    /**@var TaglibInterface $tagObject */
                    $tagObject = ObjectManager::getInstance($item);
                    if (!($tagObject instanceof TaglibInterface)) {
                        $this->printing->warning(__('标签类 %{1} 必须实现 TaglibInterface 接口', [$item]));
                        continue;
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
                        $tag_data['module_name'] = $moduleName;
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
                        $validTags++;
                    }
                }
            } catch (\Exception $e) {
                $this->printing->warning(__('加载标签类 %{1} 失败：%{2}', [$item, $e->getMessage()]));
            }
        }
        
        // 更新缓存中的标签数据
        if (!empty($module_tags)) {
            $modules_tags[$moduleName] = array_keys($module_tags);
            $this->cache->set($cache_key, $modules_tags);
            
            // 同时保存标签详细数据到另一个缓存键
            $tags_detail_key = 'Weline_Taglib_tags_detail';
            $tags_detail = $this->cache->get($tags_detail_key) ?? [];
            $tags_detail = array_merge($tags_detail, $module_tags);
            $this->cache->set($tags_detail_key, $tags_detail);
        }
        
        // 保存到缓存
        $this->cache->set($cache_key, $modules_tags);
        
        $this->printing->success(__('模块 %{1} 共收集到 %{2} 个有效标签', [$moduleName, $validTags]));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集标签库';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'taglib:collect',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-m, --module <模块名>' => '指定要收集的模块名称（可选）',
            ],
            [],
            [
                '收集所有模块的标签库' => 'taglib:collect',
                '收集指定模块的标签库' => 'taglib:collect -m Weline_Backend',
            ]
        );
    }
}

