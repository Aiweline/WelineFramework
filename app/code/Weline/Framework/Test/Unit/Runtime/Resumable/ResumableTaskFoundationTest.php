<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime\Resumable;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Resumable\CheckpointCodec;
use Weline\Framework\Runtime\Resumable\CheckpointValidationException;
use Weline\Framework\Runtime\Resumable\InvalidTaskStateTransition;
use Weline\Framework\Runtime\Resumable\ResumableTaskStateMachine;
use Weline\Framework\Runtime\Resumable\ResumableTaskStatus;
use Weline\Framework\Runtime\Resumable\TaskCheckpoint;
use Weline\Framework\Runtime\Resumable\TaskEffectReservation;
use Weline\Framework\Runtime\Resumable\TaskEffectState;
use Weline\Framework\Runtime\Resumable\TaskEvent;
use Weline\Framework\Runtime\Resumable\TaskLease;
use Weline\Framework\Runtime\Resumable\TaskOwner;
use Weline\Framework\Runtime\Resumable\TaskPolicy;
use Weline\Framework\Runtime\Resumable\TaskResult;
use Weline\Framework\Runtime\Resumable\TaskSnapshot;

final class ResumableTaskFoundationTest extends TestCase
{
    public function testCheckpointRoundTripIsCanonicalAndChecksumProtected(): void
    {
        $checkpoint = new TaskCheckpoint(
            taskId: 'task-00000001',
            version: 3,
            cursor: 'step-3-saved',
            state: ['z' => ['second' => 2, 'first' => 1], 'a' => true],
            schemaVersion: 2,
            attempt: 1,
            fencingGeneration: 4,
            createdAt: 1_700_000_000,
        );

        self::assertSame(['a' => true, 'z' => ['first' => 1, 'second' => 2]], $checkpoint->state);
        self::assertSame($checkpoint->checksum, CheckpointCodec::checksum([
            'task_id' => 'task-00000001',
            'version' => 3,
            'cursor' => 'step-3-saved',
            'state' => ['a' => true, 'z' => ['first' => 1, 'second' => 2]],
            'schema_version' => 2,
            'attempt' => 1,
            'fencing_generation' => 4,
        ]));
        self::assertSame($checkpoint->toArray(), TaskCheckpoint::fromArray($checkpoint->toArray())->toArray());

        $tampered = $checkpoint->toArray();
        $tampered['state']['a'] = false;
        $this->expectException(CheckpointValidationException::class);
        TaskCheckpoint::fromArray($tampered);
    }

    public function testCheckpointCodecRejectsRuntimeObjectsResourcesNonFiniteValuesAndSecrets(): void
    {
        $invalidValues = [
            ['fiber' => new \Fiber(static function (): void {})],
            ['closure' => static function (): void {}],
            ['nan' => \NAN],
            ['infinity' => \INF],
            ['apiKey' => 'do-not-persist'],
            ['nested' => ['access_token' => 'do-not-persist']],
        ];

        foreach ($invalidValues as $invalidValue) {
            try {
                CheckpointCodec::normalize($invalidValue);
                self::fail('Expected checkpoint validation failure.');
            } catch (CheckpointValidationException) {
                self::addToAssertionCount(1);
            }
        }

        $resource = \fopen('php://temp', 'rb');
        self::assertIsResource($resource);
        try {
            $this->expectException(CheckpointValidationException::class);
            CheckpointCodec::normalize(['resource' => $resource]);
        } finally {
            \fclose($resource);
        }
    }

    public function testCheckpointCodecAllowsAStreamTokenButNotAnAuthenticationToken(): void
    {
        self::assertSame(['token' => 'one generated word'], CheckpointCodec::normalize([
            'token' => 'one generated word',
        ]));

        $this->expectException(CheckpointValidationException::class);
        CheckpointCodec::normalize(['authToken' => 'do-not-persist']);
    }

    public function testStateMachineAllowsOnlyDocumentedLifecycleTransitions(): void
    {
        self::assertTrue(ResumableTaskStateMachine::canTransition(
            ResumableTaskStatus::STARTING,
            ResumableTaskStatus::RUNNING,
        ));
        self::assertTrue(ResumableTaskStateMachine::canTransition(
            ResumableTaskStatus::RUNNING,
            ResumableTaskStatus::RECOVERING,
        ));
        self::assertTrue(ResumableTaskStateMachine::canTransition(
            ResumableTaskStatus::CANCEL_REQUESTED,
            ResumableTaskStatus::EXPIRED,
        ));
        self::assertFalse(ResumableTaskStateMachine::canTransition(
            ResumableTaskStatus::COMPLETED,
            ResumableTaskStatus::RUNNING,
        ));
        self::assertTrue(ResumableTaskStatus::RECOVERY_UNSAFE->isTerminal());
        self::assertTrue(ResumableTaskStatus::EVENT_BACKLOG_LIMIT->isTerminal());

        $this->expectException(InvalidTaskStateTransition::class);
        ResumableTaskStateMachine::assertTransition(
            ResumableTaskStatus::COMPLETED,
            ResumableTaskStatus::RUNNING,
        );
    }

    public function testPersistedEventUsesItsSequenceAsSseId(): void
    {
        $event = new TaskEvent(
            taskId: 'task-00000001',
            sequence: 123,
            event: 'progress',
            payload: ['phase' => 'generate', 'completed' => 3],
            checkpointVersion: 5,
            attempt: 2,
            fencingGeneration: 3,
            createdAt: 1_700_000_000,
        );

        self::assertSame([
            'id' => 123,
            'event' => 'progress',
            'data' => ['completed' => 3, 'phase' => 'generate'],
        ], $event->toSseEvent());
    }

