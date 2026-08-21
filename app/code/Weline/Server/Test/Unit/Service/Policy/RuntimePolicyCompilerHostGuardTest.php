<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Policy;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Policy\RuntimePolicyCompiler;

final class RuntimePolicyCompilerHostGuardTest extends TestCase
{
    public function testMaterialSslDirectoryHostsIncludeDottedDomainFolders(): void
    {
        $method = new \ReflectionMethod(RuntimePolicyCompiler::class, 'sslMaterialDirectoryHosts');
        $hosts = $method->invoke(new RuntimePolicyCompiler());
        self::assertIsArray($hosts);
        foreach ($hosts as $host) {
            self::assertIsString($host);
            self::assertStringContainsString('.', $host);
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
        $material = (new \ReflectionMethod(RuntimePolicyCompiler::class, 'sslMaterialDirectoryHosts'))
            ->invoke($compiler);
        foreach ($material as $host) {
            self::assertContains($host, $hosts);
        }
    }
}
