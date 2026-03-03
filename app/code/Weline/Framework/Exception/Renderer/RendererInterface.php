<?php

declare(strict_types=1);

/**
 * Weline Framework 异常渲染器接口
 */

namespace Weline\Framework\Exception\Renderer;

interface RendererInterface
{
    /**
     * 渲染异常为字符串输出
     *
     * @param \Throwable $exception 异常对象
     * @return string 渲染后的输出
     */
    public function render(\Throwable $exception): string;

    /**
     * 获取内容类型
     *
     * @return string Content-Type 头值
     */
    public function getContentType(): string;
}
