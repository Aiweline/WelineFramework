<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Controller;

use Weline\Framework\Router\RouterInterface;

/**
 * Blog 前台友好 URL 路由重写
 *
 * 支持：
 * - /blog -> /blog/frontend/index/index
 * - /blog/{slug} -> /blog/frontend/post/view?slug={slug}
 * - /blog/category/{slug} -> /blog/frontend/category/view?slug={slug}
 */
class Router implements RouterInterface
{
    public static function process(string &$path, array &$rule): void
    {
        if (!empty($rule['module'])) {
            return;
        }

        $raw = trim(rawurldecode($path), '/');
        if ($raw === '') {
            return;
        }

        // 仅处理 blog 前缀
        if (!str_starts_with(strtolower($raw), 'blog')) {
            return;
        }

        $segments = explode('/', $raw);
        if (($segments[0] ?? '') !== 'blog') {
            return;
        }

        // 保留模块原生显式路由，不做友好 URL 重写
        $second = strtolower((string)($segments[1] ?? ''));
        if (in_array($second, ['frontend', 'backend', 'api'], true)) {
            return;
        }

        // /blog
        if (count($segments) === 1) {
            $path = '/blog/frontend/index/index';
            $rule['module'] = 'GuoLaiRen_Blog';
            return;
        }

        // /blog/category/{slug}
        if (($segments[1] ?? '') === 'category') {
            $slug = trim((string)($segments[2] ?? ''));
            if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
                return;
            }
            $path = '/blog/frontend/category/view';
            $rule['module'] = 'GuoLaiRen_Blog';
            $rule['slug'] = $slug;
            $_GET['slug'] = $slug;
            return;
        }

        // /blog/{slug}
        $slug = trim((string)($segments[1] ?? ''));
        if ($slug === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            return;
        }
        $path = '/blog/frontend/post/view';
        $rule['module'] = 'GuoLaiRen_Blog';
        $rule['slug'] = $slug;
        $_GET['slug'] = $slug;
    }
}

