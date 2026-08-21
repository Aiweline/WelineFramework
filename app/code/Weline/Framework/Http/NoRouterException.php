<?php
declare(strict_types=1);

/**
 * Weline Framework - 无路由异常
 *
 * 无路由错误通过抛出此异常来实现，而不是调用 exit()。
 * Runtime 层会捕获此异常并转换为 HTTP 状态响应。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

/**
 * 无路由 / 状态回执异常。
 *
 * Response::noRouter() 会抛出此异常，由 Runtime 层统一处理。
 */
class NoRouterException extends ResponseTerminateException
{
    private string $errorMessage;

    public function __construct(int $code = 404, string $msg = '')
    {
        if ($msg === '') {
            $msg = ErrorPageRenderer::defaultMessage($code);
        }

        $this->errorMessage = $msg;
        $body = ErrorPageRenderer::render($code, $msg);
        $headers = ['Content-Type' => self::detectContentType($body)];

        parent::__construct($code, $body, $headers);
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    private static function detectContentType(string $body): string
    {
        $trim = \ltrim($body);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            return 'application/json; charset=utf-8';
        }

        return 'text/html; charset=utf-8';
    }
}
