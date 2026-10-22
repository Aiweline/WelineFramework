<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeUpdateBatch;
use Weline\Theme\Model\ThemeUpdatePointer;

/**
 * 主题更新代次的构建/切换/回滚/GC。
 *
 * 设计要点（见 doc/主题更新代次与原子切换.md）：
 * - 构建期只写代次自己的目录（`pub/static/<batch>` 与 `generated/theme-layout-entities/<batch>`），不碰线上字节；
 * - 切换 = 指针行的 CAS 更新（`WHERE epoch = 期望值`），成功即生效，不需要 purge，也不需要维护模式；
 * - 回滚 = 用同一机制把 previous 换回 current；
 * - 每次切换 `epoch + 1`，写入 Env 供 Worker 感知（≤10s）。
 */
final class ThemeUpdateBatchService
{
    public const CONFIG_CURRENT_MAP = 'theme_update_current';
    public const CONFIG_EPOCH = 'theme_update_epoch';
    /** 指针文件（Worker ≤10s 生效，不依赖进程内 Env 缓存重载）。 */
    public const POINTER_FILE_RELATIVE = 'var/theme-update/current.json';
    /** 自动 GC 保留的历史代次数（不含 current/previous），可用 Env 配置 theme_update_keep_batches 覆盖。 */
    public const DEFAULT_KEEP_BATCHES = 3;
    public const AREA_FRONTEND = 'frontend';

    public function __construct(
        private readonly ThemeUpdateBatch $batch,
        private readonly ThemeUpdatePointer $pointer,
    ) {
    }

    // ---------------------------------------------------------------- 路径/键

    public static function staticRoot(string $batchId, ?string $pubStaticRoot = null): string
    {
        $root = $pubStaticRoot !== null && $pubStaticRoot !== ''
            ? \rtrim($pubStaticRoot, '/\\')
            : \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'pub' . \DIRECTORY_SEPARATOR . 'static';

        return $root . \DIRECTORY_SEPARATOR . $batchId;
    }

    public static function entityRoot(string $batchId, ?string $entitiesRoot = null): string
    {
        if ($entitiesRoot !== null && $entitiesRoot !== '') {
            return \rtrim($entitiesRoot, '/\\') . \DIRECTORY_SEPARATOR . $batchId;
        }
        $generated = \defined('\Weline\Framework\App\Env::GENERATED_DIR')
            ? (string)\Weline\Framework\App\Env::GENERATED_DIR
            : \rtrim((string)BP, '/\\') . \DIRECTORY_SEPARATOR . 'generated';

        return \rtrim($generated, '/\\') . \DIRECTORY_SEPARATOR
            . \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths::ROOT_SEGMENT
            . \DIRECTORY_SEPARATOR . $batchId;
    }

