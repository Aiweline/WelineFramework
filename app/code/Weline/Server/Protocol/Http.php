<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP 协议
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

use Weline\Server\Connection\TcpConnection;

/**
 * Http - HTTP/1.1 协议实现
 */
class Http implements ProtocolInterface
{
    /**
     * @inheritDoc
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // 检查是否包含完整 header
        $headerEnd = strpos($buffer, "\r\n\r\n");
        
        if ($headerEnd === false) {
            // header 超过 16KB，可能是攻击
            if (strlen($buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Entity Too Large\r\n\r\n");
                return 0;
            }
            return 0;
        }
        
        // 解析请求方法
        $method = strstr($buffer, ' ', true);
        
        // GET/HEAD/OPTIONS/DELETE 等没有 body
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $headerEnd + 4;
        }
        
        // 获取 Content-Length
        $contentLength = 0;
        
        if (preg_match('/\r\nContent-Length:\s*(\d+)/i', $buffer, $match)) {
            $contentLength = (int) $match[1];
        }
        
        // 检查 Transfer-Encoding: chunked
        if (preg_match('/\r\nTransfer-Encoding:\s*chunked/i', $buffer)) {
            return static::parseChunked($buffer, $headerEnd);
        }
        
        return $headerEnd + 4 + $contentLength;
    }
    
    /**
     * 解析 chunked 编码
     */
    protected static function parseChunked(string $buffer, int $headerEnd): int
    {
        $bodyStart = $headerEnd + 4;
        $body = substr($buffer, $bodyStart);
        
        $offset = 0;
        $length = strlen($body);
        
        while ($offset < $length) {
            // 查找 chunk size 行
            $lineEnd = strpos($body, "\r\n", $offset);
            
            if ($lineEnd === false) {
                return 0;
            }
            
            $chunkSize = hexdec(substr($body, $offset, $lineEnd - $offset));
            
            // 最后一个 chunk
            if ($chunkSize === 0) {
                // 检查是否有结尾的 \r\n
                if ($lineEnd + 4 > $length) {
                    return 0;
                }
                return $bodyStart + $lineEnd + 4;
            }
            
            $offset = $lineEnd + 2 + $chunkSize + 2;
            
            if ($offset > $length) {
                return 0;
            }
        }
        
        return 0;
    }
    
    /**
     * @inheritDoc
     */
    public static function decode(string $buffer, TcpConnection $connection): Request
    {
        return new Request($buffer);
    }
    
    /**
     * @inheritDoc
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        if ($data instanceof Response) {
            return (string) $data;
        }
        
        // 字符串直接包装为 200 响应
        if (is_string($data)) {
            $response = new Response(200, [], $data);
            return (string) $response;
        }
        
        // 数组转为 JSON
        if (is_array($data)) {
            $response = new Response(
                200,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
            return (string) $response;
        }
        
        // 其他类型转为字符串
        return (string) (new Response(200, [], (string) $data));
    }
}
