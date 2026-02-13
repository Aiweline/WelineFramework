<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Service;

use Weline\Framework\Env\Api\EnvCheckerInterface;
use Weline\Framework\Env\Api\InstallScriptExecutorInterface;
use Weline\Framework\Env\Api\Data\EnvCheckResult;
use Weline\Framework\Env\Api\Data\EnvRequirements;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 环境检查器实现
 * 
 * @DESC 实现环境检测逻辑，支持 CLI 与 Web 共用
 */
class EnvChecker implements EnvCheckerInterface
{
    private ?EnvRequirements $requirements = null;
    private ?Printing $printer = null;
    private bool $isCli;

    public function __construct()
    {
        $this->isCli = (PHP_SAPI === 'cli');
        if ($this->isCli) {
            $this->printer = new Printing();
        }
    }

    /**
     * @inheritDoc
     */
    public function setRequirements(EnvRequirements $requirements): EnvCheckerInterface
    {
        $this->requirements = $requirements;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function check(): EnvCheckResult
    {
        $result = new EnvCheckResult();
        $result->setMessage(__('环境初始化检测中...'));

        if ($this->requirements === null) {
            $result->setHasError(true);
            $result->setMessage(__('未设置环境需求'));
            return $result;
        }

        // 1. 检测 PHP 版本
        $this->checkPhpVersion($result);

        // 2. 检测扩展
        $this->checkExtensions($result);

        // 3. 检测函数
        $this->checkFunctions($result);

        // 4. 检测复杂依赖项（items）
        $this->checkItems($result);

        // 5. 检测推荐扩展
        $this->checkRecommendedExtensions($result);

        // 6. 检测推荐函数
        $this->checkRecommendedFunctions($result);

        // 7. 检测推荐复杂依赖项
        $this->checkRecommendedItems($result);

        return $result;
    }

    /**
     * 检测 PHP 版本
     */
    private function checkPhpVersion(EnvCheckResult $result): void
    {
        $requiredVersion = $this->requirements->getPhpVersion();
        if ($requiredVersion === null) {
            return;
        }

        $currentVersion = PHP_VERSION;
        $satisfied = $this->checkVersionConstraint($currentVersion, $requiredVersion);

        $key = 'php_version';
        if ($satisfied) {
            $result->addDetail($key, '✔ PHP ' . $currentVersion, true);
            $this->printSuccess(__('PHP 版本: %{version} ✔', ['version' => $currentVersion]));
        } else {
            $result->setPhpVersionIssue(__('需要 PHP %{required}，当前 %{current}', [
                'required' => $requiredVersion,
                'current' => $currentVersion,
            ]));
            $result->addDetail($key, '✖ PHP ' . $currentVersion, false);
            $this->printError(__('PHP 版本: %{version} ✖（需要 %{required}）', [
                'version' => $currentVersion,
                'required' => $requiredVersion,
            ]));
        }
    }

    /**
     * 检测扩展
     */
    private function checkExtensions(EnvCheckResult $result): void
    {
        $loadedExtensions = get_loaded_extensions();
        // 将已加载扩展转为小写进行比较（扩展名不区分大小写）
        $loadedExtensionsLower = array_map('strtolower', $loadedExtensions);
        
        foreach ($this->requirements->getExtensions() as $extension) {
            $key = 'extension_' . $extension;
            // 不区分大小写比较
            if (in_array(strtolower($extension), $loadedExtensionsLower, true)) {
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('扩展 %{ext} ✔', ['ext' => $extension]));
            } else {
                $result->addMissingExtension($extension);
                $result->addDetail($key, '✖', false);
                $this->printError(__('扩展 %{ext} ✖（未安装）', ['ext' => $extension]));
            }
        }
    }

