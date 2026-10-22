<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;

/**
 * 主题更新代次构建器。
 *
 * 构建期只写代次目录，不碰线上字节：
 * 1) `pub/static/<batch>/`：默认用**硬链接**从当前静态代次目录复制一份（同盘零拷贝、
 *    只对后续被覆盖的文件产生真实空间），保证批次内所有引用（框架 JS/CSS + 主题资源）都能解析；
 * 2) `generated/theme-layout-entities/<batch>/`：把布局实体固化到批根（通过覆盖
 *    {@see ThemeLayoutEntityPaths} 的 rootOverride 实现）；
 * 3) 写清单 + 校验结论（verify.ok），状态置 verified/failed。
 *
 * 指针切换（{@see ThemeUpdateBatchService::promote()}）与此无关，构建永远不会影响线上。
 */
final class ThemeUpdateBuildService
{
    public function __construct(
        private readonly ThemeUpdateBatchService $batches,
        private readonly WelineTheme $theme,
    ) {
    }

    /**
     * @param array{layouts?:list<string>,options?:list<string>,all_versions?:bool,skip_static?:bool,skip_entities?:bool} $options
     * @param callable():bool|null $stillLatest 代次已被后来的更新拨过时返回 false，本次放弃、不切换
     * @return array{batch_id:string,static_ns:string,entity_root:string,manifest:array<string,mixed>,superseded?:bool}
     */
    public function build(int $themeId, array $options = [], ?callable $stillLatest = null): array
    {
        if ($themeId < 1) {
            throw new \InvalidArgumentException('theme_update_theme_id_invalid');
        }
        if ($this->yielded($stillLatest)) {
            return $this->superseded(null);
        }

        $batch = $this->batches->createBatch($themeId);
        $batchId = $batch->getBatchId();
        $staticDir = ThemeUpdateBatchService::staticRoot($batchId);
        $entityDir = ThemeUpdateBatchService::entityRoot($batchId);
        $checks = [];

        $themePrefix = $this->resolveThemePrefix($themeId);

        // 1) 静态：只硬链接「主题自有」子树（设计命名空间 + Weline/Theme）
        if (empty($options['skip_static'])) {
            $copied = $this->linkCurrentStaticTree($staticDir, $themePrefix);
            $publishResult = $this->publishThemeStaticIntoBatch($batchId, $staticDir, $themeId);
            $published = (int)($publishResult['count'] ?? 0);
            $changedFiles = (array)($publishResult['files'] ?? []);
            $checks[] = [
                'name' => 'static_tree_ready',
                'ok' => $copied >= 0 && $published >= 0,
                'files' => $copied,
                'published_theme_files' => $published,
            ];
        } else {
            $checks[] = ['name' => 'static_tree_ready', 'ok' => true, 'skipped' => true];
        }
        if ($this->yielded($stillLatest)) {
            return $this->superseded($batchId);
        }

        // 2) 实体：把固化根切到批根后固化（-l/-o 可限范围）
        $entityFiles = 0;
        if (empty($options['skip_entities'])) {
            // 先硬链接复制当前实体根到批根：固化流水线在「无变更」时会跳过写入，
            // 复制保证批目录始终是一份完整、自洽的快照。
            $entityCopied = 0;
            $this->linkCurrentEntityTree($entityDir, $batchId, $entityCopied);
            ThemeLayoutEntityPaths::forceRoot($entityDir);
            // 固化/编译期把「本代次」作为静态前缀来源：编译产物烘焙的是本批次前缀，
            // 这样后续 GC 删旧批次不会让产物 404。
            // 编译/烘焙期静态前缀固定为「部署构建号」：promote 会把批次主题子树镜像到该目录，
            // 于是旧编译产物与预览态都能自动跟随当前代次（预览自身资源仍走 __preview 名空间）。
            \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::forceBatch([
                'batch_id' => $batchId,
                'static_ns' => $this->deployStaticBuildId(),
                'entity_root' => $batchId,
                'theme_prefix' => $themePrefix,
                'epoch' => 1,
            ]);
            try {
                /** @var ThemeLayoutEntityUpgradeSolidifyService $solidify */
                $solidify = ObjectManager::getInstance(ThemeLayoutEntityUpgradeSolidifyService::class);
                $baseOptions = ['cache_invalidation' => 'none'];
                if (!empty($options['all_versions'])) {
                    $baseOptions['all_versions'] = true;
                }
                // 增量固化：layout_targets = [['type' => 'homepage', 'option' => 'default'], …]
                // 为空表示全主题（全布局）固化。
                $targets = $this->normalizeLayoutTargets($options);
                if ($targets === []) {
                    $solidify->solidifyTheme($themeId, null, $baseOptions);
                } else {
                    foreach ($targets as $target) {
                        $one = $baseOptions;
                        $one['layout_type'] = $target['type'];
                        if ($target['option'] !== '') {
                            $one['layout_option'] = $target['option'];
                        }
                        $solidify->solidifyTheme($themeId, null, $one);
                    }
                }
                $layoutTargetsUsed = $targets;
            } finally {
                ThemeLayoutEntityPaths::forceRoot(null);
                \Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::forceBatch(null);
            }
            $entityFiles = $this->countFiles($entityDir);
            $rewritten = $this->rewriteEntityStaticPrefixes($entityDir, $this->deployStaticBuildId(), $themePrefix);
            $checks[] = [
                'name' => 'entity_tree_ready',
                'ok' => $entityFiles > 0,
                'files' => $entityFiles,
                'rewritten_files' => $rewritten,
            ];
        }
        if ($this->yielded($stillLatest)) {
            return $this->superseded($batchId);
        }

        // 3) 校验：静态引用可解析（抽样）+ 产物非空可读
        // 说明：实体 PHTML 是「布局源码派生」，{{…}} 与 <w:…> 属合法内联标记，
        // 不能按「未编译残留」判红；编译正确性由渲染 smoke 覆盖（后续任务）。
        if (empty($options['skip_static'])) {
            $checks[] = $this->checkStaticSample($staticDir);
        }
        $checks[] = $this->checkEntitySample($entityDir);

        if ($this->yielded($stillLatest)) {
            return $this->superseded($batchId);
        }

        $ok = true;
        foreach ($checks as $check) {
            if (($check['ok'] ?? false) !== true) {
                $ok = false;
                break;
            }
        }

        $manifest = [
            'batch_id' => $batchId,
            'theme_id' => $themeId,
            'theme_prefix' => $themePrefix,
            'static_ns' => $batchId,
            'entity_root' => $batchId,
            'layout_targets' => $layoutTargetsUsed ?? [],
            'static_files' => $this->countFiles($staticDir, 200000, true),
            // 本批次实际发布的主题静态文件（相对 pub/static/<batch>/），供 promote 增量镜像
            'static_changed_files' => array_slice($changedFiles ?? [], 0, 5000),
            'entity_files' => $entityFiles,
            'checks' => $checks,
            'verify' => ['ok' => $ok, 'checks' => $checks],
            'created_at' => \date('c'),
        ];
        $this->batches->finishBuild($batchId, $manifest);

        return [
            'batch_id' => $batchId,
            'static_ns' => $batchId,
            'entity_root' => $batchId,
            'manifest' => $manifest,
        ];
    }

