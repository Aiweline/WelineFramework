<?php

declare(strict_types=1);

/**
 * Weline Framework 异常渲染器工厂
 * 
 * 根据请求类型自动选择合适的渲染器
 */

namespace Weline\Framework\Exception\Renderer;

use Weline\Framework\Exception\ExceptionBootstrap;

class RendererFactory
{
    /**
     * 创建渲染器
     *
     * @param string|null $area 区域（api, cli, frontend, backend）
     * @return RendererInterface
     */
    public static function create(?string $area = null): RendererInterface
    {
        $area = $area ?? ExceptionBootstrap::getArea();

        return match ($area) {
            'api' => new JsonRenderer(),
            'cli' => new CliRenderer(),
            default => new HtmlRenderer(),
        };
    }

    /**
     * 创建 JSON 渲染器
     */
    public static function createJson(): JsonRenderer
    {
        return new JsonRenderer();
    }

    /**
     * 创建 HTML 渲染器
     */
    public static function createHtml(): HtmlRenderer
    {
        return new HtmlRenderer();
    }

    /**
     * 创建 CLI 渲染器
     */
    public static function createCli(): CliRenderer
    {
        return new CliRenderer();
    }
}
