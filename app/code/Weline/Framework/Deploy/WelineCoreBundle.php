<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 把 4 个前端启动依赖合并为一个核心包，减少 head 外链：
 * runtime.js + dom-observer.js + weline-api-business.js + url-frontend.js
 *
 * 发布时由 deploy:upgrade 生成到 pub/static（随后被版本目录硬链接复制），
 * 生产环境 header 只加载 /static/{buildId}/Weline/Frontend/js/weline-core.js。
 * DEV 仍加载原始 4 个文件，便于调试。
 */
final class WelineCoreBundle
{
    public const RELATIVE_PATH = 'Weline/Frontend/js/weline-core.js';

    /** 顺序即执行顺序。 */
    public const SOURCES = [
        'Weline/Frontend/js/runtime.js',
        'Weline/Framework/js/dom-observer.js',
        'Weline/Frontend/js/weline-api-business.js',
        'Weline/Framework/js/url-frontend.js',
    ];

    /**
     * @return array{path:?string,changed:bool}
     */
    public static function publish(string $staticRoot): array
    {
        $root = \rtrim($staticRoot, '/\\');
        if (!\is_dir($root)) {
            return ['path' => null, 'changed' => false];
        }

        $chunks = [];
        foreach (self::SOURCES as $relative) {
            $file = $root . '/' . $relative;
            if (!\is_file($file)) {
                continue;
            }
            $content = (string)@\file_get_contents($file);
            if ($content === '') {
                continue;
            }
            $chunks[] = '/* ==== ' . $relative . ' ==== */' . "\n" . $content;
        }
        if ($chunks === []) {
            return ['path' => null, 'changed' => false];
        }

        $bundle = \implode("\n;\n", $chunks) . "\n";
        $target = $root . '/' . self::RELATIVE_PATH;
        $existing = @\file_get_contents($target);
        if (\is_string($existing) && $existing === $bundle) {
            return ['path' => self::RELATIVE_PATH, 'changed' => false];
        }

        $temporary = $target . '.tmp-' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($temporary, $bundle) === false) {
            return ['path' => null, 'changed' => false];
        }
        if (!@\rename($temporary, $target)) {
            @\unlink($temporary);

            return ['path' => null, 'changed' => false];
        }

        return ['path' => self::RELATIVE_PATH, 'changed' => true];
    }
}
