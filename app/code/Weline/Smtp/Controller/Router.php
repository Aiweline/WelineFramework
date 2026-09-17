<?php

declare(strict_types=1);

namespace Weline\Smtp\Controller;

use Weline\Framework\Router\RouterInterface;

/**
 * 兼容误用模块名 frontName：weline_smtp → 权威路由 smtp。
 *
 * etc/env.php 已声明 router/backend_router=smtp；部分链接/猜测会写成 weline_smtp。
 * 只改写 path，不写 rule.module，交由 generated/routers 命中 smtp/...。
 */
final class Router implements RouterInterface
{
    private const LEGACY_FRONT = 'weline_smtp';
    private const CANONICAL_FRONT = 'smtp';

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($normalized === self::LEGACY_FRONT) {
            $path = self::CANONICAL_FRONT;

            return;
        }
        if (str_starts_with($normalized, self::LEGACY_FRONT . '/')) {
            $path = self::CANONICAL_FRONT . substr($normalized, strlen(self::LEGACY_FRONT));
        }
    }
}
