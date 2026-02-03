<?php
declare(strict_types=1);

/**
 * Weline Server - Connection 接口
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Connection;

/**
 * ConnectionInterface - 连接接口
 */
interface ConnectionInterface
{
    /**
     * 发送数据
     * 
     * @param mixed $data 要发送的数据
     * @param bool $raw 是否发送原始数据（不经过协议编码）
     * @return bool
     */
    public function send(mixed $data, bool $raw = false): bool;
    
    /**
     * 关闭连接
     * 
     * @param mixed $data 关闭前发送的数据
     */
    public function close(mixed $data = null): void;
    
    /**
     * 获取远程 IP
     */
    public function getRemoteIp(): string;
    
    /**
     * 获取远程端口
     */
    public function getRemotePort(): int;
    
    /**
     * 获取本地 IP
     */
    public function getLocalIp(): string;
    
    /**
     * 获取本地端口
     */
    public function getLocalPort(): int;
    
    /**
     * 暂停接收数据
     */
    public function pauseRecv(): void;
    
    /**
     * 恢复接收数据
     */
    public function resumeRecv(): void;
}
