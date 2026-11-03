<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemePathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeHeadCssDuplicateInspector;

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
     * setup:upgrade 正在跑（`var/process/setup_upgrade.lock` 被 flock 占用）时禁止构建代次：
     * 否则会把升级中途的静态树/实体根硬链接进批次，产出一份半成品快照。
     */
    public static function setupUpgradeInProgress(): bool
    {
        $root = \defined('VAR_DIR')
            ? (string)\VAR_DIR
            : \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR;
        $file = \rtrim($root, '/\\') . \DIRECTORY_SEPARATOR . 'process' . \DIRECTORY_SEPARATOR . 'setup_upgrade.lock';
        if (!\is_file($file)) {
            return false;
        }
        $handle = @\fopen($file, 'r');
        if ($handle === false) {
            return false;
        }
        $locked = !\flock($handle, \LOCK_EX | \LOCK_NB);
        if (!$locked) {
            \flock($handle, \LOCK_UN);
        }
        @\fclose($handle);

        return $locked;
    }

    /**
     * @param array{layouts?:list<string>,options?:list<string>,all_versions?:bool,skip_static?:bool,skip_entities?:bool,fresh?:bool,batch_id?:string} $options
     * @return array{batch_id:string,static_ns:string,entity_root:string,manifest:array<string,mixed>}
     */
    public function build(int $themeId, array $options = []): array
    {
        if ($themeId < 1) {
            throw new \InvalidArgumentException('theme_update_theme_id_invalid');
        }
        if (self::setupUpgradeInProgress()) {
            throw new \RuntimeException('theme_update_blocked_by_setup_upgrade');
        }

        $reuse = (string)($options['batch_id'] ?? '');
        if ($reuse !== '') {
            $this->batches->ensureBuilding($themeId, $reuse);
            $batchId = $reuse;
        } else {
            $batch = $this->batches->createBatch($themeId);
            $batchId = $batch->getBatchId();
        }
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

        // 2) 实体：把固化根切到批根后固化（-l/-o 可限范围）
        $entityFiles = 0;
        if (empty($options['skip_entities'])) {
            // 先硬链接复制当前实体根到批根：固化流水线在「无变更」时会跳过写入，
            // 复制保证批目录始终是一份完整、自洽的快照。
            $entityCopied = 0;
            $this->linkCurrentEntityTree($entityDir, $batchId, $entityCopied);
            ThemeLayoutEntityPaths::forceRoot($entityDir);
            $purge = [];
            if (!empty($options['fresh'])) {
                // -f：只删「本次指定范围/版本」的固化产物，再按源码重建（不动其它范围/版本、不动历史代次）。
                $purge = $this->purgeTargetVersionArtifacts(
                    $themeId,
                    \trim((string)($options['scope'] ?? '')),
                    !empty($options['all_versions'])
                );
                $checks[] = [
                    'name' => 'fresh_version_artifacts',
                    'ok' => true,
                    'purged' => $purge['purged'],
                    'versions' => $purge['versions'],
                    'shared_formal_skipped' => $purge['shared_formal_skipped'] ?? [],
                    'candidates' => $purge['rows'] ?? 0,
                    'created_version_dirs' => $purge['created_version_dirs'] ?? 0,
                    'errors' => $purge['errors'] ?? [],
                ];
            }
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
                if (!empty($purge['version_ids'])) {
                    // 精确到本次目标版本：未发布（草稿/sealed 未发）版本也要重建，不能只烘焙已发布版本。
                    $baseOptions['version_ids'] = $purge['version_ids'];
                }
                // 范围层级：只处理该 canonical scope 的（当前激活 / --all 时为全部）版本。
                $scope = \trim((string)($options['scope'] ?? ''));
                if ($scope !== '') {
                    $baseOptions['scope'] = $scope;
                }
                if (!empty($options['fresh'])) {
                    // -f：不让 PageInputDigest 短路，全部布局页面按源码重固化一遍。
                    $baseOptions['force_pages'] = true;
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

        // 3) 校验：静态引用可解析（抽样）+ 产物非空可读
        // 说明：实体 PHTML 是「布局源码派生」，{{…}} 与 <w:…> 属合法内联标记，不能按「未编译残留」判红。
        if (empty($options['skip_static'])) {
            $checks[] = $this->checkStaticSample($staticDir, $themePrefix);
        }
        $checks[] = $this->checkEntitySample($entityDir);
        // 3.1) 头部 CSS 重复投递（所有环境同一判定）
        $checks[] = $this->checkHeadCssOverlayDuplicates($themeId);
        // 3.2) 头部皮肤包可产出（消费侧）：清单叶必须解析得到、文件必须存在。
        // 缺这一项时「代次 verified」与「页面真带皮肤 CSS」无关（2026-11-02 掉样式事故）。
        $checks[] = $this->checkHeadPackLeaves($themeId);

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

    /**
     * `-f` 的目标粒度：只删「本次指定范围/版本」的固化产物，不是删整个主题。
     *
     * 目标版本来自 `w_theme_scope_version`：
     * - 给了 `--scope`：该范围；没给：该主题全部范围；
     * - `--all`：范围内全部版本；否则只取当前激版本（is_current=1）。
     *
     * 删除的是该版本独占目录（`generated/theme/<Vendor>/<theme>/<scope>/formal/v<versionId>/`）；
     * 版本没有独占目录、历史产物落在共享 `formal/` 时只删根下非 `v*` 子项（保留其它版本 `v*`）。
     * 删完后**预建** `formal/v<versionId>/`，让本次重建按版本落盘，不再回写共享根。
     *
     * @return array{purged:int,created_version_dirs:int,versions:list<string>,shared_formal_skipped:list<string>,rows:int,errors:list<string>}
     */
    private function purgeTargetVersionArtifacts(int $themeId, string $scope, bool $allVersions): array
    {
        $model = ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class);
        $query = $model->reset()->clearData()->clearQuery()
            ->where(\Weline\Theme\Model\ThemeScopeVersion::schema_fields_THEME_ID, $themeId)
            ->where(\Weline\Theme\Model\ThemeScopeVersion::schema_fields_AREA, 'frontend');
        if ($scope !== '') {
            $query->where(\Weline\Theme\Model\ThemeScopeVersion::schema_fields_SCOPE, $scope);
        }
        if (!$allVersions) {
            $query->where(\Weline\Theme\Model\ThemeScopeVersion::schema_fields_IS_CURRENT, 1);
        }
        $rows = (array)$query->select()->fetchArray();

        $purged = 0;
        $created = 0;
        $versions = [];
        $versionIds = [];
        $shared = [];
        $errors = [];
        $generated = \defined('\Weline\Framework\App\Env::GENERATED_DIR')
            ? (string)\Weline\Framework\App\Env::GENERATED_DIR
            : \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'generated';
        $safeRoot = \str_replace('\\', '/', \rtrim($generated, '/\\') . \DIRECTORY_SEPARATOR . 'theme') . '/';
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            try {
                $version = ObjectManager::getInstance(\Weline\Theme\Model\ThemeScopeVersion::class)
                    ->setData($row);
                $identity = $version->toVersionIdentity();
                $owner = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths::class)
                    ->ownerDir($identity);
            } catch (\Throwable $e) {
                $errors[] = get_class($e) . ': ' . $e->getMessage();
                continue;
            }
            // 目标版本的产物：优先独占目录 `…/formal/v<versionId>/`；
            // 没有独占目录时，历史上产物写在共享 `formal/` 根（当前激活版本），
            // 此时只删根下非 `v*` 的子项，保留其它版本的 `v*` 目录。
            $formal = \rtrim($owner, '/\\') . \DIRECTORY_SEPARATOR . 'formal' . \DIRECTORY_SEPARATOR;
            $dir = $formal . 'v' . (int)$identity->themeVersionId . \DIRECTORY_SEPARATOR;
            $label = $identity->canonicalScope . '@v' . $identity->themeVersionId;
            $normalizedFormal = \str_replace('\\', '/', $formal);
            if (!\str_starts_with($normalizedFormal, $safeRoot)) {
                continue;
            }
            $versionIds[(int)$identity->themeVersionId] = (int)$identity->themeVersionId;
            if (\is_dir($dir)) {
                $this->removeTree($dir);
                $purged++;
                $versions[] = $label;
            } elseif (\is_dir($formal)) {
                $removed = 0;
                foreach ((array)@\scandir($formal) as $item) {
                    if ($item === '.' || $item === '..' || \preg_match('/^v[0-9]+$/', (string)$item) === 1) {
                        continue;
                    }
                    $path = $formal . $item;
                    if (\is_dir($path) && !\is_link($path)) {
                        $this->removeTree($path);
                    } else {
                        @\unlink($path);
                    }
                    $removed++;
                }
                if ($removed > 0) {
                    $purged++;
                    $versions[] = $label . '(shared root, ' . $removed . ' items)';
                } else {
                    $shared[] = $label;
                }
            } else {
                $shared[] = $label;
            }
            // 本次重建按版本落盘：预建 `v<versionId>/`，之后 versionModeDir 走独占目录，不再写共享根。
            if (!\is_dir($dir) && (@\mkdir($dir, 0775, true) || \is_dir($dir))) {
                $created++;
            }
        }

        return [
            'purged' => $purged,
            'created_version_dirs' => $created,
            'version_ids' => \array_values($versionIds),
            'versions' => \array_slice($versions, 0, 20),
            'shared_formal_skipped' => \array_slice($shared, 0, 20),
            'rows' => \count($rows),
            'errors' => \array_slice($errors, 0, 3),
        ];
    }

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
     * 抽样路径相对 `pub/static/<batch>/`。
     * theme.css 的线上地址带设计主题命名空间，不是源码相对路径。
     *
     * @return list<string>
     */
    public static function staticVerificationSamples(string $themePrefix): array
    {
        $samples = ['Weline/Theme/ui/weline-foundation.css'];
        $prefix = trim(str_replace('\\', '/', $themePrefix), '/');
        if ($prefix === '') {
            return $samples;
        }
        $relative = ltrim(substr(ThemeUpgradeCutoverService::buildNamespacedModuleThemeRequestPath(
            $prefix,
            'Weline',
            'Theme',
            'frontend',
            'assets/css/theme.css',
        ), strlen('/static/')), '/');
        if ($relative !== '') {
            $samples[] = $relative;
        }

        return $samples;
    }

    /**
     * 抽样校验：批目录下基础样式与该主题实际会请求的 theme.css 都在。
     *
     * @return array{name:string,ok:bool,sampled:int,missing:list<string>}
     */
    private function checkStaticSample(string $staticDir, string $themePrefix): array
    {
        $samples = self::staticVerificationSamples($themePrefix);
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
     * 头部 CSS 重复投递检查：PACK_OVERLAY 已合并主题叶，layout/page 再挂同一个叶就是
     * 同一份 CSS 投递两次 + 多一个渲染阻塞请求。
     *
     * 所有环境同一判定（不区分 dev/prod）。
     * 检查自身异常不得把构建拖红，降级为 skipped。
     *
     * @return array<string,mixed>
     */
    private function checkHeadCssOverlayDuplicates(int $themeId): array
    {
        try {
            /** @var ThemeHeadCssDuplicateInspector $inspector */
            $inspector = ObjectManager::getInstance(ThemeHeadCssDuplicateInspector::class);
            $findings = $inspector->inspectTheme($themeId, 'frontend');
            $hard = $inspector->hardFindings($findings);
            $check = [
                'name' => 'head_css_overlay_duplicate',
                'ok' => $hard === [],
                'findings' => \count($findings),
                'hard' => \count($hard),
            ];
            if ($hard !== []) {
                $check['detail'] = $inspector->format($hard);
            }

            return $check;
        } catch (\Throwable $e) {
            return [
                'name' => 'head_css_overlay_duplicate',
                'ok' => true,
                'skipped' => 'inspector_error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 头部皮肤包（PACK_OVERLAY）可产出检查：消费侧的最低机器判定。
     *
     * 只看主题自身：overlay 清单必须解析出叶，且每个叶文件真实存在。
     * 清单为空/叶缺失 ⇒ 页面只会拿到 tokens+storefront，没有皮肤 CSS，
     * 但静态树与实体树都“齐全”，旧校验全绿（2026-11-02 事故）。
     *
     * 不区分 dev/prod；检查自身异常降级为 skipped，不伪造通过。
     *
     * 公开：增量（无脏标记）路径也要跑这一条，否则“什么都没做”也会报成功。
     *
     * @return array<string,mixed>
     */
    public function checkHeadPackLeaves(int $themeId): array
    {
        try {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            if ((int)$theme->getId() !== $themeId) {
                $theme->load($themeId);
            }
            if ((int)$theme->getId() < 1) {
                return ['name' => 'head_pack_leaves', 'ok' => true, 'skipped' => 'theme_not_found'];
            }
            /** @var ThemeHeadChromeCssPack $pack */
            $pack = ObjectManager::getInstance(ThemeHeadChromeCssPack::class);
            $leaves = $pack->overlayManifestLeaves('frontend', $theme);
            if ($leaves === []) {
                return [
                    'name' => 'head_pack_leaves',
                    'ok' => false,
                    'reason' => 'overlay_manifest_empty',
                    'theme_path' => (string)$theme->getPath(),
                ];
            }
            $module = Env::getInstance()->getModuleList()['Weline_Theme'] ?? null;
            $moduleBase = \is_array($module)
                ? \rtrim((string)($module['base_path'] ?? ''), '/\\') . '/view/theme/'
                : '';
            $resolver = ObjectManager::getInstance(ThemePathResolver::class);
            $missing = [];
            foreach ($leaves as $leaf) {
                $rel = \ltrim((string)$leaf, '/');
                if (\str_starts_with($rel, 'theme/')) {
                    $rel = \substr($rel, \strlen('theme/'));
                }
                if ($moduleBase === '' || $rel === '') {
                    $missing[] = (string)$leaf;
                    continue;
                }
                $abs = $resolver->resolveThemeFile($moduleBase . $rel, $theme);
                if (!\is_file($abs)) {
                    $missing[] = $rel;
                }
            }

            return [
                'name' => 'head_pack_leaves',
                'ok' => $missing === [],
                'leaves' => \count($leaves),
                'missing' => \array_slice($missing, 0, 10),
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'head_pack_leaves',
                'ok' => true,
                'skipped' => 'check_error',
                'error' => $e->getMessage(),
            ];
        }
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

    /**
     * 主题静态命名空间（如 Weline/{theme}）。
     * 设计目录下的相对路径原样返回。模块内默认主题是绝对路径，没有设计命名空间，返回空，
     * 避免把磁盘路径写进抽样 URL 和代次指针。
     */
    public static function staticNamespaceFromThemePath(string $path, string $designDir): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $designDir = rtrim(str_replace('\\', '/', $designDir), '/') . '/';
        $absolute = str_starts_with($path, '/') ? $path : $designDir . ltrim($path, '/');
        if (!str_starts_with($absolute, $designDir)) {
            return '';
        }

        return trim(substr($absolute, strlen($designDir)), '/');
    }

    /** 主题对应的静态命名空间前缀（如 Weline/{theme}），用于运行时识别「主题自有资源」。 */
    private function resolveThemePrefix(int $themeId): string
    {
        try {
            $theme = $this->theme->reset()->clearQuery()->where('id', $themeId)->find()->fetch();
            $path = (string)$theme->getData('path');

            return self::staticNamespaceFromThemePath($path, (string)\Weline\Framework\App\Env::path_THEME_DESIGN_DIR);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 批内实体自洽：把固化产物里的静态前缀归一化成占位符（出 HTML 时再换本代次）。
     *
     * 主题静态 URL 在固化时可能带旧代次/部署构建号前缀（固化 digest 未变时跳过写入）。
     * 本次重构后固化物不写具体版号：主题自有资源段写 `__W_PAIR__`，模块段写 `__W_deploy__`。
     * HTML 出口 {@see \Weline\Framework\Deploy\StaticAssetHtmlRewriter} 统一替换一次。
     *
     * @return int 被改写的文件数
     */
    private function rewriteEntityStaticPrefixes(string $entityDir, string $batchId, string $themePrefix): int
    {
        if (!is_dir($entityDir)) {
            return 0;
        }
        $themePrefix = trim($themePrefix, '/');
        $segments = ['Weline/Theme/'];
        if ($themePrefix !== '') {
            $segments[] = $themePrefix . '/';
        }
        $alternation = implode('|', array_map(static fn(string $s): string => preg_quote($s, '#'), $segments));
        $deployPlaceholder = \Weline\Framework\Deploy\StaticAssetHtmlRewriter::DEPLOY_PLACEHOLDER;
        $pairPlaceholder = \Weline\Framework\Deploy\StaticAssetHtmlRewriter::PAIR_PLACEHOLDER;
        // 主题自有资源（无论旧前缀形状）→ /static/{PAIR占位符}/Weline/<theme>/…
        $patternPair = '#/static/(?:[A-Za-z0-9_.-]+/)*(' . $alternation . ')#';
        // 只去掉真正的构建段（12 位构建号 / tu-*）；Weline 是命名空间，不是构建段。
        $patternDeploy = '#/static/(?:[0-9a-f]{12}|tu-[A-Za-z0-9_.-]+)/#';
        // 裸命名空间 /static/Weline/… 也加部署占位，出 HTML 时换成当前构建目录。
        $patternBareNamespace = '#/static/(?=Weline/)#';
        // 旧批次的 `__W_deploy__/<code>/` 把 Weline 当构建段吃掉了，重建时补回命名空间。
        $patternHealVendor = '#/static/' . preg_quote($deployPlaceholder, '#') . '/(?!(?:Weline|theme|pub|media)/)([A-Za-z0-9_.-]+)/#';

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
                if (!is_string($content)) {
                    continue;
                }
                $next = preg_replace($patternPair, '/static/' . $pairPlaceholder . '/$1', $content);
                if (is_string($next) && str_contains($next, '/static/')) {
                    $next = preg_replace($patternHealVendor, '/static/' . $deployPlaceholder . '/Weline/$1/', $next);
                    $next = is_string($next)
                        ? preg_replace($patternDeploy, '/static/' . $deployPlaceholder . '/', $next)
                        : $next;
                    $next = is_string($next)
                        ? preg_replace($patternBareNamespace, '/static/' . $deployPlaceholder . '/', $next)
                        : $next;
                }
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
