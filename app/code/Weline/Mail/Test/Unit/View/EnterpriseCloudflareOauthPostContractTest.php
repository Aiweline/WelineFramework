<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * 企业邮箱域名面板「连接 Cloudflare」必须 POST 到 cdn/backend/oauth/connect。
 * GET <a href> 会落到非 postConnect，表现为授权报错。
 */
final class EnterpriseCloudflareOauthPostContractTest extends TestCase
{
    public function testEnterpriseDomainsPanelPostsOauthConnect(): void
    {
        $path = BP . 'app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);

        self::assertStringContainsString("method=\"post\" action=\"@backend-url{'cdn/backend/oauth/connect'}\"", $src);
        self::assertStringContainsString('name="adapter" value="cloudflare"', $src);
        self::assertStringContainsString('name="return_route" value="weline_mail/backend"', $src);
        self::assertStringContainsString("__('连接或重新授权 Cloudflare')", $src);

        self::assertStringNotContainsString(
            "@backend-url{'cdn/backend/oauth/connect'|\$cloudflareConnectParams}",
            $src,
            '禁止用 GET 链接触发 OAuth connect（仅 postConnect）'
        );
        self::assertDoesNotMatchRegularExpression(
            '/<a[^>]+href="@backend-url\{\'cdn\/backend\/oauth\/connect\'/',
            $src,
            '禁止 <a href> 指向 oauth/connect'
        );
    }
}
