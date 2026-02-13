<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Console\Env;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Env\Api\EnvCheckerInterface;
use Weline\Framework\Env\Api\EnvRequirementsCollectorInterface;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\EnvRequirementsCollector;
use Weline\Framework\Manager\ObjectManager;

/**
 * 环境检测命令
 * 
 * @DESC 检测当前环境是否满足框架和模块的依赖需求
 */
class Check extends CommandAbstract
{
    private EnvRequirementsCollectorInterface $collector;
    private EnvCheckerInterface $checker;

    public function __construct()
    {
        $this->collector = ObjectManager::getInstance(EnvRequirementsCollector::class);
        $this->checker = ObjectManager::getInstance(EnvChecker::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note(__('========== 环境依赖检测 =========='));
        $this->printer->note('');

        // 检查是否输出 JSON
        $jsonOutput = isset($args['json']) || isset($args['j']);

        // 收集环境需求
        $this->printer->note(__('正在收集环境需求...'));
        $requirements = $this->collector->collect();

        // 显示收集到的需求摘要
        $this->printRequirementsSummary($requirements);

        // 执行检测
        $this->printer->note('');
        $this->printer->note(__('正在检测环境...'));
        $this->printer->note('');

        $this->checker->setRequirements($requirements);
        $result = $this->checker->check();

        // 输出结果
        $this->printer->note('');
        $this->printResult($result, $jsonOutput);

        // 如果有错误，给出手动修复指引并非零退出（安装脚本可据此终止）
        if ($result->hasError()) {
            $this->printManualFixGuide($result);
            exit(1);
        }
    }

    /**
     * 打印需求摘要
     */
    private function printRequirementsSummary($requirements): void
    {
        $data = $requirements->toArray();
        
        $this->printer->note(__('收集到的需求：'));
        
        if ($data['php']) {
            $this->printer->printing(__('  PHP 版本: %{version}', ['version' => $data['php']]));
        }
        
        if (!empty($data['extensions'])) {
            $this->printer->printing(__('  扩展: %{count} 个', ['count' => count($data['extensions'])]));
        }
        
        if (!empty($data['functions'])) {
            $this->printer->printing(__('  函数: %{count} 个', ['count' => count($data['functions'])]));
        }
        
        if (!empty($data['items'])) {
            $this->printer->printing(__('  复杂依赖: %{count} 个', ['count' => count($data['items'])]));
        }

        $recommendedCount = count($data['recommended_extensions'] ?? [])
            + count($data['recommended_functions'] ?? [])
            + count($data['recommended_items'] ?? []);
        if ($recommendedCount > 0) {
            $this->printer->printing(__('  推荐项: %{count} 个', ['count' => $recommendedCount]));
        }
        
        $this->printer->printing(__('  来源: %{sources}', ['sources' => implode(', ', $data['sources'])]));
    }

    /**
     * 打印检测结果
     */
    private function printResult($result, bool $jsonOutput): void
    {
        if ($jsonOutput) {
            echo json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }

        $this->printer->note(__('========== 检测结果 =========='));

        if ($result->hasError()) {
            $this->printer->error(__('环境检测未通过 ✖'));
            $this->printer->note('');

            // PHP 版本问题
            if ($result->getPhpVersionIssue()) {
                $this->printer->error(__('PHP 版本问题: %{issue}', ['issue' => $result->getPhpVersionIssue()]));
            }

            // 缺失的扩展
            $missing = $result->getMissingExtensions();
            if (!empty($missing)) {
                $this->printer->error(__('缺失的扩展:'));
                foreach ($missing as $ext) {
                    $this->printer->error('  - ' . $ext);
                }
            }

            // 被禁用的函数
            $disabled = $result->getDisabledFunctions();
            if (!empty($disabled)) {
                $this->printer->error(__('被禁用的函数:'));
                foreach ($disabled as $func) {
                    $this->printer->error('  - ' . $func);
                }
            }

            // 未满足的 items
            $unsatisfied = $result->getUnsatisfiedItems();
            if (!empty($unsatisfied)) {
                $this->printer->error(__('未满足的依赖:'));
                foreach ($unsatisfied as $item) {
                    $name = $item['name'] ?? __('未命名');
                    $desc = $item['description'] ?? '';
                    $this->printer->error('  - ' . $name);
                    if ($desc) {
                        $this->printer->warning('    ' . $desc);
                    }
                }
            }
        } else {
            $this->printer->success(__('环境检测通过 ✔'));
        }

        // 显示推荐项（黄色提示，不算错误）
        if ($result->hasRecommendation()) {
            $this->printer->note('');
            $this->printer->warning(__('---------- 性能优化建议 ----------'));

            $missingRec = $result->getMissingRecommendedExtensions();
            if (!empty($missingRec)) {
                $this->printer->warning(__('推荐安装的扩展:'));
                foreach ($missingRec as $ext) {
                    $this->printer->warning('  ◐ ' . $ext);
                }
            }

            $disabledRec = $result->getDisabledRecommendedFunctions();
            if (!empty($disabledRec)) {
                $this->printer->warning(__('推荐解禁的函数:'));
                foreach ($disabledRec as $func) {
                    $this->printer->warning('  ◐ ' . $func);
                }
            }

            $unsatisfiedRec = $result->getUnsatisfiedRecommendedItems();
            if (!empty($unsatisfiedRec)) {
                $this->printer->warning(__('推荐安装的依赖:'));
                foreach ($unsatisfiedRec as $item) {
                    $name = $item['name'] ?? __('未命名');
                    $desc = $item['description'] ?? '';
                    $this->printer->warning('  ◐ ' . $name);
                    if ($desc) {
                        $this->printer->printing('    ' . $desc);
                    }
                }
            }

            $this->printer->note('');
            $this->printer->note(__('提示: 推荐项为可选优化，不影响系统运行，但安装后可显著提升性能'));
            $this->printer->note(__('按需安装推荐项: php bin/w env:install <名称> -y，例如 env:install event -y'));
        }
    }

    /**
     * 打印手动修复指引
     */
    private function printManualFixGuide($result): void
    {
        $this->printer->note('');
        $this->printer->note(__('========== 手动修复指引 =========='));

        // PHP 版本
        if ($result->getPhpVersionIssue()) {
            $this->printer->warning(__('【PHP 版本】'));
            $this->printer->printing(__('  去什么地方: 系统 PHP 安装目录'));
            $this->printer->printing(__('  做什么操作: 升级 PHP 到符合要求的版本'));
            $this->printer->printing(__('  如何验证: 运行 php -v 查看版本，再次运行 php bin/w env:check'));
            $this->printer->note('');
        }

        // 缺失的扩展
        $missing = $result->getMissingExtensions();
        if (!empty($missing)) {
            $phpIniPath = php_ini_loaded_file() ?: __('未知');
            $this->printer->warning(__('【缺失的扩展】'));
            $this->printer->printing(__('  去什么地方: %{path}', ['path' => $phpIniPath]));
            $this->printer->printing(__('  做什么操作:'));
            $this->printer->printing(__('    1. 安装缺失的扩展（如 pecl install %{ext}）', ['ext' => $missing[0] ?? 'extension']));
            $this->printer->printing(__('    2. 在 php.ini 中添加 extension=%{ext}', ['ext' => $missing[0] ?? 'extension']));
            $this->printer->printing(__('  如何验证: 运行 php -m 查看已加载扩展，再次运行 php bin/w env:check'));
            $this->printer->note('');
        }

        // 被禁用的函数
        $disabled = $result->getDisabledFunctions();
        if (!empty($disabled)) {
            $phpIniPath = php_ini_loaded_file() ?: __('未知');
            $this->printer->warning(__('【被禁用的函数】'));
            $this->printer->printing(__('  去什么地方: %{path}', ['path' => $phpIniPath]));
            $this->printer->printing(__('  做什么操作:'));
            $this->printer->printing(__('    1. 打开 php.ini 文件'));
            $this->printer->printing(__('    2. 找到 disable_functions 配置项'));
            $this->printer->printing(__('    3. 从中移除以下函数: %{funcs}', ['funcs' => implode(', ', $disabled)]));
            $this->printer->printing(__('    4. 重启 PHP/Web 服务'));
            $this->printer->printing(__('  如何验证: 再次运行 php bin/w env:check'));
            $this->printer->note('');
        }

        // 未满足的 items
        $unsatisfied = $result->getUnsatisfiedItems();
        if (!empty($unsatisfied)) {
            $this->printer->warning(__('【未满足的依赖】'));
            foreach ($unsatisfied as $item) {
                $name = $item['name'] ?? __('未命名');
                $desc = $item['description'] ?? __('请参考模块文档');
                $module = $item['module'] ?? '';
                
                $this->printer->printing(__('  依赖: %{name}', ['name' => $name]));
                if ($module) {
                    $this->printer->printing(__('  来源模块: %{module}', ['module' => $module]));
                }
                $this->printer->printing(__('  说明: %{desc}', ['desc' => $desc]));
                $this->printer->note('');
            }
        }

        $this->printer->note(__('提示: 可运行 php bin/w env:install -y 尝试自动安装必需依赖'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('检测当前环境是否满足框架和模块的依赖需求');
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'env:check',
            __('检测当前环境是否满足框架和模块的依赖需求，包括 PHP 版本、扩展、函数、复杂依赖项等'),
            [
                '-j, --json' => __('以 JSON 格式输出结果'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本检测') => 'php bin/w env:check',
                __('JSON 输出') => 'php bin/w env:check --json',
            ]
        );
    }
}
