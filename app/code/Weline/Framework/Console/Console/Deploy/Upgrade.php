<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Deploy;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Deploy\FlatStaticRuntimeFilesProviderInterface;
use Weline\Framework\Deploy\HostProcessPoolPolicy;
use Weline\Framework\Deploy\StaticPublishExclusion;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\PublicThemeNamespace;

class Upgrade extends CommandAbstract
{
    public const EVENT_STATIC_ASSET_TRANSFORM = 'Weline_Framework_Deploy::static_asset_transform';

    /** execute() $data：Mode\Set 已负责强失效时跳过日常 bump。 */
    public const DATA_SKIP_INVALIDATION = 'skip_invalidation';

    /** Module-level static copy process pool (d:m:se prod / deploy:upgrade). */
    public const ENV_STATIC_CONCURRENCY = 'WELINE_DEPLOY_STATIC_CONCURRENCY';
    /** Soft ceiling when ENV unset/auto — actual size from HostProcessPoolPolicy. */
    public const DEFAULT_STATIC_CONCURRENCY = 10;
    public const MAX_STATIC_CONCURRENCY = 32;

    /**
     * @var System
     */
    private System $system;

    /** 本轮是否写入了与目标不同的静态字节（树变更）。 */
    private bool $treeChanged = false;

    public function __construct(
        System $system,
        private readonly ServiceProviderRegistry $providerRegistry,
    ) {
        $this->system  = $system;
    }

    public function execute(array $args = [], array $data = [])
    {
        $this->treeChanged = false;
        $skipInvalidation = !empty($data[self::DATA_SKIP_INVALIDATION]);

        $modules    = Env::getInstance()->getActiveModules();
        $theme      = Env::getInstance()->getTheme();
        $staticRoot = DeployStagingSession::staticRoot();

        // 主题命名空间必须与 /static/ URL 前缀同源。`theme.path` 可能是绝对源码路径或
        // `Module::path` 标识；未归一化就把绝对路径当成了目录段，pub/static 下会因此
        // 长出 `Users/<name>/.../app/code/...` 与 `Weline_Theme::view/` 这类畸形树。
        $themeNamespace = PublicThemeNamespace::resolve((string)($theme['path'] ?? ''));
        $themeOverlayEnabled = $themeNamespace !== '';
        if (!$themeOverlayEnabled) {
            $this->printer->warning(
                __('主题命名空间无法归一化，已跳过主题域静态发布（扁平树不受影响）：')
                . (string)($theme['path'] ?? '')
            );
        }

        if (!is_dir($staticRoot) && !mkdir($staticRoot, 0775, true) && !is_dir($staticRoot)) {
            throw new \RuntimeException('Unable to create static deployment directory: ' . $staticRoot);
        }

        $staticOwner = @fileowner($staticRoot);
        $staticGroup = @filegroup($staticRoot);
        $applyPermissions = static function (string $path) use ($staticOwner, $staticGroup): void {
            if (is_link($path) || (!file_exists($path) && !is_dir($path))) {
                return;
            }
            @chmod($path, is_dir($path) ? 0775 : 0664);
            if ($staticOwner !== false && function_exists('chown')) {
                @chown($path, $staticOwner);
            }
            if ($staticGroup !== false && function_exists('chgrp')) {
                @chgrp($path, $staticGroup);
            }
        };
        $normalizePermissions = static function (string $path) use ($applyPermissions): void {
            if (!is_dir($path)) {
                $applyPermissions($path);
                return;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if (!$item->isLink()) {
                    $applyPermissions($item->getPathname());
                }
            }
            $applyPermissions($path);
        };

        $themeAssetExtensions = [
            'css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif',
            'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt',
            'xml', 'webmanifest', 'wasm', 'mp4', 'webm', 'mp3', 'ogg', 'wav',
        ];

        $moduleJobs = [];
        foreach ($modules as $module) {
            $name = (string)($module['name'] ?? '');
            if ($name === '' || empty($module['base_path'])) {
                continue;
            }
            $moduleJobs[] = [
                'name' => $name,
                'path' => (string)($module['path'] ?? ''),
                'base_path' => (string)$module['base_path'],
                'static_root' => $staticRoot,
                'theme_namespace' => $themeNamespace,
                'theme_overlay_enabled' => $themeOverlayEnabled,
                'theme_asset_extensions' => $themeAssetExtensions,
                'dev' => (bool)DEV,
            ];
        }

        $this->publishModuleJobs($moduleJobs);

        // Provider 白名单降级为补充/兼容路径：与整树幂等双写，不再承担防 404 主路径。
        $this->publishFlatStaticRuntimeFiles($modules);
        $normalizePermissions($staticRoot);
        $this->printer->success('静态文件部署完毕！');

        if (!$skipInvalidation) {
            /** @var DeployFpcInvalidation $invalidation */
            $invalidation = ObjectManager::getInstance(DeployFpcInvalidation::class);
            $result = $invalidation->afterUpgrade($this->treeChanged);
            if (!empty($result['skipped'])) {
                $this->printer->note(__('静态树无变更，跳过 FPC deploy ns bump（空跑勿 thrash）'));
            } else {
                $this->printer->note(__('已 bump global/storefront/deploy（日常 Upgrade 优先 bump）'));
            }
        }

        // Theme 等模块可在此清布局固化物（theme-layout-entities）；核心不硬绑 Theme。
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $afterPayload = [
            'tree_changed' => $this->treeChanged,
            'skip_invalidation' => $skipInvalidation,
            'args' => $args,
            'data' => $data,
        ];
        $eventsManager->dispatch('Weline_Framework_Deploy::upgrade_after', $afterPayload);
    }

