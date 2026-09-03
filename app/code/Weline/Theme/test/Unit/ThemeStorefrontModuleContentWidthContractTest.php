<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 模块 storefront（非 Theme layouts）页面壳也须与 .w-container 同公式，
 * 禁止 content-max-width 的 px/rem fallback 与硬编码 1180/1200/1400/1440 版心。
 */
final class ThemeStorefrontModuleContentWidthContractTest extends TestCase
{
    /** @var list<string> path substrings skipped (vendor/backends/auth/editors/tests) */
    private const SKIP_SUBSTRINGS = [
        '/BackendThemeUpzet/',
        '/ThemeFancy/',
        '/libs/',
        '/tinymce/',
        '/bootstrap',
        '/Backend/',
        '/backend/',
        '/Admin/',
        '/admin/',
        '/account-login',
        '/account-recovery',
        '/weline-customer-account-',
        '/theme-editor',
        '/weline-theme-editor',
        '/visual-editor',
        '/editor-mode',
        '/widget-config',
        '/view/tpl/',
        '/prototypes/',
        '/Test/',
        '/test/',
        '/e2e/',
        '/DeveloperWorkspace/',
        '/datatable-form',
        '/_auto-literals',
        '/variables/_spacing',
        '/Visitor/view/templates/analytics/test',
        '/Visitor/view/templates/test/',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATTERNS = [
        '--weline-layout-content-max-width,\s*1440px',
        '--weline-layout-content-max-width,\s*1400px',
        '--weline-layout-content-max-width,\s*90rem',
        '--weline-layout-content-max-width,\s*72rem',
        '--weline-layout-content-max-width,\s*75rem',
        '--weline-layout-content-max-width,\s*1040px',
        '--weline-layout-content-max-width,\s*var\(--layout-max-width',
        'width:\s*min\(\s*1180px',
        'width:\s*min\(\s*1200px',
        'max-width:\s*1200px\s*;',
        'width:\s*min\([^;]*calc\(100%\s*-\s*2rem\)',
        'width:\s*min\([^;]*calc\(100%\s*-\s*40px\)',
    ];

    public function testModuleStorefrontViewsDoNotReintroduceDivergentPageShells(): void
    {
        $codeRoot = dirname(__DIR__, 3);
        self::assertDirectoryExists($codeRoot);

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($codeRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = $file->getExtension();
            if ($ext !== 'css' && $ext !== 'phtml') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($path, '/view/')) {
                continue;
            }
            // Theme layouts already covered by ThemeFrontendLayoutsContentWidthContractTest
            if (str_contains($path, '/Theme/view/theme/frontend/layouts/')) {
                continue;
            }
            $skip = false;
            foreach (self::SKIP_SUBSTRINGS as $needle) {
                if (str_contains($path, $needle)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $contents = (string)file_get_contents($path);
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match('/' . $pattern . '/', $contents) !== 1) {
                    continue;
                }
                if ($pattern === 'max-width:\s*1200px\s*;' && !$this->hasNonMediaHardMaxWidth1200($contents)) {
                    continue;
                }
                $rel = substr($path, strlen($codeRoot) + 1);
                $violations[] = $rel . ' :: ' . $pattern;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($violations)),
            "Module storefront shells must use the shared .w-container formula.\n" . implode("\n", $violations)
        );
    }

    public function testKeyModuleShellsUseCanonicalFormula(): void
    {
        $codeRoot = dirname(__DIR__, 3);
        // 自有页面壳（非嵌在 Theme .w-container 内）
        $pageShells = [
            'Compare/view/statics/css/product-shopper-chrome.css',
            'Consent/view/statics/css/consent-banner.css',
            'Index/view/templates/Index.phtml',
            'Payment/view/templates/Frontend/checkout/return.phtml',
            'Ai/view/templates/Frontend/Center/index.phtml',
            'Product/view/statics/css/widgets/recommended-products.css',
        ];
        foreach ($pageShells as $rel) {
            $path = $codeRoot . '/' . $rel;
            self::assertFileExists($path, $rel);
            $contents = (string)file_get_contents($path);
            self::assertStringContainsString(
                'width: min(100%, var(--weline-layout-content-max-width));',
                $contents,
                $rel
            );
            self::assertDoesNotMatchRegularExpression(
                '/--weline-layout-content-max-width,\s*[^)]+\)/',
                $contents,
                $rel
            );
        }

        // 嵌在 Theme default .w-container 内：只填满，禁止再声明版心
        foreach ([
            'Currency/view/statics/css/currency-guide.css',
            'Marketing/view/statics/css/campaign-hub.css',
        ] as $rel) {
            $path = $codeRoot . '/' . $rel;
            self::assertFileExists($path, $rel);
            $contents = (string)file_get_contents($path);
            self::assertStringContainsString('width: 100%;', $contents, $rel);
            self::assertStringContainsString('max-width: 100%;', $contents, $rel);
            self::assertDoesNotMatchRegularExpression(
                '/--weline-layout-content-max-width/',
                $contents,
                $rel
            );
            self::assertStringContainsString('禁止再套版心', $contents, $rel);
        }
    }

    private function hasNonMediaHardMaxWidth1200(string $contents): bool
    {
        $offset = 0;
        while (preg_match('/max-width:\s*1200px\s*;/', $contents, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $pos = (int)$m[0][1];
            $before = substr($contents, max(0, $pos - 120), 120);
            if (!preg_match('/@media[^{]*$/', $before)) {
                return true;
            }
            $offset = $pos + strlen($m[0][0]);
        }

        return false;
    }
}
