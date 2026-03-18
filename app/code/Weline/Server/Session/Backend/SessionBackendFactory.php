<?php

declare(strict_types=1);

/**
 * Session 后端工厂类
 *
 * 根据配置创建对应的 Session 后端实例。
 * 支持 WLS 内置后端、Redis、Memcached 等。
 *
 * 重要：$instances 是进程级缓存，跨请求复用连接。
 * - 不在每请求后重置（连接应持久化）
 * - 仅在进程退出或显式调用 reset() 时断开
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Backend;

use Weline\Framework\App\Env;

final class SessionBackendFactory
{
    /** 后端类型到类名的映射 */
    private const BACKEND_MAP = [
        'wls' => WlsSessionBackend::class,
        'redis' => RedisSessionBackend::class,
        'memcached' => MemcachedSessionBackend::class,
    ];

    /** 
     * 单例实例（按后端类型缓存）
     * 进程级缓存：跨请求复用连接以提高性能
     */
    private static array $instances = [];

    /**
     * 创建 Session 后端实例
     *
     * @param array $config 配置项，可包含 'backend' 键指定后端类型
     * @return SessionBackendInterface 后端实例
     */
    public static function create(array $config = []): SessionBackendInterface
    {
        $wlsConfig = self::getWlsConfig();

        $backendType = $config['backend'] ?? $wlsConfig['backend'] ?? 'wls';

        if (isset(self::$instances[$backendType])) {
            return self::$instances[$backendType];
        }

        $backendConfig = self::getBackendConfig($backendType, $config, $wlsConfig);

        $backendClass = self::BACKEND_MAP[$backendType] ?? WlsSessionBackend::class;

        if (!\class_exists($backendClass)) {
            $backendClass = WlsSessionBackend::class;
        }

        $instance = new $backendClass($backendConfig);

        self::$instances[$backendType] = $instance;

        return $instance;
    }

    /**
     * 获取 WLS Session 配置
     */
    private static function getWlsConfig(): array
    {
        $wlsSession = Env::getInstance()->getConfig('wls.session');
        return \is_array($wlsSession) ? $wlsSession : [];
    }

    /**
     * 获取特定后端的配置
     *
     * @param string $backendType 后端类型
     * @param array $config 传入的配置
     * @param array $wlsConfig WLS 配置
     * @return array 合并后的配置
     */
    private static function getBackendConfig(string $backendType, array $config, array $wlsConfig): array
    {
        $defaultConfig = [];

        switch ($backendType) {
            case 'wls':
                $defaultConfig = $wlsConfig['wls_server'] ?? [];
                $defaultConfig['host'] = $defaultConfig['host'] ?? '127.0.0.1';
                $defaultConfig['port'] = $defaultConfig['port'] ?? 19970;
                break;

            case 'redis':
                $defaultConfig = $wlsConfig['redis'] ?? [];
                $defaultConfig['host'] = $defaultConfig['host'] ?? '127.0.0.1';
                $defaultConfig['port'] = $defaultConfig['port'] ?? 6379;
                $defaultConfig['database'] = $defaultConfig['database'] ?? 0;
                $defaultConfig['prefix'] = $defaultConfig['prefix'] ?? 'wls_sess:';
                break;

            case 'memcached':
                $defaultConfig = $wlsConfig['memcached'] ?? [];
                $defaultConfig['servers'] = $defaultConfig['servers'] ?? [['127.0.0.1', 11211]];
                $defaultConfig['prefix'] = $defaultConfig['prefix'] ?? 'wls_sess:';
                break;
        }

        return \array_merge($defaultConfig, $config);
    }

    /**
     * 重置所有实例（用于测试）
     */
    public static function reset(): void
    {
        foreach (self::$instances as $instance) {
            if ($instance instanceof SessionBackendInterface) {
                $instance->disconnect();
            }
        }
        self::$instances = [];
    }

    /**
     * 获取所有支持的后端类型
     */
    public static function getSupportedBackends(): array
    {
        return \array_keys(self::BACKEND_MAP);
    }

    /**
     * 检查后端类型是否支持
     */
    public static function isSupported(string $backendType): bool
    {
        return isset(self::BACKEND_MAP[$backendType]);
    }
}
