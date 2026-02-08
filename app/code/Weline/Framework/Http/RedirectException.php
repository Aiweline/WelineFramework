<?php
declare(strict_types=1);

/**
 * Weline Framework - 重定向异常
 * 
 * 重定向通过抛出此异常来实现，而不是调用 exit()。
 * Runtime 层会捕获此异常并转换为 HTTP 重定向响应。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 重定向异常
 * 
 * 继承自 ResponseTerminateException，表示需要进行 HTTP 重定向。
 * Response::redirect() 会抛出此异常，由 Runtime 层统一处理。
 */
class RedirectException extends ResponseTerminateException
{
    /**
     * 重定向 URL
     */
    private string $redirectUrl;
    
    /**
     * 构造函数
     * 
     * @param string $url 重定向 URL
     * @param int $code HTTP 状态码（默认 302）
     */
    public function __construct(string $url, int $code = 302)
    {
        // 设置 Location header
        $headers = ['Location' => $url];
        parent::__construct($code, '', $headers);
        $this->redirectUrl = $url;
    }
    
    /**
     * 获取重定向 URL
     */
    public function getRedirectUrl(): string
    {
        return $this->redirectUrl;
    }
}
