<?php

declare(strict_types=1);

/**
 * Deploy Core Update Command
 * 
 * 从 Git 仓库更新框架核心代码（使用临时目录，不影响项目 Git 仓库）
 *
 * @package Weline\Deploy\Console\Update
 * @author WelineFramework Team
 */

namespace Weline\Deploy\Console\Update;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Core extends CommandAbstract
{
    /** 命令别名：支持旧用法 core:update */
    public const ALIASES = ['core:update'];

    /** 默认主仓库（GitHub），未配置且可达时使用 */
    private const DEFAULT_REPO_GITHUB = 'https://github.com/Aiweline/WelineFramework.git';

    /** 默认备用仓库（Gitee），GitHub 不可达时使用 */
    private const DEFAULT_REPO_GITEE = 'https://gitee.com/aiweline/WelineFramework.git';

    private const CORE_UPDATE_EXCLUDED_PATHS = [
        'app/code/Aiweline',
        'app/code/WeShop',
    ];

    private System $system;
    private bool $isWindows;
    private string $envFilePath;

    private bool $updateAll = false;  // 是否更新整个项目
    private bool $forceUpdate = false;  // 是否强制更新（删除本地重新拉取）
    private int $updatedFiles = 0;  // 更新的文件数
    private int $skippedFiles = 0;  // 跳过的文件数（受保护的配置文件）
    private int $newFiles = 0;  // 新增的文件数
    private int $deletedFiles = 0;  // 删除的文件数
    
    /**
     * Git 变化的文件列表（增量模式使用）
     * @var array<string, string> [相对路径 => 状态(A/M/D)]
     */
    private array $changedFiles = [];
    
    /**
     * 是否是新克隆的仓库（第一次运行）
     */
    private bool $isNewClone = false;

    /**
     * 按优先级排列的仓库地址（主仓库在前，备用在后）
     *
     * @var string[]
     */
    private array $repoCandidates = [];

    public function __construct(
        Printing $printer,
        System $system
    ) {
        $this->printer = $printer;
        $this->system = $system;
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $this->envFilePath = BP . '.env';
    }

