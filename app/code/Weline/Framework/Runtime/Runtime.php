<?php
declare(strict_types=1);

/**
 * Weline Framework - 运行时辅助类
 * 
 * 提供静态方法检测当前运行模式，避免直接使用 WLS_MODE 常量。
 * 这是一个纯粹的辅助类，不持有状态。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\Compilation\ServiceProviderRegistry;

/**
 * 运行时辅助类
 * 
 * 用法：
 * - Runtime::isPersistent() 替代 defined('WLS_MODE') && WLS_MODE
 * - Runtime::isWls() 检测 WLS 模式
 * - Runtime::isFpm() 检测 FPM 模式
 * - Runtime::isCli() 检测 CLI 模式
 * - Runtime::createRuntime() 按当前环境返回 WlsRuntime（Server Provider）或 FpmRuntime
 */
class Runtime
{
    /**
     * 指定 App / 集成层使用的运行时标识（与 RuntimeInterface::MODE_* 一致）
     *
     * 例：new \Weline\Framework\App(\Weline\Framework\Runtime\Runtime::FPM)
     */
    public const FPM = RuntimeInterface::MODE_FPM;

    public const WLS = RuntimeInterface::MODE_WLS;

    public const CLI = RuntimeInterface::MODE_CLI;

    /**
     * 按当前进程检测结果构造 RuntimeInterface：
     * WLS 经编译表 RuntimeProviderInterface（Weline_Server）创建，否则 FpmRuntime。
     * WLS 解析仅读 generated modules.php + 零参 new Provider，不经 ObjectManager。
     */
    public static function createRuntime(): RuntimeInterface
    {
        if (self::isWls()) {
            $providerClass = (new ServiceProviderRegistry())
                ->implementationFor(RuntimeProviderInterface::class);
            if ($providerClass === null
                || !\is_a($providerClass, RuntimeProviderInterface::class, true)
            ) {
                throw new \RuntimeException(
                    'WLS mode requires RuntimeProviderInterface from Weline_Server. Run: php bin/w framework:compile'
                );
            }
            /** @var RuntimeProviderInterface $provider */
            $provider = new $providerClass();
            if (!$provider->supports(RuntimeInterface::MODE_WLS)) {
                throw new \RuntimeException(
                    'RuntimeProviderInterface implementation does not support WLS mode: ' . $providerClass
                );
            }

            return $provider->create(RuntimeInterface::MODE_WLS);
        }

        return new FpmRuntime();
    }

    /**
     * 当前运行模式（缓存，避免重复检测）
     */
    private static ?string $mode = null;
    
    /**
     * 检测是否为常驻内存模式
     * 
     * 替代 defined('WLS_MODE') && WLS_MODE 判断
     * 
     * @return bool
     */
    public static function isPersistent(): bool
    {
        return self::isWls();
    }
    
    /**
     * 检测是否为 WLS（Weline Server）模式
     * 
     * @return bool
     */
    public static function isWls(): bool
    {
        if (\defined('WLS_MODE') && WLS_MODE) {
            self::$mode = RuntimeInterface::MODE_WLS;
            return true;
        }

        if (self::$mode !== null) {
            return self::$mode === RuntimeInterface::MODE_WLS;
        }
        
        // 通过 WLS_MODE 常量检测
        self::$mode = self::detectMode();
        return self::$mode === RuntimeInterface::MODE_WLS;
    }
    
    /**
     * 检测是否为 FPM 模式
     * 
     * @return bool
     */
    public static function isFpm(): bool
    {
        if (self::$mode === null) {
            self::$mode = self::detectMode();
        }
        return self::$mode === RuntimeInterface::MODE_FPM;
    }
    
    /**
     * 检测是否为 CLI 模式
     * 
     * @return bool
     */
    public static function isCli(): bool
    {
        return \in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && !self::isWls();
    }
    
    /**
     * 获取当前运行模式
     * 
     * @return string RuntimeInterface::MODE_*
     */
    public static function getMode(): string
    {
        if (self::$mode === null) {
            self::$mode = self::detectMode();
        }
        return self::$mode;
    }
    
    /**
     * 检测当前运行模式
     * 
     * @return string
     */
    private static function detectMode(): string
    {
        // WLS 模式
        if (\defined('WLS_MODE') && WLS_MODE) {
            return RuntimeInterface::MODE_WLS;
        }

        if ((string)($_SERVER['WLS_PROCESS_ROLE'] ?? $_ENV['WLS_PROCESS_ROLE'] ?? \getenv('WLS_PROCESS_ROLE') ?: '') !== ''
            || (string)($_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: '') !== ''
            || (string)($_SERVER['WLS_INSTANCE_NAME'] ?? $_ENV['WLS_INSTANCE_NAME'] ?? \getenv('WLS_INSTANCE_NAME') ?: '') !== ''
        ) {
            return RuntimeInterface::MODE_WLS;
        }
        
        // CLI 模式
        if (\in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return RuntimeInterface::MODE_CLI;
        }
        
        // FPM 模式
        return RuntimeInterface::MODE_FPM;
    }
    
    /**
     * 重置模式检测缓存（用于测试）
     */
    public static function resetModeCache(): void
    {
        self::$mode = null;
    }
    
    /**
     * 设置模式（用于测试或强制模式）
     * 
     * @param string $mode RuntimeInterface::MODE_*
     */
    public static function setMode(string $mode): void
    {
        self::$mode = $mode;
    }
}
