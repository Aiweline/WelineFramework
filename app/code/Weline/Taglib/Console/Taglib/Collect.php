<?php

declare(strict_types=1);

/*
 * 覆盖 vendor/weline/module-taglib 的 Collect（app/code 优先于 vendor，见 app/autoload.php）。
 * 对静态标签类不调用 ObjectManager::getInstance，与 ObjectManager::isStaticClass 保持一致，
 * 避免“不支持静态类实例化”错误。
 */

namespace Weline\Taglib\Console\Taglib;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Taglib\TaglibInterface;
use Weline\Taglib\TaglibRegistry;

class Collect implements CommandInterface
{
    private Printing $printing;
    private CachePoolInterface $cache;

    public function __construct(
        Printing $printing
    ) {
        $this->printing = $printing;
        $this->cache = w_cache('taglib');
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $moduleName = $args['module'] ?? $args['m'] ?? $args[1] ?? null;

        if ($moduleName) {
            $moduleName = is_string($moduleName) ? trim($moduleName) : '';
            // 支持逗号分隔的多个模块；仅保留已注册的模块名，避免 stage code（如 schema_diff）等被当作模块
            $requested = $moduleName === '' ? [] : array_map('trim', explode(',', $moduleName));
            $validNames = array_keys(Env::getInstance()->getActiveModules());
            $moduleList = array_values(array_intersect($requested, $validNames));
            if ($moduleList !== $requested && $requested !== []) {
                $this->printing->warning(__('“%{1}”不是已注册模块名，已忽略；仅收集已注册模块的标签。', [implode(', ', array_diff($requested, $moduleList))]));
            }
            if (!empty($moduleList)) {
                $this->printing->note(__('正在收集模块 %{1} 的标签库...', [implode(', ', $moduleList)]));
                try {
                    foreach ($moduleList as $name) {
                        $this->collectModuleTaglibs($name);
                    }
                    $this->printing->success(__('模块 %{1} 标签库收集成功！', [implode(', ', $moduleList)]));
                } catch (\Exception $e) {
                    $this->printing->error(__('模块 %{1} 标签库收集失败：%{2}', [implode(', ', $moduleList), $e->getMessage()]));
                    return;
                }
            } else {
                $this->printing->note(__('正在收集所有模块的标签库...'));
                try {
                    $this->collectAllTaglibs();
                    $this->printing->success(__('所有标签库收集成功！'));
                } catch (\Exception $e) {
                    $this->printing->error(__('标签库收集失败：%{1}', [$e->getMessage()]));
                    return;
                }
            }
        } else {
            $this->printing->note(__('正在收集所有模块的标签库...'));
            try {
                $this->collectAllTaglibs();
                $this->printing->success(__('所有标签库收集成功！'));
            } catch (\Exception $e) {
                $this->printing->error(__('标签库收集失败：%{1}', [$e->getMessage()]));
                return;
            }
        }

        $this->printing->note(__('正在清理标签库缓存...'));
        try {
            $this->cache->clear();
            $this->printing->success(__('标签库缓存清理成功！'));
        } catch (\Exception $e) {
            $this->printing->warning(__('标签库缓存清理失败：%{1}，但标签收集已完成。', [$e->getMessage()]));
        }

        $this->printing->note(__('清理模板缓存...'));
        try {
            /** @var \Weline\Framework\Cache\Console\Template\Clear $templateCacheClear */
            $templateCacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Template\Clear::class);
            $templateCacheClear->execute();
            $this->printing->success(__('模板缓存清理完成！'));
        } catch (\Exception $e) {
            $this->printing->warning(__('模板缓存清理失败：%{1}', [$e->getMessage()]));
        }

