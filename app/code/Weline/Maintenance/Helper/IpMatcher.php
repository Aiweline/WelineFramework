<?php

declare(strict_types=1);

/*
 * IP匹配辅助类
 * 支持单个IP和CIDR格式的IP范围匹配
 * 处理代理服务器的X-Forwarded-For等头部
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Helper
 */

namespace Weline\Maintenance\Helper;

class IpMatcher
{
    /**
     * 获取客户端真实IP地址
     * 
     * @return string
     */
    public static function getClientIp(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // 处理多个IP的情况（代理链）
                if (str_contains($ip, ',')) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]); // 取第一个IP（最原始的客户端IP）
                } else {
                    $ip = trim($ip);
                }
                
                // 验证IP格式
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * 检查IP是否在指定的IP范围内
     * 支持单个IP和CIDR格式
     * 
     * @param string $ip 要检查的IP地址
     * @param string $range IP范围（单个IP或CIDR格式，如 192.168.1.0/24）
     * @return bool
     */
    public static function isIpInRange(string $ip, string $range): bool
    {
        // 标准化IP地址
        $ip = self::normalizeIp($ip);
        $range = trim($range);
        
        if (empty($ip) || empty($range)) {
            return false;
        }
        
        // 如果是单个IP，直接比较
        if (!str_contains($range, '/')) {
            return $ip === self::normalizeIp($range);
        }
        
        // 处理CIDR格式
        list($subnet, $mask) = explode('/', $range, 2);
        $mask = (int)$mask;
        $subnet = self::normalizeIp($subnet);
        
        if (empty($subnet) || $mask < 0) {
            return false;
        }
        
        // IPv4 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isIpv4InRange($ip, $subnet, $mask);
        }
        
        // IPv6 CIDR
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isIpv6InRange($ip, $subnet, $mask);
        }
        
        return false;
    }
    
    /**
     * 检查IPv4是否在CIDR范围内
     * 
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private static function isIpv4InRange(string $ip, string $subnet, int $mask): bool
    {
        if ($mask > 32 || $mask < 0) {
            return false;
        }
        
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        
        // 计算网络掩码
        $netmask = -1 << (32 - $mask);
        
        return ($ipLong & $netmask) === ($subnetLong & $netmask);
    }
    
    /**
     * 检查IPv6是否在CIDR范围内
     * 
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private static function isIpv6InRange(string $ip, string $subnet, int $mask): bool
    {
        if ($mask > 128 || $mask < 0) {
            return false;
        }
        
        // 将IPv6地址转换为二进制
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        
        // 计算需要比较的字节数
        $bytes = intval($mask / 8);
        $bits = $mask % 8;
        
        // 比较完整字节
        if ($bytes > 0) {
            if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }
        }
        
        // 比较部分字节
        if ($bits > 0 && $bytes < 16) {
            $ipByte = ord($ipBin[$bytes]);
            $subnetByte = ord($subnetBin[$bytes]);
            $maskByte = 0xFF << (8 - $bits);
            
            if (($ipByte & $maskByte) !== ($subnetByte & $maskByte)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 标准化IP地址
     * 移除多余空格和格式化为标准格式
     * 
     * @param string $ip
     * @return string
     */
    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        
        // 如果是IPv4，使用ip2long/long2ip确保格式一致
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false) {
                return long2ip($long);
            }
        }
        
        // IPv6保持原样或使用inet_ntop标准化
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return inet_ntop($packed);
            }
        }
        
        return $ip;
    }
    
    /**
     * 检查IP是否在IP白名单中
     * 
     * @param string $ip 要检查的IP地址
     * @param array $whitelist IP白名单数组（可包含单个IP和CIDR格式）
     * @return bool
     */
    public static function isIpInWhitelist(string $ip, array $whitelist): bool
    {
        if (empty($whitelist)) {
            return false;
        }
        
        foreach ($whitelist as $range) {
            if (empty($range)) {
                continue;
            }
            
            if (self::isIpInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 验证CIDR格式是否有效
     * 
     * @param string $cidr
     * @return bool
     */
    public static function isValidCidr(string $cidr): bool
    {
        $cidr = trim($cidr);
        
        // 单个IP
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }
        
        list($ip, $mask) = explode('/', $cidr, 2);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        $mask = (int)$mask;
        
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $mask >= 0 && $mask <= 32;
        }
        
        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $mask >= 0 && $mask <= 128;
        }
        
        return false;
    }
}
