<?php

declare(strict_types=1);

/**
 * WLS 内存缓存 CDN 适配器
 *
 * 通过编译后的 Edge Cache Provider Registry 注册，
 * 实现本地内存全页缓存的平台无关适配器功能。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Api\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\EdgeCacheAdapterInterface;
use Weline\Server\Service\MemoryCacheRuleManager;
use Weline\Server\Service\MemoryCacheService;

/**
 * WLS 内存缓存 CDN 适配器
 *
 * 通过 Server 模块清单注册到编译 Provider Registry，实现：
 * 1. 响应 CDN 模块的缓存清理请求
 * 2. 同步缓存规则到 WLS 内存缓存
 * 3. 管理本地内存缓存 Zone
 */
class WlsMemory implements EdgeCacheAdapterInterface
{
    /**
     * 适配器代码
     */
    private const ADAPTER_CODE = 'wls_memory';

    /**
     * 适配器版本
     */
    private const VERSION = '1.0.0';

    /**
     * 虚拟 Zone ID（WLS 内存缓存只有一个 Zone）
     */
    private const DEFAULT_ZONE_ID = 'wls_local_memory';

    /**
     * @inheritDoc
     */
    public function getAdapterCode(): string
    {
        return self::ADAPTER_CODE;
    }

    /**
     * @inheritDoc
     */
    public function getAdapterName(): string
    {
        return __('WLS 内存缓存');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('Weline Server Worker L1 与共享 L2 高性能全页缓存');
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * @inheritDoc
     */
    public function purgeEverything(string $zoneId, array $credentials): array
    {
        try {
            MemoryCacheService::purgeAll();
            $stats = MemoryCacheService::getStats();

            return [
                'success' => true,
                'message' => __('WLS 内存缓存已全部清理'),
                'adapter' => self::ADAPTER_CODE,
                'stats' => $stats,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function purgeUrls(string $zoneId, array $urls, array $credentials): array
    {
        try {
            $purged = 0;
            foreach ($urls as $url) {
                if (MemoryCacheService::purgeByUrl($url)) {
                    $purged++;
                }
            }

            return [
                'success' => true,
                'message' => __('已清理 %{1} 个 URL', (string) $purged),
                'adapter' => self::ADAPTER_CODE,
                'purged_count' => $purged,
                'requested_count' => count($urls),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function purgeHosts(string $zoneId, array $hosts, array $credentials): array
    {
        try {
            $purged = 0;
            foreach ($hosts as $host) {
                $purged += MemoryCacheService::purgeByHost($host);
            }

            return [
                'success' => true,
                'message' => __('已清理 %{1} 个 Host 的缓存', (string) $purged),
                'adapter' => self::ADAPTER_CODE,
                'purged_count' => $purged,
                'requested_hosts' => count($hosts),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function purgeTags(string $zoneId, array $tags, array $credentials): array
    {
        try {
            $purged = 0;
            foreach ($tags as $tag) {
                $purged += MemoryCacheService::purgeByTag($tag);
            }

            return [
                'success' => true,
                'message' => __('已清理 %{1} 个 Tag 的缓存', (string) $purged),
                'adapter' => self::ADAPTER_CODE,
                'purged_count' => $purged,
                'requested_tags' => count($tags),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function purgeCacheKeys(string $zoneId, array $keys, array $credentials): array
    {
        try {
            $purged = 0;
            foreach ($keys as $key) {
                if (MemoryCacheService::delete($key)) {
                    $purged++;
                }
            }

            return [
                'success' => true,
                'message' => __('已清理 %{1} 个缓存键', (string) $purged),
                'adapter' => self::ADAPTER_CODE,
                'purged_count' => $purged,
                'requested_keys' => count($keys),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('清理失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getRules(string $zoneId, array $credentials): array
    {
        try {
            $ruleManager = MemoryCacheRuleManager::getInstance();
            $rules = $ruleManager->getRules();

            return [
                'success' => true,
                'rules' => $rules,
                'adapter' => self::ADAPTER_CODE,
                'default_ttl' => $ruleManager->getDefaultTtl(),
                'bypass_patterns' => $ruleManager->getBypassPathPatterns(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('获取规则失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
                'rules' => [],
            ];
        }
    }

    /**
     * 规则更新标记文件路径
     */
    public const RULES_UPDATE_FLAG = 'var/server/rules-update.flag';

    /**
     * 规则文件路径（CDN 后台推送的规则）
     */
    public const RULES_FILE = 'var/server/memory-cache-rules.json';

    /**
     * @inheritDoc
     */
    public function putRules(string $zoneId, array $rules, array $credentials): array
    {
        try {
            // 1. 确保目录存在
            $serverDir = Env::VAR_DIR . 'server';
            if (!is_dir($serverDir)) {
                @mkdir($serverDir, 0755, true);
            }

            // 2. 将规则写入 var/server/memory-cache-rules.json
            $rulesFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
            $rulesData = [
                'description' => 'WLS 内存缓存规则 - 由 CDN 后台推送',
                'version' => '1.0.0',
                'updated_at' => date('Y-m-d H:i:s'),
                'rules' => $rules,
            ];
            $writeResult = file_put_contents(
                $rulesFile,
                json_encode($rulesData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            if ($writeResult === false) {
                throw new \RuntimeException(__('无法写入规则文件: %{1}', $rulesFile));
            }

            // 3. 写入标记文件通知 Dispatcher 重载规则
            $flagFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'rules-update.flag';
            file_put_contents($flagFile, (string) time());

            // 4. 同时更新内存中的规则（如果当前进程中有 RuleManager 实例）
            $ruleManager = MemoryCacheRuleManager::getInstance();
            $ruleManager->updateRules($rules);

            return [
                'success' => true,
                'message' => __('规则已更新，共 %{1} 条，已通知 Dispatcher 重载', (string) count($rules)),
                'adapter' => self::ADAPTER_CODE,
                'rules_count' => count($rules),
                'rules_file' => $rulesFile,
                'flag_file' => $flagFile,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('更新规则失败: %{1}', $e->getMessage()),
                'adapter' => self::ADAPTER_CODE,
            ];
        }
    }

    /**
     * 获取规则更新标记文件路径
     *
     * @return string
     */
    public static function getRulesUpdateFlagPath(): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'rules-update.flag';
    }

    /**
     * 获取规则文件路径
     *
     * @return string
     */
    public static function getRulesFilePath(): string
    {
        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'memory-cache-rules.json';
    }

    /**
     * @inheritDoc
     */
    public function ensureZone(string $domain, array $credentials): array
    {
        // WLS 内存缓存不需要真正的 Zone 管理
        // 所有域名共用同一个本地内存缓存
        return [
            'zone_id' => self::DEFAULT_ZONE_ID,
            'zone_name' => 'WLS Local Memory Cache',
            'domain' => $domain,
            'adapter' => self::ADAPTER_CODE,
            'note' => __('WLS 内存缓存使用单一虚拟 Zone，所有域名共享'),
        ];
    }

    /**
     * 获取缓存统计信息
     *
     * @return array
     */
    public function getStats(): array
    {
        $stats = MemoryCacheService::getStats();

        return [
            'success' => true,
            'adapter' => self::ADAPTER_CODE,
            'stats' => $stats,
            'enabled' => MemoryCacheRuleManager::isEnabled(),
            'max_size' => MemoryCacheRuleManager::getMaxSize(),
            'max_size_human' => $this->formatBytes(MemoryCacheRuleManager::getMaxSize()),
        ];
    }

    /**
     * 检查适配器是否可用
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return MemoryCacheRuleManager::isEnabled();
    }

    /**
     * @inheritDoc
     *
     * WLS 内存缓存无专有真实 IP Header，返回空数组
     */
    public function getRealIpHeaderKeys(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     *
     * WLS 本地内存缓存不支持攻击防护模式
     */
    public function enableAttackMode(string $zoneId, array $credentials, array $attackData = []): array
    {
        return [
            'success' => true,
            'message' => __('WLS 内存缓存适配器不支持攻击防护模式'),
            'adapter' => self::ADAPTER_CODE,
        ];
    }

    /**
     * @inheritDoc
     */
    public function disableAttackMode(string $zoneId, array $credentials): array
    {
        return [
            'success' => true,
            'message' => __('WLS 内存缓存适配器不支持攻击防护模式'),
            'adapter' => self::ADAPTER_CODE,
        ];
    }

    /**
     * @inheritDoc
     */
    public function supportsAttackMode(): bool
    {
        return false;
    }

    /**
     * 格式化字节数为人类可读格式
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
