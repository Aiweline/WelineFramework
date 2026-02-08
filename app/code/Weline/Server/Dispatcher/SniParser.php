<?php
declare(strict_types=1);

/**
 * Weline Server - SNI 解析器
 *
 * 解析 TLS ClientHello 消息，提取 SNI (Server Name Indication)。
 * 用于 TCP 透传模式下的智能路由决策。
 *
 * TLS ClientHello 结构:
 * - TLS Record Header (5 bytes)
 *   - Content Type: 0x16 (Handshake)
 *   - Version: 0x0301 (TLS 1.0) 或更高
 *   - Length: 后续数据长度
 * - Handshake Protocol
 *   - Handshake Type: 0x01 (ClientHello)
 *   - Extensions
 *     - SNI Extension (type = 0x0000)
 *       - Server Name: "example.com" (明文)
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Dispatcher;

class SniParser
{
    /**
     * TLS Record 类型常量
     */
    private const TLS_CONTENT_TYPE_HANDSHAKE = 0x16;
    
    /**
     * TLS 握手类型常量
     */
    private const TLS_HANDSHAKE_TYPE_CLIENT_HELLO = 0x01;
    
    /**
     * SNI 扩展类型
     */
    private const TLS_EXTENSION_SNI = 0x0000;
    
    /**
     * SNI 名称类型（主机名）
     */
    private const SNI_NAME_TYPE_HOSTNAME = 0x00;
    
    /**
     * 最小 ClientHello 长度
     */
    private const MIN_CLIENT_HELLO_LENGTH = 43;
    
    /**
     * Peek 数据推荐大小
     */
    public const RECOMMENDED_PEEK_SIZE = 512;
    
    /**
     * 从原始数据中提取 SNI
     *
     * @param string $data 原始 TCP 数据（通过 MSG_PEEK 获取）
     * @return string|null SNI 主机名，未找到返回 null
     */
    public static function extractSNI(string $data): ?string
    {
        $length = \strlen($data);
        
        // 最小 ClientHello 长度检查
        if ($length < self::MIN_CLIENT_HELLO_LENGTH) {
            return null;
        }
        
        // 检查 TLS Record Header
        // Content Type 必须是 Handshake (0x16)
        if (\ord($data[0]) !== self::TLS_CONTENT_TYPE_HANDSHAKE) {
            return null;
        }
        
        // 跳过 TLS 版本（2 bytes）和记录长度（2 bytes）
        // 检查 Handshake Type
        if (\ord($data[5]) !== self::TLS_HANDSHAKE_TYPE_CLIENT_HELLO) {
            return null;
        }
        
        // 解析 ClientHello 结构
        // 偏移量：5 (Record Header) + 1 (Handshake Type) + 3 (Handshake Length)
        //         + 2 (Client Version) + 32 (Random) = 43
        $pos = 43;
        
        // 跳过 Session ID
        if ($pos >= $length) {
            return null;
        }
        $sessionIdLength = \ord($data[$pos]);
        $pos += 1 + $sessionIdLength;
        
        // 跳过 Cipher Suites
        if ($pos + 2 > $length) {
            return null;
        }
        $cipherSuitesLength = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
        $pos += 2 + $cipherSuitesLength;
        
        // 跳过 Compression Methods
        if ($pos >= $length) {
            return null;
        }
        $compressionMethodsLength = \ord($data[$pos]);
        $pos += 1 + $compressionMethodsLength;
        
        // 读取 Extensions Length
        if ($pos + 2 > $length) {
            return null;
        }
        $extensionsLength = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
        $pos += 2;
        
        // 遍历 Extensions，查找 SNI
        $extensionsEnd = $pos + $extensionsLength;
        
        while ($pos + 4 <= $extensionsEnd && $pos + 4 <= $length) {
            // Extension Type (2 bytes)
            $extType = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
            // Extension Length (2 bytes)
            $extLength = (\ord($data[$pos + 2]) << 8) | \ord($data[$pos + 3]);
            $pos += 4;
            
            // 检查是否是 SNI Extension
            if ($extType === self::TLS_EXTENSION_SNI) {
                return self::parseSniExtension($data, $pos, $extLength, $length);
            }
            
            // 跳过当前扩展
            $pos += $extLength;
        }
        
        return null;
    }
    
    /**
     * 解析 SNI 扩展内容
     *
     * @param string $data 原始数据
     * @param int $pos 当前位置
     * @param int $extLength 扩展长度
     * @param int $dataLength 数据总长度
     * @return string|null SNI 主机名
     */
    private static function parseSniExtension(string $data, int $pos, int $extLength, int $dataLength): ?string
    {
        // SNI List Length (2 bytes)
        if ($pos + 2 > $dataLength) {
            return null;
        }
        $sniListLength = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
        $pos += 2;
        
        // 遍历 SNI 列表
        $sniListEnd = $pos + $sniListLength;
        
        while ($pos < $sniListEnd && $pos < $dataLength) {
            // Name Type (1 byte)
            if ($pos >= $dataLength) {
                return null;
            }
            $nameType = \ord($data[$pos]);
            $pos += 1;
            
            // Name Length (2 bytes)
            if ($pos + 2 > $dataLength) {
                return null;
            }
            $nameLength = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
            $pos += 2;
            
            // 只处理 hostname 类型
            if ($nameType === self::SNI_NAME_TYPE_HOSTNAME) {
                if ($pos + $nameLength > $dataLength) {
                    return null;
                }
                $hostname = \substr($data, $pos, $nameLength);
                // 验证主机名有效性
                if (self::isValidHostname($hostname)) {
                    return \strtolower($hostname);
                }
            }
            
            $pos += $nameLength;
        }
        
        return null;
    }
    
    /**
     * 验证主机名有效性
     *
     * @param string $hostname 主机名
     * @return bool 是否有效
     */
    private static function isValidHostname(string $hostname): bool
    {
        // 空主机名无效
        if (empty($hostname)) {
            return false;
        }
        
        // 长度限制（RFC 1035）
        if (\strlen($hostname) > 253) {
            return false;
        }
        
        // 只允许有效的域名字符
        // 允许字母、数字、连字符、点号
        if (!\preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $hostname)) {
            return false;
        }
        
        // 不允许连续的点号
        if (\strpos($hostname, '..') !== false) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查数据是否是 TLS ClientHello
     *
     * @param string $data 原始数据
     * @return bool 是否是 ClientHello
     */
    public static function isClientHello(string $data): bool
    {
        if (\strlen($data) < 6) {
            return false;
        }
        
        // 检查 TLS Record Header
        if (\ord($data[0]) !== self::TLS_CONTENT_TYPE_HANDSHAKE) {
            return false;
        }
        
        // 检查 Handshake Type
        if (\ord($data[5]) !== self::TLS_HANDSHAKE_TYPE_CLIENT_HELLO) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取预期的 ClientHello 长度
     *
     * @param string $data 原始数据（至少 5 bytes）
     * @return int 预期长度，无效返回 0
     */
    public static function getExpectedLength(string $data): int
    {
        if (\strlen($data) < 5) {
            return 0;
        }
        
        // TLS Record Length (bytes 3-4)
        $recordLength = (\ord($data[3]) << 8) | \ord($data[4]);
        
        // 总长度 = Record Header (5) + Record Body
        return 5 + $recordLength;
    }
}
