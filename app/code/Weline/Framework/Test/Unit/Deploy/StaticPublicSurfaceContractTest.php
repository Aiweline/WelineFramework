<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticPublicSurface;
use Weline\Framework\Deploy\StaticPublishExclusion;

/**
 * 契约测试：把「静态公共面」口径钉死在唯一权威上，防止四处漂移。
 *
 * 背景（真实事故）：「什么能作为静态字节对外提供」曾散落在四处且强度不一——
 * WLS 传输层有正确的白名单、框架回退层完全没有策略、nginx 的 `^~` 会跳过
 * 所有 deny、发布侧另有一套排除规则。结果是传输层「不接管」被回退层当成
 * 「允许」，实测 `GET /static/.../view/statics/x.php` 在纯 WLS（不经 nginx）
 * 下返回 200 `text/x-php`，PHP 源码原文泄露。
 *
 * 本测试锁定四条不变式：
 *  1. 快路径白名单 ⊆ 服务白名单（优化不得扩大暴露面）
 *  2. 服务白名单 ∩ 脚本/模板扩展名 = ∅
 *  3. 发布侧排除的东西，服务侧一律不得提供
 *  4. nginx 样例与 WLS worker 的扩展名集合 == PHP 侧权威集合
 */
final class StaticPublicSurfaceContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        // .../app/code/Weline/Framework/Test/Unit/Deploy → 仓库根
        $this->repoRoot = dirname(__DIR__, 7);
    }

    // ---------------------------------------------------------------- 不变式 1

    public function testFastPathExtensionsAreASubsetOfServableExtensions(): void
    {
        $fast = StaticPublicSurface::FAST_PATH_EXTENSIONS;
        $servable = StaticPublicSurface::SERVABLE_EXTENSIONS;

        $outside = array_values(array_diff($fast, $servable));
        self::assertSame([], $outside, '快路径只可收窄，不得扩大暴露面');
    }

    // ---------------------------------------------------------------- 不变式 2

    /**
     * 服务端可执行 / 模板源码扩展名绝不得出现在服务白名单里。
     */
    public function testServableExtensionsContainNoScriptExtension(): void
    {
        $scripts = [
            'php', 'phtml', 'pht', 'phps', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
            'asp', 'aspx', 'jsp', 'jspx', 'shtml',
        ];

        $collisions = array_values(array_intersect($scripts, StaticPublicSurface::SERVABLE_EXTENSIONS));
        self::assertSame([], $collisions, '脚本/模板扩展名不得进入服务白名单');
    }

    // ---------------------------------------------------------------- 不变式 3

    /**
     * 「发布不了的东西，绝不可能是给人下载的东西」。
     */
    public function testPublishExcludedPathsAreNeverServable(): void
    {
        $samples = [
            'php/connector.minimal.php',
            'php/elFinder.class.php',
            'php/connector.minimal.php-dist',
            'frontend/layouts/homepage/default.phtml',
            'register.php',
            'libs/x/docs/index.html',
            'libs/x/node_modules/a/index.js',
            'libs/x/package.json',
            'libs/x/package-lock.json',
            'libs/x/.gitignore',
            'libs/x/.eslintrc.json',
            'libs/x/README.md',
            'libs/x/LICENSE',
            'test/content-slot-preservation.php',
            'tools/build.py',
            'scripts/deploy.sh',
        ];

        foreach ($samples as $relative) {
            self::assertTrue(
                StaticPublishExclusion::isExcluded($relative),
                $relative . ' 应被发布侧排除（前提断言）'
            );
            self::assertFalse(
                StaticPublicSurface::isServableUrlPath('static/' . $relative),
                $relative . ' 不得作为静态资源对外提供'
            );
        }
    }

    /**
     * 运行时资源必须仍然可达（防止口径过紧误伤）。
     */
    public function testRuntimeAssetsRemainServable(): void
    {
        $samples = [
            'static/Weline/x/view/statics/css/app.css',
            'static/Weline/x/view/statics/js/app.js',
            'static/Weline/x/view/statics/js/app.mjs',
            'static/Weline/x/view/statics/js/bundle.js.map',
            'static/Weline/x/view/statics/fonts/icon.woff2',
            'static/Weline/x/view/statics/images/hero.webp',
            'static/Weline/x/view/statics/libs/a.min.js',
            's/20260914/Weline/x/view/statics/css/app.css',
            'media/catalog/a/b.jpg',
            'errors/404.html',
            '.well-known/acme-challenge/token',
        ];

        foreach ($samples as $path) {
            self::assertTrue(
                StaticPublicSurface::isServableUrlPath($path),
                $path . ' 是运行时资源，必须可达'
            );
        }
    }

    /**
     * 目录穿越与公共根之外的路径一律拒绝（默认拒绝）。
     */
    public function testAnythingOutsideThePublicSurfaceIsDenied(): void
    {
        $denied = [
            '',
            'app/etc/env.php',
            'vendor/composer/autoload_real.php',
            'static/../app/etc/env.php',
            'static//../app/etc/env.php',
            's/20260914/../app/etc/env.php',
            'some/unknown/root/a.css',
            'static/',
            'static',
        ];

        foreach ($denied as $path) {
            self::assertFalse(
                StaticPublicSurface::isServableUrlPath($path),
                '路径 ' . ($path === '' ? '(空)' : $path) . ' 必须被拒绝'
            );
        }
    }

    // ---------------------------------------------------------------- 不变式 4

    /**
     * nginx 样例里的每一份白名单正则必须等于 PHP 侧生成的结果。
     */
    public function testNginxSampleAllowlistMatchesTheAuthoritativePattern(): void
    {
        $file = $this->repoRoot . '/nginx.static-surface.conf';
        self::assertFileExists($file);

        $patterns = $this->extractNginxAllowlistPatterns((string)file_get_contents($file));
        self::assertGreaterThanOrEqual(2, count($patterns), 'nginx 样例应含 /static/ 与 /s/ 两份白名单');

        foreach ($patterns as $pattern) {
            self::assertSame(
                StaticPublicSurface::servableExtensionPattern(),
                $pattern,
                'nginx 白名单与 PHP 侧权威集合不一致，请重新生成'
            );
        }
    }

    /**
     * WLS 两个 worker 的快路径白名单必须等于权威快路径集合（两份拷贝不得漂移）。
     */
    public function testWlsWorkerFastPathListsMatchTheAuthoritativeSet(): void
    {
        foreach (['worker.php', 'worker_ssl.php'] as $name) {
            $file = $this->repoRoot . '/app/code/Weline/Server/bin/' . $name;
            self::assertFileExists($file);

            $found = $this->extractWorkerStaticExtensions((string)file_get_contents($file));
            self::assertNotSame([], $found, $name . ' 未找到 $staticExtensions');

            self::assertSame(
                $this->sorted(StaticPublicSurface::FAST_PATH_EXTENSIONS),
                $this->sorted($found),
                $name . ' 的快路径白名单与权威集合不一致'
            );
        }
    }

    /**
     * 框架回退层必须真的执行口径（否则「传输层不接管」= 放行）。
     */
    public function testFrameworkFallbackActuallyEnforcesThePolicy(): void
    {
        $source = (string)file_get_contents($this->repoRoot . '/app/code/Weline/Framework/Router/Core.php');

        self::assertStringContainsString(
            'StaticPublicSurface::isServableFile',
            $source,
            'Router\Core::StaticFile() 必须执行静态公共面口径，否则纯 WLS 下会泄露源码'
        );
    }

    // ---------------------------------------------------------------- 辅助

    /**
     * 抽取 nginx 样例中**生效**（非注释）的 `if ($uri !~* "...")` 白名单正则。
     *
     * @return list<string>
     */
    private function extractNginxAllowlistPatterns(string $conf): array
    {
        $patterns = [];
        foreach (explode("\n", $conf) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/if\s*\(\s*\$uri\s*!~\*\s*"\\\\\.\(\?:(?P<ext>[^"]+)\)~\$?"\s*\)/i', $trimmed, $m) !== 1
                && preg_match('/if\s*\(\s*\$uri\s*!~\*\s*"\\\\\.\(\?:(?P<ext>[^"]+)\)\$/i', $trimmed, $m) !== 1
            ) {
                continue;
            }
            $patterns[] = $m['ext'];
        }

        return $patterns;
    }

    /**
     * 抽取 worker 里 `static $staticExtensions = [ ... ];` 的扩展名集合。
     *
     * @return list<string>
     */
    private function extractWorkerStaticExtensions(string $source): array
    {
        if (preg_match('/static\s+\$staticExtensions\s*=\s*\[(?P<body>.*?)\]\s*;/s', $source, $m) !== 1) {
            return [];
        }

        $extensions = [];
        if (preg_match_all("/'([a-z0-9]+)'/i", $m['body'], $matches) > 0) {
            $extensions = $matches[1];
        }

        return array_values(array_map('strval', $extensions));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values($values);
        sort($values);

        return $values;
    }
}
