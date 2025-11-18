<?php

declare(strict_types=1);

/**
 * Deploy Build Command
 * 
 * 从 Git 仓库拉取代码并更新部署项目
 *
 * @package Weline\Deploy\Console\Deploy
 * @author WelineFramework Team
 */

namespace Weline\Deploy\Console\Deploy;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\Console\Server\TablePrinter;
use Weline\Framework\Output\Cli\Printing;

class Build extends CommandAbstract
{
    use TablePrinter;

    private System $system;
    private string $envFilePath;
    private bool $isWindows;

    public function __construct(
        Printing $printer,
        System $system
    ) {
        $this->printer = $printer;
        $this->system = $system;
        $this->envFilePath = BP . '.env';
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * @DESC         |命令描述
     *
     * 参数区：
     *
     * @return string
     */
    public function tip(): string
    {
        return __('从 Git 仓库拉取代码并更新部署项目');
    }

    /**
     * @DESC         |命令帮助信息
     *
     * 参数区：
     *
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:build',
            __('从 Git 仓库拉取代码并更新部署项目'),
            [
                '-b, --branch=<分支名>' => '指定 Git 分支（默认从 .env 读取）',
                '-f, --force' => '强制拉取（会丢弃本地修改）',
                '--no-backup' => '禁用部署前备份',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '配置文件' => '需要在项目根目录创建 .env 文件并配置 Git 相关信息',
                '安全提示' => '建议在 .env 文件中配置 GIT_TOKEN 而不是使用密码',
                '备份机制' => '默认会备份当前项目到 var/backup/deploy/ 目录',
            ],
            [
                '执行部署' => 'php bin/w deploy:build',
                '指定分支' => 'php bin/w deploy:build -b develop',
                '强制更新' => 'php bin/w deploy:build --force',
            ],
            'php bin/w deploy:build [选项]'
        );
    }

    /**
     * @DESC         |执行命令
     *
     * @param array $args
     * @param array $data
     *
     * @return mixed|void
     * @throws \Exception
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('Git 部署开始...'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 1. 读取 .env 配置
        $this->printer->setup(__('步骤 1/5：读取配置文件...'));
        $config = $this->loadEnvConfig();
        if (empty($config)) {
            $this->printer->error(__('错误：无法读取 .env 配置文件或配置为空！'));
            $this->printer->note(__('请创建 .env 文件，或从 .env.sample 复制模板并修改配置。'));
            exit(1);
        }
        $this->printer->success(__('✓ 配置文件读取成功'));

        // 2. 验证必需配置
        $this->printer->setup(__('步骤 2/5：验证配置...'));
        $this->validateConfig($config);
        $this->printer->success(__('✓ 配置验证通过'));

        // 3. 备份当前项目（可选）
        $force = isset($args['force']) || isset($args['f']);
        $noBackup = isset($args['no-backup']);
        
        if (!$noBackup && ($config['BACKUP_BEFORE_DEPLOY'] ?? true)) {
            $this->printer->setup(__('步骤 3/5：备份当前项目...'));
            $this->backupProject($config);
            $this->printer->success(__('✓ 项目备份完成'));
        } else {
            $this->printer->warning(__('⚠ 跳过备份步骤'));
        }

        // 4. 执行 Git 操作
        $branch = $args['branch'] ?? $args['b'] ?? $config['GIT_BRANCH'] ?? 'main';
        $this->printer->setup(__('步骤 4/5：执行 Git 拉取更新...'));
        $this->executeGitOperations($config, $branch, $force);
        $this->printer->success(__('✓ Git 更新完成'));

        // 5. 清理和维护
        $this->printer->setup(__('步骤 5/5：执行部署后清理...'));
        $this->cleanupAfterDeploy($config);
        $this->printer->success(__('✓ 清理完成'));

        // 完成
        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✓✓✓ 部署完成！✓✓✓'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');
    }

    /**
     * 加载 .env 配置文件
     *
     * @return array
     */
    private function loadEnvConfig(): array
    {
        if (!is_file($this->envFilePath)) {
            $this->printer->warning(__('.env 文件不存在：%{1}', [$this->envFilePath]));
            return [];
        }

        $config = [];
        $lines = file($this->envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // 跳过注释
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // 解析 KEY=VALUE 格式
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // 移除引号
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * 验证必需配置项
     *
     * @param array $config
     * @throws \Exception
     */
    private function validateConfig(array $config): void
    {
        $required = ['GIT_REPO_URL'];
        $missing = [];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $this->printer->error(__('缺少必需配置项：%{1}', [implode(', ', $missing)]));
            exit(1);
        }

        // 显示使用的配置（隐藏敏感信息）
        $this->printer->note(__('仓库地址：%{1}', [$config['GIT_REPO_URL']]));
        $this->printer->note(__('分支：%{1}', [$config['GIT_BRANCH'] ?? 'main']));
        if (isset($config['GIT_USERNAME'])) {
            $this->printer->note(__('用户名：%{1}', [$config['GIT_USERNAME']]));
        }
    }

    /**
     * 备份当前项目
     *
     * @param array $config
     */
    private function backupProject(array $config): void
    {
        $backupDir = Env::backup_dir . 'deploy' . DS;
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupName = "backup_{$timestamp}";
        $backupPath = $backupDir . $backupName;

        $this->printer->note(__('备份目录：%{1}', [$backupPath]));
        $this->printer->note(__('当前平台：%{1}', [PHP_OS]));

        // Windows 平台：使用 PowerShell Compress-Archive
        if ($this->isWindows) {
            $success = $this->backupWithPowerShell($backupPath);
        } else {
            // Linux/Mac 平台：优先使用 tar
            $success = $this->backupWithUnixTools($backupPath);
        }

        if (!$success) {
            $this->printer->warning(__('无法创建压缩备份，将仅创建记录文件'));
            file_put_contents($backupPath . '.txt', json_encode([
                'timestamp' => $timestamp,
                'backup_type' => 'info_only',
                'message' => '自动备份记录（因系统缺少压缩工具）',
                'platform' => PHP_OS
            ], JSON_PRETTY_PRINT));
        }
    }

    /**
     * 执行 Git 操作
     *
     * @param array $config
     * @param string $branch
     * @param bool $force
     */
    private function executeGitOperations(array $config, string $branch, bool $force): void
    {
        // 检查 Git 是否已安装
        if (!$this->commandExists('git')) {
            $this->printer->error(__('错误：系统未检测到 Git！'));
            $this->printer->note('');
            $this->printer->warning(__('请先安装 Git 后才能使用此功能。'));
            $this->printer->note('');
            $this->printer->setup(__('安装方法：'));
            
            if ($this->isWindows) {
                $this->printer->note(__('Windows 系统：'));
                $this->printer->note(__('  1. 访问 https://git-scm.com/download/win'));
                $this->printer->note(__('  2. 下载并安装 Git for Windows'));
                $this->printer->note(__('  3. 安装完成后重启终端'));
            } else {
                $this->printer->note(__('Linux 系统：'));
                $this->printer->note(__('  Ubuntu/Debian: sudo apt-get install git'));
                $this->printer->note(__('  CentOS/RHEL: sudo yum install git'));
                $this->printer->note(__('  Fedora: sudo dnf install git'));
                $this->printer->note('');
                $this->printer->note(__('macOS 系统：'));
                $this->printer->note(__('  brew install git'));
                $this->printer->note(__('  或者访问 https://git-scm.com/download/mac'));
            }
            $this->printer->note('');
            exit(1);
        }

        $repoUrl = $config['GIT_REPO_URL'];
        $username = $config['GIT_USERNAME'] ?? '';
        $password = $config['GIT_TOKEN'] ?? $config['GIT_PASSWORD'] ?? '';

        // 检查是否已经是 Git 仓库
        $isGitRepo = is_dir(BP . '.git');

        if (!$isGitRepo) {
            $this->printer->note(__('初始化 Git 仓库...'));
            // 如果是 HTTPS URL 且提供了凭据，需要在 URL 中嵌入凭据
            if ($username && $password && str_starts_with($repoUrl, 'http')) {
                // 解析 URL 并插入凭据
                $urlParts = parse_url($repoUrl);
                $repoUrl = sprintf(
                    '%s://%s:%s@%s%s',
                    $urlParts['scheme'],
                    urlencode($username),
                    urlencode($password),
                    $urlParts['host'],
                    $urlParts['path'] ?? ''
                );
            }
            $this->system->exec('cd ' . escapeshellarg(BP) . ' && git init');
            $this->system->exec('cd ' . escapeshellarg(BP) . ' && git remote add origin ' . escapeshellarg($repoUrl));
        }

        // 获取当前分支信息
        if ($isGitRepo) {
            try {
                $currentBranch = trim(shell_exec('cd ' . escapeshellarg(BP) . ' && git rev-parse --abbrev-ref HEAD'));
                $this->printer->note(__('当前分支：%{1}', [$currentBranch]));
            } catch (\Exception $e) {
                $this->printer->warning(__('无法获取当前分支信息'));
            }
        }

        // 拉取更新
        $this->printer->note(__('从 %{1} 分支拉取更新...', [$branch]));

        if ($force) {
            $this->printer->warning(__('⚠ 强制模式：将丢弃本地修改'));
            $this->system->exec('cd ' . escapeshellarg(BP) . ' && git fetch origin');
            $this->system->exec('cd ' . escapeshellarg(BP) . ' && git reset --hard origin/' . escapeshellarg($branch));
            $this->system->exec('cd ' . escapeshellarg(BP) . ' && git clean -fd');
        } else {
            // 正常拉取
            try {
                $result = $this->system->exec('cd ' . escapeshellarg(BP) . ' && git pull origin ' . escapeshellarg($branch));
                if (!empty($result)) {
                    $this->printer->printList($result);
                }
            } catch (\Exception $e) {
                $this->printer->error(__('Git 拉取失败：%{1}', [$e->getMessage()]));
                $this->printer->note(__('提示：如果有本地修改冲突，可以使用 --force 参数强制更新'));
                exit(1);
            }
        }

        $this->printer->success(__('✓ Git 操作完成'));
    }

    /**
     * 部署后清理
     *
     * @param array $config
     */
    private function cleanupAfterDeploy(array $config): void
    {
        // 清理缓存
        $cacheDirs = ['var/cache/*'];
        foreach ($cacheDirs as $pattern) {
            $files = glob(BP . $pattern);
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $this->system->exec('rm -rf ' . escapeshellarg($file));
                }
            }
        }

        $this->printer->note(__('已清理缓存目录'));
    }

