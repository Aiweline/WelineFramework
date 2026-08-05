<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

require_once \dirname(__DIR__, 9) . DIRECTORY_SEPARATOR . 'dev'
    . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR
    . 'wls-gateway-package.php';

final class WlsGatewayPackageBuilderTest extends TestCase
{
    private string $root = '';
    /** @var array<string,string> */
    private array $inputs = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped(
                'The package builder fixture uses POSIX executable scripts; Windows is covered by native CI.',
            );
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-package-builder-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $controller = $this->root . DIRECTORY_SEPARATOR . 'controller.php';
        self::assertNotFalse(\file_put_contents(
            $controller,
            "<?php\nfinal class PackageFixtureController {}\n",
        ));
        $licenses = $this->root . DIRECTORY_SEPARATOR . 'LICENSES.txt';
        self::assertNotFalse(\file_put_contents(
            $licenses,
            "WLS fixture: test-only\nPHP: PHP License\nNginx: BSD-2-Clause\n",
        ));
        $this->inputs = [
            'controller' => $controller,
            'php' => $this->executable('php', ''),
            'nginx' => $this->executable('nginx', '-V'),
            'wls-gateway-broker' => $this->executable(
                'wls-gateway-broker',
                '--self-test',
            ),
            'wls-gateway-launcher' => $this->executable(
                'wls-gateway-launcher',
                '--self-test',
            ),
            'licenses' => $licenses,
        ];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTestPackageIsAtomicAndCannotClaimReleaseReadiness(): void
    {
        $output = $this->root . DIRECTORY_SEPARATOR . 'test-package';
        $result = (new \WlsGatewayPackageBuilder())->build($this->options(
            $output,
            'test',
        ));

        self::assertTrue($result['ok']);
        self::assertFalse($result['release_ready']);
        self::assertDirectoryExists($output);
        self::assertFileDoesNotExist($output . DIRECTORY_SEPARATOR . 'manifest.sig');
        $manifest = $this->json($output . DIRECTORY_SEPARATOR . 'manifest.json');
        self::assertFalse($manifest['release_ready']);
        self::assertFalse($manifest['capabilities']['self_contained_php']);
        self::assertFalse($manifest['capabilities']['self_contained_nginx']);
        self::assertCount(8, $manifest['components']);
        self::assertSame(
            'CycloneDX',
            $this->json($output . DIRECTORY_SEPARATOR . 'sbom.cdx.json')['bomFormat'],
        );

        $previousTestMode = \getenv('WLS_GATEWAY_TEST_MODE');
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        try {
            $verified = (new HostGatewayPackageManager())->verifyPackage(
                $output,
                'default',
            );
            self::assertSame('test', $verified['manifest']['package_profile']);
            self::assertFalse($verified['manifest']['release_ready']);
        } finally {
            $previousTestMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousTestMode);
        }
    }

    public function testProductionPackageRequiresTrustedKeyAndSelfContainedProvenance(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \sodium_crypto_sign_secretkey($keyPair);
        $public = \sodium_crypto_sign_publickey($keyPair);
        $secretFile = $this->root . DIRECTORY_SEPARATOR . 'release.secret';
        self::assertNotFalse(\file_put_contents(
            $secretFile,
            \base64_encode($secret) . PHP_EOL,
        ));
        self::assertTrue(\chmod($secretFile, 0600));
        $trustedKeys = $this->root . DIRECTORY_SEPARATOR . 'trusted-keys.json';
        self::assertNotFalse(\file_put_contents(
            $trustedKeys,
            \json_encode([
                'schema_version' => 1,
                'keys' => [[
                    'id' => 'fixture-release-key',
                    'algorithm' => 'ed25519',
                    'enabled' => true,
                    'public_key_base64' => \base64_encode($public),
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $provenance = $this->root . DIRECTORY_SEPARATOR . 'provenance.json';
        $definitions = [];
        foreach ([
            'controller',
            'php',
            'nginx',
            'wls-gateway-broker',
            'wls-gateway-launcher',
        ] as $name) {
            $path = $this->inputs[$name];
            $definitions[$name] = [
                'version' => 'fixture-1',
                'source_url' => 'https://example.invalid/' . $name,
                'source_sha256' => \hash_file('sha256', $path),
                'binary_sha256' => \hash_file('sha256', $path),
                'license' => 'test-only',
                'self_contained' => $name !== 'controller',
            ];
        }
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 1,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        $output = $this->root . DIRECTORY_SEPARATOR . 'production-package';
        $options = $this->options($output, 'production') + [
            'provenance' => $provenance,
        ];
        $result = (new \WlsGatewayPackageBuilder())->build($options);
        self::assertFalse($result['release_ready']);
        self::assertTrue($result['production_candidate']);
        self::assertFileDoesNotExist($output . DIRECTORY_SEPARATOR . 'manifest.sig');
        $executionMarker = $this->inputs['wls-gateway-launcher'] . '.executed';
        self::assertFileExists($executionMarker);
        self::assertTrue(\unlink($executionMarker));
        $auditReceipt = $this->root . DIRECTORY_SEPARATOR
            . 'production-package-audit.json';
        $signer = new \WlsGatewayPackageSigner();
        $audit = $signer->audit([
            'package' => $output,
            'receipt-output' => $auditReceipt,
        ]);
        self::assertTrue($audit['ok']);
        $auditEnvironment = (string)$audit['audit_environment_sha256'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $auditEnvironment);
        self::assertFileDoesNotExist(
            $executionMarker,
            'The dependency-audit phase must parse metadata without executing a package component.',
        );
        $receiptBytes = (string)\file_get_contents($auditReceipt);
        $tamperedReceipt = \json_decode(
            $receiptBytes,
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $tamperedReceipt['component_set_sha256'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $auditReceipt,
            \json_encode(
                $tamperedReceipt,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        try {
            $signer->sign([
                'package' => $output,
                'audit-receipt' => $auditReceipt,
                'expected-audit-environment-sha256' => $auditEnvironment,
                'signing-key-id' => 'fixture-release-key',
                'signing-key-file' => $secretFile,
                'trusted-keys' => $trustedKeys,
            ]);
            self::fail('A forged dependency-audit receipt must not authorize signing.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'receipt does not match',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents($auditReceipt, $receiptBytes));
        $signed = $signer->sign([
            'package' => $output,
            'audit-receipt' => $auditReceipt,
            'expected-audit-environment-sha256' => $auditEnvironment,
            'signing-key-id' => 'fixture-release-key',
            'signing-key-file' => $secretFile,
            'trusted-keys' => $trustedKeys,
        ]);
        self::assertTrue($signed['release_ready']);
        self::assertFileDoesNotExist(
            $executionMarker,
            'The signing phase must never execute a package component.',
        );
        $manifestBytes = (string)\file_get_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.json',
        );
        $signature = \base64_decode(\trim((string)\file_get_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.sig',
        )), true);
        self::assertIsString($signature);
        self::assertTrue(\sodium_crypto_sign_verify_detached(
            $signature,
            $manifestBytes,
            $public,
        ));
        $verified = (new HostGatewayPackageManager(
            trustedKeysFile: $trustedKeys,
        ))->verifyPackage($output, 'default');
        self::assertSame('production', $verified['manifest']['package_profile']);
        self::assertTrue($verified['manifest']['release_ready']);

        $sbomMismatchOutput = $this->root . DIRECTORY_SEPARATOR . 'sbom-mismatch';
        (new \WlsGatewayPackageBuilder())->build(
            $this->options($sbomMismatchOutput, 'production') + [
                'provenance' => $provenance,
            ],
        );
        $sbomFile = $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'sbom.cdx.json';
        $sbom = $this->json($sbomFile);
        $sbom['components'][0]['hashes'][0]['content'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $sbomFile,
            \json_encode(
                $sbom,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        $manifestFile = $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'manifest.json';
        $mismatchedManifest = $this->json($manifestFile);
        $mismatchedManifest['components']['sbom.cdx.json']['sha256']
            = \hash_file('sha256', $sbomFile);
        $mismatchedManifest['components']['sbom.cdx.json']['size']
            = \filesize($sbomFile);
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $mismatchedManifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        try {
            (new \WlsGatewayPackageSigner())->sign([
                'package' => $sbomMismatchOutput,
                'signing-key-id' => 'fixture-release-key',
                'signing-key-file' => $secretFile,
                'trusted-keys' => $trustedKeys,
            ]);
            self::fail('A CycloneDX/provenance hash mismatch must not be signed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'SBOM does not match provenance',
                $exception->getMessage(),
            );
        }
        self::assertFileDoesNotExist(
            $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
        self::assertFalse($this->json($manifestFile)['release_ready']);
        \sodium_memzero($secret);

        $definitions['php']['self_contained'] = false;
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 1,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $rejectedOutput = $this->root . DIRECTORY_SEPARATOR . 'rejected-package';
        try {
            (new \WlsGatewayPackageBuilder())->build(
                $this->options($rejectedOutput, 'production') + [
                    'provenance' => $provenance,
                ],
            );
            self::fail('A non-self-contained production runtime must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'not proven self-contained',
                $exception->getMessage(),
            );
        }
        self::assertDirectoryDoesNotExist($rejectedOutput);
    }

    public function testLinuxDependencyAuditRejectsDynamicCryptoDespiteProvenanceClaim(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The deterministic libcrypto fixture is Linux-specific.');
        }
        $openssl = '';
        foreach (['/usr/bin/openssl', '/bin/openssl'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $openssl = $candidate;
                break;
            }
        }
        if ($openssl === '') {
            self::markTestSkipped('A system OpenSSL binary is unavailable.');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden host runtime dependency');
        \WlsGatewayDependencyAuditor::assertBaseSystemOnly(
            'nginx',
            $openssl,
            'Linux',
        );
    }

    public function testDarwinDependencyAuditSupportsLlvmCrossFormatFallback(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('The Mach-O LLVM fallback fixture is macOS-specific.');
        }
        $llvm = '';
        foreach ([
            '/opt/homebrew/opt/llvm/bin/llvm-readobj',
            '/usr/local/opt/llvm/bin/llvm-readobj',
            '/usr/bin/llvm-readobj',
        ] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $llvm = $candidate;
                break;
            }
        }
        if ($llvm === '') {
            self::markTestSkipped('llvm-readobj is unavailable.');
        }
        $oldPath = \getenv('PATH');
        try {
            \putenv('PATH=' . \dirname($llvm));
            \WlsGatewayDependencyAuditor::assertBaseSystemOnly(
                'mach-o-system-fixture',
                '/bin/ls',
                'Darwin',
            );
            self::assertTrue(true);
        } finally {
            $oldPath === false ? \putenv('PATH') : \putenv('PATH=' . $oldPath);
        }
    }

    public function testWindowsDependencyAllowlistAcceptsOnlyBaseSystemCrt(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'windowsSystemLibrary',
        );
        self::assertTrue($method->invoke(null, 'KERNEL32.dll'));
        self::assertTrue($method->invoke(null, 'msvcrt.dll'));
        self::assertFalse($method->invoke(null, 'libssp-0.dll'));
        self::assertFalse($method->invoke(null, 'vcruntime140.dll'));
    }

    public function testWindowsGnuObjdumpFallbackParsesOnlyPeImportTable(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'gnuObjdumpNeededLibraries',
        );
        $output = <<<'OUTPUT'
fixture.exe:     file format pei-x86-64

The Import Tables (interpreted .idata section contents)
	DLL Name: KERNEL32.dll
	DLL Name: bcrypt.dll
	DLL Name: KERNEL32.dll
OUTPUT;
        self::assertSame(
            ['kernel32.dll', 'bcrypt.dll'],
            $method->invoke(null, $output),
        );
    }

    public function testWindowsGnuObjdumpFallbackRejectsUnrecognizedOutput(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'gnuObjdumpNeededLibraries',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not recognized as a PE import table');
        $method->invoke(
            null,
            "not-a-pe: file format elf64-x86-64\nDLL Name: KERNEL32.dll\n",
        );
    }

    /**
     * @return array<string,string>
     */
    private function options(string $output, string $profile): array
    {
        return $this->inputs + [
            'output' => $output,
            'profile' => $profile,
            'version' => '2.0.0-fixture',
            'platform' => \PHP_OS_FAMILY,
            'arch' => $this->normalizedArch(),
        ];
    }

    private function executable(string $name, string $expectedArgument): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        $source = $path . '.c';
        $nameLiteral = \json_encode($name, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedLiteral = \json_encode(
            $expectedArgument,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $markerLiteral = \json_encode(
            $path . '.executed',
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(\file_put_contents(
            $source,
            <<<C
#include <stdio.h>
#include <string.h>

int main(int argc, char **argv) {
    const char *name = {$nameLiteral};
    const char *expected = {$expectedLiteral};
    if (strcmp(name, "php") == 0) {
        if (argc >= 2
            && (strcmp(argv[1], "-l") == 0 || strcmp(argv[1], "--version") == 0)) {
            return 0;
        }
        return argc == 3 && strcmp(argv[2], "--self-test") == 0 ? 0 : 1;
    }
    FILE *marker = fopen({$markerLiteral}, "wb");
    if (marker != NULL) {
        fclose(marker);
    }
    return argc == 2 && strcmp(argv[1], expected) == 0 ? 0 : 1;
}
C,
        ));
        $compiler = '';
        foreach (['/usr/bin/cc', '/usr/bin/clang', '/usr/bin/gcc'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $compiler = $candidate;
                break;
            }
        }
        if ($compiler === '') {
            self::markTestSkipped('A native C compiler is required for package fixtures.');
        }
        $process = \proc_open(
            [$compiler, '-O2', $source, '-o', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $output = (string)\stream_get_contents($pipes[1])
            . (string)\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        self::assertSame(0, \proc_close($process), $output);
        self::assertTrue(\is_executable($path));
        return $path;
    }

    /** @return array<string,mixed> */
    private function json(string $file): array
    {
        return \json_decode(
            (string)\file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function normalizedArch(): string
    {
        return match (\strtolower((string)\php_uname('m'))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower((string)\php_uname('m')),
        };
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink()
                ? @\rmdir($path)
                : @\unlink($path);
        }
        @\rmdir($root);
    }
}