    /** @param callable():bool|null $stillLatest */
    private function yielded(?callable $stillLatest): bool
    {
        return $stillLatest !== null && $stillLatest() !== true;
    }

    /** @return array{batch_id:string,static_ns:string,entity_root:string,manifest:array<string,mixed>,superseded:bool} */
    private function superseded(?string $batchId): array
    {
        if ($batchId !== null && $batchId !== '') {
            $this->batches->finishBuild($batchId, [
                'verify' => ['ok' => false, 'superseded' => true],
                'entity_files' => 0,
                'static_files' => 0,
            ]);
        }

        return [
            'batch_id' => $batchId ?? '',
            'static_ns' => $batchId ?? '',
            'entity_root' => $batchId ?? '',
            'superseded' => true,
            'manifest' => ['verify' => ['ok' => false, 'superseded' => true]],
        ];
    }

    /**
     * 把当前静态代次树硬链接进批目录；返回文件数（-1 表示失败）。
     */
    /**
     * 准备批静态树：
     * - 主题自有子树（设计命名空间 `Weline/<themePrefix>`、`Weline/Theme`）→ 硬链接真实文件（可覆盖新资源）；
     * - 其余顶层目录 → 符号链接到部署构建树（保证批次内任何 URL 都能解析，且不占空间）。
     *
     * @return int 复制的真实文件数（-1 表示失败）
     */
    private function linkCurrentStaticTree(string $targetDir, string $themePrefix): int
    {
        $source = $this->resolveCurrentStaticDir();
        if ($source === null) {
            return -1;
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return -1;
        }

        $themeSecondLevel = ['Theme'];
        $prefixParts = array_values(array_filter(explode('/', trim(str_replace('\\', '/', $themePrefix), '/'))));
        if (count($prefixParts) >= 2 && $prefixParts[0] === 'Weline') {
            $themeSecondLevel[] = $prefixParts[1];
        } elseif ($prefixParts !== []) {
            $themeSecondLevel[] = $prefixParts[0];
        }

        $count = 0;
        $items = @scandir($source) ?: [];
        foreach ($items as $top) {
            if ($top === '.' || $top === '..' || str_starts_with($top, 'tu-')) {
                continue;
            }
            $srcTop = $source . DIRECTORY_SEPARATOR . $top;
            $dstTop = $targetDir . DIRECTORY_SEPARATOR . $top;
            if (!is_dir($srcTop)) {
                continue;
            }
            if (!is_dir($dstTop) && !@mkdir($dstTop, 0775, true) && !is_dir($dstTop)) {
                continue;
            }
            $second = @scandir($srcTop) ?: [];
            foreach ($second as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $src = $srcTop . DIRECTORY_SEPARATOR . $child;
                $dst = $dstTop . DIRECTORY_SEPARATOR . $child;
                if (file_exists($dst) || is_link($dst)) {
                    continue;
                }
                $isThemeOwned = ($top === 'Weline' && in_array($child, $themeSecondLevel, true));
                if ($isThemeOwned) {
                    if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
                        continue;
                    }
                    $this->hardlinkTree($src, $dst, $count);
                    continue;
                }
                @symlink($src, $dst);
            }
        }

