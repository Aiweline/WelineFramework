<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Worker 进程内的路由策略注册表。
 *
 * 说明：
 * - 策略由 Master 通过 IPC 下发；
 * - 本类只保存进程级只读快照，不保存请求级状态；
 * - 未下发策略时使用内建默认值，保证零配置可用。
 */
class RoutingPolicyRegistry
{
    /**
     * 当前进程的路由策略快照。
     *
     * @var array<string, mixed>|null
     */
    private static ?array $policy = null;

    /**
     * 写入/更新策略快照。
     *
     * @param array<string, mixed> $policy
     */
    public static function update(array $policy): void
    {
        self::$policy = $policy;
    }

    /**
     * 获取完整策略快照。
     *
     * @return array<string, mixed>|null
     */
    public static function getPolicy(): ?array
    {
        return self::$policy;
    }

    /**
     * Session: file 驱动是否接管到 wls。
     */
    public static function shouldHijackSessionFile(): bool
    {
        $value = self::$policy['routing']['session']['hijack_file_driver'] ?? true;
        return (bool)$value;
    }

    /**
     * Cache: file 驱动是否接管到 wls_memory。
     */
    public static function shouldHijackCacheFile(): bool
    {
        $value = self::$policy['routing']['cache']['hijack_file_driver'] ?? true;
        return (bool)$value;
    }

    /**
     * 获取 Session 服务端点。
     *
     * @return array{host: string, port: int}
     */
    public static function getSessionEndpoint(): array
    {
        $host = (string)(self::$policy['endpoints']['session']['host'] ?? '127.0.0.1');
        $port = (int)(self::$policy['endpoints']['session']['port'] ?? 19970);
        return ['host' => $host, 'port' => $port > 0 ? $port : 19970];
    }

    /**
     * 获取 Memory 服务端点。
     *
     * @return array{host: string, port: int}
     */
    public static function getMemoryEndpoint(): array
    {
        $host = (string)(self::$policy['endpoints']['memory']['host'] ?? '127.0.0.1');
        $port = (int)(self::$policy['endpoints']['memory']['port'] ?? 19971);
        return ['host' => $host, 'port' => $port > 0 ? $port : 19971];
    }

    /**
     * 测试/调试辅助：清空快照。
     */
    public static function clear(): void
    {
        self::$policy = null;
    }
}

