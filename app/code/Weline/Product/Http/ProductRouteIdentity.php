<?php

declare(strict_types=1);

namespace Weline\Product\Http;

use Weline\Framework\Context;
use Weline\Framework\Http\Request;

/**
 * Product 前台路由写入的身份字段（path → Context input.query）与 Request::getParam 对齐。
 *
 * Module Router 只写 Context（见 Product\Controller\Router）；控制器必须通过本 helper
 * 读取 slug/id，禁止在控制器里 regex REQUEST_URI 兜底。
 *
 * Marker: product-route-identity-v1
 */
final class ProductRouteIdentity
{
    public static function slug(Request $request): string
    {
        $slug = strtolower(trim((string)$request->getParam('slug', '')));
        if ($slug !== '') {
            return $slug;
        }

        try {
            return strtolower(trim((string)(Context::current()->query('slug') ?? '')));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function productId(Request $request): int
    {
        $productId = (int)$request->getParam('id', 0);
        if ($productId > 0) {
            return $productId;
        }

        try {
            return max(0, (int)(Context::current()->query('id') ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }
}
