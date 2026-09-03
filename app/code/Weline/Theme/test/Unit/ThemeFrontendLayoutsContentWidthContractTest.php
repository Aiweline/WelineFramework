<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 前台 Theme layouts 页面壳必须与 foundation .w-container 同一版心公式，
 * 禁止 1440px/1400px/90rem/calc(100%-2rem)/私有 token 另起容器。
 */
final class ThemeFrontendLayoutsContentWidthContractTest extends TestCase
{
    /** @var list<string> */
    private const ALLOWLIST = [
        'blank/full.phtml',
        'cms_page/blank.phtml',
        'account/auth.phtml',
        'account/challenge.phtml',
        'account_auth/default.phtml',
        'account_logout/default.phtml',
        'homepage/minimal.phtml',
        'test/assets-test.phtml',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PATTERNS = [
        '--weline-layout-content-max-width,\s*1440px',
        '--weline-layout-content-max-width,\s*1400px',
        '--weline-layout-content-max-width,\s*90rem',
        '--weline-layout-content-max-width,\s*var\(--layout-max-width',
        'calc\(100%\s*-\s*2rem\)',
        'calc\(100%\s*-\s*\(var\(--weline-layout-content-padding-inline\)',
        '--token-size-1120px',
        '--token-size-1200px',
        '--size-content-1180',
        '--size-content-960',
    ];

    public function testFoundationDefinesCanonicalContainerClass(): void
    {
        $foundation = dirname(__DIR__, 2) . '/view/statics/ui/weline-foundation.css';
        self::assertFileExists($foundation);
        $css = (string)file_get_contents($foundation);
        self::assertStringContainsString('.w-container {', $css);
        self::assertStringContainsString(
            'width: min(100%, var(--weline-layout-content-max-width));',
            $css
        );
        self::assertStringContainsString(
            'padding-inline: var(--weline-layout-content-padding-inline);',
            $css
        );
    }

    public function testFrontendLayoutsDoNotReintroduceDivergentPageShells(): void
    {
        $layoutsRoot = dirname(__DIR__, 2) . '/view/theme/frontend/layouts';
        self::assertDirectoryExists($layoutsRoot);

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($layoutsRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'phtml') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($layoutsRoot) + 1));
            if (in_array($rel, self::ALLOWLIST, true)) {
                continue;
            }
            $contents = (string)file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match('/' . $pattern . '/', $contents) === 1) {
                    $violations[] = $rel . ' :: ' . $pattern;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Frontend layouts must use the shared .w-container formula.\n" . implode("\n", $violations)
        );
    }

    public function testSampleShellsUseCanonicalWidthFormula(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts';
        foreach ([
            'about/default.phtml',
            'contact/default.phtml',
            'blog/default.phtml',
            'product_list/default.phtml',
            'search/default.phtml',
            'category/default.phtml',
            'not_found/default.phtml',
        ] as $rel) {
            $contents = (string)file_get_contents($base . '/' . $rel);
            self::assertStringContainsString(
                'width: min(100%, var(--weline-layout-content-max-width));',
                $contents,
                $rel
            );
            self::assertStringContainsString(
                'var(--weline-layout-content-padding-inline)',
                $contents,
                $rel
            );
            self::assertStringNotContainsString('1440px', $contents, $rel);
            self::assertStringNotContainsString('1400px', $contents, $rel);
            self::assertStringNotContainsString('90rem', $contents, $rel);
        }
    }
}
