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
    private string $releasePublicKeyHex = '';
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
        $this->releasePublicKeyHex = \bin2hex(\random_bytes(32));
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
        $caBundle = $this->root . DIRECTORY_SEPARATOR . 'ca-bundle.pem';
        self::assertNotFalse(\file_put_contents(
            $caBundle,
            $this->certificateAuthority(),
        ));
        $this->inputs = [
            'controller' => $controller,
            'ca-bundle' => $caBundle,
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
        self::assertTrue($manifest['capabilities']['certificate_snapshot_seal']);
        self::assertTrue(
            $manifest['capabilities']['stable_launcher_rollback_target_proof'],
        );
        self::assertTrue(
            $manifest['capabilities']['physical_rebootstrap_capacity_reserve'],
        );
        self::assertFalse($manifest['capabilities']['self_contained_php']);
        self::assertFalse($manifest['capabilities']['self_contained_nginx']);
        self::assertSame(
            HostGatewayPackageManager::DURABLE_STATE_CONTRACT,
            $manifest['durable_state_contract'],
        );
        self::assertCount(9, $manifest['components']);
        self::assertTrue(
            $manifest['capabilities']['certificate_public_trust_bundle'],
        );
        self::assertSame(
            'CycloneDX',
            $this->json($output . DIRECTORY_SEPARATOR . 'sbom.cdx.json')['bomFormat'],
        );
        self::assertSame(
            "--self-test\n--rollback-target-proof-self-test\n--recovery-ledger-self-test\n--capacity-reserve-contract-self-test\n",
            (string)\file_get_contents(
                $this->inputs['wls-gateway-launcher'] . '.executed',
            ),
            'A package must execute the launcher proof test before it can claim the capability.',
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

    public function testPackageBuilderFailsClosedWhenLauncherProofTestIsUnavailable(): void
    {
        $this->inputs['wls-gateway-launcher'] = $this->executable(
            'wls-gateway-launcher-without-proof',
            '--self-test',
        );
        $output = $this->root . DIRECTORY_SEPARATOR . 'unproved-launcher-package';

        try {
            (new \WlsGatewayPackageBuilder())->build($this->options(
                $output,
                'test',
            ));
            self::fail('An unproved stable launcher must not produce a package manifest.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'component self-test failed',
                $exception->getMessage(),
            );
        }
        self::assertDirectoryDoesNotExist($output);
    }

    public function testProductionCandidateRejectsZeroOrMismatchedEmbeddedLauncherKey(): void
    {
        $zeroKey = \str_repeat('0', 64);
        $candidates = [
            'zero' => $zeroKey,
            'mismatched' => \str_repeat('1', 64),
        ];
        foreach ($candidates as $name => $reportedKey) {
            $this->inputs['wls-gateway-launcher'] = $this->executable(
                'wls-gateway-launcher',
                '--self-test',
                $reportedKey,
            );
            $provenance = $this->productionProvenance(
                'embedded-launcher-key-' . $name,
            );
            $output = $this->root . DIRECTORY_SEPARATOR
                . 'embedded-launcher-key-' . $name;
            $rejected = false;
            try {
                (new \WlsGatewayPackageBuilder())->build(
                    $this->options($output, 'production') + [
                        'provenance' => $provenance,
                        'approved-provenance-sha256' => \hash_file('sha256', $provenance),
                    ],
                );
            } catch (\RuntimeException $exception) {
                $rejected = true;
                self::assertStringContainsString(
                    'embedded release public key',
                    $exception->getMessage(),
                );
            }
            self::assertTrue(
                $rejected,
                'A production candidate with a ' . $name
                    . ' embedded Launcher public key must be rejected.',
            );
            self::assertDirectoryDoesNotExist($output);
        }

        $this->inputs['wls-gateway-launcher'] = $this->executable(
            'wls-gateway-launcher',
            '--self-test',
            $this->releasePublicKeyHex,
        );
        $output = $this->root . DIRECTORY_SEPARATOR . 'declared-zero-launcher-key';
        $rejected = false;
        $rejected = false;
        try {
            (new \WlsGatewayPackageBuilder())->build(\array_replace(
                $this->options($output, 'production'),
                [
                    'provenance' => $provenance = $this->productionProvenance(
                        'declared-zero-launcher-key',
                    ),
                    'approved-provenance-sha256' => \hash_file('sha256', $provenance),
                    'release-public-key-hex' => $zeroKey,
                ],
            ));
        } catch (\RuntimeException $exception) {
            $rejected = true;
            self::assertStringContainsString(
                'embedded release public key',
                $exception->getMessage(),
            );
        }
        self::assertTrue(
            $rejected,
            'A production assembly must not declare an all-zero Launcher public key.',
        );
        self::assertDirectoryDoesNotExist($output);
    }

    public function testProductionAuthorityRejectsAnUnapprovedProvenanceAndAuditSignDigestMismatches(): void
    {
        $approved = $this->productionProvenance('approved-provenance');
        $alternate = $this->json($approved);
        $alternate['components']['nginx']['source_url'] .= '?alternate=1';
        $unapproved = $this->root . DIRECTORY_SEPARATOR . 'unapproved-provenance.json';
        self::assertNotFalse(\file_put_contents(
            $unapproved,
            \json_encode(
                $alternate,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        $output = $this->root . DIRECTORY_SEPARATOR . 'unapproved-provenance-candidate';
        $rejected = false;
        try {
            (new \WlsGatewayPackageBuilder())->build(\array_replace(
                $this->options($output, 'production'),
                [
                    'provenance' => $unapproved,
                    'approved-provenance-sha256' => \hash_file('sha256', $approved),
                ],
            ));
        } catch (\RuntimeException $exception) {
            $rejected = true;
            self::assertStringContainsString('approved provenance', $exception->getMessage());
        }
        self::assertTrue($rejected, 'Assembly must reject P\' when only P was approved.');

        $fixture = $this->productionSigningFixture('expected-provenance-mismatch');
        $mismatch = \str_repeat('0', 64);
        $receipt = $this->root . DIRECTORY_SEPARATOR
            . 'expected-provenance-mismatch-second.audit.json';
        $auditRejected = false;
        try {
            $fixture['signer']->audit([
                'package' => $fixture['package'],
                'receipt-output' => $receipt,
                'expected-provenance-sha256' => $mismatch,
            ]);
        } catch (\RuntimeException $exception) {
            $auditRejected = true;
            self::assertStringContainsString('provenance', $exception->getMessage());
        }
        if (!$auditRejected) {
            self::fail('Audit accepted a mismatched provenance authority.');
        }
        self::assertTrue($auditRejected, 'Audit must bind the caller-approved provenance digest.');

        $audit = $fixture['signer']->audit([
            'package' => $fixture['package'],
            'receipt-output' => $receipt,
            'expected-provenance-sha256' => \hash_file(
                'sha256',
                $fixture['package'] . DIRECTORY_SEPARATOR . 'provenance.json',
            ),
        ]);
        $signRejected = false;
        try {
            $fixture['signer']->sign(\array_replace(
                $fixture['sign_options'],
                [
                    'audit-receipt' => $receipt,
                    'expected-audit-environment-sha256'
                        => (string)$audit['audit_environment_sha256'],
                    'expected-provenance-sha256' => $mismatch,
                ],
            ));
        } catch (\RuntimeException $exception) {
            $signRejected = true;
        }
        self::assertTrue($signRejected, 'Signing must bind the caller-approved provenance digest.');
    }

    public function testProductionAssemblyRejectsNoncanonicalApprovedProvenanceBytes(): void
    {
        $provenance = $this->productionProvenance('noncanonical-approved-provenance');
        self::assertNotFalse(\file_put_contents(
            $provenance,
            (string)\file_get_contents($provenance) . " \n",
        ));
        $output = $this->root . DIRECTORY_SEPARATOR . 'noncanonical-approved-provenance';

        try {
            (new \WlsGatewayPackageBuilder())->build(\array_replace(
                $this->options($output, 'production'),
                [
                    'provenance' => $provenance,
                    'approved-provenance-sha256' => \hash_file('sha256', $provenance),
                ],
            ));
            self::fail('Production assembly must reject noncanonical bytes even when their digest is approved.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('canonical', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($output);
    }

    public function testProductionAssemblyUsesOneStableProvenanceReadForApprovalAndPackaging(): void
    {
        $source = $this->productionProvenance('single-read-provenance-p1');
        $p1 = (string)\file_get_contents($source);
        $p2Document = $this->json($source);
        $p2Document['components']['nginx']['source_url'] .= '?replacement=2';
        $p2 = \json_encode(
            $p2Document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        $output = $this->root . DIRECTORY_SEPARATOR . 'single-read-provenance';
        $firstReadPath = '';
        $firstReadBytes = '';
        $builder = new \WlsGatewayPackageBuilder(static function (
            string $readPath,
            string $readBytes,
        ) use (&$firstReadPath, &$firstReadBytes, $source, $p2): void {
            $firstReadPath = $readPath;
            $firstReadBytes = $readBytes;
            self::assertNotFalse(\file_put_contents($source, $p2));
        });
        $rejected = false;
        try {
            $builder->build(\array_replace(
                $this->options($output, 'production'),
                [
                    'provenance' => $source,
                    'approved-provenance-sha256' => \hash('sha256', $p2),
                ],
            ));
        } catch (\RuntimeException $exception) {
            $rejected = true;
            self::assertStringContainsString('approved provenance', $exception->getMessage());
        }
        self::assertSame((string)\realpath($source), $firstReadPath);
        self::assertSame($p1, $firstReadBytes);
        self::assertTrue(
            $rejected,
            'A provenance replacement after its first read must not authorize assembly.',
        );
        self::assertDirectoryDoesNotExist($output);
    }

    public function testProductionAssemblyRejectsUnknownOrUppercaseSchemaTwoProvenanceFields(): void
    {
        foreach (['root', 'component', 'uppercase-launcher-key'] as $mutation) {
            $provenance = $this->productionProvenance('strict-provenance-' . $mutation);
            $decoded = $this->json($provenance);
            if ($mutation === 'root') {
                $decoded['unapproved'] = true;
            } elseif ($mutation === 'component') {
                $decoded['components']['nginx']['unapproved'] = true;
            } else {
                $decoded['components']['wls-gateway-launcher']
                    ['embedded_release_public_key_hex'] = \strtoupper(
                        (string)$decoded['components']['wls-gateway-launcher']
                            ['embedded_release_public_key_hex'],
                    );
            }
            self::assertNotFalse(\file_put_contents(
                $provenance,
                \json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL,
            ));
            $output = $this->root . DIRECTORY_SEPARATOR . 'strict-provenance-' . $mutation;
            try {
                (new \WlsGatewayPackageBuilder())->build(\array_replace(
                    $this->options($output, 'production'),
                    [
                        'provenance' => $provenance,
                        'approved-provenance-sha256' => \hash_file('sha256', $provenance),
                    ],
                ));
                self::fail('Production assembly must reject the ' . $mutation . ' provenance mutation.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('provenance', \strtolower($exception->getMessage()));
            }
            self::assertDirectoryDoesNotExist($output);
        }
    }

    public function testUnsignedCandidateRejectsProvenanceLauncherKeyThatDiffersFromManifest(): void
    {
        $fixture = $this->productionSigningFixture('provenance-manifest-launcher-key-split');
        $provenanceFile = $fixture['package'] . DIRECTORY_SEPARATOR . 'provenance.json';
        $provenance = $this->json($provenanceFile);
        $provenance['components']['wls-gateway-launcher']['embedded_release_public_key_hex']
            = \str_repeat('1', 64);
        if ($provenance['components']['wls-gateway-launcher']['embedded_release_public_key_hex']
            === (string)$this->json($fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json')
                ['launcher_embedded_release_public_key_hex']) {
            $provenance['components']['wls-gateway-launcher']['embedded_release_public_key_hex']
                = \str_repeat('2', 64);
        }
        $provenanceBytes = \json_encode(
            $provenance,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        self::assertNotFalse(\file_put_contents($provenanceFile, $provenanceBytes));
        $manifestFile = $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = $this->json($manifestFile);
        $manifest['components']['provenance.json']['sha256'] = \hash('sha256', $provenanceBytes);
        $manifest['components']['provenance.json']['size'] = \strlen($provenanceBytes);
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        self::assertTrue(\unlink($fixture['audit_receipt']));
        $rejected = false;
        try {
            $fixture['signer']->audit([
                'package' => $fixture['package'],
                'receipt-output' => $fixture['audit_receipt'],
                'expected-provenance-sha256' => \hash_file('sha256', $provenanceFile),
            ]);
        } catch (\RuntimeException $exception) {
            $rejected = true;
            self::assertStringContainsString('provenance', \strtolower($exception->getMessage()));
        }
        self::assertTrue($rejected, 'A K1 provenance/manifest split must not reach audit or signing.');
    }

    public function testTestProfileKeepsZeroEmbeddedLauncherKeySelfTestsCompatible(): void
    {
        $this->inputs['wls-gateway-launcher'] = $this->executable(
            'wls-gateway-launcher',
            '--self-test',
            \str_repeat('0', 64),
        );
        $output = $this->root . DIRECTORY_SEPARATOR . 'zero-key-test-profile';

        $result = (new \WlsGatewayPackageBuilder())->build($this->options(
            $output,
            'test',
        ));

        self::assertTrue($result['ok']);
        self::assertDirectoryExists($output);
    }

    public function testManifestSchemaLocksStableLauncherProofCapability(): void
    {
        $schema = $this->json(
            \dirname(__DIR__, 5)
                . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'gateway'
                . DIRECTORY_SEPARATOR . 'package-manifest.schema.json',
        );
        $capabilities = $schema['properties']['capabilities'];

        self::assertContains(
            'stable_launcher_rollback_target_proof',
            $capabilities['required'],
        );
        self::assertContains(
            'physical_rebootstrap_capacity_reserve',
            $capabilities['required'],
        );
        self::assertSame(
            ['const' => true],
            $capabilities['properties']['stable_launcher_rollback_target_proof'],
        );
        self::assertSame(
            ['const' => true],
            $capabilities['properties']['physical_rebootstrap_capacity_reserve'],
        );
        self::assertFalse($capabilities['additionalProperties']);

        $schemaRequired = $capabilities['required'];
        $signerRequired = (new \ReflectionClass(\WlsGatewayPackageSigner::class))
            ->getReflectionConstant('REQUIRED_CAPABILITIES');
        $managerRequired = (new \ReflectionClass(HostGatewayPackageManager::class))
            ->getReflectionConstant('REQUIRED_CAPABILITIES');
        self::assertInstanceOf(\ReflectionClassConstant::class, $signerRequired);
        self::assertInstanceOf(\ReflectionClassConstant::class, $managerRequired);
        $signerRequired = $signerRequired->getValue();
        $managerRequired = $managerRequired->getValue();
        self::assertIsArray($signerRequired);
        self::assertIsArray($managerRequired);
        \sort($schemaRequired, SORT_STRING);
        \sort($signerRequired, SORT_STRING);
        \sort($managerRequired, SORT_STRING);
        self::assertSame($schemaRequired, $signerRequired);
        self::assertSame($schemaRequired, $managerRequired);
    }

    public function testWindowsManifestLocksNativeNamedPipeDeadlineTransport(): void
    {
        $schema = $this->json(
            \dirname(__DIR__, 5)
                . DIRECTORY_SEPARATOR . 'env'
                . DIRECTORY_SEPARATOR . 'gateway'
                . DIRECTORY_SEPARATOR . 'package-manifest.schema.json',
        );
        $windowsRule = $schema['allOf'][0]['then']['properties']['capabilities'];
        self::assertContains(
            'windows_named_pipe_deadline_transport',
            $windowsRule['required'],
        );
        self::assertSame(
            ['const' => true],
            $windowsRule['properties']['windows_named_pipe_deadline_transport'],
        );

        $builder = (string)\file_get_contents(
            \dirname(__DIR__, 9) . '/dev/tools/wls-gateway-package.php',
        );
        self::assertStringContainsString(
            "\$capabilities['windows_named_pipe_deadline_transport'] = true;",
            $builder,
        );
        self::assertStringContainsString(
            "\$expectedCapabilities[] = 'windows_named_pipe_deadline_transport';",
            $builder,
        );
        self::assertStringContainsString(
            "'--pipe-deadline-self-test'",
            $builder,
        );
    }

    public function testReleaseAuditRejectsNonExactDurableStateContract(): void
    {
        $fixture = $this->productionSigningFixture('durable-contract-tamper');
        $manifestFile = $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = $this->json($manifestFile);
        $manifest['durable_state_contract']['security_ledger_read_schema'] = 6;
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        $receipt = $this->root . DIRECTORY_SEPARATOR
            . 'durable-contract-tamper-second-audit.json';

        try {
            $fixture['signer']->audit([
                'package' => $fixture['package'],
                'receipt-output' => $receipt,
            ]);
            self::fail('The release auditor must reject a non-v2 durable-state contract.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'unsigned production candidate',
                $exception->getMessage(),
            );
        }
        self::assertFileDoesNotExist($receipt);
    }

    public function testAuditorAndSignerRejectStableLauncherProofDowngrades(): void
    {
        $fixture = $this->productionSigningFixture('launcher-proof-downgrade');
        $manifestFile = $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json';
        $original = $this->json($manifestFile);
        $mutations = [
            'missing' => static function (array &$manifest): void {
                unset($manifest['capabilities']['stable_launcher_rollback_target_proof']);
            },
            'false' => static function (array &$manifest): void {
                $manifest['capabilities']['stable_launcher_rollback_target_proof'] = false;
            },
            'type-wrong' => static function (array &$manifest): void {
                $manifest['capabilities']['stable_launcher_rollback_target_proof'] = 'true';
            },
            'unknown-downgrade' => static function (array &$manifest): void {
                $manifest['capabilities']['stable_launcher_rollback_target_proof_v1'] = true;
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $manifest = $original;
            $mutate($manifest);
            self::assertNotFalse(\file_put_contents(
                $manifestFile,
                \json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL,
            ));
            $expected = $name === 'unknown-downgrade'
                ? 'capability topology'
                : 'lacks capability: stable_launcher_rollback_target_proof';
            $receipt = $this->root . DIRECTORY_SEPARATOR
                . 'launcher-proof-' . $name . '.audit.json';

            foreach (['audit', 'sign'] as $operation) {
                try {
                    if ($operation === 'audit') {
                        $fixture['signer']->audit([
                            'package' => $fixture['package'],
                            'receipt-output' => $receipt,
                        ]);
                    } else {
                        $fixture['signer']->sign($fixture['sign_options']);
                    }
                    self::fail(
                        $operation . ' must reject stable-launcher proof mutation: ' . $name,
                    );
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString(
                        $expected,
                        $exception->getMessage(),
                        $operation . ' accepted mutation: ' . $name,
                    );
                }
            }
            self::assertFileDoesNotExist($receipt);
        }

        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            $fixture['unsigned_manifest'],
        ));
    }

    public function testProductionPackageRequiresTrustedKeyAndSelfContainedProvenance(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \sodium_crypto_sign_secretkey($keyPair);
        $public = \sodium_crypto_sign_publickey($keyPair);
        $this->releasePublicKeyHex = \bin2hex($public);
        $this->inputs['wls-gateway-launcher'] = $this->executable(
            'wls-gateway-launcher',
            '--self-test',
        );
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
                'schema_version' => 2,
                'keys' => [[
                    'id' => 'fixture-release-key',
                    'algorithm' => 'ed25519',
                    'enabled' => true,
                    'public_key_base64' => \base64_encode($public),
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        $provenance = $this->root . DIRECTORY_SEPARATOR . 'provenance.json';
        $definitions = [];
        foreach ([
            'controller',
            'ca-bundle',
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
            if ($name === 'wls-gateway-launcher') {
                $definitions[$name]['embedded_release_public_key_hex']
                    = $this->releasePublicKeyHex;
            }
        }
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 2,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));

        $output = $this->root . DIRECTORY_SEPARATOR . 'production-package';
        $options = $this->options($output, 'production') + [
            'provenance' => $provenance,
            'approved-provenance-sha256' => \hash_file('sha256', $provenance),
        ];
        $result = (new \WlsGatewayPackageBuilder())->build($options);
        self::assertFalse($result['release_ready']);
        self::assertTrue($result['production_candidate']);
        self::assertSame(
            (string)\file_get_contents($provenance),
            (string)\file_get_contents($output . DIRECTORY_SEPARATOR . 'provenance.json'),
            'Production assembly must package the exact approved provenance bytes.',
        );
        self::assertSame(
            \hash_file('sha256', $provenance),
            \hash_file('sha256', $output . DIRECTORY_SEPARATOR . 'provenance.json'),
        );
        self::assertFileDoesNotExist($output . DIRECTORY_SEPARATOR . 'manifest.sig');
        $executionMarker = $this->inputs['wls-gateway-launcher'] . '.executed';
        self::assertFileExists($executionMarker);
        self::assertSame(
            "--self-test\n--rollback-target-proof-self-test\n--recovery-ledger-self-test\n--capacity-reserve-contract-self-test\n",
            (string)\file_get_contents($executionMarker),
        );
        self::assertTrue(
            $this->json($output . DIRECTORY_SEPARATOR . 'manifest.json')
                ['capabilities']['stable_launcher_rollback_target_proof'],
        );
        self::assertTrue(\unlink($executionMarker));
        $auditReceipt = $this->root . DIRECTORY_SEPARATOR
            . 'production-package-audit.json';
        $signer = new \WlsGatewayPackageSigner();
        $audit = $signer->audit([
            'package' => $output,
            'receipt-output' => $auditReceipt,
            'expected-provenance-sha256' => \hash_file(
                'sha256',
                $output . DIRECTORY_SEPARATOR . 'provenance.json',
            ),
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
                'expected-provenance-sha256' => \hash_file(
                    'sha256',
                    $output . DIRECTORY_SEPARATOR . 'provenance.json',
                ),
                'expected-provenance-sha256' => \hash_file(
                    'sha256',
                    $output . DIRECTORY_SEPARATOR . 'provenance.json',
                ),
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
        $manifestFile = $output . DIRECTORY_SEPARATOR . 'manifest.json';
        $unsignedManifestBytes = (string)\file_get_contents($manifestFile);
        $modeTamperedManifest = $this->json($manifestFile);
        $phpComponent = \PHP_OS_FAMILY === 'Windows' ? 'bin/php.exe' : 'bin/php';
        self::assertSame(0755, $modeTamperedManifest['components'][$phpComponent]['mode']);
        $originalDigest = $modeTamperedManifest['components'][$phpComponent]['sha256'];
        $modeTamperedManifest['components'][$phpComponent]['mode'] = 0400;
        self::assertSame(
            $originalDigest,
            $modeTamperedManifest['components'][$phpComponent]['sha256'],
            'The tampered candidate preserves the component bytes and hash.',
        );
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $modeTamperedManifest,
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
            self::fail('A non-executable declared bin component must not be signed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'locked release mode',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents($manifestFile, $unsignedManifestBytes));
        $signed = $signer->sign([
            'package' => $output,
            'audit-receipt' => $auditReceipt,
            'expected-audit-environment-sha256' => $auditEnvironment,
            'expected-provenance-sha256' => \hash_file(
                'sha256',
                $output . DIRECTORY_SEPARATOR . 'provenance.json',
            ),
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

        $modeTamperedManifest = $this->json($manifestFile);
        self::assertSame(0755, $modeTamperedManifest['components'][$phpComponent]['mode']);
        $modeTamperedManifest['components'][$phpComponent]['mode'] = 0400;
        $modeTamperedBytes = \json_encode(
            $modeTamperedManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        self::assertNotFalse(\file_put_contents($manifestFile, $modeTamperedBytes));
        self::assertNotFalse(\file_put_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.sig',
            \base64_encode(\sodium_crypto_sign_detached($modeTamperedBytes, $secret)) . PHP_EOL,
        ));
        try {
            (new HostGatewayPackageManager(
                trustedKeysFile: $trustedKeys,
            ))->verifyPackage($output, 'default');
            self::fail('A validly signed package with a non-executable declared bin component must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'component verification failed',
                $exception->getMessage(),
            );
        }

        $sbomMismatchOutput = $this->root . DIRECTORY_SEPARATOR . 'sbom-mismatch';
        (new \WlsGatewayPackageBuilder())->build(
            $this->options($sbomMismatchOutput, 'production') + [
                'provenance' => $provenance,
                'approved-provenance-sha256' => \hash_file('sha256', $provenance),
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
                'schema_version' => 2,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        $rejectedOutput = $this->root . DIRECTORY_SEPARATOR . 'rejected-package';
        try {
            (new \WlsGatewayPackageBuilder())->build(
                $this->options($rejectedOutput, 'production') + [
                    'provenance' => $provenance,
                    'approved-provenance-sha256' => \hash_file('sha256', $provenance),
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

    public function testSignerRejectsCandidateWhoseEmbeddedLauncherKeyDiffersFromSigningKey(): void
    {
        $embeddedKey = \bin2hex(\sodium_crypto_sign_publickey(
            \sodium_crypto_sign_keypair(),
        ));
        $fixture = $this->productionSigningFixture(
            'embedded-launcher-signing-key-mismatch',
            $embeddedKey,
        );

        try {
            $fixture['signer']->sign($fixture['sign_options']);
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'embedded release public key',
                $exception->getMessage(),
            );
            return;
        }
        self::fail(
            'A production candidate whose Launcher embeds K1 must not be signed with a trusted K2.',
        );
    }

    public function testLauncherVerifierChallengeIsDiagnosticRatherThanSigningAuthority(): void
    {
        $fixture = $this->productionSigningFixture(
            'launcher-self-report-challenge-mismatch',
            null,
            \str_repeat('1', 64),
        );

        $signed = $fixture['signer']->sign($fixture['sign_options']);
        self::assertTrue($signed['release_ready']);
    }

    public function testProductionEmbeddedLauncherKeyClaimFailsClosedWhenInvalidOrTampered(): void
    {
        foreach (['absent', 'malformed', 'tampered'] as $case) {
            $fixture = $this->productionSigningFixture(
                'embedded-launcher-key-claim-' . $case,
            );
            $manifestFile = $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json';
            $manifest = $this->json($manifestFile);
            if ($case === 'absent') {
                unset($manifest['launcher_embedded_release_public_key_hex']);
            } elseif ($case === 'malformed') {
                $manifest['launcher_embedded_release_public_key_hex'] = 'not-a-key';
            } else {
                $claim = (string)$manifest['launcher_embedded_release_public_key_hex'];
                $manifest['launcher_embedded_release_public_key_hex']
                    = ($claim[0] === '1' ? '2' : '1') . \substr($claim, 1);
            }
            self::assertNotFalse(\file_put_contents(
                $manifestFile,
                \json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ) . PHP_EOL,
            ));

            try {
                $fixture['signer']->audit([
                    'package' => $fixture['package'],
                    'receipt-output' => $this->root . DIRECTORY_SEPARATOR
                        . 'embedded-launcher-key-claim-' . $case . '.audit.json',
                ]);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    $case === 'tampered'
                        ? 'Production provenance changed or is incomplete: wls-gateway-launcher'
                        : 'unsigned production candidate',
                    $exception->getMessage(),
                );
                continue;
            }
            self::fail('An invalid or tampered Launcher key claim must not be auditable.');
        }
    }

    public function testWindowsProductionManifestRequiresANonzeroLauncherKeyClaim(): void
    {
        $validator = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'launcherEmbeddedReleaseKeyClaimValid',
        );
        $signer = new \WlsGatewayPackageSigner();

        self::assertTrue($validator->invoke($signer, [
            'platform' => 'Windows',
            'launcher_embedded_release_public_key_hex' => \str_repeat('1', 64),
        ]));
        self::assertFalse($validator->invoke($signer, [
            'platform' => 'Windows',
            'launcher_embedded_release_public_key_hex' => '',
        ]));
        self::assertFalse($validator->invoke($signer, [
            'platform' => 'Windows',
            'launcher_embedded_release_public_key_hex' => \str_repeat('0', 64),
        ]));
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

    public function testSignerReconcilesCrashPointsAndRecognizesCompletedTransaction(): void
    {
        $fixture = $this->productionSigningFixture('transaction-crash-points');
        $signer = $fixture['signer'];
        $options = $fixture['sign_options'];
        $package = $fixture['package'];
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $active = $package . '.signing-transaction';
        $complete = $package . '.signing-complete';

        $signer->sign($options);
        $completedManifest = (string)\file_get_contents($manifestFile);
        $completedSignature = (string)\file_get_contents($signatureFile);
        self::assertDirectoryExists($complete);
        $recognized = $signer->sign($options);
        self::assertTrue($recognized['release_ready']);
        self::assertSame($completedManifest, (string)\file_get_contents($manifestFile));
        self::assertSame($completedSignature, (string)\file_get_contents($signatureFile));

        self::assertTrue(\rename($complete, $active));
        $this->tamperAuditReceipt($fixture['audit_receipt']);
        try {
            $signer->sign($options);
            self::fail('A crash after manifest publication must roll back before revalidation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('receipt does not match', $exception->getMessage());
        }
        self::assertSame($fixture['unsigned_manifest'], (string)\file_get_contents($manifestFile));
        self::assertFileDoesNotExist($signatureFile);
        self::assertDirectoryDoesNotExist($active);
        self::assertDirectoryDoesNotExist($complete);

        self::assertNotFalse(\file_put_contents(
            $fixture['audit_receipt'],
            $fixture['audit_receipt_bytes'],
        ));
        $signer->sign($options);
        self::assertTrue(\rename($complete, $active));
        self::assertTrue(\rename(
            $manifestFile,
            $active . DIRECTORY_SEPARATOR . 'signed-manifest.json',
        ));
        self::assertTrue(\rename(
            $active . DIRECTORY_SEPARATOR . 'quarantine'
                . DIRECTORY_SEPARATOR . 'original-unsigned-manifest.json',
            $manifestFile,
        ));
        $this->tamperAuditReceipt($fixture['audit_receipt']);
        try {
            $signer->sign($options);
            self::fail('A crash after signature publication must roll back before revalidation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('receipt does not match', $exception->getMessage());
        }
        self::assertSame($fixture['unsigned_manifest'], (string)\file_get_contents($manifestFile));
        self::assertFileDoesNotExist($signatureFile);
        self::assertDirectoryDoesNotExist($active);
    }

    public function testActiveSigningRecoveryRejectsAProofBoundDifferentLauncherKey(): void
    {
        $fixture = $this->productionSigningFixture('active-proof-launcher-key-mismatch');
        $signer = $fixture['signer'];
        $package = $fixture['package'];
        $complete = $package . '.signing-complete';
        $active = $package . '.signing-transaction';

        $signer->sign($fixture['sign_options']);
        self::assertTrue(\rename($complete, $active));
        $recordFile = $active . DIRECTORY_SEPARATOR . 'record.json';
        $record = $this->json($recordFile);
        $record['launcher_embedded_release_public_key_hex'] = \str_repeat('1', 64);
        if ($record['launcher_embedded_release_public_key_hex']
            === (string)$this->json($package . DIRECTORY_SEPARATOR . 'manifest.json')
                ['launcher_embedded_release_public_key_hex']) {
            $record['launcher_embedded_release_public_key_hex'] = \str_repeat('2', 64);
        }
        $payload = $record;
        unset($payload['proof_signature_base64']);
        $secret = \base64_decode(
            \trim((string)\file_get_contents(
                $fixture['sign_options']['signing-key-file'],
            )),
            true,
        );
        self::assertIsString($secret);
        $transactionJson = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'signingTransactionJson',
        );
        $record['proof_signature_base64'] = \base64_encode(
            \sodium_crypto_sign_detached(
                $transactionJson->invoke($signer, $payload),
                $secret,
            ),
        );
        $recordBytes = $transactionJson->invoke($signer, $record);
        \sodium_memzero($secret);
        self::assertNotFalse(\file_put_contents($recordFile, $recordBytes));

        try {
            $signer->sign($fixture['sign_options']);
            self::fail('Active recovery must reject a K2-signed proof whose K1 differs from its candidate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('provenance proof', $exception->getMessage());
        }
        self::assertDirectoryExists($active);
    }

    public function testSignerFailsClosedForUnprovedReservedTransactionState(): void
    {
        $fixture = $this->productionSigningFixture('reserved-transaction-state');
        $reserved = $fixture['package'] . '.signing-transaction';
        self::assertTrue(\mkdir($reserved, 0700));
        self::assertNotFalse(\file_put_contents(
            $reserved . DIRECTORY_SEPARATOR . 'signed-manifest.json',
            "unproved\n",
        ));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('Reserved signing state without a valid transaction proof must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('transaction proof', $exception->getMessage());
        }
        self::assertSame(
            $fixture['unsigned_manifest'],
            (string)\file_get_contents(
                $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json',
            ),
        );
        self::assertFileExists($reserved . DIRECTORY_SEPARATOR . 'signed-manifest.json');
    }

    public function testWindowsBootstrapRollbackRequiresExactTransactionOwnershipProof(): void
    {
        $remove = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'removeWindowsBootstrapBundle',
        );
        $expected = [
            'wls-bounded-command.exe' => ['bytes' => "helper\n", 'mode' => 0755],
            'wls-bounded-command.manifest.json' => ['bytes' => "manifest\n", 'mode' => 0644],
            'wls-bounded-command.manifest.sig' => ['bytes' => "signature\n", 'mode' => 0644],
        ];
        $signer = new \WlsGatewayPackageSigner();
        $owned = $this->windowsBootstrapFixture(
            '.owned-bootstrap.signing-quarantine-' . \str_repeat('a', 32),
            $expected,
        );
        $ownedIdentities = $this->windowsBootstrapIdentities($signer, $owned);
        $remove->invoke($signer, $owned, $expected, $ownedIdentities);
        self::assertDirectoryDoesNotExist($owned);

        $unowned = $this->windowsBootstrapFixture(
            '.unowned-bootstrap.signing-quarantine-' . \str_repeat('b', 32),
            $expected,
        );
        $unownedIdentities = $this->windowsBootstrapIdentities($signer, $unowned);
        self::assertTrue(\unlink(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
        ));
        self::assertNotFalse(\file_put_contents(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
            "arbitrary-pre-existing-output\n",
        ));
        self::assertTrue(\chmod(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
            0755,
        ));
        try {
            $remove->invoke($signer, $unowned, $expected, $unownedIdentities);
            self::fail('Rollback must not delete a bootstrap directory without exact ownership proof.');
        } catch (\RuntimeException $exception) {
            self::assertTrue(
                \str_contains($exception->getMessage(), 'identity changed')
                    || \str_contains($exception->getMessage(), 'ownership proof'),
            );
        }
        self::assertDirectoryExists($unowned);
        self::assertFileExists(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
        );
    }

    public function testSignerLockRejectsConcurrentInterleavingForSamePackage(): void
    {
        $fixture = $this->productionSigningFixture('exclusive-signing-lock');
        $acquire = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'acquireSigningLock',
        );
        $release = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'releaseSigningLock',
        );
        $first = $acquire->invoke($fixture['signer'], $fixture['package']);
        try {
            $acquire->invoke($fixture['signer'], $fixture['package']);
            self::fail('A second signer must not interleave while the package lock is held.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already locked', $exception->getMessage());
        } finally {
            $release->invoke($fixture['signer'], $first);
        }
    }

    public function testSignerLockRootIsPrivateAndOutsideCandidateWritableNamespace(): void
    {
        $fixture = $this->productionSigningFixture('protected-signing-lock-root');
        $legacySiblingLock = $fixture['package'] . '.signing.lock';
        self::assertNotFalse(\file_put_contents($legacySiblingLock, "candidate-owned\n"));
        self::assertTrue(\chmod($this->root, 0777));
        $acquire = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'acquireSigningLock',
        );
        $release = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'releaseSigningLock',
        );

        try {
            $lock = $acquire->invoke($fixture['signer'], $fixture['package']);
            try {
                $lockPath = (string)$lock['path'];
                $lockRoot = \dirname($lockPath);
                self::assertNotSame($legacySiblingLock, $lockPath);
                self::assertFalse(\str_starts_with(
                    \str_replace('\\', '/', $lockRoot) . '/',
                    \str_replace('\\', '/', $this->root) . '/',
                ));
                $rootStatus = \lstat($lockRoot);
                self::assertIsArray($rootStatus);
                self::assertSame(0700, ((int)$rootStatus['mode']) & 0777);
                if (\function_exists('posix_geteuid')) {
                    self::assertSame((int)\posix_geteuid(), (int)$rootStatus['uid']);
                }
                self::assertSame(
                    "candidate-owned\n",
                    (string)\file_get_contents($legacySiblingLock),
                );
            } finally {
                $release->invoke($fixture['signer'], $lock);
            }
        } finally {
            self::assertTrue(\chmod($this->root, 0700));
        }
    }

    public function testSignerRejectsGroupReadableSecretKeyFile(): void
    {
        $fixture = $this->productionSigningFixture('private-signing-key-policy');
        $secret = $fixture['sign_options']['signing-key-file'];
        self::assertTrue(\chmod($secret, 0640));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('A group-readable release signing key must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('signing key permissions', $exception->getMessage());
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testSignerRejectsActualComponentModeThatContradictsManifest(): void
    {
        $fixture = $this->productionSigningFixture('actual-component-mode-policy');
        $php = $fixture['package'] . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'php';
        self::assertTrue(\chmod($php, 0777));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('A writable executable must not be signed under a declared 0755 mode.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('component mode or owner', $exception->getMessage());
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testEarliestCrashLeavesOnlyRandomPrivateStagingAndDoesNotReserveActiveState(): void
    {
        $fixture = $this->productionSigningFixture('earliest-staging-crash');
        $orphan = $fixture['package'] . '.signing-stage-' . \bin2hex(\random_bytes(16));
        self::assertTrue(\mkdir($orphan, 0700));
        self::assertNotFalse(\file_put_contents(
            $orphan . DIRECTORY_SEPARATOR . 'partial-record.json',
            "partial\n",
        ));

        $signed = $fixture['signer']->sign($fixture['sign_options']);
        self::assertTrue($signed['release_ready']);
        self::assertDirectoryExists($orphan);
        self::assertDirectoryDoesNotExist($fixture['package'] . '.signing-transaction');
        self::assertDirectoryExists($fixture['package'] . '.signing-complete');
    }

    public function testSignerRecoversProofBoundAbandonedStageBeforeNewSigning(): void
    {
        $fixture = $this->productionSigningFixture('proof-bound-abandoned-stage');
        $fixture['signer']->sign($fixture['sign_options']);
        $package = $fixture['package'];
        $complete = $package . '.signing-complete';
        $record = $this->json($complete . DIRECTORY_SEPARATOR . 'record.json');
        $stage = $package . '.signing-stage-' . (string)$record['transaction_id'];
        $manifest = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signature = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        self::assertTrue(\rename(
            $manifest,
            $complete . DIRECTORY_SEPARATOR . 'signed-manifest.json',
        ));
        self::assertTrue(\rename(
            $signature,
            $complete . DIRECTORY_SEPARATOR . 'manifest.sig',
        ));
        self::assertTrue(\rename(
            $complete . DIRECTORY_SEPARATOR . 'quarantine'
                . DIRECTORY_SEPARATOR . 'original-unsigned-manifest.json',
            $manifest,
        ));
        self::assertTrue(\rename($complete, $stage));

        $signed = $fixture['signer']->sign($fixture['sign_options']);

        self::assertTrue($signed['release_ready']);
        self::assertDirectoryDoesNotExist($stage);
        self::assertDirectoryExists($package . '.signing-complete');
    }

    public function testUnprovedAbandonedStagesAreRetainedButBounded(): void
    {
        $fixture = $this->productionSigningFixture('bounded-unproved-staging');
        $stages = [];
        for ($index = 0; $index < 9; $index++) {
            $stage = $fixture['package'] . '.signing-stage-'
                . \str_pad(\dechex($index + 1), 32, '0', STR_PAD_LEFT);
            self::assertTrue(\mkdir($stage, 0700));
            self::assertNotFalse(\file_put_contents(
                $stage . DIRECTORY_SEPARATOR . 'unproved',
                "unproved\n",
            ));
            $stages[] = $stage;
        }

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('Unbounded unproved signing staging must stop new release publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('abandoned signing-stage bound', $exception->getMessage());
        }
        foreach ($stages as $stage) {
            self::assertDirectoryExists($stage);
            self::assertFileExists($stage . DIRECTORY_SEPARATOR . 'unproved');
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testActiveArtifactMismatchIsQuarantinedWithoutDeletion(): void
    {
        $fixture = $this->productionSigningFixture('active-proof-mismatch');
        $fixture['signer']->sign($fixture['sign_options']);
        $active = $fixture['package'] . '.signing-transaction';
        $complete = $fixture['package'] . '.signing-complete';
        $quarantine = $fixture['package'] . '.signing-quarantine';
        self::assertTrue(\rename($complete, $active));
        $rollback = $active . DIRECTORY_SEPARATOR . 'unsigned-manifest.json';
        self::assertNotFalse(\file_put_contents($rollback, "replacement\n"));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('An active artifact replacement must be isolated and reported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quarantined', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($active);
        self::assertDirectoryExists($quarantine);
        self::assertSame(
            "replacement\n",
            (string)\file_get_contents(
                $quarantine . DIRECTORY_SEPARATOR . 'unsigned-manifest.json',
            ),
        );
    }

    public function testQuarantineDetectsReplacementBetweenIdentityCheckAndRename(): void
    {
        $signer = new \WlsGatewayPackageSigner();
        $identityMethod = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'securePathIdentity',
        );
        $quarantineMethod = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'quarantineExactFile',
        );
        $source = $this->root . DIRECTORY_SEPARATOR . 'toctou-source';
        $quarantineDirectory = $this->root . DIRECTORY_SEPARATOR . 'toctou-quarantine';
        self::assertNotFalse(\file_put_contents($source, "owned\n"));
        self::assertTrue(\chmod($source, 0600));
        self::assertTrue(\mkdir($quarantineDirectory, 0700));
        $sourceIdentity = $identityMethod->invoke($signer, $source, false, 0600);
        $quarantineIdentity = $identityMethod->invoke(
            $signer,
            $quarantineDirectory,
            true,
            0700,
        );
        self::assertTrue(\unlink($source));
        self::assertNotFalse(\file_put_contents($source, "replacement\n"));
        self::assertTrue(\chmod($source, 0600));

        try {
            $quarantineMethod->invoke(
                $signer,
                $source,
                $quarantineDirectory . DIRECTORY_SEPARATOR . 'owned',
                "owned\n",
                $sourceIdentity,
                $quarantineIdentity,
                0600,
                'TOCTOU fixture',
            );
            self::fail('A replacement must be detected before it can be moved or deleted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('identity changed', $exception->getMessage());
        }
        self::assertSame("replacement\n", (string)\file_get_contents($source));
        self::assertFileDoesNotExist(
            $quarantineDirectory . DIRECTORY_SEPARATOR . 'owned',
        );
    }

    public function testWindowsBootstrapStagingAndQuarantineStayInTargetVolumeParent(): void
    {
        $signer = new \WlsGatewayPackageSigner();
        $bootstrapPaths = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'bootstrapTransactionPaths',
        );
        $packageParent = $this->root . DIRECTORY_SEPARATOR . 'package-volume';
        $outputParent = $this->root . DIRECTORY_SEPARATOR . 'bootstrap-volume';
        self::assertTrue(\mkdir($packageParent, 0700));
        self::assertTrue(\mkdir($outputParent, 0700));
        $package = $packageParent . DIRECTORY_SEPARATOR . 'package';
        $output = $outputParent . DIRECTORY_SEPARATOR . 'bootstrap';
        $transactionId = \str_repeat('a', 32);
        $paths = $bootstrapPaths->invoke($signer, $output, $transactionId);

        self::assertSame($outputParent, \dirname($paths['stage']));
        self::assertSame($outputParent, \dirname($paths['quarantine']));
        self::assertNotSame(\dirname($package), \dirname($paths['stage']));
        self::assertStringContainsString($transactionId, \basename($paths['stage']));
        self::assertStringContainsString($transactionId, \basename($paths['quarantine']));
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

    private function certificateAuthority(): string
    {
        $config = $this->root . DIRECTORY_SEPARATOR . 'openssl-ca.cnf';
        self::assertNotFalse(\file_put_contents(
            $config,
            <<<'CONFIG'
[ req ]
distinguished_name = req_distinguished_name
prompt = no
x509_extensions = v3_ca

[ req_distinguished_name ]
CN = WLS Gateway Package Test Root
O = Weline Test

[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
CONFIG
                . PHP_EOL,
        ));
        $options = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'v3_ca',
        ];
        $key = \openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = \openssl_csr_new([], $key, $options);
        self::assertNotFalse($csr);
        $certificate = \openssl_csr_sign(
            $csr,
            null,
            $key,
            3650,
            $options,
            1,
        );
        self::assertNotFalse($certificate);
        $pem = '';
        self::assertTrue(\openssl_x509_export($certificate, $pem, true));
        return \rtrim($pem) . "\n";
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
            'release-public-key-hex' => $this->releasePublicKeyHex,
        ];
    }

    private function productionProvenance(string $name): string
    {
        $definitions = [];
        foreach ([
            'controller',
            'ca-bundle',
            'php',
            'nginx',
            'wls-gateway-broker',
            'wls-gateway-launcher',
        ] as $component) {
            $path = $this->inputs[$component];
            $definitions[$component] = [
                'version' => 'fixture-1',
                'source_url' => 'https://example.invalid/' . $component,
                'source_sha256' => \hash_file('sha256', $path),
                'binary_sha256' => \hash_file('sha256', $path),
                'license' => 'test-only',
                'self_contained' => $component !== 'controller',
            ];
            if ($component === 'wls-gateway-launcher') {
                $definitions[$component]['embedded_release_public_key_hex']
                    = $this->releasePublicKeyHex;
            }
        }
        $provenance = $this->root . DIRECTORY_SEPARATOR . $name . '.provenance.json';
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 2,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        return $provenance;
    }

    private function executable(
        string $name,
        string $expectedArgument,
        ?string $releasePublicKeyHex = null,
        ?string $releaseVerifierKeyHex = null,
    ): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        $source = $path . '.c';
        $nameLiteral = \json_encode($name, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedLiteral = \json_encode(
            $expectedArgument,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $releasePublicKeyLiteral = \json_encode(
            $releasePublicKeyHex ?? $this->releasePublicKeyHex,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $releaseVerifierKeyLiteral = \json_encode(
            $releaseVerifierKeyHex ?? $releasePublicKeyHex ?? $this->releasePublicKeyHex,
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
#include <stdlib.h>
#include <string.h>

int main(int argc, char **argv) {
    const char *name = {$nameLiteral};
    const char *expected = {$expectedLiteral};
    const char *release_public_key = {$releasePublicKeyLiteral};
    const char *release_verifier_key = {$releaseVerifierKeyLiteral};
    if (strcmp(name, "php") == 0) {
        if (argc >= 2
            && (strcmp(argv[1], "-l") == 0 || strcmp(argv[1], "--version") == 0)) {
            return 0;
        }
        return argc == 3 && strcmp(argv[2], "--self-test") == 0 ? 0 : 1;
    }
    if (strcmp(name, "wls-gateway-launcher") == 0
        && strcmp(argv[1], "--release-signature-self-test") == 0) {
        const char *actual = getenv("WLS_FIXTURE_LAUNCHER_VERIFIER_KEY");
        if (argc != 4) return 1;
        if (actual == NULL || actual[0] == '\\0') actual = release_verifier_key;
        return strcmp(actual, release_public_key) == 0 ? 0 : 1;
    }
    if (argc != 2) {
        return 1;
    }
    if (strcmp(name, "wls-gateway-launcher") == 0
        && strcmp(argv[1], "--release-public-key-self-test") == 0) {
        return puts(release_public_key) < 0 ? 1 : 0;
    }
        if (strcmp(argv[1], expected) != 0
            && !(strcmp(name, "wls-gateway-launcher") == 0
            && (strcmp(argv[1], "--rollback-target-proof-self-test") == 0
                || strcmp(argv[1], "--recovery-ledger-self-test") == 0
                || strcmp(argv[1], "--capacity-reserve-contract-self-test") == 0))) {
        return 1;
    }
    FILE *marker = fopen({$markerLiteral}, "ab");
    if (marker != NULL) {
        fprintf(marker, "%s\\n", argv[1]);
        fclose(marker);
    }
    return 0;
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

    /**
     * @return array{
     *   signer:\WlsGatewayPackageSigner,
     *   sign_options:array<string,string>,
     *   package:string,
     *   unsigned_manifest:string,
     *   audit_receipt:string,
     *   audit_receipt_bytes:string
     * }
     */
    private function productionSigningFixture(
        string $name,
        ?string $embeddedLauncherKeyHex = null,
        ?string $releaseVerifierKeyHex = null,
    ): array
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \sodium_crypto_sign_secretkey($keyPair);
        $public = \sodium_crypto_sign_publickey($keyPair);
        $this->releasePublicKeyHex = $embeddedLauncherKeyHex ?? \bin2hex($public);
        $this->inputs['wls-gateway-launcher'] = $this->executable(
            'wls-gateway-launcher',
            '--self-test',
            null,
            $releaseVerifierKeyHex,
        );
        $secretFile = $this->root . DIRECTORY_SEPARATOR . $name . '.secret';
        self::assertNotFalse(\file_put_contents(
            $secretFile,
            \base64_encode($secret) . PHP_EOL,
        ));
        self::assertTrue(\chmod($secretFile, 0600));
        $trustedKeys = $this->root . DIRECTORY_SEPARATOR . $name . '.trusted.json';
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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        $definitions = [];
        foreach ([
            'controller',
            'ca-bundle',
            'php',
            'nginx',
            'wls-gateway-broker',
            'wls-gateway-launcher',
        ] as $component) {
            $path = $this->inputs[$component];
            $definitions[$component] = [
                'version' => 'fixture-1',
                'source_url' => 'https://example.invalid/' . $component,
                'source_sha256' => \hash_file('sha256', $path),
                'binary_sha256' => \hash_file('sha256', $path),
                'license' => 'test-only',
                'self_contained' => !\in_array(
                    $component,
                    ['controller', 'ca-bundle'],
                    true,
                ),
            ];
            if ($component === 'wls-gateway-launcher') {
                $definitions[$component]['embedded_release_public_key_hex']
                    = $this->releasePublicKeyHex;
            }
        }
        $provenance = $this->root . DIRECTORY_SEPARATOR . $name . '.provenance.json';
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 2,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        ));
        $package = $this->root . DIRECTORY_SEPARATOR . $name;
        (new \WlsGatewayPackageBuilder())->build(
            $this->options($package, 'production') + [
                'provenance' => $provenance,
                'approved-provenance-sha256' => \hash_file('sha256', $provenance),
            ],
        );
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $unsignedManifest = (string)\file_get_contents($manifestFile);
        $auditReceipt = $this->root . DIRECTORY_SEPARATOR . $name . '.audit.json';
        $signer = new \WlsGatewayPackageSigner();
        $audit = $signer->audit([
            'package' => $package,
            'receipt-output' => $auditReceipt,
            'expected-provenance-sha256' => \hash_file(
                'sha256',
                $package . DIRECTORY_SEPARATOR . 'provenance.json',
            ),
        ]);
        $signOptions = [
            'package' => $package,
            'audit-receipt' => $auditReceipt,
            'expected-audit-environment-sha256' => (string)$audit['audit_environment_sha256'],
            'expected-provenance-sha256' => \hash_file(
                'sha256',
                $package . DIRECTORY_SEPARATOR . 'provenance.json',
            ),
            'signing-key-id' => 'fixture-release-key',
            'signing-key-file' => $secretFile,
            'trusted-keys' => $trustedKeys,
        ];
        \sodium_memzero($secret);

        return [
            'signer' => $signer,
            'sign_options' => $signOptions,
            'package' => $package,
            'unsigned_manifest' => $unsignedManifest,
            'audit_receipt' => $auditReceipt,
            'audit_receipt_bytes' => (string)\file_get_contents($auditReceipt),
        ];
    }

    private function tamperAuditReceipt(string $file): void
    {
        $receipt = $this->json($file);
        $receipt['manifest_sha256'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $file,
            \json_encode(
                $receipt,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
    }

    /** @param array<string,array{bytes:string,mode:int}> $files */
    private function windowsBootstrapFixture(string $name, array $files): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir($directory, 0700));
        foreach ($files as $leaf => $definition) {
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            self::assertNotFalse(\file_put_contents($file, $definition['bytes']));
            self::assertTrue(\chmod($file, $definition['mode']));
        }
        return $directory;
    }

    /** @return array<string,array<string,int|string>> */
    private function windowsBootstrapIdentities(
        \WlsGatewayPackageSigner $signer,
        string $directory,
    ): array {
        $identity = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'securePathIdentity',
        );
        return [
            'bootstrap_parent' => $identity->invoke(
                $signer,
                \dirname($directory),
                true,
            ),
            'bootstrap_stage' => $identity->invoke($signer, $directory, true, 0700),
            'bootstrap_helper' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
                false,
                0755,
            ),
            'bootstrap_manifest' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.manifest.json',
                false,
                0644,
            ),
            'bootstrap_signature' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.manifest.sig',
                false,
                0644,
            ),
        ];
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
