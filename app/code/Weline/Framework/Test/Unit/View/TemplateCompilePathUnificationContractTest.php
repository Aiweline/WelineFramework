<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\View\Template;
use Weline\Theme\Helper\LayoutPathResolver;

/**
 * All compile-path consumers must share resolveStableCompileRoot + plaintext leaves.
 * No parallel lang-only / *_ctx_* / __source_{24} / __bytes_* trees.
 */
final class TemplateCompilePathUnificationContractTest extends TestCase
{
    public function testFetchFileFromSourceUsesStableRootAndPlainSrcLeaf(): void
    {
        foreach (['area' => 'frontend', 'website_id' => 0, 'website_code' => 'default', 'user.lang' => 'zh_Hans_CN', 'user.currency' => 'CNY'] as $name => $value) {
            WelineEnv::set($name, $value, 'compile path unification fixture');
        }
        WelineEnv::set('website_url', '', 'compile path unification fixture');

        $template = Template::getInstance();
        $compiled = $template->getFetchFileFromSource(
            'unify-virtual.phtml',
            '<span>unify</span>',
            __FILE__,
            'pin-a'
        );

        $normalized = \str_replace('\\', '/', $compiled);
        self::assertFileExists($compiled);
        self::assertDoesNotMatchRegularExpression('#_ctx_[a-f0-9]{32}#i', $normalized);
        self::assertStringNotContainsString('__bytes_', $normalized);
        self::assertStringNotContainsString('__source_', $normalized);
        self::assertMatchesRegularExpression('#/src_unify-virtual_pin-a(?:_r[a-f0-9]{8})?/#', $normalized);
        self::assertMatchesRegularExpression('#/frontend_w0_default_zh_Hans_CN_CNY#', $normalized);

        $otherContext = $template->getFetchFileFromSource(
            'unify-virtual.phtml',
            '<span>unify-b</span>',
            __FILE__,
            'pin-b'
        );
        $otherNormalized = \str_replace('\\', '/', $otherContext);
        self::assertNotSame($compiled, $otherContext);
        self::assertMatchesRegularExpression('#/src_unify-virtual_pin-b(?:_r[a-f0-9]{8})?/#', $otherNormalized);
        self::assertMatchesRegularExpression('#/frontend_w0_default_zh_Hans_CN_CNY#', $otherNormalized);
        // Same stable scope root; only the plaintext src_ leaf differs.
        self::assertSame(\dirname(\dirname($normalized)), \dirname(\dirname($otherNormalized)));
    }

    public function testLayoutPathResolverSharesStableCompileRoot(): void
    {
        $path = LayoutPathResolver::getCompiledLayoutPath('Weline_Theme::layouts/homepage/default.phtml', 'en_US');
        if ($path === '') {
            self::markTestSkipped('Theme module / ObjectManager not ready for layout path resolution');
        }

        $normalized = \str_replace('\\', '/', $path);
        self::assertDoesNotMatchRegularExpression('#_ctx_[a-f0-9]{32}#i', $normalized);
        self::assertStringNotContainsString('__bytes_', $normalized);
        self::assertStringNotContainsString('__source_', $normalized);
        // Must include currency leaf from Template scope (not bare lang-only).
        self::assertMatchesRegularExpression(
            '#/(?:frontend|backend)_[^/]+_[A-Za-z]{2,}[^/]*_[A-Z]{3}[^/]*/#',
            $normalized
        );
        self::assertStringEndsWith('com_default.phtml', $path);
    }
}
