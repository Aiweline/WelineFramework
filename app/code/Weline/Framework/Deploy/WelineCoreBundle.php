<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 把 4 个前端启动依赖合并为一个核心包，减少 head 外链：
 * runtime.js + dom-observer.js + weline-api-business.js + url-frontend.js
 *
 * 发布时由 deploy:upgrade 生成到 pub/static（随后被版本目录硬链接复制），
 * 生产环境 header 优先加载 weline-core.min.js（无则回退 weline-core.js）。
 * DEV 仍加载原始 4 个文件，便于调试。
 */
final class WelineCoreBundle
{
    public const RELATIVE_PATH = 'Weline/Frontend/js/weline-core.js';

    public const RELATIVE_PATH_MIN = 'Weline/Frontend/js/weline-core.min.js';

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
        $prettyChanged = !(\is_string($existing) && $existing === $bundle);
        if ($prettyChanged) {
            $temporary = $target . '.tmp-' . \bin2hex(\random_bytes(4));
            if (@\file_put_contents($temporary, $bundle) === false) {
                return ['path' => null, 'changed' => false];
            }
            if (!@\rename($temporary, $target)) {
                @\unlink($temporary);

                return ['path' => null, 'changed' => false];
            }
        }

        $minChanged = self::publishMin($root, $bundle);

        return ['path' => self::RELATIVE_PATH, 'changed' => $prettyChanged || $minChanged];
    }

    /**
     * Write companion .min.js for production header preference.
     * Prefer node terser (handles template literals); fall back to Theme JsMin.
     */
    private static function publishMin(string $root, string $bundle): bool
    {
        $min = self::minifyJs($bundle);
        if ($min === '' || $min === $bundle) {
            // Keep an existing smaller min artifact rather than overwriting with pretty.
            $existingMin = @\file_get_contents($root . '/' . self::RELATIVE_PATH_MIN);
            if (\is_string($existingMin) && $existingMin !== '' && \strlen($existingMin) < \strlen($bundle)) {
                return false;
            }
            $min = $bundle;
        }

        $target = $root . '/' . self::RELATIVE_PATH_MIN;
        $existing = @\file_get_contents($target);
        if (\is_string($existing) && $existing === $min) {
            return false;
        }

        $temporary = $target . '.tmp-' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($temporary, $min) === false) {
            return false;
        }
        if (!@\rename($temporary, $target)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    private static function minifyJs(string $js): string
    {
        $terser = self::minifyWithTerser($js);
        if ($terser !== null && $terser !== '') {
            return $terser;
        }

        try {
            if (\class_exists(\Weline\Theme\Minify\Js\JsMin::class)) {
                return (string)\Weline\Theme\Minify\Js\JsMin::minify($js);
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return $js;
    }

    private static function minifyWithTerser(string $js): ?string
    {
        if (!\function_exists('proc_open') || !\function_exists('proc_close')) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Prefer local npx when available; otherwise skip silently.
        $cmd = 'npx --yes terser -c -m --module false';
        $proc = @\proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (!\is_resource($proc)) {
            return null;
        }

        \fwrite($pipes[0], $js);
        \fclose($pipes[0]);
        $stdout = (string)\stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);
        if ($code !== 0 || $stdout === '') {
            unset($stderr);

            return null;
        }

        return $stdout;
    }
}
