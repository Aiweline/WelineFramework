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
