<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Minify;

use Weline\Theme\Minify\Css\CssMin;
use Weline\Theme\Minify\Js\JsMin;

/**
 * Theme-owned static asset minifier for deploy-time transforms.
 */
final class StaticAssetMinifier
{
    /**
     * @return bool Whether the path should be considered for minify.
     */
    public function shouldMinify(string $path): bool
    {
        $basename = strtolower(basename($path));
        if (preg_match('/\.min\.(css|js|mjs)$/', $basename) === 1) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['css', 'js', 'mjs'], true);
    }

    /**
     * Minify file content by extension. On failure returns original content.
     */
    public function minifyFileContent(string $content, string $pathOrExtension): string
    {
        if ($content === '') {
            return '';
        }

        $ext = str_contains($pathOrExtension, '.')
            ? strtolower(pathinfo($pathOrExtension, PATHINFO_EXTENSION))
            : strtolower($pathOrExtension);

        try {
            return match ($ext) {
                'css' => CssMin::minify($content),
                'js', 'mjs' => JsMin::minify($content),
                default => $content,
            };
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning('[Theme Minify] failed: ' . $e->getMessage());
            }

            return $content;
        }
    }
}
