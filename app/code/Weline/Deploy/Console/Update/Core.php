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
    /** 命令别名：core:update / core:up */
    public const ALIASES = ['core:update', 'core:up'];

    /** 默认主仓库（GitHub），未配置且可达时使用 */
    private const DEFAULT_REPO_GITHUB = 'https://github.com/Aiweline/WelineFramework.git';

    /** 默认备用仓库（Gitee），GitHub 不可达时使用 */
    private const DEFAULT_REPO_GITEE = 'https://gitee.com/aiweline/WelineFramework.git';

    /**
     * 核心更新允许同步的路径（目录或文件）。
     *
     * 业务仓（如 GuoLaiRen/*）不在范围内：不参与脏工作区拦截，也不会被拷贝覆盖。
     */
    private const CORE_UPDATE_PATHS = [
        'app/code/Weline',
        'app/code/config.php',
        'app/autoload.php',
        'app/bootstrap.php',
        'app/bootstrap_phpunit.php',
        'app/etc/env.sample.php',
        'bin',
        'dev',
        'pub',
        'setup',
    ];

    /** 显式排除：即使误落在允许前缀下也绝不覆盖 */
    private const CORE_UPDATE_EXCLUDED_PATHS = [
        'app/code/Aiweline',
        'app/code/GuoLaiRen',
        'app/code/WeShop',
    ];

    /** 已存在时绝不覆盖的项目级配置文件（含业务仓模块清单） */
    private const CORE_UPDATE_PROTECTED_PATHS = [
        'app/etc/env.php',
        'app/etc/modules.php',
        'app/etc/module_dependencies.php',
        'app/.env',
        '.env',
        'dev/deploy/.config',
    ];

    private System $system;
    private bool $isWindows;
    private string $envFilePath;
    private string $gitExecutable = 'git';
    private string $gitCommand = 'git';

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
        return __('从核心仓库增量更新框架代码（仅 Weline 模块与必要核心路径；不触碰业务代码）');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'update:core',
            __('从 Git 仓库增量更新框架核心代码'),
            [
                '[分支名]' => __('可直接指定分支，如：master；也可使用 -b/--branch'),
                '-b, --branch=<分支名>' => __('指定分支（未配置默认分支时必填，如：main, master, dev）'),
                '-t, --tag=<标签名>' => '指定标签版本（可选，如：v1.0.0）',
                '--repo=<仓库地址>' => __('指定 Git 仓库地址（覆盖配置文件，默认：公用官网或配置的仓库）'),
                '-f, --force' => '强制更新：重新克隆仓库，完全覆盖核心文件',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '双仓库策略' => __('未指定仓库时：GitHub 可达则对比 GitHub/Gitee 同分支 tip；一致时优先 GitHub，不一致时选用 Gitee 以免拉到落后镜像；GitHub 不可达则用 Gitee。增量更新会把缓存 origin 切到本次选用仓库'),
                '仓库可配置' => __('仓库地址、默认分支、密钥可在项目根目录 .env 或 app/etc/env.php 的 core_update 中配置；显式配置后不再自动切换'),
                '两阶段语义' => __('旧缓存 HEAD 只用于检测「项目本地核心是否相对上次同步被私改」；检测通过后必须把缓存仓更新到线上最新 tip，再按旧基线→新 tip 的 diff（并补缺失）同步到项目。绝不能用旧缓存内容当作本次更新源'),
                '增量更新' => __('默认先对比旧基线做冲突检测，再 git fetch 线上最新；仅把相对旧基线有变更的核心文件覆盖到项目，另补本地缺失文件；已存在但内容不同的本地核心不因与新 tip hash 不一致而被误回刷（私改应由冲突检测拦截）'),
                '同步范围' => __('仅同步核心仓库路径：app/code/Weline 与必要启动/样例/bin/dev/pub/setup；业务模块不在更新范围内'),
                '本地冲突检测' => __('只对比「本地核心文件」与 tmp/core-update 缓存仓上次同步的核心 commit；绝不读取业务项目 git status。本地核心相对该基线有私改才拒绝；-f 强制覆盖。业务模块改动完全忽略'),
                '强制更新' => __('仅显式使用 -f/--force 时跳过本地核心冲突保护，删除缓存并重新克隆后覆盖核心文件；成功后缓存仓 HEAD 即成为下次对比基线'),
                '临时目录方式' => '使用临时目录下载，不影响项目 Git 仓库',
                '版本验证' => '如果指定了标签但不存在，命令会报错并退出',
                '排除目录' => __('不会拷贝 app/code/Aiweline、GuoLaiRen、WeShop；这些由目标项目自行管理'),
                '保护文件' => 'app/etc/env.php、modules.php、module_dependencies.php、.env、dev/deploy/.config 等已存在时不覆盖',
            ],
            [
                '增量更新到最新' => 'php bin/w core:update master  （或 update:core -b master）',
                '强制完整更新' => 'php bin/w update:core -b main -f',
                '指定标签' => 'php bin/w update:core -b main -t v1.0.0',
                __('使用自定义仓库（需先配置 .env 或 env.php）') => 'php bin/w update:core -b master',
            ],
            'php bin/w core:update <分支名> 或 php bin/w update:core -b <分支名>'
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
        $this->printer->setup(__('步骤 1/7：检查 Git...'));
        $this->checkGit();

        // 2. 验证参数
        $this->printer->setup(__('步骤 2/7：验证参数...'));
        $config = $this->getCoreUpdateConfig();
        $branch = $this->getBranch($args, $config);
        $tag = $args['tag'] ?? $args['t'] ?? null;
        $this->repoCandidates = $this->resolveRepoCandidates($args, $config, $branch);
        $repo = $this->repoCandidates[0];

        $this->printer->note(__('仓库：%{1}', [$this->maskRepoUrl($repo)]));
        if (count($this->repoCandidates) > 1) {
            $this->printer->note(__('备用仓库：%{1}', [$this->maskRepoUrl($this->repoCandidates[1])]));
        }
        $this->printer->note(__('分支：%{1}', [$branch]));
        if ($tag) {
            $this->printer->note(__('标签：%{1}', [$tag]));
        }
        $this->printer->note(__('更新模式：%{1}', [
            $this->forceUpdate
                ? '强制完整更新（跳过冲突检测，重克后覆盖）'
                : '两阶段：旧基线仅做冲突检测 → 拉取线上最新 → 按 diff 同步到项目',
        ]));
        $this->printer->note('');

        // 3. 准备缓存目录（当前 HEAD = 上次已同步到项目的核心 commit，仅作冲突基线）
        $this->printer->setup(__('步骤 3/7：准备临时目录...'));
        $tmpDir = $this->prepareTempDirectory();

        // 4. 旧基线只用于检测：本地核心相对「上次同步 commit」是否被私改
        $this->printer->setup(__('步骤 4/7：检查本地核心是否相对上次同步有改动...'));
        $this->assertLocalCoreUnmodifiedSinceLastSyncUnlessForced($tmpDir);

        // 5. 冲突检测已通过：必须把缓存仓更新到线上最新 tip（绝不能继续用旧内容当更新源）
        $this->printer->setup(__('步骤 5/7：下载框架代码（线上最新）...'));
        $this->downloadFramework($tmpDir, $branch, $tag);

        // 6. 用「已更新到最新 tip」的缓存树，按旧基线→新 tip 的变更同步到项目
        $this->printer->setup(__('步骤 6/7：更新核心文件...'));
        $this->copyCoreFiles($tmpDir);
        $this->refreshReflectionFactoriesAfterUpdate();

        // 7. 保留缓存；此时 HEAD = 新 tip，成为下次冲突检测基线
        $this->printer->setup(__('步骤 7/7：完成处理...'));
        $this->printer->note(__('保留缓存目录：当前 HEAD 仅作为下次冲突检测基线'));
        $this->printer->success(__('✓ 缓存目录：%{1}', [$tmpDir]));
        $this->printSyncedCoreBaseline($tmpDir);

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

    /**
     * 打印本次写入的核心基线（tmp/core-update 的 HEAD）。
     * 下次无 -f 时只与该基线对比本地核心文件，不看业务仓 git。
     */
    private function printSyncedCoreBaseline(string $tmpDir): void
    {
        $headOutput = [];
        $headCode = $this->runGitCommand($tmpDir, ['rev-parse', '--short', 'HEAD'], $headOutput);
        if ($headCode !== 0 || $headOutput === []) {
            return;
        }
        $short = trim((string)$headOutput[0]);
        if ($short === '') {
            return;
        }
        $this->printer->success(__(
            '✓ 下次冲突检测基线已更新为缓存仓 commit %{1}（仅用于判断本地核心是否被私改；下次更新仍会先拉线上最新 tip）',
            [$short]
        ));
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
     * 默认拒绝「本地私自改过核心文件」时的更新。
     *
     * 旧缓存 HEAD 的唯一职责：作为「上次已同步到项目的核心 commit」对比基线。
     * 对比对象：本地磁盘 CORE_UPDATE_PATHS ↔ 该旧基线树同路径内容。
     * 绝不读取业务项目 git；GuoLaiRen 等业务改动完全忽略。
     * 本检查必须在 downloadFramework（拉线上最新）之前完成；通过后旧树才会被前进到新 tip。
     * 显式 -f/--force 可跳过；首次尚无缓存时跳过。
     */
    private function assertLocalCoreUnmodifiedSinceLastSyncUnlessForced(string $tmpDir): void
    {
        if ($this->forceUpdate) {
            $this->printer->warning(__('⚠ 已显式启用 -f/--force，跳过本地核心冲突保护（将强制覆盖核心文件）'));
            return;
        }

        $gitDir = $tmpDir . DS . '.git';
        if (!is_dir($gitDir)) {
            $this->printer->note(__('首次核心更新（尚无上次同步 commit），跳过本地核心冲突检查'));
            return;
        }

        $headOutput = [];
        $headCode = $this->runGitCommand($tmpDir, ['rev-parse', '--short', 'HEAD'], $headOutput);
        $lastSyncedCommit = ($headCode === 0 && $headOutput !== [])
            ? trim((string)$headOutput[0])
            : '';

        $this->printer->note(__(
            '冲突检测基线（旧）：核心缓存仓 commit %{1}——仅用于判断本地核心是否被私改，不会作为本次更新源',
            [$lastSyncedCommit !== '' ? $lastSyncedCommit : '?']
        ));

        $drifted = $this->collectLocalCoreDriftAgainstLastSynced($tmpDir);
        if ($drifted === []) {
            if ($lastSyncedCommit !== '') {
                $this->printer->success(__(
                    '✓ 本地核心与上次同步 commit %{1} 一致，可直接更新',
                    [$lastSyncedCommit]
                ));
            } else {
                $this->printer->success(__('✓ 本地核心与上次同步内容一致，可直接更新'));
            }
            return;
        }

        $this->printer->error(__(
            '检测到 %{1} 个核心文件相对上次同步核心 commit%{2} 有本地私自修改，已拒绝更新',
            [
                count($drifted),
                $lastSyncedCommit !== '' ? (' ' . $lastSyncedCommit) : '',
            ]
        ));
        foreach (array_slice($drifted, 0, 30) as $path) {
            $this->printer->note('  ' . $path);
        }
        if (count($drifted) > 30) {
            $this->printer->note(__('  ……另有 %{1} 项未显示', [count($drifted) - 30]));
        }
        $this->printer->note(__('以上仅为核心路径相对缓存仓的内容差异，与业务模块 git 无关'));
        $this->printer->note(__('请先自行处理这些核心改动；确需用仓库版本强制覆盖时执行：php bin/w core:update <分支名> -f'));
        exit(1);
    }

    /**
     * 收集「上次同步核心树」中相对本地已漂移的核心文件。
     *
     * @return list<string>
     */
    private function collectLocalCoreDriftAgainstLastSynced(string $tmpDir): array
    {
        $drifted = [];

        foreach (self::CORE_UPDATE_PATHS as $rootPath) {
            $normalizedRoot = $this->normalizeCoreUpdateRelativePath($rootPath);
            if ($normalizedRoot === '' || $this->isExcludedCoreUpdatePath($normalizedRoot)) {
                continue;
            }

            $cacheRoot = $tmpDir . DS . str_replace('/', DS, $normalizedRoot);
            $localRoot = BP . str_replace('/', DS, $normalizedRoot);

            if (is_file($cacheRoot)) {
                if ($this->shouldSkipCoreDriftPath($normalizedRoot)) {
                    continue;
                }
                if ($this->isLocalCoreFileDrifted($cacheRoot, $localRoot)) {
                    $drifted[] = $normalizedRoot;
                }
                continue;
            }

            if (!is_dir($cacheRoot)) {
                continue;
            }

            foreach ($this->listCoreUpdateRelativeFiles($cacheRoot, $normalizedRoot) as $relativePath) {
                if ($this->shouldSkipCoreDriftPath($relativePath)) {
                    continue;
                }
                $cacheFile = $tmpDir . DS . str_replace('/', DS, $relativePath);
                $localFile = BP . str_replace('/', DS, $relativePath);
                if ($this->isLocalCoreFileDrifted($cacheFile, $localFile)) {
                    $drifted[] = $relativePath;
                }
            }
        }

        sort($drifted);

        return array_values(array_unique($drifted));
    }

    /**
     * @return list<string>
     */
    private function listCoreUpdateRelativeFiles(string $absoluteDir, string $relativeRoot): array
    {
        if (!is_dir($absoluteDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $absolutePath = $item->getPathname();
            $suffix = substr($absolutePath, strlen($absoluteDir));
            $suffix = $this->normalizeCoreUpdateRelativePath(str_replace('\\', '/', (string)$suffix));
            $relativePath = $suffix === ''
                ? $relativeRoot
                : $this->normalizeCoreUpdateRelativePath($relativeRoot . '/' . $suffix);
            if ($relativePath !== '') {
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    private function shouldSkipCoreDriftPath(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);
        if ($normalizedPath === '') {
            return true;
        }
        if ($this->isExcludedCoreUpdatePath($normalizedPath) || $this->isProtectedCoreUpdatePath($normalizedPath)) {
            return true;
        }
        if ($this->isIgnorableWorkingTreeNoise($normalizedPath)) {
            return true;
        }

        return false;
    }

    private function isLocalCoreFileDrifted(string $cacheFile, string $localFile): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }
        if (!is_file($localFile)) {
            return true;
        }

        return $this->shouldSyncIncrementalFile($cacheFile, $localFile);
    }

    /**
     * 本地噪音：不参与核心冲突检测。
     */
    private function isIgnorableWorkingTreeNoise(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);
        if ($normalizedPath === '' || $normalizedPath === '.DS_Store') {
            return true;
        }
        if (\str_ends_with($normalizedPath, '/.DS_Store') || \basename($normalizedPath) === '.DS_Store') {
            return true;
        }
        if (\str_contains($normalizedPath, '/__pycache__/') || \str_starts_with($normalizedPath, '__pycache__/')) {
            return true;
        }
        if (\str_ends_with($normalizedPath, '.pyc') || \str_ends_with($normalizedPath, '.pyo')) {
            return true;
        }
        if (\str_starts_with($normalizedPath, 'node_modules/') || \str_contains($normalizedPath, '/node_modules/')) {
            return true;
        }
        if (\str_starts_with($normalizedPath, '.vite/') || \str_contains($normalizedPath, '/.vite/')) {
            return true;
        }

        return false;
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
     * 解析仓库候选列表：显式配置时仅用指定仓库；否则 GitHub/Gitee 双源择优。
     *
     * @return string[]
     */
    private function resolveRepoCandidates(array $args, array $config, string $branch): array
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
        if (!$this->isGithubReachable()) {
            $this->printer->warning(__('GitHub 不可达，使用 Gitee 镜像仓库'));
            return [$giteeRepo];
        }

        $this->printer->success(__('✓ GitHub 可达'));
        return $this->orderDualReposByBranchTip($githubRepo, $giteeRepo, $branch);
    }

    /**
     * GitHub/Gitee tip 一致时优先 GitHub；不一致时选用 Gitee（本仓库主推源，避免 GitHub 滞后把核心打回旧版）。
     *
     * @return string[]
     */
    private function orderDualReposByBranchTip(string $githubRepo, string $giteeRepo, string $branch): array
    {
        $githubTip = $this->lsRemoteBranchTip($githubRepo, $branch);
        $giteeTip = $this->lsRemoteBranchTip($giteeRepo, $branch);

        if ($githubTip === '' && $giteeTip === '') {
            $this->printer->warning(__('未能探测双源 tip，回退优先 GitHub'));
            return [$githubRepo, $giteeRepo];
        }
        if ($githubTip === '') {
            $this->printer->warning(__('GitHub tip 探测失败，使用 Gitee'));
            return [$giteeRepo, $githubRepo];
        }
        if ($giteeTip === '') {
            $this->printer->note(__('Gitee tip 探测失败，使用 GitHub'));
            return [$githubRepo, $giteeRepo];
        }
        if (hash_equals($githubTip, $giteeTip)) {
            $this->printer->success(__(
                '✓ GitHub/Gitee %{1} tip 一致（%{2}），优先 GitHub',
                [$branch, substr($githubTip, 0, 8)]
            ));
            return [$githubRepo, $giteeRepo];
        }

        $this->printer->warning(__(
            '⚠ GitHub 与 Gitee 的 %{1} tip 不一致（GitHub=%{2}, Gitee=%{3}），选用 Gitee 以免拉到落后镜像',
            [$branch, substr($githubTip, 0, 8), substr($giteeTip, 0, 8)]
        ));
        return [$giteeRepo, $githubRepo];
    }

    private function lsRemoteBranchTip(string $repo, string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '') {
            return '';
        }
        $cwd = getcwd() ?: BP;
        $output = [];
        $code = $this->runGitCommand($cwd, ['ls-remote', $repo, 'refs/heads/' . $branch], $output);
        if ($code !== 0 || $output === []) {
            return '';
        }
        foreach ($output as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, 'fatal:') || str_starts_with($line, 'error:')) {
                continue;
            }
            if (preg_match('/^([0-9a-f]{40})\s+refs\/heads\//i', $line, $m) === 1) {
                return strtolower($m[1]);
            }
        }

        return '';
    }

    /**
     * 增量缓存仓的 origin 必须指向本次选用仓库，否则会一直 fetch 旧镜像 tip。
     */
    private function ensureCacheOriginUsesPreferredRepo(string $tmpDir): void
    {
        $preferred = trim((string)($this->repoCandidates[0] ?? ''));
        if ($preferred === '') {
            return;
        }

        $output = [];
        $code = $this->runGitCommand($tmpDir, ['remote', 'get-url', 'origin'], $output);
        $current = ($code === 0 && $output !== []) ? trim((string)$output[0]) : '';
        if ($current === $preferred) {
            return;
        }

        if ($current === '') {
            $this->runGitCommand($tmpDir, ['remote', 'add', 'origin', $preferred], $output);
            $this->printer->note(__('缓存仓已添加 origin：%{1}', [$this->maskRepoUrl($preferred)]));
            return;
        }

        $this->printer->warning(__(
            '缓存仓 origin 为 %{1}，切换为本次选用仓库 %{2}',
            [$this->maskRepoUrl($current), $this->maskRepoUrl($preferred)]
        ));
        $setCode = $this->runGitCommand($tmpDir, ['remote', 'set-url', 'origin', $preferred], $output);
        if ($setCode !== 0) {
            $this->printer->warning(__('切换 origin 失败，继续使用原远程'));
        }
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
        $branch = $args['branch'] ?? $args['b'] ?? null;
        if (empty($branch)) {
            $skipNextValue = false;
            foreach ($args as $key => $value) {
                if (!is_int($key) || !is_string($value) || $value === '') {
                    continue;
                }
                if ($skipNextValue) {
                    $skipNextValue = false;
                    continue;
                }
                if (in_array($value, ['-b', '--branch', '-t', '--tag', '--repo'], true)) {
                    $skipNextValue = true;
                    continue;
                }
                if (
                    $value === 'core:update'
                    || $value === 'update:core'
                    || str_starts_with($value, '-')
                ) {
                    continue;
                }
                $branch = $value;
                break;
            }
        }
        $branch = $branch ?: ($config['branch_default'] ?? null);
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
            // 冲突检测已用旧 HEAD 做完。此处旧 HEAD 只记作基线，缓存仓必须前进到线上最新 tip。
            $this->printer->note(__('冲突检测已通过：旧缓存仅作基线，开始拉取线上最新 tip...'));
            $this->ensureCacheOriginUsesPreferredRepo($tmpDir);
            
            // 记录旧基线（仅用于算 diff）；之后 reset 到线上最新
            $currentHead = '';
            $headCode = $this->runGitCommand($tmpDir, ['rev-parse', 'HEAD'], $headOutput);
            if ($headCode === 0 && !empty($headOutput)) {
                $currentHead = trim($headOutput[0]);
            }
            if ($currentHead !== '') {
                $this->printer->note(__(
                    '旧基线 commit %{1}（只用于冲突检测与 diff；更新源是线上最新）',
                    [substr($currentHead, 0, 8)]
                ));
            }
            
            // 获取远程更新（失败则按候选列表轮换 origin 再试，仍失败才整仓重克）
            $fetchCode = 1;
            $output = [];
            foreach ($this->repoCandidates as $index => $candidateRepo) {
                if ($index > 0) {
                    $this->printer->warning(__('fetch 失败，切换备用仓库：%{1}', [$this->maskRepoUrl($candidateRepo)]));
                    $this->runGitCommand($tmpDir, ['remote', 'set-url', 'origin', $candidateRepo], $output);
                }
                $fetchCode = $this->runGitCommand($tmpDir, ['fetch', 'origin'], $output);
                if ($fetchCode === 0) {
                    break;
                }
            }
            
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
                $tagFetchCode = $this->runGitCommand($tmpDir, ['fetch', '--tags'], $output);
            }
            
            // 获取 Git diff 变化的文件列表（在 reset 之前）
            if (!empty($currentHead)) {
                $this->changedFiles = $this->getGitChangedFiles($tmpDir, $currentHead, $targetRef);
                $changedCount = count($this->changedFiles);
                $this->printer->note(__('旧基线 → 线上 tip 变更文件：%{1} 个', [$changedCount]));
            }
            
            if ($tag) {
                // 切换到标签
                $checkoutCode = $this->runGitCommand($tmpDir, ['checkout', $tag], $output);
                
                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
                $this->printer->success(__('✓ 已切换到标签 %{1}', [$tag]));
            } else {
                // 重置到远程分支最新
                $this->printer->note(__('将缓存仓重置到线上分支 %{1} 最新 tip...', [$branch]));
                
                // 先切换到目标分支
                $checkoutCode = $this->runGitCommand($tmpDir, ['checkout', $branch], $output);
                
                if ($checkoutCode !== 0) {
                    // 分支不存在，从远程创建
                    $createBranchCode = $this->runGitCommand($tmpDir, ['checkout', '-b', $branch, 'origin/' . $branch], $output);
                    
                    if ($createBranchCode !== 0) {
                        $this->printer->error(__('分支不存在: %{1}', [$branch]));
                        exit(1);
                    }
                }
                
                // 强制重置到远程分支最新
                $resetCode = $this->runGitCommand($tmpDir, ['reset', '--hard', 'origin/' . $branch], $output);
                
                if ($resetCode !== 0) {
                    $this->printer->warning(__('重置失败，尝试切换仓库并重新克隆...'));
                    $this->removeDirectory($tmpDir);
                    mkdir($tmpDir, 0755, true);
                    $this->cloneRepository($tmpDir, $branch, $tag);
                    $this->isNewClone = true;
                    $this->changedFiles = [];
                    return;
                }
                
                $newHeadOutput = [];
                $newHead = '';
                if ($this->runGitCommand($tmpDir, ['rev-parse', '--short', 'HEAD'], $newHeadOutput) === 0 && $newHeadOutput !== []) {
                    $newHead = trim((string)$newHeadOutput[0]);
                }
                $this->printer->success(__(
                    '✓ 缓存仓已从旧基线前进到线上最新 tip %{1}（分支 %{2}）',
                    [$newHead !== '' ? $newHead : '?', $branch]
                ));
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
        $diffCode = $this->runGitCommand($tmpDir, ['diff', '--name-status', $fromRef . '..' . $toRef], $diffOutput);
        
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

        $returnCode = $this->runGitCommand($tmpDir, ['clone', '-b', $branch, '--depth', '1', $repo, '.'], $output);
        $lastOutput = $output;

        if ($returnCode !== 0) {
            $this->printer->warning(__('克隆失败，尝试初始化仓库...'));
            $this->removeDirectory($tmpDir);
            mkdir($tmpDir, 0755, true);

            $initCode = $this->runGitCommand($tmpDir, ['init'], $output);
            $lastOutput = array_merge($lastOutput, $output);
            $remoteCode = $this->runGitCommand($tmpDir, ['remote', 'add', 'origin', $repo], $output);
            $lastOutput = array_merge($lastOutput, $output);
            $fetchCode = $this->runGitCommand($tmpDir, ['fetch', 'origin'], $output);
            $lastOutput = array_merge($lastOutput, $output);

            if ($fetchCode !== 0) {
                return false;
            }

            if ($tag) {
                $this->printer->note(__('拉取标签 %{1}...', [$tag]));
                $checkoutCode = $this->runGitCommand($tmpDir, ['checkout', $tag], $output);
                $lastOutput = array_merge($lastOutput, $output);

                if ($checkoutCode !== 0) {
                    $this->printer->error(__('标签不存在: %{1}', [$tag]));
                    exit(1);
                }
            } else {
                $this->printer->note(__('拉取分支 %{1}...', [$branch]));
                $checkoutCode = $this->runGitCommand($tmpDir, ['checkout', '-b', $branch, 'origin/' . $branch], $output);
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
            $returnCode = $this->runGitCommand($tmpDir, ['fetch', '--tags'], $output);
            if ($returnCode === 0) {
                $returnCode = $this->runGitCommand($tmpDir, ['checkout', $tag], $checkoutOutput);
                $output = array_merge($output, $checkoutOutput);
            }
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
        $logCode = $this->runGitCommand($tmpDir, ['log', '-1', '--format=%h - %s (%ci)'], $logOutput);
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
        
        $allPaths = self::CORE_UPDATE_PATHS;
        $this->printer->note(__('同步目录：%{1}', [implode(', ', $allPaths)]));
        
        // 判断更新模式：
        // 1. 强制模式(-f)或新克隆 → 全量覆盖
        // 2. 增量模式 → 仅 Git diff 变更覆盖；再补本地缺失文件（不因内容不同回刷本地私改）
        
        if ($this->forceUpdate || $this->isNewClone) {
            // 强制模式或新克隆：完全覆盖所有文件
            $this->printer->note(__('完全覆盖模式：更新所有文件...'));
            $this->copyAllFiles($tmpDir, $allPaths);
        } else {
            $processedBefore = $this->newFiles + $this->updatedFiles;

            $this->printer->note(__('增量模式：Git 变更覆盖 + 仅补本地缺失文件...'));

            if (!empty($this->changedFiles)) {
                $this->copyChangedFilesOnly($tmpDir, $allPaths);
            }

            $this->syncIncrementalCoreFiles($tmpDir);

            if ($processedBefore === 0 && $this->newFiles === 0 && $this->updatedFiles === 0) {
                $this->printer->success(__('✓ 已是最新版本，无需更新文件'));
            }
        }
        
        // 注意：不更新 vendor 目录，保留现有的依赖包
        $this->printer->note('');
        $this->printer->note(__('⚠ vendor 目录未更新，保留现有依赖包'));
    }

    private function refreshReflectionFactoriesAfterUpdate(): void
    {
        if (($this->newFiles + $this->updatedFiles + $this->deletedFiles) === 0) {
            $this->printer->note(__('未检测到核心文件变化，跳过反射工厂刷新'));
            return;
        }

        $this->printer->setup(__('刷新反射元数据与编译工厂...'));

        $binW = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        $exitCode = $this->runPhpCommand([$binW, 'reflection:compile'], 120, $output);

        if ($exitCode === 0) {
            $this->printer->success(__('反射工厂刷新完成'));
            return;
        }

        $this->printer->warning(__('反射工厂刷新失败，退出码：%{1}。可手动执行：php bin/w reflection:compile', [$exitCode]));
        if (!empty($output)) {
            $this->printer->printList(array_slice($output, -20));
        }
    }
    
    /**
     * 增量模式：只拷贝 Git 变化的文件
     */
    private function copyChangedFilesOnly(string $tmpDir, array $allowedPaths): void
    {
        $processedCount = 0;
        
        foreach ($this->changedFiles as $relativePath => $status) {
            $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);
            
            if (!$this->isInCoreUpdatePath($normalizedPath, $allowedPaths)) {
                continue;
            }

            if ($this->isExcludedCoreUpdatePath($normalizedPath)) {
                $this->skippedFiles++;
                continue;
            }
            
            $targetPath = BP . str_replace('/', DS, $normalizedPath);
            
            if ($this->isProtectedCoreUpdatePath($normalizedPath) && file_exists($targetPath)) {
                $this->skippedFiles++;
                continue;
            }

            $sourcePath = $tmpDir . DS . str_replace('/', DS, $normalizedPath);
            
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
     * 增量模式：仅补本地缺失的核心文件。
     * 已存在但内容不同的文件不在此覆盖——避免把本地领先/私改核心打回缓存仓旧版。
     * 上游有变更时由 copyChangedFilesOnly（git diff）覆盖；全量覆盖仅 -f / 新克隆。
     */
    private function syncIncrementalCoreFiles(string $tmpDir): void
    {
        $missingCount = 0;

        foreach (self::CORE_UPDATE_PATHS as $path) {
            $source = $tmpDir . DS . \str_replace('/', DS, $path);

            if (\is_file($source)) {
                $relativePath = $this->normalizeCoreUpdateRelativePath($path);
                if ($this->isExcludedCoreUpdatePath($relativePath)) {
                    continue;
                }

                $targetPath = BP . \str_replace('/', DS, $relativePath);
                $targetExists = \file_exists($targetPath);
                if ($this->isProtectedCoreUpdatePath($relativePath) && $targetExists) {
                    continue;
                }

                if (!$targetExists) {
                    $this->ensureDirectoryExists(\dirname($targetPath));
                    \copy($source, $targetPath);
                    $this->newFiles++;
                    $missingCount++;
                }
                continue;
            }

            if (!\is_dir($source)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $relativePath = $this->normalizeCoreUpdateRelativePath($path . '/' . $iterator->getSubPathname());

                if ($this->isExcludedCoreUpdatePath($relativePath)) {
                    continue;
                }

                $sourcePath = $item->getPathname();
                $targetPath = BP . \str_replace('/', DS, $relativePath);
                $targetExists = \file_exists($targetPath);

                if ($this->isProtectedCoreUpdatePath($relativePath) && $targetExists) {
                    continue;
                }

                if (!$targetExists) {
                    $this->ensureDirectoryExists(\dirname($targetPath));
                    \copy($sourcePath, $targetPath);
                    $this->newFiles++;
                    $missingCount++;
                }
            }
        }

        if ($missingCount > 0) {
            $this->printer->note(__('补缺：同步 %{1} 个本地缺失的核心文件', [$missingCount]));
        }
    }

    /**
     * 增量扫描：先比文件大小，再比 hash；hash 不可用时回退到 mtime
     */
    private function shouldSyncIncrementalFile(string $sourcePath, string $targetPath): bool
    {
        $sourceSize = filesize($sourcePath);
        $targetSize = filesize($targetPath);
        if ($sourceSize !== false && $targetSize !== false && $sourceSize !== $targetSize) {
            return true;
        }

        $sourceHash = $this->hashCoreUpdateFile($sourcePath);
        $targetHash = $this->hashCoreUpdateFile($targetPath);
        if ($sourceHash !== null && $targetHash !== null) {
            return !hash_equals($sourceHash, $targetHash);
        }

        $sourceMtime = filemtime($sourcePath);
        $targetMtime = filemtime($targetPath);

        return $sourceMtime !== false
            && $targetMtime !== false
            && $sourceMtime > $targetMtime;
    }

    private function hashCoreUpdateFile(string $filePath): ?string
    {
        $hash = @hash_file('xxh128', $filePath);
        if ($hash !== false) {
            return $hash;
        }

        $hash = @md5_file($filePath);
        if ($hash !== false) {
            return $hash;
        }

        return null;
    }
    
    /**
     * 强制模式：完全覆盖所有文件
     */
    private function copyAllFiles(string $tmpDir, array $allPaths): void
    {
        $processedPaths = 0;
        $failedPaths = 0;
        
        foreach ($allPaths as $path) {
            $normalizedPath = $this->normalizeCoreUpdateRelativePath($path);
            $source = $tmpDir . DS . \str_replace('/', DS, $normalizedPath);
            $target = BP . \str_replace('/', DS, $normalizedPath);

            if ($this->isExcludedCoreUpdatePath($normalizedPath)) {
                $this->skippedFiles++;
                $failedPaths++;
                continue;
            }

            if (\is_file($source)) {
                $this->printer->note(__('拷贝 %{1}...', [$normalizedPath]));
                if ($this->isProtectedCoreUpdatePath($normalizedPath) && \file_exists($target)) {
                    $this->skippedFiles++;
                    $this->printer->note(__('跳过受保护文件：%{1}', [$normalizedPath]));
                    $failedPaths++;
                    continue;
                }
                $this->ensureDirectoryExists(\dirname($target));
                if (\copy($source, $target)) {
                    if (\file_exists($target)) {
                        $this->updatedFiles++;
                    } else {
                        $this->newFiles++;
                    }
                    $this->printer->success(__('✓ %{1}', [$normalizedPath]));
                    $processedPaths++;
                } else {
                    $this->printer->warning(__('⚠ %{1} 处理失败', [$normalizedPath]));
                    $failedPaths++;
                }
                continue;
            }
            
            if (!\is_dir($source)) {
                $this->printer->warning(__('⚠ 源路径不存在: %{1}', [$normalizedPath]));
                $failedPaths++;
                continue;
            }
            
            $this->printer->note(__('拷贝 %{1}...', [$normalizedPath]));
            
            // 完全覆盖：拷贝所有文件
            if ($this->copyDirectoryFull($source, $target, $normalizedPath)) {
                $this->printer->success(__('✓ %{1}', [$normalizedPath]));
                $processedPaths++;
            } else {
                $this->printer->warning(__('⚠ %{1} 处理失败', [$normalizedPath]));
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
                
                if ($this->isProtectedCoreUpdatePath($logicalPath) && file_exists($targetPath)) {
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

    private function normalizeCoreUpdateRelativePath(string $relativePath): string
    {
        return trim(str_replace('\\', '/', $relativePath), '/');
    }

    private function isInCoreUpdatePath(string $relativePath, array $allowedPaths): bool
    {
        $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);

        foreach ($allowedPaths as $allowedPath) {
            if ($normalizedPath === $allowedPath || str_starts_with($normalizedPath, $allowedPath . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isProtectedCoreUpdatePath(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);

        return in_array($normalizedPath, self::CORE_UPDATE_PROTECTED_PATHS, true);
    }

    private function isExcludedCoreUpdatePath(string $relativePath): bool
    {
        $normalizedPath = $this->normalizeCoreUpdateRelativePath($relativePath);
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
                continue;
            }
            @chmod($path, 0666);
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function commandExists(string $command): bool
    {
        if ($this->isWindows) {
            $safeCommand = preg_replace('/[^a-zA-Z0-9_.-]/', '', $command) ?: $command;
            $whereExe = getenv('WINDIR') ?: 'C:\\Windows';
            $whereExe = rtrim($whereExe, "\\/") . '\\System32\\where.exe';

            if (is_file($whereExe)) {
                $output = [];
                exec('"' . $whereExe . '" ' . escapeshellarg($safeCommand) . ' 2>nul', $output, $returnCode);
                if ($returnCode === 0) {
                    foreach ($output as $line) {
                        $resolvedPath = trim($line);
                        if ($resolvedPath !== '' && is_file($resolvedPath)) {
                            $this->rememberResolvedWindowsCommand($safeCommand, $resolvedPath);
                            break;
                        }
                    }
                    return true;
                }
            }

            foreach ($this->windowsCommandCandidatePaths($safeCommand) as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $this->rememberResolvedWindowsCommand($safeCommand, $candidate);
                    return true;
                }
            }

            $output = [];
            exec("where {$safeCommand} 2>nul", $output, $returnCode);
            if ($returnCode === 0) {
                foreach ($output as $line) {
                    $resolvedPath = trim($line);
                    if ($resolvedPath !== '' && is_file($resolvedPath)) {
                        $this->rememberResolvedWindowsCommand($safeCommand, $resolvedPath);
                        break;
                    }
                }
            }
        } else {
            exec("which {$command} 2>/dev/null", $output, $returnCode);
        }
        return $returnCode === 0;
    }

    private function rememberResolvedWindowsCommand(string $command, string $path): void
    {
        if (strtolower($command) === 'git') {
            $this->gitExecutable = str_replace('"', '', $path);
            $this->gitCommand = '"' . str_replace('"', '', $path) . '"';
        }
    }

    /**
     * @param string[] $arguments
     */
    private function runGitCommand(string $workingDirectory, array $arguments, &$output): int
    {
        $output = [];
        $command = array_merge([$this->gitExecutable], $arguments);
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $workingDirectory,
            null,
            $this->isWindows ? ['bypass_shell' => true] : []
        );

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $code = proc_close($process);
            $combined = trim((string)$stdout . "\n" . (string)$stderr);
            $output = $combined !== '' ? preg_split('/\R/', $combined) ?: [] : [];
            return $code;
        }

        $escapedArgs = array_map('escapeshellarg', $arguments);
        exec(
            sprintf('cd %s && %s %s 2>&1', escapeshellarg($workingDirectory), $this->gitCommand, implode(' ', $escapedArgs)),
            $output,
            $code
        );
        return $code;
    }

    /**
     * @param string[] $arguments
     */
    private function runPhpCommand(array $arguments, int $timeoutSeconds, &$output): int
    {
        $output = [];
        $phpBin = PHP_BINARY ?: 'php';
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            array_merge([$phpBin], $arguments),
            $descriptorSpec,
            $pipes,
            BP,
            null,
            $this->isWindows ? ['bypass_shell' => true] : []
        );

        if (!is_resource($process)) {
            $escapedArgs = array_map('escapeshellarg', $arguments);
            exec('"' . $phpBin . '" ' . implode(' ', $escapedArgs) . ' 2>&1', $output, $code);
            return $code;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $exitCode = null;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if ((time() - $startedAt) >= $timeoutSeconds) {
                @proc_terminate($process);
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                $output = $this->splitProcessOutput($stdout, $stderr);
                $output[] = 'reflection:compile timed out after ' . $timeoutSeconds . ' seconds';
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return -1;
            }
            usleep(100000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        $output = $this->splitProcessOutput($stdout, $stderr);
        return ($exitCode !== null && $exitCode >= 0) ? $exitCode : $closeCode;
    }

    /**
     * @return string[]
     */
    private function splitProcessOutput(string $stdout, string $stderr): array
    {
        $combined = trim($stdout . "\n" . $stderr);
        return $combined !== '' ? preg_split('/\R/', $combined) ?: [] : [];
    }

    /**
     * @return string[]
     */
    private function windowsCommandCandidatePaths(string $command): array
    {
        $extensions = pathinfo($command, PATHINFO_EXTENSION) !== ''
            ? ['']
            : ['.exe', '.cmd', '.bat', '.com'];
        $directories = [
            getenv('WINDIR') ? rtrim((string)getenv('WINDIR'), "\\/") . '\\System32' : 'C:\\Windows\\System32',
            'C:\\Program Files\\Git\\cmd',
            'C:\\Program Files\\Git\\bin',
        ];

        $candidates = [];
        foreach ($directories as $directory) {
            foreach ($extensions as $extension) {
                $candidates[] = rtrim($directory, "\\/") . '\\' . $command . $extension;
            }
        }
        return $candidates;
    }
}
