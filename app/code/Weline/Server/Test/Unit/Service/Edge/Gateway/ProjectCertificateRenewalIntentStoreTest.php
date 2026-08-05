<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateRenewalIntentStore;

final class ProjectCertificateRenewalIntentStoreTest extends TestCase
{
    public function testStoreRejectsFilesystemProjectRoot(): void
    {
        $root = \realpath(\sys_get_temp_dir());
        self::assertIsString($root);
        $filesystemRoot = \preg_match('/\A([A-Za-z]:)[\\\\\/]/D', $root, $match) === 1
            ? $match[1] . DIRECTORY_SEPARATOR
            : DIRECTORY_SEPARATOR;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('safe project root');
        new ProjectCertificateRenewalIntentStore($filesystemRoot);
    }

    public function testStoreRecognizesExtendedWindowsFilesystemRoots(): void
    {
        $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-renewal-root-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($root, 0700, true));
        try {
            $store = new ProjectCertificateRenewalIntentStore($root);
            $method = new \ReflectionMethod($store, 'isFilesystemRoot');
            foreach ([
                'C:\\',
                '\\\\server\\',
                '\\\\server\\share\\',
                '\\\\?\\C:\\',
                '\\\\?\\UNC\\server\\share\\',
                '\\\\?\\UNC\\server\\',
                '\\\\.\\C:\\',
            ] as $path) {
                self::assertTrue($method->invoke($store, $path), $path);
            }
            self::assertFalse($method->invoke($store, 'C:\\project'));
        } finally {
            $this->removeTree($root);
        }
    }

    public function testAttemptReturnsExactLockedIntentAndCannotMarkSuccessorFailed(): void
    {
        $root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-renewal-intent-' . \bin2hex(\random_bytes(6));
        self::assertTrue(@\mkdir(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc'
                . DIRECTORY_SEPARATOR . 'ssl',
            0700,
            true,
        ));
        try {
            $store = new ProjectCertificateRenewalIntentStore($root);
            $first = $this->registration(1, 'a');
            $store->enqueueFromRegistration($first);
            $firstPending = $store->pendingReplay();
            self::assertIsArray($firstPending);
            $firstIntentId = (string)$firstPending['intent']['intent_id'];
            self::assertSame(
                $firstIntentId,
                $store->recordAttempt($first, 'default', [
                    'action' => 'register',
                    'gateway_epoch' => \str_repeat('1', 32),
                    'expected_route_generations' => [],
                ]),
            );

            $second = $this->registration(2, 'b');
            $store->enqueueFromRegistration($second);
            $store->recordFailure($firstIntentId, 'failure from retired publication');
            $secondPending = $store->pendingReplay();

            self::assertIsArray($secondPending);
            self::assertSame(2, $secondPending['intent']['project_generation']);
            self::assertNull($secondPending['intent']['last_attempt']);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testRenewFenceRequiresExactGenerationValues(): void
    {
        $method = new \ReflectionMethod(
            ProjectCertificateRenewalIntentStore::class,
            'routeGenerationFencesMatch',
        );
        $routeId = \str_repeat('a', 32);

        self::assertTrue($method->invoke(null, [$routeId => 7], [$routeId => 7]));
        self::assertFalse($method->invoke(null, [$routeId => 7], [$routeId => 8]));
    }

    public function testAcknowledgementFactsRequireExactReadyActivePublication(): void
    {
        $store = (new \ReflectionClass(ProjectCertificateRenewalIntentStore::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($store, 'assertStatusMatchesFacts');
        $projectUuid = '123e4567-e89b-42d3-a456-426614174091';
        $routeId = \str_repeat('b', 32);
        $requestDigest = \str_repeat('c', 64);
        $nonCertificateDigest = \str_repeat('d', 64);
        $sourceDigest = \str_repeat('e', 64);
        $facts = [
            'project_uuid' => $projectUuid,
            'project_generation' => 9,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $nonCertificateDigest,
            'routes' => [[
                'route_id' => $routeId,
                'domain' => 'example.test',
                'certificate_generation' => 3,
                'source_digest' => $sourceDigest,
            ]],
        ];
        $receipt = $this->leaseReceipt(
            $facts,
            [$routeId => 7],
            12,
            \str_repeat('9', 64),
        );
        $status = [
            ...$this->statusForReceipt($receipt),
            'publication_exact' => true,
            'project_generation' => 9,
            'request_digest' => $requestDigest,
            'non_certificate_desired_digest' => $nonCertificateDigest,
            'active_config_generation' => 12,
            'active_config_digest' => \str_repeat('9', 64),
            'active_routes' => [[
                'project_uuid' => $projectUuid,
                'route_id' => $routeId,
                'domain' => 'example.test',
                'status' => 'ACTIVE',
                'route_generation' => 7,
                'certificate' => [
                    'generation' => 3,
                    'source_digest' => $sourceDigest,
                ],
            ]],
        ];

        $method->invoke($store, $status, $facts, [$routeId => 7], $receipt);
        self::addToAssertionCount(1);

        foreach ([
            'not-exact' => ['publication_exact' => false],
            'stale-project-generation' => ['project_generation' => 8],
            'stale-request' => ['request_digest' => \str_repeat('f', 64)],
            'stale-policy' => [
                'non_certificate_desired_digest' => \str_repeat('0', 64),
            ],
            'pending-route' => [
                'active_routes' => [[
                    ...$status['active_routes'][0],
                    'status' => 'PENDING_CERTIFICATE',
                ]],
            ],
        ] as $label => $override) {
            try {
                $method->invoke(
                    $store,
                    [...$status, ...$override],
                    $facts,
                    [$routeId => 7],
                    $receipt,
                );
                self::fail('Expected mismatched publication to be rejected: ' . $label);
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                self::assertInstanceOf(\RuntimeException::class, $throwable);
            }
        }
    }

    public function testPendingCertificateAcknowledgesOnlyExactChallengePublication(): void
    {
        $store = (new \ReflectionClass(ProjectCertificateRenewalIntentStore::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($store, 'assertStatusMatchesFacts');
        $projectUuid = '123e4567-e89b-42d3-a456-426614174092';
        $routeId = \str_repeat('7', 32);
        $pendingDigest = \str_repeat('8', 64);
        $activeDigest = \str_repeat('9', 64);
        $facts = [
            'project_uuid' => $projectUuid,
            'project_generation' => 4,
            'request_digest' => \str_repeat('a', 64),
            'non_certificate_desired_digest' => \str_repeat('b', 64),
            'routes' => [[
                'route_id' => $routeId,
                'domain' => 'pending.example.test',
                'certificate_generation' => 0,
                'source_digest' => $pendingDigest,
                'pending' => true,
            ]],
        ];
        $receipt = $this->leaseReceipt(
            $facts,
            [$routeId => 2],
            6,
            $activeDigest,
        );
        $status = [
            ...$this->statusForReceipt($receipt),
            'publication_exact' => true,
            'project_generation' => 4,
            'request_digest' => \str_repeat('a', 64),
            'non_certificate_desired_digest' => \str_repeat('b', 64),
            'active_config_generation' => 6,
            'active_config_digest' => $activeDigest,
            'active_routes' => [[
                'project_uuid' => $projectUuid,
                'route_id' => $routeId,
                'domain' => 'pending.example.test',
                'status' => 'PENDING_CERTIFICATE',
                'route_generation' => 2,
                'certificate' => [
                    'generation' => 0,
                    'source_digest' => $pendingDigest,
                ],
            ]],
        ];
        $method->invoke($store, $status, $facts, [$routeId => 2], $receipt);
        self::addToAssertionCount(1);
    }

    public function testCertificateReceiptRequiresExactSchemaTwoLeaseContract(): void
    {
        $store = (new \ReflectionClass(ProjectCertificateRenewalIntentStore::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($store, 'assertReceiptMatchesFacts');
        $routeId = \str_repeat('4', 32);
        $facts = [
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174093',
            'project_generation' => 5,
            'request_digest' => \str_repeat('5', 64),
            'routes' => [[
                'route_id' => $routeId,
                'domain' => 'receipt.example.test',
                'certificate_generation' => 8,
                'source_digest' => \str_repeat('6', 64),
            ]],
        ];
        $receipt = $this->leaseReceipt(
            $facts,
            [$routeId => 11],
            17,
            \str_repeat('7', 64),
        );

        self::assertSame(
            [$routeId => 11],
            $method->invoke(
                $store,
                $receipt,
                $facts,
                'default',
                \str_repeat('2', 32),
            ),
        );

        foreach (['legacy-schema', 'missing-boot', 'zero-sequence'] as $case) {
            $invalid = $receipt;
            if ($case === 'legacy-schema') {
                $invalid['schema_version'] = 1;
            } elseif ($case === 'missing-boot') {
                unset($invalid['host_boot_id']);
            } else {
                $invalid['lease_sequence'] = 0;
            }
            try {
                $method->invoke(
                    $store,
                    $invalid,
                    $facts,
                    'default',
                    \str_repeat('2', 32),
                );
                self::fail('Expected incompatible lease receipt rejection: ' . $case);
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                self::assertInstanceOf(\RuntimeException::class, $throwable);
            }
        }
    }

    /**
     * @param array<string,mixed> $facts
     * @param array<string,int> $routeGenerations
     * @return array<string,mixed>
     */
    private function leaseReceipt(
        array $facts,
        array $routeGenerations,
        int $activeConfigGeneration,
        string $activeConfigDigest,
    ): array {
        return [
            'schema_version' => 2,
            'protocol' => GatewayPaths::PROTOCOL,
            'host_id' => \str_repeat('1', 64),
            'project_uuid' => (string)$facts['project_uuid'],
            'gateway_epoch' => \str_repeat('2', 32),
            'project_generation' => (int)$facts['project_generation'],
            'instance_id' => 'default',
            'instance_generation' => 3,
            'instance_digest' => \str_repeat('3', 64),
            'master_epoch' => 4,
            'launch_id' => \str_repeat('4', 32),
            'request_digest' => (string)$facts['request_digest'],
            'idempotency_key' => \str_repeat('5', 40),
            'active_config_generation' => $activeConfigGeneration,
            'active_config_digest' => $activeConfigDigest,
            'host_boot_id' => \str_repeat('6', 64),
            'issued_monotonic' => 1.0,
            'lease_sequence' => 7,
            'lease_ttl_seconds' => 45,
            'route_generations' => $routeGenerations,
            'routes_digest' => \hash(
                'sha256',
                GatewayClient::canonicalJson($routeGenerations),
            ),
            'issued_at' => '2026-08-03T00:00:00+00:00',
            'signature' => \str_repeat('7', 64),
        ];
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function statusForReceipt(array $receipt): array
    {
        $routes = [];
        foreach ((array)$receipt['route_generations'] as $routeId => $generation) {
            $routes[] = [
                'project_uuid' => (string)$receipt['project_uuid'],
                'route_id' => (string)$routeId,
                'status' => 'ACTIVE',
                'route_generation' => (int)$generation,
            ];
        }
        return [
            'ok' => true,
            'control_plane_ready' => true,
            'release_ready' => true,
            'broker_ready' => true,
            'supervisor_ready' => true,
            'protocol' => GatewayPaths::PROTOCOL,
            'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL,
            'security_profile' => GatewayPaths::SECURITY_PROFILE,
            'protocol_min' => 2,
            'protocol_max' => 2,
            'epoch' => (string)$receipt['gateway_epoch'],
            'public_http' => 80,
            'public_https' => 443,
            'publication_exact' => true,
            'project_uuid' => (string)$receipt['project_uuid'],
            'project_generation' => (int)$receipt['project_generation'],
            'request_digest' => (string)$receipt['request_digest'],
            'idempotency_key' => (string)$receipt['idempotency_key'],
            'active_config_generation' => (int)$receipt['active_config_generation'],
            'active_config_digest' => (string)$receipt['active_config_digest'],
            'host_boot_id' => (string)$receipt['host_boot_id'],
            'instances' => [[
                'instance_id' => (string)$receipt['instance_id'],
                'generation' => (int)$receipt['instance_generation'],
                'status' => 'ACTIVE',
                'digest' => (string)$receipt['instance_digest'],
                'master_epoch' => (int)$receipt['master_epoch'],
                'launch_id' => (string)$receipt['launch_id'],
            ]],
            'active_routes' => $routes,
        ];
    }

    /** @return array<string,mixed> */
    private function registration(int $generation, string $seed): array
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174090';
        $domain = 'renewal.example.test';
        return [
            'project_uuid' => $projectUuid,
            'instance_id' => 'default',
            'project_generation' => $generation,
            'request_digest' => \str_repeat($seed, 64),
            'non_certificate_desired_digest' => \str_repeat('c', 64),
            'routes' => [[
                'route_id' => \substr(
                    \hash('sha256', $projectUuid . "\0" . $domain),
                    0,
                    32,
                ),
                'domain' => $domain,
                'certificate' => [
                    'generation' => $generation,
                    'source_digest' => \str_repeat($seed, 64),
                    'pending' => false,
                ],
            ]],
        ];
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            if (\file_exists($path) || \is_link($path)) {
                @\unlink($path);
            }
            return;
        }
        $entries = \scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @\rmdir($path);
    }
}
