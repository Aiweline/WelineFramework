<?php
declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

use Weline\Server\Log\WlsLogger;

/**
 * 实例信息网关 - 统一读取最新的 instance 信息
 * 
 * 用途：各子进程（Worker/Dispatcher/SessionServer 等）在需要读取 instance 配置信息时，
 *      通过此网关读取最新值，而不是缓存启动时的旧值
 * 
 * 优点：
 * - Master 更新了 control_port（如自动口优化调动）时，子进程能实时感知
 * - 避免各进程重复编写文件读取和JSON解析逻辑
 * - 统一的错误处理和日志记录
 * 
 * @author Aiweline
 */
class InstanceInfoGateway
{
    /**
     * @var string Instance name (e.g., 'default', 'frontend')
     */
    private string $instanceName;
    
    /**
     * @var array 缓存的实例信息（从上次成功读取中得到）
     */
    private array $cachedInfo = [];
    
    /**
     * @var int 最后一次成功读取的 control_port（用于检测变化）
     */
    private int $lastKnownControlPort = 0;
    
    /**
     * @var string instance.json 文件路径
     */
    private string $instanceJsonPath;
    
    public function __construct(string $instanceName)
    {
        if (!\defined('BP')) {
            throw new \RuntimeException('BP constant not defined');
        }
        
        $this->instanceName = $instanceName;
        $this->instanceJsonPath = BP . 'var' . DIRECTORY_SEPARATOR 
            . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR 
            . $instanceName . '.json';
    }
    
    /**
     * 读取最新的 instance 信息（每次调用都从磁盘读取，不使用缓存）
     * 
     * @return array|null 返回 instance 配置数组，若文件不存在或解析失败返回 null
     */
    public function readLatestInfo(): ?array
    {
        if (!\is_file($this->instanceJsonPath)) {
            return null;
        }
        
        $content = @\file_get_contents($this->instanceJsonPath);
        if ($content === false) {
            WlsLogger::debug_("[InstanceInfoGateway] 无法读取 instance 文件: {$this->instanceJsonPath}");
            return null;
        }
        
        $info = @\json_decode($content, true);
        if (!\is_array($info)) {
            WlsLogger::debug_("[InstanceInfoGateway] instance JSON 格式错误或为空");
            return null;
        }
        
        // 缓存此次读取的信息
        $this->cachedInfo = $info;
        
        return $info;
    }
    
    /**
     * 获取最新的 control_port
     * 
     * @param int $fallbackPort 若无法读取时的回退端口
     * @return int control_port 值（至少为 0），若不可用返回 $fallbackPort
     */
    public function getLatestControlPort(int $fallbackPort = 0): int
    {
        $info = $this->readLatestInfo();
        if ($info === null || !isset($info['control_port'])) {
            return $fallbackPort;
        }
        
        $port = (int)$info['control_port'];
        return $port > 0 ? $port : $fallbackPort;
    }
    
    /**
     * 检测 control_port 是否已改变
     * 
     * 这是一个便利方法，用于提醒主循环 port 已更新。
     * 
     * @param int $currentPort 当前记录的端口号
     * @return bool 如果端口发生改变返回 true，同时 $currentPort 会被更新为新值
     */
    public function hasControlPortChanged(int &$currentPort): bool
    {
        $latestPort = $this->getLatestControlPort($currentPort);
        
        if ($latestPort !== $currentPort) {
            WlsLogger::warning_(
                "[InstanceInfoGateway] control_port 已更新（可能是 Master 自动顺延）: "
                . "{$currentPort} → {$latestPort}"
            );
            $currentPort = $latestPort;
            return true;
        }
        
        return false;
    }
    
    /**
     * 检测 control_port 是否已改变（不修改传入值的版本）
     *
     * @param int $currentPort 当前记录的端口号
     * @return bool 如果端口发生改变返回 true
     */
    public function isControlPortChanged(int $currentPort): bool
    {
        $latestPort = $this->getLatestControlPort($currentPort);
        return $latestPort !== $currentPort;
    }
    
    /**
     * 获取当前缓存的完整 instance 信息
     * 
     * @return array 返回最后一次成功读取的 instance 配置
     */
    public function getCachedInfo(): array
    {
        return $this->cachedInfo;
    }
    
    /**
     * 获取特定字段的值
     * 
     * @param string $field 字段名
     * @param mixed $default 默认值
     * @return mixed 字段值或默认值
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->cachedInfo[$field] ?? $default;
    }
}
