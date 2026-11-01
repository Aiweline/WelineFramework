<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeApplicationContext;

/**
 * 世代版本号统一读取入口（Step 0b / 定论 14）。
 *
 * 唯一权威值来自 `var/deploy/publish_state.json` 的 `chains[{site}|{theme}|{area}].active`，
 * 由发布流程（SystemReleaseManager / ThemeReleaseManager）写入；请求期一律只读。
 *
 * 契约：
 * - 调用方**不需要**传 site/theme：内部按当前环境上下文解析链（resolveChainFromContext）。
 * - preload() 之后全部是**内存查表**：不读盘、不查库、不阻塞业务逻辑。
 * - 解析不到链时返回 ''（**禁止**塌缩成全局值，那会让所有站共用同一个 token）。
 * - `publish_state.json` 不存在时回退到旧口径（部署号 + 指针），保证升级过程不炸。
 */
final class GenerationRegistry
{
    public const STATE_FILE = 'var/deploy/publish_state.json';
    public const MAX_SUPPORTED = 3;
    /** 预加载结果进程内复用；超过该秒数允许重读一次（防漏广播）。 */
    public const PRELOAD_TTL_SECONDS = 60;

    /** @var array<string,mixed>|null 预加载后的 chains 快照 */
    private static ?array $chains = null;
    private static int $preloadedAt = 0;
    private static string $resolvedChainKey = '';
    /** @var int 文件读取次数（供测试断言"预热后零读盘"） */
    private static int $fileReadCount = 0;

    // ---------------------------------------------------------------- 预加载

    /**
     * 站点/主题上下文就绪后立刻预热（挂载点：ThemeApplicationContext::install 末尾）。
     * 失败不得影响请求：捕获后记 warning，保留上一份缓存。
     */
    public static function preload(): void
    {
        try {
            $state = self::readStateFile();
            self::$chains = \is_array($state['chains'] ?? null) ? $state['chains'] : [];
            self::$preloadedAt = \time();
            self::$resolvedChainKey = '';
        } catch (\Throwable $e) {
            self::logWarning('generation_preload_failed', $e);
        }
    }

    public static function reset(): void
    {
        self::$chains = null;
        self::$preloadedAt = 0;
        self::$resolvedChainKey = '';
    }

    /** 测试用：读盘次数。 */
    public static function fileReadCount(): int
    {
        return self::$fileReadCount;
    }

    public static function resetFileReadCount(): void
    {
        self::$fileReadCount = 0;
    }

    // ---------------------------------------------------------------- 读取

    /** 当前环境的链记录（未预加载或过期会自动补一次）。 */
    public static function chain(string $chainKey = ''): array
    {
        $key = $chainKey !== '' ? $chainKey : self::resolveChainFromContext();
        if ($key === '') {
            return [];
        }
        self::ensurePreloaded();
        $row = self::$chains[$key] ?? [];

        return \is_array($row) ? $row : [];
    }

    /** 唯一权威读口：`{deploy_build}~{theme_generation}`。 */
    public static function generationUid(string $chainKey = ''): string
    {
        $active = self::active($chainKey);
        $uid = \trim((string)($active['generation_uid'] ?? ''));
        if ($uid !== '') {
            return $uid;
        }

        return self::legacyGenerationUid();
    }

    /** 该链当前页桶 token（16 hex）。 */
    public static function pageToken(string $chainKey = ''): string
    {
        $active = self::active($chainKey);
        $token = \strtolower(\trim((string)($active['page_token'] ?? '')));
        if ($token !== '' && \preg_match('/^[a-f0-9]{16}$/', $token) === 1) {
            return $token;
        }

        return FpcHtmlDiskStore::pageGenerationToken();
    }

    public static function deployBuild(string $chainKey = ''): string
    {
        $active = self::active($chainKey);
        $build = \strtolower(\trim((string)($active['deploy_build'] ?? '')));

        return $build !== '' ? $build : StaticBuildId::current();
    }

    public static function active(string $chainKey = ''): array
    {
        $row = self::chain($chainKey);
        $active = $row['active'] ?? [];

        return \is_array($active) ? $active : [];
    }

    /** @return list<array{generation_uid:string,page_token:string,state:string,servable:bool,kind:string,created_at:int}> */
    public static function supported(string $chainKey = ''): array
    {
        $row = self::chain($chainKey);
        $list = $row['supported'] ?? [];
        if (!\is_array($list)) {
            return [];
        }

        return \array_values(\array_filter($list, static fn($item): bool => \is_array($item)));
    }

    /** 签章校验：命中该链窗口且 servable 才放行。 */
    public static function isSupported(string $chainKey, string $uid): bool
    {
        $uid = \trim($uid);
        if ($uid === '' || $chainKey === '') {
            return false;
        }
        foreach (self::supported($chainKey) as $item) {
            if (\strcasecmp((string)($item['generation_uid'] ?? ''), $uid) === 0) {
                return ($item['servable'] ?? true) !== false;
            }
        }

        return false;
    }

