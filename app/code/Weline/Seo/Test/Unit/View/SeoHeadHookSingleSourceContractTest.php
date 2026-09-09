<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class SeoHeadHookSingleSourceContractTest extends TestCase
{
    public function testDefaultSeoHeadHookDoesNotEmitCoreSeoTaglib(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/seo/head.phtml';
        self::assertFileExists($path);

        $contents = (string)file_get_contents($path);
        $executable = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
        $executable = preg_replace('#^\s*//.*$#m', '', $executable) ?? $executable;
        self::assertStringNotContainsString(
            '<w:seo',
            $executable,
            'seo::head must stay an extension point; core SEO is rendered by Frontend public/head.phtml'
        );
        self::assertStringContainsString('public/head.phtml', $contents);
    }

    public function testFrontendPublicHeadRemainsSingleSeoSource(): void
    {
        $path = dirname(__DIR__, 4) . '/Frontend/view/templates/public/head.phtml';
        self::assertFileExists($path);

        $contents = (string)file_get_contents($path);
        self::assertSame(
            1,
            substr_count($contents, '<w:seo slot="head"/>'),
            'Frontend public head must keep exactly one core SEO head slot'
        );
    }
}