    /**
     * 检测函数
     */
    private function checkFunctions(EnvCheckResult $result): void
    {
        $disableFunctions = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', $disableFunctions));

        foreach ($this->requirements->getFunctions() as $function) {
            $key = 'function_' . $function;
            if (in_array($function, $disabledList, true)) {
                $result->addDisabledFunction($function);
                $result->addDetail($key, '✖', false);
                $this->printError(__('函数 %{func} ✖（已被禁用）', ['func' => $function]));
            } else {
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('函数 %{func} ✔', ['func' => $function]));
            }
        }
    }

    /**
     * 检测复杂依赖项（通过调用脚本 --check）
     */
    private function checkItems(EnvCheckResult $result): void
    {
        foreach ($this->requirements->getItems() as $item) {
            $itemName = $item['name'] ?? __('未命名依赖');
            $modulePath = $item['module_path'] ?? '';

            if (empty($modulePath)) {
                // 没有模块路径，无法调用脚本检查，标记为未满足
                $result->addUnsatisfiedItem($item);
                $result->addDetail('item_' . $itemName, '✖', false);
                $this->printError(__('依赖 %{name} ✖（无法检测）', ['name' => $itemName]));
                continue;
            }

            $satisfied = $this->checkItem($item, $modulePath);
            $key = 'item_' . $itemName;

            if ($satisfied) {
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('依赖 %{name} ✔', ['name' => $itemName]));
            } else {
                $result->addUnsatisfiedItem($item);
                $result->addDetail($key, '✖', false);
                $this->printError(__('依赖 %{name} ✖（未满足）', ['name' => $itemName]));
            }
        }
    }

    /**
     * 检测推荐扩展（缺失不影响 hasError，区分平台条件）
     *
     * 每项格式: ['name'=>string, 'platform'=>string, 'reason'=>string]
     * 当前平台不匹配的直接跳过（不报告、不提示）
     */
    private function checkRecommendedExtensions(EnvCheckResult $result): void
    {
        $recommended = $this->requirements->getRecommendedExtensions();
        if (empty($recommended)) {
            return;
        }

        $loadedExtensions = get_loaded_extensions();
        $loadedExtensionsLower = array_map('strtolower', $loadedExtensions);

        foreach ($recommended as $item) {
            $extName  = $item['name'] ?? '';
            $platform = $item['platform'] ?? 'all';
            $reason   = $item['reason'] ?? '';

            if (empty($extName)) {
                continue;
            }

            // 平台不匹配：静默跳过
            if (!EnvRequirements::matchesPlatform($platform)) {
                continue;
            }

            $key = 'recommended_extension_' . $extName;
            $label = $reason !== '' ? $extName . '（' . $reason . '）' : $extName;

            if (\in_array(\strtolower($extName), $loadedExtensionsLower, true)) {
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('推荐扩展 %{ext} ✔', ['ext' => $label]));
            } else {
                $result->addMissingRecommendedExtension($extName);
                $result->addDetail($key, '◐', false);
                $this->printWarning(__('推荐扩展 %{ext} ◐（未安装，建议启用以获得最佳性能）', ['ext' => $label]));
            }
        }
    }

    /**
     * 检测推荐函数（缺失不影响 hasError）
     *
     * 兼容两种不可用场景：
     *  1. 函数被 disable_functions 禁用 → 提示解禁
     *  2. 函数不存在（所属扩展未安装，如 Windows 下的 pcntl_fork）→ 提示安装扩展
     */
    private function checkRecommendedFunctions(EnvCheckResult $result): void
    {
        $recommended = $this->requirements->getRecommendedFunctions();
        if (empty($recommended)) {
            return;
        }

        $disableFunctions = ini_get('disable_functions');
        $disabledList = array_map('trim', explode(',', $disableFunctions));

        foreach ($recommended as $function) {
            $key = 'recommended_function_' . $function;

            if (\function_exists($function)) {
                // 函数存在且未被禁用
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('推荐函数 %{func} ✔', ['func' => $function]));
            } elseif (\in_array($function, $disabledList, true)) {
                // 函数被 disable_functions 禁用
                $result->addDisabledRecommendedFunction($function);
                $result->addDetail($key, '◐', false);
                $this->printWarning(__('推荐函数 %{func} ◐（已被禁用，建议解禁）', ['func' => $function]));
            } else {
                // 函数不存在（所属扩展未安装，如 Windows 下无 pcntl）
                $result->addDisabledRecommendedFunction($function);
                $result->addDetail($key, '◐', false);
                $this->printWarning(__('推荐函数 %{func} ◐（不可用，所属扩展未安装）', ['func' => $function]));
            }
        }
    }

    /**
     * 检测推荐复杂依赖项
     */
    private function checkRecommendedItems(EnvCheckResult $result): void
    {
        foreach ($this->requirements->getRecommendedItems() as $item) {
            $itemName = $item['name'] ?? __('未命名依赖');
            $modulePath = $item['module_path'] ?? '';

            if (empty($modulePath)) {
                $result->addUnsatisfiedRecommendedItem($item);
                $result->addDetail('recommended_item_' . $itemName, '◐', false);
                $this->printWarning(__('推荐依赖 %{name} ◐（无法检测）', ['name' => $itemName]));
                continue;
            }

            $satisfied = $this->checkItem($item, $modulePath);
            $key = 'recommended_item_' . $itemName;

            if ($satisfied) {
                $result->addDetail($key, '✔', true);
                $this->printSuccess(__('推荐依赖 %{name} ✔', ['name' => $itemName]));
            } else {
                $result->addUnsatisfiedRecommendedItem($item);
                $result->addDetail($key, '◐', false);
                $this->printWarning(__('推荐依赖 %{name} ◐（未满足，建议安装以获得最佳性能）', ['name' => $itemName]));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function checkItem(array $item, string $modulePath): bool
    {
        $envDir = $modulePath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR;

        // 获取脚本执行器
        $executor = $this->getScriptExecutor();
        if ($executor === null) {
            return false;
        }

        // 执行 --check
        $result = $executor->execute($modulePath, $item, $envDir, InstallScriptExecutorInterface::ACTION_CHECK);

        // 退出码 0 表示已满足
        return $result->isSuccess();
    }

    /**
     * 获取当前系统对应的脚本执行器（Windows → Windows；Linux/macOS → Linux）
     */
    private function getScriptExecutor(): ?InstallScriptExecutorInterface
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ObjectManager::getInstance(WindowsScriptExecutor::class);
        }
        return ObjectManager::getInstance(LinuxScriptExecutor::class);
    }

    /**
     * 检查版本约束（简化实现，支持 ^、>=、> 等）
     */
    private function checkVersionConstraint(string $current, string $constraint): bool
    {
        // 移除空格
        $constraint = trim($constraint);

        // 处理 ^ 约束（如 ^8.1 表示 >=8.1.0 <9.0.0）
        if (str_starts_with($constraint, '^')) {
            $version = substr($constraint, 1);
            $parts = explode('.', $version);
            $major = (int)($parts[0] ?? 0);
            $minor = (int)($parts[1] ?? 0);

            return version_compare($current, $major . '.' . $minor . '.0', '>=')
                && version_compare($current, ($major + 1) . '.0.0', '<');
        }

        // 处理 >= 约束
        if (str_starts_with($constraint, '>=')) {
            return version_compare($current, trim(substr($constraint, 2)), '>=');
        }

        // 处理 > 约束
        if (str_starts_with($constraint, '>')) {
            return version_compare($current, trim(substr($constraint, 1)), '>');
        }

        // 处理 <= 约束
        if (str_starts_with($constraint, '<=')) {
            return version_compare($current, trim(substr($constraint, 2)), '<=');
        }

        // 处理 < 约束
        if (str_starts_with($constraint, '<')) {
            return version_compare($current, trim(substr($constraint, 1)), '<');
        }

        // 精确匹配
        return version_compare($current, $constraint, '>=');
    }

    /**
     * 打印成功信息（CLI 模式）
     */
    private function printSuccess(string $message): void
    {
        if ($this->isCli && $this->printer !== null) {
            $this->printer->success($message);
        }
    }

    /**
     * 打印错误信息（CLI 模式）
     */
    private function printError(string $message): void
    {
        if ($this->isCli && $this->printer !== null) {
            $this->printer->error($message);
        }
    }

    /**
     * 打印警告信息（CLI 模式，用于推荐项）
     */
    private function printWarning(string $message): void
    {
        if ($this->isCli && $this->printer !== null) {
            $this->printer->warning($message);
        }
    }
}
