<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\MailOriginIpResolver;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailOriginIpResolverContractTest extends TestCase
{
    public function testStoredIpv4Wins(): void
    {
        $resolver = new MailOriginIpResolver();
        self::assertSame('203.0.113.10', $resolver->resolve('203.0.113.10'));
    }

    public function testRejectsNonIpStoredViaFilter(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/MailOriginIpResolver.php');
        self::assertStringContainsString('FILTER_VALIDATE_IP', $src);
        self::assertStringContainsString('FILTER_FLAG_IPV4', $src);
        // 非法存值不会 early-return，会落到 env / ServerIpService（需完整引导，本契约只锁校验门）
        self::assertMatchesRegularExpression(
            '/if \(\$stored !== \'\' && \$this->isIpv4\(\$stored\)\)/',
            $src
        );
    }

    public function testResolverSourcePrefersEnvThenServerIpService(): void
    {
        $src = (string)file_get_contents(BP . 'app/code/Weline/Mail/Service/MailOriginIpResolver.php');
        self::assertStringContainsString("Env::get('wls.public_ip')", $src);
        self::assertStringContainsString('ServerIpService', $src);
        self::assertStringContainsString('getPublicIpv4(true)', $src);
    }
}
