<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名解析服务
 * 
 * 使用 PSL (Public Suffix List) 库进行根域解析
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Pdp\Rules;

/**
 * 域名解析服务
 * 
 * 提供统一的根域名解析功能，支持 co.uk、com.cn 等多级后缀
 */
class DomainParserService
{
    /**
     * PSL 规则实例缓存
     */
    protected static ?Rules $rules = null;
    
    /**
     * PSL 数据文件路径
     */
    protected string $pslPath;
    
    public function __construct()
    {
        $this->pslPath = \dirname(__DIR__, 4) . '/vendor/jeremykendall/php-domain-parser/resources/public_suffix_list.dat';
    }
    
    /**
     * 获取 PSL 规则实例
     */
    protected function getRules(): ?Rules
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        
        if (!\is_file($this->pslPath)) {
            return null;
        }
        
        try {
            self::$rules = Rules::fromPath($this->pslPath);
            return self::$rules;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * 解析根域名
     * 
     * @param string $domain 完整域名（如 www.example.co.uk）
     * @return string 根域名（如 example.co.uk）
     */
    public function parseRootDomain(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        
        // 本地/特殊域名直接返回
        if ($this->isLocalDomain($domain)) {
            return $domain;
        }
        
        // 使用 PSL 解析
        $rules = $this->getRules();
        if ($rules !== null) {
            try {
                $result = $rules->resolve($domain);
                $registrableDomain = $result->registrableDomain()->toString();
                
                if (!empty($registrableDomain)) {
                    return $registrableDomain;
                }
            } catch (\Throwable $e) {
                // 解析失败，使用回退逻辑
            }
        }
        
        // 回退到简单解析
        return $this->fallbackParseRootDomain($domain);
    }
    
    /**
     * 规范化域名（转小写、移除端口等）
     */
    public function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        
        // 移除端口号
        if (\strpos($domain, ':') !== false) {
            $domain = \explode(':', $domain)[0];
        }
        
        // 移除协议前缀
        if (\strpos($domain, '://') !== false) {
            $domain = \parse_url($domain, PHP_URL_HOST) ?: $domain;
        }
        
        return $domain;
    }
    
    /**
     * 检查是否为本地域名
     */
    public function isLocalDomain(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        
        $localDomains = [
            'localhost',
            '127.0.0.1',
            '::1',
        ];
        
        // 直接匹配
        if (\in_array($domain, $localDomains, true)) {
            return true;
        }
        
        // IP 地址
        if (\filter_var($domain, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        // .local 后缀
        if (\str_ends_with($domain, '.local')) {
            return true;
        }
        
        // .test 后缀（开发用）
        if (\str_ends_with($domain, '.test')) {
            return true;
        }
        
        // .localhost 后缀
        if (\str_ends_with($domain, '.localhost')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查域名是否解析到本地/私有地址
     */
    public function resolvesToLocal(string $domain): bool
    {
        if ($this->isLocalDomain($domain)) {
            return true;
        }
        
        try {
            $ip = \gethostbyname($domain);
            
            // 未解析成功时返回原域名
            if ($ip === $domain) {
                return true;
            }
            
            return $this->isPrivateIp($ip);
        } catch (\Throwable $e) {
            return true;
        }
    }
    
    /**
     * 检查是否为私有 IP
     */
    public function isPrivateIp(string $ip): bool
    {
        // 回环地址
        if (\str_starts_with($ip, '127.') || $ip === '::1') {
            return true;
        }
        
        // 私有 IP 段
        $privateRanges = [
            '10.',           // 10.0.0.0/8
            '172.16.',       // 172.16.0.0/12 (172.16-31.x.x)
            '172.17.',
            '172.18.',
            '172.19.',
            '172.20.',
            '172.21.',
            '172.22.',
            '172.23.',
            '172.24.',
            '172.25.',
            '172.26.',
            '172.27.',
            '172.28.',
            '172.29.',
            '172.30.',
            '172.31.',
            '192.168.',      // 192.168.0.0/16
            '169.254.',      // 链路本地
        ];
        
        foreach ($privateRanges as $range) {
            if (\str_starts_with($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 从子域名构造泛域名
     * 
     * @param string $domain 域名（如 www.example.com）
     * @return string 泛域名（如 *.example.com）
     */
    public function toWildcard(string $domain): string
    {
        $rootDomain = $this->parseRootDomain($domain);
        return '*.' . $rootDomain;
    }
    
    /**
     * 检查域名是否匹配泛域名
     * 
     * @param string $domain 待检查的域名（如 www.example.com）
     * @param string $wildcard 泛域名（如 *.example.com）
     * @return bool
     */
    public function matchesWildcard(string $domain, string $wildcard): bool
    {
        $domain = $this->normalizeDomain($domain);
        $wildcard = $this->normalizeDomain($wildcard);
        
        if (!\str_starts_with($wildcard, '*.')) {
            return $domain === $wildcard;
        }
        
        // 移除 *. 前缀
        $wildcardBase = \substr($wildcard, 2);
        
        // 精确匹配根域
        if ($domain === $wildcardBase) {
            return false; // 泛域名不覆盖根域本身
        }
        
        // 检查是否为子域名
        return \str_ends_with($domain, '.' . $wildcardBase);
    }
    
    /**
     * 简单的根域解析回退逻辑
     * 
     * 仅用于 PSL 库不可用时
     */
    protected function fallbackParseRootDomain(string $domain): string
    {
        // 常见的多级后缀
        $multiLevelSuffixes = [
            'co.uk', 'org.uk', 'gov.uk', 'ac.uk', 'me.uk',
            'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn',
            'com.au', 'net.au', 'org.au', 'edu.au',
            'co.jp', 'or.jp', 'ne.jp', 'ac.jp',
            'co.kr', 'or.kr', 'ne.kr',
            'com.br', 'net.br', 'org.br',
            'com.mx', 'org.mx', 'net.mx',
            'co.nz', 'org.nz', 'net.nz',
            'com.sg', 'org.sg', 'net.sg',
            'com.hk', 'org.hk', 'net.hk',
            'com.tw', 'org.tw', 'net.tw',
            'co.in', 'org.in', 'net.in',
            'com.ru', 'org.ru', 'net.ru',
            'co.za', 'org.za', 'net.za',
        ];
        
        foreach ($multiLevelSuffixes as $suffix) {
            if (\str_ends_with($domain, '.' . $suffix)) {
                $withoutSuffix = \substr($domain, 0, -\strlen('.' . $suffix));
                $parts = \explode('.', $withoutSuffix);
                return \array_pop($parts) . '.' . $suffix;
            }
        }
        
        // 默认取最后两段
        $parts = \explode('.', $domain);
        if (\count($parts) >= 2) {
            return $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1];
        }
        
        return $domain;
    }
    
    /**
     * 获取域名的所有层级
     * 
     * @param string $domain 域名（如 www.shop.example.com）
     * @return array 层级数组（如 ['www.shop.example.com', 'shop.example.com', 'example.com']）
     */
    public function getDomainLevels(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $rootDomain = $this->parseRootDomain($domain);
        
        $levels = [$domain];
        $parts = \explode('.', $domain);
        
        // 逐级移除子域名直到根域
        while (\count($parts) > 2) {
            \array_shift($parts);
            $current = \implode('.', $parts);
            $levels[] = $current;
            
            if ($current === $rootDomain) {
                break;
            }
        }
        
        return $levels;
    }
}
