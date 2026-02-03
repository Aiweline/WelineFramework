<?php
declare(strict_types=1);

/**
 * Weline Server - WebSocket 协议
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Protocol;

use Weline\Server\Connection\TcpConnection;

/**
 * WebSocket - WebSocket 协议实现
 */
class WebSocket implements ProtocolInterface
{
    /**
     * WebSocket GUID
     */
    protected const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    
    /**
     * 帧操作码
     */
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xa;
    
    /**
     * @inheritDoc
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // 检查是否已完成握手
        if (!isset($connection->websocketHandshake)) {
            return static::handshakeInput($buffer, $connection);
        }
        
        // WebSocket 帧解析
        return static::frameInput($buffer, $connection);
    }
    
    /**
     * 握手输入处理
     */
    protected static function handshakeInput(string $buffer, TcpConnection $connection): int
    {
        // 检查 HTTP 请求头是否完整
        $headerEnd = strpos($buffer, "\r\n\r\n");
        
        if ($headerEnd === false) {
            if (strlen($buffer) > 8192) {
                $connection->close();
                return 0;
            }
            return 0;
        }
        
        return $headerEnd + 4;
    }
    
    /**
     * WebSocket 帧输入处理
     */
    protected static function frameInput(string $buffer, TcpConnection $connection): int
    {
        $len = strlen($buffer);
        
        if ($len < 2) {
            return 0;
        }
        
        // 解析帧头
        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        
        $opcode = $firstByte & 0x0f;
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7f;
        
        $headLen = 2;
        
        // 扩展长度
        if ($payloadLen === 126) {
            if ($len < 4) {
                return 0;
            }
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $headLen = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return 0;
            }
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $headLen = 10;
        }
        
        // 掩码
        if ($masked) {
            $headLen += 4;
        }
        
        $frameLen = $headLen + $payloadLen;
        
        if ($len < $frameLen) {
            return 0;
        }
        
        // 保存帧信息
        $connection->websocketOpcode = $opcode;
        $connection->websocketMasked = $masked;
        
        return $frameLen;
    }
    
    /**
     * @inheritDoc
     */
    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        // 握手处理
        if (!isset($connection->websocketHandshake)) {
            return static::handleHandshake($buffer, $connection);
        }
        
        // 帧解码
        return static::decodeFrame($buffer, $connection);
    }
    
    /**
     * 处理握手
     */
    protected static function handleHandshake(string $buffer, TcpConnection $connection): mixed
    {
        // 解析 HTTP 请求
        $headers = [];
        $lines = explode("\r\n", substr($buffer, 0, strpos($buffer, "\r\n\r\n")));
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        
        // 检查 WebSocket 升级请求
        if (!isset($headers['sec-websocket-key'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n");
            return null;
        }
        
        $key = $headers['sec-websocket-key'];
        $acceptKey = base64_encode(sha1($key . self::GUID, true));
        
        // 发送握手响应
        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n";
        $response .= "Server: Weline Server\r\n";
        $response .= "\r\n";
        
        $connection->send($response, true);
        
        // 标记握手完成
        $connection->websocketHandshake = true;
        $connection->websocketHeaders = $headers;
        
        // 返回连接事件
        return ['type' => 'connect', 'headers' => $headers];
    }
    
    /**
     * 解码帧
     */
    protected static function decodeFrame(string $buffer, TcpConnection $connection): mixed
    {
        $len = strlen($buffer);
        
        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        
        $fin = ($firstByte & 0x80) !== 0;
        $opcode = $firstByte & 0x0f;
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7f;
        
        $offset = 2;
        
        // 扩展长度
        if ($payloadLen === 126) {
            $payloadLen = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            $payloadLen = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }
        
        // 掩码
        $mask = null;
        if ($masked) {
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }
        
        // 负载数据
        $payload = substr($buffer, $offset, $payloadLen);
        
        // 解掩码
        if ($masked && $mask !== null) {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
        }
        
        // 处理控制帧
        switch ($opcode) {
            case self::OPCODE_CLOSE:
                $connection->close();
                return ['type' => 'close', 'data' => $payload];
                
            case self::OPCODE_PING:
                // 发送 PONG
                $connection->send(static::encodeFrame($payload, self::OPCODE_PONG), true);
                return ['type' => 'ping', 'data' => $payload];
                
            case self::OPCODE_PONG:
                return ['type' => 'pong', 'data' => $payload];
                
            case self::OPCODE_TEXT:
            case self::OPCODE_BINARY:
            default:
                return $payload;
        }
    }
    
    /**
     * @inheritDoc
     */
    public static function encode(mixed $data, TcpConnection $connection): string
    {
        // 握手阶段直接返回
        if (!isset($connection->websocketHandshake)) {
            return is_string($data) ? $data : '';
        }
        
        // 编码为 WebSocket 帧
        $opcode = is_string($data) ? self::OPCODE_TEXT : self::OPCODE_BINARY;
        $data = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        
        return static::encodeFrame($data, $opcode);
    }
    
    /**
     * 编码帧
     */
    protected static function encodeFrame(string $data, int $opcode): string
    {
        $len = strlen($data);
        
        // FIN + opcode
        $frame = chr(0x80 | $opcode);
        
        // 长度
        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126) . pack('n', $len);
        } else {
            $frame .= chr(127) . pack('J', $len);
        }
        
        // 负载
        $frame .= $data;
        
        return $frame;
    }
    
    /**
     * 发送 Ping
     */
    public static function sendPing(TcpConnection $connection, string $data = ''): bool
    {
        return $connection->send(static::encodeFrame($data, self::OPCODE_PING), true);
    }
    
    /**
     * 发送 Close
     */
    public static function sendClose(TcpConnection $connection, int $code = 1000, string $reason = ''): bool
    {
        $data = pack('n', $code) . $reason;
        return $connection->send(static::encodeFrame($data, self::OPCODE_CLOSE), true);
    }
}