    /**
     * 代次是否**已经生效或已在窗口内**。
     *
     * 2026-11-01（W1 单调 / Step 2 缺陷）：主题就地发版（dev 模式不改 pair）时，
     * 不能拿着与当前 active 相同的 pair 反复 stage——那会把 active 拉回旧值。
     * 调用方应先问这里：已经在了就什么都不做。
     */
    public static function containsGeneration(string $chainKey, string $uid): bool
    {
        $uid = \strtolower(\trim($uid));
        if ($uid === '' || $chainKey === '') {
            return false;
        }
        foreach (self::supported($chainKey) as $item) {
            if (\strtolower(\trim((string)($item['generation_uid'] ?? ''))) === $uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按当前环境解析链键。优先级：请求上下文 → 唯一链兜底 → ''。
     * 绝不返回"全局"值：解析不到就让调用方走无桶路径。
     */
    public static function resolveChainFromContext(): string
    {
        if (self::$resolvedChainKey !== '') {
            return self::$resolvedChainKey;
        }
        self::ensurePreloaded();
        $chains = self::$chains ?? [];
        if ($chains === []) {
            return '';
        }

        $area = 'frontend';
        $themeId = 0;
        $websiteId = 0;
        try {
            $ctx = ThemeApplicationContext::current('frontend')
                ?? ThemeApplicationContext::current('backend');
            if ($ctx instanceof ThemeApplicationContext) {
                $data = $ctx->toArray();
                $themeId = (int)($data['theme_id'] ?? 0);
                $area = \trim((string)($data['area'] ?? 'frontend')) ?: 'frontend';
            }
        } catch (\Throwable) {
            // 上下文不可用：继续尝试唯一链兜底
        }
        try {
            $rawWebsiteId = Env::getInstance()->getConfig('website_id');
            $websiteId = \is_numeric($rawWebsiteId) ? \max(0, (int)$rawWebsiteId) : 0;
        } catch (\Throwable) {
            $websiteId = 0;
        }
        if ($themeId > 0) {
            $candidate = $websiteId . '|' . $themeId . '|' . $area;
            if (isset($chains[$candidate])) {
                return self::$resolvedChainKey = $candidate;
            }
        }
        // 地址桶键只取 {site}|{theme}：链的第三段是区域，不再当域名段用。
        $requestHost = '';
        try {
            $requestHost = \strtolower(\trim((string)(Env::getInstance()->getConfig('public_host') ?? '')));
        } catch (\Throwable) {
            $requestHost = '';
        }
        if ($requestHost !== '') {
            foreach ($chains as $key => $row) {
                $hosts = \is_array($row['hosts'] ?? null) ? $row['hosts'] : [];
                if (\in_array($requestHost, $hosts, true)) {
                    return self::$resolvedChainKey = (string)$key;
                }
            }
        }
        // 唯一链兜底（单站单主题场景）
        if (\count($chains) === 1) {
            return self::$resolvedChainKey = (string)\array_key_first($chains);
        }

        return '';
    }

    // ---------------------------------------------------------------- 内部

    private static function ensurePreloaded(): void
    {
        if (self::$chains !== null && (\time() - self::$preloadedAt) < self::PRELOAD_TTL_SECONDS) {
            return;
        }
        self::preload();
    }

    /**
     * 读发布状态文件。
     *
     * 2026-11-01 修正：此前硬编码 `BP . STATE_FILE`，绕过了
     * `PublishStateStore::stateFile()`，导致 `overrideStateFile()`（测试/CLI 重定向）
     * 对 Registry 无效 —— 读写两份不同文件，测试与运行时行为不一致。
     * 路径只允许有一个来源。
     *
     * @return array{chains:array<string,mixed>}
     */
    private static function readStateFile(): array
    {
        $file = PublishStateStore::stateFile();
        if (!\is_file($file)) {
            return ['chains' => []];
        }
        self::$fileReadCount++;
        $raw = @\file_get_contents($file);
        if (!\is_string($raw) || $raw === '') {
            return ['chains' => []];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : ['chains' => []];
    }

    /** 无 publish_state 时的旧口径：部署号 + 指针 pair（升级兼容，不塌缩到单一全局值）。 */
    private static function legacyGenerationUid(): string
    {
        $build = StaticBuildId::current();
        $pair = '';
        try {
            if (\class_exists(\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::class)) {
                $pair = \trim((string)\Weline\Theme\Service\ThemeUpdate\ThemeUpdateRuntime::pairSegment());
            }
        } catch (\Throwable) {
            $pair = '';
        }

        return $pair !== '' ? $build . '~' . $pair : $build;
    }

    private static function logWarning(string $reason, \Throwable $e): void
    {
        try {
            ObjectManager::getInstance(\Weline\Framework\Log\FpmLogger::class)->warning(
                \sprintf('[%s] %s: %s', $reason, \get_class($e), $e->getMessage())
            );
        } catch (\Throwable) {
            // 日志不可用时静默（不得影响请求）
        }
    }
}
