<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Model;

use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeContext;
use Weline\SystemConfig\Service\ConfigCacheInvalidationFeedback;
use Weline\SystemConfig\Service\ConfigCacheInvalidationService;
use Weline\SystemConfig\Service\ScopeConfigCacheInvalidator;
use Weline\SystemConfig\Service\SystemConfigLockService;
use Weline\SystemConfig\Service\SystemConfigResourceChangePublisher;

#[Table(comment: 'System config table')]
#[Index(name: 'idx_system_config_identity', columns: ['module', 'area', 'key', 'scope', 'locale'], type: 'UNIQUE')]
#[Index(name: 'idx_system_config_module_scope', columns: ['module', 'area', 'scope', 'locale'])]
#[Index(name: 'idx_key_module_area', columns: ['key', 'module', 'area'])]
#[Index(name: 'idx_module', columns: ['module'])]
class SystemConfig extends \Weline\Framework\Database\Model
{
    private const CACHE_HIT_FLAG = '__system_config_cache_hit';

    /** 请求 memo 里标记“结论性快照”的数据来源：一次库读，或整表进程快照。 */
    private const SOURCE_DB = 'db';
    private const SNAPSHOT_SOURCE = 'snapshot';

    /** Request-memo sentinel for a concluded "no row" resolution (negative cache). */
    private const RESOLVED_ROW_MISS = '__system_config_resolved_row_miss__';

    public const SCOPE_GLOBAL = 'default.default.default';
    public const LOCALE_DEFAULT = 'default';

    public const VALUE_TYPE_STRING = 'string';
    public const VALUE_TYPE_INT = 'int';
    public const VALUE_TYPE_FLOAT = 'float';
    public const VALUE_TYPE_BOOL = 'bool';
    public const VALUE_TYPE_JSON = 'json';
    public const VALUE_TYPE_NULL = 'null';

    public const schema_table = 'system_config';
    /** @var list<string> */
    /** Column order matches physical UNIQUE `idx_system_config_identity` for promote. */
    public const schema_primary_keys = ['module', 'area', 'key', 'scope', 'locale'];

    #[Col('varchar', 120, nullable: false, comment: 'Config key')]
    public const schema_fields_KEY = 'key';

    #[Col('text', comment: 'Config value')]
    public const schema_fields_VALUE = 'v';

    #[Col('varchar', 120, nullable: false, comment: 'Module')]
    public const schema_fields_MODULE = 'module';

    #[Col('varchar', 120, nullable: false, default: 'frontend', comment: 'Area')]
    public const schema_fields_AREA = 'area';

    #[Col('varchar', 191, nullable: false, default: self::SCOPE_GLOBAL, comment: 'Config scope: website.store.extra')]
    public const schema_fields_SCOPE = 'scope';

    #[Col('varchar', 32, nullable: false, default: self::LOCALE_DEFAULT, comment: 'Locale code or default')]
    public const schema_fields_LOCALE = 'locale';

    #[Col('varchar', 16, nullable: false, default: self::VALUE_TYPE_STRING, comment: 'Serialized value type')]
    public const schema_fields_VALUE_TYPE = 'value_type';

    #[Col('smallint', 1, nullable: false, default: 0, comment: 'Whether value is sensitive')]
    public const schema_fields_IS_SENSITIVE = 'is_sensitive';

    #[Col('smallint', 1, nullable: false, default: 1, comment: 'Whether config row is active')]
    public const schema_fields_IS_ACTIVE = 'is_active';

    #[Col('int', 11, nullable: false, default: 1, comment: 'Optimistic row version')]
    public const schema_fields_VERSION = 'version';

    #[Col('text', nullable: true, comment: 'Config metadata JSON')]
    public const schema_fields_METADATA = 'metadata';

    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    #[Col('varchar', 96, nullable: true, comment: 'Updated by actor')]
    public const schema_fields_UPDATED_BY = 'updated_by';

    public const area_BACKEND = 'backend';
    public const area_FRONTEND = 'frontend';

    /**
     * Request-local write generation. A module snapshot may only prove absence
     * of a key when it was loaded at the current generation and is complete
     * (a DB read, or a still-valid non-empty cache snapshot); any write bumps
     * the generation and forces the strong exact-row fallback.
     */
    private const MUTATION_GENERATION_KEY = 'system_config.mutation_generation';

    /**
     * 整表快照的**跨进程**代次键。
     *
     * 落在 `system_config` 池的自定义键上：该池是 `hijack_exempt + durable`，
     * WLS worker 与 CLI 读写的都是同一份持久缓存，因此这条代次能被所有进程看到。
     * 写路径在提交后推进它（{@see self::bumpTableSnapshotGeneration()}），
     * 每个 worker 读取前比对；不一致就丢掉本地快照重建。
     *
     * 没有这条代次时，A worker 保存配置后，B/C/D worker 会一直拿旧快照证明
     * “某 key 不存在”，表现为“配置改了不生效”——因为 `MUTATION_GENERATION_KEY`
     * 走 {@see \Weline\Framework\Runtime\RequestContext} 的请求级静态，不跨进程。
     */
    private const TABLE_SNAPSHOT_GENERATION_KEY = 'system_config_table_snapshot_generation';

    /** 请求内记忆代次，避免每次切片都读一次持久缓存。 */
    private const TABLE_SNAPSHOT_GENERATION_MEMO = 'system_config.table_snapshot_generation';

    /** 代次键寿命：与 scope generation 一致，过期只是让快照重建一次。 */
    private const TABLE_SNAPSHOT_GENERATION_TTL = 86400 * 30;

    public array $_index_sort_keys = ['module', 'area', 'scope', 'locale', 'key'];
    public array $_unit_primary_keys = ['module', 'area', 'key', 'scope', 'locale'];

    /**
     * 进程级 module rows：area → module → rowsCacheKey → rows。
     * 叶子既可能是 list<row>（单 scope/locale），也可能是
     * [scope][locale] => list<row>（批量读取）。
     * 键含 scope/locale/versionVector；模块变更时 unset 整块 [$area][$module]。
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    public static array $configs = [];

    /**
     * 整表进程快照：area → module → scope → locale → list<row>。
     *
     * `null` 表示尚未加载（或已被失效）。加载后，任何 (area, module, scope, locale)
     * 组合都是纯内存切片，请求期不再逐 key 探针查库。表规模是商家配置量级
     * （本机 37 组 module/area、264 行），一次读进内存的成本远低于每个请求重复探测。
     *
     * 失效由写路径驱动：后台保存 → {@see \Weline\SystemConfig\Service\ConfigCacheInvalidationService}
     * → {@see self::clearProcessCache()} 丢掉本进程的整张快照（按切片丢弃会把刚写入的
     * 行永久藏掉），再加上提交后的 {@see self::bumpTableSnapshotGeneration()} 推进
     * 跨进程代次，使**其它 worker**的静态快照同时失效。快照本身带着建表时的代次
     * （`self::$tableSnapshotGeneration`），每次切片前比对，不一致即重建。
     */
    private static ?array $tableSnapshot = null;

    /** 防重入：加载期间再次触发读取时直接走库，避免递归。 */
    private static bool $tableSnapshotLoading = false;

    /** 建快照时观察到的跨进程代次；与当前代次不一致即视为失效。 */
    private static ?int $tableSnapshotGeneration = null;

    public static function clearProcessCache(?string $area = null, ?string $module = null): void
    {
        // 整表快照按“不存在的切片 = 该处确实没有配置”来解释，所以**不能按切片丢弃**：
        // 一旦只 unset 掉 [$area][$module]，后续切片会变成空数组并被当作“权威无行”，
        // 把刚写入的那批行永久藏掉。精确失效代价是整表重读（本机 264 行 / 1 条 SQL），
        // 后台保存频率远低于请求频率，这里直接丢整快照，下次读取自动从库重建。
        self::$tableSnapshot = null;
        self::$tableSnapshotGeneration = null;

        if ($area === null && $module === null) {
            self::$configs = [];
            return;
        }
        if ($area !== null && $module !== null) {
            unset(self::$configs[$area][$module]);
            if (isset(self::$configs[$area]) && self::$configs[$area] === []) {
                unset(self::$configs[$area]);
            }
            return;
        }
        if ($area !== null) {
            unset(self::$configs[$area]);
            return;
        }
        foreach (self::$configs as $areaKey => $modules) {
            unset(self::$configs[$areaKey][$module]);
            if (self::$configs[$areaKey] === []) {
                unset(self::$configs[$areaKey]);
            }
        }
    }

    /**
     * 把整张配置表一次读进进程内存（Worker 预热与首次冷读都会走到这里）。
     *
     * 返回快照覆盖的 (module, area) 组合数，便于预热日志展示覆盖面。失败返回 0，
     * 绝不抛：配置表在 setup:upgrade 之前可能还不存在。
     */
    public static function warmTableSnapshot(): int
    {
        return self::ensureTableSnapshot();
    }

    public static function clearTableSnapshot(): void
    {
        self::$tableSnapshot = null;
        self::$tableSnapshotGeneration = null;
    }

    /**
     * 写路径（提交后）推进整表快照的跨进程代次。
     *
     * Worker 是长驻进程，`self::$tableSnapshot` / `self::$configs` 都是静态的：
     * 只清本进程的静态缓存无法通知其它 worker。代次必须落在跨进程共享的
     * `system_config` 池上，别的 worker 下次读取时会发现代次不符并重建快照。
     *
     * 缓存不可写时不抛：其它 worker 读代次会得到 -1（不可用），
     * {@see self::tableSnapshotStillValid()} 随即拒绝把快照当权威，退回库读——慢但正确。
     */
    public static function bumpTableSnapshotGeneration(): void
    {
        self::$tableSnapshot = null;
        self::$tableSnapshotGeneration = null;
        if (RequestContext::has(self::TABLE_SNAPSHOT_GENERATION_MEMO)) {
            RequestContext::remove(self::TABLE_SNAPSHOT_GENERATION_MEMO);
        }

        try {
            $cache = \w_cache('system_config');
            $current = (int)($cache->getCustom(self::TABLE_SNAPSHOT_GENERATION_KEY) ?? 0);
            $cache->setCustom(
                self::TABLE_SNAPSHOT_GENERATION_KEY,
                $current + 1,
                self::TABLE_SNAPSHOT_GENERATION_TTL,
            );
        } catch (\Throwable) {
            // 见方法注释：读不到代次的 worker 会自动退回库读。
        }
    }

    /**
     * 快照是否已加载（含"整表为空"这一结论性状态）。
     *
     * 预热期用它区分"读成功但表里没配置"和"读失败"：前者不该报 failures。
     */
    public static function hasTableSnapshot(): bool
    {
        return self::$tableSnapshot !== null;
    }

    /**
     * @return int 快照内的 (module, area) 组合数；加载失败为 0
     */
    private static function ensureTableSnapshot(): int
    {
        if (self::tableSnapshotStillValid()) {
            return self::countSnapshotTargets(self::$tableSnapshot ?? []);
        }
        if (self::$tableSnapshotLoading) {
            return 0;
        }
        // 事务进行中不建快照：快照读走独立连接，看不到未提交行，
        // 空结果会被当成“确实没配置”，把本事务刚写入的行藏掉。
        // 事务内的读取继续走事务感知的库读分支。
        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            return 0;
        }

