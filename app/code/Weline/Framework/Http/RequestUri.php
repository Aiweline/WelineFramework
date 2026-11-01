<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Context;

/**
 * 当前请求 URI 的唯一读取口。
 *
 * 顺序：当前请求 Context 的 `input.uri` → `$_SERVER['REQUEST_URI']`。
 * 调用方（诊断、模板看门狗、安全头、探针）不要再直接摸超全局：
 * 那些点会在 WLS 复用进程里读到别的 Fiber 留下的值。
 *
 * 本类只做读取，不负责解析（路径请读 Context::path() / Request::getBaseUrl()）。
 */
final class RequestUri
{
    public static function current(): string
    {
        $context = Context::getCurrent();
        if ($context !== null && $context->has('input.uri')) {
            return $context->uri();
        }

        return (string)($_SERVER['REQUEST_URI'] ?? '');
    }

    public static function path(): string
    {
        $uri = self::current();
        if ($uri === '') {
            return '';
        }

        return (string)(\parse_url($uri, \PHP_URL_PATH) ?: '');
    }
}
