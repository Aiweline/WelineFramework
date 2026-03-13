<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Console\Env;

use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Env\Api\EnvCheckerInterface;
use Weline\Framework\Env\Api\EnvRequirementsCollectorInterface;
use Weline\Framework\Env\Api\InstallScriptExecutorInterface;
use Weline\Framework\Env\Api\Data\EnvCheckResult;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\EnvRequirementsCollector;
use Weline\Framework\Env\Service\ExtensionInstallStrategyMap;
use Weline\Framework\Env\Service\LinuxScriptExecutor;
use Weline\Framework\Env\Service\RecommendedStatusService;
use Weline\Framework\Env\Service\WindowsScriptExecutor;
use Weline\Framework\Manager\ObjectManager;

/**
 * 环境安装命令
 * 
 * @DESC 检测并尝试自动修复环境依赖问题
 */
class Install extends CommandAbstract
{
    private EnvRequirementsCollectorInterface $collector;
    private EnvCheckerInterface $checker;
    private RecommendedStatusService $statusService;
    private System $system;

    /** @var bool 是否强制重试已尝试过的推荐项 */
    private bool $forceRetry = false;

    /** @var bool 是否以 root/管理员权限运行 */
    private bool $isRoot = false;

    /** @var bool 是否有 sudo 可用 */
    private bool $hasSudo = false;

    public function __construct()
    {
        $this->collector = ObjectManager::getInstance(EnvRequirementsCollector::class);
        $this->checker = ObjectManager::getInstance(EnvChecker::class);
        $this->statusService = ObjectManager::getInstance(RecommendedStatusService::class);
        $this->system = ObjectManager::getInstance(System::class);
        $this->detectPrivileges();
    }

