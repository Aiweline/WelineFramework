<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller;

use Weline\Framework\Context;
use Weline\Framework\Router\RouterInterface;

final class Router implements RouterInterface
{
    private const PUBLIC_ROUTE_PREFIX = 'promotion';
    private const FIXED_ALIASES = [
        'promotion' => 'promotion',
    ];

    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $normalizedPath = self::normalizePublicPath($path);
        if (!str_starts_with($normalizedPath, self::PUBLIC_ROUTE_PREFIX)) {
            return;
        }

        if (isset(self::FIXED_ALIASES[$normalizedPath])) {
            $path = self::FIXED_ALIASES[$normalizedPath];
            $rule['module'] = 'Weline_Promotion';

            return;
        }

        if (!preg_match('#^promotion/([a-z0-9_-]+)$#', $normalizedPath, $matches)) {
            return;
        }

        $slug = strtolower((string)$matches[1]);
        if ($slug === 'index') {
            $path = 'promotion';
            $rule['module'] = 'Weline_Promotion';

            return;
        }

        Context::current()->set('input.query.page_slug', $slug);
        $path = 'promotion/page';
        $rule['module'] = 'Weline_Promotion';
    }

    private static function normalizePublicPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '://')) {
            $path = (string)(parse_url($path, PHP_URL_PATH) ?: '');
        }
        if (str_contains($path, '?')) {
            $path = explode('?', $path, 2)[0];
        }

        return strtolower(trim($path, '/'));
    }
}
