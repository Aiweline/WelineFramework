<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Policy;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Policy\RuntimePolicyCompiler;

final class RuntimePolicyCompilerHostGuardTest extends TestCase
{
    public function testMaterialSslDirectoryHostsIncludeDottedDomainFolders(): void
    {
        $method = new \ReflectionMethod(RuntimePolicyCompiler::class, 'activeCertificateHosts');
        $hosts = $method->invoke(new RuntimePolicyCompiler());
        self::assertIsArray($hosts);
        foreach ($hosts as $host) {
            self::assertIsString($host);
            self::assertNotSame('', $host);
        }
    }

    public function testCompileAllowedHostsMergesCertificateMaterialWhenBindHostIsIp(): void
    {
        $compiler = new RuntimePolicyCompiler();
        $method = new \ReflectionMethod(RuntimePolicyCompiler::class, 'compileAllowedHosts');
        $hosts = $method->invoke(
            $compiler,
            ['public_host' => '172.31.35.19', 'host' => '172.31.35.19'],
            [],
            ['172.31.35.19'],
            true,
        );
        self::assertContains('172.31.35.19', $hosts);
        $material = (new \ReflectionMethod(RuntimePolicyCompiler::class, 'activeCertificateHosts'))
            ->invoke($compiler);
        foreach ($material as $host) {
            self::assertContains($host, $hosts);
        }
    }

    public function testCompileAllowedHostsMergesExtraAllowedHostsFromContext(): void
    {
        $compiler = new RuntimePolicyCompiler();
        $normalize = new \ReflectionMethod(RuntimePolicyCompiler::class, 'normalizeConfiguredHosts');
        $hosts = $normalize->invoke($compiler, [
            'host' => '127.0.0.1',
            'public_host' => 'www.changanhanfu.com',
            'ssl_domain' => 'www.changanhanfu.com',
            'extra_allowed_hosts' => ['www.changanhanfu.com', 'changanhanfu.com'],
        ]);
        self::assertContains('www.changanhanfu.com', $hosts);
        self::assertContains('changanhanfu.com', $hosts);
        self::assertContains('127.0.0.1', $hosts);

        $compile = new \ReflectionMethod(RuntimePolicyCompiler::class, 'compileAllowedHosts');
        $compiled = $compile->invoke(
            $compiler,
            [
                'public_host' => 'www.changanhanfu.com',
                'extra_allowed_hosts' => ['changanhanfu.com'],
            ],
            [],
            $hosts,
            true,
        );
        self::assertContains('www.changanhanfu.com', $compiled);
        self::assertContains('changanhanfu.com', $compiled);
    }
}