    public function tip(): string
    {
        return __('从 Git 仓库增量更新框架核心代码（只更新变化的文件）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'update:core',
            __('从 Git 仓库增量更新框架核心代码'),
            [
                '-b, --branch=<分支名>' => __('指定分支（未配置默认分支时必填，如：main, master, dev）'),
                '-t, --tag=<标签名>' => '指定标签版本（可选，如：v1.0.0）',
                '--repo=<仓库地址>' => __('指定 Git 仓库地址（覆盖配置文件，默认：公用官网或配置的仓库）'),
                '-f, --force' => '强制更新：重新克隆仓库，完全覆盖核心文件',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '双仓库策略' => __('未指定仓库时优先 GitHub；先 ping github.com，不可达时自动切换 Gitee 镜像'),
                '仓库可配置' => __('仓库地址、默认分支、密钥可在项目根目录 .env 或 app/etc/env.php 的 core_update 中配置；显式配置后不再自动切换'),
                '增量更新' => '默认使用 git fetch 增量拉取，通过 git diff 获取变化文件列表，只拷贝变化的文件',
                '强制更新' => '使用 -f 参数强制删除缓存并重新克隆，完全覆盖目标目录',
                '临时目录方式' => '使用临时目录下载，不影响项目 Git 仓库',
                '版本验证' => '如果指定了标签但不存在，命令会报错并退出',
                '排除目录' => __('核心更新不会拷贝 app/code/Aiweline 和 app/code/WeShop；这些项目级模块由目标项目自行管理'),
            ],
            [
                '增量更新到最新' => 'php bin/w update:core -b main  （或 core:update -b main）',
                '强制完整更新' => 'php bin/w update:core -b main -f',
                '指定标签' => 'php bin/w update:core -b main -t v1.0.0',
                __('使用自定义仓库（需先配置 .env 或 env.php）') => 'php bin/w update:core -b master',
            ],
            'php bin/w update:core -b <分支名> 或 php bin/w core:update -b <分支名>'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        // 检查是否强制更新
        $this->forceUpdate = isset($args['f']) || isset($args['force']);
        
        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        if ($this->forceUpdate) {
            $this->printer->setup(__('框架核心代码更新（强制模式 - 完整重新下载并覆盖）'));
        } else {
            $this->printer->setup(__('框架核心代码更新（增量模式 - 基于 Git diff 更新变化文件）'));
        }
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        // 1. 检查 Git
        $this->printer->setup(__('步骤 1/6：检查 Git...'));
        $this->checkGit();

        // 2. 验证参数
        $this->printer->setup(__('步骤 2/6：验证参数...'));
        $config = $this->getCoreUpdateConfig();
        $branch = $this->getBranch($args, $config);
        $tag = $args['tag'] ?? $args['t'] ?? null;
        $this->repoCandidates = $this->resolveRepoCandidates($args, $config);
        $repo = $this->repoCandidates[0];

        $this->printer->note(__('仓库：%{1}', [$this->maskRepoUrl($repo)]));
        if (count($this->repoCandidates) > 1) {
            $this->printer->note(__('备用仓库：%{1}', [$this->maskRepoUrl($this->repoCandidates[1])]));
        }
        $this->printer->note(__('分支：%{1}', [$branch]));
        if ($tag) {
            $this->printer->note(__('标签：%{1}', [$tag]));
        }
        $this->printer->note(__('更新模式：%{1}', [$this->forceUpdate ? '强制完整更新（覆盖所有文件）' : '增量更新（只更新 Git 变化的文件）']));
        $this->printer->note('');

        // 3. 创建/准备临时目录
        $this->printer->setup(__('步骤 3/6：准备临时目录...'));
        $tmpDir = $this->prepareTempDirectory();

        // 4. 克隆/拉取仓库（增量模式会获取变化文件列表）
        $this->printer->setup(__('步骤 4/6：下载框架代码...'));
        $this->downloadFramework($tmpDir, $branch, $tag);

        // 5. 拷贝核心文件
        $this->printer->setup(__('步骤 5/6：更新核心文件...'));
        $this->copyCoreFiles($tmpDir);

        // 6. 保留临时目录（用于下次增量更新）
        $this->printer->setup(__('步骤 6/6：完成处理...'));
        $this->printer->note(__('保留缓存目录用于下次增量更新'));
        $this->printer->success(__('✓ 缓存目录：%{1}', [$tmpDir]));

        $this->printer->note('');
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✓✓✓ 框架核心更新完成！✓✓✓'));
        $this->printer->success('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');
        
        // 显示更新统计
        $this->printer->note(__('更新统计：'));
        $this->printer->note(__('  - 新增文件：%{1} 个', [$this->newFiles]));
        $this->printer->note(__('  - 更新文件：%{1} 个', [$this->updatedFiles]));
        $this->printer->note(__('  - 删除文件：%{1} 个', [$this->deletedFiles]));
        $this->printer->note(__('  - 跳过文件：%{1} 个（受保护或排除的文件）', [$this->skippedFiles]));
        $this->printer->note('');
    }

    private function checkGit(): void
    {
        if (!$this->commandExists('git')) {
            $this->printer->error(__('错误：未检测到 Git'));
            exit(1);
        }
        $this->printer->success(__('✓ Git 检查通过'));
    }

    /**
     * 加载核心更新配置：优先 app/etc/env.php 的 core_update，再叠加 .env 的 CORE_UPDATE_*
     *
     * @return array{repo_url?: string, branch_default?: string, repo_token?: string, repo_username?: string}
     */
    private function getCoreUpdateConfig(): array
    {
        $config = [];
        if (class_exists(Env::class)) {
            $fromEnv = Env::getInstance()->getConfig('core_update');
            if (is_array($fromEnv)) {
                $config = array_merge($config, $fromEnv);
            }
        }
        if (is_file($this->envFilePath) && is_readable($this->envFilePath)) {
            $lines = file($this->envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }
                    $map = [
                        'CORE_UPDATE_REPO_URL' => 'repo_url',
                        'CORE_UPDATE_BRANCH_DEFAULT' => 'branch_default',
                        'CORE_UPDATE_REPO_TOKEN' => 'repo_token',
                        'CORE_UPDATE_REPO_USERNAME' => 'repo_username',
                    ];
                    if (isset($map[$key])) {
                        $config[$map[$key]] = $value;
                    }
                }
            }
        }
        try {
            /** @var DeployConfigService $deployConfigService */
            $deployConfigService = ObjectManager::getInstance(DeployConfigService::class);
            $config = array_merge($config, $deployConfigService->getCoreUpdateConfig());
        } catch (\Throwable) {
            // 后台配置不可用时继续使用 app/etc/env.php 或 .env，避免核心恢复入口失效。
        }
        return $config;
    }

    /**
     * 为 HTTPS 仓库 URL 注入凭据（token 或 username+token），私有仓库时使用
     */
    private function buildRepoUrlWithAuth(string $repo, array $config): string
    {
        $token = $config['repo_token'] ?? '';
        $username = $config['repo_username'] ?? '';
        if ($token === '' || !str_starts_with($repo, 'http')) {
            return $repo;
        }
        $parsed = parse_url($repo);
        if ($parsed === false || !isset($parsed['host'])) {
            return $repo;
        }
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $path = $parsed['path'] ?? '';
        if (isset($parsed['port']) && $parsed['port'] !== 80 && $parsed['port'] !== 443) {
            $host .= ':' . $parsed['port'];
        }
        $user = $username !== '' ? $username : 'oauth2';
        return $scheme . '://' . rawurlencode($user) . ':' . rawurlencode($token) . '@' . $host . $path;
    }

    /** 输出时隐藏 URL 中的凭据 */
    private function maskRepoUrl(string $repo): string
    {
        if (str_contains($repo, '@')) {
            return preg_replace('#://[^@]+@#', '://***@', $repo) ?: $repo;
        }
        return $repo;
    }

    /**
     * 解析仓库候选列表：显式配置时仅用指定仓库；否则 GitHub 优先，Gitee 备用。
     *
     * @return string[]
     */
    private function resolveRepoCandidates(array $args, array $config): array
    {
        $explicitRepo = trim((string)($args['repo'] ?? ''));
        if ($explicitRepo !== '') {
            return [$this->buildRepoUrlWithAuth($explicitRepo, $config)];
        }

        $configRepo = trim((string)($config['repo_url'] ?? ''));
        if ($configRepo !== '') {
            return [$this->buildRepoUrlWithAuth($configRepo, $config)];
        }

        $githubRepo = $this->buildRepoUrlWithAuth(self::DEFAULT_REPO_GITHUB, $config);
        $giteeRepo = $this->buildRepoUrlWithAuth(self::DEFAULT_REPO_GITEE, $config);

        $this->printer->note(__('检测 GitHub 连通性（ping github.com）...'));
        if ($this->isGithubReachable()) {
            $this->printer->success(__('✓ GitHub 可达，使用 GitHub 仓库'));
            return [$githubRepo, $giteeRepo];
        }

        $this->printer->warning(__('GitHub 不可达，使用 Gitee 镜像仓库'));
        return [$giteeRepo];
    }

    /**
     * 检测 github.com 是否可达：先 ping，再探测 HTTPS 443。
     */
    private function isGithubReachable(int $timeoutSeconds = 3): bool
    {
        $host = 'github.com';

        if ($this->isWindows) {
            $timeoutMs = max(1000, $timeoutSeconds * 1000);
            exec(
                sprintf('ping -n 1 -w %d %s', $timeoutMs, escapeshellarg($host)),
                $output,
                $pingCode
            );
        } else {
            exec(
                sprintf('ping -c 1 -W %d %s 2>/dev/null', $timeoutSeconds, escapeshellarg($host)),
                $output,
                $pingCode
            );
        }

        if ($pingCode === 0) {
            return true;
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('ssl://' . $host, 443, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket)) {
            fclose($socket);
            $this->printer->note(__('GitHub ping 未响应，但 HTTPS 端口可达'));
            return true;
        }

        return false;
    }

    private function getBranch(array $args, array $config): string
    {
        $branch = $args['branch'] ?? $args['b'] ?? $config['branch_default'] ?? null;
        if (empty($branch)) {
            $this->printer->error(__('错误：必须指定分支（-b <分支名>）或在配置中设置 branch_default'));
            $this->printer->note(__('php bin/w update:core -b <分支名>'));
            exit(1);
        }
        return $branch;
    }

    private function prepareTempDirectory(): string
    {
        $tmpDir = Env::backup_dir . 'tmp' . DS . 'core-update';
        
        // 只有在强制模式下才删除旧目录
        if ($this->forceUpdate && is_dir($tmpDir)) {
            $this->printer->note(__('强制模式：删除旧缓存目录...'));
            $this->removeDirectory($tmpDir);
        }
        
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
            $this->printer->success(__('✓ 创建新缓存目录：%{1}', [$tmpDir]));
        } else {
            $this->printer->success(__('✓ 使用现有缓存目录：%{1}', [$tmpDir]));
        }
        
        return $tmpDir;
    }

    private function downloadFramework(string $tmpDir, string $branch, ?string $tag): void
    {
        // 检查是否已有 Git 仓库
        $gitDir = $tmpDir . DS . '.git';
        $isExistingRepo = is_dir($gitDir);
        
        if ($isExistingRepo && !$this->forceUpdate) {
            // 增量模式：已有仓库，使用 git fetch + diff 获取变化文件
            $this->printer->note(__('增量更新：检测到现有仓库缓存，使用 git fetch 拉取变更...'));
            
            // 获取当前 HEAD
            $currentHead = '';
            exec(sprintf('cd %s && git rev-parse HEAD 2>&1', escapeshellarg($tmpDir)), $headOutput, $headCode);
            if ($headCode === 0 && !empty($headOutput)) {
                $currentHead = trim($headOutput[0]);
            }
            
            // 获取远程更新
            $fetchCmd = sprintf('cd %s && git fetch origin', escapeshellarg($tmpDir));
            exec($fetchCmd, $output, $fetchCode);
            
            if ($fetchCode !== 0) {
                $this->printer->warning(__('fetch 失败，尝试切换仓库并重新克隆...'));
                $this->removeDirectory($tmpDir);
                mkdir($tmpDir, 0755, true);
                $this->cloneRepository($tmpDir, $branch, $tag);
                $this->isNewClone = true;
                $this->changedFiles = [];
                return;
            }
            
            // 确定目标引用
            $targetRef = $tag ? $tag : "origin/{$branch}";
            
            if ($tag) {
                // 如果指定了标签，获取所有标签
                $this->printer->note(__('获取标签...', [$tag]));
                exec(sprintf('cd %s && git fetch --tags', escapeshellarg($tmpDir)), $output, $tagFetchCode);
            }
            
            // 获取 Git diff 变化的文件列表（在 reset 之前）
            if (!empty($currentHead)) {
                $this->changedFiles = $this->getGitChangedFiles($tmpDir, $currentHead, $targetRef);
                $changedCount = count($this->changedFiles);
                $this->printer->note(__('检测到 %{1} 个变化的文件', [$changedCount]));
            }
            
            if ($tag) {
                // 切换到标签
                exec(sprintf('cd %s && git checkout %s 2>&1', escapeshellarg($tmpDir), escapeshellarg($tag)), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
                $this->printer->success(__('✓ 已切换到标签 %{1}', [$tag]));
            } else {
                // 重置到远程分支最新
                $this->printer->note(__('重置到远程分支 %{1} 最新...', [$branch]));
                
                // 先切换到目标分支
                exec(sprintf('cd %s && git checkout %s 2>&1', escapeshellarg($tmpDir), escapeshellarg($branch)), $output, $checkoutCode);
                
                if ($checkoutCode !== 0) {
                    // 分支不存在，从远程创建
                    exec(sprintf('cd %s && git checkout -b %s origin/%s 2>&1', 
                        escapeshellarg($tmpDir), 
                        escapeshellarg($branch), 
                        escapeshellarg($branch)
                    ), $output, $createBranchCode);
                    
                    if ($createBranchCode !== 0) {
                        $this->printer->error(__('分支不存在: %{1}', [$branch]));
                        exit(1);
                    }
                }
                
                // 强制重置到远程分支最新
                $resetCmd = sprintf('cd %s && git reset --hard origin/%s', 
                    escapeshellarg($tmpDir), 
                    escapeshellarg($branch)
                );
                exec($resetCmd, $output, $resetCode);
                
                if ($resetCode !== 0) {
                    $this->printer->warning(__('重置失败，尝试切换仓库并重新克隆...'));
                    $this->removeDirectory($tmpDir);
                    mkdir($tmpDir, 0755, true);
                    $this->cloneRepository($tmpDir, $branch, $tag);
                    $this->isNewClone = true;
                    $this->changedFiles = [];
                    return;
                }
                
                $this->printer->success(__('✓ 已更新到分支 %{1} 最新版本', [$branch]));
            }
            
            // 显示最新提交信息
            $this->showLatestCommit($tmpDir);
            
        } else {
            // 强制模式或新仓库：完整克隆
            $this->cloneRepository($tmpDir, $branch, $tag);
            // 标记为新克隆，需要全量拷贝
            $this->isNewClone = true;
            $this->changedFiles = [];
        }
    }
    
    /**
     * 获取 Git 变化的文件列表
     * 
     * @param string $tmpDir 临时目录
     * @param string $fromRef 起始引用（当前 HEAD）
     * @param string $toRef 目标引用（远程分支或标签）
     * @return array<string, string> [相对路径 => 状态(A/M/D/R)]
     */
    private function getGitChangedFiles(string $tmpDir, string $fromRef, string $toRef): array
    {
        $changedFiles = [];
        
        // 使用 git diff --name-status 获取变化的文件及其状态
        $diffCmd = sprintf(
            'cd %s && git diff --name-status %s..%s 2>&1',
            escapeshellarg($tmpDir),
            escapeshellarg($fromRef),
            escapeshellarg($toRef)
        );
        
        exec($diffCmd, $diffOutput, $diffCode);
        
        if ($diffCode !== 0) {
            $this->printer->warning(__('无法获取 Git diff，将使用全量更新'));
            return [];
        }
        
        foreach ($diffOutput as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // 格式: "M\tpath/to/file" 或 "R100\told/path\tnew/path"
            $parts = preg_split('/\t+/', $line);
            if (count($parts) >= 2) {
                $status = $parts[0];
                $filePath = $parts[1];
                
                // 处理重命名的情况 (R100 old_path new_path)
                if (str_starts_with($status, 'R')) {
                    // 旧文件标记为删除
                    $changedFiles[$filePath] = 'D';
                    // 新文件标记为添加
                    if (isset($parts[2])) {
                        $changedFiles[$parts[2]] = 'A';
                    }
                } else {
                    // A=添加, M=修改, D=删除
                    $changedFiles[$filePath] = $status[0];
                }
            }
        }
        
        return $changedFiles;
    }
    
    /**
     * 完整克隆仓库（按候选列表依次尝试）
     */
    private function cloneRepository(string $tmpDir, string $branch, ?string $tag): void
    {
        $this->printer->note(__('完整克隆仓库...'));
        $lastOutput = [];

        foreach ($this->repoCandidates as $index => $repo) {
            if ($index > 0) {
                $this->printer->warning(__('主仓库失败，尝试备用仓库：%{1}', [$this->maskRepoUrl($repo)]));
                $this->removeDirectory($tmpDir);
                mkdir($tmpDir, 0755, true);
            }

            if ($this->attemptCloneRepository($repo, $tmpDir, $branch, $tag, $lastOutput)) {
                $this->showLatestCommit($tmpDir);
                return;
            }
        }

        $this->printer->error(__('所有仓库均无法克隆或初始化'));
        $this->printer->printList($lastOutput);
        exit(1);
    }

    /**
     * 尝试从单个仓库克隆或初始化
     *
     * @param string[] $lastOutput
     */
    private function attemptCloneRepository(
        string $repo,
        string $tmpDir,
        string $branch,
        ?string $tag,
        array &$lastOutput
    ): bool {
        $this->printer->note(__('克隆仓库：%{1}', [$this->maskRepoUrl($repo)]));

        $command = sprintf(
            'cd %s && git clone -b %s --depth 1 %s . 2>&1',
            escapeshellarg($tmpDir),
            escapeshellarg($branch),
            escapeshellarg($repo)
        );

        exec($command, $output, $returnCode);
        $lastOutput = $output;

        if ($returnCode !== 0) {
            $this->printer->warning(__('克隆失败，尝试初始化仓库...'));
            $this->removeDirectory($tmpDir);
            mkdir($tmpDir, 0755, true);

            exec('cd ' . escapeshellarg($tmpDir) . ' && git init 2>&1', $output, $initCode);
            $lastOutput = array_merge($lastOutput, $output);
            exec('cd ' . escapeshellarg($tmpDir) . ' && git remote add origin ' . escapeshellarg($repo) . ' 2>&1', $output, $remoteCode);
            $lastOutput = array_merge($lastOutput, $output);
            exec('cd ' . escapeshellarg($tmpDir) . ' && git fetch origin 2>&1', $output, $fetchCode);
            $lastOutput = array_merge($lastOutput, $output);

            if ($fetchCode !== 0) {
                return false;
            }

            if ($tag) {
                $this->printer->note(__('拉取标签 %{1}...', [$tag]));
                exec('cd ' . escapeshellarg($tmpDir) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1', $output, $checkoutCode);
                $lastOutput = array_merge($lastOutput, $output);

                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
            } else {
                $this->printer->note(__('拉取分支 %{1}...', [$branch]));
                exec(
                    'cd ' . escapeshellarg($tmpDir) . ' && git checkout -b '
                    . escapeshellarg($branch) . ' origin/' . escapeshellarg($branch) . ' 2>&1',
                    $output,
                    $checkoutCode
                );
                $lastOutput = array_merge($lastOutput, $output);

                if ($checkoutCode !== 0) {
                    $this->printer->error(__('分支不存在: %{1}', [$branch]));
                    exit(1);
                }
            }

            $this->printer->success(__('✓ 仓库初始化成功'));
            return true;
        }

        $this->printer->success(__('✓ 仓库克隆成功'));

        if ($tag) {
            $this->printer->note(__('切换到标签 %{1}...', [$tag]));
            $command = sprintf(
                'cd %s && git fetch --tags && git checkout %s 2>&1',
                escapeshellarg($tmpDir),
                escapeshellarg($tag)
            );

            exec($command, $output, $returnCode);
            $lastOutput = $output;

            if ($returnCode !== 0) {
                $this->printer->error(__('标签不存在: %{1}', [$tag]));
                exit(1);
            }

            $this->printer->success(__('✓ 已切换到标签 %{1}', [$tag]));
        }

        return true;
    }
    
    /**
     * 显示最新提交信息
     */
    private function showLatestCommit(string $tmpDir): void
    {
        exec(sprintf('cd %s && git log -1 --format="%%h - %%s (%%ci)"', escapeshellarg($tmpDir)), $logOutput, $logCode);
        if ($logCode === 0 && !empty($logOutput)) {
            $this->printer->note(__('最新提交：%{1}', [$logOutput[0]]));
        }
    }

    private function copyCoreFiles(string $tmpDir): void
    {
        // 重置计数器
        $this->updatedFiles = 0;
        $this->skippedFiles = 0;
        $this->newFiles = 0;
        $this->deletedFiles = 0;
        
        // 需要更新的所有目录（但不包括 vendor）
        $allPaths = ['app', 'bin', 'pub'];
        
        // 判断更新模式：
        // 1. 强制模式(-f)或新克隆 → 全量覆盖
        // 2. 增量模式且有变化 → 只拷贝变化的文件
        // 3. 增量模式且无变化 → 跳过（已是最新）
        
        if ($this->forceUpdate || $this->isNewClone) {
            // 强制模式或新克隆：完全覆盖所有文件
            $this->printer->note(__('完全覆盖模式：更新所有文件...'));
            $this->copyAllFiles($tmpDir, $allPaths);
        } elseif (!empty($this->changedFiles)) {
            // 增量模式：只拷贝 Git 变化的文件
            $this->printer->note(__('增量模式：只更新 Git 变化的文件...'));
            $this->copyChangedFilesOnly($tmpDir, $allPaths);
        } else {
            // 增量模式但没有变化：跳过
            $this->printer->success(__('✓ 已是最新版本，无需更新文件'));
        }
        
        // 注意：不更新 vendor 目录，保留现有的依赖包
        $this->printer->note('');
        $this->printer->note(__('⚠ vendor 目录未更新，保留现有依赖包'));
    }
    
    /**
     * 增量模式：只拷贝 Git 变化的文件
     */
    private function copyChangedFilesOnly(string $tmpDir, array $allowedPaths): void
    {
        // 需要保护的完整文件路径（绝对不覆盖）
        $protectedPaths = [
            'app/etc/env.php',
            'app/.env',
            '.env',
        ];
        
        $processedCount = 0;
        
        foreach ($this->changedFiles as $relativePath => $status) {
            // 标准化路径分隔符
            $relativePath = str_replace(['/', '\\'], DS, $relativePath);
            
            // 检查是否在允许的目录中
            $isInAllowedPath = false;
            foreach ($allowedPaths as $allowedPath) {
                if (str_starts_with($relativePath, $allowedPath . DS) || $relativePath === $allowedPath) {
                    $isInAllowedPath = true;
                    break;
                }
            }
            
            if (!$isInAllowedPath) {
                continue;
            }

            if ($this->isExcludedCoreUpdatePath($relativePath)) {
                $this->skippedFiles++;
                continue;
            }
            
            // 检查是否是受保护的文件
            $normalizedPath = str_replace('\\', '/', $relativePath);
            $isProtected = false;
            foreach ($protectedPaths as $protectedPath) {
                if ($normalizedPath === $protectedPath) {
                    $isProtected = true;
                    break;
                }
            }
            
            $sourcePath = $tmpDir . DS . $relativePath;
            $targetPath = BP . $relativePath;
            
            if ($isProtected && file_exists($targetPath)) {
                // 受保护的文件，跳过
                $this->skippedFiles++;
                continue;
            }
            
            switch ($status) {
                case 'A': // 新增
                    if (file_exists($sourcePath)) {
                        $this->ensureDirectoryExists(dirname($targetPath));
                        copy($sourcePath, $targetPath);
                        $this->newFiles++;
                        $processedCount++;
                    }
                    break;
                    
                case 'M': // 修改
                    if (file_exists($sourcePath)) {
                        $this->ensureDirectoryExists(dirname($targetPath));
                        copy($sourcePath, $targetPath);
                        $this->updatedFiles++;
                        $processedCount++;
                    }
                    break;
                    
                case 'D': // 删除
                    // 注意：通常不删除目标目录中的文件，因为用户可能有自定义修改
                    // 如果需要删除，取消下面的注释
                    // if (file_exists($targetPath)) {
                    //     unlink($targetPath);
                    //     $this->deletedFiles++;
                    //     $processedCount++;
                    // }
                    break;
            }
        }
        
        $this->printer->success(__('✓ 处理了 %{1} 个变化的文件', [$processedCount]));
    }
    
    /**
     * 强制模式：完全覆盖所有文件
     */
    private function copyAllFiles(string $tmpDir, array $allPaths): void
    {
        $processedPaths = 0;
        $failedPaths = 0;
        
        foreach ($allPaths as $path) {
            $source = $tmpDir . DS . $path;
            $target = BP . $path;
            
            if (!is_dir($source)) {
                $this->printer->warning(__('⚠ 源路径不存在: %{1}', [$path]));
                $failedPaths++;
                continue;
            }
            
            $this->printer->note(__('拷贝 %{1}...', [$path]));
            
            // 完全覆盖：拷贝所有文件
            if ($this->copyDirectoryFull($source, $target, $path)) {
                $this->printer->success(__('✓ %{1}', [$path]));
                $processedPaths++;
            } else {
                $this->printer->warning(__('⚠ %{1} 处理失败', [$path]));
                $failedPaths++;
            }
        }
        
        $this->printer->note('');
        $this->printer->success(__('✓ 共处理 %{1} 个路径', [$processedPaths]));
        if ($failedPaths > 0) {
            $this->printer->warning(__('⚠ 跳过 %{1} 个路径', [$failedPaths]));
        }
    }
    
    /**
     * 完全覆盖目录：
     * 1. 新文件 → 拷贝
     * 2. 已存在的文件 → 覆盖（除了受保护的配置文件）
     * 3. 目标目录中的额外文件 → 保留不动
     */
    private function copyDirectoryFull(string $source, string $target, string $rootPath): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        
        // 需要保护的完整文件路径（绝对不覆盖）
        $protectedPaths = ['etc' . DS . 'env.php', '.env'];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = $iterator->getSubPathname();
            $logicalPath = str_replace('\\', '/', $rootPath . '/' . $relativePath);
            $targetPath = $target . DS . $relativePath;

            if ($this->isExcludedCoreUpdatePath($logicalPath)) {
                if ($item->isFile()) {
                    $this->skippedFiles++;
                }
                continue;
            }
            
            if ($item->isDir()) {
                // 目录：不存在就创建
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } elseif ($item->isFile()) {
                $sourcePath = $item->getPathname();
                
                // 检查是否是受保护的文件路径
                $shouldProtect = false;
                foreach ($protectedPaths as $protectedPath) {
                    if ($relativePath === $protectedPath) {
                        $shouldProtect = true;
                        break;
                    }
                }
                
                // 保护配置文件：绝对不覆盖 app/etc/env.php 和 .env
                if ($shouldProtect && file_exists($targetPath)) {
                    $this->skippedFiles++;
                    continue;
                }
                
                // 检查目标文件是否存在
                $isNewFile = !file_exists($targetPath);
                
                // 确保目标目录存在
                $this->ensureDirectoryExists(dirname($targetPath));
                
                // 直接覆盖拷贝
                copy($sourcePath, $targetPath);
                
                if ($isNewFile) {
                    $this->newFiles++;
                } else {
                    $this->updatedFiles++;
                }
            }
        }
        
        return true;
    }

    private function isExcludedCoreUpdatePath(string $relativePath): bool
    {
        $normalizedPath = trim(str_replace('\\', '/', $relativePath), '/');
        foreach (self::CORE_UPDATE_EXCLUDED_PATHS as $excludedPath) {
            if ($normalizedPath === $excludedPath || str_starts_with($normalizedPath, $excludedPath . '/')) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 确保目录存在
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        if ($this->isWindows) {
            exec(sprintf('rmdir /s /q %s', escapeshellarg($dir)));
        } else {
            exec(sprintf('rm -rf %s', escapeshellarg($dir)));
        }
    }

    private function commandExists(string $command): bool
    {
        if ($this->isWindows) {
            exec("where {$command} 2>nul", $output, $returnCode);
        } else {
            exec("which {$command} 2>/dev/null", $output, $returnCode);
        }
        return $returnCode === 0;
    }
}

