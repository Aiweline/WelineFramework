<?php

declare(strict_types=1);

/**
 * Weline Framework JSON 异常渲染器
 * 
 * 用于 API 请求，返回 JSON 格式的错误响应
 * 支持多语言的 title、message 等字段，前端可直接显示友好提示
 */

namespace Weline\Framework\Exception\Renderer;

use Weline\Framework\Exception\ErrorResponse;
use Weline\Framework\Exception\ExceptionBootstrap;

class JsonRenderer implements RendererInterface
{
    /**
     * 渲染异常为 JSON
     * 
     * 返回格式：
     * {
     *   "error": true,
     *   "success": false,
     *   "code": 500,
     *   "title": "服务器错误",      // 已翻译
     *   "message": "服务器内部错误", // 已翻译
     *   "icon": "⚠️",
     *   "retry_after": 15,          // 可选，自动重试秒数
     *   "debug": { ... }            // 仅开发模式
     * }
     */
    public function render(\Throwable $exception): string
    {
        $isDevMode = ExceptionBootstrap::isDevMode();
        $response = ErrorResponse::fromException($exception, $isDevMode);
        
        return json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * 获取内容类型
     */
    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }
}
