<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\Service\Security\SecurityProbeTokenService;

final class SecurityProbeSecretHardeningTest extends TestCase
{
    public function testPersistedSecretIsRandomStableAndOwnerOnly(): void
    {
        $resolve = new \ReflectionMethod(SecurityProbeTokenService::class, 'resolveSecret');
        $resolve->setAccessible(true);
        $cache = new \ReflectionProperty(SecurityProbeTokenService::class, 'secretCache');
        $cache->setAccessible(true);

        $cache->setValue(null, null);
        $first = (string)$resolve->invoke(null);
        $cache->setValue(null, null);
        $second = (string)$resolve->invoke(null);

        if ($first === '') {
            self::markTestSkipped('Probe secret is explicitly configured or unavailable.');
        }
        self::assertSame($first, $second);
        self::assertSame(32, \strlen($first), 'Persisted secret must be 32 random bytes.');

        $path = Env::varDir() . 'server/security_probe_secret';
        self::assertFileExists($path);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', \trim((string)\file_get_contents($path)));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($path) & 0777, 'Probe secret must be owner-only.');
        }
    }

    public function testSecretIsNotDerivedFromTheProjectPath(): void
    {
        $resolve = new \ReflectionMethod(SecurityProbeTokenService::class, 'resolveSecret');
        $resolve->setAccessible(true);
        $cache = new \ReflectionProperty(SecurityProbeTokenService::class, 'secretCache');
        $cache->setAccessible(true);
        $cache->setValue(null, null);
        $secret = (string)$resolve->invoke(null);
        if ($secret === '') {
            self::markTestSkipped('Probe secret is explicitly configured or unavailable.');
        }

        $pathDerived = \hash('sha256', BP . '|wls-security-probe-fallback', true);
        self::assertNotSame($pathDerived, $secret);
    }

    public function testServiceSourceHasNoProjectPathFallback(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(SecurityProbeTokenService::class))->getFileName(),
        );
        self::assertStringNotContainsString('wls-security-probe-fallback', $source);
        self::assertStringContainsString('random_bytes(32)', $source);
        self::assertStringContainsString('chmod($path, 0600)', $source);
    }
}
