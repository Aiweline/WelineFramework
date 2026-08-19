<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\AdministratorAuthorizationSession;
use Weline\Server\Service\SslCertificateService;

require_once dirname(__DIR__, 6) . '/bootstrap_phpunit.php';

final class SslCertificateMacosTrustTest extends TestCase
{
    private const CURRENT_CA_FINGERPRINT = '09ABA86200000000000000000000000000005133';
    private const LOGIN_STALE_FINGERPRINT = '546555A400000000000000000000000000005C69';
    private const SYSTEM_STALE_FINGERPRINT = '8FF068CA0000000000000000000000000000F8C9';

    public function testMacosTrustCheckDoesNotTreatSelfSignedRootVerifyAsSystemTrust(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        $service->caSelfVerifyTrusted = true;
        $service->leafSystemVerifyTrusted = false;
        $service->opensslChainValid = true;

        $trusted = new \ReflectionMethod($service, 'isLocalCertificateAuthorityTrustedOnMacos');
        $trusted->setAccessible(true);

        self::assertFalse(
            $trusted->invoke($service, $caPath),
            'Same-CN stale roots plus CA self-verify must not count as current-CA system trust.',
        );
        self::assertNotContains(
            $caPath,
            $service->verifyCertTargets,
            'System trust must not be proven by verifying the self-signed root against itself.',
        );
        self::assertContains(
            $leafPath,
            $service->verifyCertTargets,
            'System trust must be proven with the current site leaf certificate.',
        );
    }

    public function testMacosTrustImportReusesAdministratorSessionAndRemovesStaleFingerprints(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        $service->caSelfVerifyTrusted = true;
        $service->leafSystemVerifyTrusted = false;
        $service->opensslChainValid = true;

        $sessionCommands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command) use (&$sessionCommands): int {
                $sessionCommands[] = $command;

                return 0;
            },
            interactiveProbe: static fn (): bool => true,
            effectiveUidProbe: static fn (): int => 501,
            sudoBinary: '/usr/bin/sudo',
            osFamily: 'Darwin',
        );
        $service->setAdministratorAuthorizationSession($session);

        $result = $service->trust($caPath);

        self::assertTrue((bool)($result['trusted'] ?? false));
        self::assertSame(['/usr/bin/sudo', '-v'], $sessionCommands[0] ?? null);
        $privileged = $sessionCommands[1] ?? [];
        self::assertSame('/usr/bin/sudo', $privileged[0] ?? null);
        self::assertSame('-n', $privileged[1] ?? null);
        self::assertSame('--', $privileged[2] ?? null);
        self::assertContains('delete-certificate', $privileged);
        self::assertContains(self::SYSTEM_STALE_FINGERPRINT, $privileged);

        $addTrusted = null;
        foreach ($sessionCommands as $command) {
            if (\in_array('add-trusted-cert', $command, true)) {
                $addTrusted = $command;
                break;
            }
        }
        self::assertNotNull($addTrusted, 'Current CA must be imported through the start authorization session.');
        self::assertContains('/Library/Keychains/System.keychain', $addTrusted);
        self::assertContains($caPath, $addTrusted);
        self::assertContains(self::LOGIN_STALE_FINGERPRINT, $service->deletedFingerprints);
        self::assertContains($leafPath, $service->verifyCertTargets);
        foreach ($service->commands as $command) {
            self::assertNotContains(
                '-n',
                $command,
                'macOS System Keychain mutation must not build a private sudo -n argv outside the start session.',
            );
        }
    }

    public function testMacosTrustCacheDoesNotPinAFailedImport(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        $service->blockTrustMutations = true;
        $failed = $service->trust($caPath);
        self::assertFalse((bool)($failed['trusted'] ?? true));

        $service->blockTrustMutations = false;
        $retried = $service->trust($caPath);
        self::assertTrue((bool)($retried['trusted'] ?? false));
    }

    /**
     * @return MacosTrustSslCertificateService
     */
    private function macosTrustService(string $caPath, string $leafPath): MacosTrustSslCertificateService
    {
        $service = new MacosTrustSslCertificateService();
        $service->caPath = $caPath;
        $service->leafPath = $leafPath;
        $service->currentFingerprint = self::CURRENT_CA_FINGERPRINT;

        return $service;
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $tempRoot = \realpath(\sys_get_temp_dir());
        self::assertIsString($tempRoot);
        $dir = $tempRoot . DIRECTORY_SEPARATOR . 'wls-macos-trust-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($dir, 0700, true));

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        self::assertNotFalse(\file_put_contents($path, $contents));

        return $path;
    }
}