        return $count;
    }

    /** 当前线上静态代次目录：`pub/static/<build_id>`（无则退回 pub/static 平铺树）。 */
    private function resolveCurrentStaticDir(): ?string
    {
        $pubStatic = \rtrim((string)\PUB, '/\\') . \DIRECTORY_SEPARATOR . 'static';
        try {
            $buildId = (string)\Weline\Framework\Deploy\StaticBuildId::current($pubStatic);
        } catch (\Throwable) {
            $buildId = '';
        }
        if ($buildId !== '') {
            $candidate = $pubStatic . \DIRECTORY_SEPARATOR . $buildId;
            if (\is_dir($candidate)) {
                return $candidate;
            }
        }

        return \is_dir($pubStatic) ? $pubStatic : null;
    }

    private function hardlinkTree(string $source, string $target, int &$count): void
    {
        $items = @\scandir($source) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $source . \DIRECTORY_SEPARATOR . $item;
            $dst = $target . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($src) && !\is_link($src)) {
                if (!\is_dir($dst) && !@\mkdir($dst, 0775, true) && !\is_dir($dst)) {
                    continue;
                }
                $this->hardlinkTree($src, $dst, $count);
                continue;
            }
            if (\file_exists($dst)) {
                $count++;
                continue;
            }
            if (@\link($src, $dst) || @\copy($src, $dst)) {
                $count++;
            }
        }
    }

    private function countFiles(string $dir, int $limit = 50000, bool $allowMissing = false): int
    {
        if (!\is_dir($dir)) {
            return $allowMissing ? 0 : 0;
        }
        $count = 0;
        $stack = [$dir];
        while ($stack !== [] && $count < $limit) {
            $current = \array_pop($stack);
            $items = @\scandir($current) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $current . \DIRECTORY_SEPARATOR . $item;
                if (\is_dir($path) && !\is_link($path)) {
                    $stack[] = $path;
                } else {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 抽样校验：批目录下主题资源与基础 JS 至少各存在一个。
     *
     * @return array{name:string,ok:bool,sampled:int,missing:list<string>}
     */
    private function checkStaticSample(string $staticDir): array
    {
        $samples = [
            'Weline/Theme/ui/weline-foundation.css',
            'Weline/Theme/view/theme/frontend/assets/css/theme.css',
        ];
        $missing = [];
        $sampled = 0;
        foreach ($samples as $rel) {
            $sampled++;
            if (!\is_file($staticDir . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $rel))) {
                $missing[] = $rel;
            }
        }

        return [
            'name' => 'static_refs_resolvable',
            'ok' => $missing === [],
            'sampled' => $sampled,
            'missing' => $missing,
        ];
    }

    /**
     * 抽样校验：实体产物可读且非空。
     *
     * @return array{name:string,ok:bool,sampled:int,empty:list<string>}
     */
    private function checkEntitySample(string $entityDir): array
    {
        if (!is_dir($entityDir)) {
            return ['name' => 'entity_readable', 'ok' => true, 'sampled' => 0, 'empty' => []];
        }
        $stack = [$entityDir];
        $sampled = 0;
        $empty = [];
        while ($stack !== [] && $sampled < 50) {
            $current = (string)array_pop($stack);
            $items = @scandir($current) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $current . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $stack[] = $path;
                    continue;
                }
                if (!str_ends_with($path, '.phtml')) {
                    continue;
                }
                $sampled++;
                if ((int)@filesize($path) <= 0) {
                    $empty[] = basename($path);
                }
                if ($sampled >= 50) {
                    break;
                }
            }
        }

        return [
            'name' => 'entity_readable',
            'ok' => $empty === [],
            'sampled' => $sampled,
            'empty' => array_slice($empty, 0, 5),
        ];
    }

    /**
     * 把当前线上实体根（除其它代次目录外）硬链接进批根。
     */
    private function linkCurrentEntityTree(string $targetDir, string $batchId, int &$count): void
    {
        $liveRoot = rtrim(self::entityLiveRoot(), '/\\');
        if (!is_dir($liveRoot)) {
            return;
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return;
        }
        $items = @scandir($liveRoot) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $batchId || str_starts_with($item, 'tu-')) {
                continue;
            }
            $src = $liveRoot . DIRECTORY_SEPARATOR . $item;
            $dst = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $item;
            if (is_dir($src) && !is_link($src)) {
                if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
                    continue;
                }
                $this->hardlinkTree($src, $dst, $count);
                continue;
            }
            if (file_exists($dst)) {
                $count++;
                continue;
            }
            if (@link($src, $dst) || @copy($src, $dst)) {
                $count++;
            }
        }
    }

    /** 线上实体根（generated/theme-layout-entities）。 */
    private static function entityLiveRoot(): string
    {
        $generated = defined('\Weline\Framework\App\Env::GENERATED_DIR')
            ? (string)\Weline\Framework\App\Env::GENERATED_DIR
            : rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'generated';

        return rtrim($generated, '/\\') . DIRECTORY_SEPARATOR
            . ThemeLayoutEntityPaths::ROOT_SEGMENT . DIRECTORY_SEPARATOR;
    }

    /** 主题对应的静态命名空间前缀（如 Weline/hanfu），用于运行时识别「主题自有资源」。 */
    private function resolveThemePrefix(int $themeId): string
    {
        try {
            $theme = $this->theme->reset()->clearQuery()->where('id', $themeId)->find()->fetch();
            $path = (string)$theme->getData('path');
            if ($path === '') {
                return '';
            }
            $designDir = rtrim((string)\Weline\Framework\App\Env::path_THEME_DESIGN_DIR, '/\\') . DIRECTORY_SEPARATOR;
            $abs = str_starts_with($path, '/') ? $path : $designDir . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $rel = str_starts_with($abs, $designDir) ? substr($abs, strlen($designDir)) : $path;

            return trim(str_replace('\\', '/', $rel), '/');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 批内实体自洽：把烘焙进模板 HTML 的静态前缀统一改写成当前批次前缀。
     *
     * 主题静态 URL 在固化时写死，而固化在 digest 未变时会跳过写入，
     * 批内实体可能仍是线上快照（含旧代次/部署构建号前缀）。
     * 这里只改「主题自有资源」段（`Weline/<theme_prefix>/` 与 `Weline/Theme/`），
     * 框架/模块静态保持部署构建号。
     *
     * @return int 被改写的文件数
     */
    private function rewriteEntityStaticPrefixes(string $entityDir, string $batchId, string $themePrefix): int
    {
        if (!is_dir($entityDir)) {
            return 0;
        }
        $themePrefix = trim($themePrefix);
        $segments = ['Weline/Theme/'];
        if ($themePrefix !== '') {
            $segments[] = 'Weline/' . $themePrefix . '/';
        }
        $alternation = implode('|', array_map(static fn(string $s): string => preg_quote($s, '#'), $segments));
        // 已带前缀（/static/<old>/Weline/<theme>/…）→ 换成本批次
        $patternPrefixed = '#/static/[A-Za-z0-9_.-]+/(' . $alternation . ')#';
        // 裸主题路径（/static/Weline/<theme>/…）→ 补批次前缀
        $patternFlat = '#(?<!/static/[A-Za-z0-9_.-])/static/(' . $alternation . ')#';

        $rewritten = 0;
        $stack = [$entityDir];
        while ($stack !== []) {
            $current = (string)array_pop($stack);
            $items = @scandir($current) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $current . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path) && !is_link($path)) {
                    $stack[] = $path;
                    continue;
                }
                if (!str_ends_with($path, '.phtml')) {
                    continue;
                }
                $content = @file_get_contents($path);
                if (!is_string($content) || !str_contains($content, '/static/')) {
                    continue;
                }
                $next = preg_replace($patternPrefixed, '/static/' . $batchId . '/$1', $content);
                $next = is_string($next)
                    ? preg_replace($patternFlat, '/static/' . $batchId . '/$1', $next)
                    : $next;
                if (!is_string($next) || $next === $content) {
                    continue;
                }
                if (@file_put_contents($path, $next) !== false) {
                    $rewritten++;
                }
            }
        }

        return $rewritten;
    }

    /**
     * 归一化「受影响布局」目标：
     * - `layout_targets`: [['type'=>..,'option'=>..], …]（发布时记录，支持增量）
     * - 兼容旧参数：`layouts`（类型列表）+ `options`（单选项）
     *
     * @param array<string,mixed> $options
     * @return list<array{type:string,option:string}>
     */
    private function normalizeLayoutTargets(array $options): array
    {
        $targets = [];
        foreach ((array)($options['layout_targets'] ?? []) as $target) {
            if (!is_array($target)) {
                continue;
            }
            $type = trim((string)($target['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $option = trim((string)($target['option'] ?? ''));
            $key = $type . '/' . $option;
            $targets[$key] = ['type' => $type, 'option' => $option];
        }
        if ($targets === []) {
            $layouts = array_values(array_filter((array)($options['layouts'] ?? [])));
            if (count($layouts) === 1) {
                $optionList = array_values(array_filter((array)($options['options'] ?? [])));
                $targets[(string)$layouts[0] . '/'] = [
                    'type' => (string)$layouts[0],
                    'option' => count($optionList) === 1 ? (string)$optionList[0] : '',
                ];
            }
        }

        return array_values($targets);
    }

    /**
     * 把「主题静态（含设计覆盖）」真正发布进批次目录。
     *
     * 复用部署链路的 `StaticAssetMode` 贡献者，写到 `DeployStagingSession` 的 staging 根，
     * 再以硬链接覆盖进 `pub/static/<batch>/`。失败时返回 -1（降级为仅用部署目录内容）。
     *
     * @return array{count:int,files:list<string>} count=-1 表示发布失败
     */
    private function publishThemeStaticIntoBatch(string $batchId, string $staticDir, int $themeId): array
    {
        $session = null;
        $stagingRoot = '';
        try {
            /** @var \Weline\Theme\Model\WelineTheme $theme */
            $theme = $this->theme->reset()->clearQuery()->where('id', $themeId)->find()->fetch();
            if ((int)$theme->getId() < 1) {
                return ['count' => -1, 'files' => []];
            }

            $session = \Weline\Framework\Deploy\DeployStagingSession::open('tu-' . $batchId . '-' . \bin2hex(\random_bytes(2)));
            $stagingRoot = $session->staticRoot();
            $context = new \Weline\Framework\Deploy\StaticAssetModeContext(
                \Weline\Framework\Deploy\DeployPublicUrlMode::useProduction() ? 'prod' : 'dev',
                'theme',
                $stagingRoot,
                [$theme],
                []
            );
            /** @var \Weline\Framework\Compilation\ServiceProviderRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Compilation\ServiceProviderRegistry::class);
            $implementations = $registry->implementationsWithPrefix(\Weline\Framework\Deploy\StaticAssetMode::CAPABILITY_PREFIX);
            $ran = 0;
            foreach ($implementations as $implementation) {
                if (!is_string($implementation) || $implementation === '') {
                    continue;
                }
                $contributor = ObjectManager::getInstance($implementation);
                if ($contributor instanceof \Weline\Framework\Deploy\StaticAssetModeContributorInterface) {
                    $contributor->publish($context);
                    $ran++;
                }
            }
            if ($ran === 0 || !is_dir($stagingRoot)) {
                return ['count' => -1, 'files' => []];
            }

            $overlaid = 0;
            $files = [];
            $this->overlayTree($stagingRoot, $staticDir, $overlaid, $files, '');

            return ['count' => $overlaid, 'files' => $files];
        } catch (\Throwable) {
            return ['count' => -1, 'files' => []];
        } finally {
            if ($session !== null) {
                try {
                    $session->abort();
                } catch (\Throwable) {
                }
            }
            if ($stagingRoot !== '' && is_dir($stagingRoot)) {
                $base = dirname($stagingRoot);
                $this->removeTree($base);
            }
        }
    }

    /**
     * 递归把 $src 覆盖进 $dst（硬链接优先，跨设备则复制）。
     *
     * @param list<string> $collected 收集被覆盖文件的相对路径（相对 $dst 根）
     */
    private function overlayTree(string $src, string $dst, int &$count, array &$collected = [], string $prefix = ''): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
            return;
        }
        foreach ((array)@scandir($src) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . DIRECTORY_SEPARATOR . $item;
            $to = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_link($to)) {
                @unlink($to);
            }
            if (is_dir($from)) {
                $this->overlayTree($from, $to, $count, $collected, $prefix === '' ? $item : ($prefix . '/' . $item));
                continue;
            }
            if (is_link($to) || is_file($to)) {
                @unlink($to);
            }
            if (@link($from, $to) || @copy($from, $to)) {
                $count++;
                $collected[] = $prefix === '' ? $item : ($prefix . '/' . $item);
            }
        }
    }

    /** 递归删除目录树（仅用于清理 staging）。 */
    private function removeTree(string $dir): void
    {
        foreach ((array)@scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** 当前部署静态构建号（pub/static/.build.json）。 */
    private function deployStaticBuildId(): string
    {
        $pubStatic = rtrim((string)PUB, '/\\') . DIRECTORY_SEPARATOR . 'static';
        try {
            $id = (string)\Weline\Framework\Deploy\StaticBuildId::current($pubStatic);
        } catch (\Throwable) {
            $id = '';
        }

        return $id;
    }
}
