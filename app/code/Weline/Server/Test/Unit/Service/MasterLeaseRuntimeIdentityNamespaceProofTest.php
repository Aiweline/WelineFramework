<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Covers the pure NSpid decision used by the PID namespace fallback.
 *
 * The Linux branch itself cannot run on Darwin/Windows hosts, so the decision is
 * extracted into {@see MasterLeaseRuntimeIdentity::nsPidProvesSameNamespace()}
 * (pure array/string logic) and pinned here for every namespace shape.
 */
final class MasterLeaseRuntimeIdentityNamespaceProofTest extends TestCase
{
    private const SHARED_NAMESPACE = 'pid:[4026531836]';
    private const TARGET_PID = 43210;
    private const SELF_PID = 1111;

    /** @param list<int>|null $targetNspid @param list<int>|null $selfNspid */
    private function prove(
        ?array $targetNspid,
        ?array $selfNspid,
        int $pid = self::TARGET_PID,
        int $selfPid = self::SELF_PID,
        ?string $selfNamespace = self::SHARED_NAMESPACE,
    ): ?string {
        $method = new \ReflectionMethod(
            MasterLeaseRuntimeIdentity::class,
            'nsPidProvesSameNamespace',
        );
        $method->setAccessible(true);

        return $method->invoke(null, $targetNspid, $selfNspid, $pid, $selfPid, $selfNamespace);
    }

    public function testSingleEntrySnapshotsProveTheSharedNamespace(): void
    {
        self::assertSame(
            self::SHARED_NAMESPACE,
            $this->prove([self::TARGET_PID], [self::SELF_PID]),
        );
    }

    public function testNestedNamespaceSnapshotsAreRejected(): void
    {
        // Two entries means the process also belongs to an inner namespace, so
        // the procfs mount namespace is not proven and we must fail closed.
        self::assertNull($this->prove([self::TARGET_PID, 7], [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID, 9]));
        self::assertNull($this->prove([self::TARGET_PID, 7], [self::SELF_PID, 9]));
    }

    public function testMissingOrEmptySnapshotsAreRejected(): void
    {
        self::assertNull($this->prove(null, [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], null));
        self::assertNull($this->prove(null, null));
        self::assertNull($this->prove([], []));
        self::assertNull($this->prove([], [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], []));
    }

    public function testNonIntegerSnapshotsAreRejected(): void
    {
        // A string PID never equals the int PID strictly, so a malformed NSpid
        // snapshot can never be mistaken for a proven namespace.
        self::assertNull($this->prove(['43210'], [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], ['1111']));
        self::assertNull($this->prove([43210.0], [self::SELF_PID]));
        self::assertNull($this->prove([true], [self::SELF_PID]));
        self::assertNull($this->prove(['abc'], [self::SELF_PID]));
    }

    public function testOutOfRangeOrNegativeSnapshotsAreRejected(): void
    {
        self::assertNull($this->prove([-self::TARGET_PID], [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], [-self::SELF_PID]));
        self::assertNull($this->prove([0], [self::SELF_PID]));
        // Longer than the 20-digit kernel PID ceiling.
        self::assertNull($this->prove([999999999999999999999], [self::SELF_PID]));
    }

    public function testSnapshotEntryMustMatchThePidItDescribes(): void
    {
        // Guards against a cross-wired snapshot: the single entry has to be the
        // exact PID the caller asked about, otherwise the namespace id would be
        // borrowed from a different process.
        self::assertNull($this->prove([self::TARGET_PID + 1], [self::SELF_PID]));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID + 1]));
        self::assertNull(
            $this->prove([self::SELF_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID),
        );
    }

    public function testSelfNamespaceMustBeAValidPidNamespaceId(): void
    {
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, null));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, ''));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, 'pid:[]'));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, 'pid:[0]'));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, 'pid:[4026531836'));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, 'pid:[abc]'));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, 'net:[4026531840]'));
        self::assertNull($this->prove([self::TARGET_PID], [self::SELF_PID], self::TARGET_PID, self::SELF_PID, ' pid:[4026531836]'));
    }

    public function testInvalidPidsAreRejected(): void
    {
        self::assertNull($this->prove([0], [self::SELF_PID], 0, self::SELF_PID));
        self::assertNull($this->prove([self::TARGET_PID], [0], self::TARGET_PID, 0));
        self::assertNull($this->prove([-1], [-2], -1, -2));
        // A process never needs a fallback for its own namespace: readlink on
        // /proc/self/ns/pid always succeeds.
        self::assertNull(
            $this->prove([self::SELF_PID], [self::SELF_PID], self::SELF_PID, self::SELF_PID),
        );
    }
}
