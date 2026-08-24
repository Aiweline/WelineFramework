<?php
declare(strict_types=1);

/**
 * Weline Server 启动策略接口
 * 
 * 遵循 SOLID 原则：
 * - S：单一职责 - 每个策略只负责特定平台的服务器启动
 * - O：开闭原则 - 新增平台支持只需添加新策略实现
 * - L：里氏替换 - 所有策略可互相替换
 * - I：接口隔离 - 定义精确的接口契约
 * - D：依赖倒置 - 高层模块依赖此抽象接口
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Strategy;

/**
 * 服务器启动策略接口
 * 
 * 不同平台有不同的最优启动方式：
 * - Linux/Mac：SO_REUSEPORT 直连模式（多 Worker 监听同一端口）
 * - Windows：Dispatcher TCP 透传模式（单入口分流到多 Worker）
 */
interface ServerStrategyInterface
{
    /**
     * 获取策略名称
     * 
     * @return string 如 "Linux SO_REUSEPORT 直连模式" 或 "Windows Dispatcher 透传模式"
     */
    public function getName(): string;
    
    /**
     * 获取策略简短标识
     * 
     * @return string 如 "linux-direct" 或 "windows-dispatcher"
     */
    public function getIdentifier(): string;
    
    /**
     * 检查当前策略是否支持当前平台
     * 
     * @return bool true=支持当前平台
     */
    public function supports(): bool;
    
    /**
     * 启动服务器
     * 
     * @param ServerConfig $config 服务器配置
     * @return bool 是否启动成功
     */
    public function start(ServerConfig $config): bool;
    
    /**
     * 停止服务器
     * 
     * @param string $instanceName 实例名称
     * @return bool 是否停止成功
     */
    public function stop(string $instanceName): bool;
    
    /**
     * 获取服务器运行状态
     * 
     * @param string $instanceName 实例名称
     * @return array{
     *     running: bool,
     *     mode: string,
     *     workers: array,
     *     dispatcher: array|null,
     *     master: array|null,
     *     uptime: int
     * }
     */
    public function getStatus(string $instanceName): array;
    
    /**
     * 获取策略的架构说明
     * 
     * @return string 架构描述，如 "Worker 直接监听端口，内核负载均衡"
     */
    public function getArchitectureDescription(): string;
    
    /**
     * 设置日志回调函数
     * 
     * @param callable $callback 回调函数 function(string $message, string $level): void
     * @return void
     */
    public function setLogCallback(callable $callback): void;
}
