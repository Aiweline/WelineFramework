<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Service;

/**
 * IP白名单服务
 * 
 * 用于验证IP地址是否在白名单中
 */
class IpWhitelistService
{
    /**
     * 检查IP是否在白名单中
     * 
     * @param string $clientIp 客户端IP
     * @param array $allowedIps 允许的IP列表
     * @return bool 是否允许
     */
    public function isIpAllowed(string $clientIp, array $allowedIps): bool
    {
        if (empty($allowedIps)) {
            return true; // 如果白名单为空，允许所有IP
        }
        
        foreach ($allowedIps as $allowedIp) {
            $allowedIp = trim($allowedIp ?? '');
            if (empty($allowedIp)) {
                continue;
            }
            
            // 单个IP匹配
            if ($clientIp === $allowedIp) {
                return true;
            }
            
            // CIDR匹配
            if (strpos($allowedIp, '/') !== false) {
                if ($this->isIpInCidr($clientIp, $allowedIp)) {
                    return true;
                }
            }
            
            // IP范围匹配
            if (strpos($allowedIp, '-') !== false) {
                if ($this->isIpInRange($clientIp, $allowedIp)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否在CIDR范围内
     * 
     * @param string $ip IP地址
     * @param string $cidr CIDR格式（如：192.168.1.0/24）
     * @return bool 是否在范围内
     */
    private function isIpInCidr(string $ip, string $cidr): bool
    {
        try {
            list($subnet, $mask) = explode('/', $cidr);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            
            $maskLong = -1 << (32 - (int)$mask);
            
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 检查IP是否在范围内
     * 
     * @param string $ip IP地址
     * @param string $range IP范围（如：192.168.1.1-192.168.1.100）
     * @return bool 是否在范围内
     */
    private function isIpInRange(string $ip, string $range): bool
    {
        try {
            list($startIp, $endIp) = explode('-', $range);
            $ipLong = ip2long(trim($ip));
            $startLong = ip2long(trim($startIp));
            $endLong = ip2long(trim($endIp));
            
            if ($ipLong === false || $startLong === false || $endLong === false) {
                return false;
            }
            
            return $ipLong >= $startLong && $ipLong <= $endLong;
        } catch (\Exception $e) {
            return false;
        }
    }
}