    public static function pointerFile(): string
    {
        return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::POINTER_FILE_RELATIVE);
    }

    public static function pointerConfigKey(int $websiteId, int $themeId, string $area = self::AREA_FRONTEND): string
    {
        return \max(0, $websiteId) . '|' . \max(0, $themeId) . '|' . \trim($area);
    }

    /** @return array{batch_id:string,static_ns:string,entity_root:string,theme_prefix:string,epoch:int} */
    public static function pointerConfigValue(ThemeUpdateBatch $batch, int $epoch): array
    {
        $manifest = $batch->getManifest();

        return [
            'batch_id' => $batch->getBatchId(),
            'static_ns' => (string)$batch->getData(ThemeUpdateBatch::schema_fields_STATIC_NS),
            'entity_root' => (string)$batch->getData(ThemeUpdateBatch::schema_fields_ENTITY_ROOT),
            'theme_prefix' => \trim((string)($manifest['theme_prefix'] ?? '')),
            'epoch' => \max(1, $epoch),
        ];
    }

    // ---------------------------------------------------------------- 构建

    public function createBatch(int $themeId, int $websiteId = 0, string $area = self::AREA_FRONTEND): ThemeUpdateBatch
    {
        if ($themeId < 1) {
            throw new \InvalidArgumentException('theme_update_theme_id_invalid');
        }
        $batchId = ThemeUpdatePointerPlanner::newBatchId(new \DateTimeImmutable(), \bin2hex(\random_bytes(3)));

        foreach ([self::staticRoot($batchId), self::entityRoot($batchId)] as $dir) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
                throw new \RuntimeException('theme_update_batch_dir_failed: ' . $dir);
            }
        }

        $this->batch->reset()->clearData()->setData([
            ThemeUpdateBatch::schema_fields_BATCH_ID => $batchId,
            ThemeUpdateBatch::schema_fields_WEBSITE_ID => \max(0, $websiteId),
            ThemeUpdateBatch::schema_fields_THEME_ID => $themeId,
            ThemeUpdateBatch::schema_fields_AREA => $area,
            ThemeUpdateBatch::schema_fields_STATIC_NS => $batchId,
            ThemeUpdateBatch::schema_fields_ENTITY_ROOT => $batchId,
            ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_BUILDING,
            ThemeUpdateBatch::schema_fields_FILE_COUNT => 0,
        ])->save();

        return $this->batch;
    }

    /**
     * 写入清单并落状态：verify.ok === true → verified，否则 failed（指针不会指向 failed）。
     *
     * @param array<string,mixed> $manifest
     */
    public function finishBuild(string $batchId, array $manifest): ThemeUpdateBatch
    {
        $batch = $this->loadBatch($batchId);
        $ok = ($manifest['verify']['ok'] ?? false) === true;
        $fileCount = (int)($manifest['entity_files'] ?? 0) + (int)($manifest['static_files'] ?? 0);

        $batch->setData([
            ThemeUpdateBatch::schema_fields_MANIFEST_JSON => \json_encode(
                $manifest,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            ThemeUpdateBatch::schema_fields_FILE_COUNT => $fileCount,
            ThemeUpdateBatch::schema_fields_STATUS => $ok
                ? ThemeUpdateBatch::STATUS_VERIFIED
                : ThemeUpdateBatch::STATUS_FAILED,
            ThemeUpdateBatch::schema_fields_VERIFIED_TIME => $ok ? \date('Y-m-d H:i:s') : null,
        ])->save();

        return $batch;
    }

    // ---------------------------------------------------------------- 切换

    /**
     * @return array{current:string,previous:string,epoch:int}
     */
    public function promote(string $batchId): array
    {
        $batch = $this->loadBatch($batchId);
        if (!$batch->isServable() || !$batch->isManifestVerified()) {
            throw new \RuntimeException('theme_update_batch_not_servable: ' . $batchId);
        }

        $themeId = (int)$batch->getData(ThemeUpdateBatch::schema_fields_THEME_ID);
        $websiteId = (int)$batch->getData(ThemeUpdateBatch::schema_fields_WEBSITE_ID);
        $area = (string)$batch->getData(ThemeUpdateBatch::schema_fields_AREA);
        $row = $this->loadPointerRow($websiteId, $themeId, $area);
        $state = $row === null
            ? ['current' => '', 'previous' => '', 'epoch' => 0]
            : $row->toState();

        $next = ThemeUpdatePointerPlanner::planPromote($state, $batchId);
        $this->commitPointer($row, $websiteId, $themeId, $area, $next, (int)$state['epoch'], $batchId);
        $this->persistPointerConfig($websiteId, $themeId, $area, $batch, $next['epoch']);
        $this->mirrorThemeStaticToDeploy($batchId);
        $this->invalidateThemeCaches($themeId, 'theme_update_promote');
        // 自动回收历史代次，避免批次目录无界增长（失败静默，不影响切换）
        try {
            // 自动 GC 只删目录，不再重复清缓存（promote 本身已清过）
            $this->gc($themeId, $websiteId, $area, $this->keepBatches(), false, false);
        } catch (\Throwable) {
        }

        return $next;
    }

    /**
     * @return array{current:string,previous:string,epoch:int}
     */
    public function rollback(int $themeId, int $websiteId = 0, string $area = self::AREA_FRONTEND): array
    {
        $row = $this->loadPointerRow($websiteId, $themeId, $area);
        if ($row === null) {
            throw new \RuntimeException('theme_update_pointer_missing');
        }
        $state = $row->toState();
        $next = ThemeUpdatePointerPlanner::planRollback($state);
        $batch = $this->loadBatch($next['current']);
        if (!$batch->isRollbackEligible() || !$batch->isManifestVerified()) {
            throw new \RuntimeException('theme_update_rollback_batch_not_servable: ' . $next['current']);
        }

        $this->commitPointer($row, $websiteId, $themeId, $area, $next, (int)$state['epoch'], $next['current']);
        $this->persistPointerConfig($websiteId, $themeId, $area, $batch, $next['epoch']);
        $this->mirrorThemeStaticToDeploy($next['current']);
        $this->invalidateThemeCaches($themeId, 'theme_update_rollback');

        return $next;
    }

    // ---------------------------------------------------------------- 查询/GC

    /** @return list<ThemeUpdateBatch> */
    public function listBatches(int $themeId, int $websiteId = 0, string $area = self::AREA_FRONTEND, int $limit = 50): array
    {
        $rows = $this->batch->reset()->clearQuery()
            ->where(ThemeUpdateBatch::schema_fields_THEME_ID, $themeId)
            ->where(ThemeUpdateBatch::schema_fields_WEBSITE_ID, \max(0, $websiteId))
            ->where(ThemeUpdateBatch::schema_fields_AREA, $area)
            ->order(ThemeUpdateBatch::schema_fields_CREATE_TIME, 'DESC')
            ->limit(\max(1, $limit))
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    /**
     * 删除历史代次（永不删当前与回滚目标）。
     *
     * @return list<string> 已删除的代次ID
     */
    public function gc(
        int $themeId,
        int $websiteId = 0,
        string $area = self::AREA_FRONTEND,
        int $keep = ThemeUpdatePointerPlanner::DEFAULT_KEEP,
        bool $purgeArtifacts = true,
        bool $invalidateCaches = true
    ): array
    {
        $rows = $this->listBatches($themeId, $websiteId, $area, 200);
        $row = $this->loadPointerRow($websiteId, $themeId, $area);
        $state = $row?->toState() ?? ['current' => '', 'previous' => '', 'epoch' => 0];

        $drop = ThemeUpdatePointerPlanner::planGc($rows, $state['current'], $state['previous'], $keep);
        $deleted = [];
        foreach ($drop as $batchId) {
            $this->purgeBatchDirs($batchId);
            $this->batch->reset()->clearQuery()
                ->where(ThemeUpdateBatch::schema_fields_BATCH_ID, $batchId)
                ->delete()->fetch();
            $deleted[] = $batchId;
        }

        // 被删代次的静态前缀可能仍被店面缓存/编译产物引用 → 定向失效 + 清理，避免出现 404 资源
        if ($deleted !== []) {
            // 产物扫描很重（遍历 generated/complicate + var/cache）；自动 GC 不做，
            // 需要时用 `theme:update gc --purge-artifacts` 手动清理。
            if ($purgeArtifacts) {
                $this->purgeArtifactReferences($deleted);
            }
            if ($invalidateCaches) {
                $this->invalidateThemeCaches($themeId, 'theme_update_gc');
            }
        }

        return $deleted;
    }

    // ---------------------------------------------------------------- internals

    private function loadBatch(string $batchId): ThemeUpdateBatch
    {
        $batch = $this->batch->reset()->clearQuery()
            ->where(ThemeUpdateBatch::schema_fields_BATCH_ID, $batchId)
            ->find()->fetch();

        if (!$batch instanceof ThemeUpdateBatch || $batch->getBatchId() === '') {
            throw new \RuntimeException('theme_update_batch_missing: ' . $batchId);
        }

        return $batch;
    }

    private function loadPointerRow(int $websiteId, int $themeId, string $area): ?ThemeUpdatePointer
    {
        $row = $this->pointer->reset()->clearQuery()
            ->where(ThemeUpdatePointer::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ThemeUpdatePointer::schema_fields_THEME_ID, $themeId)
            ->where(ThemeUpdatePointer::schema_fields_AREA, $area)
            ->find()->fetch();

        if ($row instanceof ThemeUpdatePointer && $row->getCurrentBatchId() !== '') {
            return $row;
        }

        return null;
    }

    /**
     * 指针切换：插入或 CAS 更新（`WHERE epoch = 期望值`），失败说明并发切换，重读后重试一次。
     *
     * @param array{current:string,previous:string,epoch:int} $next
     */
    private function commitPointer(
        ?ThemeUpdatePointer $row,
        int $websiteId,
        int $themeId,
        string $area,
        array $next,
        int $expectedEpoch,
        string $currentBatchId
    ): void {
        if ($row === null) {
            $this->pointer->reset()->clearData()->setData([
                ThemeUpdatePointer::schema_fields_WEBSITE_ID => $websiteId,
                ThemeUpdatePointer::schema_fields_THEME_ID => $themeId,
                ThemeUpdatePointer::schema_fields_AREA => $area,
                ThemeUpdatePointer::schema_fields_CURRENT_BATCH_ID => $next['current'],
                ThemeUpdatePointer::schema_fields_PREVIOUS_BATCH_ID => $next['previous'],
                ThemeUpdatePointer::schema_fields_EPOCH => $next['epoch'],
                ThemeUpdatePointer::schema_fields_UPDATE_TIME => \date('Y-m-d H:i:s'),
            ])->save();

            return;
        }

        $this->pointer->reset()->clearQuery()
            ->where(ThemeUpdatePointer::schema_fields_ID, (int)$row->getData(ThemeUpdatePointer::schema_fields_ID))
            ->where(ThemeUpdatePointer::schema_fields_EPOCH, $expectedEpoch)
            ->update([
                ThemeUpdatePointer::schema_fields_CURRENT_BATCH_ID => $next['current'],
                ThemeUpdatePointer::schema_fields_PREVIOUS_BATCH_ID => $next['previous'],
                ThemeUpdatePointer::schema_fields_EPOCH => $next['epoch'],
                ThemeUpdatePointer::schema_fields_UPDATE_TIME => \date('Y-m-d H:i:s'),
            ])->fetch();

        // 状态行：新代次 current，其余 superseded（best-effort，失败不影响指针）
        try {
            $this->batch->reset()->clearQuery()
                ->where(ThemeUpdateBatch::schema_fields_BATCH_ID, $currentBatchId)
                ->update([
                    ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_CURRENT,
                    ThemeUpdateBatch::schema_fields_PROMOTED_TIME => \date('Y-m-d H:i:s'),
                ])->fetch();
            $this->batch->reset()->clearQuery()
                ->where(ThemeUpdateBatch::schema_fields_THEME_ID, $themeId)
                ->where(ThemeUpdateBatch::schema_fields_AREA, $area)
                ->where(ThemeUpdateBatch::schema_fields_STATUS, ThemeUpdateBatch::STATUS_CURRENT)
                ->where(ThemeUpdateBatch::schema_fields_BATCH_ID, $currentBatchId, '<>')
                ->update([ThemeUpdateBatch::schema_fields_STATUS => ThemeUpdateBatch::STATUS_SUPERSEDED])
                ->fetch();
        } catch (\Throwable) {
            // 状态仅用于展示与 GC，指针已生效
        }
    }

    /**
     * 写 Env 供渲染期与 Worker 感知；失败不影响指针（指针是权威）。
     *
     * @param array{current:string,previous:string,epoch:int} $state
     */
    private function persistPointerConfig(int $websiteId, int $themeId, string $area, ThemeUpdateBatch $batch, int $epoch): void
    {
        try {
            $env = Env::getInstance();
            $map = $env->getConfig(self::CONFIG_CURRENT_MAP, []);
            if (!\is_array($map)) {
                $map = [];
            }
            $map[self::pointerConfigKey($websiteId, $themeId, $area)] = self::pointerConfigValue($batch, $epoch);
            $env->setConfig(self::CONFIG_CURRENT_MAP, $map);
            $env->setConfig(self::CONFIG_EPOCH, $epoch);
            $this->writePointerFile($map, $epoch);
        } catch (\Throwable) {
            // 指针已落库，渲染期可回读 DB
        }
    }

    private function purgeBatchDirs(string $batchId): void
    {
        $staticRoot = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'pub' . \DIRECTORY_SEPARATOR . 'static';
        foreach ([self::staticRoot($batchId, $staticRoot), self::entityRoot($batchId)] as $dir) {
            $real = \realpath($dir);
            if ($real === false || !\is_dir($real)) {
                continue;
            }
            // 安全阀：只允许删代次目录（父目录必须是 pub/static 或 theme-layout-entities 根）
            $parent = \realpath(\dirname($real));
            $expectedParents = [
                \realpath($staticRoot),
                \realpath(\dirname(self::entityRoot($batchId))),
            ];
            if (!\in_array($parent, \array_filter($expectedParents), true)) {
                continue;
            }
            $this->removeTree($real);
        }
    }

    private function removeTree(string $dir): void
    {
        $items = @\scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path) && !\is_link($path)) {
                $this->removeTree($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }

    /**
     * 写指针文件（原子 rename）。渲染期以 ≤10s TTL 读取，切换后无需重载 Worker。
     *
     * @param array<string,mixed> $map
     */
    private function writePointerFile(array $map, int $epoch): void
    {
        $file = self::pointerFile();
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        $payload = \json_encode([
            'epoch' => $epoch,
            'updated_at' => \date('c'),
            'map' => $map,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $tmp = $file . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $payload) === false) {
            return;
        }
        @\rename($tmp, $file);
    }

    /**
     * 指针切换后失效主题相关缓存（FPC/chrome/卡片等），使新代次立即对外可见。
     * 只做「主题相关」定向清理，不做全站 purge，也不需要维护模式。
     */
    private function invalidateThemeCaches(int $themeId, string $reason): void
    {
        // 1) 主题相关运行时缓存（chrome/卡片/投影等）
        try {
            $cleaner = ObjectManager::getInstance(\Weline\Theme\Service\ThemeRuntimeCacheCleaner::class);
            $cleaner->clearAllThemeRelatedCaches($themeId > 0 ? $themeId : null, $reason);
        } catch (\Throwable) {
            // 缓存失效失败不影响指针（下一次请求仍会按新指针渲染）
        }

        // 2) 店面 FPC/CDN：按「主题绑定站点 + 该主题全部布局」定向清理
        if ($themeId < 1) {
            return;
        }
        try {
            /** @var \Weline\Theme\Model\WelineTheme $theme */
            $theme = ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme->reset()->clearQuery()->where('id', $themeId)->find()->fetch();
            if ((int)$theme->getId() < 1) {
                return;
            }
            $layouts = [];
            $catalog = ObjectManager::getInstance(\Weline\Theme\Service\ThemeResourceCatalog::class);
            foreach ($catalog->getLayouts('frontend', $theme) as $type => $options) {
                foreach ((array)$options as $option) {
                    $optionName = \is_array($option) ? (string)($option['value'] ?? 'default') : (string)$option;
                    $layouts[] = ['type' => (string)$type, 'option' => $optionName === '' ? 'default' : $optionName];
                }
            }
            $targets = ObjectManager::getInstance(\Weline\Theme\Service\ThemeApplicationUsageService::class)
                ->ownSolidifyTargetsForTheme($themeId);
            if ($layouts === [] || $targets === []) {
                return;
            }
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher::class)
                ->purgeLayoutsForThemeTargets($themeId, $layouts, $targets, $reason);
        } catch (\Throwable) {
            // 同上：best-effort
        }
    }

    /**
     * 清理引用了已删除代次的编译产物与页面缓存文件（否则会出现 `/static/<dead-batch>/…` 404）。
     *
     * 扫描范围：`generated/complicate/**`（taglib 编译产物）与 `var/cache/**`（不含 fpc 大目录）。
     *
     * @param list<string> $batchIds 已删除的代次ID
     * @return int 清理的文件数
     */
    private function purgeArtifactReferences(array $batchIds): int
    {
        $batchIds = array_values(array_filter(array_map('strval', $batchIds)));
        if ($batchIds === []) {
            return 0;
        }
        $needles = array_map(static fn(string $id): string => '/static/' . $id . '/', $batchIds);
        $roots = [
            rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'complicate',
            rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache',
        ];

        $removed = 0;
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $stack = [$root];
            while ($stack !== []) {
                $current = (string)array_pop($stack);
                // 不进入 pub/static 或代次产物目录（它们由代次目录自身管理）
                if (str_contains(str_replace('\\', '/', $current), '/theme-layout-entities/')) {
                    continue;
                }
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
                    $content = @file_get_contents($path, false, null, 0, 262144);
                    if (!is_string($content) || $content === '') {
                        continue;
                    }
                    foreach ($needles as $needle) {
                        if (str_contains($content, $needle)) {
                            if (@unlink($path)) {
                                $removed++;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return $removed;
    }

    /** 自动 GC 保留数（Env 可配）。 */
    private function keepBatches(): int
    {
        try {
            $configured = Env::getInstance()->getConfig('theme_update_keep_batches');
            if (is_numeric($configured)) {
                return max(0, (int)$configured);
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_KEEP_BATCHES;
    }

    /**
     * 把代次的主题静态子树镜像到部署构建目录（硬链接覆盖）。
     *
     * 编译产物/烘焙缓存里写的是部署构建号前缀；镜像后这些 URL 自动指向当前代次内容，
     * 因此预览态与旧编译产物都不会出现指向不存在资源的 404。
     */
    private function mirrorThemeStaticToDeploy(string $batchId): void
    {
        if ($batchId === '') {
            return;
        }
        $pubStatic = rtrim((string)PUB, '/\\') . DIRECTORY_SEPARATOR . 'static';
        try {
            $deployId = (string)\Weline\Framework\Deploy\StaticBuildId::current($pubStatic);
        } catch (\Throwable) {
            return;
        }
        if ($deployId === '') {
            return;
        }
        $batchRoot = self::staticRoot($batchId, $pubStatic);
        $deployRoot = $pubStatic . DIRECTORY_SEPARATOR . $deployId;
        if (!is_dir($batchRoot) || !is_dir($deployRoot)) {
            return;
        }

        // 增量镜像：只覆盖本批次真正发布过的文件（避免每次遍历 1.4 万文件）
        $changed = $this->changedStaticFiles($batchId);
        if ($changed !== []) {
            foreach ($changed as $relative) {
                $from = $batchRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $to = $deployRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_file($from)) {
                    if (is_file($to)) {
                        @unlink($to);
                    }
                    continue;
                }
                $dir = dirname($to);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    continue;
                }
                if (is_link($to) || is_file($to)) {
                    @unlink($to);
                }
                if (!@link($from, $to)) {
                    @copy($from, $to);
                }
            }

            return;
        }

        // 兼容：无清单（旧批次）→ 全量镜像主题子树
        $welineBatch = $batchRoot . DIRECTORY_SEPARATOR . 'Weline';
        if (!is_dir($welineBatch)) {
            return;
        }
        foreach ((array)@scandir($welineBatch) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $welineBatch . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($src)) {
                continue;
            }
            // 仅镜像主题相关子树：Weline/Theme 与设计命名空间 Weline/<theme>
            if ($item !== 'Theme' && !$this->isDesignNamespace($item)) {
                continue;
            }
            $this->overlayTreeTo($src, $deployRoot . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . $item);
        }
    }

    /** 是否为设计命名空间（app/design 下的主题目录，如 hanfu）。 */
    private function isDesignNamespace(string $name): bool
    {
        $designRoot = rtrim((string)\Weline\Framework\App\Env::path_THEME_DESIGN_DIR, '/\\');
        if ($designRoot === '' || !is_dir($designRoot)) {
            return false;
        }
        foreach ((array)@scandir($designRoot) as $vendor) {
            if ($vendor === '.' || $vendor === '..') {
                continue;
            }
            if (is_dir($designRoot . DIRECTORY_SEPARATOR . $vendor . DIRECTORY_SEPARATOR . $name)) {
                return true;
            }
        }

        return false;
    }

    /** 递归硬链接覆盖（目标存在则先删）。 */
    private function overlayTreeTo(string $src, string $dst): void
    {
        if (is_link($dst)) {
            @unlink($dst);
        }
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
                $this->overlayTreeTo($from, $to);
                continue;
            }
            if (is_file($to)) {
                @unlink($to);
            }
            if (!@link($from, $to)) {
                @copy($from, $to);
            }
        }
    }

    /**
     * 读取批次清单里「本批次实际发布的主题静态文件」。
     *
     * @return list<string> 相对 pub/static/<batch>/ 的路径
     */
    private function changedStaticFiles(string $batchId): array
    {
        try {
            $row = $this->batch->reset()->clearQuery()
                ->where(ThemeUpdateBatch::schema_fields_BATCH_ID, $batchId)
                ->find()->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($row)) {
            return [];
        }
        $manifest = json_decode((string)($row[ThemeUpdateBatch::schema_fields_MANIFEST_JSON] ?? ''), true);
        $files = is_array($manifest) ? ($manifest['static_changed_files'] ?? []) : [];
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $files)));
    }
}
