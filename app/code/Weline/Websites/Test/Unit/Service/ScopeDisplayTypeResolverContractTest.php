<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ScopeDisplayTypeResolverContractTest extends TestCase
{
    public function testResolverPreferChannelThenStoreThenWebsiteAndFailSoftUnknown(): void
    {
        $resolverPath = dirname(__DIR__, 3) . '/Service/ScopeDisplayTypeResolver.php';
        $registryPath = dirname(__DIR__, 3) . '/Service/ScopeDisplayTypeRegistry.php';
        $interfacePath = dirname(__DIR__, 3) . '/Api/ScopeDisplayTypeProviderInterface.php';
        $extendsPath = dirname(__DIR__, 3) . '/extends.php';

        self::assertFileExists($resolverPath);
        self::assertFileExists($registryPath);
        self::assertFileExists($interfacePath);
        self::assertFileExists($extendsPath);

        $resolver = (string)file_get_contents($resolverPath);
        $interface = (string)file_get_contents($interfacePath);
        $extends = (string)file_get_contents($extendsPath);

        self::assertStringContainsString('channel → store → website', $resolver);
        self::assertStringContainsString('REQUEST_PRIVATE_KEY', $resolver);
        self::assertStringContainsString('runtime.request_context.display_type', $resolver);
        self::assertStringContainsString('isKnownCode', $resolver);
        self::assertStringNotContainsString('createCatalogConstraint', $interface);
        self::assertStringContainsString('getCode()', $interface);
        self::assertStringContainsString('getLabel()', $interface);
        self::assertStringContainsString('getModule()', $interface);
        self::assertStringContainsString("'ScopeDisplayType'", $extends);
        self::assertStringContainsString('ScopeDisplayTypeProviderInterface', $extends);
    }

    public function testModelsOwnDisplayTypeAndChannelUrlOutsideSummaryV1(): void
    {
        $website = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Website.php');
        $store = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Store.php');
        $channel = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/SalesChannel.php');
        $storeSummary = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Catalog/Data/StoreSummary.php');
        $channelSummary = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Catalog/Data/SalesChannelSummary.php');

        self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $website);
        self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $store);
        self::assertStringContainsString("schema_fields_DISPLAY_TYPE = 'display_type'", $channel);
        self::assertStringContainsString("schema_fields_URL = 'url'", $channel);
        self::assertStringNotContainsString('display_type', $storeSummary);
        self::assertStringNotContainsString('display_type', $channelSummary);
        self::assertStringNotContainsString("'url'", $channelSummary);
    }
}
