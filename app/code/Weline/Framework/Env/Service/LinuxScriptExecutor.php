<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Service;

use Weline\Framework\Env\Api\InstallScriptExecutorInterface;
use Weline\Framework\Env\Api\Data\ExecutionResult;

/**
 * Linux/macOS 脚本执行器
 * 
 * @DESC 在 Linux/macOS 系统上执行安装脚本
 */
class LinuxScriptExecutor implements InstallScriptExecutorInterface
{
    /** @var array 支持的脚本扩展名 */
    private const SUPPORTED_EXTENSIONS = ['sh', 'php'];

    /**
     * @inheritDoc
     */
    public function execute(string $modulePath, array $item, string $envDir, string $action): ExecutionResult
    {
        $scriptPath = $this->resolveScriptPath($item, $envDir);
        
        if ($scriptPath === null) {
            // 没有脚本，尝试执行 env/script/ 下的所有脚本
            $results = $this->executeAllScripts($modulePath, $envDir, $action);
            if (empty($results)) {
                // 没有可执行的脚本
                return ExecutionResult::failure(-1, __('未找到可执行的安装脚本'), '', $action);
            }
            // 返回最后一个结果
            return end($results);
        }

        return $this->executeScript($scriptPath, $action, $item['name'] ?? '', $modulePath);
    }

    /**
     * @inheritDoc
     */
    public function executeAllScripts(string $modulePath, string $envDir, string $action): array
    {
        $results = [];
        $scriptDir = $envDir . 'script' . DIRECTORY_SEPARATOR;

        if (!is_dir($scriptDir)) {
            return $results;
        }

        // 获取所有脚本文件并按文件名排序
        $files = scandir($scriptDir);
        if ($files === false) {
            return $results;
        }

        sort($files);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $scriptPath = $scriptDir . $file;
            if (!is_file($scriptPath)) {
                continue;
            }

            $result = $this->executeScript($scriptPath, $action, $file, $modulePath);
            $results[] = $result;

            // 如果是 install 动作且失败，停止执行后续脚本
            if ($action === self::ACTION_INSTALL && !$result->isSuccess()) {
                break;
            }
        }

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function getSupportedOs(): string
    {
        return 'Linux';
    }

    /**
     * @inheritDoc
     */
    public function isSupported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    /**
     * 解析脚本路径
     */
    private function resolveScriptPath(array $item, string $envDir): ?string
    {
        // 优先使用 script_linux
        if (isset($item['script_linux']) && !empty($item['script_linux'])) {
            $path = $envDir . $item['script_linux'];
            if (is_file($path) && $this->isPathSafe($path, $envDir)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 执行单个脚本
     */
    private function executeScript(string $scriptPath, string $action, string $itemName, string $modulePath): ExecutionResult
    {
        $startTime = microtime(true);
        $extension = pathinfo($scriptPath, PATHINFO_EXTENSION);

        // 根据扩展名构建命令
        $command = $this->buildCommand($scriptPath, $extension, $action);

        // 设置工作目录为 env 目录
        $envDir = dirname($scriptPath);

        // 执行命令
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $envDir);

        if (!is_resource($process)) {
            return ExecutionResult::failure(-1, __('无法启动进程'), $command, $action)
                ->setItemName($itemName);
        }

        // 关闭 stdin
        fclose($pipes[0]);

        // 读取输出
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $duration = microtime(true) - $startTime;

        $result = new ExecutionResult(
            $exitCode === 0,
            $exitCode,
            $stdout ?: '',
            $stderr ?: '',
            $command,
            $action
        );

        $result->setItemName($itemName);
        $result->setDuration($duration);

        return $result;
    }

    /**
     * 构建执行命令
     */
    private function buildCommand(string $scriptPath, string $extension, string $action): string
    {
        switch ($extension) {
            case 'php':
                return 'php ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($action);
            case 'sh':
                return 'bash ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($action);
            default:
                return escapeshellarg($scriptPath) . ' ' . escapeshellarg($action);
        }
    }

    /**
     * 检查路径是否安全（防止 ../ 逃逸）
     */
    private function isPathSafe(string $path, string $baseDir): bool
    {
        $realPath = realpath($path);
        $realBaseDir = realpath($baseDir);

        if ($realPath === false || $realBaseDir === false) {
            return false;
        }

        return str_starts_with($realPath, $realBaseDir);
    }
}
