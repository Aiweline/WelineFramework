<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteScopeTreeService;

final class WebsiteScopeTreeServiceContractTest extends TestCase
{
    public function testParseAndFormatNode(): void
    {
        self::assertSame(
            ['kind' => 'website', 'id' => 0],
            WebsiteScopeTreeService::parseNode('website:0'),
        );
        self::assertSame(
            ['kind' => 'store', 'id' => 12],
            WebsiteScopeTreeService::parseNode('store:12'),
        );
        self::assertSame(
            ['kind' => 'channel', 'id' => 3],
            WebsiteScopeTreeService::parseNode('channel:3'),
        );
        self::assertNull(WebsiteScopeTreeService::parseNode('store:_'));
        self::assertNull(WebsiteScopeTreeService::parseNode('global'));
        self::assertSame('website:0', WebsiteScopeTreeService::formatNode('website', 0));
    }

    public function testServiceSourceForbidsGlobalKind(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WebsiteScopeTreeService.php',
        );
        self::assertStringContainsString("preg_match('/^(website|store|channel):", $source);
        self::assertStringNotContainsString("'global'", $source);
        self::assertStringContainsString('buildTree', $source);
        self::assertStringContainsString('resolveSelection', $source);
        self::assertStringContainsString('WebsiteScopeTreeBrandLogoResolver', $source);
        self::assertStringContainsString("'logo'", $source);
        $resolver = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WebsiteScopeTreeBrandLogoResolver.php',
        );
        self::assertStringContainsString('ThemeBrandResolver', $resolver);
        self::assertStringContainsString('class_exists', $resolver);
    }
}
