<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle;

final class GatewayRegistrationLifecycleTest extends TestCase
{
    private string $directory = '';
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-registration-lifecycle-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
        $this->file = $this->directory . DIRECTORY_SEPARATOR . 'shop.json';
        $this->write($this->endpoint());
    }

    protected function tearDown(): void
    {
        @\unlink($this->file);
        @\unlink($this->file . '.lock');
        foreach ((array)\glob($this->file . '.tmp.*') as $temporary) {
            @\unlink($temporary);
        }
        @\rmdir($this->directory);
        parent::tearDown();
    }

    public function testRetirementSerializesWithRegistrationAndCancellationRestoresAttempt(): void
    {
        $lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );
        $attempt = $lifecycle->beginMutation('shop', 'register');
        self::assertSame(1, $attempt['attempt_sequence']);
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERING,
            $this->fact()['state'],
        );

        $retirement = $lifecycle->claimRetirement('shop');
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERING,
            $retirement['previous_state'],
        );
        self::assertSame('register', $this->fact()['mutation']);
        try {
            $lifecycle->beginMutation('shop', 'renew');
            self::fail('A retiring launch must reject every later host mutation.');
        } catch (\RuntimeException) {
        }

        self::assertTrue($lifecycle->cancelRetirement(
            'shop',
            (string)$retirement['nonce'],
        ));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_UNCERTAIN,
            $this->fact()['state'],
        );
        self::assertSame('register', $this->fact()['mutation']);
        self::assertFalse($lifecycle->markRegistered('shop', 1, 'register'));
        $replay = $lifecycle->beginMutation('shop', 'register');
        self::assertSame(2, $replay['attempt_sequence']);
        self::assertTrue($lifecycle->markRegistered('shop', 2, 'register'));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERED,
            $this->fact()['state'],
        );
    }

    public function testCommittedRetirementCannotBeCancelledByAnOlderStopAttempt(): void
    {
        $lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );
        $retirement = $lifecycle->claimRetirement('shop');

        self::assertTrue($lifecycle->completeRetirement(
            'shop',
            (string)$retirement['nonce'],
            'authenticated own-status absence',
        ));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRED,
            $this->fact()['state'],
        );
        self::assertFalse($lifecycle->cancelRetirement(
            'shop',
            (string)$retirement['nonce'],
        ));
        $next = $lifecycle->claimRetirement('shop');
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRED,
            $next['previous_state'],
        );
    }

    public function testMissingLegacyFactBecomesUncertainAfterAMutationFailure(): void
    {
        $endpoint = $this->endpoint();
        unset($endpoint['gateway']['registration_lifecycle']);
        $this->write($endpoint);
        $lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );

        $attempt = $lifecycle->beginMutation('shop', 'register');
        self::assertTrue($lifecycle->markUncertain(
            'shop',
            (int)$attempt['attempt_sequence'],
            'register',
            'transport failed after submission',
        ));

        $fact = $this->fact();
        self::assertSame(GatewayRegistrationLifecycle::STATE_UNCERTAIN, $fact['state']);
        self::assertSame(1, $fact['attempt_sequence']);
        self::assertStringContainsString('transport failed', $fact['reason']);
    }

    public function testMutationRejectsAPresentButTamperedRetirementFence(): void
    {
        $endpoint = $this->endpoint();
        $endpoint['gateway']['registration_lifecycle']['state']
            = GatewayRegistrationLifecycle::STATE_RETIRED;
        $this->write($endpoint);
        $lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );

        try {
            $lifecycle->beginMutation('shop', 'register');
            self::fail('A present lifecycle fact with an invalid seal must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lifecycle fact is invalid', $exception->getMessage());
        }

        try {
            $lifecycle->claimRetirement('shop');
            self::fail('Retirement must not replace a present lifecycle fact with an invalid seal.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('lifecycle fact is invalid', $exception->getMessage());
        }

        $persisted = \json_decode(
            (string)\file_get_contents($this->file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRED,
            $persisted['gateway']['registration_lifecycle']['state'],
            'A tampered retirement fence must not be overwritten by registration.',
        );
    }

    public function testMutationRejectsAnEndpointResolvedForAnotherInstance(): void
    {
        $endpoint = $this->endpoint();
        $gateway = $endpoint['gateway'];
        $gateway['instance_id'] = 'other';
        $gateway['registration_lifecycle'] = GatewayRegistrationLifecycle::initial(
            (string)$gateway['project_uuid'],
            'other',
            (int)$gateway['instance_generation'],
            (string)$gateway['launch_id'],
            1_700_000_001,
        );
        $endpoint['gateway'] = $gateway;
        $this->write($endpoint);
        $lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );

        try {
            $lifecycle->beginMutation('shop', 'register');
            self::fail('A resolver must not redirect one instance mutation to another endpoint.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'does not match the requested instance',
                $exception->getMessage(),
            );
        }

        $persisted = \json_decode(
            (string)\file_get_contents($this->file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_NEVER_ATTEMPTED,
            $persisted['gateway']['registration_lifecycle']['state'],
        );
    }

    public function testDurableMutationRemainsCommittedWhenPublicationCrossesDeadline(): void
    {
        $now = 100.0;
        $updaterCalls = 0;
        $lifecycle = new GatewayRegistrationLifecycle(
            instanceFileResolver: fn (string $instance): string => $this->file,
            atomicUpdater: function (
                string $file,
                callable $modifier,
                float $timeout,
            ) use (&$now, &$updaterCalls): bool {
                $updaterCalls++;
                self::assertGreaterThan(0.0, $timeout);
                $current = \json_decode(
                    (string)\file_get_contents($file),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                $next = $modifier($current);
                self::assertIsArray($next);
                self::assertNotFalse(\file_put_contents(
                    $file,
                    \json_encode($next, JSON_THROW_ON_ERROR),
                    LOCK_EX,
                ));
                $now = 101.0;
                return true;
            },
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
        );

        $attempt = $lifecycle->beginMutation(
            'shop',
            'register',
            0.25,
            100.5,
        );

        self::assertSame(1, $updaterCalls);
        self::assertSame(1, $attempt['attempt_sequence']);
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERING,
            $this->fact()['state'],
        );
    }

    public function testFalseAtomicResultRecoversAnExactlyCommittedFact(): void
    {
        $now = 200.0;
        $updaterCalls = 0;
        $lifecycle = new GatewayRegistrationLifecycle(
            instanceFileResolver: fn (string $instance): string => $this->file,
            atomicUpdater: function (
                string $file,
                callable $modifier,
                float $timeout,
            ) use (&$updaterCalls): bool {
                $updaterCalls++;
                self::assertGreaterThan(0.0, $timeout);
                $current = \json_decode(
                    (string)\file_get_contents($file),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                $next = $modifier($current);
                self::assertIsArray($next);
                self::assertNotFalse(\file_put_contents(
                    $file,
                    \json_encode($next, JSON_THROW_ON_ERROR),
                    LOCK_EX,
                ));
                // Model a rename that committed before a later durability
                // acknowledgement failed to reach the helper's caller.
                return false;
            },
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
        );

        $attempt = $lifecycle->beginMutation(
            'shop',
            'register',
            0.25,
            201.0,
        );

        self::assertSame(1, $updaterCalls);
        self::assertSame(1, $attempt['attempt_sequence']);
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERING,
            $this->fact()['state'],
        );
    }

    public function testUnprovenAtomicOutcomeIsExplicitAndRetryable(): void
    {
        $now = 300.0;
        $lifecycle = new GatewayRegistrationLifecycle(
            instanceFileResolver: fn (string $instance): string => $this->file,
            atomicUpdater: static function (
                string $file,
                callable $modifier,
                float $timeout,
            ): bool {
                $current = \json_decode(
                    (string)\file_get_contents($file),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                self::assertIsArray($modifier($current));
                // The helper cannot prove whether its intended rename
                // committed; leave the old file in place for reconciliation.
                return false;
            },
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
        );

        try {
            $lifecycle->beginMutation('shop', 'register', 0.25, 301.0);
            self::fail('An unproven lifecycle publication must be explicit.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'publication outcome is uncertain',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_NEVER_ATTEMPTED,
            $this->fact()['state'],
        );

        $retry = (new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        ))->beginMutation('shop', 'register');
        self::assertSame(1, $retry['attempt_sequence']);
    }

    public function testFailureCompensationUsesAnIndependentBoundedDeadline(): void
    {
        $now = 400.0;
        $lifecycle = new GatewayRegistrationLifecycle(
            instanceFileResolver: fn (string $instance): string => $this->file,
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
        );
        $attempt = $lifecycle->beginMutation(
            'shop',
            'register',
            0.25,
            401.0,
        );

        // The host-operation deadline has passed, but compensation receives
        // a fresh one-second local budget instead of reusing it.
        $now = 500.0;
        self::assertTrue($lifecycle->markUncertain(
            'shop',
            (int)$attempt['attempt_sequence'],
            'register',
            'operation deadline expired after submission',
        ));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_UNCERTAIN,
            $this->fact()['state'],
        );
    }

    public function testRetirementMutationsRecoverExactlyCommittedFacts(): void
    {
        $lifecycle = new GatewayRegistrationLifecycle(
            instanceFileResolver: fn (string $instance): string => $this->file,
            atomicUpdater: static function (
                string $file,
                callable $modifier,
                float $timeout,
            ): bool {
                $current = \json_decode(
                    (string)\file_get_contents($file),
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                $next = $modifier($current);
                self::assertIsArray($next);
                self::assertNotFalse(\file_put_contents(
                    $file,
                    \json_encode($next, JSON_THROW_ON_ERROR),
                    LOCK_EX,
                ));
                return false;
            },
        );

        $claim = $lifecycle->claimRetirement('shop');
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRING,
            $this->fact()['state'],
        );
        self::assertTrue($lifecycle->cancelRetirement(
            'shop',
            (string)$claim['nonce'],
        ));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_NEVER_ATTEMPTED,
            $this->fact()['state'],
        );

        $claim = $lifecycle->claimRetirement('shop');
        self::assertTrue($lifecycle->completeRetirement(
            'shop',
            (string)$claim['nonce'],
            'authenticated absence',
        ));
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRED,
            $this->fact()['state'],
        );
    }

    /** @return array<string,mixed> */
    private function endpoint(): array
    {
        $projectUuid = '12345678-1234-4123-8123-123456789abc';
        $launchId = \str_repeat('b', 32);
        return [
            'master_pid' => 4321,
            'master_epoch' => 11,
            'lifecycle_state' => 'running',
            'gateway' => [
                'project_uuid' => $projectUuid,
                'instance_id' => 'shop',
                'instance_generation' => 7,
                'launch_id' => $launchId,
                'backend_identity_schema' => GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                'registration_lifecycle' => GatewayRegistrationLifecycle::initial(
                    $projectUuid,
                    'shop',
                    7,
                    $launchId,
                    1_700_000_000,
                ),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fact(): array
    {
        $endpoint = \json_decode(
            (string)\file_get_contents($this->file),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($endpoint);
        $fact = GatewayRegistrationLifecycle::factForEndpoint($endpoint);
        self::assertNotSame([], $fact);
        return $fact;
    }

    /** @param array<string,mixed> $endpoint */
    private function write(array $endpoint): void
    {
        self::assertNotFalse(\file_put_contents(
            $this->file,
            \json_encode($endpoint, JSON_THROW_ON_ERROR),
        ));
    }
}