    public function resolveStaticConcurrency(?int $override = null): int
    {
        return $this->resolveStaticConcurrencyDecision($override)['concurrency'];
    }

    /**
     * @return array{concurrency:int, source:string, cpus:int, mem_avail_mb:int|null}
     */
    public function resolveStaticConcurrencyDecision(?int $override = null): array
    {
        return (new HostProcessPoolPolicy())->resolve(
            $override,
            HostProcessPoolPolicy::envRaw(self::ENV_STATIC_CONCURRENCY),
            self::DEFAULT_STATIC_CONCURRENCY,
            self::MAX_STATIC_CONCURRENCY,
        );
    }

    /**
     * @param list<array<string,mixed>> $jobs
     */
    private function publishModuleJobs(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }
        $decision = $this->resolveStaticConcurrencyDecision();
        $concurrency = (int)$decision['concurrency'];
        $wls = trim((string)(getenv('WLS_WORKER_ID') ?: ($_ENV['WLS_WORKER_ID'] ?? '')));
        if ($concurrency <= 1 || count($jobs) <= 1 || $wls !== '') {
            if ($wls === '' && count($jobs) > 1) {
                $this->printer->note(sprintf(
                    '%s pool=%d modules=%d source=%s cpus=%d mem_avail_mb=%s',
                    (string)__('静态资源部署进程池'),
                    $concurrency,
                    count($jobs),
                    (string)$decision['source'],
                    (int)$decision['cpus'],
                    $decision['mem_avail_mb'] === null ? 'n/a' : (string)(int)$decision['mem_avail_mb'],
                ));
            }
            foreach ($jobs as $job) {
                $result = $this->publishOneModuleJob($job);
                if (!empty($result['tree_changed'])) {
                    $this->treeChanged = true;
                }
                if (!empty($result['noted'])) {
                    $this->printer->note((string)$result['name'] . '...');
                }
            }

            return;
        }

