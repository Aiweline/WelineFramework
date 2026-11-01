<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;

/**
 * 发布状态存储（Task 0）：每站一条独立代次链 `chains[{site}|{theme}|{area}]`。
 *
 * 职责边界（HARD）：
 * - **唯一写入方**：SystemReleaseManager / ThemeReleaseManager（它们调用 stageTarget / promoteTarget）；
 * - **唯一指针写入方**：本类的 promoteTarget() 是唯一允许写 `pg/current` 的入口；
 * - 请求期只读：通过 GenerationRegistry 读取，本类的写方法不得在请求路径调用。
 *
 * 链结构：
 * ```
 * chains["{site}|{theme}|{area}"] = [
 *   "hosts"     => list<string>,          // 发布期解析，请求期只读
 *   "active"    => {generation_uid,page_token,deploy_build,theme_generation,kind},
 *   "target"    => ?array,                // 已 stage 未 promote
 *   "supported" => [ ...× ≤3 ],           // 该站自己的 3 版窗口，首位为 active
 * ]
 * ```
 */
final class PublishStateStore
{
    public const STATE_FILE = 'var/deploy/publish_state.json';
    public const MAX_SUPPORTED = 3;
    public const AREA_FRONTEND = 'frontend';

    // ---------------------------------------------------------------- 读取

    /** @return array{chains:array<string,mixed>} */
    public static function read(): array
    {
        $file = self::stateFile();
        if (!\is_file($file)) {
            return ['chains' => []];
        }
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return ['chains' => []];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : ['chains' => []];
    }

    /** @return array{hosts:list<string>,active:array,target:?array,supported:list<array>} */
    public static function chain(int $websiteId, int $themeId, string $area = self::AREA_FRONTEND): array
    {
        $key = self::chainKey($websiteId, $themeId, $area);
        $row = self::read()['chains'][$key] ?? [];

        return [
            'hosts' => \is_array($row['hosts'] ?? null) ? \array_values($row['hosts']) : [],
            'active' => \is_array($row['active'] ?? null) ? $row['active'] : [],
            'target' => \is_array($row['target'] ?? null) ? $row['target'] : null,
            'supported' => \is_array($row['supported'] ?? null) ? \array_values($row['supported']) : [],
        ];
    }

    public static function chainKey(int $websiteId, int $themeId, string $area = self::AREA_FRONTEND): string
    {
        return \max(0, $websiteId) . '|' . \max(0, $themeId) . '|' . \trim($area);
    }

    // ---------------------------------------------------------------- 写入

    /**
     * 只写该链 target，`ready` 保持 false，不动 active，**不校验产物**（Task 0 D2 定论）。
     */
    public static function stageTarget(
        int $websiteId,
        int $themeId,
        string $area,
        string $deployBuild,
        string $themeGeneration,
        string $pageToken,
        string $kind = 'theme'
    ): void {
        $doc = self::read();
        $key = self::chainKey($websiteId, $themeId, $area);
        $row = \is_array($doc['chains'][$key] ?? null) ? $doc['chains'][$key] : [];
        $uid = self::composeUid($deployBuild, $themeGeneration);
        $row['target'] = [
            'generation_uid' => $uid,
            'page_token' => \strtolower(\trim($pageToken)),
            'deploy_build' => \strtolower(\trim($deployBuild)),
            'theme_generation' => \trim($themeGeneration),
            'kind' => \in_array($kind, ['system', 'theme', 'rollback'], true) ? $kind : 'theme',
            'created_at' => \time(),
        ];
        $doc['chains'][$key] = $row;
        self::write($doc);
    }

