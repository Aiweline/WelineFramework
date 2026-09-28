<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CdnSystemConfigPageContractTest extends TestCase
{
    public function testConfigTemplateEmbedsCdnBackendSystemConfig(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Config/index.phtml';
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        self::assertStringContainsString('data-testid="cdn-system-config"', $html);
        self::assertStringContainsString('<w:config:embed', $html);
        self::assertStringContainsString('module="Weline_Cdn"', $html);
        self::assertStringContainsString('area="backend"', $html);
        self::assertStringContainsString('<w:scope', $html);
        self::assertStringContainsString('cdn/backend/account/index', $html);
        self::assertStringContainsString('weline_systemconfig/backend/config', $html);
    }

    public function testMenuRegistersSystemConfigEntry(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/backend/menu.xml';
        self::assertFileExists($path);
        $xml = (string)file_get_contents($path);
        self::assertStringContainsString('Weline_Cdn::cdn_system_config', $xml);
        self::assertStringContainsString('cdn/backend/config', $xml);
        self::assertStringNotContainsString('cdn/backend/config/index', $xml);
        self::assertStringContainsString('title="系统配置"', $xml);
    }

    public function testOauthConfigAclUsesSystemConfigSource(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/cdn-cloudflare-oauth.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('@config.acl {Weline_Cdn::cdn_system_config}', $src);
    }
}
