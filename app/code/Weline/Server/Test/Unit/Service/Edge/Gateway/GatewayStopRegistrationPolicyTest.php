<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationLifecycle;
use Weline\Server\Service\Edge\Gateway\GatewayStopRegistrationPolicy;

final class GatewayStopRegistrationPolicyTest extends TestCase
{
    public function testNeverAttemptedAutoFallbackCanStopWhenControllerIsUnavailable(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_NEVER_ATTEMPTED, 0),
            ['ok' => false, 'reason' => 'controller unavailable'],
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY, $decision['action']);
        self::assertFalse($decision['status_authenticated']);
    }

    public function testRegisteredOrUncertainLaunchBlocksWithoutControllerProof(): void
    {
        foreach ([
            GatewayRegistrationLifecycle::STATE_REGISTERED,
            GatewayRegistrationLifecycle::STATE_UNCERTAIN,
            GatewayRegistrationLifecycle::STATE_REGISTERING,
        ] as $state) {
            $decision = GatewayStopRegistrationPolicy::decide(
                $this->retiringEndpoint($state, 1),
                ['ok' => false],
                \str_repeat('f', 64),
            );
            self::assertSame(GatewayStopRegistrationPolicy::ACTION_BLOCK, $decision['action']);
        }
    }

    public function testAuthenticatedAbsenceAllowsLocalStopAfterRegistrationIsFenced(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERED, 2),
            $this->status([]),
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_LOCAL_ONLY, $decision['action']);
        self::assertTrue($decision['status_authenticated']);
    }

    public function testInFlightRegistrationCannotUseAnEmptyStatusAsNeverRegisteredProof(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERING, 1),
            $this->status([]),
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_BLOCK, $decision['action']);
    }

    public function testExactAuthenticatedLaunchRequiresSignedDrain(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERED, 2),
            $this->status([[
                'instance_id' => 'shop',
                'status' => 'ACTIVE',
                'generation' => 7,
                'master_epoch' => 11,
                'launch_id' => \str_repeat('b', 32),
            ]]),
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_DRAIN, $decision['action']);
    }

    public function testSameInstanceIdFromAnotherLaunchBlocksDestructiveDrain(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERED, 2),
            $this->status([[
                'instance_id' => 'shop',
                'status' => 'ACTIVE',
                'generation' => 7,
                'master_epoch' => 11,
                'launch_id' => \str_repeat('c', 32),
            ]]),
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_BLOCK, $decision['action']);
    }

    public function testInFlightMutationBlocksEvenWhenStatusAlreadyShowsExactActiveRoute(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERING, 1),
            $this->status([[
                'instance_id' => 'shop',
                'status' => 'ACTIVE',
                'generation' => 7,
                'master_epoch' => 11,
                'launch_id' => \str_repeat('b', 32),
            ]]),
            \str_repeat('f', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_BLOCK, $decision['action']);
    }

    public function testStatusFromAnotherHostBootCannotProveRouteAbsence(): void
    {
        $decision = GatewayStopRegistrationPolicy::decide(
            $this->retiringEndpoint(GatewayRegistrationLifecycle::STATE_REGISTERED, 2),
            $this->status([]),
            \str_repeat('a', 64),
        );

        self::assertSame(GatewayStopRegistrationPolicy::ACTION_BLOCK, $decision['action']);
        self::assertFalse($decision['status_authenticated']);
    }

    /** @return array<string,mixed> */
    private function retiringEndpoint(string $previousState, int $attemptSequence): array
    {
        $projectUuid = '12345678-1234-4123-8123-123456789abc';
        $launchId = \str_repeat('b', 32);
        $fact = GatewayRegistrationLifecycle::initial(
            $projectUuid,
            'shop',
            7,
            $launchId,
            1_700_000_000,
        );
        unset($fact['fact_digest']);
        $fact['state'] = GatewayRegistrationLifecycle::STATE_RETIRING;
        $fact['master_pid'] = 4321;
        $fact['master_epoch'] = 11;
        $fact['attempt_sequence'] = $attemptSequence;
        $fact['mutation'] = $previousState
            === GatewayRegistrationLifecycle::STATE_REGISTERING
                ? 'register'
                : '';
        $fact['previous_state'] = $previousState;
        $fact['retirement_nonce'] = \str_repeat('d', 32);
        $fact['updated_timestamp'] = 1_700_000_001;
        $fact['fact_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($fact),
        );
        return [
            'master_pid' => 4321,
            'master_epoch' => 11,
            'gateway' => [
                'project_uuid' => $projectUuid,
                'instance_id' => 'shop',
                'instance_generation' => 7,
                'launch_id' => $launchId,
                'backend_identity_schema' => GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                'requested_mode' => 'auto',
                'mode' => 'wls',
                'registration_lifecycle' => $fact,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $instances @return array<string,mixed> */
    private function status(array $instances): array
    {
        return [
            'ok' => true,
            'publication_exact' => true,
            'protocol' => GatewayPaths::PROTOCOL,
            'project_uuid' => '12345678-1234-4123-8123-123456789abc',
            'epoch' => \str_repeat('e', 32),
            'host_boot_id' => \str_repeat('f', 64),
            'instances' => $instances,
        ];
    }
}