        $this->printing->success(__('标签库收集和缓存清理完成！'));
    }

    /**
     * 收集所有模块的标签库
     * 静态标签类直接使用类名调用，不通过 ObjectManager 实例化
     *
     * @throws \Exception
     */
    private function collectAllTaglibs(): void
    {
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = [];
        $modules = Env::getInstance()->getActiveModules();

        $totalModules = count($modules);
        $currentModule = 0;

        foreach ($modules as $module) {
            $currentModule++;
            $this->printing->printing(__('正在扫描模块 %{1} (%{2}/%{3})...', [$module['name'], $currentModule, $totalModules]));

            $tags = glob($module['base_path'] . 'Taglib' . DS . '*.php');
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $tagF = rtrim($tag, '.php');
                    $tagClass = str_replace(DS, '\\', str_replace($module['base_path'], $module['namespace_path'] . '\\', $tagF));
                    $modules_tags[$module['name']][] = $tagClass;
                }
                $this->printing->printing(__('  - 找到 %{1} 个标签', [count($tags)]));
            }
        }

        $module_tags = [];
        $totalTags = 0;
        foreach ($modules_tags as $module_name => $module_tag) {
            foreach ($module_tag as $item) {
                try {
                    if (!class_exists($item)) {
                        continue;
                    }
                    if (!(new \ReflectionClass($item))->implementsInterface(TaglibInterface::class)) {
                        continue;
                    }

                    $isStaticClass = ObjectManager::isStaticClass($item);

                    if ($isStaticClass) {
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
                            if (method_exists($item, 'parent')) {
                                $parentTag = $item::parent();
                                if ($parentTag) {
                                    if (strpos($parentTag, ',') !== false) {
                                        $tag_data['parent'] = array_map('trim', explode(',', $parentTag));
                                    } else {
                                        $tag_data['parent'] = $parentTag;
                                    }
                                }
                            }
                            $module_tags[$item::name()] = $tag_data;
                            $totalTags++;
                        }
                    } else {
                        /** @var TaglibInterface $tagObject */
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
                            if (method_exists($tagObject, 'parent')) {
                                $parentTag = $tagObject::parent();
                                if ($parentTag) {
                                    if (strpos($parentTag, ',') !== false) {
                                        $tag_data['parent'] = array_map('trim', explode(',', $parentTag));
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

        $this->cache->set($cache_key, $modules_tags);

        /** @var TaglibRegistry $registry */
        $registry = ObjectManager::getInstance(TaglibRegistry::class);
        if ($registry->saveRegistry($module_tags)) {
            $this->printing->success(__('标签注册表已保存到 %{1}', [TaglibRegistry::REGISTRY_FILE]));
        } else {
            $this->printing->warning(__('标签注册表保存失败'));
        }

        $this->printing->success(__('共收集到 %{1} 个标签', [$totalTags]));
    }

    /**
     * 收集指定模块的标签库
     *
     * @param string $moduleName
     * @throws \Exception
     */
    private function collectModuleTaglibs(string $moduleName): void
    {
        $cache_key = 'Weline_Taglib_module_tags';
        $modules_tags = $this->cache->get($cache_key) ?? [];

        $modules = Env::getInstance()->getActiveModules();
        $targetModule = null;

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

        $validTags = 0;
        foreach ($modules_tags[$moduleName] as $item) {
            try {
                if (!class_exists($item)) {
                    continue;
                }
                if (!(new \ReflectionClass($item))->implementsInterface(TaglibInterface::class)) {
                    continue;
                }
                if (ObjectManager::isStaticClass($item)) {
                    $validTags++;
                } else {
                    $tagObject = ObjectManager::getInstance($item);
                    if ($tagObject instanceof TaglibInterface) {
                        $validTags++;
                    }
                }
            } catch (\Exception $e) {
                $this->printing->warning(__('加载标签类 %{1} 失败：%{2}', [$item, $e->getMessage()]));
            }
        }

        $this->cache->set($cache_key, $modules_tags);
        $this->printing->success(__('模块 %{1} 共收集到 %{2} 个有效标签', [$moduleName, $validTags]));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('收集标签库');
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
                '-h, --help' => __('显示帮助信息'),
                '-m, --module <模块名>' => __('指定要收集的模块名称（可选）'),
            ],
            [],
            [
                __('收集所有模块的标签库') => 'taglib:collect',
                __('收集指定模块的标签库') => 'taglib:collect -m Weline_Backend',
            ]
        );
    }
}