final class MacosTrustSslCertificateService extends SslCertificateService
{
    public string $caPath = '';
    public string $leafPath = '';
    public string $currentFingerprint = '';
    /** @var array<string,list<string>> */
    public array $keychainFingerprints = [];
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<string> */
    public array $verifyCertTargets = [];
    /** @var list<string> */
    public array $deletedFingerprints = [];
    public bool $caSelfVerifyTrusted = false;
    public bool $leafSystemVerifyTrusted = false;
    public bool $opensslChainValid = true;
    public bool $blockTrustMutations = false;

    public function __construct()
    {
        parent::__construct(true);
    }

    public function setAdministratorAuthorizationSession(
        AdministratorAuthorizationSession $session,
    ): self {
        return parent::setAdministratorAuthorizationSession($session);
    }

    public function trust(string $caCertPath): array
    {
        return $this->trustLocalCertificateAuthority($caCertPath);
    }

    protected function getOsFamily(): string
    {
        return 'Darwin';
    }

    protected function commandExists(string $command): bool
    {
        return \in_array($command, ['security', 'sudo', 'openssl'], true);
    }

    protected function resolveTrustExecutable(string $command): string
    {
        return match ($command) {
            'security' => '/usr/bin/security',
            'sudo' => '/usr/bin/sudo',
            'openssl' => '/usr/bin/openssl',
            default => '',
        };
    }

    protected function resolveMacosLoginKeychain(): string
    {
        return '/Users/unit/Library/Keychains/login.keychain-db';
    }

    protected function getCertificateSha1Fingerprint(string $certPath): string
    {
        unset($certPath);

        return $this->currentFingerprint;
    }

    protected function resolveLocalDevelopmentProbeLeafPath(): string
    {
        return $this->leafPath;
    }

    protected function isLocalDevelopmentSslChainCryptographicallyValid(
        string $caCertPath,
        string $leafPath,
    ): bool {
        unset($caCertPath, $leafPath);

        return $this->opensslChainValid;
    }

    protected function runTrustCommand(
        array $command,
        ?int &$exitCode = null,
        bool $inheritStdin = false,
    ): string {
        unset($inheritStdin);
        $this->commands[] = $command;
        $exitCode = 0;

        if (\in_array('verify-cert', $command, true)) {
            $target = (string)($command[\array_search('-c', $command, true) + 1] ?? '');
            $this->verifyCertTargets[] = $target;
            if ($target === $this->caPath) {
                return $this->caSelfVerifyTrusted ? 'Cert Verify Result: No error' : 'CSSMERR_TP_NOT_TRUSTED';
            }
            if ($target === $this->leafPath && $this->leafSystemVerifyTrusted) {
                return 'Cert Verify Result: No error';
            }

            return 'CSSMERR_TP_NOT_TRUSTED';
        }

        if (\in_array('find-certificate', $command, true)) {
            $keychain = (string)($command[\count($command) - 1] ?? '');
            $output = '';
            foreach ($this->keychainFingerprints[$keychain] ?? [] as $fingerprint) {
                $output .= 'SHA-1 hash: ' . $fingerprint . "\n";
            }

            return $output;
        }

        if (\in_array('delete-certificate', $command, true)) {
            $hashIndex = \array_search('-Z', $command, true);
            $fingerprint = \is_int($hashIndex) ? (string)($command[$hashIndex + 1] ?? '') : '';
            $keychain = (string)($command[\count($command) - 1] ?? '');
            $this->deletedFingerprints[] = $fingerprint;
            $this->keychainFingerprints[$keychain] = \array_values(\array_filter(
                $this->keychainFingerprints[$keychain] ?? [],
                static fn (string $installed): bool => $installed !== $fingerprint,
            ));

            return 'deleted';
        }

        if (\in_array('add-trusted-cert', $command, true)) {
            if ($this->blockTrustMutations) {
                $exitCode = 1;

                return 'add-trusted-cert blocked';
            }
            $keychain = (string)($command[\array_search('-k', $command, true) + 1] ?? '');
            $this->keychainFingerprints[$keychain][] = $this->currentFingerprint;
            $this->leafSystemVerifyTrusted = true;

            return 'imported';
        }

        return '';
    }

    protected function runPrivilegedTrustMutation(array $command, ?int &$exitCode = null): string
    {
        if ($this->blockTrustMutations) {
            $exitCode = 1;

            return 'privileged mutation rejected';
        }

        $output = parent::runPrivilegedTrustMutation($command, $exitCode);
        if (($exitCode ?? 1) === 0) {
            $this->runTrustCommand($command, $appliedExitCode);
        }

        return $output;
    }
}
