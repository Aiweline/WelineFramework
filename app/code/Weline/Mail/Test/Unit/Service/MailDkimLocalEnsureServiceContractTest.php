<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\MailDkimLocalEnsureService;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MailDkimLocalEnsureServiceContractTest extends TestCase
{
    public function testEnsureGeneratesReusablePublicKey(): void
    {
        $svc = new MailDkimLocalEnsureService();
        $domain = 'dkim-auto-' . bin2hex(random_bytes(4)) . '.example.com';
        $first = $svc->ensure($domain, 'default');
        self::assertTrue($first['created']);
        self::assertNotSame('', $first['public_key']);
        self::assertGreaterThan(32, strlen(base64_decode($first['public_key'], true) ?: ''));
        self::assertFileExists($first['private_key_path']);

        $second = $svc->ensure($domain, 'default');
        self::assertFalse($second['created']);
        self::assertSame($first['public_key'], $second['public_key']);

        @unlink($first['private_key_path']);
    }
}