    /**
     * 检测当前用户权限
     */
    private function detectPrivileges(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: 检查是否以管理员身份运行
            $output = [];
            @exec('net session 2>&1', $output, $code);
            $this->isRoot = ($code === 0);
            $this->hasSudo = false; // Windows 没有 sudo
        } else {
            // Linux/macOS: 检查是否为 root (uid=0)
            $this->isRoot = (posix_getuid() === 0);
            // 检查 sudo 是否可用
            $this->hasSudo = $this->commandExists('sudo');
        }
    }

    /**
     * 检查命令是否存在
     */
    private function commandExists(string $cmd): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec("where $cmd 2>NUL", $output, $code);
            return $code === 0 && !empty($output);
        } else {
            $output = [];
            @exec("command -v $cmd 2>/dev/null", $output, $code);
            return $code === 0 && !empty($output);
        }
    }

    /**
     * 获取带 sudo 前缀的命令（如果需要且可用）
     */
    private function getSudoCommand(string $cmd): string
    {
        if ($this->isRoot) {
            return $cmd;
        }
        if ($this->hasSudo) {
            return 'sudo ' . $cmd;
        }
        return $cmd;
    }

    /**
     * 打印当前权限状态
     */
    private function printPrivilegeStatus(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if ($this->isRoot) {
                $this->printer->success(__('当前以管理员身份运行 ✔'));
            } else {
                $this->printer->warning(__('当前非管理员身份运行'));
                $this->printer->note(__('  部分操作（如修改 php.ini、安装扩展）可能需要管理员权限'));
                $this->printer->note(__('  建议：右键点击命令提示符 → 以管理员身份运行'));
            }
        } else {
            $currentUser = posix_getpwuid(posix_getuid())['name'] ?? __('未知');
            if ($this->isRoot) {
                $this->printer->success(__('当前以 root 身份运行 ✔'));
            } elseif ($this->hasSudo) {
                $this->printer->note(__('当前用户: %{user}（非 root，但有 sudo 可用）', ['user' => $currentUser]));
                $this->printer->note(__('  将自动使用 sudo 执行需要权限的操作'));
            } else {
                $this->printer->warning(__('当前用户: %{user}（非 root，且无 sudo 可用）', ['user' => $currentUser]));
                $this->printer->warning(__('  部分操作可能失败，建议切换到 root 或具有 sudo 权限的用户'));
                $this->printer->note(__('  执行: sudo php bin/w env:install -y'));
            }
        }
        $this->printer->note('');
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note(__('========== 环境依赖安装 =========='));
        $this->printer->note('');

        // 权限检测提示
        $this->printPrivilegeStatus();

        $skipConfirm = !empty($args['y']) || !empty($args['yes']) || !empty($args['-y']);
        // 仅当第一个参数不是选项（不以 - 开头）时才视为推荐项名称，避免 env:install -y 被当成安装名为 -y 的推荐项
        $target = null;
        if (isset($args[1]) && is_string($args[1]) && $args[1] !== '' && !str_starts_with(trim($args[1]), '-')) {
            $target = trim($args[1]);
        }

        $this->forceRetry = isset($args['force']) || isset($args['F']);
        if ($this->forceRetry) {
            $this->printer->warning(__('强制模式：将重试所有推荐项（忽略之前的安装记录）'));
            $this->statusService->resetAll();
            $this->printer->note('');
        }

        $this->printer->note(__('正在收集环境需求...'));
        $requirements = $this->collector->collect();
        $this->printer->note(__('正在检测环境...'));
        $this->checker->setRequirements($requirements);
        $result = $this->checker->check();
        $this->printer->note('');

        // 非交互（如被 run.php 或 CI 调用）时，检测到缺失依赖则自动执行安装，不等待确认
        if (!$skipConfirm && $result->hasError() && function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
            $skipConfirm = true;
        }

        if ($target !== null) {
            $this->executeInstallTarget($target, $result, $skipConfirm);
            return;
        }

        if (!$result->hasError() && !$result->hasRecommendation()) {
            $this->printer->success(__('环境检测通过，无需修复 ✔'));
            return;
        }

        if (!$result->hasError() && $result->hasRecommendation()) {
            $this->printer->success(__('必需依赖已满足 ✔'));
            $this->printer->note(__('可选推荐项可单独安装，例如: php bin/w env:install event -y'));
            return;
        }

        if (!$result->hasError()) {
            return;
        }

        $this->printPendingActions($result, $requirements);

        if (!$skipConfirm) {
            $this->printer->note('');
            $this->printer->warning(__('即将执行以上操作（必需项 + 可选推荐项），是否继续？(y/n)'));
            $input = $this->system->input();
            if (strtolower(trim($input)) !== 'y') {
                $this->printer->note(__('操作已取消'));
                return;
            }
        }

        $this->printer->note('');
        $this->printer->note(__('开始执行修复（必需项与可选推荐项）...'));
        $this->executeInstall($result, $requirements);
    }

    /**
     * 打印将要执行的操作
     */
    private function printPendingActions(EnvCheckResult $result, $requirements): void
    {
        $this->printer->warning(__('========== 将尝试执行以下操作 =========='));
        $this->printer->note('');

        $hasAction = false;

        // 被禁用的函数
        $disabled = $result->getDisabledFunctions();
        if (!empty($disabled)) {
            $phpIniPath = php_ini_loaded_file() ?: __('未知');
            $hasAction = true;
            $this->printer->warning(__('【函数解禁】'));
            $this->printer->printing(__('  将尝试修改: %{path}', ['path' => $phpIniPath]));
            $this->printer->printing(__('  修改内容: 从 disable_functions 中移除: %{funcs}', ['funcs' => implode(', ', $disabled)]));
            $this->printer->note('');
        }

        // 缺失的扩展
        $missing = $result->getMissingExtensions();
        if (!empty($missing)) {
            $hasAction = true;
            $this->printer->warning(__('【扩展安装】'));
            $this->printer->printing(__('  将尝试安装: %{exts}', ['exts' => implode(', ', $missing)]));
            $this->printer->note(__('  注意: 扩展安装可能需要系统权限，如失败请手动安装'));
            $this->printer->note('');
        }

        // 未满足的 items（有脚本的）
        $unsatisfied = $result->getUnsatisfiedItems();
        if (!empty($unsatisfied)) {
            $hasAction = true;
            $this->printer->warning(__('【依赖项安装】'));
            foreach ($unsatisfied as $item) {
                $name = $item['name'] ?? __('未命名');
                $module = $item['module'] ?? '';
                $scriptLinux = $item['script_linux'] ?? '';
                $scriptWindows = $item['script_windows'] ?? '';

                $this->printer->printing(__('  依赖: %{name}', ['name' => $name]));
                if ($module) {
                    $this->printer->printing(__('  来源模块: %{module}', ['module' => $module]));
                }
                
                $scriptDarwin = $item['script_darwin'] ?? '';
                if (PHP_OS_FAMILY === 'Windows' && $scriptWindows) {
                    $this->printer->printing(__('  将执行脚本: %{script} --install', ['script' => $scriptWindows]));
                } elseif (PHP_OS_FAMILY === 'Darwin' && $scriptDarwin) {
                    $this->printer->printing(__('  将执行脚本: %{script} --install（macOS）', ['script' => $scriptDarwin]));
                } elseif (PHP_OS_FAMILY !== 'Windows' && $scriptLinux) {
                    $this->printer->printing(__('  将执行脚本: %{script} --install', ['script' => $scriptLinux]));
                } else {
                    $this->printer->printing(__('  将尝试执行 env/script/ 下的安装脚本'));
                }
                $this->printer->note('');
            }
        }

        // 推荐函数
        $disabledRecFuncs = $result->getDisabledRecommendedFunctions();
        if (!empty($disabledRecFuncs)) {
            $hasAction = true;
            $this->printer->warning(__('【推荐函数】（可选，提升性能）'));
            $this->printer->printing(__('  将尝试解禁: %{funcs}', ['funcs' => implode(', ', $disabledRecFuncs)]));
            $this->printer->note('');
        }

        // 推荐扩展
        $missingRec = $result->getMissingRecommendedExtensions();
        if (!empty($missingRec)) {
            $hasAction = true;
            $this->printer->warning(__('【推荐扩展】（可选，提升性能）'));
            $this->printer->printing(__('  将尝试安装: %{exts}', ['exts' => implode(', ', $missingRec)]));
            $this->printer->note('');
        }

        // 推荐 items
        $unsatisfiedRec = $result->getUnsatisfiedRecommendedItems();
        if (!empty($unsatisfiedRec)) {
            $hasAction = true;
            $this->printer->warning(__('【推荐依赖】（可选，提升性能）'));
            foreach ($unsatisfiedRec as $item) {
                $name = $item['name'] ?? __('未命名');
                $desc = $item['description'] ?? '';
                $this->printer->printing(__('  依赖: %{name}', ['name' => $name]));
                if ($desc) {
                    $this->printer->printing(__('  说明: %{desc}', ['desc' => $desc]));
                }
            }
            $this->printer->note('');
        }

        if (!$hasAction) {
            $this->printer->note(__('没有可自动修复的问题，请参考上面的手动修复指引'));
        }
    }

    /**
     * 仅安装指定推荐项（扩展名或依赖名称），例如 env:install event -y
     * 支持必需扩展（如 pdo_pgsql）和推荐扩展，便于 run.php 在数据库初始化前显式安装数据库驱动
     */
    private function executeInstallTarget(string $target, EnvCheckResult $result, bool $skipConfirm): void
    {
        $targetLower = strtolower($target);
        $missing = $result->getMissingExtensions();
        $missingRec = $result->getMissingRecommendedExtensions();
        $unsatisfiedRec = $result->getUnsatisfiedRecommendedItems();

        // 必需扩展（如 pdo_pgsql 数据库驱动）也可单独安装：env:install pdo_pgsql -y
        if (in_array($targetLower, array_map('strtolower', $missing), true)) {
            $this->printer->note(__('正在安装数据库驱动扩展: %{ext}', ['ext' => $target]));
            $installed = $this->tryInstallExtension($targetLower);
            if ($installed) {
                $this->statusService->markInstalled('extension', $targetLower);
                $this->printer->success(__('扩展 %{ext} 已启用 ✔', ['ext' => $target]));
            } else {
                $this->printer->warning(__('扩展 %{ext} 自动安装失败', ['ext' => $target]));
                $this->printExtensionInstallGuide($targetLower);
            }
            return;
        }

        if (in_array($targetLower, array_map('strtolower', $missingRec), true)) {
            $this->printer->note(__('正在安装推荐扩展: %{ext}', ['ext' => $target]));
            $installed = $this->tryInstallExtension($targetLower);
            if ($installed) {
                $this->statusService->markInstalled('extension', $targetLower);
                $this->printer->success(__('推荐扩展 %{ext} 已启用 ✔', ['ext' => $target]));
            } else {
                $this->printer->warning(__('推荐扩展 %{ext} 安装失败', ['ext' => $target]));
                $this->printExtensionInstallGuide($targetLower);
            }
            return;
        }

        foreach ($unsatisfiedRec as $item) {
            $name = $item['name'] ?? '';
            $installId = $item['install_id'] ?? '';
            $nameMatch = strtolower($name) === $targetLower;
            $idMatch = $installId !== '' && strtolower($installId) === $targetLower;
            if (!$nameMatch && !$idMatch) {
                continue;
            }
            $modulePath = $item['module_path'] ?? '';
            if (empty($modulePath)) {
                $this->printer->warning(__('推荐项 %{name} 无模块路径，跳过', ['name' => $target]));
                return;
            }
            $this->printer->note(__('正在安装推荐项: %{name}', ['name' => $target]));
            $envDir = $modulePath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR;
            $executor = $this->getScriptExecutor();
            $execResult = $executor->execute($modulePath, $item, $envDir, InstallScriptExecutorInterface::ACTION_INSTALL);
            if ($execResult->isSuccess()) {
                $this->statusService->markInstalled('item', $name, $execResult->getOutput() ?: '');
                $this->printer->success(__('推荐项 %{name} 安装成功 ✔', ['name' => $target]));
            } else {
                $this->printer->warning(__('推荐项 %{name} 安装失败', ['name' => $target]));
                $this->printItemInstallFailure($item);
            }
            return;
        }

        $this->printer->warning(__('未找到推荐项「%{target}」。可运行 php bin/w env:check 查看当前推荐项。', ['target' => $target]));
    }

    /**
     * 执行安装：先必需依赖，再可选推荐项（检测通过性由必需依赖决定，但安装时一并尝试推荐项）
     */
    private function executeInstall(EnvCheckResult $result, $requirements): void
    {
        $successCount = 0;
        $failCount = 0;

        // 1. 尝试修复被禁用的函数
        $disabled = $result->getDisabledFunctions();
        if (!empty($disabled)) {
            $this->printer->note(__('【步骤 1】尝试解禁函数...'));
            $success = $this->tryUnblockFunctions($disabled);
            if ($success) {
                $successCount++;
                $this->printer->success(__('  函数解禁成功 ✔'));
            } else {
                $failCount++;
                $this->printFunctionUnblockFailure($disabled);
            }
            $this->printer->note('');
        }

        // 2. 尝试安装缺失的扩展
        $missing = $result->getMissingExtensions();
        $triedRebuildPhp = false;
        if (!empty($missing)) {
            $this->printer->note(__('【步骤 2】尝试安装缺失的扩展...'));
            foreach ($missing as $ext) {
                $this->printer->note(__('  正在处理扩展: %{ext}', ['ext' => $ext]));
                $installed = $this->tryInstallExtension($ext);
                if ($installed) {
                    $successCount++;
                    $this->printer->success(__('    扩展 %{ext} 已启用 ✔', ['ext' => $ext]));
                } else {
                    // 项目自带 PHP（extend/server/php）时，包管理器安装的扩展无效，尝试重编 PHP（仅试一次）
                    if (
                        !$triedRebuildPhp
                        && PHP_OS_FAMILY !== 'Windows'
                        && str_contains((string) PHP_BINARY, 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php')
                    ) {
                        $triedRebuildPhp = true;
                        $this->printer->note(__('    当前为项目自带 PHP，正在尝试重编 PHP 以加入缺失扩展（bin/install.sh --rebuild-php php）...'));
                        if ($this->tryRebuildProjectPhp()) {
                            $this->printer->success(__('    PHP 已重新编译完成。请再次运行 php bin/w env:check 验证环境。'));
                            $successCount++;
                            break; // 当前进程仍为旧 PHP，后续扩展需重跑 env:check 后再判
                        }
                    }
                    $failCount++;
                    $this->printer->warning(__('    扩展 %{ext} 自动安装失败，请参考以下指引手动安装', ['ext' => $ext]));
                    $this->printExtensionInstallGuide($ext);
                    if (str_contains((string) PHP_BINARY, 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php')) {
                        $this->printer->note(__('    若当前使用的是项目自带的 PHP，可手动执行: bin/install.sh --rebuild-php php'));
                    }
                }
            }
            $this->printer->note('');
        }

        // 3. 执行未满足的 items 的安装脚本
        $unsatisfied = $result->getUnsatisfiedItems();
        if (!empty($unsatisfied)) {
            $this->printer->note(__('【步骤 3】执行依赖项安装脚本...'));
            $executor = $this->getScriptExecutor();

            foreach ($unsatisfied as $item) {
                $name = $item['name'] ?? __('未命名');
                $modulePath = $item['module_path'] ?? '';

                if (empty($modulePath)) {
                    $this->printer->warning(__('  跳过 %{name}（无模块路径）', ['name' => $name]));
                    $failCount++;
                    continue;
                }

                $this->printer->note(__('  正在安装: %{name}', ['name' => $name]));

                $envDir = $modulePath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR;
                $execResult = $executor->execute($modulePath, $item, $envDir, InstallScriptExecutorInterface::ACTION_INSTALL);

                if ($execResult->isSuccess()) {
                    $successCount++;
                    $this->printer->success(__('    安装成功 ✔'));
                    if ($execResult->getOutput()) {
                        $this->printer->printing('    ' . $execResult->getOutput());
                    }
                } else {
                    $failCount++;
                    $this->printer->error(__('    安装失败 ✖'));
                    if ($execResult->getErrorOutput()) {
                        $this->printer->error('    ' . $execResult->getErrorOutput());
                    }
                    $this->printItemInstallFailure($item);
                }
            }
            $this->printer->note('');
        }

        // 4. 尝试解禁推荐函数
        $recSuccess = 0;
        $recFail = 0;
        $recSkipped = 0;
        $disabledRecFuncs = $result->getDisabledRecommendedFunctions();
        if (!empty($disabledRecFuncs)) {
            $this->printer->note(__('【步骤 4】尝试处理推荐函数（可选优化）...'));
            $disableFunctions = \ini_get('disable_functions');
            $disabledList = \array_map('trim', \explode(',', $disableFunctions ?? ''));
            foreach ($disabledRecFuncs as $func) {
                if ($this->statusService->hasAttempted('function', $func)) {
                    $prevStatus = $this->statusService->getStatus('function', $func);
                    $this->printer->note(__('  推荐函数 %{func} 已尝试过（%{status}），跳过', ['func' => $func, 'status' => $prevStatus]));
                    $recSkipped++;
                    continue;
                }
                if (\in_array($func, $disabledList, true)) {
                    $this->printer->note(__('  尝试解禁函数: %{func}', ['func' => $func]));
                    $success = $this->tryUnblockFunctions([$func]);
                    if ($success) {
                        $recSuccess++;
                        $this->statusService->markInstalled('function', $func, __('已从 disable_functions 解禁'));
                        $this->printer->success(__('    推荐函数 %{func} 解禁成功 ✔', ['func' => $func]));
                    } else {
                        $recFail++;
                        $this->statusService->markFailed('function', $func, __('解禁失败，需手动编辑 php.ini'));
                        $this->printer->warning(__('    推荐函数 %{func} 解禁失败（不影响系统运行）', ['func' => $func]));
                    }
                } else {
                    $this->statusService->markFailed('function', $func, __('所属扩展未安装'));
                    $this->printer->note(__('  推荐函数 %{func} 不可用（所属扩展未安装）', ['func' => $func]));
                }
            }
            $this->printer->note('');
        }

        // 5. 尝试安装推荐扩展
        $missingRec = $result->getMissingRecommendedExtensions();
        if (!empty($missingRec)) {
            $this->printer->note(__('【步骤 5】尝试安装推荐扩展（可选优化）...'));
            foreach ($missingRec as $ext) {
                if ($this->statusService->hasAttempted('extension', $ext)) {
                    $prevStatus = $this->statusService->getStatus('extension', $ext);
                    $this->printer->note(__('  推荐扩展 %{ext} 已尝试过（%{status}），跳过', ['ext' => $ext, 'status' => $prevStatus]));
                    $recSkipped++;
                    continue;
                }
                $this->printer->note(__('  正在处理推荐扩展: %{ext}', ['ext' => $ext]));
                $installed = $this->tryInstallExtension($ext);
                if ($installed) {
                    $recSuccess++;
                    $this->statusService->markInstalled('extension', $ext);
                    $this->printer->success(__('    推荐扩展 %{ext} 已启用 ✔', ['ext' => $ext]));
                } else {
                    $recFail++;
                    $this->statusService->markFailed('extension', $ext, __('自动安装失败'));
                    $this->printer->warning(__('    推荐扩展 %{ext} 安装失败（不影响系统运行）', ['ext' => $ext]));
                    $this->printExtensionInstallGuide($ext);
                }
            }
            $this->printer->note('');
        }

        // 6. 尝试安装推荐 items
        $unsatisfiedRec = $result->getUnsatisfiedRecommendedItems();
        if (!empty($unsatisfiedRec)) {
            $this->printer->note(__('【步骤 6】执行推荐依赖安装脚本（可选优化）...'));
            $executor = $this->getScriptExecutor();
            foreach ($unsatisfiedRec as $item) {
                $name = $item['name'] ?? __('未命名');
                $modulePath = $item['module_path'] ?? '';
                if ($this->statusService->hasAttempted('item', $name)) {
                    $prevStatus = $this->statusService->getStatus('item', $name);
                    $this->printer->note(__('  推荐项 %{name} 已尝试过（%{status}），跳过', ['name' => $name, 'status' => $prevStatus]));
                    $recSkipped++;
                    continue;
                }
                if (empty($modulePath)) {
                    $this->statusService->markFailed('item', $name, __('无模块路径'));
                    $recFail++;
                    continue;
                }
                $this->printer->note(__('  正在安装推荐项: %{name}', ['name' => $name]));
                $envDir = $modulePath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR;
                $execResult = $executor->execute($modulePath, $item, $envDir, InstallScriptExecutorInterface::ACTION_INSTALL);
                if ($execResult->isSuccess()) {
                    $recSuccess++;
                    $this->statusService->markInstalled('item', $name, $execResult->getOutput() ?: '');
                    $this->printer->success(__('    安装成功 ✔'));
                } else {
                    $recFail++;
                    $this->statusService->markFailed('item', $name, $execResult->getErrorOutput() ?: __('安装脚本返回失败'));
                    $this->printer->warning(__('    安装失败（不影响系统运行）'));
                }
            }
            $this->printer->note('');
        }

        // 打印总结
        $this->printer->note(__('========== 安装完成 =========='));
        $this->printer->printing(__('必需项 - 成功: %{success} 项', ['success' => $successCount]));
        if ($failCount > 0) {
            $this->printer->warning(__('必需项 - 失败: %{fail} 项（请参考上面的手动修复指引）', ['fail' => $failCount]));
        }
        if ($recSuccess > 0 || $recFail > 0 || $recSkipped > 0) {
            $this->printer->printing(__('推荐项 - 成功: %{success} 项', ['success' => $recSuccess]));
            if ($recFail > 0) {
                $this->printer->warning(__('推荐项 - 未安装: %{fail} 项（可选，不影响运行）', ['fail' => $recFail]));
            }
            if ($recSkipped > 0) {
                $this->printer->note(__('推荐项 - 已跳过: %{skip} 项（之前已尝试，使用 --force 强制重试）', ['skip' => $recSkipped]));
            }
        }
        $this->printer->note('');
        $this->printer->note(__('请再次运行 php bin/w env:check 验证环境'));
        if ($recFail > 0 || $recSkipped > 0) {
            $this->printer->note(__('按需单独安装推荐项: php bin/w env:install <名称> -y，例如 env:install event -y'));
        }
        if ($failCount > 0) {
            exit(1);
        }
    }

    /**
     * 尝试解禁函数
     */
    private function tryUnblockFunctions(array $functions): bool
    {
        $phpIniPath = php_ini_loaded_file();
        if (!$phpIniPath) {
            return false;
        }

        // 检查是否可写（直接或通过 sudo）
        $canWrite = is_writable($phpIniPath);
        $needSudo = !$canWrite && $this->hasSudo && !$this->isRoot;

        if (!$canWrite && !$needSudo) {
            $this->printer->warning(__('  php.ini 不可写且无 sudo 权限: %{path}', ['path' => $phpIniPath]));
            return false;
        }

        $content = file_get_contents($phpIniPath);
        if ($content === false) {
            return false;
        }

        // 查找 disable_functions
        $pattern = '/^(disable_functions\s*=\s*)(.*)$/m';
        if (!preg_match($pattern, $content, $matches)) {
            return false;
        }

        $currentDisabled = array_map('trim', explode(',', $matches[2]));
        $newDisabled = array_diff($currentDisabled, $functions);
        $newLine = 'disable_functions = ' . implode(',', array_filter($newDisabled));

        $newContent = preg_replace($pattern, $newLine, $content);
        if ($newContent === null) {
            return false;
        }

        if ($canWrite) {
            return file_put_contents($phpIniPath, $newContent) !== false;
        }

        // 使用 sudo 写入
        $tempFile = sys_get_temp_dir() . '/php_ini_temp_' . uniqid() . '.ini';
        if (file_put_contents($tempFile, $newContent) === false) {
            return false;
        }

        $cmd = $this->getSudoCommand("cp '$tempFile' '$phpIniPath'");
        $output = [];
        @exec($cmd . ' 2>&1', $output, $code);
        @unlink($tempFile);

        if ($code === 0) {
            $this->printer->note(__('  已通过 sudo 修改 php.ini'));
            return true;
        }

        return false;
    }

    /**
     * 尝试安装/启用 PHP 扩展
     *
     * Windows: 检查 ext 目录是否有对应 DLL，有则修改 php.ini 启用
     * Linux/macOS: 按平台优先尝试常用包管理器（Mac→brew、Ubuntu→apt、CentOS→yum 等），再回退逐个尝试（先检查命令存在）
     */
    private function tryInstallExtension(string $ext): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->tryEnableExtensionWindows($ext);
        } else {
            return $this->tryInstallExtensionLinux($ext);
        }
    }

    /**
     * Windows: 在 php.ini 中启用扩展
     *
     * 1. 检查 ext 目录是否存在 php_{ext}.dll
     * 2. 如果 php.ini 中有被注释的行，取消注释
     * 3. 如果没有对应行，追加 extension={ext}
     */
    private function tryEnableExtensionWindows(string $ext): bool
    {
        // 检查 DLL 是否存在
        $phpDir = dirname(PHP_BINARY);
        $extDir = $phpDir . DIRECTORY_SEPARATOR . ini_get('extension_dir');
        // 如果 extension_dir 是绝对路径则直接使用
        if (is_dir(ini_get('extension_dir'))) {
            $extDir = ini_get('extension_dir');
        }

        $dllName = 'php_' . $ext . '.dll';
        $dllPath = $extDir . DIRECTORY_SEPARATOR . $dllName;

        if (!file_exists($dllPath)) {
            $this->printer->warning(__('    DLL 不存在: %{path}', ['path' => $dllPath]));
            return false;
        }

        $this->printer->note(__('    找到 DLL: %{path}', ['path' => $dllPath]));

        // 修改 php.ini
        $phpIniPath = php_ini_loaded_file();
        if (!$phpIniPath) {
            $this->printer->warning(__('    无法获取 php.ini 路径'));
            return false;
        }
        if (!is_writable($phpIniPath)) {
            $this->printer->warning(__('    php.ini 不可写: %{path}', ['path' => $phpIniPath]));
            return false;
        }

        $content = file_get_contents($phpIniPath);
        if ($content === false) {
            return false;
        }

        $modified = false;

        // 匹配格式: ;extension=php_ext.dll 或 ;extension=ext
        $patterns = [
            '/^;(\s*extension\s*=\s*(?:php_)?' . preg_quote($ext, '/') . '(?:\.dll)?\s*)$/mi',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '$1', $content);
                $modified = true;
                $this->printer->note(__('    取消注释: extension=%{ext}', ['ext' => $ext]));
                break;
            }
        }

        // 如果没有找到被注释的行，追加新行
        if (!$modified) {
            // 检查是否已经启用（不应该到这里，但做防御性检查）
            $enabledPattern = '/^\s*extension\s*=\s*(?:php_)?' . preg_quote($ext, '/') . '(?:\.dll)?\s*$/mi';
            if (preg_match($enabledPattern, $content)) {
                $this->printer->note(__('    扩展已在 php.ini 中启用（可能需要重启 PHP 服务）'));
                return true;
            }

            // 在 [extensions] 段之后或其他 extension= 行之后追加
            $extensionLine = 'extension=' . $ext;
            // 尝试在最后一个 extension= 行后插入
            $lastExtPos = 0;
            if (preg_match_all('/^extension\s*=\s*.+$/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
                $lastMatch = end($matches[0]);
                $lastExtPos = $lastMatch[1] + strlen($lastMatch[0]);
            }

            if ($lastExtPos > 0) {
                $content = substr($content, 0, $lastExtPos) . "\n" . $extensionLine . substr($content, $lastExtPos);
            } else {
                // 没有找到任何 extension 行，追加到文件末尾
                $content .= "\n" . $extensionLine . "\n";
            }
            $modified = true;
            $this->printer->note(__('    添加配置: %{line}', ['line' => $extensionLine]));
        }

        if ($modified) {
            $written = file_put_contents($phpIniPath, $content);
            if ($written === false) {
                $this->printer->error(__('    写入 php.ini 失败'));
                return false;
            }
            $this->printer->success(__('    php.ini 已更新: %{path}', ['path' => $phpIniPath]));
            $this->printer->note(__('    注意: CLI 模式已生效，Web 服务（Apache/Nginx+FPM）需要重启才能生效'));
            return true;
        }

        return false;
    }

    /**
     * Linux/macOS: 按当前系统优先尝试常用包管理器（如 Mac→brew、Ubuntu→apt、CentOS→yum），命令不存在则回退逐个尝试（先检查命令存在）
     */
    private function tryInstallExtensionLinux(string $ext): bool
    {
        $phpBin = (string) (PHP_BINARY ?? '');
        // 项目自编译 PHP（extend/server/php）：apt/dnf 对其无效，扩展应在编译时已加入
        if (str_contains($phpBin, 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'php')) {
            $this->printer->note(__('    当前 PHP 来自项目自编译 (extend/server/php)，apt/dnf 安装无效。若扩展缺失请执行: bash bin/install.sh --rebuild-php php'));
            if ($this->tryEnableExtensionInIniLinux($ext)) {
                return true;
            }
            return false;
        }
        // 宝塔 PHP（/www/server/php/）：apt/dnf 对其无效，需在宝塔面板启用
        if (str_contains($phpBin, '/www/server/php/')) {
            $this->printer->note(__('    当前 PHP 来自宝塔面板，apt/dnf 安装无效。请在宝塔 软件商店 → PHP → 安装扩展 中启用 %{ext}', ['ext' => $ext]));
            if ($this->tryEnableExtensionInIniLinux($ext)) {
                return true;
            }
            return false;
        }

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $map = ObjectManager::getInstance(ExtensionInstallStrategyMap::class);
        $platform = $map->getCurrentPlatform();

        $this->printer->note(__('    当前平台: %{platform}，优先尝试: %{pkg}', [
            'platform' => $platform,
            'pkg'      => $map->getPreferredPackageManagerName($platform),
        ]));

        // 显示权限状态
        if (!$this->isRoot && $this->hasSudo) {
            $this->printer->note(__('    将使用 sudo 执行安装命令'));
        } elseif (!$this->isRoot && !$this->hasSudo) {
            $this->printer->warning(__('    非 root 且无 sudo，安装可能失败'));
        }

        $tryStrategies = function (array $strategies) use ($map, $ext): bool {
            foreach ($strategies as $s) {
                if (!$map->commandExists($s['check'])) {
                    continue;
                }
                $this->printer->note(__('    尝试: %{name}...', ['name' => $s['name']]));
                
                // 为安装命令添加 sudo 前缀（如果需要）
                $cmd = $this->getSudoCommand($s['cmd']);
                
                $output = [];
                $returnCode = 0;
                @exec($cmd . ' 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    $this->printer->success(__('    %{name} 执行成功', ['name' => $s['name']]));
                    // 用当前进程的 PHP 校验扩展是否已加载（避免 PATH 下另一份 php 导致误判）
                    $checkOutput = [];
                    @exec(escapeshellarg(PHP_BINARY) . ' -m 2>&1', $checkOutput, $checkCode);
                    $loadedExts = array_map('strtolower', $checkOutput);
                    if (in_array(strtolower($ext), $loadedExts, true)) {
                        return true;
                    }
                    // 包管理器装的是系统 PHP，当前若是项目自带 PHP 则未生效，尝试 php.ini 启用或返回 false 以触发重编
                    if ($this->tryEnableExtensionInIniLinux($ext)) {
                        return true;
                    }
                    // 命令成功但扩展未加载：输出实际输出帮助诊断（如 No package、Nothing to do、宝塔 PHP 等）
                    $summary = array_slice($output, -8, 8);
                    if ($summary !== []) {
                        $summaryStr = implode(' ', array_map(static fn(string $l) => trim($l), $summary));
                        if (strlen($summaryStr) > 200) {
                            $summaryStr = substr($summaryStr, 0, 200) . '...';
                        }
                        $this->printer->note(__('    命令输出摘要: %{output}', ['output' => $summaryStr]));
                    }
                    $this->printer->note(__('    可能原因: 包名与当前 PHP 版本不匹配(RHEL/Amazon 需 php81-exif 等)、当前使用宝塔/自编译 PHP(apt/dnf 对其无效，需在宝塔面板启用)'));
                } else {
                    // 输出错误信息帮助诊断
                    $errorOutput = implode("\n", array_slice($output, 0, 3));
                    if ($errorOutput) {
                        $this->printer->note(__('    输出: %{output}', ['output' => $errorOutput]));
                    }
                }
            }
            return false;
        };

        $preferred = $map->getPreferredStrategies($platform, $ext, $phpVersion);
        if ($tryStrategies($preferred)) {
            return true;
        }

        $this->printer->note(__('    优先方式未成功，回退尝试其他安装命令（仅尝试已存在的命令）...'));
        $fallback = $map->getFallbackStrategies($platform, $ext, $phpVersion);
        if ($tryStrategies($fallback)) {
            return true;
        }

        if ($this->tryEnableExtensionInIniLinux($ext)) {
            return true;
        }

        return false;
    }

    /**
     * Linux/macOS: 在 php.ini 中启用扩展（.so 文件可能已存在）
     */
    private function tryEnableExtensionInIniLinux(string $ext): bool
    {
        $phpIniPath = php_ini_loaded_file();
        if (!$phpIniPath) {
            return false;
        }

        // 检查是否可写（直接或通过 sudo）
        $canWrite = is_writable($phpIniPath);
        $needSudo = !$canWrite && $this->hasSudo && !$this->isRoot;

        if (!$canWrite && !$needSudo) {
            return false;
        }

        // 检查 .so 文件是否存在
        $extDir = ini_get('extension_dir');
        $soFile = $extDir . DIRECTORY_SEPARATOR . $ext . '.so';
        if (!file_exists($soFile)) {
            return false;
        }

        $content = file_get_contents($phpIniPath);
        if ($content === false) {
            return false;
        }

        $modified = false;

        // 检查是否已有被注释的行
        $pattern = '/^;(\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*)$/mi';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1', $content);
            $this->printer->note(__('    取消注释: extension=%{ext}', ['ext' => $ext]));
            $modified = true;
        } else {
            // 检查是否已启用
            $enabledPattern = '/^\s*extension\s*=\s*' . preg_quote($ext, '/') . '(?:\.so)?\s*$/mi';
            if (preg_match($enabledPattern, $content)) {
                return true;
            }
            // 追加
            $content .= "\nextension=" . $ext . "\n";
            $this->printer->note(__('    添加配置: extension=%{ext}', ['ext' => $ext]));
            $modified = true;
        }

        if (!$modified) {
            return false;
        }

        if ($canWrite) {
            return file_put_contents($phpIniPath, $content) !== false;
        }

        // 使用 sudo 写入
        $tempFile = sys_get_temp_dir() . '/php_ini_temp_' . uniqid() . '.ini';
        if (file_put_contents($tempFile, $content) === false) {
            return false;
        }

        $cmd = $this->getSudoCommand("cp '$tempFile' '$phpIniPath'");
        $output = [];
        @exec($cmd . ' 2>&1', $output, $code);
        @unlink($tempFile);

        if ($code === 0) {
            $this->printer->note(__('    已通过 sudo 修改 php.ini'));
            return true;
        }

        return false;
    }

    /**
     * 尝试执行 bin/install.sh --rebuild-php php（仅 Linux，项目自带 PHP 时用）
     * 成功返回 true，否则 false
     */
    private function tryRebuildProjectPhp(): bool
    {
        $root = \defined('BP') ? rtrim(BP, DIRECTORY_SEPARATOR) : (getcwd() ?: '');
        if ($root === '' || !is_file($root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'install.sh')) {
            return false;
        }
        $installSh = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'install.sh';
        $cmd = 'bash ' . escapeshellarg($installSh) . ' --rebuild-php php 2>&1';
        $oldCwd = getcwd();
        if ($oldCwd !== false && @chdir($root)) {
            try {
                passthru($cmd, $code);
            } finally {
                @chdir($oldCwd);
            }
            return isset($code) && $code === 0;
        }
        return false;
    }

    /**
     * 打印函数解禁失败指引
     */
    private function printFunctionUnblockFailure(array $functions): void
    {
        $phpIniPath = php_ini_loaded_file() ?: __('未知');
        $this->printer->error(__('  函数解禁失败 ✖'));
        $this->printer->warning(__('  【手动修复指引】'));
        $this->printer->printing(__('    去什么地方: %{path}', ['path' => $phpIniPath]));
        $this->printer->printing(__('    做什么操作:'));
        if (PHP_OS_FAMILY === 'Windows') {
            $this->printer->printing(__('      1. 以管理员身份打开命令提示符'));
            $this->printer->printing(__('      2. 用记事本（管理员）打开该文件'));
        } else {
            $this->printer->printing(__('      1. 使用 root 权限或 sudo: sudo nano %{path}', ['path' => $phpIniPath]));
        }
        $this->printer->printing(__('      2. 找到 disable_functions 配置项'));
        $this->printer->printing(__('      3. 从中移除: %{funcs}', ['funcs' => implode(', ', $functions)]));
        $this->printer->printing(__('      4. 保存并重启 PHP/Web 服务'));
        if (!$this->isRoot && PHP_OS_FAMILY !== 'Windows') {
            $this->printer->note(__('    或者直接以 root 运行: sudo php bin/w env:install -y'));
        }
        $this->printer->printing(__('    如何验证: 再次运行 php bin/w env:check'));
    }

    /**
     * 打印扩展安装指引
     */
    private function printExtensionInstallGuide(string $ext): void
    {
        $pkg = ObjectManager::getInstance(ExtensionInstallStrategyMap::class)->getDistroPackageName($ext);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->printer->printing(__('    Windows 安装方式:'));
            $this->printer->printing(__('      1. 从 https://pecl.php.net 下载 php_%{ext}.dll', ['ext' => $ext]));
            $this->printer->printing(__('      2. 放入 PHP 的 ext 目录'));
            $this->printer->printing(__('      3. 在 php.ini 中添加 extension=%{ext}', ['ext' => $ext]));
        } else {
            $this->printer->printing(__('    Linux/macOS 安装方式:'));
            $this->printer->printing(__('      Ubuntu/Debian: sudo apt install php-%{pkg}', ['pkg' => $pkg]));
            $this->printer->printing(__('      CentOS/RHEL: sudo yum install php-%{pkg}', ['pkg' => $pkg]));
            $this->printer->printing(__('      macOS: brew install php && pecl install %{ext}', ['ext' => $ext]));
            $this->printer->printing(__('      通用: pecl install %{ext}', ['ext' => $ext]));
        }
    }

    /**
     * 打印 item 安装失败指引
     */
    private function printItemInstallFailure(array $item): void
    {
        $name = $item['name'] ?? __('未命名');
        $desc = $item['description'] ?? '';
        $module = $item['module'] ?? '';

        $this->printer->warning(__('    【手动修复指引】'));
        $this->printer->printing(__('      依赖: %{name}', ['name' => $name]));
        if ($module) {
            $this->printer->printing(__('      来源模块: %{module}', ['module' => $module]));
        }
        if ($desc) {
            $this->printer->printing(__('      说明: %{desc}', ['desc' => $desc]));
        }
        $this->printer->printing(__('      如何验证: 再次运行 php bin/w env:check'));
    }

    /**
     * 获取当前系统对应的脚本执行器
     */
    private function getScriptExecutor(): InstallScriptExecutorInterface
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ObjectManager::getInstance(WindowsScriptExecutor::class);
        } else {
            return ObjectManager::getInstance(LinuxScriptExecutor::class);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('检测并尝试自动修复环境依赖问题');
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'env:install [名称]',
            __('仅在必需依赖未满足时进入安装；安装时同时尝试必需项与可选推荐项。可指定名称仅安装某推荐项。'),
            [
                '[名称]' => __('可选。指定推荐项名称时仅安装该项，如 event'),
                '-y, --yes' => __('跳过确认，直接执行'),
                '-F, --force' => __('强制重试推荐项（清除之前的安装记录）'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('必需未满足时安装（含推荐项）') => 'php bin/w env:install -y',
                __('仅安装指定推荐项') => 'php bin/w env:install event -y',
            ]
        );
    }
}
