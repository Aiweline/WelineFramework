<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class EnterpriseDnsChecklistMarksContractTest extends TestCase
{
    public function testEnterpriseShowsLiveDnsMarksAndOauthError(): void
    {
        $path = BP . 'app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('dns_live_checks', $src);
        self::assertStringContainsString('detected_origin_ip', $src);
        self::assertStringContainsString('mail-enterprise__dns-mark', $src);
        self::assertStringContainsString('mail-enterprise__dns-guide', $src);
        self::assertStringContainsString('<details class="mail-enterprise__dns-item"', $src);
        self::assertStringContainsString('本机邮局 IP', $src);
        self::assertStringContainsString('本机邮局公钥待生成', $src);
        self::assertStringContainsString('cloudflare_token_ready', $src);
        self::assertStringContainsString('一键同步 DNS 到 Cloudflare', $src);
        self::assertStringContainsString('mail-cf-one-click-apply', $src);
        self::assertStringContainsString('data-dns-code', $src);
        self::assertStringContainsString('data-testid="mail-oauth-error"', $src);
        self::assertStringContainsString('invalid_scope', $src);
        self::assertStringContainsString('DNS 已通过', $src);
        self::assertStringNotContainsString('→ <?= $escape($originIp ?: __(\'源站公网 IP\')) ?>', $src);

        $index = (string)file_get_contents(BP . 'app/code/Weline/Mail/Controller/Backend/Index.php');
        self::assertStringContainsString('buildLiveDnsChecks', $index);
        self::assertStringContainsString('MailOriginIpResolver', $index);
        self::assertStringContainsString('isCloudflareDefaultTokenReady', $index);
        self::assertStringContainsString("oauth_error", $index);
    }
}
