<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class TraitTemplateTagSourceAreaRouteContractTest extends TestCase
{
    public function testFetchTagSourceDisablesAreaRouteForAllTypes(): void
    {
        $path = \dirname(__DIR__, 3) . '/View/TraitTemplate.php';
        $src = \file_get_contents($path);
        self::assertIsString($src);

        self::assertStringContainsString("viewEnvironmentCacheSuffix('tag-source', [", $src);
        self::assertStringContainsString("viewEnvironmentCacheSuffix('tag-source-file', [", $src);
        self::assertStringNotContainsString("\$type === 'hooks' ? ['area_route' => false]", $src);
        self::assertMatchesRegularExpression(
            "/viewEnvironmentCacheSuffix\\('tag-source',[\\s\\S]{0,80}'area_route'\\s*=>\\s*false/",
            $src
        );
    }
}
