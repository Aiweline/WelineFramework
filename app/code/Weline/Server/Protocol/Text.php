<?php
declare(strict_types=1);

/**
 * Weline Server - Text 协议
 * 
 * 简单的文本协议，以换行符分隔消息
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

use Weline\Server\Connection\TcpConnection;

/**
 * Text - 文本协议
 * 
 * 每条消息以 \n 结尾
 */
class Text implements ProtocolInterface
{
    /**
     * @inheritDoc
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // 查找换行符
        $pos = strpos($buffer, "\n");
        
        if ($pos === false) {
            // 检查缓冲区大小限制
            if (strlen($buffer) >= TcpConnection::$maxPackageSize) {
                $connection->close();
                return 0;
            }
            return 0;
        }
        
        // 返回完整消息长度（包括换行符）
        return $pos + 1;
    }
    
    /**
     * @inheritDoc
     */
    public static function decode(string $buffer, TcpConnection $connection): string
    {
        // 移除末尾的换行符
        return rtrim($buffer, "\r\n");
    }
    
    /**
     * @inheritDoc
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        // 添加换行符
        return $data . "\n";
    }
}
