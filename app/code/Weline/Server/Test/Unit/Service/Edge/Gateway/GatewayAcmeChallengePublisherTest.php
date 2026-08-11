<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayAcmeChallengePublisher;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayAcmeChallengePublisherTest extends TestCase
{
    private const PROJECT_UUID = '123e4567-e89b-42d3-a456-4266141740ac';

    public function testMatchingGatewayRoutePublishesFilteredProjectSet(): void
    {
        $calls = [];
        $desired = $this->desired([
            $this->challenge('gateway.example.test', 'TOKEN_gateway'),
            $this->challenge('pure.example.test', 'TOKEN_pure'),
        ]);
        $registration = $this->registration('gateway', ['gateway.example.test']);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
                'pure' => ['public_host' => 'pure.example.test', 'gateway' => ['mode' => 'wls']],
            ],
            registrationProvider: static fn (): array => $registration,
            sync: static function (
                string $projectUuid,
                int $generation,
                array $challenges,
                string $digest,
            ) use (&$calls): bool {
                $calls[] = \compact('projectUuid', 'generation', 'challenges', 'digest');
                return true;
            },
            statusProvider: fn (): array => $this->gatewayStatus([$registration]),
            leaseReceiptProvider: fn (): array => $this->receipt($registration),
        );

        self::assertTrue($publisher->publish($desired, 'gateway.example.test'));
        self::assertCount(1, $calls);
        self::assertSame(self::PROJECT_UUID, $calls[0]['projectUuid']);
        self::assertSame(7, $calls[0]['generation']);
        self::assertSame(
            ['gateway.example.test'],
            \array_column($calls[0]['challenges'], 'domain'),
        );
        self::assertSame(
            \hash('sha256', GatewayClient::canonicalJson($calls[0]['challenges'])),
            $calls[0]['digest'],
        );
    }

    public function testUnrelatedGatewayDoesNotBlockPureWlsDomain(): void
    {
        $syncCalled = false;
        $registration = $this->registration('gateway', ['gateway.example.test']);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static fn (): array => $registration,
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return false;
            },
            statusProvider: fn (): array => $this->gatewayStatus([$registration]),
            leaseReceiptProvider: fn (): array => $this->receipt($registration),
        );

        self::assertTrue($publisher->publish(
            $this->desired([$this->challenge('pure.example.test', 'TOKEN_pure')]),
            'pure.example.test',
        ));
        self::assertFalse($syncCalled);
    }

    public function testMatchingGatewayEndpointFailsClosedWhenRegistrationCannotBuild(): void
    {
        $registration = $this->registration('gateway', ['gateway.example.test']);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static function (): array {
                throw new \RuntimeException('endpoint unavailable');
            },
            sync: static fn (): bool => true,
            statusProvider: fn (): array => $this->gatewayStatus([$registration]),
            leaseReceiptProvider: fn (): array => $this->receipt($registration),
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('gateway.example.test', 'TOKEN_gateway')]),
            'gateway.example.test',
        ));
    }

    public function testDeadGatewayMasterCannotMisclassifySecondaryRouteAsPureWls(): void
    {
        $syncCalled = false;
        $registrationCalled = false;
        $endpoint = $this->gatewayEndpoint('primary.example.test');
        $endpoint['master_pid'] = 2_147_483_647;
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: static fn (): array => ['gateway' => $endpoint],
            registrationProvider: static function () use (&$registrationCalled): array {
                $registrationCalled = true;
                throw new \RuntimeException('dead Master cannot build registration');
            },
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
            servingManifestProvider: fn (): array => $this->servingManifest(
                'gateway',
                ['primary.example.test', 'secondary.example.test'],
            ),
        );

        self::assertFalse($publisher->publish(
            $this->desired([
                $this->challenge('secondary.example.test', 'TOKEN_secondary'),
            ]),
            'secondary.example.test',
        ));
        self::assertFalse($registrationCalled);
        self::assertFalse($syncCalled);
    }

    public function testDeadGatewayMasterUsesCompleteManifestToProveUnrelatedPureWlsDomain(): void
    {
        $endpoint = $this->gatewayEndpoint('primary.example.test');
        $endpoint['master_pid'] = 2_147_483_647;
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: static fn (): array => ['gateway' => $endpoint],
            servingManifestProvider: fn (): array => $this->servingManifest(
                'gateway',
                ['primary.example.test', 'secondary.example.test'],
            ),
        );

        self::assertTrue($publisher->publish(
            $this->desired([$this->challenge('pure.example.test', 'TOKEN_pure')]),
            'pure.example.test',
        ));
    }

    public function testDeadGatewayMasterFailsClosedWhenCompleteMembershipIsUnavailable(): void
    {
        $endpoint = $this->gatewayEndpoint('primary.example.test');
        $endpoint['master_pid'] = 2_147_483_647;
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: static fn (): array => ['gateway' => $endpoint],
            servingManifestProvider: static function (): array {
                throw new \RuntimeException('serving manifest unavailable');
            },
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('pure.example.test', 'TOKEN_pure')]),
            'pure.example.test',
        ));
    }

    public function testFullReplayFailsClosedInsteadOfPublishingPartialGatewayView(): void
    {
        $syncCalled = false;
        $registrations = [
            'one' => $this->registration('one', ['one.example.test']),
            'two' => $this->registration('two', ['two.example.test']),
        ];
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'one' => $this->gatewayEndpoint('one.example.test'),
                'two' => $this->gatewayEndpoint('two.example.test'),
            ],
            registrationProvider: static function (string $instance) use (
                $registrations,
            ): array {
                if ($instance === 'two') {
                    throw new \RuntimeException('second endpoint unavailable');
                }
                return $registrations[$instance];
            },
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
            statusProvider: fn (): array => $this->gatewayStatus(\array_values($registrations)),
            leaseReceiptProvider: fn (string $instance): array => $this->receipt(
                $registrations[$instance],
            ),
        );

        self::assertFalse($publisher->publish($this->desired([
            $this->challenge('one.example.test', 'TOKEN_one'),
            $this->challenge('two.example.test', 'TOKEN_two'),
        ])));
        self::assertFalse($syncCalled);
    }

    public function testFullReplayRejectsPartialAuthenticatedGatewayStatus(): void
    {
        $syncCalled = false;
        $registrations = [
            'one' => $this->registration('one', ['one.example.test']),
            'two' => $this->registration('two', ['two.example.test']),
        ];
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'one' => $this->gatewayEndpoint('one.example.test'),
                'two' => $this->gatewayEndpoint('two.example.test'),
            ],
            registrationProvider: static fn (string $instance): array =>
                $registrations[$instance],
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
            statusProvider: fn (): array => $this->gatewayStatus([
                $registrations['one'],
            ]),
            leaseReceiptProvider: fn (string $instance): array => $this->receipt(
                $registrations[$instance],
            ),
        );

        self::assertFalse($publisher->publish($this->desired([
            $this->challenge('one.example.test', 'TOKEN_one'),
            $this->challenge('two.example.test', 'TOKEN_two'),
        ])));
        self::assertFalse($syncCalled);
    }

    public function testUnicodeRequiredDomainMatchesNormalizedGatewayRoute(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('IDNA normalization requires ext-intl.');
        }
        $ascii = (string)\idn_to_ascii(
            'täst.example',
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46,
        );
        $syncCalled = false;
        $registration = $this->registration('gateway', [$ascii]);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint($ascii),
            ],
            registrationProvider: static fn (): array => $registration,
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
            statusProvider: fn (): array => $this->gatewayStatus([$registration]),
            leaseReceiptProvider: fn (): array => $this->receipt($registration),
        );

        self::assertTrue($publisher->publish(
            $this->desired([$this->challenge($ascii, 'TOKEN_idna')]),
            'täst.example',
        ));
        self::assertTrue($syncCalled);
    }

    public function testRequiredGatewayDomainMustExistInDesiredSet(): void
    {
        $registration = $this->registration('gateway', ['gateway.example.test']);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static fn (): array => $registration,
            sync: static fn (): bool => true,
            statusProvider: fn (): array => $this->gatewayStatus([$registration]),
            leaseReceiptProvider: fn (): array => $this->receipt($registration),
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('other.example.test', 'TOKEN_other')]),
            'gateway.example.test',
        ));
    }

    public function testExpiredPublicationDeadlineFailsBeforeEndpointDiscovery(): void
    {
        $endpointCalls = 0;
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: static function () use (&$endpointCalls): array {
                ++$endpointCalls;
                return [];
            },
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('gateway.example.test', 'TOKEN_gateway')]),
            'gateway.example.test',
            (\hrtime(true) / 1_000_000_000) - 1.0,
        ));
        self::assertSame(0, $endpointCalls);
    }

    public function testRealGatewayPublicationCallsShareTheAbsoluteDeadline(): void
    {
        $method = new \ReflectionMethod(
            GatewayAcmeChallengePublisher::class,
            'publish',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            'validatedLeaseReceiptForInstance(',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/validatedLeaseReceiptForInstance\([\s\S]*?'
                . '\$deadlineMonotonic,\s*\)/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/->build\(\s*\$instanceName,\s*'
                . '\$deadlineMonotonic,\s*\)/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/->status\(\s*5\.0,\s*\$deadlineMonotonic,\s*\)/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/->syncAcmeChallenges\([\s\S]*?'
                . '\$filteredDigest,\s*\$deadlineMonotonic,\s*\)/',
            $source,
        );
    }

    /** @return array<string,mixed> */
    private function gatewayEndpoint(string $domain): array
    {
        return [
            'master_pid' => \getmypid(),
            'master_epoch' => 3,
            'public_host' => $domain,
            'gateway' => [
                'requested_mode' => 'gateway',
                'mode' => 'gateway',
                'protocol' => GatewayPaths::PROTOCOL,
                'project_uuid' => self::PROJECT_UUID,
                'instance_id' => 'gateway',
                'instance_generation' => 5,
                'launch_id' => \str_repeat('a', 32),
                'backend_identity_schema' => 'wls-backend-listener-identity/2',
            ],
        ];
    }

    /** @param list<string> $domains @return array<string,mixed> */
    private function registration(string $instance, array $domains): array
    {
        $routes = [];
        foreach ($domains as $domain) {
            $routes[] = [
                'route_id' => \substr(\hash('sha256', $domain), 0, 32),
                'domain' => $domain,
                'backend_identity' => [
                    'project_uuid' => self::PROJECT_UUID,
                    'instance_id' => $instance,
                    'public_digest' => \hash(
                        'sha256',
                        $instance . "\0" . $domain,
                    ),
                ],
            ];
        }
        return [
            'project_uuid' => self::PROJECT_UUID,
            'instance_id' => $instance,
            'project_generation' => 7,
            'instance_generation' => 5,
            'master_epoch' => 3,
            'launch_id' => \str_repeat('a', 32),
            'request_digest' => \str_repeat('b', 64),
            'routes' => $routes,
        ];
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private function receipt(array $registration): array
    {
        $routeGenerations = [];
        foreach ((array)$registration['routes'] as $route) {
            $routeGenerations[(string)$route['route_id']] = 1;
        }
        return [
            'project_uuid' => (string)$registration['project_uuid'],
            'instance_id' => (string)$registration['instance_id'],
            'project_generation' => (int)$registration['project_generation'],
            'instance_generation' => (int)$registration['instance_generation'],
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
            'request_digest' => (string)$registration['request_digest'],
            'gateway_epoch' => \str_repeat('c', 32),
            'route_generations' => $routeGenerations,
        ];
    }

    /** @param list<string> $domains @return array<string,mixed> */
    private function servingManifest(string $instance, array $domains): array
    {
        $routes = [];
        foreach ($domains as $domain) {
            $routes[] = [
                'route_id' => \substr(\hash(
                    'sha256',
                    self::PROJECT_UUID . "\0" . $domain,
                ), 0, 32),
                'domain' => $domain,
            ];
        }
        return ['payload' => [
            'project_uuid' => self::PROJECT_UUID,
            'instance_id' => $instance,
            'instance_generation' => 5,
            'master_epoch' => 3,
            'launch_id' => \str_repeat('a', 32),
            'desired_route_count' => \count($routes),
            'desired_routes' => $routes,
            'routes' => [],
            'converged' => false,
        ]];
    }

    /** @param list<array<string,mixed>> $registrations @return array<string,mixed> */
    private function gatewayStatus(array $registrations): array
    {
        $routes = [];
        foreach ($registrations as $registration) {
            $instance = (string)$registration['instance_id'];
            foreach ((array)$registration['routes'] as $route) {
                $routes[] = [
                    'project_uuid' => self::PROJECT_UUID,
                    'route_id' => (string)$route['route_id'],
                    'domain' => (string)$route['domain'],
                    'status' => 'PENDING_CERTIFICATE',
                    'backend_instances' => [
                        $instance => [
                            'backend_identity' => (array)$route['backend_identity'],
                        ],
                    ],
                ];
            }
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
            'protocol_min' => 1,
            'protocol_max' => 2,
            'epoch' => \str_repeat('c', 32),
            'host_boot_id' => \str_repeat('d', 64),
            'public_http' => 80,
            'public_https' => 443,
            'project_uuid' => self::PROJECT_UUID,
            'desired_routes' => $routes,
        ];
    }

    /** @return array{domain:string,token:string,key_authorization:string,expires_at:int} */
    private function challenge(string $domain, string $token): array
    {
        return [
            'domain' => $domain,
            'token' => $token,
            'key_authorization' => $token . '.' . \str_repeat('A', 43),
            'expires_at' => 1_900,
        ];
    }

    /** @param list<array<string,mixed>> $challenges @return array<string,mixed> */
    private function desired(array $challenges): array
    {
        return [
            'generation' => 7,
            'digest' => \hash('sha256', GatewayClient::canonicalJson($challenges)),
            'challenges' => $challenges,
        ];
    }
}
