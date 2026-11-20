<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use Weline\Framework\Deploy\DeployStagingSession;
use Weline\Framework\Runtime\RequestContext;

/**
 * 模板导航 manifest：把「逻辑文件 → 解析后源文件」的映射固化为编译期产物。
 *
 * 现状（无 manifest）：每个模板 fetch 都要算 viewEnvironmentCacheSuffix +
 * 派发 resolve_theme_cache_suffix 事件 + viewCache(FileAdapter) 磁盘读 +
 * miss 时再走 fetch_file 三段观察者（主题链 is_file 扫描 / I18n 全文读 /
 * Sticker 注册表）。常驻 Worker 下这些成本每请求重复支付。
 *
 * 本类把「某主题 scope 下 filename 解析到哪」固化为 PHP 数组文件：
 * generated/framework/view_manifest/{scopeHash}.php
 *
 * 命中路径 = 一次静态数组查表 + 一次 is_file 验证；
 * 未命中 = 完整回退原有链路（事件派发 → 主题解析 → 观察者），
 * 然后把解析结果写回 manifest（原子写，跨 Worker 自愈）。
 *
 * 失效：共享代际 key（w_cache('view') 池）——主题保存/删除、Sticker 编译、
 * 模块安装/卸载、framework:compile 时 bump；代际不符的 bag 整袋重建，
 * 不产生孤儿键（固定文件、覆盖写）。
 *
 * 生命周期边界：manifest 只在 PROD 非 staging 会话启用；DEV / 部署 staging /
 * 主题编辑器预览路径仍走全链（编辑器必须活解析）。预览 scope 后缀里带
 * request_theme/preview_token 的请求不写入 manifest。
 */
final class ViewPathManifest
{
    /** 共享代际键（w_cache('view') 池内） */
    private const GENERATION_KEY = 'view.navigation_manifest.generation';
    /** manifest 文件格式版本：维度/语义变动时递增，旧文件整体作废 */
    private const SCHEMA = 'v1';
    private const RELATIVE_DIR = 'generated' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'view_manifest';
    /** 单袋条目上限：防异常 filename 风暴把文件吹大 */
    private const MAX_ENTRIES_PER_BAG = 4096;

    /** @var array<string, array<string, string>> scopeKey => map */
    private static array $bags = [];
    /** @var array<string, array{file: string, gen: string, dirty: bool}> */
    private static array $bagMeta = [];
    /** 请求级 memo：共享代际只读一次 */
    private static ?string $generationMemo = null;
    /** 请求级 memo：manifest 启用性（不依赖 scope） */
    private static ?bool $enabledMemo = null;
    /** 测试 seam：强制启用/禁用（null = 按 PROD+staging 判）。 */
    private static ?bool $enabledOverride = null;
    /** 测试 seam：manifest 目录覆盖（null = generated/framework/view_manifest）。 */
    private static ?string $dirOverride = null;
    /** 测试 seam：共享代际覆盖（null = 读 w_cache('view')）。 */
    private static ?string $generationOverride = null;

    /** 测试 seam：覆盖启用判定；传 null 恢复默认。 */
    public static function setEnabledOverrideForTest(?bool $enabled): void
    {
        self::$enabledOverride = $enabled;
        self::$enabledMemo = null;
    }

    /** 测试 seam：覆盖 manifest 目录；传 null 恢复默认。 */
    public static function setDirectoryForTest(?string $dir): void
    {
        self::$dirOverride = $dir === null ? null : \rtrim($dir, '/\\');
        self::$bags = [];
        self::$bagMeta = [];
    }

    /** 测试 seam：覆盖共享代际；传 null 恢复默认。 */
    public static function setGenerationOverrideForTest(?string $generation): void
    {
        self::$generationOverride = $generation;
        self::$generationMemo = null;
        self::$bags = [];
        self::$bagMeta = [];
    }

    /**
     * 是否启用 manifest 命中（不感知 scope 的部分）。
     * 调用方仍需自行排除预览/编辑/skip 上下文。
     */
    public static function enabled(): bool
    {
        if (self::$enabledOverride !== null) {
            return self::$enabledOverride;
        }
        if (self::$enabledMemo !== null) {
            return self::$enabledMemo;
        }
        return self::$enabledMemo = self::resolveEnabled();
    }

