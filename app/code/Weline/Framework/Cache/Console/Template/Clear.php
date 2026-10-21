<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Template;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Console\Command\Upgrade;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\TemplateCacheManager;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        Printing $printing,
        System   $system
    )
    {
        $this->system = $system;
        $this->printing = $printing;
    }


    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        unset($args[0]);
        $modules = Env::getInstance()->getModuleList();
        if (empty($modules)) {
            ObjectManager::getInstance(Upgrade::class)->execute();
        }
        
        // 检查是否为静默模式（批量升级时使用）
        $silent = $data['silent'] ?? false;
        $clearedCount = 0;
        
        if (!$silent) {
            $this->printing->note(__('开始清理拓展全页缓存：'));
        }
        
        foreach ($args as $arg) {
            if (isset($modules[$arg]) && $module_data = $modules[$arg]) {
                if ($this->clear($arg, $module_data['base_path'], $silent)) {
                    $clearedCount++;
                }
            } elseif (!$silent) {
                $this->printing->note(__('模块')) . $this->printing->setup($arg) . $this->printing->note(__('不存在！'));
            }
        }
        if (empty($args)) {
            foreach ($modules as $module_name => $module_data) {
                if ($this->clear($module_name, $module_data['base_path'], $silent)) {
                    $clearedCount++;
                }
            }
        }
        
        // 在静默模式下，只输出汇总信息
        if ($silent && $clearedCount > 0) {
            $this->printing->note(__('已清理 %{1} 个模块的模板缓存', [$clearedCount]));
        } elseif ($silent && $clearedCount === 0) {
            $this->printing->note(__('没有需要清理的模板缓存'));
        }

        $this->clearViewCompileCaches();
    }

    /**
     * 清理模板编译相关缓存，覆盖 view/tpl 之外的增强模板缓存和 Taglib 文件缓存。
     */
    private function clearViewCompileCaches(): void
    {
        try {
            $taglib = ObjectManager::getInstance(Taglib::class);
            if ($taglib instanceof Taglib) {
                $taglib->clearCache();
            }
        } catch (\Throwable) {
            // View may be unavailable in stripped CLI contexts.
        }

        try {
            TemplateCacheManager::getInstance()->clearAll();
        } catch (\Throwable) {
            // Enhanced template cache clear is best-effort during template cache clear.
        }
    }

    /**
     * 清理指定模块的模板缓存
     * @param string $module_name 模块名
     * @param string $base_path 基础路径
     * @param bool $silent 静默模式
     * @return bool 是否有清理操作
     */
    public function clear(string $module_name, string $base_path, bool $silent = false): bool
    {
        $cleared = false;
        $tplDir = $base_path . DataInterface::dir . DS . DataInterface::dir_type_TEMPLATE_COMPILE;
        if (is_dir($tplDir)) {
            $this->system->exec('rm -rf ' . \escapeshellarg($tplDir));
            $cleared = true;
        }

        // PROD/部署态编译产物在 generated/complicate/<Vendor>/<Module>/view，不在模块 view/tpl。
        // 只清该模块子树，避免整树 1.9G 重编译。
        $complicateDir = $this->complicateModuleViewDir($base_path);
        if ($complicateDir !== null && is_dir($complicateDir)) {
            $this->system->exec('rm -rf ' . \escapeshellarg($complicateDir));
            $cleared = true;
        }

        if ($cleared && !$silent) {
            $this->printing->note(__('清理完成：%{1}', [$module_name]));
        }

        return $cleared;
    }

    private function complicateModuleViewDir(string $basePath): ?string
    {
        $basePath = \rtrim(\str_replace('\\', '/', $basePath), '/');
        $appCode = \rtrim(\str_replace('\\', '/', BP . 'app/code'), '/') . '/';
        if (!\str_starts_with($basePath, $appCode)) {
            return null;
        }
        $relative = \substr($basePath, \strlen($appCode));
        if ($relative === '' || \str_contains($relative, '..')) {
            return null;
        }

        return \rtrim(\Weline\Framework\App\Env::path_COMPLICATE_GENERATED_DIR, '/\\')
            . DS . $relative . DS . DataInterface::dir . DS;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '清理模板缓存！';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