        $this->printer->note(sprintf(
            '%s pool=%d modules=%d source=%s cpus=%d mem_avail_mb=%s',
            (string)__('静态资源部署进程池'),
            $concurrency,
            count($jobs),
            (string)$decision['source'],
            (int)$decision['cpus'],
            $decision['mem_avail_mb'] === null ? 'n/a' : (string)(int)$decision['mem_avail_mb'],
        ));
        // __DIR__ = …/Framework/Console/Console/Deploy → Framework root = dirname×3
        $script = dirname(__DIR__, 3) . '/Deploy/bin/publish-module-static-job.php';
        if (!is_file($script)) {
            throw new \RuntimeException('deploy_static_worker_script_missing:' . $script);
        }
        $jobRoot = rtrim(sys_get_temp_dir(), '/\\') . '/weline-deploy-static-' . bin2hex(random_bytes(6));
        if (!mkdir($jobRoot, 0700, true) && !is_dir($jobRoot)) {
            throw new \RuntimeException('deploy_static_job_root_failed');
        }
        $phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $queue = array_values($jobs);
        /** @var array<string, array{proc:resource,pipes:array<int,resource>,name:string}> $running */
        $running = [];
        $errors = [];
        $done = 0;
        $total = count($jobs);
        try {
            while ($queue !== [] || $running !== []) {
                while ($queue !== [] && count($running) < $concurrency) {
                    $job = array_shift($queue);
                    if ($job === null) {
                        break;
                    }
                    $name = (string)($job['name'] ?? 'module');
                    $jobFile = $jobRoot . '/job-' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) . '-' . bin2hex(random_bytes(3)) . '.json';
                    file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                    $proc = proc_open([$phpBin, $script, $jobFile], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                    if (!is_resource($proc)) {
                        $errors[] = 'deploy_static_spawn_failed:' . $name;
                        break 2;
                    }
                    fclose($pipes[0]);
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                    $running[$name . ':' . bin2hex(random_bytes(2))] = [
                        'proc' => $proc,
                        'pipes' => $pipes,
                        'name' => $name,
                    ];
                }
                foreach ($running as $key => $state) {
                    $status = proc_get_status($state['proc']);
                    if (!empty($status['running'])) {
                        continue;
                    }
                    $stdout = stream_get_contents($state['pipes'][1]);
                    $stderr = stream_get_contents($state['pipes'][2]);
                    foreach ([1, 2] as $fd) {
                        if (is_resource($state['pipes'][$fd])) {
                            fclose($state['pipes'][$fd]);
                        }
                    }
                    $exit = proc_close($state['proc']);
                    unset($running[$key]);
                    ++$done;
                    $this->printer->progressBar(
                        $done,
                        max(1, $total),
                        sprintf('%s %s', (string)__('静态资源部署'), $state['name']),
                        24,
                    );
                    if ($exit !== 0) {
                        $snippet = trim((string)$stderr);
                        if ($snippet === '') {
                            $snippet = trim((string)$stdout);
                        }
                        $errors[] = 'deploy_static_worker_failed:' . $state['name']
                            . ':exit=' . $exit
                            . ($snippet !== '' ? ':' . $snippet : '');
                        continue;
                    }
                    $payload = json_decode(trim((string)$stdout), true);
                    if (is_array($payload) && !empty($payload['tree_changed'])) {
                        $this->treeChanged = true;
                    }
                }
                if ($running !== []) {
                    usleep(5000);
                }
            }
            if ($errors !== []) {
                $this->printer->finishProgressLine();
                throw new \RuntimeException(implode(' | ', $errors));
            }
        } finally {
            foreach ($running as $state) {
                if (is_resource($state['proc'])) {
                    @proc_terminate($state['proc']);
                    @proc_close($state['proc']);
                }
            }
            $this->removeJobTree($jobRoot);
        }
    }

    /**
     * One-module static publish (overlay + flat + theme assets). Used by process workers.
     *
     * @param array<string,mixed> $job
     * @return array{name:string,tree_changed:bool,noted:bool}
     */
    public function publishOneModuleJob(array $job): array
    {
        $name = (string)($job['name'] ?? '');
        $basePath = (string)($job['base_path'] ?? '');
        $staticRoot = (string)($job['static_root'] ?? DeployStagingSession::staticRoot());
        $themeNamespace = (string)($job['theme_namespace'] ?? '');
        $themeOverlayEnabled = !empty($job['theme_overlay_enabled']) && $themeNamespace !== '';
        $themeAssetExtensions = is_array($job['theme_asset_extensions'] ?? null)
            ? $job['theme_asset_extensions']
            : [];
        $dev = array_key_exists('dev', $job) ? (bool)$job['dev'] : (bool)DEV;
        $modulePath = (string)($job['path'] ?? '');
        $moduleViewDir = ($dev ? $modulePath : str_replace('_', DS, $name) . DS) . DataInterface::dir;
        $staticSource = $this->resolveModuleStaticsSourceDir($basePath);
        $themeSource = $basePath . DataInterface::dir . DS . 'theme';
        if ($themeSource !== '' && !is_dir($themeSource)) {
            $altTheme = rtrim($basePath, '\\/') . DS . 'View' . DS . 'theme';
            if (is_dir($altTheme)) {
                $themeSource = $altTheme;
            }
        }
        $noted = is_dir($staticSource) || is_dir($themeSource);
        $beforeChanged = $this->treeChanged;

        if ($themeOverlayEnabled && is_dir($staticSource)) {
            $staticTarget = $staticRoot . DS . $themeNamespace . DS . $moduleViewDir
                . DS . DataInterface::dir_type_STATICS;
            if (!is_dir($staticTarget) && !mkdir($staticTarget, 0775, true) && !is_dir($staticTarget)) {
                throw new \RuntimeException('Unable to create module static directory: ' . $staticTarget);
            }
            $this->recursiveCopy($staticSource, $staticTarget);
        }
        if (is_dir($staticSource)) {
            $this->publishModuleFlatStatics($name, $staticSource, $staticRoot);
        }
        if ($themeOverlayEnabled && is_dir($themeSource)) {
            $themeTarget = $staticRoot . DS . $themeNamespace . DS . $moduleViewDir . DS . 'theme';
            $this->publishThemeAssets($themeSource, $themeTarget, $themeAssetExtensions);
        }

        return [
            'name' => $name,
            // Worker process starts with treeChanged=false; parent serial path already mutated $this->treeChanged.
            'tree_changed' => $this->treeChanged && !$beforeChanged,
            'noted' => $noted,
        ];
    }

    private function removeJobTree(string $root): void
    {
        if ($root === '' || !is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($root);
    }

    /**
     * @param list<string> $themeAssetExtensions
     */
    private function publishThemeAssets(string $source, string $target, array $themeAssetExtensions): int
    {
        if (!is_dir($source)) {
            return 0;
        }

        $published = 0;
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                continue;
            }
            $extension = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($extension, $themeAssetExtensions, true)) {
                continue;
            }
            $sourcePath = $item->getPathname();
            $relative   = ltrim(substr($sourcePath, strlen($source)), '/\\');
            $targetPath = $target . DS . str_replace(['/', '\\'], DS, $relative);
            $targetDir  = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Unable to create theme asset directory: ' . $targetDir);
            }
            $this->publishFile($sourcePath, $targetPath);
            $published++;
        }

        return $published;
    }

    /**
     * 将模块 view/statics 整树扁平铺到 pub/static/{Vendor}/{Module}/（相对 statics 根）。
     * 与 PROD resolveStaticPath / Module:: 约定对齐；无 statics 目录时 no-op。
     */
    private function publishModuleFlatStatics(string $moduleName, string $staticsSource, string $staticRoot): void
    {
        if (!is_dir($staticsSource)) {
            return;
        }

        $flatTarget = $this->resolveFlatStaticModuleRoot($moduleName, $staticRoot);
        if ($flatTarget === null) {
            return;
        }

        if (!is_dir($flatTarget) && !mkdir($flatTarget, 0775, true) && !is_dir($flatTarget)) {
            throw new \RuntimeException('Unable to create module flat static directory: ' . $flatTarget);
        }

        $this->recursiveCopy($staticsSource, $flatTarget);
    }

    /**
     * Convention is lowercase `view/statics`. Framework git tree stores them under
     * PSR-4 `View/statics`; on case-sensitive Linux those diverge — prefer
     * lowercase when present, else fall back to `View`.
     */
    private function resolveModuleStaticsSourceDir(string $moduleBasePath): string
    {
        $base = rtrim($moduleBasePath, '\\/');
        foreach (['view', 'View'] as $viewDir) {
            $candidate = $base . DS . $viewDir . DS . DataInterface::dir_type_STATICS;
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $base . DS . DataInterface::dir . DS . DataInterface::dir_type_STATICS;
    }

    /**
     * Vendor_Module → {staticRoot}/{Vendor}/{Module}；非法名返回 null（不污染 static 根）。
     */
    private function resolveFlatStaticModuleRoot(string $moduleName, string $staticRoot): ?string
    {
        $moduleParts = explode('_', $moduleName, 2);
        if (count($moduleParts) !== 2 || $moduleParts[0] === '' || $moduleParts[1] === '') {
            return null;
        }

        return rtrim($staticRoot, '\\/') . \DS . $moduleParts[0] . \DS . $moduleParts[1];
    }

    /**
     * 兼容桥：deploy.flat_static.* Provider 白名单文件幂等写入扁平树（可降级为补充路径）。
     */
    private function publishFlatStaticRuntimeFiles(array $modules): void
    {
        foreach ($this->flatStaticRuntimeFiles() as $moduleName => $relativeFiles) {
            if (!isset($modules[$moduleName]) || empty($modules[$moduleName]['base_path'])) {
                continue;
            }

            foreach ($relativeFiles as $relativeFile) {
                $this->copyFlatStaticRuntimeFile($moduleName, (string)$modules[$moduleName]['base_path'], $relativeFile);
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function flatStaticRuntimeFiles(): array
    {
        $files = [];
        foreach ($this->providerRegistry->implementationsWithPrefix('deploy.flat_static.') as $implementation) {
            try {
                $provider = ObjectManager::getInstance($implementation);
            } catch (\Throwable) {
                continue;
            }
            if (!$provider instanceof FlatStaticRuntimeFilesProviderInterface) {
                continue;
            }

            $moduleName = \trim($provider->moduleName());
            if ($moduleName === '') {
                continue;
            }
            foreach ($provider->relativeFiles() as $relativeFile) {
                $relativeFile = \trim((string)$relativeFile);
                if ($relativeFile !== '') {
                    $files[$moduleName][$relativeFile] = $relativeFile;
                }
            }
        }

        return \array_map('array_values', $files);
    }

    private function copyFlatStaticRuntimeFile(string $moduleName, string $moduleBasePath, string $relativeFile): void
    {
        $staticsRoot = $this->resolveModuleStaticsSourceDir($moduleBasePath);
        $sourceFile = rtrim($staticsRoot, '\\/') . DS
            . str_replace(['/', '\\'], DS, $relativeFile);
        if (!is_file($sourceFile)) {
            return;
        }

        $flatRoot = $this->resolveFlatStaticModuleRoot($moduleName, DeployStagingSession::staticRoot());
        if ($flatRoot === null) {
            return;
        }

        $targetFile = $flatRoot . DS . str_replace(['/', '\\'], DS, $relativeFile);
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return;
        }

        $this->publishFile($sourceFile, $targetFile);
    }

    /**
     * 递归复制目录（跨平台兼容）。
     *
     * `pub/static` 在 Web 根之下，故发布树只许含运行时资源：文档、测试与工具链元数据
     * 按 StaticPublishExclusion 剪枝（目录命中即整棵子树剪枝），避免文档被直接读取。
     *
     * @param string $source 源目录
     * @param string $dest 目标目录
     * @return void
     */
    private function recursiveCopy(string $source, string $dest): void
    {
        // 确保源目录存在
        if (!is_dir($source)) {
            return;
        }

        // 创建目标目录的父目录
        $parent_dest = dirname($dest);
        if (!is_dir($parent_dest)) {
            mkdir($parent_dest, 0775, true);
        }

        // 遍历源目录（排除非运行时资源）
        $publishable = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            static function (\SplFileInfo $item) use ($source): bool {
                $relativePath = ltrim(substr($item->getPathname(), strlen($source)), '/\\');

                return !StaticPublishExclusion::isExcluded($relativePath, $item->isDir());
            }
        );
        $iterator = new \RecursiveIteratorIterator($publishable, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            // 计算相对路径
            $relativePath = substr($item->getPathname(), strlen($source));
            $destPath = $dest . $relativePath;

            if ($item->isDir()) {
                // 创建目录
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0775, true);
                }
            } else {
                // 复制文件
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0775, true);
                }
                $this->publishFile($item->getPathname(), $destPath);
            }
        }
    }

    /**
     * Copy one file to the static deploy target, allowing observers to transform content.
     */
    private function publishFile(string $sourcePath, string $targetPath): void
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            $existed = is_file($targetPath);
            if (!@copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Unable to publish static asset: ' . $sourcePath);
            }
            if (!$existed) {
                $this->treeChanged = true;
            }

            return;
        }

        $payload = new DataObject([
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
            'extension' => $extension,
            'content' => $raw,
            'transformed' => false,
        ]);

        try {
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
            $events->dispatch(self::EVENT_STATIC_ASSET_TRANSFORM, $payload);
        } catch (\Throwable) {
            // Transform hooks must not block deploy; fall back to original bytes.
        }

        $content = (string)$payload->getData('content');
        if (is_file($targetPath)) {
            $existing = @file_get_contents($targetPath);
            if ($existing !== false && $existing === $content) {
                return;
            }
        }
        if (@file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException('Unable to write static asset: ' . $targetPath);
        }
        $this->treeChanged = true;
    }

    public function tip(): string
    {
        return '静态资源同步更新。';
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
