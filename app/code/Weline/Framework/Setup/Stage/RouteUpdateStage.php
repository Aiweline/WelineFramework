<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Router\Helper\Data as RouterHelper;

/**
 * 路由更新阶段
 * 
 * 职责：管理路由的批量更新，确保所有路由在内存中收集完成后一次性写入文件
 * 
 * @package Weline\Framework\Setup\Stage
 */
class RouteUpdateStage extends AbstractStage
{
    /**
     * @var RouterHelper 路由助手
     */
    private RouterHelper $routerHelper;
    
    /**
     * @var array 路由文件路径列表
     */
    private array $routerFilePaths = [];
    
    /**
     * @var array 路由数据 [文件路径 => 路由数组]
     */
    private array $routeData = [];
    
    /**
     * 原始路由文件备份路径（磁盘临时副本，避免把整表路由再拷进内存）。
     * @var array<string, string> livePath => backupTempPath
     */
    private array $originalRouteBackupFiles = [];
    
    /**
     * @var array 需要清除的模块列表
     */
    private array $modulesToClear = [];

    /**
     * 本轮声明应重扫的模块（seed_partial / scoped -m 路径）：
     * commit 前须证明每个「磁盘有 PC 控制器」的声明模块在新缓冲里有条目。
     * @var list<string>
     */
    private array $declaredChangedModules = [];

    /** 指纹全员命中：不写路由文件、不 flush batch */
    private bool $skipWrite = false;
    
    /**
     * @param RouterHelper $routerHelper
     */
    public function __construct(RouterHelper $routerHelper)
    {
        $this->routerHelper = $routerHelper;
        $this->routerFilePaths = Env::router_files_PATH;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'route_update';
    }
    
    /**
     * @inheritDoc
     */
    public function prepare(array $context = []): void
    {
        // 如果已经准备过，跳过（避免重复准备导致路由数据被清空）
        if ($this->prepared) {
            return;
        }
        // 当 setup:upgrade --stage=xxx 指定了不含 route_update 的阶段时，不触碰路由，避免 enableBatchMode 清空缓冲后不 flush 导致路由丢失
        if (!empty($context['skip_route_stage'])) {
            $this->prepared = true;
            $this->committed = true;
            $this->clearErrors();
            return;
        }
        // 如果指定了需要清除的模块，记录它们
        if (isset($context['modules_to_clear']) && is_array($context['modules_to_clear'])) {
            $this->modulesToClear = $context['modules_to_clear'];
        }

        $seedPartial = !empty($context['seed_partial']) && !empty($this->modulesToClear);
        $isPartial = !empty($this->modulesToClear) && !$seedPartial;
        // 声明式完整性断言的期望集：prepare 时记下本轮被声明要重扫的模块，
        // 之后 modulesToClear 会被清空（seed_partial），不能依赖它做 commit 校验。
        if ($seedPartial || $isPartial) {
            $this->declaredChangedModules = array_values(array_unique($this->modulesToClear));
        }

        // 路由文件批量缓冲 + ACL 事件 defer：扫描阶段只做内存收集，commit 时一次落盘/落库
        /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
        $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Module\Helper\Data::class
        );
        $moduleHelper->enableDeferControllerAttributes();
        // 扫描失败记录（M3）：静默 continue 处留痕，commit 前读取并否决。
        $moduleHelper->enableRouteScanFailureRecording();
        \Weline\Framework\Module\Handle::resetBatchRouteProgress();

