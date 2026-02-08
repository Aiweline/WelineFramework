<?php
declare(strict_types=1);

/**
 * Weline Framework - 响应发射器接口
 * 
 * 负责在最终阶段发送 HTTP 响应。
 * 不同的运行模式（FPM/WLS）有不同的实现。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 响应发射器接口
 * 
 * 职责：
 * - 发送 HTTP 状态码
 * - 发送响应头
 * - 发送响应体
 * - 处理特殊响应（重定向、文件下载等）
 */
interface ResponseEmitterInterface
{
    /**
     * 发送响应
     * 
     * @param HeaderCollectorInterface $headerCollector 头收集器
     * @param string $body 响应体
     * @param bool $terminate 是否终止请求（FPM 模式下调用 exit）
     * @return void
     */
    public function emit(HeaderCollectorInterface $headerCollector, string $body, bool $terminate = true): void;
    
    /**
     * 发送重定向响应
     * 
     * @param string $url 重定向 URL
     * @param int $code HTTP 状态码（默认 302）
     * @param bool $terminate 是否终止请求
     * @return void
     */
    public function emitRedirect(string $url, int $code = 302, bool $terminate = true): void;
    
    /**
     * 发送文件下载响应
     * 
     * @param string $filePath 文件路径
     * @param string $fileName 下载文件名
     * @param bool $deleteAfter 下载后是否删除
     * @param bool $terminate 是否终止请求
     * @return void
     */
    public function emitDownload(string $filePath, string $fileName = '', bool $deleteAfter = false, bool $terminate = true): void;
    
    /**
     * 发送静态文件响应
     * 
     * @param string $filePath 文件路径
     * @param string $mimeType MIME 类型
     * @param array $cacheHeaders 缓存头
     * @param bool $terminate 是否终止请求
     * @return void
     */
    public function emitStaticFile(string $filePath, string $mimeType, array $cacheHeaders = [], bool $terminate = true): void;
    
    /**
     * 发送 304 Not Modified 响应
     * 
     * @param bool $terminate 是否终止请求
     * @return void
     */
    public function emitNotModified(bool $terminate = true): void;
    
    /**
     * 发送错误响应
     * 
     * @param int $code HTTP 状态码
     * @param string $message 错误消息
     * @param bool $terminate 是否终止请求
     * @return void
     */
    public function emitError(int $code, string $message = '', bool $terminate = true): void;
    
    /**
     * 将响应转换为 HTTP 字符串（用于 WLS 模式）
     * 
     * @param HeaderCollectorInterface $headerCollector 头收集器
     * @param string $body 响应体
     * @return string HTTP 响应字符串
     */
    public function toHttpString(HeaderCollectorInterface $headerCollector, string $body): string;
}
