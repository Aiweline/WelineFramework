<?php

declare(strict_types=1);

namespace Weline\Order\Controller;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\RouterInterface;

/**
 * 公共 /orders/track 等路径降级到账户订单（Router 层 302，不依赖生成路由表）。
 */
final class Router implements RouterInterface
{
    private const TRACK_PATHS = [
        'orders/track',
        'order/track',
        'order/tracking',
    ];

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if (!in_array($normalizedPath, self::TRACK_PATHS, true)) {
            return;
        }

        $target = '/customer/account/index#orders';
        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            $built = rtrim((string) $url->getUrl('customer/account/index'), '/');
            if ($built !== '') {
                $target = $built . '#orders';
            }
        } catch (\Throwable) {
            try {
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);
                $base = rtrim((string) $request->getOriginBaseUrl(), '/');
                if ($base !== '') {
                    $target = $base . '/customer/account/index#orders';
                }
            } catch (\Throwable) {
                // keep relative fallback
            }
        }

        $rule['module'] = 'Weline_Order';
        throw new ResponseTerminateException(302, '', ['Location' => $target]);
    }
}
