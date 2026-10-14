<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class LocaleCatalogScopeResolverResolvedCodesContractTest extends TestCase
{
    public function testResolverUsesResolvedQueryAndPreservesExplicitOrder(): void
    {
        $resolver = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LocaleCatalogScopeResolver.php'
        );
        $scope = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LocaleCatalogScope.php'
        );
        $switcher = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php'
        );

        self::assertStringContainsString('getResolvedLanguageCodes', $resolver);
        self::assertStringContainsString('preserveExplicitOrder', $scope);
        self::assertStringContainsString('preserveExplicitOrder', $resolver);
        self::assertStringContainsString('preserveExplicitOrder', $switcher);
        self::assertStringContainsString('storeId', $scope);
        self::assertStringContainsString('channelId', $scope);
    }
}
