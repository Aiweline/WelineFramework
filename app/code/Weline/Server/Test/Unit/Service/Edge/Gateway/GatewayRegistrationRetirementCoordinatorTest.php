<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationRetirementCoordinator;
use Weline\Server\Service\Edge\Gateway\GatewayStopRegistrationPolicy;

final class GatewayRegistrationRetirementCoordinatorTest extends TestCase
{
    private const HOST_BOOT_ID = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private string $directory = '';

    private string $file = '';

    private GatewayRegistrationLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-registration-retirement-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
        $this->file = $this->directory . DIRECTORY_SEPARATOR . 'shop.json';
        $this->write($this->endpoint());
        $this->lifecycle = new GatewayRegistrationLifecycle(
            fn (string $instance): string => $this->file,
        );
        $attempt = $this->lifecycle->beginMutation('shop', 'register');
        self::assertTrue($this->lifecycle->markRegistered(
            'shop',
            (int)$attempt['attempt_sequence'],
            'register',
        ));
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

    public function testCommittedDrainRetiresExactLaunch(): void
    {
        $calls = [];
        $coordinator = new GatewayRegistrationRetirementCoordinator(
            lifecycle: $this->lifecycle,
            statusResolver: function () use (&$calls): array {
                $calls[] = 'status';
                return $this->status([$this->instance('ACTIVE')]);
            },
            receiptValidator: static function (string $instance) use (&$calls): void {
                $calls[] = 'receipt:' . $instance;
            },
            drainResolver: static function (
                string $instance,
                int $seconds,
                bool $wait,
            ) use (&$calls): array {
                $calls[] = 'drain:' . $instance . ':' . $seconds . ':' . (int)$wait;
                return [
                    'unregistered' => true,
                    'drain_complete' => true,
                ];
            },
            currentHostBootId: self::HOST_BOOT_ID,
        );

        $result = $coordinator->retire('shop', 1, true, false);

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_DRAIN, $result['action']);
        self::assertSame(['status', 'receipt:shop', 'drain:shop:1:1'], $calls);
        self::assertSame(
            GatewayRegistrationLifecycle::STATE_RETIRED,
            $this->fact()['state'],
        );
    }

    public function testFailedStartupRetirementKeepsRetiringFence(): void
    {
        $coordinator = new GatewayRegistrationRetirementCoordinator(
            lifecycle: $this->lifecycle,
            statusResolver: fn (): array => $this->status([$this->instance('ACTIVE')]),
            receiptValidator: static function (string $instance): void {
                unset($instance);
            },
            drainResolver: static function (
                string $instance,
                int $seconds,
                bool $wait,
            ): array {
                unset($instance, $seconds, $wait);
                throw new \RuntimeException('control transport failed after drain submission');
            },
            currentHostBootId: self::HOST_BOOT_ID,
        );

        try {
            $coordinator->retire('shop', 1, true, false);
            self::fail('Failed startup rollback must report an uncertain host outcome.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('transport failed', $exception->getMessage());
        }

        $fact = $this->fact();
        self::assertSame(GatewayRegistrationLifecycle::STATE_RETIRING, $fact['state']);
        self::assertSame(GatewayRegistrationLifecycle::STATE_REGISTERED, $fact['previous_state']);
    }

    public function testOrdinaryStopFailureRestoresRegisteredState(): void
    {
        $coordinator = new GatewayRegistrationRetirementCoordinator(
            lifecycle: $this->lifecycle,
            statusResolver: fn (): array => $this->status([$this->instance('ACTIVE')]),
            receiptValidator: static function (string $instance): void {
                unset($instance);
            },
            drainResolver: static function (
                string $instance,
                int $seconds,
                bool $wait,
            ): array {
                unset($instance, $seconds, $wait);
                throw new \RuntimeException('controller unavailable');
            },
            currentHostBootId: self::HOST_BOOT_ID,
        );

        try {
            $coordinator->retire('shop', 300, true, true);
            self::fail('Ordinary Stop must fail when host retirement is unconfirmed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('controller unavailable', $exception->getMessage());
        }

        self::assertSame(
            GatewayRegistrationLifecycle::STATE_REGISTERED,
            $this->fact()['state'],
        );
    }

    public function testAlreadyRemovedRequiresFreshAuthenticatedAbsence(): void
    {
        $statuses = [
            $this->status([$this->instance('ACTIVE')]),
            $this->status([]),
        ];
        $coordinator = new GatewayRegistrationRetirementCoordinator(
            lifecycle: $this->lifecycle,
            statusResolver: static function () use (&$statuses): array {
                return \array_shift($statuses) ?? [];
            },
            receiptValidator: static function (string $instance): void {
                unset($instance);
            },
            drainResolver: static function (
                string $instance,
                int $seconds,
                bool $wait,
            ): array {
                unset($instance, $seconds, $wait);
                return ['already_removed' => true];
            },
            currentHostBootId: self::HOST_BOOT_ID,
        );

        $result = $coordinator->retire('shop', 1, true, false);

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_DRAIN, $result['action']);
        self::assertSame([], $statuses);
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

    /** @param list<array<string,mixed>> $instances */
    private function status(array $instances): array
    {
        return [
            'ok' => true,
            'publication_exact' => true,
            'project_uuid' => '12345678-1234-4123-8123-123456789abc',
            'protocol' => GatewayPaths::PROTOCOL,
            'epoch' => \str_repeat('a', 32),
            'host_boot_id' => self::HOST_BOOT_ID,
            'instances' => $instances,
        ];
    }

    /** @return array<string,mixed> */
    private function instance(string $status): array
    {
        return [
            'instance_id' => 'shop',
            'generation' => 7,
            'master_epoch' => 11,
            'launch_id' => \str_repeat('b', 32),
            'status' => $status,
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