    public function testTaskOwnerPreservesZeroAsTheSystemDefaultWebsite(): void
    {
        $owner = new TaskOwner(
            area: 'frontend',
            principal: 'customer:42',
            sessionId: 'session-0001',
            websiteId: 0,
            tenantId: 'tenant-1',
            acl: ['Weline_Websites::build'],
        );

        self::assertSame(0, $owner->websiteId);
        self::assertTrue($owner->equals(new TaskOwner(
            area: 'frontend',
            principal: 'customer:42',
            sessionId: 'session-0001',
            websiteId: 0,
            tenantId: 'tenant-1',
            acl: ['Weline_Websites::build'],
        )));
    }

    public function testDefaultPolicyCarriesDisconnectAndRetentionBoundaries(): void
    {
        $policy = TaskPolicy::defaults();

        self::assertSame(600, $policy->leaseTtlSeconds);
        self::assertSame(30, $policy->leaseRenewalSeconds);
        self::assertSame(86_400, $policy->terminalRetentionSeconds);
        self::assertSame(50_000, $policy->maxEvents);
        self::assertSame(52_428_800, $policy->maxEventBacklogBytes);
    }

    public function testStartingSnapshotAllowsUnclaimedCountersOnlyBeforeRunnerClaim(): void
    {
        $owner = new TaskOwner('frontend', 'customer:42');
        $policy = TaskPolicy::defaults();
        $snapshot = new TaskSnapshot(
            taskId: 'task-00000001',
            typeCode: 'websites.site_build',
            status: ResumableTaskStatus::STARTING,
            owner: $owner,
            policy: $policy,
            attempt: 0,
            maxAttempts: 4,
            fencingGeneration: 0,
            checkpoint: null,
            latestEventSequence: 0,
            result: null,
            errorCode: null,
            terminalReason: '',
            createdAt: 1_700_000_000,
            updatedAt: 1_700_000_000,
        );

        self::assertSame(0, $snapshot->attempt);
        self::assertSame(0, $snapshot->fencingGeneration);

        $this->expectException(\InvalidArgumentException::class);
        new TaskSnapshot(
            taskId: 'task-00000001',
            typeCode: 'websites.site_build',
            status: ResumableTaskStatus::RUNNING,
            owner: $owner,
            policy: $policy,
            attempt: 0,
            maxAttempts: 4,
            fencingGeneration: 0,
            checkpoint: null,
            latestEventSequence: 0,
            result: null,
            errorCode: null,
            terminalReason: '',
            createdAt: 1_700_000_000,
            updatedAt: 1_700_000_000,
        );
    }

    public function testLeaseIsIndependentFromStreamAndExpiresAtBoundary(): void
    {
        $lease = new TaskLease(
            leaseId: 'lease-00000001',
            taskId: 'task-00000001',
            owner: new TaskOwner('frontend', 'customer:42'),
            subscriptionId: 'tab-0001',
            lastSeenAt: 100,
            expiresAt: 700,
        );

        self::assertFalse($lease->isExpired(699));
        self::assertTrue($lease->isExpired(700));
    }

    public function testEffectReservationUsesStableIdempotencyKeyAndUnknownIsNotRetrySafe(): void
    {
        $reservation = new TaskEffectReservation(
            taskId: 'task-00000001',
            effectKey: 'purchase-domain:example.com',
            state: TaskEffectState::UNKNOWN,
            alreadyExisted: true,
            externalReference: 'provider-order-42',
            result: ['provider_order_id' => 'provider-order-42'],
            attempt: 2,
            fencingGeneration: 5,
        );

        self::assertSame('task-00000001:purchase-domain:example.com', $reservation->externalIdempotencyKey());
        self::assertTrue($reservation->requiresManualRecovery());
        self::assertFalse(TaskEffectState::UNKNOWN->isSafeToRetry());
        self::assertTrue(TaskEffectState::RESERVED->isSafeToRetry());
    }

    public function testTerminalSnapshotCannotContainMismatchedResultStatus(): void
    {
        $owner = new TaskOwner('frontend', 'customer:42');
        $policy = TaskPolicy::defaults();
        $snapshot = new TaskSnapshot(
            taskId: 'task-00000001',
            typeCode: 'websites.site_build',
            status: ResumableTaskStatus::COMPLETED,
            owner: $owner,
            policy: $policy,
            attempt: 1,
            maxAttempts: 4,
            fencingGeneration: 1,
            checkpoint: null,
            latestEventSequence: 12,
            result: TaskResult::completed(['website_id' => 0]),
            errorCode: null,
            terminalReason: '',
            createdAt: 1_700_000_000,
            updatedAt: 1_700_000_005,
            completedAt: 1_700_000_005,
        );

        self::assertTrue($snapshot->isTerminal());
        self::assertSame(0, $snapshot->result?->data['website_id']);

        $this->expectException(\InvalidArgumentException::class);
        new TaskSnapshot(
            taskId: 'task-00000001',
            typeCode: 'websites.site_build',
            status: ResumableTaskStatus::COMPLETED,
            owner: $owner,
            policy: $policy,
            attempt: 1,
            maxAttempts: 4,
            fencingGeneration: 1,
            checkpoint: null,
            latestEventSequence: 12,
            result: TaskResult::failed('unexpected'),
            errorCode: null,
            terminalReason: '',
            createdAt: 1_700_000_000,
            updatedAt: 1_700_000_005,
            completedAt: 1_700_000_005,
        );
    }
}