    /**
     * 切该链 active（唯一允许写 `pg/current` 的入口）。
     *
     * 校验两档（D2）：
     * - 页桶 `pg/{page_token}` 必须存在且非空（严格）；
     * - 构建段 `pub/static/{deploy_build}`：存在 → 严格；不存在 → 宽松放行并告警（兼容尚未产出段的旧部署）。
     */
    public static function promoteTarget(int $websiteId, int $themeId, string $area = self::AREA_FRONTEND): bool
    {
        // 必须**重新读盘**：调用方（如 SystemReleaseManager::promoteAllChains）可能刚写过 hosts，
        // 若沿用同一请求内的旧快照，会把 hosts 覆盖回空值（实测：hosts=[] 覆盖 ["example.test"]）。
        $doc = self::read();
        $key = self::chainKey($websiteId, $themeId, $area);
        $row = \is_array($doc['chains'][$key] ?? null) ? $doc['chains'][$key] : [];
        $target = \is_array($row['target'] ?? null) ? $row['target'] : null;
        if ($target === null) {
            return false;
        }
        // hosts 是链级字段，promote 只改 active/target/supported，其余字段原样保留
        $hosts = \is_array($row['hosts'] ?? null) ? \array_values($row['hosts']) : [];

        // 2026-11-01 单调保护（防版本倒退）：普通 promote 只允许**推进到新代次**。
        // 若 target 的 generation_uid 已是窗口内的旧条目（draining/retired），
        // 说明调用方拿旧 pair 又 stage 了一次；此时切过去就是"版本倒退"
        // （实测：theme:update 把 active 从 v277 退回 v276）。
        // 真正要回退必须走 ThemeReleaseManager::rollback()（它显式改 target 的 kind/来源）。
        $targetUid = \strtolower(\trim((string)($target['generation_uid'] ?? '')));
        $activeUidNow = \strtolower(\trim((string)($row['active']['generation_uid'] ?? '')));
        $isRollback = \strtolower(\trim((string)($target['kind'] ?? ''))) === 'rollback';
        if (!$isRollback && $targetUid !== '' && $targetUid !== $activeUidNow) {
            foreach ((array)($row['supported'] ?? []) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $itemUid = \strtolower(\trim((string)($item['generation_uid'] ?? '')));
                if ($itemUid === $targetUid && $itemUid !== $activeUidNow) {
                    return false;
                }
            }
        }

        $pageToken = \strtolower(\trim((string)($target['page_token'] ?? '')));
        if ($pageToken === '' || !self::pageBucketExists($hosts, $pageToken)) {
            return false;
        }
        $deployBuild = \strtolower(\trim((string)($target['deploy_build'] ?? '')));
        $hasSegment = $deployBuild !== '' && \is_dir(self::staticSegmentRoot($deployBuild));

        $previous = \is_array($row['active'] ?? null) ? $row['active'] : [];
        $target['servable'] = true;
        $row['active'] = $target;
        $row['target'] = null;
        $row['has_build_segment'] = $hasSegment;

        // 3 版窗口：旧 active 压入头部，裁到 MAX_SUPPORTED，尾部标记 retired（不立刻删桶）
        $supported = \is_array($row['supported'] ?? null) ? \array_values($row['supported']) : [];
        if ($previous !== []) {
            $previous['state'] = 'draining';
            \array_unshift($supported, $previous);
        }
        // 去重：同 generation_uid 只留最新一条
        $seen = [];
        $kept = [];
        foreach ($supported as $item) {
            $uid = \strtolower(\trim((string)($item['generation_uid'] ?? '')));
            if ($uid === '' || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $kept[] = $item;
        }
        $activeUid = \strtolower((string)($row['active']['generation_uid'] ?? ''));
        // active 必须恒在首位
        $kept = \array_values(\array_filter($kept, static fn(array $i): bool => \strtolower((string)($i['generation_uid'] ?? '')) !== $activeUid));
        \array_unshift($kept, [
            'generation_uid' => (string)($row['active']['generation_uid'] ?? ''),
            'page_token' => $pageToken,
            'state' => 'active',
            'servable' => true,
            'kind' => (string)($row['active']['kind'] ?? 'theme'),
            'created_at' => (int)($row['active']['created_at'] ?? \time()),
        ]);
        // 2026-11-01（W1/定论）：窗口恒为 3 条。此前只把超出的标 retired 却**不裁掉**，
        // 窗口会无界增长（实测发两次后变成 5 条）。现在先裁到 MAX_SUPPORTED，再标记。
        $kept = \array_slice($kept, 0, self::MAX_SUPPORTED);
        foreach ($kept as $index => $item) {
            if ($index >= self::MAX_SUPPORTED) {
                $kept[$index]['state'] = 'retired';
            }
        }
        $row['supported'] = $kept;
        $row['hosts'] = $hosts;
        $doc['chains'][$key] = $row;
        self::write($doc);

        // 只重指该链 hosts 的 pg/current
        foreach ($hosts as $host) {
            FpcHtmlDiskStore::pointHostGeneration((string)$host, $pageToken);
        }
        GenerationRegistry::reset();
        GenerationRegistry::preload();

        return true;
    }

    /**
     * 首次启用引导（D16）：逐链用当前部署号 + 指针 pair 初始化 active，幂等。
     *
     * 2026-11-01 修正：原先只在**没有任何链**时建一条 `0|0|frontend`，
     * 导致指针文件里有 8 个站点主题对、发布状态里只有 1 条链 —— 其它站点的
     * 窗口不存在，GC 的保留集会漏掉它们正在用的构建段。现在改为**逐条补齐**：
     * 对指针 map 里每个 `{site}|{theme}|{area}` 建立缺失的链。
     */
    public static function bootstrapIfMissing(): bool
    {
        $doc = self::read();
        $chains = \is_array($doc['chains'] ?? null) ? $doc['chains'] : [];
        $deployBuild = StaticBuildId::current();
        if ($deployBuild === '') {
            return false;
        }
        $created = 0;

        foreach (self::pointerPairs() as $chainKey => $pair) {
            if (isset($chains[$chainKey])) {
                continue;
            }
            $chains[$chainKey] = self::newChain($deployBuild, $pair, []);
            $created++;
        }

        // 指针文件缺失/为空时，至少保留一条默认链（单站场景）
        if ($created === 0 && $chains === []) {
            $pair = '';
            try {
                if (\class_exists(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::class)) {
                    $pair = \trim((string)\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pairSegment());
                }
            } catch (\Throwable) {
                $pair = '';
            }
            if ($pair === '') {
                $pair = self::pointerPairSegment();
            }
            $chains[self::chainKey(0, 0)] = self::newChain($deployBuild, $pair, []);
            $created++;
        }

        if ($created === 0) {
            return false;
        }
        $doc['chains'] = $chains;
        self::write($doc);
        GenerationRegistry::reset();

        return true;
    }

    /** @param list<string> $hosts */
    private static function newChain(string $deployBuild, string $pair, array $hosts): array
    {
        $uid = self::composeUid($deployBuild, $pair);
        // 2026-11-01 修正：新建链必须沿用**该站实际正在服务**的页桶 token，
        // 不能现场用 pageGenerationToken() 现算 —— 现算出的 token 在磁盘上通常
        // 还没有目录，会让 publish_state 与真实桶错位（实测踩过：状态写 bbec2a…，
        // 实际 pg/current 仍指 a7e984c6…）。取不到才回退计算值。
        $token = self::existingPageToken($hosts);
        if ($token === '') {
            $token = FpcHtmlDiskStore::pageGenerationToken();
        }

        return [
            'hosts' => $hosts,
            'active' => [
                'generation_uid' => $uid,
                'page_token' => $token,
                'deploy_build' => $deployBuild,
                'theme_generation' => $pair,
                'kind' => 'system',
                'created_at' => \time(),
            ],
            'target' => null,
            'supported' => [[
                'generation_uid' => $uid,
                'page_token' => $token,
                'state' => 'active',
                'servable' => true,
                'kind' => 'system',
                'created_at' => \time(),
            ]],
        ];
    }

    /**
     * 该链实际在用的页桶 token：优先读 hosts 的 `pg/current` 指向；
     * hosts 为空时按 live 根下任一 host 的 `pg/current` 兜底。
     *
     * 公开，因为发布侧（主题就地固化、系统更新）**必须**用它取 token：
     * 现场用 `pageGenerationToken()` 现算出的 token 在盘上通常还没有页面，
     * 会让状态与真实桶错位（实测踩过两次）。
     *
     * @param list<string> $hosts
     */
    public static function existingPageToken(array $hosts = []): string
    {
        $root = self::liveRootSafe();
        if ($root === '') {
            return '';
        }
        $candidates = [];
        foreach ($hosts as $host) {
            $host = \strtolower(\trim((string)$host));
            if ($host !== '') {
                $candidates[] = $host;
            }
        }
        foreach ($candidates as $host) {
            $token = \strtolower((string)@\readlink($root . '/' . $host . '/pg/current'));
            if (\preg_match('/^[a-f0-9]{16}$/', $token) === 1) {
                return $token;
            }
        }
        // hosts 未登记：取任一已有 host 的 current（首次引导，各站通常同代）
        foreach ((array)@\scandir($root) as $host) {
            if ($host === '.' || $host === '..' || !\is_string($host)) {
                continue;
            }
            $token = \strtolower((string)@\readlink($root . '/' . $host . '/pg/current'));
            if (\preg_match('/^[a-f0-9]{16}$/', $token) === 1) {
                return $token;
            }
        }

        return '';
    }

    private static function liveRootSafe(): string
    {
        try {
            return FpcHtmlDiskStore::liveRoot();
        } catch (\Throwable) {
            return \rtrim((string)BP, '/\\') . '/generated/html/live';
        }
    }

    /** @return array<string,string> 链键 => pair_segment（来自主题更新指针） */
    private static function pointerPairs(): array
    {
        $file = \rtrim((string)BP, '/\\') . '/var/theme-update/current.json';
        if (!\is_file($file)) {
            return [];
        }
        $raw = @\file_get_contents($file);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (!\is_array($decoded) || !\is_array($decoded['map'] ?? null)) {
            return [];
        }
        $pairs = [];
        foreach ((array)$decoded['map'] as $key => $row) {
            if (!\is_array($row) || \preg_match('/^\d+\|\d+\|[a-z]+$/D', (string)$key) !== 1) {
                continue;
            }
            $pair = \trim((string)($row['pair_segment'] ?? ''));
            if ($pair === '') {
                $pair = \trim((string)($row['batch_id'] ?? ''));
            }
            if ($pair !== '') {
                $pairs[(string)$key] = $pair;
            }
        }

        return $pairs;
    }

    /** 从主题更新指针文件里取该站该主题的 pair_segment（无则回退 batch_id）。 */
    private static function pointerPairSegment(): string
    {
        $file = \rtrim((string)BP, '/\\') . '/var/theme-update/current.json';
        if (!\is_file($file)) {
            return '';
        }
        $raw = @\file_get_contents($file);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (!\is_array($decoded) || !\is_array($decoded['map'] ?? null)) {
            return '';
        }
        foreach ((array)$decoded['map'] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $pair = \trim((string)($row['pair_segment'] ?? ''));
            if ($pair !== '') {
                return $pair;
            }
            $batch = \trim((string)($row['batch_id'] ?? ''));
            if ($batch !== '') {
                return $batch;
            }
        }

        return '';
    }

    // ---------------------------------------------------------------- 工具

    /**
     * 所有链 `supported` 窗口内出现的 `deploy_build`（供 GC 当保留集用）。
     *
     * 2026-11-01（Task 5）：GC 不能只按"最近 N 代 / 代龄"猜——A 站窗口内的段
     * 可能被 B 站发布后的 GC 删掉，旧标签页随即 404。保留集必须来自发布状态本身。
     *
     * @return list<string>
     */
    public static function keptDeployBuilds(): array
    {
        $builds = [];
        foreach ((array)(self::read()['chains'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $active = \is_array($row['active'] ?? null) ? $row['active'] : [];
            $activeBuild = \strtolower(\trim((string)($active['deploy_build'] ?? '')));
            if (\preg_match('/^[0-9a-f]{12}$/', $activeBuild) === 1) {
                $builds[$activeBuild] = true;
            }
            foreach ((array)($row['supported'] ?? []) as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                // generation_uid 形如 `{deploy_build}~{theme_generation}`
                $uid = \trim((string)($item['generation_uid'] ?? ''));
                $sep = \strpos($uid, '~');
                $build = \strtolower($sep === false ? $uid : \substr($uid, 0, $sep));
                if (\preg_match('/^[0-9a-f]{12}$/', $build) === 1) {
                    $builds[$build] = true;
                }
            }
        }

        return \array_keys($builds);
    }

    public static function composeUid(string $deployBuild, string $themeGeneration): string
    {
        $build = \strtolower(\trim($deployBuild));
        $generation = \trim($themeGeneration);
        if ($build === '') {
            return $generation;
        }

        return $generation === '' ? $build : $build . '~' . $generation;
    }

    /** `generation_uid` 落盘/做 tag 时的安全段（D15）。 */
    public static function generationUidToPathSegment(string $uid): string
    {
        return (string)\preg_replace('/[^A-Za-z0-9._-]+/', '_', \trim($uid));
    }

    /** @var string|null 测试/CLI 显式覆盖的状态文件路径 */
    private static ?string $stateFileOverride = null;
    /** @var bool 是否处于单元测试进程（写真实状态文件时拒绝，防止测试污染线上发布状态） */
    private static bool $testProcessMode = false;

    /** 覆盖状态文件路径（仅测试用；传 null 恢复默认）。 */
    public static function overrideStateFile(?string $path): void
    {
        self::$stateFileOverride = $path;
        GenerationRegistry::reset();
    }

    /** 标记本进程为测试进程：写真实状态文件的调用将被拒绝（防污染）。 */
    public static function markTestProcess(bool $flag = true): void
    {
        self::$testProcessMode = $flag;
    }

    /** 当前生效的状态文件路径。 */
    public static function stateFile(): string
    {
        if (self::$stateFileOverride !== null && self::$stateFileOverride !== '') {
            return self::$stateFileOverride;
        }

        return \rtrim((string)BP, '/\\') . '/' . self::STATE_FILE;
    }

    /**
     * 写盘前守卫：测试进程必须显式 overrideStateFile() 到临时文件，
     * 否则拒绝写入真实 `var/deploy/publish_state.json`（真实环境已因此被污染过一次）。
     */
    private static function assertWritable(): void
    {
        if (!self::$testProcessMode) {
            return;
        }
        if (self::$stateFileOverride !== null && self::$stateFileOverride !== '') {
            return;
        }
        throw new \RuntimeException('publish_state_test_writes_real_file_forbidden');
    }

    private static function staticSegmentRoot(string $deployBuild): string
    {
        return \rtrim((string)BP, '/\\') . '/pub/static/' . $deployBuild;
    }

    /** @param list<string> $hosts */
    private static function pageBucketExists(array $hosts, string $pageToken): bool
    {
        if ($pageToken === '') {
            return false;
        }
        $roots = [];
        try {
            $roots[] = FpcHtmlDiskStore::liveRoot();
        } catch (\Throwable) {
            $roots[] = \rtrim((string)BP, '/\\') . '/generated/html/live';
        }
        // hosts 里登记了本次发布涉及的域名时，优先按它们查（严格）；
        // 查不到再回退"任意 host 命中"——因为桶目录按 host 分，发布前 hosts 可能还没刷新。
        $ordered = [];
        foreach ($hosts as $host) {
            $host = \strtolower(\trim((string)$host));
            if ($host !== '') {
                $ordered[] = $host;
            }
        }
        foreach ($roots as $root) {
            foreach ($ordered as $host) {
                if (self::bucketDirAlreadyPopulated($root . '/' . $host . '/pg/' . $pageToken)) {
                    return true;
                }
            }
        }
        foreach ($roots as $root) {
            foreach ((array)@\scandir($root) as $host) {
                if ($host === '.' || $host === '..' || !\is_string($host)) {
                    continue;
                }
                if (self::bucketDirAlreadyPopulated($root . '/' . $host . '/pg/' . $pageToken)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function bucketDirAlreadyPopulated(string $dir): bool
    {
        return \is_dir($dir) && \count((array)@\scandir($dir)) > 2;
    }

    /**
     * 发布期写整个状态文档（供 SystemReleaseManager 刷新 hosts 等）。
     * 请求期禁止调用；唯一的"切 active"入口仍是 promoteTarget()。
     *
     * @param array<string,mixed> $doc
     */
    public static function persist(array $doc): void
    {
        self::write($doc);
        GenerationRegistry::reset();
    }

    /** @param array<string,mixed> $doc */
    private static function write(array $doc): void
    {
        self::assertWritable();
        $file = self::stateFile();
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException('publish_state_mkdir_failed');
        }
        $payload = \json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($payload === false) {
            throw new \RuntimeException('publish_state_encode_failed');
        }
        $tmp = $file . '.tmp.' . \getmypid();
        if (@\file_put_contents($tmp, $payload) === false || !@\rename($tmp, $file)) {
            throw new \RuntimeException('publish_state_write_failed');
        }
    }
}