        if ($seedPartial) {
            // 指纹部分命中：batch + 磁盘种子 + 内存清除变更模块，禁止空 batch 跳扫（P0）
            $this->backupOriginalRoutes();
            $this->routerHelper->enableBatchMode();
            $this->routerHelper->seedBatchFromDisk();
            $this->clearModuleRoutersInMemory();
            // commit 走全量 flush；modulesToClear 清空以免误判增量
            $this->modulesToClear = [];
        } elseif ($isPartial) {
            // 增量模式：确保不处于批量模式，避免仅写入内存不落盘
            if ($this->routerHelper->isBatchMode()) {
                // 将当前批量缓存（如果有）先落盘并退出批量模式
                $this->routerHelper->flushBatchRouters();
            }

            // 增量模式：不启用批量模式，避免覆盖整个路由文件
            // 先备份原始路由数据，便于出现异常时回滚
            $this->backupOriginalRoutes();

            // 直接在文件层面清理指定模块的旧路由
            foreach ($this->routerFilePaths as $path) {
                try {
                    $this->routerHelper->clearModuleRouters($path, $this->modulesToClear);
                } catch (\Exception $e) {
                    throw new Exception(__('清理模块 %{1} 路由失败：%{2}', [
                        implode(', ', $this->modulesToClear),
                        $e->getMessage(),
                    ]), 0, $e);
                }
            }
        } else {
            // 全量模式：磁盘备份 + seed 现有路由，再由收集覆盖变更模块。
            $this->backupOriginalRoutes();
            $this->routerHelper->enableBatchMode();
            $this->routerHelper->seedBatchFromDisk();
        }
        
