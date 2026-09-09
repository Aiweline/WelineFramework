<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

final class StorefrontRouteIdentityNoUrlReparseContractTest extends TestCase
{
    public function testOfferResolverReadsContextNotUrl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/StorefrontOfferResolver.php',
        );

        self::assertStringContainsString("Context::current()", $source);
        self::assertStringContainsString("query('slug')", $source);
        self::assertStringContainsString("query('id')", $source);
        self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
        self::assertStringNotContainsString('theme_public_route', $source);
        self::assertStringNotContainsString('REQUEST_URI', $source);
        self::assertStringNotContainsString('parse_url', $source);
    }

    public function testVariantResolverReadsContextNotUrl(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/StorefrontVariantContextResolver.php',
        );

        self::assertStringContainsString("Context::current()", $source);
        self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
        self::assertStringNotContainsString('theme_public_route', $source);
        self::assertStringNotContainsString('preg_match', $source);
    }

    public function testDetailCarriesResolvedIdentityIntoRequestContext(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Frontend/Detail.php',
        );

        self::assertStringContainsString('carryResolvedIdentity', $source);
        self::assertStringContainsString("set('input.query.id'", $source);
        self::assertStringContainsString("set('input.query.slug'", $source);
        self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
    }
}
