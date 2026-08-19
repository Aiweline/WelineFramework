<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\Edge\Gateway\ServingManifestAuthorityTransitionException;

/**
 * Focused regression coverage for the only stale-manifest path that may
 * classify a monotonic project certificate-authority transition. Ordinary
 * listener consumers remain exact and every endpoint binding is checked
 * before the typed recovery signal can escape.
 */
final class ProjectServingManifestStartupRecoveryTest extends TestCase
{
    private const PROJECT_UUID = '123e4567-e89b-42d3-a456-426614174000';
    private const REQUEST_DIGEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DESIRED_DIGEST = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private string $root = '';

    protected function setUp(): void
    {
        if (!\function_exists('openssl_pkey_new')) {
            self::markTestSkipped('The OpenSSL extension is required for certificate authority tests.');
        }
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $this->root = $base . DIRECTORY_SEPARATOR . 'wls-serving-recovery-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl',
            0700,
            true,
        ));
        self::assertTrue(\mkdir(
            $this->root . DIRECTORY_SEPARATOR . 'var/server',
            0755,
            true,
        ));
        $canonical = \realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testActiveGenerationAdvanceIsTheOnlyTypedRebuildSignal(): void
    {
        $domain = 'advance.example.test';
        $generations = new ProjectCertificateGenerationStore($this->root);
        $first = $this->activate($generations, $domain, 'advance-one');
        $publication = $this->publishActive('advance', $domain, $first);
        $second = $this->activate($generations, $domain, 'advance-two');

        self::assertGreaterThan((int)$first['generation'], (int)$second['generation']);
        $transition = $this->captureTransition(
            fn (): array => $this->startupManifest('advance', $publication),
        );

        self::assertSame(
            ServingManifestAuthorityTransitionException::STALE_REBUILDABLE,
            $transition->transitionState(),
        );
        self::assertSame([$domain], $transition->activeDomains);
        self::assertSame('active_advanced', $transition->transitions[0]['reason'] ?? null);
        self::assertSame((int)$first['generation'], $transition->transitions[0]['manifest_generation'] ?? null);
        self::assertSame((int)$second['generation'], $transition->transitions[0]['authority_generation'] ?? null);
    }

    public function testAllTombstonedManifestCarriesNoActiveDomainForHttpOnlyRecovery(): void
    {
        $domains = [
            'retired-primary.example.test',
            'retired-sibling.example.test',
        ];
        $generations = new ProjectCertificateGenerationStore($this->root);
        $active = [];
        foreach ($domains as $index => $domain) {
            $active[$domain] = $this->activate(
                $generations,
                $domain,
                'retired-' . $index,
            );
        }
        $publication = (new ProjectServingManifestStore($this->root))
            ->publishFromRegistration($this->registration('retired', [
                $this->activeRoute($domains[0], $active[$domains[0]]),
                $this->activeRoute($domains[1], $active[$domains[1]]),
            ]));

        foreach ($domains as $domain) {
            $generations->deactivate($domain);
            $disabled = $generations->disabled($domain);
            self::assertIsArray($disabled);
            self::assertGreaterThan(
                (int)$active[$domain]['generation'],
                (int)$disabled['generation'],
            );
        }

        $transition = $this->captureTransition(
            fn (): array => $this->startupManifest('retired', $publication),
        );
        self::assertSame(
            ServingManifestAuthorityTransitionException::TOMBSTONED,
            $transition->transitionState(),
        );
        self::assertSame([], $transition->activeDomains);
        self::assertCount(2, $transition->transitions);
        self::assertSame(
            ['tombstoned', 'tombstoned'],
            \array_column($transition->transitions, 'reason'),
        );
    }

    public function testDisabledManifestRequiresExplicitReenableBeforeItCanRebuildTls(): void
    {
        $domain = 'reenabled.example.test';
        $generations = new ProjectCertificateGenerationStore($this->root);
        $first = $this->activate($generations, $domain, 'reenable-one');
        $generations->deactivate($domain);
        $disabled = $generations->disabled($domain);
        self::assertIsArray($disabled);

        $manifestStore = new ProjectServingManifestStore($this->root);
        $publication = $manifestStore->publishFromRegistration(
            $this->registration('reenabled', [
                $this->disabledRoute($domain, $disabled),
            ]),
        );
        $source = $this->createCertificate($domain, 'reenable-two');
        $second = $generations->withCertificateLifecycleLock(
            function () use ($generations, $domain, $source): array {
                $intent = $generations->issueExplicitReenableIntent(
                    $domain,
                    $source['cert'],
                    $source['key'],
                    '',
                    [$this->sslRoot()],
                    null,
                    ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
                    ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
                );
                self::assertTrue((bool)$intent['required']);

                return $generations->activate(
                    $domain,
                    $source['cert'],
                    $source['key'],
                    '',
                    [$this->sslRoot()],
                    null,
                    ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
                    ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
                );
            },
        );

        self::assertGreaterThan((int)$disabled['generation'], (int)$second['generation']);
        self::assertGreaterThan((int)$first['generation'], (int)$second['generation']);
        $transition = $this->captureTransition(
            fn (): array => $this->startupManifest('reenabled', $publication),
        );
        self::assertSame(
            ServingManifestAuthorityTransitionException::STALE_REBUILDABLE,
            $transition->transitionState(),
        );
        self::assertSame([$domain], $transition->activeDomains);
        self::assertSame('explicitly_reenabled', $transition->transitions[0]['reason'] ?? null);
    }

    public function testEndpointAndLaunchBindingsFailBeforeAdvancedAuthorityIsClassified(): void
    {
        $domain = 'binding.example.test';
        $generations = new ProjectCertificateGenerationStore($this->root);
        $first = $this->activate($generations, $domain, 'binding-one');
        $publication = $this->publishActive('binding', $domain, $first);
        $this->activate($generations, $domain, 'binding-two');

        $expected = $this->expected($publication);
        $fence = $this->fence('binding');
        $cases = [
            'path' => [
                'expected' => ['path' => (string)$publication['path'] . '.stale'],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'generation' => [
                'expected' => ['generation' => (int)$publication['generation'] + 1],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'digest' => [
                'expected' => ['digest' => \str_repeat('d', 64)],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'project UUID' => [
                'expected' => ['project_uuid' => '223e4567-e89b-42d3-a456-426614174000'],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'request digest' => [
                'expected' => ['request_digest' => \str_repeat('e', 64)],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'trust profile' => [
                'expected' => [
                    'certificate_trust_profile'
                        => ProjectCertificateGenerationStore::TRUST_PROFILE_PRODUCTION,
                ],
                'fence' => [],
                'message' => 'binding is stale or inconsistent',
            ],
            'instance generation' => [
                'expected' => [],
                'fence' => ['instance_generation' => 12],
                'message' => 'master fence',
            ],
            'launch ID' => [
                'expected' => [],
                'fence' => ['launch_id' => \str_repeat('f', 32)],
                'message' => 'instance launch',
            ],
        ];

        foreach ($cases as $label => $case) {
            $failure = $this->captureOrdinaryFailure(fn (): array => (
                new ProjectServingManifestStore($this->root)
            )->currentForStartupRecoveryFence(
                \array_replace($fence, $case['fence']),
                \array_replace($expected, $case['expected']),
            ));
            self::assertStringContainsString(
                (string)$case['message'],
                \strtolower($failure->getMessage()),
                $label,
            );
        }
    }

    public function testSameGenerationSelectorReplacementIsCorruptionNotRebuildAuthority(): void
    {
        $domain = 'same-generation.example.test';
        $generations = new ProjectCertificateGenerationStore($this->root);
        $first = $this->activate($generations, $domain, 'same-one');
        $publication = $this->publishActive('same-generation', $domain, $first);
        $this->activate($generations, $domain, 'same-two');

        $selectorPath = $this->activeSelectorPath($domain);
        $envelope = \json_decode((string)\file_get_contents($selectorPath), true);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['generation'] = (int)$first['generation'];
        $envelope['sha256'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($envelope['payload']),
        );
        self::assertNotFalse(\file_put_contents(
            $selectorPath,
            (string)\json_encode(
                $envelope,
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION,
            ),
        ));
        self::assertTrue(\chmod($selectorPath, 0600));

        $failure = $this->captureOrdinaryFailure(
            fn (): array => $this->startupManifest('same-generation', $publication),
        );
        self::assertMatchesRegularExpression(
            '/same generation|corrupt|roll(?:ed)? back/i',
            $failure->getMessage(),
        );
    }

    public function testMissingSelectorWithoutTombstoneFailsClosed(): void
    {
        $domain = 'absent.example.test';
        $generations = new ProjectCertificateGenerationStore($this->root);
        $active = $this->activate($generations, $domain, 'absent');
        $publication = $this->publishActive('absent', $domain, $active);
        self::assertTrue(\unlink($this->activeSelectorPath($domain)));

        $failure = $this->captureOrdinaryFailure(
            fn (): array => $this->startupManifest('absent', $publication),
        );
        self::assertStringContainsString('disappeared or rolled back', $failure->getMessage());
    }

    public function testPersistentCertificateFactAllowsOnlyBootScopedDeviceDrift(): void
    {
        $store = new ProjectServingManifestStore($this->root);
        $method = new \ReflectionMethod($store, 'assertSameFileFact');
        $expected = [
            'path' => '/immutable/fullchain.pem',
            'sha256' => \str_repeat('a', 64),
            'size' => 8906,
            'dev' => 16777232,
            'ino' => 85082290,
            'uid' => 501,
            'gid' => 20,
            'mode' => 33152,
            'nlink' => 1,
        ];

        $method->invoke(
            $store,
            $expected,
            \array_replace($expected, ['dev' => 16777233]),
            'certificate',
        );
        self::addToAssertionCount(1);

        $changes = [
            'path' => '/immutable/replaced.pem',
            'sha256' => \str_repeat('b', 64),
            'size' => 8907,
            'ino' => 85082291,
            'uid' => 0,
            'gid' => 0,
            'mode' => 33188,
            'nlink' => 2,
        ];
        foreach ($changes as $field => $value) {
            $failure = $this->captureOrdinaryFailure(
                fn (): mixed => $method->invoke(
                    $store,
                    $expected,
                    \array_replace($expected, ['dev' => 16777233, $field => $value]),
                    'certificate',
                ),
            );
            self::assertStringContainsString(
                'WLS serving certificate identity changed',
                $failure->getMessage(),
                $field,
            );
        }
    }

    /** @return array<string,mixed> */
    private function activate(
        ProjectCertificateGenerationStore $store,
        string $domain,
        string $name,
    ): array {
        $source = $this->createCertificate($domain, $name);
        return $store->activate(
            $domain,
            $source['cert'],
            $source['key'],
            '',
            [$this->sslRoot()],
            null,
            ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            ProjectCertificateGenerationStore::PROVIDER_EXTERNAL,
        );
    }

    /** @param array<string,mixed> $active @return array<string,mixed> */
    private function publishActive(string $instanceId, string $domain, array $active): array
    {
        return (new ProjectServingManifestStore($this->root))->publishFromRegistration(
            $this->registration($instanceId, [$this->activeRoute($domain, $active)]),
        );
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    private function startupManifest(string $instanceId, array $publication): array
    {
        return (new ProjectServingManifestStore($this->root))
            ->currentForStartupRecoveryFence(
                $this->fence($instanceId),
                $this->expected($publication),
            );
    }

    /** @param array<string,mixed> $publication @return array<string,mixed> */
    private function expected(array $publication): array
    {
        return [
            'path' => (string)$publication['path'],
            'generation' => (int)$publication['generation'],
            'digest' => (string)$publication['digest'],
            'project_uuid' => self::PROJECT_UUID,
            'certificate_trust_profile'
                => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            'request_digest' => self::REQUEST_DIGEST,
        ];
    }

    /** @return array<string,mixed> */
    private function fence(string $instanceId): array
    {
        return [
            'instance_id' => $instanceId,
            'instance_generation' => 11,
            'master_pid' => 12345,
            'master_epoch' => 22,
            'launch_id' => \str_repeat('a', 32),
        ];
    }

    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    private function registration(string $instanceId, array $routes): array
    {
        return [
            'project_uuid' => self::PROJECT_UUID,
            'project_root' => $this->root,
            'certificate_trust_profile'
                => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
            'instance_id' => $instanceId,
            'instance_generation' => 11,
            'master_pid' => 12345,
            'master_epoch' => 22,
            'launch_id' => \str_repeat('a', 32),
            'project_generation' => 5,
            'request_digest' => self::REQUEST_DIGEST,
            'non_certificate_desired_digest' => self::DESIRED_DIGEST,
            'routes' => $routes,
        ];
    }

    /** @param array<string,mixed> $active @return array<string,mixed> */
    private function activeRoute(string $domain, array $active): array
    {
        return [
            'route_id' => $this->routeId($domain),
            'domain' => $domain,
            'certificate' => [
                'state' => 'active',
                'pending' => false,
                'generation' => (int)$active['generation'],
                'source_digest' => (string)$active['source_digest'],
                'trust_profile' => (string)$active['trust_profile'],
                'provider' => (string)$active['provider'],
                'material_class' => (string)$active['material_class'],
                'provenance_digest' => (string)$active['provenance_digest'],
                'leaf_fingerprint_sha256'
                    => (string)$active['leaf_fingerprint_sha256'],
                'cert' => $this->certificateReference((string)$active['cert_path']),
                'key' => $this->certificateReference((string)$active['key_path']),
            ],
            'force_https' => true,
            'force_root_to_www' => false,
            'root_to_www_target' => '',
        ];
    }

    /** @param array<string,mixed> $disabled @return array<string,mixed> */
    private function disabledRoute(string $domain, array $disabled): array
    {
        $generation = (int)$disabled['generation'];
        $sourceDigest = (string)$disabled['source_digest'];
        return [
            'route_id' => $this->routeId($domain),
            'domain' => $domain,
            'certificate' => [
                'state' => 'disabled',
                'pending' => true,
                'generation' => $generation,
                'source_digest' => $sourceDigest,
                'trust_profile' => ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
                'provider' => 'none',
                'material_class' => 'none',
                'provenance_digest'
                    => ProjectCertificateGenerationStore::inactiveProvenanceDigest(
                        $domain,
                        'disabled',
                        $sourceDigest,
                        $generation,
                        ProjectCertificateGenerationStore::TRUST_PROFILE_TEST,
                    ),
            ],
            'force_https' => false,
            'force_root_to_www' => false,
            'root_to_www_target' => '',
        ];
    }

    /** @return array{root_alias:string,relative_path:string} */
    private function certificateReference(string $path): array
    {
        $prefix = $this->sslRoot() . DIRECTORY_SEPARATOR;
        self::assertStringStartsWith($prefix, $path);
        return [
            'root_alias' => 'project_ssl',
            'relative_path' => \str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                \substr($path, \strlen($prefix)),
            ),
        ];
    }

    private function routeId(string $domain): string
    {
        return \substr(\hash('sha256', self::PROJECT_UUID . "\0" . $domain), 0, 32);
    }

    private function sslRoot(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'app/etc/ssl';
    }

    private function activeSelectorPath(string $domain): string
    {
        return $this->sslRoot() . DIRECTORY_SEPARATOR . '.wls-generations'
            . DIRECTORY_SEPARATOR . 'active' . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $domain), 0, 32) . '.json';
    }

    /** @return array{cert:string,key:string} */
    private function createCertificate(string $domain, string $name): array
    {
        $directory = $this->sslRoot() . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir($directory, 0700, true));
        $config = $directory . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertNotFalse(\file_put_contents($config, <<<CONF
[req]
distinguished_name = dn
prompt = no
req_extensions = server_ext
x509_extensions = server_ext

[dn]
CN = {$domain}

[server_ext]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = {$domain}
CONF
        ));
        $arguments = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'req_extensions' => 'server_ext',
            'x509_extensions' => 'server_ext',
        ];
        $key = \openssl_pkey_new($arguments);
        self::assertNotFalse($key);
        $request = \openssl_csr_new(['commonName' => $domain], $key, $arguments);
        self::assertNotFalse($request);
        $certificate = \openssl_csr_sign($request, null, $key, 30, $arguments);
        self::assertNotFalse($certificate);
        self::assertTrue(\openssl_x509_export($certificate, $certificatePem));
        self::assertTrue(\openssl_pkey_export($key, $keyPem, null, $arguments));

        $certificatePath = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        self::assertNotFalse(\file_put_contents($certificatePath, $certificatePem));
        self::assertNotFalse(\file_put_contents($keyPath, $keyPem));
        self::assertTrue(\chmod($certificatePath, 0600));
        self::assertTrue(\chmod($keyPath, 0600));

        return ['cert' => $certificatePath, 'key' => $keyPath];
    }

    private function captureTransition(callable $callback): ServingManifestAuthorityTransitionException
    {
        try {
            $callback();
        } catch (ServingManifestAuthorityTransitionException $exception) {
            return $exception;
        }
        self::fail('Expected a typed monotonic serving-manifest authority transition.');
    }

    private function captureOrdinaryFailure(callable $callback): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            self::assertNotInstanceOf(
                ServingManifestAuthorityTransitionException::class,
                $throwable,
                'Corruption or an endpoint binding mismatch must not expose rebuild authority.',
            );
            return $throwable;
        }
        self::fail('Expected fail-closed serving-manifest validation.');
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || (!\file_exists($path) && !\is_link($path))) {
            return;
        }
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        $entries = \scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