    private static function resolveEnabled(): bool
    {
        if (!\defined('PROD') || !PROD) {
            return false;
        }
        try {
            if (\class_exists(DeployStagingSession::class, false)
                && DeployStagingSession::isActive()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** 测试/调试复位（不影响线上语义）。 */
    public static function resetForTest(): void
    {
        self::$bags = [];
        self::$bagMeta = [];
        self::$generationMemo = null;
        self::$enabledMemo = null;
    }

    /**
     * scope 命中：manifest 中存在且解析文件仍在 → 返回解析路径。
     * 未命中/失效/文件丢失 → null（调用方走全链）。
     *
     * @param string $scopeKey 调用方算好的 scope key（area+theme 维度，不含 env）
     * @param string $env      视图环境摘要（hooks registry / 编译 schema / 运行时根等），
     *                         变化时整袋作废并按新 env 原地重建——不产生孤儿文件
     */
    public static function resolve(string $filename, string $scopeKey, string $env): ?string
    {
        if ($scopeKey === '' || !self::enabled()) {
            return null;
        }
        $bag = self::bag($scopeKey, $env);
        if ($bag === null) {
            return null;
        }
        $resolved = $bag[$filename] ?? null;
        if (!\is_string($resolved) || $resolved === '') {
            return null;
        }
        // 防护：条目指向的源已被删除时回退全链自愈
        if (!\is_file($resolved)) {
            unset(self::$bags[$scopeKey][$filename]);
            self::$bagMeta[$scopeKey]['dirty'] = true;
            return null;
        }
        return $resolved;
    }

    /**
     * 全链解析完成后回写。仅当 scope 可固化（非预览上下文）时落袋。
     * 写完即持久化（miss 是稀有路径，写放大可控），保证其他 Worker 立刻受益。
     */
    public static function record(string $filename, string $resolved, string $scopeKey, string $env): void
    {
        if ($scopeKey === '' || $resolved === '' || !self::enabled()) {
            return;
        }
        if (\str_contains($scopeKey, 'request_theme:') || \str_contains($scopeKey, 'preview_token:')) {
            return; // 预览/显式主题上下文不固化
        }
        if (!\is_file($resolved)) {
            return;
        }
        $bag = self::bag($scopeKey, $env) ?? [];
        if (($bag[$filename] ?? null) === $resolved) {
            return;
        }
        if (\count($bag) >= self::MAX_ENTRIES_PER_BAG) {
            return;
        }
        $bag[$filename] = $resolved;
        self::$bags[$scopeKey] = $bag;
        self::persist($scopeKey, $bag, $env);
    }

    /** 失效入口：代际 bump（固定 key 覆盖写，无孤儿）。 */
    public static function bumpGeneration(): void
    {
        try {
            $pool = w_cache('view');
            $pool->set(self::GENERATION_KEY, self::newGenerationValue());
        } catch (\Throwable) {
            // 缓存不可用时 manifest 靠文件 mtime 退化：下次 record 覆盖即可
        }
        // 本进程立即失效
        self::$bags = [];
        self::$bagMeta = [];
        self::$generationMemo = null;
    }

    /**
     * 编译/升级后调用：清空 manifest 目录 + bump 代际。
     * framework:compile 与 setup:upgrade 均可安全调用。
     */
    public static function clearAll(): void
    {
        self::$bags = [];
        self::$bagMeta = [];
        $dir = self::dir();
        if (\is_dir($dir)) {
            foreach (\scandir($dir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (\is_file($path)) {
                    @\unlink($path);
                }
            }
        }
        self::bumpGeneration();
    }

    /** 当前共享代际（每请求 memo，跨 Worker 一致）。 */
    private static function currentGeneration(): string
    {
        if (self::$generationOverride !== null) {
            return self::$generationOverride;
        }
        if (self::$generationMemo !== null) {
            return self::$generationMemo;
        }
        $gen = '';
        try {
            $value = w_cache('view')->get(self::GENERATION_KEY);
            $gen = \is_string($value) ? $value : '';
        } catch (\Throwable) {
            $gen = '';
        }
        if ($gen === '') {
            $gen = '0';
        }
        return self::$generationMemo = $gen;
    }

    private static function newGenerationValue(): string
    {
        return \date('YmdHis') . '-' . \bin2hex(\random_bytes(4));
    }

    /**
     * 读取某 scope 的 bag（代际 + env 双闸）。
     * @return array<string, string>|null null = 不可用/失配
     */
    private static function bag(string $scopeKey, string $env): ?array
    {
        if (isset(self::$bags[$scopeKey])) {
            $meta = self::$bagMeta[$scopeKey] ?? null;
            if ($meta === null || ($meta['env'] ?? '') === $env) {
                return self::$bags[$scopeKey];
            }
            // env 变化：整袋作废，原地按新 env 重建
            self::$bags[$scopeKey] = [];
            self::$bagMeta[$scopeKey] = ['file' => $meta['file'], 'gen' => self::currentGeneration(), 'env' => $env, 'dirty' => true];
            return self::$bags[$scopeKey];
        }
        $file = self::manifestFile($scopeKey);
        if (!\is_file($file)) {
            self::$bags[$scopeKey] = [];
            self::$bagMeta[$scopeKey] = ['file' => $file, 'gen' => self::currentGeneration(), 'env' => $env, 'dirty' => false];
            return self::$bags[$scopeKey];
        }
        $data = null;
        try {
            $data = require $file;
        } catch (\Throwable) {
            $data = null;
        }
        if (!\is_array($data)
            || ($data['schema'] ?? '') !== self::SCHEMA
            || !\is_array($data['map'] ?? null)) {
            self::$bags[$scopeKey] = [];
            self::$bagMeta[$scopeKey] = ['file' => $file, 'gen' => self::currentGeneration(), 'env' => $env, 'dirty' => true];
            return self::$bags[$scopeKey];
        }
        $gen = self::currentGeneration();
        $fileGen = (string)($data['gen'] ?? '');
        $fileEnv = (string)($data['env'] ?? '');
        if (($gen !== '0' && $fileGen !== '' && $fileGen !== $gen)
            || $fileEnv !== $env) {
            // 代际/环境失配：整袋作废（懒重建，不删文件——record 会覆盖）
            self::$bags[$scopeKey] = [];
            self::$bagMeta[$scopeKey] = ['file' => $file, 'gen' => $gen, 'env' => $env, 'dirty' => true];
            return self::$bags[$scopeKey];
        }
        /** @var array<string, string> $map */
        $map = $data['map'];
        self::$bags[$scopeKey] = $map;
        self::$bagMeta[$scopeKey] = ['file' => $file, 'gen' => $gen !== '0' ? $gen : $fileGen, 'env' => $env, 'dirty' => false];
        return self::$bags[$scopeKey];
    }

    /** 原子持久化单袋：读改写 + 锁 + 临时文件 rename。 */
    private static function persist(string $scopeKey, array $map, string $env): void
    {
        $file = self::manifestFile($scopeKey);
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0770, true) && !\is_dir($dir)) {
            return;
        }
        $gen = self::currentGeneration();
        self::$bagMeta[$scopeKey] = ['file' => $file, 'gen' => $gen, 'env' => $env, 'dirty' => false];

        // 并发合并：其他 Worker 可能刚写过——读入最新再合并
        $lockFile = $file . '.lock';
        $lock = @\fopen($lockFile, 'c');
        if (\is_resource($lock)) {
            @\flock($lock, \LOCK_EX);
        }
        try {
            if (\is_file($file)) {
                $existing = null;
                try {
                    $existing = require $file;
                } catch (\Throwable) {
                    $existing = null;
                }
                if (\is_array($existing)
                    && ($existing['schema'] ?? '') === self::SCHEMA
                    && \is_array($existing['map'] ?? null)
                    && (string)($existing['gen'] ?? '') === $gen
                    && (string)($existing['env'] ?? '') === $env) {
                    $merged = $existing['map'];
                    foreach ($map as $k => $v) {
                        $merged[$k] = $v;
                    }
                    $map = $merged;
                    self::$bags[$scopeKey] = $map;
                }
            }
            $payload = '<?php return ' . \var_export([
                'schema' => self::SCHEMA,
                'gen' => $gen,
                'env' => $env,
                'map' => $map,
            ], true) . ';';
            $tmp = $file . '.tmp-' . \bin2hex(\random_bytes(4));
            if (@\file_put_contents($tmp, $payload, \LOCK_EX) !== false) {
                @\rename($tmp, $file);
            } else {
                @\unlink($tmp);
            }
        } finally {
            if (\is_resource($lock)) {
                @\flock($lock, \LOCK_UN);
                @\fclose($lock);
            }
        }
    }

    private static function manifestFile(string $scopeKey): string
    {
        return self::dir() . DIRECTORY_SEPARATOR . 'scope-' . \sha1(self::SCHEMA . '|' . $scopeKey) . '.php';
    }

    private static function dir(): string
    {
        if (self::$dirOverride !== null) {
            return self::$dirOverride;
        }
        $base = \defined('BP') ? \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR : '';
        return $base . self::RELATIVE_DIR;
    }
}
