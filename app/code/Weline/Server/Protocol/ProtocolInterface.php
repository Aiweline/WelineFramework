<?php
declare(strict_types=1);

/**
 * Weline Server - Protocol 接口
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

use Weline\Server\Connection\TcpConnection;

/**
 * ProtocolInterface - 协议接口
 * 
 * 所有应用层协议必须实现此接口
 */
interface ProtocolInterface
{
    /**
     * 检查包的完整性
     * 
     * 用于从接收缓冲区中判断一个完整包的长度
     * 
     * @param string $buffer 接收缓冲区数据
     * @param TcpConnection $connection 连接对象
     * @return int 返回包长度（0 表示包不完整，负数表示协议错误）
     */
    public static function input(string $buffer, TcpConnection $connection): int;
    
    /**
     * 解码数据
     * 
     * 将收到的完整包解码为应用层数据
     * 
     * @param string $buffer 完整的数据包
     * @param TcpConnection $connection 连接对象
     * @return mixed 解码后的数据
     */
    public static function decode(string $buffer, TcpConnection $connection): mixed;
    
    /**
     * 编码数据
     * 
     * 将应用层数据编码为可发送的格式
     * 
     * @param mixed $data 应用层数据
     * @param TcpConnection $connection 连接对象
     * @return string 编码后的数据
     */
    public static function encode(mixed $data, TcpConnection $connection): string;
}
