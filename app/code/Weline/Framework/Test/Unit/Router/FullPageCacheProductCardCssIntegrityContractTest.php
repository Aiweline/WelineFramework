<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Source-level contract: FPC must refuse/invalidate storefront HTML that has
 * product cards but lacks the inline product-card CSS marker.
 */
final class FullPageCacheProductCardCssIntegrityContractTest extends TestCase
{
    public function testCoordinatorGuardsProductCardCssOnPublishAndHit(): void
    {
        $src = (string)file_get_contents(
            (new \ReflectionClass(\Weline\Framework\Router\FullPageCacheCoordinator::class))->getFileName()
        );

        self::assertStringContainsString('function storefrontProductCardCssIntegrityOk', $src);
        self::assertStringContainsString('data-testid="weline-product-card"', $src);
        self::assertStringContainsString('data-weline-product-card-css', $src);
        self::assertStringContainsString('skip publish missing product-card css', $src);
        self::assertStringContainsString('invalidate hit missing product-card css', $src);
        self::assertStringContainsString('invalidate stale hit missing product-card css', $src);
    }

    public function testIntegrityPredicateLogic(): void
    {
        $ok = static function (string $body): bool {
            $hasCard = \str_contains($body, 'data-testid="weline-product-card"')
                || \str_contains($body, "data-testid='weline-product-card'");
            if (!$hasCard) {
                return true;
            }

            return \str_contains($body, 'data-weline-product-card-css');
        };

        self::assertTrue($ok('<html><body>no cards</body></html>'));
        self::assertTrue($ok(
            '<style data-weline-product-card-css="1"></style>'
            . '<article data-testid="weline-product-card">ok</article>'
        ));
        self::assertFalse($ok('<article data-testid="weline-product-card">poison</article>'));
    }
}
