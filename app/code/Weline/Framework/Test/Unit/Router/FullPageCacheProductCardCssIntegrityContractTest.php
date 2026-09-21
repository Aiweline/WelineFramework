<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Helper\HtmlCacheAdmission;

/**
 * Source-level contract: FPC / hook HTML stores share HtmlCacheAdmission.
 */
final class FullPageCacheProductCardCssIntegrityContractTest extends TestCase
{
    public function testCoordinatorUsesSharedHtmlCacheAdmission(): void
    {
        $src = (string)file_get_contents(
            (new \ReflectionClass(\Weline\Framework\Router\FullPageCacheCoordinator::class))->getFileName()
        );

        self::assertStringContainsString('HtmlCacheAdmission::admit', $src);
        self::assertStringContainsString('skip publish html cache admission rejected', $src);
        self::assertStringContainsString('invalidate hit html cache admission rejected', $src);
        self::assertStringContainsString('invalidate stale hit html cache admission rejected', $src);
        self::assertStringContainsString('healStorefrontProductCardCss', $src);
        self::assertStringNotContainsString('function storefrontProductCardCssIntegrityOk', $src);
        self::assertStringNotContainsString('function storefrontModuleTitleIntegrityOk', $src);
    }

    public function testTemplateHookStoresUseSharedAdmission(): void
    {
        $src = (string)file_get_contents(
            (new \ReflectionClass(\Weline\Framework\View\Template::class))->getFileName()
        );
        self::assertStringContainsString('HtmlCacheAdmission::admit', $src);
        self::assertStringNotContainsString('scrubModulePlaceholderTitlesHtml', $src);
    }

    public function testAdmissionPredicateLogic(): void
    {
        self::assertTrue(HtmlCacheAdmission::storefrontProductCardCssOk('<html><body>no cards</body></html>'));
        self::assertTrue(HtmlCacheAdmission::storefrontProductCardCssOk(
            '<style data-weline-product-card-css="1"></style>'
            . '<article data-testid="weline-product-card">ok</article>'
        ));
        self::assertFalse(HtmlCacheAdmission::storefrontProductCardCssOk(
            '<article data-testid="weline-product-card">poison</article>'
        ));
        self::assertFalse(HtmlCacheAdmission::admit('<h1>Weline_Theme</h1>'));

        $poison = '<html><body><article data-testid="weline-product-card">x</article></body></html>';
        $healed = HtmlCacheAdmission::healStorefrontProductCardCss($poison);
        self::assertStringContainsString('data-weline-product-card-css', $healed);
        self::assertTrue(HtmlCacheAdmission::admit($healed));
    }

    public function testCoordinatorHealsProductCardCssBeforeAdmission(): void
    {
        $src = (string)file_get_contents(
            (new \ReflectionClass(\Weline\Framework\Router\FullPageCacheCoordinator::class))->getFileName()
        );
        self::assertStringContainsString('healStorefrontProductCardCss', $src);
        self::assertStringContainsString('healed product-card css before publish', $src);
    }
}
