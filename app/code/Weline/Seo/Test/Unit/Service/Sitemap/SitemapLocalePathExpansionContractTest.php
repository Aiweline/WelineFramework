<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Sitemap;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Seo\Api\Sitemap\AbstractSitemapUrlProvider;
use Weline\Seo\Interface\SitemapUrlProviderInterface;

final class SitemapLocalePathExpansionContractTest extends TestCase
{
    public function testInterfaceAndAbstractDeclarePathExpansionHook(): void
    {
        self::assertTrue(method_exists(SitemapUrlProviderInterface::class, 'supportsSiteLanguagePathExpansion'));
        self::assertTrue(method_exists(AbstractSitemapUrlProvider::class, 'supportsSiteLanguagePathExpansion'));

        $method = new ReflectionMethod(AbstractSitemapUrlProvider::class, 'supportsSiteLanguagePathExpansion');
        self::assertFalse($method->isAbstract());
        $src = (string)file_get_contents((new ReflectionClass(AbstractSitemapUrlProvider::class))->getFileName());
        self::assertMatchesRegularExpression(
            '/function\s+supportsSiteLanguagePathExpansion\(\)\s*:\s*bool\s*\{\s*return\s+false\s*;/s',
            $src,
        );
    }
}