        $this->prepared = true;
        $this->clearErrors();
    }
    
    /**
     * 将现有路由文件拷到临时目录（不 require 进内存）。
     */
    private function backupOriginalRoutes(): void
    {
        $this->discardOriginalRouteBackups();
        $dir = \rtrim(\sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'weline_route_bak_'
            . \getmypid();
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new Exception(__('无法创建路由回滚临时目录：%{1}', [$dir]));
        }

        foreach ($this->routerFilePaths as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $bak = $dir . DIRECTORY_SEPARATOR . \md5($path) . '.php';
            if (!@\copy($path, $bak)) {
                throw new Exception(__('备份路由文件失败：%{1}', [$path]));
            }
            $this->originalRouteBackupFiles[$path] = $bak;
        }
    }

    private function discardOriginalRouteBackups(): void
    {
        $dirs = [];
        foreach ($this->originalRouteBackupFiles as $bak) {
            if (\is_string($bak) && $bak !== '' && \is_file($bak)) {
                $dirs[\dirname($bak)] = true;
                @\unlink($bak);
            }
        }
        $this->originalRouteBackupFiles = [];
        foreach (\array_keys($dirs) as $dir) {
            if (\is_dir($dir)) {
                @\rmdir($dir);
            }
        }
    }
    
    /**
     * 在内存中清除指定模块的路由
     * 
     * @return void
     */
    private function clearModuleRoutersInMemory(): void
    {
        foreach ($this->routerFilePaths as $path) {
            // 使用路由助手的清除方法（如果支持批量模式）
            // 否则直接操作批量路由缓存
            try {
                $this->routerHelper->clearModuleRouters($path, $this->modulesToClear);
            } catch (\Exception $e) {
                // 如果清除方法不支持批量模式，手动清除
                $routers = $this->routerHelper->getBatchRouters($path);
                
                // 清除指定模块的路由
                $cleared = false;
                foreach ($routers as $routerKey => $router) {
                    $routerModule = $this->extractModuleFromRouter($router);
                    if ($routerModule && in_array($routerModule, $this->modulesToClear, true)) {
                        unset($routers[$routerKey]);
                        $cleared = true;
                    }
                }
                
                if ($cleared) {
                    // 更新批量路由缓存
                    $reflection = new \ReflectionClass($this->routerHelper);
                    $property = $reflection->getProperty('batchRouters');
                    $property->setAccessible(true);
                    $batchRouters = $property->getValue($this->routerHelper);
                    $batchRouters[$path] = $routers;
                    $property->setValue($this->routerHelper, $batchRouters);
                }
            }
        }
    }
    
    /**
     * 从路由数据中提取模块名
     * 
     * @param mixed $router 路由数据
     * @return string|null
     */
    private function extractModuleFromRouter($router): ?string
    {
        if (!is_array($router)) {
            return null;
        }
        
        // 直接包含 module 键
        if (isset($router['module'])) {
            return $router['module'];
        }
        
        // 或者包含在 rule 键中
        if (isset($router['rule']) && is_array($router['rule']) && isset($router['rule']['module'])) {
            return $router['rule']['module'];
        }
        
        return null;
    }

    /**
     * 增量 --route：磁盘清理后若重收集失败，会留下整模块空路由（店面 404）。
     * 清理前有条目的模块，提交前必须已写回至少 1 条；否则回滚备份并失败关闭。
     *
     * @param list<string> $moduleNames
     * @param callable(string):void $note
     */
    private function assertPartialClearedModulesRepopulated(array $moduleNames, callable $note): void
    {
        $emptyAfter = [];
        foreach ($moduleNames as $moduleName) {
            $moduleName = \trim((string)$moduleName);
            if ($moduleName === '') {
                continue;
            }
            $before = $this->countModuleRoutesInBackupFiles($moduleName);
            if ($before < 1) {
                continue;
            }
            $after = $this->countModuleRoutesOnDisk($moduleName);
            if ($after < 1) {
                $emptyAfter[] = $moduleName . '(before=' . $before . ')';
            }
        }
        if ($emptyAfter === []) {
            return;
        }

        $note(__('   - route_update：增量重收集后路由为空，正在回滚：%{1}', [\implode(', ', $emptyAfter)]));
        $this->rollback();
        throw new Exception(__(
            '增量路由更新后模块路由仍为空（已回滚）：%{1}。请重跑 php bin/w setup:upgrade -m <Module> --route',
            [\implode(', ', $emptyAfter)]
        ));
    }

    /**
     * M3 · 扫描失败必须发声：registerModuleRouter 里类加载失败/解析为空的静默
     * continue 已记录到 Module\Helper\Data；commit 前读取并否决——只要某模块
     * 「有 Controller 目录且本轮全部候选类都扫描失败」，其路由必然清零，正是
     * 02:47 店面 404 的根因链。失败即回滚、指纹不落（DEV/CI 抛错，prod 同样抛错
     * ——路由产物是持久文件，降级写坏表比中断更危险）。
     */
    private function assertNoRouteScanFailuresRecorded(
        \Weline\Framework\Module\Helper\Data $moduleHelper,
        callable $note
    ): void {
        $failures = $moduleHelper->getRouteScanFailures();
        $moduleHelper->clearRouteScanFailures();
        if ($failures === []) {
            return;
        }

        $byModule = [];
        foreach ($failures as $failure) {
            $moduleName = (string)($failure['module'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $byModule[$moduleName][] = sprintf('%s (%s)', (string)($failure['class'] ?? '?'), (string)($failure['kind'] ?? '?'));
        }
        // 只否决「致命」模块：声明重扫集内、或清理前磁盘有条目、现在整模块候选全失败。
        // batch 模式（全量 / seed_partial）下未重扫模块的旧条目来自磁盘种子不会丢，
        // 其扫描失败仅记 warning；增量模式按文件即时写，任何记录都可能是致命。
        $declared = array_fill_keys($this->declaredChangedModules, true);
        $fatal = [];
        foreach ($byModule as $moduleName => $details) {
            $isDeclared = isset($declared[$moduleName]);
            $hadRoutesBefore = $this->countModuleRoutesInBackupFiles($moduleName) > 0;
            $willRescan = $this->routerHelper->isBatchMode()
                ? ($isDeclared || $hadRoutesBefore)
                : true;
            if (!$willRescan) {
                // 非本轮重扫的模块：其旧路由仍整批保留在 seed 缓冲里，不构成丢路由。
                w_log_warning(__(
                    '路由扫描存在失败记录（非本轮重扫模块，旧路由保留）：%1 — %2',
                    [$moduleName, implode('; ', array_slice($details, 0, 5))]
                ), [], 'setup/route_integrity.log');
                continue;
            }
            $fatal[$moduleName] = $details;
        }
        if ($fatal === []) {
            $note(__('   - route_update：扫描失败记录均属于未重扫模块，旧路由保留，放行'));
            return;
        }

        $summary = [];
        foreach ($fatal as $moduleName => $details) {
            $summary[] = $moduleName . ': ' . implode('; ', array_slice($details, 0, 5));
        }
        $note(__('   - route_update：检测到路由扫描失败，正在回滚：%{1}', [\implode(' | ', $summary)]));
        w_log_warning(__(
            'route_update 提交被否决：路由扫描失败模块 %1（已回滚，指纹不落）',
            [\implode(', ', array_keys($fatal))]
        ), [], 'setup/route_integrity.log');
        $this->rollback();
        throw new Exception(__(
            '路由完整性断言失败：本轮重扫模块的控制器在扫描期加载/解析失败（已回滚，旧路由保留、指纹未写入）：%{1}。'
            . '详见 var/log/setup/route_integrity.log',
            [\implode(' | ', $summary)]
        ));
    }

    /**
     * 声明式完整性断言（根治「changed 模块静默零产出仍 commit + 落指纹」）：
     * 本轮被声明重扫的每个模块，只要磁盘上有可注册的 PC 控制器目录，
     * 就必须在新缓冲（batch 未 flush）或新磁盘文件（已 flush / 增量即时写）里
     * 至少出现 1 条路由；否则视为收集器吞掉了该模块 → 拒绝提交、回滚备份、
     * 抛错（上层 catch 后不落指纹）。
     *
     * @param callable(string):void $note
     */
    private function assertDeclaredChangedModulesPresent(callable $note): void
    {
        if ($this->declaredChangedModules === []) {
            return;
        }
        $modules = $this->loadModulesForCompletenessAssert();
        $missing = [];
        foreach ($this->declaredChangedModules as $moduleName) {
            $moduleName = \trim((string)$moduleName);
            if ($moduleName === '' || !isset($modules[$moduleName]) || !is_array($modules[$moduleName])) {
                continue;
            }
            if ((int)($modules[$moduleName]['status'] ?? 1) !== 1) {
                continue;
            }
            $basePath = \rtrim((string)($modules[$moduleName]['base_path'] ?? ''), DIRECTORY_SEPARATOR);
            $pcDir = $basePath . DIRECTORY_SEPARATOR . \Weline\Framework\Module\Handle::pc_DIR;
            if ($basePath === '' || !\is_dir($pcDir)) {
                continue;
            }
            // 只有 0 字节空壳控制器文件的模块（如 AiKnowledge Controller/Backend/*，创建即空）
            // 不可能产出路由：不能要求它「至少 1 条」，否则会拖死整次 setup:upgrade。
            if (!$this->dirHasNonEmptyPhpFile($pcDir)) {
                continue;
            }
            $count = $this->routerHelper->isBatchMode()
                ? $this->countModuleRoutesInBatch($moduleName)
                : $this->countModuleRoutesOnDisk($moduleName);
            if ($count < 1) {
                $missing[] = $moduleName;
            }
        }
        $this->declaredChangedModules = [];
        if ($missing === []) {
            return;
        }

        $note(__('   - route_update：声明重扫的模块在路由产物中缺失，正在回滚：%{1}', [\implode(', ', $missing)]));
        w_log_warning(__(
            'route_update 完整性断言失败：模块 %1 有 Controller 目录却零路由条目（已回滚，指纹不落）',
            [\implode(', ', $missing)]
        ), [], 'setup/route_integrity.log');
        $this->rollback();
        throw new Exception(__(
            '路由完整性断言失败：本轮声明重扫的模块未产出任何路由条目（已回滚，旧路由保留、指纹未写入）：%{1}。'
            . '请用 php bin/w setup:upgrade -m <Module> --route 复现并查看 setup/route_integrity.log',
            [\implode(', ', $missing)]
        ));
    }

    /**
     * 断言用模块表：优先内存 Handle，CLI/独立进程退回 app/etc/modules.php。
     *
     * @return array<string, mixed>
     */
    private function loadModulesForCompletenessAssert(): array
    {
        try {
            /** @var \Weline\Framework\Module\Handle $handle */
            $handle = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Module\Handle::class);
            $modules = $handle->getModules();
            if (\is_array($modules) && $modules !== []) {
                return $modules;
            }
        } catch (\Throwable) {
            // fall through to disk registry below
        }
        $file = \APP_ETC_PATH . 'modules.php';
        if (\is_file($file)) {
            $modules = require $file;
            if (\is_array($modules)) {
                return $modules;
            }
        }
        return [];
    }

    /**
     * batch 缓冲模式下统计某模块在全部路由文件缓冲中的条目数（含 module / rule.module 两种形态）。
     */
    private function countModuleRoutesInBatch(string $moduleName): int
    {
        $count = 0;
        foreach ($this->routerFilePaths as $path) {
            foreach ($this->routerHelper->getBatchRouters((string)$path) as $router) {
                $extracted = $this->extractModuleFromRouter($router);
                if ($extracted === $moduleName) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 目录下是否存在非空 .php 文件（递归）。用于区分「真的写了控制器」与「只有空壳占位」。
     * 探测自身失败时返回 true，保持原有严格性（宁可拦，不要漏）。
     */
    private function dirHasNonEmptyPhpFile(string $dir): bool
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if (\strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                if ($file->getSize() > 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function countModuleRoutesOnDisk(string $moduleName): int
    {
        $count = 0;
        foreach ($this->routerFilePaths as $path) {
            if (!\is_string($path) || $path === '' || !\is_file($path)) {
                continue;
            }
            $count += $this->countModuleRoutesInPhpReturnFile($path, $moduleName);
        }

        return $count;
    }

    private function countModuleRoutesInBackupFiles(string $moduleName): int
    {
        $count = 0;
        foreach ($this->originalRouteBackupFiles as $bak) {
            if (!\is_string($bak) || $bak === '' || !\is_file($bak)) {
                continue;
            }
            $count += $this->countModuleRoutesInPhpReturnFile($bak, $moduleName);
        }

        return $count;
    }

    private function countModuleRoutesInPhpReturnFile(string $path, string $moduleName): int
    {
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($path, true);
        }
        \clearstatcache(true, $path);
        // Backup/live router PHP return arrays — process gate (CLI may forget after rewrite).
        \Weline\Framework\Compilation\CompiledPhpRegistry::forgetForTests($path);
        $routers = \Weline\Framework\Compilation\CompiledPhpRegistry::load($path);
        if (!\is_array($routers)) {
            return 0;
        }
        $count = 0;
        foreach ($routers as $router) {
            if ($this->extractModuleFromRouter($router) === $moduleName) {
                $count++;
            }
        }

        return $count;
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }
        
        // 验证路由数据格式
        // 这里可以添加更详细的验证逻辑
        
        return true;
    }
    
    private static function cleanupStaleRouterTempFiles(): void
    {
        $dirs = [];
        foreach ((array) \Weline\Framework\App\Env::router_files_PATH as $file) {
            $dirs[dirname((string) $file)] = true;
        }
        foreach (array_keys($dirs) as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.tmp.*') ?: [] as $tmpFile) {
                $mtime = @filemtime($tmpFile);
                if ($mtime !== false && (time() - $mtime) > 86400) {
                    @unlink($tmpFile);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        self::cleanupStaleRouterTempFiles();
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        
        if ($this->committed) {
            // 已经提交过，跳过
            return;
        }

        if ($this->skipWrite) {
            $this->committed = true;
            $this->clearErrors();
            return;
        }

        $printing = null;
        if (\PHP_SAPI === 'cli') {
            try {
                $printing = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Output\Cli\Printing::class
                );
            } catch (\Throwable) {
                $printing = null;
            }
        }
        $note = static function (string $message) use ($printing): void {
            if ($printing === null) {
                return;
            }
            $printing->note($message);
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                \fflush(\STDOUT);
            }
        };
        
        try {
            $isPartial = !empty($this->modulesToClear);

            /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
            $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Module\Helper\Data::class
            );
            // M3 门禁（先于任何写动作）：扫描期记录到控制器类加载失败/解析为空即否决，
            // 回滚备份、上层不落指纹；禁止把「吞掉的模块」写进路由产物。
            $this->assertNoRouteScanFailuresRecorded($moduleHelper, $note);
            // 先落 ACL（扫描期已 defer），再写路由文件。
            // 禁止在此处 clear CollectedAclSourceIdsRegistry / LiveSourceSet：
            // orphan diff（after_route_collection）依赖 registry；提前清空会导致
            // 刚写入的方法级 ACL（如 Theme scope_*）被当成孤儿删掉。
            $note(__('   - route_update：正在批量写入控制器 ACL（可能较慢）…'));
            $moduleHelper->flushDeferredControllerAttributes();
            $note(__('   - route_update：ACL 写入完成'));

            // 增量模式：路由在注册过程中已经按文件即时写入，这里不再做全量 flush
            if ($isPartial) {
                // 门禁：清理前有路由的模块，收集后不得仍为空（否则整模块前台/后台 404）。
                $this->assertPartialClearedModulesRepopulated($this->modulesToClear, $note);
                $this->assertDeclaredChangedModulesPresent($note);
                $note(__('   - route_update：增量模式，刷新路由快照…'));
                \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();
                $this->committed = true;
                $this->clearErrors();
                $this->discardOriginalRouteBackups();
                $this->modulesToClear = [];
                $this->routeData = [];
                if (\function_exists('gc_collect_cycles')) {
                    \gc_collect_cycles();
                }
                $note(__('   - route_update：提交完成（增量）'));
                return;
            }

            // 全量模式：使用批量模式一次性写入所有路由文件
            // 检查批量模式是否启用
            if (!$this->routerHelper->isBatchMode()) {
                throw new Exception(__('批量模式未启用，无法提交路由更新'));
            }
            
            // 一次性写入所有路由文件
            $note(__('   - route_update：正在落盘路由文件…'));
            $this->routerHelper->flushBatchRouters();
            // flush 后 batchMode=false，断言自动改查磁盘新内容；缺失即回滚并拒绝提交。
            $this->assertDeclaredChangedModulesPresent($note);
            $note(__('   - route_update：路由文件已写入，正在生成运行时快照…'));
            \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();

            $this->committed = true;
            $this->clearErrors();
            // 提交成功后备份不再需要，立刻卸掉临时文件。
            $this->discardOriginalRouteBackups();
            $this->modulesToClear = [];
            $this->routeData = [];
            if (\function_exists('gc_collect_cycles')) {
                \gc_collect_cycles();
            }
            $note(__('   - route_update：提交完成'));
        } catch (\Exception $e) {
            $this->addError(__('路由文件写入失败：%{1}', [$e->getMessage()]));
            throw new Exception(__('路由文件写入失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        // 若从未做过真实准备（如 skip_route_stage），无备份可恢复，直接重置状态避免误写空数据
        if ($this->originalRouteBackupFiles === []) {
            $this->prepared = false;
            $this->committed = false;
            return;
        }
        // 从临时文件拷回，避免再把整表路由 load 进 PHP 数组。
        foreach ($this->originalRouteBackupFiles as $path => $bak) {
            try {
                if (!\is_string($bak) || $bak === '' || !\is_file($bak)) {
                    continue;
                }
                $dir = \dirname($path);
                if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                    throw new \RuntimeException('mkdir failed: ' . $dir);
                }
                if (!@\copy($bak, $path)) {
                    throw new \RuntimeException('copy failed');
                }
            } catch (\Exception $e) {
                $this->addError(__('回滚路由文件 %{1} 失败：%{2}', [$path, $e->getMessage()]));
            }
        }
        $this->discardOriginalRouteBackups();
        
        // 禁用批量模式
        if ($this->routerHelper->isBatchMode()) {
            // 通过反射清除批量路由数据
            try {
                $reflection = new \ReflectionClass($this->routerHelper);
                $property = $reflection->getProperty('batchRouters');
                $property->setAccessible(true);
                $property->setValue($this->routerHelper, []);
                
                $batchModeProperty = $reflection->getProperty('batchMode');
                $batchModeProperty->setAccessible(true);
                $batchModeProperty->setValue($this->routerHelper, false);
            } catch (\Exception $e) {
                // 忽略反射错误
            }
        }

        try {
            /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
            $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Module\Helper\Data::class
            );
            $moduleHelper->clearDeferredControllerAttributes();
        } catch (\Throwable) {
        }
        \Weline\Framework\Module\Handle::resetBatchRouteProgress();
        
        $this->prepared = false;
        $this->committed = false;
    }
}
