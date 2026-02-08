<?php
declare(strict_types=1);

/**
 * Weline Framework - Header 收集器接口
 * 
 * 统一管理 HTTP 响应头，避免在应用层直接调用 header() 函数。
 * 响应头由 Runtime 层在适当的时机发送。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * Header 收集器接口
 * 
 * 实现类负责：
 * - 收集响应头（不立即发送）
 * - 在 Runtime 层决定何时发送
 * - 支持 FPM 和 WLS 双模式
 */
interface HeaderCollectorInterface
{
    /**
     * 设置响应头
     * 
     * @param string $name 头名称
     * @param string $value 头值
     * @param bool $replace 是否替换现有同名头，默认 true
     * @return static
     */
    public function setHeader(string $name, string $value, bool $replace = true): static;
    
    /**
     * 批量设置响应头
     * 
     * @param array $headers ['Name' => 'Value', ...]
     * @param bool $replace 是否替换现有同名头
     * @return static
     */
    public function setHeaders(array $headers, bool $replace = true): static;
    
    /**
     * 获取所有已收集的响应头
     * 
     * @return array ['Name' => 'Value', ...] 或 ['Name' => ['Value1', 'Value2'], ...]
     */
    public function getHeaders(): array;
    
    /**
     * 获取指定响应头
     * 
     * @param string $name 头名称
     * @return string|array|null 头值或 null（如果不存在）
     */
    public function getHeader(string $name): string|array|null;
    
    /**
     * 检查响应头是否存在
     * 
     * @param string $name 头名称
     * @return bool
     */
    public function hasHeader(string $name): bool;
    
    /**
     * 移除响应头
     * 
     * @param string $name 头名称
     * @return static
     */
    public function removeHeader(string $name): static;
    
    /**
     * 清空所有响应头
     * 
     * @return static
     */
    public function clearHeaders(): static;
    
    /**
     * 设置 HTTP 状态码
     * 
     * @param int $code HTTP 状态码
     * @return static
     */
    public function setStatusCode(int $code): static;
    
    /**
     * 获取 HTTP 状态码
     * 
     * @return int
     */
    public function getStatusCode(): int;
    
    /**
     * 设置 Cookie（通过 Set-Cookie 头）
     * 
     * @param string $name Cookie 名称
     * @param string $value Cookie 值
     * @param int $expire 过期时间（Unix 时间戳）
     * @param string $path 路径
     * @param string $domain 域名
     * @param bool $secure 是否仅 HTTPS
     * @param bool $httpOnly 是否 HttpOnly
     * @param string $sameSite SameSite 属性
     * @return static
     */
    public function setCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static;
}
