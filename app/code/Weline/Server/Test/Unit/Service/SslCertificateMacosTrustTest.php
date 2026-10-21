<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\AdministratorAuthorizationSession;
use Weline\Server\Service\LocalCaOwnershipPolicy;
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

    public function testMacosTrustImportPreservesForeignSameCnFingerprints(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        $service->retiredFingerprints = [];
        $service->caSelfVerifyTrusted = true;
        $service->leafSystemVerifyTrusted = false;
        $service->opensslChainValid = true;

        $sessionCommands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command, ?array $environment = null) use (&$sessionCommands): int {
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
        self::assertSame([], $service->deletedFingerprints, 'Sibling/foreign same-CN CAs must never be deleted.');
        self::assertContains(
            self::SYSTEM_STALE_FINGERPRINT,
            $service->keychainFingerprints['/Library/Keychains/System.keychain'] ?? [],
        );
        self::assertContains(
            self::LOGIN_STALE_FINGERPRINT,
            $service->keychainFingerprints['/Users/unit/Library/Keychains/login.keychain-db'] ?? [],
        );

        self::assertSame(['/usr/bin/sudo', '-v'], $sessionCommands[0] ?? null);
        $privileged = $sessionCommands[1] ?? [];
        self::assertSame('/usr/bin/sudo', $privileged[0] ?? null);
        self::assertSame('-n', $privileged[1] ?? null);
        self::assertSame('--', $privileged[2] ?? null);
        self::assertStringContainsString(
            'wls_macos_local_ca_trust_editor.php',
            \implode(' ', $privileged),
            'Foreign same-CN preservation must still use the one-shot System Keychain editor.',
        );
        self::assertStringContainsString('--ca=', \implode(' ', $privileged));
        self::assertStringNotContainsString(
            '--retire=',
            \implode(' ', $privileged),
            'Non-retired sibling fingerprints must not be passed to the elevated editor.',
        );
        self::assertCount(2, $sessionCommands, 'CA trust must elevate exactly once.');
        self::assertContains($leafPath, $service->verifyCertTargets);
    }

    public function testMacosTrustImportRemovesOnlyWorkspaceRetiredFingerprints(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [
                self::SYSTEM_STALE_FINGERPRINT,
                self::LOGIN_STALE_FINGERPRINT,
            ],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        // Only the prior generation of THIS workspace may be deleted:
        // retired list + origin=generated + not shared + workspace issuance proof.
        $service->retiredFingerprints = [self::SYSTEM_STALE_FINGERPRINT];
        $service->provenance = [
            self::SYSTEM_STALE_FINGERPRINT => [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => ['unit-retired.test.weline.com'],
            ],
        ];
        $service->caSelfVerifyTrusted = true;
        $service->leafSystemVerifyTrusted = false;
        $service->opensslChainValid = true;

        $sessionCommands = [];
        $session = new AdministratorAuthorizationSession(
            commandRunner: static function (array $command, ?array $environment = null) use (&$sessionCommands): int {
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
        $privilegedLine = \implode(' ', $privileged);
        self::assertStringContainsString(
            'wls_macos_local_ca_trust_editor.php',
            $privilegedLine,
            'System Keychain retire+import must run in one elevated editor process.',
        );
        self::assertStringContainsString('--ca=', $privilegedLine);
        self::assertStringContainsString(
            '--retire=' . self::SYSTEM_STALE_FINGERPRINT,
            $privilegedLine,
        );
        self::assertStringNotContainsString(
            self::LOGIN_STALE_FINGERPRINT,
            $privilegedLine,
            'Fingerprints not in this workspace retired list must not be retired by the editor.',
        );
        self::assertCount(2, $sessionCommands, 'sudo authorize + one editor invocation only.');
        self::assertContains(self::SYSTEM_STALE_FINGERPRINT, $service->deletedFingerprints);
        self::assertNotContains(
            self::LOGIN_STALE_FINGERPRINT,
            $service->deletedFingerprints,
            'Fingerprints not in this workspace retired list must be preserved.',
        );
        self::assertContains(
            self::CURRENT_CA_FINGERPRINT,
            $service->keychainFingerprints['/Library/Keychains/System.keychain'] ?? [],
        );
        self::assertContains($leafPath, $service->verifyCertTargets);
        foreach ($service->commands as $command) {
            self::assertNotContains(
                '-n',
                $command,
                'macOS System Keychain mutation must not build a private sudo -n argv outside the start session.',
            );
        }
    }

    /**
     * 候选清单（retired_sha1_fingerprints.txt）不足以证明归属：
     * 从整机共享目录继承来的 CA 即使被登记为候选，也必须保留。
     */
    public function testMacosTrustImportPreservesAdoptedGlobalCaEvenWhenListedAsRetired(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [self::LOGIN_STALE_FINGERPRINT],
        ];
        $service->retiredFingerprints = [self::SYSTEM_STALE_FINGERPRINT];
        $service->provenance = [
            self::SYSTEM_STALE_FINGERPRINT => [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_ADOPTED_GLOBAL,
                'shared' => true,
                'domains' => ['unit-adopted.test.weline.com'],
            ],
        ];

        $result = $service->trust($caPath);

        self::assertTrue((bool)($result['trusted'] ?? false));
        self::assertNotContains(
            self::SYSTEM_STALE_FINGERPRINT,
            $service->deletedFingerprints,
            'A CA inherited from the machine-wide _local_ca directory must never be deleted.',
        );
        self::assertContains(
            self::SYSTEM_STALE_FINGERPRINT,
            $service->keychainFingerprints['/Library/Keychains/System.keychain'] ?? [],
        );
    }

    /**
     * 本仓生成但已共享给其他项目的 CA：兄弟项目可能正在继承，换新 CA 时也必须保留。
     */
    public function testMacosTrustImportPreservesSharedWorkspaceCa(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [],
        ];
        $service->retiredFingerprints = [self::SYSTEM_STALE_FINGERPRINT];
        $service->provenance = [
            self::SYSTEM_STALE_FINGERPRINT => [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => true,
                'domains' => ['unit-shared.test.weline.com'],
            ],
        ];

        $result = $service->trust($caPath);

        self::assertTrue((bool)($result['trusted'] ?? false));
        self::assertNotContains(
            self::SYSTEM_STALE_FINGERPRINT,
            $service->deletedFingerprints,
            'A workspace CA already published for sibling projects must be preserved.',
        );
    }

    /**
     * 本仓生成、未共享，但拿不出「该 CA 签发过本仓域名」的证据：保留（宁可不清理）。
     */
    public function testMacosTrustImportPreservesWorkspaceCaWithoutIssuanceProof(): void
    {
        $caPath = $this->writeTempFile('rootCA.pem', 'current-ca');
        $leafPath = $this->writeTempFile('fullchain.pem', 'current-leaf');
        $service = $this->macosTrustService($caPath, $leafPath);
        $service->keychainFingerprints = [
            '/Library/Keychains/System.keychain' => [self::SYSTEM_STALE_FINGERPRINT],
            '/Users/unit/Library/Keychains/login.keychain-db' => [],
        ];
        $service->retiredFingerprints = [self::SYSTEM_STALE_FINGERPRINT];
        $service->provenance = [
            self::SYSTEM_STALE_FINGERPRINT => [
                'origin' => LocalCaOwnershipPolicy::ORIGIN_GENERATED,
                'shared' => false,
                'domains' => [],
            ],
        ];

        $result = $service->trust($caPath);

        self::assertTrue((bool)($result['trusted'] ?? false));
        self::assertNotContains(
            self::SYSTEM_STALE_FINGERPRINT,
            $service->deletedFingerprints,
            'Without DB/leaf issuance proof the CA must be preserved.',
        );
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
    /** @var list<string> */
    public array $retiredFingerprints = [];
    /** @var array<string,array<string,mixed>> */
    public array $provenance = [];
    /** @var list<string> */
    public array $issuanceProofDomains = [];
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

    protected function loadLocalCaRetiredFingerprints(): array
    {
        return \array_values(\array_unique(\array_map(
            static fn (string $fingerprint): string => \strtoupper(
                (string) \preg_replace('/[^A-F0-9]/i', '', $fingerprint)
            ),
            $this->retiredFingerprints,
        )));
    }

    protected function loadLocalCaProvenance(): array
    {
        return $this->provenance;
    }

    protected function resolveWorkspaceDomainsSignedByLocalCa(string $caCertPath): array
    {
        unset($caCertPath);

        return $this->issuanceProofDomains;
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

    protected function publishMacosSystemLocalCaTrust(
        string $caCertPath,
        array $retireFingerprints,
    ): bool {
        if ($this->blockTrustMutations) {
            return false;
        }
        $ok = parent::publishMacosSystemLocalCaTrust($caCertPath, $retireFingerprints);
        if (!$ok) {
            return false;
        }
        // Session commandRunner only records elevation; mirror editor side effects here.
        foreach ($retireFingerprints as $fingerprint) {
            $exitCode = 0;
            $this->runTrustCommand(
                [
                    '/usr/bin/security',
                    'delete-certificate',
                    '-Z',
                    $fingerprint,
                    '/Library/Keychains/System.keychain',
                ],
                $exitCode,
            );
        }
        $exitCode = 0;
        $this->runTrustCommand(
            [
                '/usr/bin/security',
                'add-trusted-cert',
                '-d',
                '-r',
                'trustRoot',
                '-p',
                'ssl',
                '-p',
                'basic',
                '-k',
                '/Library/Keychains/System.keychain',
                $caCertPath,
            ],
            $exitCode,
        );

        return true;
    }
}
