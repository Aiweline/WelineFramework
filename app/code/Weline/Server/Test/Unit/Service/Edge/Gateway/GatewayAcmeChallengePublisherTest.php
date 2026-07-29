<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayAcmeChallengePublisher;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;

final class GatewayAcmeChallengePublisherTest extends TestCase
{
    public function testMatchingGatewayRoutePublishesFilteredProjectSet(): void
    {
        $calls = [];
        $desired = $this->desired([
            $this->challenge('gateway.example.test', 'TOKEN_gateway'),
            $this->challenge('pure.example.test', 'TOKEN_pure'),
        ]);
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
                'pure' => ['public_host' => 'pure.example.test', 'gateway' => ['mode' => 'wls']],
            ],
            registrationProvider: static fn (string $instance): array => [
                'project_uuid' => 'project-uuid',
                'routes' => $instance === 'gateway'
                    ? [['domain' => 'gateway.example.test']]
                    : [],
            ],
            sync: static function (
                string $projectUuid,
                int $generation,
                array $challenges,
                string $digest,
            ) use (&$calls): bool {
                $calls[] = \compact('projectUuid', 'generation', 'challenges', 'digest');
                return true;
            },
        );

        self::assertTrue($publisher->publish($desired, 'gateway.example.test'));
        self::assertCount(1, $calls);
        self::assertSame('project-uuid', $calls[0]['projectUuid']);
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
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static fn (): array => [
                'project_uuid' => 'project-uuid',
                'routes' => [['domain' => 'gateway.example.test']],
            ],
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return false;
            },
        );

        self::assertTrue($publisher->publish(
            $this->desired([$this->challenge('pure.example.test', 'TOKEN_pure')]),
            'pure.example.test',
        ));
        self::assertFalse($syncCalled);
    }

    public function testMatchingGatewayEndpointFailsClosedWhenRegistrationCannotBuild(): void
    {
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static function (): array {
                throw new \RuntimeException('endpoint unavailable');
            },
            sync: static fn (): bool => true,
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('gateway.example.test', 'TOKEN_gateway')]),
            'gateway.example.test',
        ));
    }

    public function testFullReplayFailsClosedInsteadOfPublishingPartialGatewayView(): void
    {
        $syncCalled = false;
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'one' => $this->gatewayEndpoint('one.example.test'),
                'two' => $this->gatewayEndpoint('two.example.test'),
            ],
            registrationProvider: static function (string $instance): array {
                if ($instance === 'two') {
                    throw new \RuntimeException('second endpoint unavailable');
                }
                return [
                    'project_uuid' => 'project-uuid',
                    'routes' => [['domain' => 'one.example.test']],
                ];
            },
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
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
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint($ascii),
            ],
            registrationProvider: static fn () => [
                'project_uuid' => 'project-uuid',
                'routes' => [['domain' => $ascii]],
            ],
            sync: static function () use (&$syncCalled): bool {
                $syncCalled = true;
                return true;
            },
        );

        self::assertTrue($publisher->publish(
            $this->desired([$this->challenge($ascii, 'TOKEN_idna')]),
            'täst.example',
        ));
        self::assertTrue($syncCalled);
    }

    public function testRequiredGatewayDomainMustExistInDesiredSet(): void
    {
        $publisher = new GatewayAcmeChallengePublisher(
            endpointProvider: fn (): array => [
                'gateway' => $this->gatewayEndpoint('gateway.example.test'),
            ],
            registrationProvider: static fn (): array => [
                'project_uuid' => 'project-uuid',
                'routes' => [['domain' => 'gateway.example.test']],
            ],
            sync: static fn (): bool => true,
        );

        self::assertFalse($publisher->publish(
            $this->desired([$this->challenge('other.example.test', 'TOKEN_other')]),
            'gateway.example.test',
        ));
    }

    /** @return array<string,mixed> */
    private function gatewayEndpoint(string $domain): array
    {
        return [
            'public_host' => $domain,
            'gateway' => [
                'mode' => 'gateway',
                'protocol' => GatewayPaths::PROTOCOL,
            ],
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