        // 拿不到跨进程代次就不建快照：快照用“空切片 = 确实没有配置”来解释，
        // 无法在别的 worker 写入后失效时，它就是一个会撒谎的权威。宁可退回库读。
        $generation = self::currentTableSnapshotGeneration();
        if ($generation < 0) {
            return 0;
        }

        self::$tableSnapshotLoading = true;
        try {
            /** @var static $model */
            $model = ObjectManager::getInstance(self::class, [], false);
            $rows = $model->clearData()->clearQuery()
                ->select()
                ->fetchArray();

            $snapshot = [];
            foreach (\is_array($rows) ? $rows : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $module = \trim((string)($row[self::schema_fields_MODULE] ?? ''));
                $area = \trim((string)($row[self::schema_fields_AREA] ?? ''));
                $scope = (string)($row[self::schema_fields_SCOPE] ?? '');
                $locale = (string)($row[self::schema_fields_LOCALE] ?? '');
                if ($module === '' || $area === '' || $scope === '' || $locale === '') {
                    continue;
                }
                $snapshot[$area][$module][$scope][$locale][] = $row;
            }

            self::$tableSnapshot = $snapshot;
            self::$tableSnapshotGeneration = $generation;

            return self::countSnapshotTargets($snapshot);
        } catch (\Throwable) {
            self::$tableSnapshot = null;
            self::$tableSnapshotGeneration = null;

            return 0;
        } finally {
            self::$tableSnapshotLoading = false;
        }
    }

    /**
     * 读当前跨进程代次：`-1` 表示读不到（缓存不可用），`>= 0` 为有效代次。
     *
     * 首次读到的空值会显式落一个 0 再读回——这样才能把“还没有人写过”和
     * “这个池根本读不出来”区分开；后者必须让快照整体放弃。
     * 记忆放在请求级 Context 里，只在有请求身份时生效（预热期没有请求身份，
     * 记忆若跨请求留存会让快照一直不失效）。
     */
    private static function currentTableSnapshotGeneration(): int
    {
        $memoKey = self::TABLE_SNAPSHOT_GENERATION_MEMO;
        $requestId = RequestContext::getRequestId();
        $memoizable = \is_string($requestId) && $requestId !== '';
        if ($memoizable && RequestContext::has($memoKey)) {
            $memo = RequestContext::get($memoKey);

            return \is_int($memo) ? $memo : -1;
        }

        $generation = -1;
        try {
            $cache = \w_cache('system_config');
            $raw = $cache->getCustom(self::TABLE_SNAPSHOT_GENERATION_KEY);
            if ($raw === null) {
                $cache->setCustom(self::TABLE_SNAPSHOT_GENERATION_KEY, 0, self::TABLE_SNAPSHOT_GENERATION_TTL);
                $raw = $cache->getCustom(self::TABLE_SNAPSHOT_GENERATION_KEY);
            }
            if ($raw !== null) {
                $generation = (int)$raw;
            }
        } catch (\Throwable) {
            $generation = -1;
        }

        if ($memoizable) {
            RequestContext::set($memoKey, $generation);
        }

        return $generation;
    }

    /**
     * 快照是否仍在当前跨进程代次下有效；失效时就地丢弃本地快照。
     */
    private static function tableSnapshotStillValid(): bool
    {
        if (self::$tableSnapshot === null || self::$tableSnapshotGeneration === null) {
            return false;
        }

        $current = self::currentTableSnapshotGeneration();
        if ($current >= 0 && $current === self::$tableSnapshotGeneration) {
            return true;
        }

        // 代次变了（别的 worker 提交了配置）或读不到代次：本地快照立即作废，
        // 下次读取重建（最坏一次全表读）。
        self::$tableSnapshot = null;
        self::$tableSnapshotGeneration = null;

        return false;
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    private static function countSnapshotTargets(array $snapshot): int
    {
        $count = 0;
        foreach ($snapshot as $modules) {
            $count += \count($modules);
        }

        return $count;
    }

    /**
     * 从整表快照切出 [scope][locale] => list<row>。
     *
     * 快照与库读取等价：切片为该范围内**全部**行，所以空结果同样结论性
     * （调用方据此把模块快照标记为权威，不再逐 key 探针）。
     * 该等价关系成立的**前提**是：快照一旦加载就覆盖全表，局部失效必须丢整张快照
     * （见 {@see self::clearProcessCache()}）。
     *
     * @param list<string> $scopes
     * @param list<string> $locales
     * @return array<string, array<string, list<array<string, mixed>>>>|null null=快照不可用
     */
    private static function tableSnapshotSlice(
        string $module,
        string $area,
        array $scopes,
        array $locales,
    ): ?array {
        // 切片前先验代次：别的 worker 提交后本进程的静态快照必须作废，
        // 否则会拿旧行证明“该 key 不存在”。
        if (!self::tableSnapshotStillValid()) {
            return null;
        }

        $batch = [];
        foreach ($scopes as $scope) {
            foreach ($locales as $locale) {
                $rows = self::$tableSnapshot[$area][$module][$scope][$locale] ?? [];
                $batch[$scope][$locale] = \is_array($rows) ? $rows : [];
            }
        }

        return $batch;
    }

    /**
     * 把一个模块在某作用域身份下的配置行一次性读进进程内存。
     *
     * 与请求路径共用同一条批量读取（{@see self::getExactModuleRowsForScopes()}），
     * 所以预热写入的正是请求会命中的那块 `self::$configs`；后台保存后由
     * {@see \Weline\SystemConfig\Service\ConfigCacheInvalidationService} 调
     * `clearProcessCache($area, $module)` 整块丢弃，下一次读取自动从库里重建。
     *
     * 预热失败不抛：Worker READY 不能被配置读取拖垮。
     */
    public function warmModuleRows(
        string $module,
        string $area,
        \Weline\Framework\Runtime\ScopeIdentity $identity,
        ?string $locale = null,
    ): bool {
        $module = \trim($module);
        $area = \trim($area);
        if ($module === '' || $area === '') {
            return false;
        }

        try {
            /** @var \Weline\SystemConfig\Service\SystemConfigScopeResolver $resolver */
            $resolver = ObjectManager::getInstance(\Weline\SystemConfig\Service\SystemConfigScopeResolver::class);
            $scopes = $resolver->chainFromIdentity($identity);
            $locales = $this->getFallbackLocales($this->normalizeLocale($locale));
            $this->getExactModuleRowsForScopes($module, $area, $scopes, $locales);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 表里实际存在配置的 (module, area) 组合，一次分组查询。
     *
     * 预热按“库里真的有哪些模块配置”发现，不在运行时代码里维护一份模块名单；
     * 新增模块配置不需要改预热代码。
     *
     * @return list<array{module: string, area: string}>
     */
    public static function configuredTargets(): array
    {
        // 整表快照已就位时直接派生：覆盖率指标不该再发一次全表分组查询。
        // 用 stillValid 而不是 null 判断：快照被别的 worker 写脏时宁可查一次库。
        if (self::tableSnapshotStillValid()) {
            $targets = [];
            foreach (self::$tableSnapshot ?? [] as $area => $modules) {
                foreach (array_keys($modules) as $module) {
                    $key = $module . '|' . $area;
                    $targets[$key] = ['module' => (string)$module, 'area' => (string)$area];
                }
            }

            return \array_values($targets);
        }

        try {
            /** @var static $model */
            $model = ObjectManager::getInstance(self::class, [], false);
            $rows = $model->clearData()->clearQuery()
                ->fields([self::schema_fields_MODULE, self::schema_fields_AREA])
                ->group(self::schema_fields_MODULE . ',' . self::schema_fields_AREA)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $targets = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $module = \trim((string)($row[self::schema_fields_MODULE] ?? ''));
            $area = \trim((string)($row[self::schema_fields_AREA] ?? ''));
            if ($module === '' || $area === '') {
                continue;
            }
            $targets[$module . '|' . $area] = ['module' => $module, 'area' => $area];
        }

        return \array_values($targets);
    }

    /**
     * Record how the module snapshot used by this request was obtained.
     *
     * A DB snapshot at the current write generation proves that a key is
     * absent. A cache snapshot proves absence only when it is complete
     * (non-empty): the process/shared keys embed the scope version vector, so a
     * valid non-empty snapshot is the same read contract that already serves
     * present values. Empty/negative cache entries stay non-authoritative and
     * keep the exact-row fallback, so a row inserted before invalidation
     * reaches this worker is never hidden by a negative cache.
     */
    private function markModuleSnapshot(
        string $module,
        string $area,
        string $scope,
        string $locale,
        string $source,
        bool $complete = true,
    ): void {
        $key = $this->buildRequestCacheKey('module_snapshot', $module, $area, null, $scope, $locale);
        $generation = $this->mutationGeneration();
        $existing = RequestContext::get($key);
        if (\is_array($existing)
            && (int)($existing['generation'] ?? -1) === $generation
            && (\in_array(($existing['source'] ?? ''), [self::SOURCE_DB, self::SNAPSHOT_SOURCE], true)
                || ($existing['complete'] ?? false) === true)
        ) {
            // Do not downgrade a snapshot that was already loaded in this
            // request just because a later read reused the process cache.
            return;
        }

        RequestContext::set($key, [
            'source' => $source,
            'generation' => $generation,
            'complete' => $complete,
        ]);
    }

    private function isModuleSnapshotAuthoritative(
        string $module,
        string $area,
        string $scope,
        string $locale,
    ): bool {
        $marker = RequestContext::get(
            $this->buildRequestCacheKey('module_snapshot', $module, $area, null, $scope, $locale),
        );
        if (!\is_array($marker)) {
            return false;
        }

        return (int)($marker['generation'] ?? -1) === $this->mutationGeneration()
            && (\in_array(($marker['source'] ?? ''), [self::SOURCE_DB, self::SNAPSHOT_SOURCE], true)
                || ($marker['complete'] ?? false) === true);
    }

    /**
     * 空结果能否当作"确实没有配置"。
     *
     * 整表快照一加载就是全表的完整镜像，而且任何写入都会在开始时把它丢掉
     * （{@see self::bumpMutationGeneration()}），所以快照在前时，空的进程袋/空切片
     * 同样是结论性的：不必再逐 scope×locale 探针查库。
     *
     * 这是单请求 73 条 `w_system_config` 的来源：模块本来没有配置时，旧逻辑
     * 对每个 key 把 4 个 fallback scope × 2 个 locale 各探一次。
     */
    private function absenceIsConclusive(): bool
    {
        return self::tableSnapshotStillValid();
    }

    /**
     * Mutation generation carrier.
     *
     * Web requests use the request-shared carrier ({@see RequestContext::sharedGeneration()})
     * so a write performed on one Fiber of the request is visible to every other
     * Fiber copy of the Context (Context-local state is per Fiber). CLI / tests
     * have no request id, so they keep the Context-local carrier unchanged.
     */
    private function mutationGeneration(): int
    {
        if ($this->hasRequestIdentity()) {
            return max(0, RequestContext::sharedGeneration(self::MUTATION_GENERATION_KEY));
        }

        return max(0, (int)RequestContext::get(self::MUTATION_GENERATION_KEY, 0));
    }

    /**
     * Any write in this request invalidates the authority of snapshots loaded
     * before it. The next read either rebuilds from the database or falls back
     * to the exact-row reader. Single-value read memos embed this generation in
     * their key ({@see buildRequestCacheKey()}), so they are dropped as well.
     */
    private function bumpMutationGeneration(): void
    {
        // 写一开始就丢掉整表快照：不能拿写前的快照去证明“此 key 不存在”，
        // 否则写后同请求的读取会被旧数据藏掉。重建代价就是一次全表读。
        // 跨进程那半边由提交后的 {@see self::bumpTableSnapshotGeneration()} 负责。
        self::$tableSnapshot = null;
        self::$tableSnapshotGeneration = null;
        if ($this->hasRequestIdentity()) {
            RequestContext::sharedBumpGeneration(self::MUTATION_GENERATION_KEY);

            return;
        }
        RequestContext::set(self::MUTATION_GENERATION_KEY, $this->mutationGeneration() + 1);
    }

    private function hasRequestIdentity(): bool
    {
        $requestId = RequestContext::getRequestId();

        return \is_string($requestId) && $requestId !== '';
    }

    /**
     * C2 kill switch coverage: single-value request memos must go inert when the
     * framework request-memo switch is off (`generated/framework/request_memo.disabled`
     * or `wls.request_memo.enabled=false`). Reads then fall through to the
     * authoritative row loaders instead of serving a memo captured earlier.
     */
    private function requestMemoEnabled(): bool
    {
        return RequestContext::sharedMemoEnabled();
    }

    private function rememberExactBatchSource(string $requestCacheKey, string $source): void
    {
        RequestContext::set($requestCacheKey . ':source', [
            'source' => $source,
            'generation' => $this->mutationGeneration(),
        ]);
    }

    private function readExactBatchSource(string $requestCacheKey): string
    {
        $marker = RequestContext::get($requestCacheKey . ':source');
        if (\is_array($marker)
            && \in_array(($marker['source'] ?? ''), [self::SOURCE_DB, self::SNAPSHOT_SOURCE], true)
            && (int)($marker['generation'] ?? -1) === $this->mutationGeneration()
        ) {
            return (string)$marker['source'];
        }

        return 'cache';
    }

    public function __init()
    {
        parent::__init();
        // Always bind the dedicated pool. parent::__init() sets AbstractModel::_cache
        // to w_cache('database'); the previous !isset() guard left reads/writes on
        // database while ConfigCacheInvalidationService cleared system_config only.
        $this->_cache = w_cache('system_config');
    }

    public function normalizeScope(?string $scope = null): string
    {
        $scope = trim((string)($scope ?? ''));
        if ($scope === '') {
            $scope = ScopeContext::getScope();
        }

        $segments = array_values(array_filter(
            array_map('trim', explode('.', strtolower($scope))),
            static fn(string $segment): bool => $segment !== ''
        ));
        $segments = array_slice($segments, 0, 3);
        while (count($segments) < 3) {
            $segments[] = 'default';
        }

        return implode('.', $segments);
    }

    public function normalizeLocale(?string $locale = null): string
    {
        $locale = trim((string)($locale ?? ''));
        if ($locale === '') {
            $locale = trim((string)RequestContext::get('system_config.locale', ''));
        }
        if ($locale === '') {
            $locale = trim(RequestContext::getWelineUserLang());
        }

        return $locale !== '' ? $locale : self::LOCALE_DEFAULT;
    }

    /**
     * @return list<string>
     */
    public function getFallbackScopes(?string $scope = null): array
    {
        $normalized = $this->normalizeScope($scope);
        [$website, $store, $extra] = explode('.', $normalized) + ['default', 'default', 'default'];

        return array_values(array_unique([
            implode('.', [$website, $store, $extra]),
            implode('.', [$website, $store, 'default']),
            implode('.', [$website, 'default', 'default']),
            self::SCOPE_GLOBAL,
        ]));
    }

    public function getConfigByModule(
        string $module,
        string $area = self::area_FRONTEND,
        ?string $scope = null,
        ?string $locale = null
    ): ?array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('module_rows', $module, $area, null, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $processKey = $this->buildModuleRowsCacheKey($module, $area, $scope, $locale);
        if (isset(self::$configs[$area][$module][$processKey])
            && \is_array(self::$configs[$area][$module][$processKey])
        ) {
            $rows = self::$configs[$area][$module][$processKey];
            RequestContext::set($requestCacheKey, $rows);
            $this->markModuleSnapshot(
                $module,
                $area,
                $scope,
                $locale,
                'cache',
                $rows !== [] || $this->absenceIsConclusive(),
            );
            return $rows;
        }

        $cache = $this->scopedConfigCache($module, $area, $scope, $locale);
        $cacheEntry = $this->readCacheEnvelope($cache, $processKey);
        // Empty envelopes are not durable hits: a prior negative cache must not hide
        // rows inserted after the empty snapshot was written.
        if ($cacheEntry['hit'] && \is_array($cacheEntry['value']) && $cacheEntry['value'] !== []) {
            self::$configs[$area][$module][$processKey] = $cacheEntry['value'];
            RequestContext::set($requestCacheKey, $cacheEntry['value']);
            $this->markModuleSnapshot($module, $area, $scope, $locale, 'cache', true);
            return $cacheEntry['value'];
        }

        $rowsByKey = [];
        $fallbackScopes = $this->getFallbackScopes($scope);
        $fallbackLocales = $this->getFallbackLocales($locale);
        $exact = $this->getExactModuleRowsForScopes($module, $area, $fallbackScopes, $fallbackLocales);
        $exactRows = $exact['rows'];
        foreach ($fallbackScopes as $fallbackScope) {
            foreach ($fallbackLocales as $fallbackLocale) {
                foreach ($exactRows[$fallbackScope][$fallbackLocale] ?? [] as $row) {
                    $rowKey = (string)($row[self::schema_fields_KEY] ?? '');
                    if ($rowKey === '' || isset($rowsByKey[$rowKey])) {
                        continue;
                    }
                    if (array_key_exists(self::schema_fields_IS_ACTIVE, $row) && (int)$row[self::schema_fields_IS_ACTIVE] === 0) {
                        continue;
                    }
                    if (SystemConfigLockService::isRowSuppressed($row)) {
                        continue;
                    }
                    $rowsByKey[$rowKey] = $this->normalizeRow($row, $fallbackScope, $fallbackLocale);
                }
            }
        }

        $rows = array_values($rowsByKey);
        self::$configs[$area][$module][$processKey] = $rows;
        RequestContext::set($requestCacheKey, $rows);
        if ($rows !== []) {
            $this->writeCacheEnvelope($cache, $processKey, $rows);
        }
        $this->markModuleSnapshot(
            $module,
            $area,
            $scope,
            $locale,
            $exact['source'],
            \in_array($exact['source'], [self::SOURCE_DB, self::SNAPSHOT_SOURCE], true) || $rows !== [],
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigMapByModule(
        string $module,
        string $area = self::area_FRONTEND,
        ?string $scope = null,
        ?string $locale = null
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('module_map', $module, $area, null, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return (array)RequestContext::get($requestCacheKey);
        }

        $cache = $this->scopedConfigCache($module, $area, $scope, $locale);
        $cacheEntry = $this->readCacheEnvelope($cache, $this->buildModuleMapCacheKey($module, $area, $scope, $locale));
        if ($cacheEntry['hit']) {
            $cachedMap = is_array($cacheEntry['value']) ? $cacheEntry['value'] : [];
            RequestContext::set($requestCacheKey, $cachedMap);
            return $cachedMap;
        }

        $rows = $this->getConfigByModule($module, $area, $scope, $locale) ?? [];
        $configMap = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowKey = (string)($row[self::schema_fields_KEY] ?? '');
            if ($rowKey === '') {
                continue;
            }
            $configMap[$rowKey] = $this->unserializeValue(
                $row[self::schema_fields_VALUE] ?? null,
                (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
            );
        }

        RequestContext::set($requestCacheKey, $configMap);
        $this->writeCacheEnvelope($cache, $this->buildModuleMapCacheKey($module, $area, $scope, $locale), $configMap);

        return $configMap;
    }

    /**
     * Warm process/request module config maps at cron / CLI entry so later
     * getConfig() hits the module snapshot instead of per-key exact rows.
     *
     * @param list<string>|array<int|string, string> $modules
     */
    public function warmupModuleMaps(
        array $modules,
        ?string $scope = null,
        ?string $locale = null,
    ): int {
        $warmed = 0;
        $seen = [];
        foreach ($modules as $module) {
            $module = trim((string)$module);
            if ($module === '' || isset($seen[$module])) {
                continue;
            }
            $seen[$module] = true;
            foreach ([self::area_BACKEND, self::area_FRONTEND] as $area) {
                $this->getConfigMapByModule($module, $area, $scope, $locale);
            }
            ++$warmed;
        }

        return $warmed;
    }

    public function getConfig(
        string $key,
        string $module,
        string $area,
        mixed $default = null,
        ?string $scope = null,
        ?string $locale = null
    ): mixed {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('resolved', $module, $area, $key, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            return RequestContext::get($requestCacheKey);
        }

        $row = $this->loadResolvedConfigRow($key, $module, $area, $scope, $locale);
        if ($row !== null) {
            $result = $this->unserializeValue(
                $row[self::schema_fields_VALUE] ?? null,
                (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
            );
            RequestContext::set($requestCacheKey, $result);
            return $this->dispatchConfigGetEvent($key, $module, $area, $result, $scope, $locale);
        }

        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $rootKey = (string)array_shift($keys);
            $rootRow = $this->loadResolvedConfigRow($rootKey, $module, $area, $scope, $locale);
            if ($rootRow !== null) {
                $configValue = $this->unserializeValue(
                    $rootRow[self::schema_fields_VALUE] ?? null,
                    (string)($rootRow[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
                );
                $result = $this->extractNestedConfigValue($configValue, $rootKey, $keys);
                if ($result !== null) {
                    RequestContext::set($requestCacheKey, $result);
                    return $this->dispatchConfigGetEvent($key, $module, $area, $result, $scope, $locale);
                }
            }
        }

        RequestContext::set($requestCacheKey, $default);

        return $this->dispatchConfigGetEvent($key, $module, $area, $default, $scope, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $row = $this->loadResolvedConfigRow($key, $module, $area, $scope, $locale);
        if ($row === null) {
            return [
                'found' => false,
                'key' => $key,
                'module' => $module,
                'area' => $area,
                'requested_scope' => $scope,
                'requested_locale' => $locale,
                'fallback_scopes' => $this->getFallbackScopes($scope),
                'fallback_locales' => $this->getFallbackLocales($locale),
                'value' => $default,
                'source' => null,
            ];
        }

        $value = $this->unserializeValue(
            $row[self::schema_fields_VALUE] ?? null,
            (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING)
        );

        return [
            'found' => true,
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'requested_scope' => $scope,
            'requested_locale' => $locale,
            'fallback_scopes' => $this->getFallbackScopes($scope),
            'fallback_locales' => $this->getFallbackLocales($locale),
            'value' => $value,
            'source' => [
                'scope' => (string)($row[self::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL),
                'locale' => (string)($row[self::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT),
                'version' => (int)($row[self::schema_fields_VERSION] ?? 0),
                'value_type' => (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING),
                'is_sensitive' => (int)($row[self::schema_fields_IS_SENSITIVE] ?? 0) === 1,
                'metadata' => $this->decodeJson((string)($row[self::schema_fields_METADATA] ?? '')),
            ],
        ];
    }

    /**
     * TASK-P1C-001：typed Scope 解析 + source DTO。
     */
    public function resolveTypedConfig(
        string $key,
        string $module,
        string $area,
        \Weline\Framework\Runtime\ScopeIdentity $identity,
        ?string $locale = null,
        mixed $default = null,
    ): \Weline\SystemConfig\Api\Scope\ConfigScopeValue {
        $hasDefault = \func_num_args() >= 6;
        $locale = $this->normalizeLocale($locale);
        /** @var \Weline\SystemConfig\Service\SystemConfigScopeResolver $resolver */
        $resolver = ObjectManager::getInstance(\Weline\SystemConfig\Service\SystemConfigScopeResolver::class);
        $storageScopes = $resolver->chainFromIdentity($identity);
        $fallbackLocales = $this->getFallbackLocales($locale);
        $exactRows = $this->getExactModuleRowsForScopes($module, $area, $storageScopes, $fallbackLocales)['rows'];
        $records = [];
        foreach ($storageScopes as $storageScope) {
            foreach ($fallbackLocales as $fallbackLocale) {
                $row = $this->findRowByKey($key, $exactRows[$storageScope][$fallbackLocale] ?? []);
                if ($row === null) {
                    continue;
                }
                if (\array_key_exists(self::schema_fields_IS_ACTIVE, $row) && (int)$row[self::schema_fields_IS_ACTIVE] === 0) {
                    continue;
                }
                if (SystemConfigLockService::isRowSuppressed($row)) {
                    continue;
                }
                $locKey = $fallbackLocale === self::LOCALE_DEFAULT ? '' : $fallbackLocale;
                $recordKey = \Weline\SystemConfig\Service\SystemConfigScopeResolver::recordKey($storageScope, $locKey);
                if (isset($records[$recordKey])) {
                    continue;
                }
                $records[$recordKey] = [
                    \Weline\SystemConfig\Service\SystemConfigScopeResolver::KEY_VALUE => $this->unserializeValue(
                        $row[self::schema_fields_VALUE] ?? null,
                        (string)($row[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING),
                    ),
                    \Weline\SystemConfig\Service\SystemConfigScopeResolver::KEY_VERSION => (int)($row[self::schema_fields_VERSION] ?? 0),
                    \Weline\SystemConfig\Service\SystemConfigScopeResolver::KEY_SENSITIVE => (int)($row[self::schema_fields_IS_SENSITIVE] ?? 0) === 1,
                    \Weline\SystemConfig\Service\SystemConfigScopeResolver::KEY_METADATA => $this->decodeJson((string)($row[self::schema_fields_METADATA] ?? '')),
                ];
            }
        }

        return $resolver->resolveForIdentity(
            $records,
            $identity,
            $locale === self::LOCALE_DEFAULT ? '' : $locale,
            $default,
            $hasDefault,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getScopedConfigRow(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null
    ): ?array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        // Request memo (positive AND negative) — scope resolvers probe the same
        // exact rows repeatedly per request. Generation keeps read-after-write.
        $memoEnabled = $this->requestMemoEnabled();
        $requestCacheKey = $this->buildRequestCacheKey('scoped_row', $module, $area, $key, $scope, $locale);
        if ($memoEnabled && RequestContext::isInitialized() && RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);
            if ($cached === self::RESOLVED_ROW_MISS) {
                return null;
            }

            return \is_array($cached) ? $cached : null;
        }

        $row = $this->loadExactConfigRow($key, $module, $area, $scope, $locale);
        if ($memoEnabled && RequestContext::isInitialized()) {
            RequestContext::set($requestCacheKey, $row ?? self::RESOLVED_ROW_MISS);
        }

        return $row;
    }

    /**
     * 列出某 key 在所有 scope/locale 下的精确行（含 suppressed；供 lock 预览）。
     *
     * @return list<array<string, mixed>>
     */
    public function listRowsForKey(string $module, string $area, string $key): array
    {
        $rows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_AREA, $area],
            ])
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
    }

    /**
     * @throws Exception
     */
    public function setConfig(string $key, string $value, string $module, string $area): bool
    {
        return $this->setScopedConfig($key, $value, $module, $area, self::SCOPE_GLOBAL, self::LOCALE_DEFAULT);
    }

    /**
     * @throws Exception
     */
    public function setScopedConfig(
        string $key,
        mixed $value,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): bool {
        $result = $this->saveScopeConfig($module, $area, [$key => $value], $scope, $locale, $options);

        return (bool)($result['success'] ?? false);
    }

    /**
     * @throws Exception
     */
    public function deleteScopedConfig(
        string $key,
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): bool {
        $options['inherit_keys'] = array_values(array_unique(array_merge(
            array_map('strval', (array)($options['inherit_keys'] ?? [])),
            [$key]
        )));
        $result = $this->saveScopeConfig($module, $area, [$key => null], $scope, $locale, $options);

        return (bool)($result['success'] ?? false);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     * @throws Exception
     */
    public function saveScopeConfig(
        string $module,
        string $area,
        array $values,
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): array {
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            return $transactions->run(
                $this->getConnection(),
                fn(): array => $this->saveScopeConfig($module, $area, $values, $scope, $locale, $options),
            );
        }
        // This request may now contain uncommitted rows. Snapshots captured
        // before this point must not be used to prove absence.
        $this->bumpMutationGeneration();

        if (isset($options['scope_identity']) && $options['scope_identity'] instanceof \Weline\Framework\Runtime\ScopeIdentity) {
            /** @var \Weline\Framework\Runtime\ScopeIdentity $identity */
            $identity = $options['scope_identity'];
            $scope = ObjectManager::getInstance(\Weline\SystemConfig\Service\SystemConfigScopeResolver::class)
                ->toStorageScope($identity);
        } else {
            ObjectManager::getInstance(\Weline\SystemConfig\Service\SystemConfigScopeResolver::class)
                ->assertWritableRawScope($scope);
            $scope = $this->normalizeScope($scope);
        }
        $locale = $this->normalizeLocale($locale);
        $inheritKeys = array_values(array_filter(array_map('strval', (array)($options['inherit_keys'] ?? []))));
        ObjectManager::getInstance(\Weline\SystemConfig\Service\SecurityPolicyConfigGuard::class)->assertMutation(
            $module,
            $area,
            $scope,
            $locale,
            $values,
            $inheritKeys,
        );
        $baseVersions = is_array($options['base_versions'] ?? null) ? $options['base_versions'] : [];
        $actorId = (string)($options['actor_id'] ?? RequestContext::get('system_config.actor_id', ''));
        $actorName = (string)($options['actor_name'] ?? RequestContext::get('system_config.actor_name', ''));
        $reason = (string)($options['reason'] ?? '');
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];
        $valueTypes = is_array($options['value_types'] ?? null) ? $options['value_types'] : [];
        $sensitiveKeys = array_fill_keys(array_map('strval', (array)($options['sensitive_keys'] ?? [])), true);
        $sensitiveValues = is_array($options['is_sensitive_values'] ?? null) ? $options['is_sensitive_values'] : [];
        $now = date('Y-m-d H:i:s');

        $changes = [];
        $rollbackStack = [];

        try {
            foreach ($values as $key => $value) {
                $key = (string)$key;
                if ($key === '') {
                    continue;
                }
                $oldRow = $this->loadExactConfigRow($key, $module, $area, $scope, $locale);
                $expectedVersion = $baseVersions[$key] ?? null;
                if ($expectedVersion !== null && (int)($oldRow[self::schema_fields_VERSION] ?? 0) !== (int)$expectedVersion) {
                    return [
                        'success' => false,
                        'status' => 'conflict',
                        'conflicts' => [[
                            'key' => $key,
                            'expected_version' => (int)$expectedVersion,
                            'current_version' => (int)($oldRow[self::schema_fields_VERSION] ?? 0),
                        ]],
                    ];
                }

                if (in_array($key, $inheritKeys, true)) {
                    // Already inheriting (no override row) — nothing to write.
                    if ($oldRow === null) {
                        continue;
                    }
                    $rollbackStack[] = [
                        'key' => $key,
                        'old_row' => $oldRow,
                    ];
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    $changes[] = $this->buildChangeRecord('inherit', $key, $module, $area, $scope, $locale, $oldRow, null);
                    continue;
                }

                $serialized = $this->serializeValue($value, (string)($valueTypes[$key] ?? ($options['value_type'] ?? '')));
                $isSensitive = array_key_exists($key, $sensitiveKeys)
                    ? 1
                    : (int)($sensitiveValues[$key] ?? ($options['is_sensitive'] ?? ($oldRow[self::schema_fields_IS_SENSITIVE] ?? 0)));
                $existingMeta = is_array($oldRow)
                    ? $this->decodeJson((string)($oldRow[self::schema_fields_METADATA] ?? ''))
                    : [];
                if (!is_array($existingMeta)) {
                    $existingMeta = [];
                }
                $fieldMeta = $options['field_metadata'][$key] ?? null;
                if (is_array($fieldMeta)) {
                    // 显式传入：整表替换（调用方负责 merge）
                    $nextMeta = $fieldMeta;
                } else {
                    // 普通保存：保留旧 metadata（避免冲掉 suppress / lock 标记）
                    $nextMeta = $existingMeta;
                }

                if ($oldRow !== null && $this->isUnchangedConfigWrite(
                    $oldRow,
                    $serialized['value'],
                    $serialized['type'],
                    $isSensitive,
                    $nextMeta,
                    is_array($fieldMeta),
                )) {
                    continue;
                }

                $newVersion = ((int)($oldRow[self::schema_fields_VERSION] ?? 0)) + 1;
                $row = [
                    self::schema_fields_KEY => $key,
                    self::schema_fields_MODULE => $module,
                    self::schema_fields_AREA => $area,
                    self::schema_fields_SCOPE => $scope,
                    self::schema_fields_LOCALE => $locale,
                    self::schema_fields_VALUE => $serialized['value'],
                    self::schema_fields_VALUE_TYPE => $serialized['type'],
                    self::schema_fields_IS_SENSITIVE => $isSensitive,
                    self::schema_fields_IS_ACTIVE => 1,
                    self::schema_fields_VERSION => $newVersion,
                    self::schema_fields_METADATA => $this->encodeJson($nextMeta),
                    self::schema_fields_UPDATED_AT => $now,
                    self::schema_fields_UPDATED_BY => $actorName !== '' ? $actorName : $actorId,
                ];
                $rollbackStack[] = [
                    'key' => $key,
                    'old_row' => $oldRow,
                ];
                $this->upsertConfigRow($row, $oldRow !== null);
                $changes[] = $this->buildChangeRecord($oldRow === null ? 'insert' : 'update', $key, $module, $area, $scope, $locale, $oldRow, $row);
            }

            if ($changes === []) {
                return [
                    'success' => true,
                    'status' => 'noop',
                    'version_id' => null,
                    'module' => $module,
                    'area' => $area,
                    'scope' => $scope,
                    'locale' => $locale,
                    'changes' => [],
                    'message' => (string)__('没有检测到配置变更，未创建新版本。'),
                ];
            }

            $versionId = $this->recordVersionIfAvailable([
                SystemConfigVersion::schema_fields_MODULE => $module,
                SystemConfigVersion::schema_fields_AREA => $area,
                SystemConfigVersion::schema_fields_SCOPE => $scope,
                SystemConfigVersion::schema_fields_LOCALE => $locale,
                SystemConfigVersion::schema_fields_OPERATION => (string)($options['operation'] ?? 'save'),
                SystemConfigVersion::schema_fields_STATUS => SystemConfigVersion::STATUS_APPLIED,
                SystemConfigVersion::schema_fields_CHANGES_JSON => $this->encodeJson($changes),
                SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON => $this->encodeJson($inheritKeys),
                SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON => $this->encodeJson($baseVersions),
                SystemConfigVersion::schema_fields_ACTOR_ID => $actorId,
                SystemConfigVersion::schema_fields_ACTOR_NAME => $actorName,
                SystemConfigVersion::schema_fields_REASON => $reason,
                SystemConfigVersion::schema_fields_METADATA => $this->encodeJson($metadata),
                SystemConfigVersion::schema_fields_PARENT_VERSION_ID => (int)($options['parent_version_id'] ?? 0) ?: null,
                SystemConfigVersion::schema_fields_CREATED_AT => $now,
            ]);
        } catch (\Throwable $e) {
            $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
            throw new Exception((string)__('保存配置失败，已回滚本次批次。%{1}', $e->getMessage()));
        }

        $invalidation = ObjectManager::getInstance(ConfigCacheInvalidationService::class);
        $fallbackScopes = $this->getFallbackScopes($scope);
        $fallbackLocales = $this->getFallbackLocales($locale);
        $changedKeys = array_values(array_map('strval', array_keys($values)));
        $requestedNamespaces = $this->normalizeCacheNamespacesOption($options['cache_namespaces'] ?? null);
        /** @var SystemConfigResourceChangePublisher $publisher */
        $publisher = ObjectManager::getInstance(SystemConfigResourceChangePublisher::class);
        $impactNamespaces = $publisher->resolveNamespaces(
            $module,
            $area,
            $scope,
            $locale,
            $changedKeys,
            [],
            $requestedNamespaces,
        );
        // Build cache_ops with the pre-bump version vector + namespace fingerprint, then
        // schedule local-only cleanup; Changed KeyDeleteCapability deletes after commit.
        $cacheOps = $invalidation->buildCacheOps(
            $module,
            $area,
            $scope,
            $locale,
            $changedKeys,
            $fallbackScopes,
            $fallbackLocales,
            $impactNamespaces,
        );
        $this->invalidateConfigCachesForModule(
            $module,
            $area,
            $scope,
            $locale,
            $changedKeys,
            (bool)($options['defer_namespace_invalidation'] ?? false),
        );
        $publisher->publish(
            $module,
            $area,
            $scope,
            $locale,
            $changes,
            'system_config.save',
            $cacheOps,
            [],
            $requestedNamespaces,
        );

        $feedback = ObjectManager::getInstance(ConfigCacheInvalidationFeedback::class);
        $cacheInvalidation = $feedback->build($impactNamespaces, $cacheOps, count($changedKeys));
        $parts = $feedback->formatSaveParts($versionId, $cacheInvalidation);

        return [
            'success' => true,
            'status' => 'applied',
            'version_id' => $versionId,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'changes' => $changes,
            'cache_invalidation' => $cacheInvalidation,
            'message' => $parts['message'],
            'message_title' => $parts['title'],
            'message_body' => $parts['body'],
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    public function rollbackScopeConfigVersion(int $versionId, array $options = []): array
    {
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            return $transactions->run(
                $this->getConnection(),
                fn(): array => $this->rollbackScopeConfigVersion($versionId, $options),
            );
        }
        // Rollback also mutates config; invalidate any snapshot captured earlier
        // in this request before reading the version record.
        $this->bumpMutationGeneration();
        /** @var SystemConfigVersion $version */
        $version = ObjectManager::getInstance(SystemConfigVersion::class);
        $rows = $version->clear()->reset()
            ->where(SystemConfigVersion::schema_fields_ID, $versionId)
            ->select()
            ->fetchArray();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;

        if (!is_array($row) || empty($row[SystemConfigVersion::schema_fields_ID])) {
            return ['success' => false, 'status' => 'not_found', 'version_id' => $versionId];
        }

        $changes = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_CHANGES_JSON] ?? ''));
        if (!is_array($changes)) {
            $changes = [];
        }

        $module = (string)($row[SystemConfigVersion::schema_fields_MODULE] ?? '');
        $area = (string)($row[SystemConfigVersion::schema_fields_AREA] ?? '');
        $scope = $this->normalizeScope((string)($row[SystemConfigVersion::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL));
        $locale = $this->normalizeLocale((string)($row[SystemConfigVersion::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT));
        $now = date('Y-m-d H:i:s');
        $actorId = (string)($options['actor_id'] ?? RequestContext::get('system_config.actor_id', ''));
        $actorName = (string)($options['actor_name'] ?? RequestContext::get('system_config.actor_name', ''));
        $rollbackChanges = [];
        $conflicts = [];
        $rollbackStack = [];

        try {
            foreach (array_reverse($changes) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $key = (string)($change['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $oldRow = is_array($change['old_row'] ?? null) ? $change['old_row'] : null;
                $newRow = is_array($change['new_row'] ?? null) ? $change['new_row'] : null;
                $currentRow = $this->loadExactConfigRow($key, $module, $area, $scope, $locale);
                $expectedVersion = (int)($newRow[self::schema_fields_VERSION] ?? 0);
                if ($expectedVersion > 0 && (int)($currentRow[self::schema_fields_VERSION] ?? 0) !== $expectedVersion) {
                    $conflicts[] = [
                        'key' => $key,
                        'expected_version' => $expectedVersion,
                        'current_version' => (int)($currentRow[self::schema_fields_VERSION] ?? 0),
                    ];
                    continue;
                }

                $rollbackStack[] = ['key' => $key, 'old_row' => $currentRow];
                if ($oldRow === null) {
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    $rollbackChanges[] = $this->buildChangeRecord('rollback_delete', $key, $module, $area, $scope, $locale, $currentRow, null);
                    continue;
                }

                $oldRow[self::schema_fields_VERSION] = ((int)($currentRow[self::schema_fields_VERSION] ?? 0)) + 1;
                $oldRow[self::schema_fields_UPDATED_AT] = $now;
                $oldRow[self::schema_fields_UPDATED_BY] = $actorName !== '' ? $actorName : $actorId;
                $this->upsertConfigRow($oldRow, $currentRow !== null);
                $rollbackChanges[] = $this->buildChangeRecord('rollback_restore', $key, $module, $area, $scope, $locale, $currentRow, $oldRow);
            }

            if ($conflicts !== []) {
                $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
                return [
                    'success' => false,
                    'status' => 'conflict',
                    'version_id' => $versionId,
                    'conflicts' => $conflicts,
                ];
            }

            $rollbackVersionId = $this->recordVersionIfAvailable([
                SystemConfigVersion::schema_fields_MODULE => $module,
                SystemConfigVersion::schema_fields_AREA => $area,
                SystemConfigVersion::schema_fields_SCOPE => $scope,
                SystemConfigVersion::schema_fields_LOCALE => $locale,
                SystemConfigVersion::schema_fields_OPERATION => 'rollback',
                SystemConfigVersion::schema_fields_STATUS => SystemConfigVersion::STATUS_APPLIED,
                SystemConfigVersion::schema_fields_CHANGES_JSON => $this->encodeJson($rollbackChanges),
                SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON => $this->encodeJson([]),
                SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON => $this->encodeJson([]),
                SystemConfigVersion::schema_fields_ACTOR_ID => $actorId,
                SystemConfigVersion::schema_fields_ACTOR_NAME => $actorName,
                SystemConfigVersion::schema_fields_REASON => (string)($options['reason'] ?? __('回滚配置版本 %{1}', $versionId)),
                SystemConfigVersion::schema_fields_METADATA => $this->encodeJson(['rollback_of' => $versionId]),
                SystemConfigVersion::schema_fields_PARENT_VERSION_ID => $versionId,
                SystemConfigVersion::schema_fields_CREATED_AT => $now,
            ]);
        } catch (\Throwable $e) {
            $this->restoreRollbackStack($module, $area, $scope, $locale, $rollbackStack);
            throw new Exception((string)__('回滚配置失败，已恢复本次回滚前状态。%{1}', $e->getMessage()));
        }

        $rollbackMark = $this->markVersionRolledBack($versionId);
        $invalidation = ObjectManager::getInstance(ConfigCacheInvalidationService::class);
        $rollbackKeys = array_values(array_map('strval', array_column($rollbackChanges, 'key')));
        $fallbackScopes = $this->getFallbackScopes($scope);
        $fallbackLocales = $this->getFallbackLocales($locale);
        $requestedNamespaces = $this->normalizeCacheNamespacesOption($options['cache_namespaces'] ?? null);
        /** @var SystemConfigResourceChangePublisher $publisher */
        $publisher = ObjectManager::getInstance(SystemConfigResourceChangePublisher::class);
        $impactNamespaces = $publisher->resolveNamespaces(
            $module,
            $area,
            $scope,
            $locale,
            $rollbackKeys,
            [],
            $requestedNamespaces,
        );
        $cacheOps = $invalidation->buildCacheOps(
            $module,
            $area,
            $scope,
            $locale,
            $rollbackKeys,
            $fallbackScopes,
            $fallbackLocales,
            $impactNamespaces,
        );
        $this->invalidateConfigCachesForModule($module, $area, $scope, $locale, $rollbackKeys);
        $publisher->publish(
            $module,
            $area,
            $scope,
            $locale,
            $rollbackChanges,
            'system_config.rollback',
            $cacheOps,
            [],
            $requestedNamespaces,
        );

        /** @var ConfigCacheInvalidationFeedback $feedback */
        $feedback = ObjectManager::getInstance(ConfigCacheInvalidationFeedback::class);
        $cacheInvalidation = $feedback->build($impactNamespaces, $cacheOps, count($rollbackKeys));
        $parts = $feedback->formatRollbackParts($rollbackVersionId, $cacheInvalidation);

        return [
            'success' => true,
            'status' => 'applied',
            'version_id' => $versionId,
            'rollback_version_id' => $rollbackVersionId,
            'rolled_back_marked' => $rollbackMark['marked'],
            'rolled_back_mark_error' => $rollbackMark['error'],
            'changes' => $rollbackChanges,
            'cache_invalidation' => $cacheInvalidation,
            'message' => $parts['message'],
            'message_title' => $parts['title'],
            'message_body' => $parts['body'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfigVersions(
        string $module,
        string $area,
        ?string $scope = null,
        ?string $locale = null,
        int $limit = 50
    ): array {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $query = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_MODULE, $module)
                ->where(SystemConfigVersion::schema_fields_AREA, $area)
                ->where(SystemConfigVersion::schema_fields_SCOPE, $this->normalizeScope($scope))
                ->where(SystemConfigVersion::schema_fields_LOCALE, $this->normalizeLocale($locale))
                ->order(SystemConfigVersion::schema_fields_ID, 'DESC');
            if ($limit > 0) {
                $query->limit($limit);
            }

            $rows = $query->select()->fetchArray();

            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfigVersionDetail(int $versionId): ?array
    {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $rows = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_ID, $versionId)
                ->select()
                ->fetchArray();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return null;
            }
            throw $e;
        }

        if (!is_array($row) || empty($row[SystemConfigVersion::schema_fields_ID])) {
            return null;
        }

        $row['changes'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_CHANGES_JSON] ?? ''));
        $row['inherit_keys'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_INHERIT_KEYS_JSON] ?? ''));
        $row['base_versions'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_BASE_VERSIONS_JSON] ?? ''));
        $row['metadata_data'] = $this->decodeJson((string)($row[SystemConfigVersion::schema_fields_METADATA] ?? ''));

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadConfigRowsByModule(string $module, string $area, string $scope, string $locale): array
    {
        return $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_SCOPE, $scope],
                [self::schema_fields_LOCALE, $locale],
            ])
            ->select()
            ->fetchArray();
    }

    /**
     * Exact module rows for a scope/locale chain in one query.
     * Callers merge inheritance in PHP; this method never applies fallback precedence.
     *
     * @param list<string> $scopes
     * @param list<string> $locales
     * @return list<array<string, mixed>>
     */
    protected function loadConfigRowsByModuleScopes(
        string $module,
        string $area,
        array $scopes,
        array $locales
    ): array {
        return $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->where(self::schema_fields_SCOPE, $scopes, 'IN')
            ->where(self::schema_fields_LOCALE, $locales, 'IN')
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function loadSingleConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        $configRows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_SCOPE, $scope],
                [self::schema_fields_LOCALE, $locale],
            ])
            ->select()
            ->fetchArray();
        $configRow = is_array($configRows) ? ($configRows[0] ?? null) : null;

        return is_array($configRow) && array_key_exists(self::schema_fields_KEY, $configRow) ? $configRow : null;
    }

    protected function dispatchConfigGetEvent(
        string $key,
        string $module,
        string $area,
        mixed $value,
        string $scope,
        string $locale
    ): mixed {
        $eventData = [
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'value' => $value,
        ];

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_SystemConfig::domain::config_get', $eventData);

        return $eventData['value'] ?? $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadResolvedConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        // Request memo (positive AND negative): a single storefront request reads
        // the same merchant/SEO keys several times; the module snapshot does not
        // cover them when it misses the whole fallback chain, and every repeat used
        // to re-probe scope×locale pairs with one SQL each. The mutation generation
        // in the key makes any in-request write (setConfig/saveScopeConfig/…) fall
        // through to a fresh read, so read-after-write keeps its semantics.
        $memoEnabled = $this->requestMemoEnabled();
        $requestCacheKey = $this->buildRequestCacheKey('resolved_row', $module, $area, $key, $scope, $locale);
        if ($memoEnabled && RequestContext::isInitialized() && RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);
            if ($cached === self::RESOLVED_ROW_MISS) {
                return null;
            }

            return \is_array($cached) ? $cached : null;
        }

        $result = $this->resolveConfigRowUncached($key, $module, $area, $scope, $locale);
        if ($memoEnabled && RequestContext::isInitialized()) {
            RequestContext::set($requestCacheKey, $result ?? self::RESOLVED_ROW_MISS);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveConfigRowUncached(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        $rows = $this->getConfigByModule($module, $area, $scope, $locale) ?? [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((string)($row[self::schema_fields_KEY] ?? '') === $key) {
                return $row;
            }
        }

        // A complete snapshot covers the whole fallback chain, so absence is
        // conclusive. A DB snapshot is always complete; a cache snapshot is
        // complete when it is non-empty and still valid for the current scope
        // version vector. Empty/negative snapshots and snapshots from before a
        // write keep the strong exact-row path.
        //
        // 模块级 marker 只覆盖“本请求读到的那一组 scope/locale”；多候选 scope 链的
        // 调用形状（如 Weline_Seo 商家信息：__store__ → default 逐级 resolveConfig）
        // 命中不了它，于是每个 key 仍要逐 scope×locale 探针一次。整表快照有效时它
        // 覆盖全部 (module, area, scope, locale)，缺失同样是结论性的，必须一并短路，
        // 否则这条路径就是同一族 N+1 的残留。
        if ($this->absenceIsConclusive()
            || $this->isModuleSnapshotAuthoritative($module, $area, $scope, $locale)
        ) {
            return null;
        }

        foreach ($this->getFallbackScopes($scope) as $fallbackScope) {
            foreach ($this->getFallbackLocales($locale) as $fallbackLocale) {
                $row = $this->loadExactConfigRow($key, $module, $area, $fallbackScope, $fallbackLocale);
                if (!\is_array($row)) {
                    continue;
                }
                if (\array_key_exists(self::schema_fields_IS_ACTIVE, $row) && (int)$row[self::schema_fields_IS_ACTIVE] === 0) {
                    continue;
                }
                if (SystemConfigLockService::isRowSuppressed($row)) {
                    continue;
                }

                return $this->normalizeRow($row, $fallbackScope, $fallbackLocale);
            }
        }

        return null;
    }

    /**
     * Exact module rows for one storage scope + locale (no inheritance merge).
     * Shared by map/resolved/typed readers so single-key paths do not re-query.
     *
     * @return list<array<string, mixed>>
     */
    private function getExactModuleRows(
        string $module,
        string $area,
        string $scope,
        string $locale,
    ): array {
        $scope = $this->normalizeScope($scope);
        $locale = $this->normalizeLocale($locale);
        $requestCacheKey = $this->buildRequestCacheKey('module_exact_rows', $module, $area, null, $scope, $locale);
        if (RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);

            return \is_array($cached) ? $cached : [];
        }

        $cache = $this->scopedConfigCache($module, $area, $scope, $locale);
        $cacheEntry = $this->readCacheEnvelope($cache, $this->buildExactModuleRowsCacheKey($module, $area, $scope, $locale));
        if ($cacheEntry['hit'] && \is_array($cacheEntry['value']) && $cacheEntry['value'] !== []) {
            RequestContext::set($requestCacheKey, $cacheEntry['value']);

            return $cacheEntry['value'];
        }

        $rows = $this->loadConfigRowsByModuleIfAvailable($module, $area, $scope, $locale);
        RequestContext::set($requestCacheKey, $rows);
        if ($rows !== []) {
            $this->writeCacheEnvelope($cache, $this->buildExactModuleRowsCacheKey($module, $area, $scope, $locale), $rows);
        }

        return $rows;
    }

    /**
     * Exact module rows for a fallback scope/locale chain, loaded with one SQL.
     * The result is keyed [scope][locale] so callers can keep the existing
     * scope-major, locale-minor inheritance order.
     *
     * @param list<string> $scopes
     * @param list<string> $locales
     * @return array{
     *     rows: array<string, array<string, list<array<string, mixed>>>>,
     *     source: 'db'|'cache'|'snapshot'
     * }
     */
    private function getExactModuleRowsForScopes(
        string $module,
        string $area,
        array $scopes,
        array $locales,
    ): array {
        $scopes = array_values(array_unique(array_map(
            fn(string $scope): string => $this->normalizeScope($scope),
            $scopes
        )));
        $locales = array_values(array_unique(array_map(
            fn(string $locale): string => $this->normalizeLocale($locale),
            $locales
        )));
        if ($scopes === [] || $locales === []) {
            return ['rows' => [], 'source' => 'db'];
        }

        $requestCacheKey = $this->buildBatchRequestCacheKey('module_exact_rows_batch', $module, $area, $scopes, $locales)
            . ':g' . $this->mutationGeneration();
        if (RequestContext::has($requestCacheKey)) {
            $cached = RequestContext::get($requestCacheKey);

            return [
                'rows' => \is_array($cached) ? $cached : [],
                'source' => $this->readExactBatchSource($requestCacheKey),
            ];
        }

        $cachedPairs = $this->readExactModuleRowsFromRequestCache($module, $area, $scopes, $locales);
        if ($cachedPairs !== null) {
            RequestContext::set($requestCacheKey, $cachedPairs);
            $this->rememberExactBatchSource($requestCacheKey, 'cache');

            return ['rows' => $cachedPairs, 'source' => 'cache'];
        }

        $processKey = $this->buildBatchModuleRowsCacheKey($module, $area, $scopes, $locales);
        if (isset(self::$configs[$area][$module][$processKey])
            && \is_array(self::$configs[$area][$module][$processKey])
        ) {
            $batch = self::$configs[$area][$module][$processKey];
            $this->seedExactModuleRowsRequestCache($module, $area, $batch);
            RequestContext::set($requestCacheKey, $batch);
            $this->rememberExactBatchSource($requestCacheKey, 'cache');

            return ['rows' => $batch, 'source' => 'cache'];
        }

        // 整表进程快照：一次全表读即覆盖全部 (module, scope, locale)。
        // ensureTableSnapshot() 自带跨进程代次校验：有效时只是一次（请求内记忆化过的）
        // 缓存读；失效（含别的 worker 刚提交）时就地重建，避免本次调用退化成逐批库读。
        // 与库读取等价（范围内行全在），包括空结果，所以标 'snapshot' 并当作权威，
        // 否则模块没配置的 key 仍会退化成逐 scope×locale 探针（单请求 73 条的来源）。
        self::ensureTableSnapshot();
        $snapshotBatch = self::tableSnapshotSlice($module, $area, $scopes, $locales);
        if ($snapshotBatch !== null) {
            self::$configs[$area][$module][$processKey] = $snapshotBatch;
            $this->seedExactModuleRowsRequestCache($module, $area, $snapshotBatch);
            RequestContext::set($requestCacheKey, $snapshotBatch);
            $this->rememberExactBatchSource($requestCacheKey, self::SNAPSHOT_SOURCE);

            return ['rows' => $snapshotBatch, 'source' => self::SNAPSHOT_SOURCE];
        }

        $cache = $this->scopedConfigCache(
            $module,
            $area,
            (string)($scopes[0] ?? self::SCOPE_GLOBAL),
            (string)($locales[0] ?? self::LOCALE_DEFAULT)
        );
        $cacheEntry = $this->readCacheEnvelope($cache, $processKey);
        // Empty envelopes are not durable hits: a prior negative cache must not
        // hide rows inserted after the empty snapshot was written.
        if ($cacheEntry['hit'] && \is_array($cacheEntry['value']) && $cacheEntry['value'] !== []) {
            $batch = $cacheEntry['value'];
            self::$configs[$area][$module][$processKey] = $batch;
            $this->seedExactModuleRowsRequestCache($module, $area, $batch);
            RequestContext::set($requestCacheKey, $batch);
            $this->rememberExactBatchSource($requestCacheKey, 'cache');

            return ['rows' => $batch, 'source' => 'cache'];
        }

        try {
            $rows = $this->loadConfigRowsByModuleScopes($module, $area, $scopes, $locales);
        } catch (\Throwable $e) {
            if ($this->isSystemConfigTableMissing($e)) {
                $rows = [];
            } elseif ($this->isSystemConfigScopeSchemaMissing($e)) {
                // Legacy schema: preserve the per-pair reader (and its
                // global/default-only gate) instead of guessing column support.
                $batch = [];
                foreach ($scopes as $fallbackScope) {
                    foreach ($locales as $fallbackLocale) {
                        $batch[$fallbackScope][$fallbackLocale] = $this->loadConfigRowsByModuleIfAvailable(
                            $module,
                            $area,
                            $fallbackScope,
                            $fallbackLocale
                        );
                    }
                }
                $this->storeExactModuleRowsBatch($module, $area, $processKey, $cache, $requestCacheKey, $batch);
                $this->rememberExactBatchSource($requestCacheKey, 'db');

                return ['rows' => $batch, 'source' => 'db'];
            } else {
                throw $e;
            }
        }

        $batch = [];
        foreach ($scopes as $fallbackScope) {
            foreach ($locales as $fallbackLocale) {
                $batch[$fallbackScope][$fallbackLocale] = [];
            }
        }
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $rowScope = (string)($row[self::schema_fields_SCOPE] ?? '');
            $rowLocale = (string)($row[self::schema_fields_LOCALE] ?? '');
            if ($rowScope === '' || $rowLocale === '' || !isset($batch[$rowScope][$rowLocale])) {
                continue;
            }
            $batch[$rowScope][$rowLocale][] = $row;
        }

        $this->storeExactModuleRowsBatch($module, $area, $processKey, $cache, $requestCacheKey, $batch);
        $this->rememberExactBatchSource($requestCacheKey, 'db');

        return ['rows' => $batch, 'source' => 'db'];
    }

    /**
     * @param array<string, array<string, list<array<string, mixed>>>> $batch
     */
    private function storeExactModuleRowsBatch(
        string $module,
        string $area,
        string $processKey,
        mixed $cache,
        string $requestCacheKey,
        array $batch,
    ): void {
        self::$configs[$area][$module][$processKey] = $batch;
        $this->seedExactModuleRowsRequestCache($module, $area, $batch);
        RequestContext::set($requestCacheKey, $batch);

        $hasRows = false;
        foreach ($batch as $byLocale) {
            foreach ($byLocale as $rows) {
                if ($rows !== []) {
                    $hasRows = true;
                    break 2;
                }
            }
        }
        if ($hasRows) {
            $this->writeCacheEnvelope($cache, $processKey, $batch);
        }
    }

    /**
     * Keep the existing single-pair request cache warm so exact-row readers
     * (findExactModuleRow, administrative reads) do not re-query.
     *
     * @param array<string, array<string, list<array<string, mixed>>>> $batch
     */
    private function seedExactModuleRowsRequestCache(string $module, string $area, array $batch): void
    {
        foreach ($batch as $scope => $byLocale) {
            foreach ($byLocale as $locale => $rows) {
                if (!\is_array($rows)) {
                    continue;
                }
                $key = $this->buildRequestCacheKey(
                    'module_exact_rows',
                    $module,
                    $area,
                    null,
                    (string)$scope,
                    (string)$locale
                );
                RequestContext::set($key, $rows);
            }
        }
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $locales
     * @return array<string, array<string, list<array<string, mixed>>>>|null
     */
    private function readExactModuleRowsFromRequestCache(
        string $module,
        string $area,
        array $scopes,
        array $locales,
    ): ?array {
        $batch = [];
        foreach ($scopes as $scope) {
            foreach ($locales as $locale) {
                $key = $this->buildRequestCacheKey(
                    'module_exact_rows',
                    $module,
                    $area,
                    null,
                    $scope,
                    $locale
                );
                if (!RequestContext::has($key)) {
                    return null;
                }
                $rows = RequestContext::get($key);
                $batch[$scope][$locale] = \is_array($rows) ? $rows : [];
            }
        }

        return $batch;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExactModuleRow(
        string $key,
        string $module,
        string $area,
        string $scope,
        string $locale,
    ): ?array {
        return $this->findRowByKey($key, $this->getExactModuleRows($module, $area, $scope, $locale));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRowByKey(string $key, array $rows): ?array
    {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ((string)($row[self::schema_fields_KEY] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * setup:upgrade may read config before SchemaDiff creates or upgrades system_config.
     */
    private function loadConfigRowsByModuleIfAvailable(string $module, string $area, string $scope, string $locale): array
    {
        try {
            return $this->loadConfigRowsByModule($module, $area, $scope, $locale);
        } catch (\Throwable $e) {
            if ($this->isSystemConfigTableMissing($e)) {
                return [];
            }
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                return $this->loadLegacyConfigRowsByModule($module, $area, $scope, $locale);
            }
            throw $e;
        }
    }

    /**
     * setup:upgrade may read config before SchemaDiff creates or upgrades system_config.
     */
    private function loadSingleConfigRowIfAvailable(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        try {
            return $this->loadSingleConfigRow($key, $module, $area, $scope, $locale);
        } catch (\Throwable $e) {
            if ($this->isSystemConfigTableMissing($e)) {
                return null;
            }
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                return $this->loadLegacySingleConfigRow($key, $module, $area, $scope, $locale);
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadExactConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        // Writes and conflict/override checks require the authoritative row,
        // independent of a read snapshot captured earlier in this request.
        return $this->loadSingleConfigRowIfAvailable($key, $module, $area, $scope, $locale);
    }

    private function isSystemConfigTableMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (!str_contains($message, 'system_config')) {
            return false;
        }

        return str_contains($message, 'no such table')
            || str_contains($message, 'base table or view not found')
            || str_contains($message, 'undefined table')
            || str_contains($message, 'does not exist');
    }

    private function isSystemConfigScopeSchemaMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $isColumnError = str_contains($message, 'unknown column')
            || str_contains($message, 'no such column')
            || str_contains($message, 'undefined column')
            || str_contains($message, 'column not found');
        if (!$isColumnError) {
            return false;
        }

        return str_contains($message, 'system_config')
            || str_contains($message, 'scope')
            || str_contains($message, 'locale')
            || str_contains($message, 'is_active')
            || str_contains($message, 'value_type');
    }

    private function isSystemConfigVersionTableMissing(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'system_config_version')
            && (
                str_contains($message, 'no such table')
                || str_contains($message, 'base table or view not found')
                || str_contains($message, 'undefined table')
                || str_contains($message, 'does not exist')
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadLegacyConfigRowsByModule(string $module, string $area, string $scope, string $locale): array
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return [];
        }

        $rows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeRow($row, $scope, $locale), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLegacySingleConfigRow(string $key, string $module, string $area, string $scope, string $locale): ?array
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return null;
        }

        $configRows = $this->clear()
            ->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_AREA, $area],
                [self::schema_fields_MODULE, $module],
            ])
            ->select()
            ->fetchArray();
        $configRow = is_array($configRows) ? ($configRows[0] ?? null) : null;

        return is_array($configRow) && array_key_exists(self::schema_fields_KEY, $configRow)
            ? $this->normalizeRow($configRow, $scope, $locale)
            : null;
    }

    private function extractNestedConfigValue(mixed $configValue, string $rootKey, array $keys): mixed
    {
        if (is_string($configValue)) {
            $configValue = json_decode($configValue, true);
        }

        if (!is_array($configValue)) {
            return null;
        }

        $result = $configValue[$rootKey] ?? null;
        foreach ($keys as $nestedKey) {
            if (!is_array($result) || !array_key_exists($nestedKey, $result)) {
                return null;
            }
            $result = $result[$nestedKey];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function getFallbackLocales(string $locale): array
    {
        return array_values(array_unique([$this->normalizeLocale($locale), self::LOCALE_DEFAULT]));
    }

    /**
     * True when an upsert would not change stored value/type/sensitive/active.
     * Template field_metadata churn alone must not create a new version.
     *
     * @param array<string, mixed> $oldRow
     * @param array<string, mixed> $nextMeta unused; kept for call-site clarity
     */
    private function isUnchangedConfigWrite(
        array $oldRow,
        string $newValue,
        string $newType,
        int $isSensitive,
        array $nextMeta = [],
        bool $fieldMetaProvided = false,
    ): bool {
        unset($nextMeta, $fieldMetaProvided);
        if ((int)($oldRow[self::schema_fields_IS_ACTIVE] ?? 1) !== 1) {
            return false;
        }
        if ((string)($oldRow[self::schema_fields_VALUE] ?? '') !== $newValue) {
            return false;
        }
        if ((string)($oldRow[self::schema_fields_VALUE_TYPE] ?? self::VALUE_TYPE_STRING) !== $newType) {
            return false;
        }
        if ((int)($oldRow[self::schema_fields_IS_SENSITIVE] ?? 0) !== $isSensitive) {
            return false;
        }

        return true;
    }

    /**
     * @return array{value: string, type: string}
     */
    private function serializeValue(mixed $value, string $preferredType = ''): array
    {
        $type = $preferredType !== '' ? $preferredType : match (true) {
            $value === null => self::VALUE_TYPE_NULL,
            is_bool($value) => self::VALUE_TYPE_BOOL,
            is_int($value) => self::VALUE_TYPE_INT,
            is_float($value) => self::VALUE_TYPE_FLOAT,
            is_array($value) || is_object($value) => self::VALUE_TYPE_JSON,
            default => self::VALUE_TYPE_STRING,
        };

        $serialized = match ($type) {
            self::VALUE_TYPE_NULL => '',
            self::VALUE_TYPE_BOOL => $value ? '1' : '0',
            self::VALUE_TYPE_INT => (string)(int)$value,
            self::VALUE_TYPE_FLOAT => (string)(float)$value,
            self::VALUE_TYPE_JSON => $this->encodeJson($value),
            default => (string)$value,
        };

        return ['value' => $serialized, 'type' => $type];
    }

    private function unserializeValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            self::VALUE_TYPE_NULL => null,
            self::VALUE_TYPE_BOOL => (string)$value === '1' || $value === true,
            self::VALUE_TYPE_INT => (int)$value,
            self::VALUE_TYPE_FLOAT => (float)$value,
            self::VALUE_TYPE_JSON => $this->decodeJson((string)$value),
            default => $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, string $scope, string $locale): array
    {
        $row[self::schema_fields_SCOPE] ??= $scope;
        $row[self::schema_fields_LOCALE] ??= $locale;
        $row[self::schema_fields_VALUE_TYPE] ??= self::VALUE_TYPE_STRING;
        $row[self::schema_fields_IS_SENSITIVE] ??= 0;
        $row[self::schema_fields_IS_ACTIVE] ??= 1;
        $row[self::schema_fields_VERSION] ??= 0;
        $row[self::schema_fields_METADATA] ??= null;

        return $row;
    }

    /**
     * @param array<string, mixed>|null $oldRow
     * @param array<string, mixed>|null $newRow
     * @return array<string, mixed>
     */
    private function buildChangeRecord(
        string $action,
        string $key,
        string $module,
        string $area,
        string $scope,
        string $locale,
        ?array $oldRow,
        ?array $newRow
    ): array {
        return [
            'action' => $action,
            'key' => $key,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'old_row' => $oldRow,
            'new_row' => $newRow,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    public function maskSensitiveRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if ((int)($row[self::schema_fields_IS_SENSITIVE] ?? 0) === 1) {
            $row[self::schema_fields_VALUE] = '***';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertConfigRow(array $row, bool $exists): void
    {
        $row = $this->filterConfigRowForWrite($row);
        $where = $this->identityWhere(
            (string)$row[self::schema_fields_KEY],
            (string)$row[self::schema_fields_MODULE],
            (string)$row[self::schema_fields_AREA],
            (string)$row[self::schema_fields_SCOPE],
            (string)$row[self::schema_fields_LOCALE]
        );

        try {
            if ($exists) {
                $this->updateConfigRow($row, $where);
                return;
            }

            $this->clear()->reset()->insert($row, [], '', true)->fetch();
        } catch (\Throwable $e) {
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                $this->legacyUpsertConfigRow($row, $exists);
                return;
            }
            if (!$exists && $this->isDuplicateIdentityError($e)) {
                $this->updateConfigRow($row, $where);
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{0: string, 1: mixed}> $where
     */
    private function updateConfigRow(array $row, array $where): void
    {
        $updateRow = $row;
        foreach ([
            self::schema_fields_KEY,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
            self::schema_fields_SCOPE,
            self::schema_fields_LOCALE,
            self::schema_fields_UPDATED_AT,
            self::schema_fields_CREATE_TIME,
            self::schema_fields_UPDATE_TIME,
        ] as $field) {
            unset($updateRow[$field]);
        }

        if ($updateRow === []) {
            return;
        }

        $this->clear()->reset()->where($where)->update($updateRow)->fetch();
    }

    private function isDuplicateIdentityError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate key value')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'sqlstate[23505]');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterConfigRowForWrite(array $row): array
    {
        $allowed = [
            self::schema_fields_KEY,
            self::schema_fields_VALUE,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
            self::schema_fields_SCOPE,
            self::schema_fields_LOCALE,
            self::schema_fields_VALUE_TYPE,
            self::schema_fields_IS_SENSITIVE,
            self::schema_fields_IS_ACTIVE,
            self::schema_fields_VERSION,
            self::schema_fields_METADATA,
            self::schema_fields_UPDATED_AT,
            self::schema_fields_UPDATED_BY,
        ];

        return array_intersect_key($row, array_fill_keys($allowed, true));
    }

    private function deleteConfigRow(string $key, string $module, string $area, string $scope, string $locale): void
    {
        try {
            $this->clear()->reset()
                ->where($this->identityWhere($key, $module, $area, $scope, $locale))
                ->delete()
                ->fetch();
        } catch (\Throwable $e) {
            if ($this->isSystemConfigScopeSchemaMissing($e)) {
                $this->legacyDeleteConfigRow($key, $module, $area, $scope, $locale);
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function legacyUpsertConfigRow(array $row, bool $exists): void
    {
        if ((string)($row[self::schema_fields_SCOPE] ?? '') !== self::SCOPE_GLOBAL
            || (string)($row[self::schema_fields_LOCALE] ?? '') !== self::LOCALE_DEFAULT
        ) {
            throw new Exception((string)__('当前 system_config 表尚未升级，不能保存非全局 scope 配置。'));
        }

        $legacyRow = [
            self::schema_fields_KEY => $row[self::schema_fields_KEY],
            self::schema_fields_MODULE => $row[self::schema_fields_MODULE],
            self::schema_fields_AREA => $row[self::schema_fields_AREA],
            self::schema_fields_VALUE => $row[self::schema_fields_VALUE],
        ];
        $legacyWhere = [
            [self::schema_fields_KEY, $row[self::schema_fields_KEY]],
            [self::schema_fields_MODULE, $row[self::schema_fields_MODULE]],
            [self::schema_fields_AREA, $row[self::schema_fields_AREA]],
        ];

        if ($exists) {
            $this->clear()->reset()->where($legacyWhere)->update($legacyRow)->fetch();
            return;
        }

        $this->clear()->reset()->insert($legacyRow, [
            self::schema_fields_KEY,
            self::schema_fields_MODULE,
            self::schema_fields_AREA,
        ])->fetch();
    }

    private function legacyDeleteConfigRow(string $key, string $module, string $area, string $scope, string $locale): void
    {
        if ($scope !== self::SCOPE_GLOBAL || $locale !== self::LOCALE_DEFAULT) {
            return;
        }

        $this->clear()->reset()
            ->where([
                [self::schema_fields_KEY, $key],
                [self::schema_fields_MODULE, $module],
                [self::schema_fields_AREA, $area],
            ])
            ->delete()
            ->fetch();
    }

    /**
     * @param array<int, array{key: string, old_row: ?array<string, mixed>}> $rollbackStack
     */
    private function restoreRollbackStack(string $module, string $area, string $scope, string $locale, array $rollbackStack): void
    {
        foreach (array_reverse($rollbackStack) as $item) {
            $key = (string)($item['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $oldRow = $item['old_row'] ?? null;
            try {
                if ($oldRow === null) {
                    $this->deleteConfigRow($key, $module, $area, $scope, $locale);
                    continue;
                }
                $this->upsertConfigRow($oldRow, $this->loadExactConfigRow($key, $module, $area, $scope, $locale) !== null);
            } catch (\Throwable $ignored) {
            }
        }
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function identityWhere(string $key, string $module, string $area, string $scope, string $locale): array
    {
        return [
            [self::schema_fields_KEY, $key],
            [self::schema_fields_MODULE, $module],
            [self::schema_fields_AREA, $area],
            [self::schema_fields_SCOPE, $scope],
            [self::schema_fields_LOCALE, $locale],
        ];
    }

    /**
     * @param list<string> $keys
     */
    private function invalidateConfigCachesForModule(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $keys = [],
        bool $deferNamespace = false,
    ): void
    {
        ObjectManager::getInstance(ConfigCacheInvalidationService::class)->schedule(
            $this->getConnection(),
            $module,
            $area,
            $scope,
            $locale,
            array_values(array_map('strval', $keys)),
            $this->getFallbackScopes($scope),
            $this->getFallbackLocales($locale),
            $deferNamespace,
        );
    }

    private function buildSingleCacheKey(
        string $key,
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)->versionVectorFor($scope);

        return 'system_config_cache_' . sha1(implode('|', [$area, $module, $key, $scope, $locale, $vector]));
    }

    private function buildModuleRowsCacheKey(
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)->versionVectorFor($scope);

        return 'system_config_rows_' . sha1(implode('|', [$area, $module, $scope, $locale, $vector]));
    }

    private function buildExactModuleRowsCacheKey(
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)->versionVectorFor($scope);

        return 'system_config_exact_rows_' . sha1(implode('|', [$area, $module, $scope, $locale, $vector]));
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $locales
     */
    private function buildBatchModuleRowsCacheKey(
        string $module,
        string $area,
        array $scopes,
        array $locales
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)
            ->versionVectorFor((string)($scopes[0] ?? self::SCOPE_GLOBAL));

        return 'system_config_exact_rows_batch_' . sha1(implode('|', [
            $area,
            $module,
            implode(',', $scopes),
            implode(',', $locales),
            $vector,
        ]));
    }

    /**
     * @param list<string> $scopes
     * @param list<string> $locales
     */
    private function buildBatchRequestCacheKey(
        string $type,
        string $module,
        string $area,
        array $scopes,
        array $locales
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)
            ->versionVectorFor((string)($scopes[0] ?? self::SCOPE_GLOBAL));

        return implode(':', [
            'system_config',
            $type,
            $area,
            $module,
            implode(',', $scopes),
            implode(',', $locales),
            $vector,
            'g' . $this->mutationGeneration(),
        ]);
    }

    private function buildModuleMapCacheKey(
        string $module,
        string $area,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)->versionVectorFor($scope);

        return 'system_config_map_' . sha1(implode('|', [$area, $module, $scope, $locale, $vector]));
    }

    private function buildRequestCacheKey(
        string $type,
        string $module,
        string $area,
        ?string $key = null,
        string $scope = self::SCOPE_GLOBAL,
        string $locale = self::LOCALE_DEFAULT
    ): string {
        $vector = ObjectManager::getInstance(ScopeConfigCacheInvalidator::class)->versionVectorFor($scope);

        // Single-value read memos must stop matching after an in-request write
        // (read-after-write); module snapshots keep their explicit generation
        // marker (markModuleSnapshot) so absence authority stays unchanged.
        $generationScoped = \in_array($type, ['resolved', 'resolved_row', 'scoped_row'], true);

        return implode(':', array_filter([
            'system_config',
            $type,
            $area,
            $module,
            $key,
            $scope,
            $locale,
            $vector,
            $generationScoped ? 'g' . $this->mutationGeneration() : null,
        ], static fn(?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * Namespace-scoped pool so ResourceChange bump delivers without relying on key deletes.
     */
    private function scopedConfigCache(string $module, string $area, string $scope, string $locale): mixed
    {
        $cache = $this->_cache;
        if (!$cache instanceof NamespaceScopedCachePoolInterface) {
            return $cache;
        }
        /** @var NamespacePath $paths */
        $paths = ObjectManager::getInstance(NamespacePath::class);
        $identity = implode('|', [$module, $area, $scope, $locale]);
        $namespaces = [
            $paths->global('system-config', [hash('sha256', $identity)]),
            $paths->global('storefront', ['config']),
        ];
        if ($module === 'Weline_Captcha') {
            $namespaces[] = $paths->global('storefront', ['captcha']);
        } elseif ($module === 'Weline_Customer') {
            $namespaces[] = $paths->global('storefront', ['auth']);
        } elseif ($module === 'Weline_Theme') {
            $namespaces[] = $paths->global('storefront', ['theme']);
        } elseif ($module === 'Weline_Currency') {
            $namespaces[] = $paths->global('storefront', ['price']);
        }

        return $cache->withNamespaces($namespaces);
    }

    /**
     * @return array{hit: bool, value: mixed}
     */
    private function readCacheEnvelope(mixed $cache, string $cacheKey): array
    {
        $cached = is_object($cache) && method_exists($cache, 'get') ? $cache->get($cacheKey) : null;
        if (!is_array($cached) || ($cached[self::CACHE_HIT_FLAG] ?? false) !== true || !array_key_exists('value', $cached)) {
            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $cached['value']];
    }

    private function writeCacheEnvelope(mixed $cache, string $cacheKey, mixed $value): void
    {
        if (is_object($cache) && method_exists($cache, 'set')) {
            $cache->set($cacheKey, [
                self::CACHE_HIT_FLAG => true,
                'value' => $value,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeCacheNamespacesOption(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $out[$item] = $item;
            }
        }
        return array_values($out);
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'null';
    }

    private function decodeJson(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordVersionIfAvailable(array $data): ?int
    {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $version->clear()->reset()->insert($data, [], '', true)->fetch();
            $rows = $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_MODULE, (string)($data[SystemConfigVersion::schema_fields_MODULE] ?? ''))
                ->where(SystemConfigVersion::schema_fields_AREA, (string)($data[SystemConfigVersion::schema_fields_AREA] ?? ''))
                ->where(SystemConfigVersion::schema_fields_SCOPE, (string)($data[SystemConfigVersion::schema_fields_SCOPE] ?? self::SCOPE_GLOBAL))
                ->where(SystemConfigVersion::schema_fields_LOCALE, (string)($data[SystemConfigVersion::schema_fields_LOCALE] ?? self::LOCALE_DEFAULT))
                ->order(SystemConfigVersion::schema_fields_ID, 'DESC')
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;

            return is_array($row) && !empty($row[SystemConfigVersion::schema_fields_ID])
                ? (int)$row[SystemConfigVersion::schema_fields_ID]
                : null;
        } catch (\Throwable $e) {
            if ($this->isSystemConfigVersionTableMissing($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @return array{marked: bool, error: string|null}
     */
    private function markVersionRolledBack(int $versionId): array
    {
        try {
            /** @var SystemConfigVersion $version */
            $version = ObjectManager::getInstance(SystemConfigVersion::class);
            $version->clear()->reset()
                ->where(SystemConfigVersion::schema_fields_ID, $versionId)
                ->update([SystemConfigVersion::schema_fields_STATUS => SystemConfigVersion::STATUS_ROLLED_BACK])
                ->fetch();

            return ['marked' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['marked' => false, 'error' => $e->getMessage()];
        }
    }
}