    /**
     * 使用 PowerShell 创建备份（Windows）
     */
    private function backupWithPowerShell(string $backupPath): bool
    {
        $this->printer->note(__('使用 PowerShell 创建备份...'));
        
        // 构建 PowerShell 命令
        $bpPath = str_replace('\\', '/', BP);
        $backupZip = $backupPath . '.zip';
        $backupZipPath = str_replace('\\', '/', $backupZip);
        
        $psScript = "Get-ChildItem -Path '$bpPath' -Recurse | " .
            "Where-Object { \$_.FullName -notlike '*\\var\\cache*' -and " .
            "\$_.FullName -notlike '*\\var\\session*' -and " .
            "\$_.FullName -notlike '*\\var\\log*' -and " .
            "\$_.FullName -notlike '*\\.git*' -and " .
            "\$_.FullName -notlike '*\\vendor*' -and " .
            "\$_.FullName -notlike '*\\node_modules*' } | " .
            "Compress-Archive -DestinationPath '$backupZipPath' -Force";
        
        $command = 'powershell -Command "' . str_replace('"', '`"', $psScript) . '"';
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            $this->printer->success(__('✓ PowerShell 备份创建成功'));
            return true;
        } else {
            $this->printer->warning(__('PowerShell 备份失败'));
            return false;
        }
    }

    /**
     * 使用 Unix 工具创建备份（Linux/Mac）
     */
    private function backupWithUnixTools(string $backupPath): bool
    {
        $this->printer->note(__('检测可用的备份工具...'));
        
        // 尝试使用 tar
        if ($this->commandExists('tar')) {
            $this->printer->note(__('使用 tar 创建备份...'));
            return $this->backupWithTar($backupPath);
        }
        
        // 尝试使用 zip
        if ($this->commandExists('zip')) {
            $this->printer->note(__('使用 zip 创建备份...'));
            return $this->backupWithZip($backupPath);
        }
        
        return false;
    }

    /**
     * 使用 tar 创建备份
     */
    private function backupWithTar(string $backupPath): bool
    {
        $command = "cd " . escapeshellarg(BP) . " && tar --exclude='var/cache/*' --exclude='var/session/*' --exclude='var/log/*' --exclude='.git' --exclude='vendor' --exclude='node_modules' -czf " . escapeshellarg($backupPath . '.tar.gz') . " .";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->printer->success(__('✓ tar 备份创建成功'));
            return true;
        } else {
            $this->printer->warning(__('tar 备份失败'));
            return false;
        }
    }

    /**
     * 使用 zip 创建备份
     */
    private function backupWithZip(string $backupPath): bool
    {
        $command = "cd " . escapeshellarg(BP) . " && zip -r " . escapeshellarg($backupPath . '.zip') . " . -x 'var/cache/*' 'var/session/*' 'var/log/*' '.git/*' 'vendor/*' 'node_modules/*'";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            $this->printer->success(__('✓ zip 备份创建成功'));
            return true;
        } else {
            $this->printer->warning(__('zip 备份失败'));
            return false;
        }
    }

    /**
     * 检查命令是否存在（跨平台）
     */
    private function commandExists(string $command): bool
    {
        if ($this->isWindows) {
            // Windows: 使用 where 命令
            exec("where {$command} 2>nul", $output, $returnCode);
        } else {
            // Unix: 使用 which 命令
            exec("which {$command} 2>/dev/null", $output, $returnCode);
        }
        return $returnCode === 0 && !empty($output);
    }
}

