<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayProjectIdentityRotator;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\Edge\Nginx\ManagedNginxConfigWriter;
use Weline\Server\Service\ServiceOrchestrator;

/**
 * Aggregated contract suite (merged from 16 *ContractTest files).
 * Observe one overall pass/fail via testAllServerGatewayContractsPass().
 */
final class ServerGatewayContractsTest extends TestCase
{
    public function testAllServerGatewayContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'GatewayControllerColdBootRecovery::testMutableDataPlaneRecoveryWaitsForAuthenticatedBootstrap' => function () {
            $this->assert_GatewayControllerColdBootRecovery_testMutableDataPlaneRecoveryWaitsForAuthenticatedBootstrap();
        },
            'GatewayControllerColdBootRecovery::testBootstrapAttestsSecurityRootBeforeDataPlaneRecovery' => function () {
            $this->assert_GatewayControllerColdBootRecovery_testBootstrapAttestsSecurityRootBeforeDataPlaneRecovery();
        },
            'GatewayGuardianTransitionRetirement::testTerminalRecoveryTransactionIsRetiredBeforeTheNextRequest' => function () {
            $this->setUp_GatewayGuardianTransitionRetirement();
            $this->assert_GatewayGuardianTransitionRetirement_testTerminalRecoveryTransactionIsRetiredBeforeTheNextRequest();
        },
            'GatewayGuardianTransitionRetirement::testSignedRetirementMarkerMakesMultiFileDeletionCrashReplayable' => function () {
            $this->setUp_GatewayGuardianTransitionRetirement();
            $this->assert_GatewayGuardianTransitionRetirement_testSignedRetirementMarkerMakesMultiFileDeletionCrashReplayable();
        },
            'GatewayGuardianTransitionRetirement::testRetirementLeafIsAFormalCrossPlatformTrustContract' => function () {
            $this->setUp_GatewayGuardianTransitionRetirement();
            $this->assert_GatewayGuardianTransitionRetirement_testRetirementLeafIsAFormalCrossPlatformTrustContract();
        },
            'GatewayGuardianTransitionRetirement::testGuardianRecoveryInventoryNamesTheFixedNeutralTlsNamespace' => function () {
            $this->setUp_GatewayGuardianTransitionRetirement();
            $this->assert_GatewayGuardianTransitionRetirement_testGuardianRecoveryInventoryNamesTheFixedNeutralTlsNamespace();
        },
            'GatewayLauncherRecovery::testPersistentRecoveryPolicyAndSupervisorRetryRemainAligned' => function () {
            $this->assert_GatewayLauncherRecovery_testPersistentRecoveryPolicyAndSupervisorRetryRemainAligned();
        },
            'GatewayLauncherRecovery::testPosixHealthProofBindsPidBirthAndExecutableDigestWithoutSignallingIt' => function () {
            $this->assert_GatewayLauncherRecovery_testPosixHealthProofBindsPidBirthAndExecutableDigestWithoutSignallingIt();
        },
            'GatewayLauncherRecovery::testLauncherProjectionCannotBecomeControlAuthority' => function () {
            $this->assert_GatewayLauncherRecovery_testLauncherProjectionCannotBecomeControlAuthority();
        },
            'GatewayLauncherRecovery::testPosixSystemdGuardianUsesDedicatedDefinitionAndVerifiesFixedLink' => function () {
            $this->assert_GatewayLauncherRecovery_testPosixSystemdGuardianUsesDedicatedDefinitionAndVerifiesFixedLink();
        },
            'GatewayLauncherRecovery::testPosixCapacityKeepsIndependentPlatformFilesystemReserve' => function () {
            $this->assert_GatewayLauncherRecovery_testPosixCapacityKeepsIndependentPlatformFilesystemReserve();
        },
            'GatewayNativeNginxTest::testProductionPublicationUsesNativeCandidateTest' => function () {
            $this->assert_GatewayNativeNginxTest_testProductionPublicationUsesNativeCandidateTest();
        },
            'GatewayNativeNginxTest::testLkgRollbackPersistsOneShotBoundIntentBeforeNativeTest' => function () {
            $this->assert_GatewayNativeNginxTest_testLkgRollbackPersistsOneShotBoundIntentBeforeNativeTest();
        },
            'GatewayNativeNginxTest::testLkgIntentCanonicalBindsRetainedBundleAndCurrentFence' => function () {
            $this->assert_GatewayNativeNginxTest_testLkgIntentCanonicalBindsRetainedBundleAndCurrentFence();
        },
            'GatewayNativeWaitpidDeadline::testPrivilegedPosixChildrenNeverUseBlockingWaitpid' => function () {
            $this->assert_GatewayNativeWaitpidDeadline_testPrivilegedPosixChildrenNeverUseBlockingWaitpid();
        },
            'GatewayProtocolReceipt::testLeaseReceiptValidationBindsCurrentHostBootBeforeUsingMonotonicTime' => function () {
            $this->assert_GatewayProtocolReceipt_testLeaseReceiptValidationBindsCurrentHostBootBeforeUsingMonotonicTime();
        },
            'GatewayProtocolReceipt::testEnrollmentWireDigestExcludesOnlyDerivedEnvelopeFields' => function () {
            $this->assert_GatewayProtocolReceipt_testEnrollmentWireDigestExcludesOnlyDerivedEnvelopeFields();
        },
            'GatewayProtocolReceipt::testDrainOperationIdentityBindsLaunchAndCannotExtendWithSeconds' => function () {
            $this->assert_GatewayProtocolReceipt_testDrainOperationIdentityBindsLaunchAndCannotExtendWithSeconds();
        },
            'GatewayProtocolReceipt::testDrainPersistsPublishedLifecycleReceiptAndRefreshesItWhileWaiting' => function () {
            $this->assert_GatewayProtocolReceipt_testDrainPersistsPublishedLifecycleReceiptAndRefreshesItWhileWaiting();
        },
            'GatewayProtocolReceipt::testRotationPrepareDigestAndIdempotencyMatchProtocolFacts' => function () {
            $this->assert_GatewayProtocolReceipt_testRotationPrepareDigestAndIdempotencyMatchProtocolFacts();
        },
            'GatewayProtocolReceipt::testAuthenticatedRotationPrepareKeepsOnlyPendingCredentialShape' => function () {
            $this->assert_GatewayProtocolReceipt_testAuthenticatedRotationPrepareKeepsOnlyPendingCredentialShape();
        },
            'GatewayProtocolReceipt::testRotationLocalAfterImageMustProveCommittedCredentialSecret' => function () {
            $this->assert_GatewayProtocolReceipt_testRotationLocalAfterImageMustProveCommittedCredentialSecret();
        },
            'GatewayProtocolReceipt::testLeaseReceiptMustMatchExactAuthenticatedPublicationFence' => function () {
            $this->assert_GatewayProtocolReceipt_testLeaseReceiptMustMatchExactAuthenticatedPublicationFence();
        },
            'GatewayProtocolReceipt::testConcurrentReceiptCompletionCannotOverwriteNewerPublication' => function () {
            $this->assert_GatewayProtocolReceipt_testConcurrentReceiptCompletionCannotOverwriteNewerPublication();
        },
            'GatewayProtocolReceipt::testDurableLeaseReceiptCommitIsNotReclassifiedByExpiredBusinessDeadline' => function () {
            $this->assert_GatewayProtocolReceipt_testDurableLeaseReceiptCommitIsNotReclassifiedByExpiredBusinessDeadline();
        },
            'GatewayProtocolReceipt::testExpiredRegistrationDeadlineIsNotReusedForFailureCompensation' => function () {
            $this->assert_GatewayProtocolReceipt_testExpiredRegistrationDeadlineIsNotReusedForFailureCompensation();
        },
            'GatewayProtocolReceipt::testPosixSnapshotReceiptV2BindsImmutableEntityAndRootOnlyMac' => function () {
            $this->assert_GatewayProtocolReceipt_testPosixSnapshotReceiptV2BindsImmutableEntityAndRootOnlyMac();
        },
            'GatewayProtocolReceipt::testPosixNginxTestIsPublicationBoundAndRestoresTemporaryAcl' => function () {
            $this->assert_GatewayProtocolReceipt_testPosixNginxTestIsPublicationBoundAndRestoresTemporaryAcl();
        },
            'GatewayProtocolReceipt::testPosixReloadTestsSameFenceBeforeSignallingMaster' => function () {
            $this->assert_GatewayProtocolReceipt_testPosixReloadTestsSameFenceBeforeSignallingMaster();
        },
            'NativeGatewayAdminStoppedTransaction::testBothNativeStopWritersUseTheSharedPackageTransactionLock' => function () {
            $this->assert_NativeGatewayAdminStoppedTransaction_testBothNativeStopWritersUseTheSharedPackageTransactionLock();
        },
            'NativeGatewayAdminStoppedTransaction::testPhpClearAndRestoreShareTheLockAndRestoreIsCompareAbsent' => function () {
            $this->assert_NativeGatewayAdminStoppedTransaction_testPhpClearAndRestoreShareTheLockAndRestoreIsCompareAbsent();
        },
            'NativeGatewayAdminStoppedTransaction::testExplicitStartAndRebootstrapReserveAndProveCompensation' => function () {
            $this->assert_NativeGatewayAdminStoppedTransaction_testExplicitStartAndRebootstrapReserveAndProveCompensation();
        },
            'NativeGatewayAdminStoppedTransaction::testBothNativeBootstrapClientsUseTheProtocolSecretVerbatim' => function () {
            $this->assert_NativeGatewayAdminStoppedTransaction_testBothNativeBootstrapClientsUseTheProtocolSecretVerbatim();
        },
            'NativeGatewayAtomicCandidateRecovery::testBothBrokersInventoryOnlyTheirCompleteReusableTargetClosure' => function () {
            $this->assert_NativeGatewayAtomicCandidateRecovery_testBothBrokersInventoryOnlyTheirCompleteReusableTargetClosure();
        },
            'NativeGatewayAtomicCandidateRecovery::testDiscoveryIsBoundedExactAndRejectsMalformedReservedLeaves' => function () {
            $this->assert_NativeGatewayAtomicCandidateRecovery_testDiscoveryIsBoundedExactAndRejectsMalformedReservedLeaves();
        },
            'NativeGatewayAtomicCandidateRecovery::testEveryCommittedPairIsSemanticallyValidatedBeforeAnyDeletion' => function () {
            $this->assert_NativeGatewayAtomicCandidateRecovery_testEveryCommittedPairIsSemanticallyValidatedBeforeAnyDeletion();
        },
            'NativeGatewayAtomicCandidateRecovery::testRecoveryRunsInsidePackageAndLifecycleLocksBeforeNewFencingPublication' => function () {
            $this->assert_NativeGatewayAtomicCandidateRecovery_testRecoveryRunsInsidePackageAndLifecycleLocksBeforeNewFencingPublication();
        },
            'NativeGatewayAtomicPendingLedger::testSecurityLedgerAuxiliaryFilesAreExactBoundedAtomicTargetsOnBothPlatforms' => function () {
            $this->assert_NativeGatewayAtomicPendingLedger_testSecurityLedgerAuxiliaryFilesAreExactBoundedAtomicTargetsOnBothPlatforms();
        },
            'NativeGatewayBootstrapDeadline::testBootstrapWireBudgetContainsTheControllerBudgetOnBothPlatforms' => function () {
            $this->setUp_NativeGatewayBootstrapDeadline();
            $this->assert_NativeGatewayBootstrapDeadline_testBootstrapWireBudgetContainsTheControllerBudgetOnBothPlatforms();
        },
            'NativeGatewayBootstrapDeadline::testActionCapCoversTheRouteQuotaAndFailsDeterministically' => function () {
            $this->setUp_NativeGatewayBootstrapDeadline();
            $this->assert_NativeGatewayBootstrapDeadline_testActionCapCoversTheRouteQuotaAndFailsDeterministically();
        },
            'NativeGatewayBootstrapDeadline::testPosixBootstrapUsesOneDeadlineAcrossLockAndEveryWireStep' => function () {
            $this->setUp_NativeGatewayBootstrapDeadline();
            $this->assert_NativeGatewayBootstrapDeadline_testPosixBootstrapUsesOneDeadlineAcrossLockAndEveryWireStep();
        },
            'NativeGatewayBootstrapDeadline::testWindowsBootstrapUsesOneDeadlineAndStopFenceAcrossWireSteps' => function () {
            $this->setUp_NativeGatewayBootstrapDeadline();
            $this->assert_NativeGatewayBootstrapDeadline_testWindowsBootstrapUsesOneDeadlineAndStopFenceAcrossWireSteps();
        },
            'NativeGatewayControllerOnlyRecovery::testControllerOnlyBootstrapKeepsFiveSecondDataPlaneFenceOnBothPlatforms' => function () {
            $this->setUp_NativeGatewayControllerOnlyRecovery();
            $this->assert_NativeGatewayControllerOnlyRecovery_testControllerOnlyBootstrapKeepsFiveSecondDataPlaneFenceOnBothPlatforms();
        },
            'NativeGatewayControllerOnlyRecovery::testPosixDataPlaneDownSkipsNormalZeroExitAndRequestsFullTreeRestart' => function () {
            $this->setUp_NativeGatewayControllerOnlyRecovery();
            $this->assert_NativeGatewayControllerOnlyRecovery_testPosixDataPlaneDownSkipsNormalZeroExitAndRequestsFullTreeRestart();
        },
            'NativeGatewayControllerOnlyRecovery::testWindowsControllerReadyWaitUsesOneAbsoluteDeadlineAndAllCancellationHandles' => function () {
            $this->setUp_NativeGatewayControllerOnlyRecovery();
            $this->assert_NativeGatewayControllerOnlyRecovery_testWindowsControllerReadyWaitUsesOneAbsoluteDeadlineAndAllCancellationHandles();
        },
            'NativeGatewayControllerOnlyRecovery::testWindowsRecoveryRetainsVerifiedHandlesAndRequiresStableReadyWindow' => function () {
            $this->setUp_NativeGatewayControllerOnlyRecovery();
            $this->assert_NativeGatewayControllerOnlyRecovery_testWindowsRecoveryRetainsVerifiedHandlesAndRequiresStableReadyWindow();
        },
            'NativeGatewayEmergencyBinding::testControllerAndBothBrokersShareTheExactBindingEnvelope' => function () {
            $this->setUp_NativeGatewayEmergencyBinding();
            $this->assert_NativeGatewayEmergencyBinding_testControllerAndBothBrokersShareTheExactBindingEnvelope();
        },
            'NativeGatewayEmergencyBinding::testPosixBindingIsCommittedOwnerFencedAndSecretPrivate' => function () {
            $this->setUp_NativeGatewayEmergencyBinding();
            $this->assert_NativeGatewayEmergencyBinding_testPosixBindingIsCommittedOwnerFencedAndSecretPrivate();
        },
            'NativeGatewayEmergencyBinding::testWindowsBindingHasEquivalentAclLedgerAndConflictSemantics' => function () {
            $this->setUp_NativeGatewayEmergencyBinding();
            $this->assert_NativeGatewayEmergencyBinding_testWindowsBindingHasEquivalentAclLedgerAndConflictSemantics();
        },
            'NativeGatewayRollbackAction::testBothNativeBrokersOwnThePackageLockedRollbackPublication' => function () {
            $this->assert_NativeGatewayRollbackAction_testBothNativeBrokersOwnThePackageLockedRollbackPublication();
        },
            'NativeGatewayRollbackAction::testControllerUsesOnlyTheBoundBrokerReceiptForRollback' => function () {
            $this->assert_NativeGatewayRollbackAction_testControllerUsesOnlyTheBoundBrokerReceiptForRollback();
        },
            'NativeGatewayRollbackAction::testControllerRollbackContractMatchesThePackageContractExactly' => function () {
            $this->assert_NativeGatewayRollbackAction_testControllerRollbackContractMatchesThePackageContractExactly();
        },
            'NativeGatewayRollbackAction::testPackageManagerCleansOnlyAFullyBoundCurrentRequestUnderInstallLock' => function () {
            $this->assert_NativeGatewayRollbackAction_testPackageManagerCleansOnlyAFullyBoundCurrentRequestUnderInstallLock();
        },
            'NativeGatewayWindowsCapacity::testPhysicalAllocationAndInodeIdentityProofAreNative' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testPhysicalAllocationAndInodeIdentityProofAreNative();
        },
            'NativeGatewayWindowsCapacity::testProgramDataAuthorityIgnoresEnvironmentAndRejectsReparseRoots' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testProgramDataAuthorityIgnoresEnvironmentAndRejectsReparseRoots();
        },
            'NativeGatewayWindowsCapacity::testHeldCannotBypassDurableReleaseTransition' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testHeldCannotBypassDurableReleaseTransition();
        },
            'NativeGatewayWindowsCapacity::testEveryRebootstrapDerivedRootIsAnchoredBeforeMutation' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testEveryRebootstrapDerivedRootIsAnchoredBeforeMutation();
        },
            'NativeGatewayWindowsCapacity::testWindowsBuildAndRealIntegrationOwnTheCapacityContract' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testWindowsBuildAndRealIntegrationOwnTheCapacityContract();
        },
            'NativeGatewayWindowsCapacity::testProductionCapacityIsAnExplicitDedicatedWindowsCrashMatrixGate' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testProductionCapacityIsAnExplicitDedicatedWindowsCrashMatrixGate();
        },
            'NativeGatewayWindowsCapacity::testInspectMatchesTheReadOnlyFailClosedCapacityProtocol' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testInspectMatchesTheReadOnlyFailClosedCapacityProtocol();
        },
            'NativeGatewayWindowsCapacity::testDefinitionParentCreditsAreDurableAndLifecycleBound' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testDefinitionParentCreditsAreDurableAndLifecycleBound();
        },
            'NativeGatewayWindowsCapacity::testProductionCrashGateKillsRealHelpersAndRecoversDurableState' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testProductionCrashGateKillsRealHelpersAndRecoversDurableState();
        },
            'NativeGatewayWindowsCapacity::testCrashIntermediatesKeepExactAclAndReplayableState' => function () {
            $this->setUp_NativeGatewayWindowsCapacity();
            $this->assert_NativeGatewayWindowsCapacity_testCrashIntermediatesKeepExactAclAndReplayableState();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testBrokerOwnsTheWindowsNginxPidNamespaceAcrossTestStartAndTerminalCleanup' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testBrokerOwnsTheWindowsNginxPidNamespaceAcrossTestStartAndTerminalCleanup();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testPidSourcePublicationKeepsTheWindowsParentAclStable' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testPidSourcePublicationKeepsTheWindowsParentAclStable();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testDataPlaneUsesAProductRestrictingSidAndCannotEnterControlPipe' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testDataPlaneUsesAProductRestrictingSidAndCannotEnterControlPipe();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testNginxGetsOnlyValidatedPreboundSocketsInsideTheDataPlaneJob' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testNginxGetsOnlyValidatedPreboundSocketsInsideTheDataPlaneJob();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testNginxTestUsesSealedCandidateAclAndIndependentLkgSourceClosure' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testNginxTestUsesSealedCandidateAclAndIndependentLkgSourceClosure();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testSnapshotAndReceiptPublicationRecoverOnlyExactDigestBoundTemporaries' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testSnapshotAndReceiptPublicationRecoverOnlyExactDigestBoundTemporaries();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testLauncherRequiresExactSignedSchemaTwoRuntimeClosure' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherRequiresExactSignedSchemaTwoRuntimeClosure();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testRollbackTargetProofIsLazyAndSelfTestExercisesPositiveAndNegativeSlots' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testRollbackTargetProofIsLazyAndSelfTestExercisesPositiveAndNegativeSlots();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testLauncherAndBrokerRequireDurableExactDataPlanePathAcls' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherAndBrokerRequireDurableExactDataPlanePathAcls();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testLauncherOwnsAStableNestedJobAcrossBrokerRestarts' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherOwnsAStableNestedJobAcrossBrokerRestarts();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testGuardianIsTheOnlyScmEntrypointAndBothVariantsRemainWarningClean' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testGuardianIsTheOnlyScmEntrypointAndBothVariantsRemainWarningClean();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testGuardianRequiresPreshutdownAndAnExactProtectedScmDacl' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testGuardianRequiresPreshutdownAndAnExactProtectedScmDacl();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testPreshutdownPersistenceFailureCannotRestartTheDataPlaneInTheSameBoot' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testPreshutdownPersistenceFailureCannotRestartTheDataPlaneInTheSameBoot();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testRecoveryScmMutationIsSealedAndTransactional' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testRecoveryScmMutationIsSealedAndTransactional();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testContinuityPublisherPersistsItsMonotonicFloorInsideTheHeadLock' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testContinuityPublisherPersistsItsMonotonicFloorInsideTheHeadLock();
        },
            'NativeGatewayWindowsDataPlaneSecurity::testNeutralTlsServingPairIsBrokerSealedAndRevalidatedBeforeEverySpawn' => function () {
            $this->setUp_NativeGatewayWindowsDataPlaneSecurity();
            $this->assert_NativeGatewayWindowsDataPlaneSecurity_testNeutralTlsServingPairIsBrokerSealedAndRevalidatedBeforeEverySpawn();
        },
            'PureWlsServingManifestRecovery::testMultiDomainRecoverySelectsTheRequestedHostInsteadOfTheFirstRoute' => function () {
            $this->assert_PureWlsServingManifestRecovery_testMultiDomainRecoverySelectsTheRequestedHostInsteadOfTheFirstRoute();
        },
            'PureWlsServingManifestRecovery::testTerminalEndpointCanRecoverItsImmutablePreviousMasterFence' => function () {
            $this->assert_PureWlsServingManifestRecovery_testTerminalEndpointCanRecoverItsImmutablePreviousMasterFence();
        },
            'PureWlsServingManifestRecovery::testStaleManifestFailurePromptsExplicitTargetedCleanStart' => function () {
            $this->assert_PureWlsServingManifestRecovery_testStaleManifestFailurePromptsExplicitTargetedCleanStart();
        },
            'PureWlsServingManifestRecovery::testMonotonicRebuildUsesWholeProjectAuthorityAndPreservesSiblingDomain' => function () {
            $this->assert_PureWlsServingManifestRecovery_testMonotonicRebuildUsesWholeProjectAuthorityAndPreservesSiblingDomain();
        },
            'PureWlsServingManifestRecovery::testReusedSelectorCarriesTheCompleteAfterImageWithoutActivation' => function () {
            $this->assert_PureWlsServingManifestRecovery_testReusedSelectorCarriesTheCompleteAfterImageWithoutActivation();
        },
            'PureWlsServingManifestRecovery::testServingManifestRecoveryCarriesItsSelectedDomainAfterImage' => function () {
            $this->assert_PureWlsServingManifestRecovery_testServingManifestRecoveryCarriesItsSelectedDomainAfterImage();
        },
            'PureWlsServingManifestRecovery::testAutoFallbackAcceptsTheExactReusedSelectorIdentity' => function () {
            $this->assert_PureWlsServingManifestRecovery_testAutoFallbackAcceptsTheExactReusedSelectorIdentity();
        },
            'PureWlsServingManifestRecovery::testTombstoneAndAbsentAuthorityCannotFallThroughToLegacyPemOrSigning' => function () {
            $this->assert_PureWlsServingManifestRecovery_testTombstoneAndAbsentAuthorityCannotFallThroughToLegacyPemOrSigning();
        },
            'PureWlsServingManifestRecovery::testCertificateLifecycleAlwaysPrecedesPublicationTransaction' => function () {
            $this->assert_PureWlsServingManifestRecovery_testCertificateLifecycleAlwaysPrecedesPublicationTransaction();
        },
        ];
    }

private function assert_GatewayControllerColdBootRecovery_testMutableDataPlaneRecoveryWaitsForAuthenticatedBootstrap(): void
{
    $run = self::controllerMethodSource_GatewayControllerColdBootRecovery('run');
    self::assertMatchesRegularExpression('/if \(\$this->readOnlyRecoveryMode\) \{\s*' . '\$this->adoptOrRecoverDataPlane\(\);\s*' . '\} else \{\s*' . '\$this->startupDataPlaneRecoveryPending = true;\s*\}/s', $run);
    self::assertLessThan(\strpos($run, '$this->controlServer = $this->openControlServer();'), \strpos($run, '$this->startupDataPlaneRecoveryPending = true;'), 'Production startup must expose the control transport while recovery remains pending.');
}

private function assert_GatewayControllerColdBootRecovery_testBootstrapAttestsSecurityRootBeforeDataPlaneRecovery(): void
{
    $bootstrap = self::controllerMethodSource_GatewayControllerColdBootRecovery('bootstrapRecovery');
    $attest = \strpos($bootstrap, '$this->attestBrokerSecurityRoot(\'bootstrap\');');
    $adopt = \strpos($bootstrap, '$this->adoptOrRecoverDataPlane();');
    self::assertIsInt($attest);
    self::assertIsInt($adopt);
    self::assertLessThan($adopt, $attest, 'The current Broker identity must be authenticated before snapshot-backed recovery.');
}

private function controllerMethodSource_GatewayControllerColdBootRecovery(string $method): string
{
    if (!\class_exists('WlsEdgeGatewayController', false)) {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/wls_gateway_controller.php';
    }
    $reflection = new \ReflectionMethod('WlsEdgeGatewayController', $method);
    $lines = \file((string) $reflection->getFileName());
    self::assertIsArray($lines);
    return \implode('', \array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

private string $gateway_GatewayGuardianTransitionRetirement;

private function setUp_GatewayGuardianTransitionRetirement(): void
{
    $this->gateway_GatewayGuardianTransitionRetirement = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway';
}

private function assert_GatewayGuardianTransitionRetirement_testTerminalRecoveryTransactionIsRetiredBeforeTheNextRequest(): void
{
    $protocol = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayGuardianTransitionProtocol.php');
    $ensure = $this->methodBody_GatewayGuardianTransitionRetirement($protocol, 'private function ensureCommitRequest(', 'private function recoverHandshakeArtifacts(');
    self::assertStringContainsString('$this->retireCompletedHandshakeForNextRequestWhileLocked()', $ensure);
    self::assertStringContainsString('$this->retireOrphanedTerminalHandshakeWhileLocked();', $ensure);
    self::assertLessThan(\strpos($ensure, '$existing = GatewayProjectStateFilesystem::readOptional('), \strpos($ensure, '$this->resumeHandshakeRetirementWhileLocked();'));
    self::assertLessThan(\strpos($ensure, '$head = $this->generationHead()->readWhileLocked();'), \strpos($ensure, '$this->retireOrphanedTerminalHandshakeWhileLocked();'));
    $terminal = $this->methodBody_GatewayGuardianTransitionRetirement($protocol, 'private function terminalHandshakeRetirement(', 'private function publishHandshakeRetirementWhileLocked(');
    foreach (["!\\hash_equals('STABLE', (string)(\$head['phase'] ?? ''))", "\$purpose === 'rollback'", '$this->assertTerminalRecoveryTransaction(', "'transaction_sha256' => \$transactionRaw === null"] as $contract) {
        self::assertStringContainsString($contract, $terminal);
    }
    self::assertStringContainsString("(int)\$transaction['sequence'] !== 26", $protocol);
    self::assertStringContainsString('$this->recoveryTransactionRawAtSequence(', $protocol);
}

private function assert_GatewayGuardianTransitionRetirement_testSignedRetirementMarkerMakesMultiFileDeletionCrashReplayable(): void
{
    $protocol = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayGuardianTransitionProtocol.php');
    self::assertStringContainsString('WLS-GUARDIAN-TRANSITION-RETIREMENT/1', $protocol);
    self::assertMatchesRegularExpression('/\$this->signature\(\s*' . '\$this->encodeHandshakeRetirementUnsigned\(/s', $protocol);
    $resume = $this->methodBody_GatewayGuardianTransitionRetirement($protocol, 'private function resumeHandshakeRetirementWhileLocked(', 'private function retireCompletedHandshakeForNextRequestWhileLocked(');
    $request = \strpos($resume, '$this->paths->guardianTransitionRequestFile() => [');
    $ack = \strpos($resume, '$this->paths->guardianTransitionAcknowledgementFile() => [');
    $transaction = \strpos($resume, '$this->paths->guardianRecoveryTransactionFile() => [');
    $markerRemoval = \strrpos($resume, "'Recovery Guardian handshake retirement'");
    self::assertIsInt($request);
    self::assertIsInt($ack);
    self::assertIsInt($transaction);
    self::assertIsInt($markerRemoval);
    self::assertLessThan($ack, $request);
    self::assertLessThan($transaction, $ack);
    self::assertLessThan($markerRemoval, $transaction);
    self::assertStringContainsString('foreach (\array_keys($definitions) as $artifact)', $resume);
}

private function assert_GatewayGuardianTransitionRetirement_testRetirementLeafIsAFormalCrossPlatformTrustContract(): void
{
    $paths = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayPaths.php');
    $protocol = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayGuardianTransitionProtocol.php');
    $installer = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayPlatformServiceInstaller.php');
    $manager = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/HostGatewayPackageManager.php');
    $posix = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/Native/posix/wls_gateway_launcher.c');
    foreach ([$paths, $protocol, $installer, $manager, $posix] as $source) {
        self::assertStringContainsString('guardian-transition.retirement', $source);
    }
    self::assertStringContainsString('public function guardianTransitionRetirementFile(): string', $paths);
    self::assertStringContainsString('$this->paths->guardianTransitionRetirementFile()', $protocol);
}

private function assert_GatewayGuardianTransitionRetirement_testGuardianRecoveryInventoryNamesTheFixedNeutralTlsNamespace(): void
{
    $protocol = (string) \file_get_contents($this->gateway_GatewayGuardianTransitionRetirement . '/GatewayGuardianTransitionProtocol.php');
    self::assertStringContainsString("'neutral-tls' => [", $protocol);
    self::assertStringContainsString("'root_id' => 'host/neutral-tls'", $protocol);
    self::assertStringContainsString("'authority_profile' => 'root-data-plane-traverse-neutral-tls-v2'", $protocol);
    self::assertStringContainsString("'authority_policy' => 'root-data-plane-traverse-neutral-tls-v2-recreate-sealed'", $protocol);
}

private function methodBody_GatewayGuardianTransitionRetirement(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start);
    $end = \strpos($source, $endNeedle, $start);
    self::assertIsInt($end);
    return \substr($source, $start, $end - $start);
}

private function assert_GatewayLauncherRecovery_testPersistentRecoveryPolicyAndSupervisorRetryRemainAligned(): void
{
    $gateway = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway';
    $ledger = (string) \file_get_contents($gateway . '/Native/wls_launcher_recovery_ledger.h');
    self::assertStringContainsString('#define WLS_RECOVERY_WINDOW_MILLISECONDS 900000ULL', $ledger);
    self::assertStringContainsString('#define WLS_RECOVERY_FAILURE_LIMIT 10U', $ledger);
    self::assertStringContainsString('#define WLS_RECOVERY_BASE_DELAY_SECONDS 5ULL', $ledger);
    self::assertStringContainsString('#define WLS_RECOVERY_MAX_DELAY_SECONDS 300ULL', $ledger);
    self::assertStringContainsString('#define WLS_RECOVERY_HEALTH_MILLISECONDS 15000ULL', $ledger);
    self::assertStringContainsString('INCOMPLETE_ATTEMPT', $ledger);
    self::assertStringContainsString('wls_recovery_initialize_invalid(', $ledger);
    $systemd = (string) \file_get_contents($gateway . '/../../../env/gateway/systemd.service.template');
    self::assertStringContainsString('StartLimitIntervalSec=0', $systemd);
    self::assertStringContainsString('RestartSec=5', $systemd);
    self::assertStringContainsString('PrivateTmp=false', $systemd);
    self::assertStringNotContainsString('PrivateTmp=true', $systemd);
    self::assertStringContainsString('CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_DAC_READ_SEARCH CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL CAP_FOWNER', $systemd);
    self::assertStringContainsString('AmbientCapabilities=CAP_NET_BIND_SERVICE CAP_DAC_READ_SEARCH CAP_SETUID CAP_SETGID CAP_CHOWN CAP_KILL CAP_FOWNER', $systemd);
    $installer = (string) \file_get_contents($gateway . '/GatewayPlatformServiceInstaller.php');
    self::assertStringContainsString("'reset=',\n            '0'", $installer);
    self::assertStringContainsString('restart/5000/restart/5000/restart/5000', $installer);
}

private function assert_GatewayLauncherRecovery_testPosixHealthProofBindsPidBirthAndExecutableDigestWithoutSignallingIt(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c');
    $start = \strpos($source, "static int wls_recovery_attested_process_live(\n" . "    const struct wls_process_attestation_receipt *receipt,\n" . "    const char *home,\n" . "    char active_slot\n" . ') {');
    $end = \strpos($source, '/* 1=exact live data-plane attestation', $start === false ? 0 : $start);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $proof = \substr($source, $start, $end - $start);
    self::assertSame(3, \substr_count($proof, 'wls_recovery_process_identity('));
    self::assertStringContainsString('start_before != receipt->start_id', $proof);
    self::assertStringContainsString('start_after != start_before', $proof);
    self::assertStringContainsString('sodium_memcmp(binary_digest, receipt->binary_digest, 64U)', $proof);
    self::assertStringContainsString('kill(pid, 0)', $proof);
    self::assertDoesNotMatchRegularExpression('/kill\(pid,\s*(?:SIG[A-Z]+|[1-9][0-9]*)\)/', $proof);
    $cmake = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native/CMakeLists.txt');
    self::assertStringContainsString('target_link_libraries(wls-gateway-launcher PRIVATE proc)', $cmake);
}

private function assert_GatewayLauncherRecovery_testLauncherProjectionCannotBecomeControlAuthority(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/GatewayHostManager.php');
    self::assertStringContainsString("'source' => 'launcher_diagnostic_projection'", $source);
    self::assertStringContainsString("'authoritative' => false", $source);
    self::assertStringContainsString("'ready' => false", $source);
}

private function assert_GatewayLauncherRecovery_testPosixSystemdGuardianUsesDedicatedDefinitionAndVerifiesFixedLink(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c');
    $targetStart = \strpos($source, 'static int wls_guardian_platform_target(');
    $verificationStart = \strpos($source, 'static int wls_guardian_systemd_fixed_link_verify(', $targetStart === false ? 0 : $targetStart);
    self::assertIsInt($targetStart);
    self::assertIsInt($verificationStart);
    $target = \substr($source, $targetStart, $verificationStart - $targetStart);
    self::assertStringContainsString('/etc/weline-gateway/weline-wls-gateway-v2.service', $target);
    self::assertStringNotContainsString('/etc/systemd/system/weline-wls-gateway-v2.service', $target);
    $liveStart = \strpos($source, 'static int wls_guardian_platform_live_verify(');
    self::assertIsInt($liveStart);
    $verification = \substr($source, $verificationStart, $liveStart - $verificationStart);
    self::assertStringContainsString('/etc/systemd/system/weline-wls-gateway-v2.service', $verification);
    self::assertStringContainsString('readlink(', $verification);
    self::assertStringContainsString('geteuid()', $verification);
    self::assertStringContainsString('getegid()', $verification);
    $liveEnd = \strpos($source, '/* Return with guardian-generation-head.lock still held.', $liveStart);
    self::assertIsInt($liveEnd);
    $live = \substr($source, $liveStart, $liveEnd - $liveStart);
    self::assertStringContainsString('wls_guardian_systemd_fixed_link_verify(definition)', $live);
}

private function assert_GatewayLauncherRecovery_testPosixCapacityKeepsIndependentPlatformFilesystemReserve(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native/posix/wls_gateway_launcher.c');
    $anchorStart = \strpos($source, 'static int wls_capacity_anchor_proof(');
    $anchorEnd = \strpos($source, 'static int wls_capacity_leaf(', $anchorStart === false ? 0 : $anchorStart);
    self::assertIsInt($anchorStart);
    self::assertIsInt($anchorEnd);
    $anchor = \substr($source, $anchorStart, $anchorEnd - $anchorStart);
    self::assertStringContainsString('struct wls_capacity_platform_anchor', $anchor);
    self::assertStringContainsString('wls_capacity_platform_requires_distinct_reserve(', $anchor);
    self::assertStringContainsString('static int wls_capacity_platform_device_self_test(void)', $source);
    self::assertStringContainsString('wls_capacity_platform_requires_distinct_reserve((dev_t)7, (dev_t)8) == 1', $source);
    self::assertStringContainsString('wls_capacity_platform_reserve_create(', $source);
    self::assertStringContainsString('wls_capacity_platform_reserve_verify(', $source);
    self::assertStringContainsString('wls_capacity_platform_reserve_release(', $source);
    self::assertStringContainsString('static int wls_capacity_platform_reserve_absent(', $source);
    self::assertStringContainsString('WLS_CAPACITY_PLATFORM_INODES == 2U', $source);
    $beginReleaseStart = \strpos($source, 'if (strcmp(operation, "begin-release") == 0)');
    $completeReleaseStart = \strpos($source, 'if (held_present) {', $beginReleaseStart === false ? 0 : $beginReleaseStart);
    self::assertIsInt($beginReleaseStart);
    self::assertIsInt($completeReleaseStart);
    $beginRelease = \substr($source, $beginReleaseStart, $completeReleaseStart - $beginReleaseStart);
    $transition = \strpos($beginRelease, 'WLS_CAPACITY_CONTROL_TRANSITION');
    $release = \strpos($beginRelease, 'wls_capacity_platform_reserve_release(');
    $finish = \strpos($beginRelease, 'wls_capacity_finish_release_control(');
    self::assertIsInt($transition);
    self::assertIsInt($release);
    self::assertIsInt($finish);
    self::assertLessThan($release, $transition);
    self::assertLessThan($finish, $release);
    self::assertStringContainsString('wls_capacity_platform_reserve_absent(', $beginRelease);
}

private function assert_GatewayNativeNginxTest_testProductionPublicationUsesNativeCandidateTest(): void
{
    $publish = self::controllerMethodSource_GatewayNativeNginxTest('publishIfDirty');
    self::assertStringContainsString('$this->brokerActionsRequired()' . "\n            ? \$this->nativeNginxCandidateTest(", $publish);
    self::assertStringContainsString(': $this->runNginx([\'-t\', \'-c\', $candidate]', $publish);
    self::assertStringContainsString('// Native NGINX_RELOAD performs a fenced TEST before it', $publish);
    self::assertStringContainsString("(string)(\$test['config_digest'] ?? '')", $publish);
    self::assertStringContainsString("\\hash('sha256', \$candidateConfig)", $publish);
}

private function assert_GatewayNativeNginxTest_testLkgRollbackPersistsOneShotBoundIntentBeforeNativeTest(): void
{
    $rollback = self::controllerMethodSource_GatewayNativeNginxTest('rollbackToLkg');
    $native = self::controllerMethodSource_GatewayNativeNginxTest('nativeNginxCandidateTest');
    self::assertMatchesRegularExpression('/\$this->state\[\'routes\'\] = \$currentRoutes;\s*' . '\$this->state\[\'generation\'\] = \$currentGeneration;\s*' . '\}\s*\$test = \$this->brokerActionsRequired\(\)\s*' . '\? \$this->nativeNginxCandidateTest\(/s', $rollback);
    self::assertStringContainsString("\$this->state['native_nginx_test_intent'] = \$lkgIntent;", $native);
    self::assertStringContainsString("unset(\$this->state['native_nginx_test_intent']);", $native);
    self::assertStringContainsString('finally {', $native);
    self::assertStringNotContainsString('$this->runNginx(', $native);
    self::assertStringContainsString("'config_digest' => \$candidateDigest", $native);
    self::assertStringContainsString("(string)(\$test['config_digest'] ?? '')", $rollback);
}

private function assert_GatewayNativeNginxTest_testLkgIntentCanonicalBindsRetainedBundleAndCurrentFence(): void
{
    $source = self::controllerMethodSource_GatewayNativeNginxTest('nativeNginxCandidateTest');
    $fields = ['schema_version=1', 'phase=LKG_ROLLBACK', 'publication_generation=', 'candidate_config_digest=', 'candidate_config_path_digest=', 'target_config_path_digest=', 'source_lkg_manifest_relative=', 'source_lkg_manifest_path_digest=', 'source_lkg_manifest_digest=', 'source_config_digest=', 'source_routes_digest=', 'gateway_epoch=', 'host_boot_id=', 'runtime_generation=', 'created_at_monotonic_ms='];
    $offset = \strpos($source, 'WLS-NGINX-LKG-TEST-INTENT/1');
    self::assertIsInt($offset);
    foreach ($fields as $field) {
        $next = \strpos($source, $field, $offset);
        self::assertIsInt($next, 'Missing LKG intent field: ' . $field);
        self::assertGreaterThan($offset, $next);
        $offset = $next;
    }
    self::assertStringContainsString("\$lkgIntent['intent_digest'] = \\hash('sha256', \$intentCanonical);", $source);
    self::assertStringContainsString("'source_lkg_manifest_relative'", $source);
    self::assertStringContainsString("'source_config_digest'", $source);
    self::assertStringContainsString("'source_routes_digest'", $source);
}

private function controllerMethodSource_GatewayNativeNginxTest(string $method): string
{
    if (!\class_exists('WlsEdgeGatewayController', false)) {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/wls_gateway_controller.php';
    }
    $reflection = new \ReflectionMethod('WlsEdgeGatewayController', $method);
    $lines = \file((string) $reflection->getFileName());
    self::assertIsArray($lines);
    return \implode('', \array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

private function assert_GatewayNativeWaitpidDeadline_testPrivilegedPosixChildrenNeverUseBlockingWaitpid(): void
{
    $native = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native/posix/';
    $broker = (string) \file_get_contents($native . 'wls_gateway_broker.c');
    $launcher = (string) \file_get_contents($native . 'wls_gateway_launcher.c');
    $blockingWait = '/waitpid\s*\(\s*[^,\n]+,\s*[^,\n]+,\s*0\s*\)/';
    self::assertDoesNotMatchRegularExpression($blockingWait, $broker);
    self::assertDoesNotMatchRegularExpression($blockingWait, $launcher);
    self::assertStringContainsString('static int wls_wait_child_exit_until(', $broker);
    self::assertStringContainsString('WLS_NGINX_CHILD_REAP_RESERVE_MS', $broker);
    self::assertSame(14, \substr_count($broker, 'wls_wait_root_self_test_child('), 'The helper definition and all thirteen root-owned self-test call sites must remain bounded.');
    self::assertStringContainsString('WLS_ROOT_SELF_TEST_CHILD_KILL_REAP_MS', $broker);
    self::assertSame(2, \substr_count($broker, 'wls_wait_child_exit_deadline_self_test('), 'The Broker helper definition and --self-test call must remain wired.');
    self::assertStringContainsString('WLS_BROKER_TERM_GRACE_MILLISECONDS', $launcher);
    self::assertStringContainsString('WLS_BROKER_KILL_REAP_MILLISECONDS', $launcher);
    self::assertStringContainsString('waitpid(child, status, WNOHANG)', $launcher);
    self::assertStringNotContainsString('while (waitpid(broker_pid, &status, 0)', $launcher);
    self::assertSame(2, \substr_count($launcher, 'wls_wait_child_exit_deadline_self_test('), 'The Launcher helper definition and --self-test call must remain wired.');
}

private function assert_GatewayProtocolReceipt_testLeaseReceiptValidationBindsCurrentHostBootBeforeUsingMonotonicTime(): void
{
    $source = (string) \file_get_contents((string) (new \ReflectionClass(GatewayHostManager::class))->getFileName());
    self::assertStringContainsString('$currentHostBootId = GatewayHostBootIdentity::current();', $source);
    self::assertStringContainsString('!\hash_equals($currentHostBootId, $hostBootId)', $source);
    self::assertSame(2, \substr_count($source, "\$registration['host_boot_id'] = self::currentAuthenticatedHostBootId(\$status);"));
    self::assertSame(3, \substr_count($source, "'host_boot_id' => (string)\$registration['host_boot_id']"));
    self::assertStringContainsString('!\hash_equals($current, $observed)', $source);
}

private function assert_GatewayProtocolReceipt_testEnrollmentWireDigestExcludesOnlyDerivedEnvelopeFields(): void
{
    $facts = ['project_uuid' => 'p', 'project_root' => '/project'];
    $digest = GatewayHostManager::enrollmentRequestDigest($facts);
    self::assertSame($digest, GatewayHostManager::enrollmentRequestDigest($facts + ['request_digest' => \str_repeat('a', 64), 'idempotency_key' => \str_repeat('b', 40)]));
    self::assertContains('authenticated_desired_digest', GatewayHostManager::ENROLLMENT_RECEIPT_FIELDS);
    self::assertSame(['schema_version', 'protocol', 'host_id', 'project_uuid', 'gateway_epoch', 'project_generation', 'instance_id', 'instance_generation', 'instance_digest', 'master_epoch', 'launch_id', 'request_digest', 'idempotency_key', 'active_config_generation', 'active_config_digest', 'host_boot_id', 'issued_monotonic', 'lifecycle_state', 'drain_operation_id', 'drain_started_monotonic', 'lease_sequence', 'lease_ttl_seconds', 'route_generations', 'routes_digest', 'issued_at', 'signature'], GatewayHostManager::LEASE_RECEIPT_FIELDS);
}

private function assert_GatewayProtocolReceipt_testDrainOperationIdentityBindsLaunchAndCannotExtendWithSeconds(): void
{
    $receipt = ['project_uuid' => '123e4567-e89b-42d3-a456-426614174011', 'instance_generation' => 7, 'master_epoch' => 9, 'launch_id' => \str_repeat('a', 32)];
    $facts = ['operation' => 'drain', 'project_uuid' => $receipt['project_uuid'], 'instance_id' => 'drain-idempotency', 'instance_generation' => 7, 'master_epoch' => 9, 'launch_id' => \str_repeat('a', 32)];
    $operationId = GatewayHostManager::drainOperationId($receipt, 'drain-idempotency');
    self::assertSame(\hash('sha256', GatewayClient::canonicalJson($facts)), $operationId);
    self::assertSame($operationId, GatewayHostManager::drainOperationId($receipt, 'drain-idempotency'));
    self::assertNotSame($operationId, GatewayHostManager::drainOperationId([...$receipt, 'launch_id' => \str_repeat('b', 32)], 'drain-idempotency'));
}

private function assert_GatewayProtocolReceipt_testDrainPersistsPublishedLifecycleReceiptAndRefreshesItWhileWaiting(): void
{
    $source = (string) \file_get_contents((string) (new \ReflectionClass(GatewayHostManager::class))->getFileName());
    self::assertStringContainsString('$operationResult = \is_array($operation[\'result\'] ?? null)', $source);
    self::assertStringContainsString('self::leaseReceiptFromPublication($payload)', $source);
    self::assertStringContainsString('$heartbeat = $this->heartbeat(', $source);
    self::assertStringContainsString('DRAIN_LEASE_HEARTBEAT_FAILURE_SECONDS', $source);
    self::assertStringContainsString('(($operationResult[\'already_removed\'] ?? false) === true)', $source);
}

private function assert_GatewayProtocolReceipt_testRotationPrepareDigestAndIdempotencyMatchProtocolFacts(): void
{
    $rotation = ['rotation_id' => \str_repeat('a', 32), 'old_project_uuid' => '123e4567-e89b-42d3-a456-426614174031', 'new_project_uuid' => '123e4567-e89b-42d3-a456-426614174032', 'project_root' => '/project'];
    $method = new \ReflectionMethod(GatewayProjectIdentityRotator::class, 'prepareRequest');
    $request = $method->invoke(null, $rotation);
    $digest = \hash('sha256', GatewayClient::canonicalJson(['schema_version' => 1, 'protocol' => GatewayPaths::PROTOCOL, 'rotation_id' => $rotation['rotation_id'], 'old_project_uuid' => $rotation['old_project_uuid'], 'new_project_uuid' => $rotation['new_project_uuid'], 'project_root' => $rotation['project_root']]));
    self::assertSame($digest, $request['request_digest']);
    self::assertSame(\substr(\hash('sha256', $rotation['old_project_uuid'] . ':rotate:' . $rotation['new_project_uuid'] . ':' . $digest), 0, 40), $request['idempotency_key']);
    self::assertSame(GatewayProjectIdentityRotator::COMMIT_RECEIPT_FIELDS, ['schema_version', 'protocol', 'host_id', 'gateway_epoch', 'rotation_id', 'old_project_uuid', 'new_project_uuid', 'project_root', 'request_digest', 'idempotency_key', 'security_generation', 'new_credential_id', 'state', 'issued_at', 'signature']);
}

private function assert_GatewayProtocolReceipt_testAuthenticatedRotationPrepareKeepsOnlyPendingCredentialShape(): void
{
    $method = new \ReflectionMethod(GatewayClient::class, 'sanitizeAuthenticatedResponse');
    $hostId = \str_repeat('a', 32);
    $newUuid = '123e4567-e89b-42d3-a456-426614174042';
    $sanitized = $method->invoke(null, ['ok' => true, 'payload' => ['credential' => ['schema_version' => 1, 'protocol' => GatewayPaths::PROTOCOL, 'host_id' => $hostId, 'project_uuid' => $newUuid, 'credential_id' => \str_repeat('b', 32), 'credential_generation' => 2, 'secret' => \str_repeat('c', 64), 'issued_at' => '2026-08-03T00:00:00+00:00', 'unexpected' => 'discarded']]], 'project', ['operation' => 'rotate-prepare', 'host_id' => $hostId, 'payload' => ['new_project_uuid' => $newUuid]]);
    self::assertSame(\str_repeat('c', 64), $sanitized['payload']['credential']['secret']);
    self::assertArrayNotHasKey('unexpected', $sanitized['payload']['credential']);
}

private function assert_GatewayProtocolReceipt_testRotationLocalAfterImageMustProveCommittedCredentialSecret(): void
{
    $credential = ['schema_version' => 1, 'protocol' => GatewayPaths::PROTOCOL, 'host_id' => \str_repeat('a', 32), 'project_uuid' => '123e4567-e89b-42d3-a456-426614174062', 'credential_id' => \str_repeat('b', 32), 'credential_generation' => 2, 'secret' => \str_repeat('c', 64), 'issued_at' => '2026-08-03T00:00:00+00:00'];
    $rotation = ['rotation_id' => \str_repeat('d', 32), 'old_project_uuid' => '123e4567-e89b-42d3-a456-426614174061', 'new_project_uuid' => $credential['project_uuid'], 'project_root' => '/project', 'request_digest' => \str_repeat('e', 64), 'idempotency_key' => \str_repeat('f', 40), 'new_credential_id' => $credential['credential_id']];
    $receipt = ['schema_version' => 1, 'protocol' => GatewayPaths::PROTOCOL, 'host_id' => $credential['host_id'], 'gateway_epoch' => \str_repeat('1', 32), 'rotation_id' => $rotation['rotation_id'], 'old_project_uuid' => $rotation['old_project_uuid'], 'new_project_uuid' => $rotation['new_project_uuid'], 'project_root' => $rotation['project_root'], 'request_digest' => $rotation['request_digest'], 'idempotency_key' => $rotation['idempotency_key'], 'security_generation' => 7, 'new_credential_id' => $rotation['new_credential_id'], 'state' => 'CONTROLLER_COMMITTED', 'issued_at' => '2026-08-03T00:00:00+00:00'];
    $receipt['signature'] = \hash_hmac('sha256', GatewayClient::canonicalJson($receipt), $credential['secret']);
    $rotation['commit_receipt'] = $receipt;
    GatewayProjectIdentityRotator::validateCommittedCredential($rotation, $credential);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('does not prove the committed host rotation');
    GatewayProjectIdentityRotator::validateCommittedCredential($rotation, [...$credential, 'secret' => \str_repeat('2', 64)]);
}

private function assert_GatewayProtocolReceipt_testLeaseReceiptMustMatchExactAuthenticatedPublicationFence(): void
{
    $method = new \ReflectionMethod(GatewayHostManager::class, 'assertLeaseReceiptMatchesAuthenticatedStatus');
    $projectUuid = '123e4567-e89b-42d3-a456-426614174051';
    $instanceId = 'receipt-test';
    $epoch = \str_repeat('a', 32);
    $requestDigest = \str_repeat('b', 64);
    $idempotencyKey = \str_repeat('c', 40);
    $instanceDigest = \str_repeat('d', 64);
    $launchId = \str_repeat('e', 32);
    $activeDigest = \str_repeat('f', 64);
    $routeId = \str_repeat('1', 32);
    $routeGenerations = [$routeId => 3];
    $receipt = ['schema_version' => GatewayHostManager::LEASE_RECEIPT_SCHEMA_VERSION, 'protocol' => GatewayPaths::PROTOCOL, 'host_id' => \str_repeat('8', 32), 'project_uuid' => $projectUuid, 'gateway_epoch' => $epoch, 'project_generation' => 5, 'request_digest' => $requestDigest, 'idempotency_key' => $idempotencyKey, 'active_config_generation' => 11, 'active_config_digest' => $activeDigest, 'host_boot_id' => \str_repeat('9', 64), 'issued_monotonic' => 100.0, 'lifecycle_state' => 'ACTIVE', 'drain_operation_id' => '', 'drain_started_monotonic' => 0.0, 'lease_sequence' => 1, 'lease_ttl_seconds' => 45, 'instance_id' => $instanceId, 'instance_generation' => 7, 'instance_digest' => $instanceDigest, 'master_epoch' => 9, 'launch_id' => $launchId, 'route_generations' => $routeGenerations, 'routes_digest' => \hash('sha256', GatewayClient::canonicalJson($routeGenerations)), 'issued_at' => '2026-08-06T00:00:00+00:00', 'signature' => \str_repeat('7', 64)];
    $status = ['ok' => true, 'control_plane_ready' => true, 'release_ready' => true, 'broker_ready' => true, 'supervisor_ready' => true, 'protocol' => GatewayPaths::PROTOCOL, 'implementation_level' => GatewayPaths::IMPLEMENTATION_LEVEL, 'security_profile' => GatewayPaths::SECURITY_PROFILE, 'protocol_min' => 2, 'protocol_max' => 2, 'public_http' => 80, 'public_https' => 443, 'epoch' => $epoch, 'publication_exact' => true, 'project_uuid' => $projectUuid, 'project_generation' => 5, 'request_digest' => $requestDigest, 'idempotency_key' => $idempotencyKey, 'active_config_generation' => 11, 'active_config_digest' => $activeDigest, 'host_boot_id' => \str_repeat('9', 64), 'instances' => [['instance_id' => $instanceId, 'status' => 'ACTIVE', 'generation' => 7, 'digest' => $instanceDigest, 'master_epoch' => 9, 'launch_id' => $launchId]], 'active_routes' => [['project_uuid' => $projectUuid, 'route_id' => $routeId, 'status' => 'PENDING_CERTIFICATE', 'route_generation' => 3]]];
    $method->invoke(null, $receipt, $status);
    self::addToAssertionCount(1);
    foreach ([['project_generation' => 6], ['request_digest' => \str_repeat('2', 64)], ['idempotency_key' => \str_repeat('3', 40)], ['active_config_generation' => 12], ['active_config_digest' => \str_repeat('4', 64)], ['instances' => [[...$status['instances'][0], 'digest' => \str_repeat('5', 64)]]], ['instances' => [[...$status['instances'][0], 'master_epoch' => 10]]], ['instances' => [[...$status['instances'][0], 'launch_id' => \str_repeat('6', 32)]]]] as $override) {
        try {
            $method->invoke(null, $receipt, [...$status, ...$override]);
            self::fail('A stale receipt publication fence must be rejected.');
        } catch (\Throwable $throwable) {
            self::assertInstanceOf(\RuntimeException::class, $throwable);
        }
    }
}

private function assert_GatewayProtocolReceipt_testConcurrentReceiptCompletionCannotOverwriteNewerPublication(): void
{
    $method = new \ReflectionMethod(GatewayHostManager::class, 'assertLeaseReceiptMayReplace');
    $routeId = \str_repeat('1', 32);
    $existing = ['project_uuid' => '123e4567-e89b-42d3-a456-426614174061', 'gateway_epoch' => \str_repeat('a', 32), 'project_generation' => 5, 'instance_id' => 'receipt-race', 'instance_generation' => 7, 'instance_digest' => \str_repeat('b', 64), 'master_epoch' => 9, 'launch_id' => \str_repeat('c', 32), 'request_digest' => \str_repeat('d', 64), 'idempotency_key' => \str_repeat('e', 40), 'active_config_generation' => 12, 'active_config_digest' => \str_repeat('f', 64), 'host_boot_id' => \str_repeat('9', 64), 'issued_monotonic' => 100.0, 'lifecycle_state' => 'ACTIVE', 'drain_operation_id' => '', 'drain_started_monotonic' => 0.0, 'lease_sequence' => 4, 'lease_ttl_seconds' => 45, 'route_generations' => [$routeId => 3], 'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([$routeId => 3])), 'issued_at' => '2026-08-03T10:00:10+00:00'];
    $newer = [...$existing, 'active_config_generation' => 13, 'active_config_digest' => \str_repeat('2', 64), 'project_generation' => 6, 'request_digest' => \str_repeat('3', 64), 'idempotency_key' => \str_repeat('4', 40), 'route_generations' => [$routeId => 4], 'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([$routeId => 4])), 'issued_at' => '2026-08-03T10:00:20+00:00', 'issued_monotonic' => 110.0, 'lease_sequence' => 5];
    $method->invoke(null, $existing, $newer);
    self::addToAssertionCount(1);
    $drainOperationId = \str_repeat('7', 64);
    $draining = [...$newer, 'lifecycle_state' => 'DRAINING', 'drain_operation_id' => $drainOperationId, 'drain_started_monotonic' => 105.0];
    $method->invoke(null, $existing, $draining);
    self::addToAssertionCount(1);
    foreach ([[...$existing, 'active_config_generation' => 11, 'issued_at' => '2026-08-03T10:00:20+00:00', 'issued_monotonic' => 110.0, 'lease_sequence' => 5], [...$existing, 'active_config_digest' => \str_repeat('5', 64), 'issued_at' => '2026-08-03T10:00:20+00:00', 'issued_monotonic' => 110.0, 'lease_sequence' => 5], [...$existing, 'route_generations' => [$routeId => 4], 'routes_digest' => \hash('sha256', GatewayClient::canonicalJson([$routeId => 4])), 'issued_at' => '2026-08-03T10:00:20+00:00', 'issued_monotonic' => 110.0, 'lease_sequence' => 5], [...$newer, 'issued_monotonic' => 90.0], [...$newer, 'issued_monotonic' => 110.0, 'lease_ttl_seconds' => 10]] as $staleCompletion) {
        $rejected = false;
        try {
            $method->invoke(null, $existing, $staleCompletion);
        } catch (\RuntimeException $throwable) {
            $rejected = true;
            self::assertStringStartsWith('REGISTER_REPLAY_REQUIRED:', $throwable->getMessage());
        }
        self::assertTrue($rejected, 'An inverse receipt completion must fail closed.');
    }
    foreach ([[...$draining, 'lifecycle_state' => 'ACTIVE', 'drain_operation_id' => '', 'drain_started_monotonic' => 0.0, 'lease_sequence' => 6, 'issued_monotonic' => 120.0], [...$draining, 'drain_operation_id' => \str_repeat('8', 64), 'lease_sequence' => 6, 'issued_monotonic' => 120.0]] as $inverseLifecycle) {
        try {
            $method->invoke(null, $draining, $inverseLifecycle);
            self::fail('A committed DRAINING receipt must never regress or change identity.');
        } catch (\RuntimeException $throwable) {
            self::assertStringStartsWith('REGISTER_REPLAY_REQUIRED:', $throwable->getMessage());
        }
    }
    $newEpochDrain = [...$draining, 'gateway_epoch' => \str_repeat('b', 32), 'active_config_generation' => 1, 'lease_sequence' => 1, 'issued_monotonic' => 120.0];
    $method->invoke(null, $draining, $newEpochDrain);
    self::addToAssertionCount(1);
    try {
        $method->invoke(null, $newEpochDrain, [...$newer, 'gateway_epoch' => \str_repeat('a', 32), 'lease_sequence' => 99, 'issued_monotonic' => 115.0]);
        self::fail('A delayed old-epoch heartbeat replaced the new epoch receipt.');
    } catch (\RuntimeException $throwable) {
        self::assertStringStartsWith('REGISTER_REPLAY_REQUIRED:', $throwable->getMessage());
    }
    try {
        $method->invoke(null, $newEpochDrain, [...$newer, 'gateway_epoch' => \str_repeat('a', 32), 'lease_sequence' => 100, 'issued_monotonic' => 120.0]);
        self::fail('Equal monotonic timestamps cannot order distinct gateway epochs.');
    } catch (\RuntimeException $throwable) {
        self::assertStringStartsWith('REGISTER_REPLAY_REQUIRED:', $throwable->getMessage());
    }
}

private function assert_GatewayProtocolReceipt_testDurableLeaseReceiptCommitIsNotReclassifiedByExpiredBusinessDeadline(): void
{
    $source = (string) \file_get_contents((string) (new \ReflectionClass(GatewayHostManager::class))->getFileName());
    $start = \strpos($source, 'private function persistLeaseReceipt(');
    $end = \strpos($source, 'private static function assertLeaseReceiptMayReplace(', $start ?: 0);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $method = \substr($source, $start, $end - $start);
    self::assertStringContainsString('$updated = ServerInstanceManager::updateJsonFileAtomically(', $method);
    self::assertStringContainsString('if (!$updated) {', $method);
    self::assertStringNotContainsString('$this->remainingOperationDeadline($deadlineMonotonic);', $method, 'A successful atomic publication is the durable commit result; a post-commit deadline gate creates a false failure.');
}

private function assert_GatewayProtocolReceipt_testExpiredRegistrationDeadlineIsNotReusedForFailureCompensation(): void
{
    $source = (string) \file_get_contents((string) (new \ReflectionClass(GatewayHostManager::class))->getFileName());
    $start = \strpos($source, 'private function submitRegistration(');
    $end = \strpos($source, 'public static function controlPlaneAcceptsRegistration(', $start ?: 0);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $method = \substr($source, $start, $end - $start);
    $catch = \strpos($method, '} catch (\Throwable $throwable) {');
    self::assertIsInt($catch);
    $compensation = \substr($method, $catch);
    self::assertStringContainsString('FAILURE_COMPENSATION_DEADLINE_SECONDS', $compensation);
    self::assertStringNotContainsString('$deadlineMonotonic', $compensation, 'Failure bookkeeping needs its own bounded deadline after the host mutation budget has expired.');
}

private function assert_GatewayProtocolReceipt_testPosixSnapshotReceiptV2BindsImmutableEntityAndRootOnlyMac(): void
{
    $source = self::posixNativeBrokerSource_GatewayProtocolReceipt();
    $canonicalStart = \strpos($source, 'static int wls_snapshot_receipt_canonical(');
    $canonicalEnd = \strpos($source, 'static int wls_snapshot_receipt_mac(', $canonicalStart ?: 0);
    self::assertIsInt($canonicalStart);
    self::assertIsInt($canonicalEnd);
    $canonical = \substr($source, $canonicalStart, $canonicalEnd - $canonicalStart);
    self::assertStringContainsString('WLS-SNAPSHOT-RECEIPT/2', $canonical);
    self::assertStringNotContainsString('receipt_digest=', $canonical);
    self::assertStringNotContainsString('receipt_mac=', $canonical);
    $ordered = ['snapshot_digest=', 'project_uuid=', 'transaction_id=', 'intent_digest=', 'security_generation=', 'certificate_generation=', 'source_binding_digest=', 'cert_source_digest=', 'key_source_digest=', 'chain_source_digest=', 'manifest_semantic_digest=', 'manifest_file_digest=', 'fullchain_digest=', 'private_key_digest=', 'leaf_fingerprint=', 'san_names_digest=', 'not_before=', 'not_after=', 'key_match=', 'platform=', 'gateway_epoch=', 'host_boot_id=', 'broker_binary_digest=', 'data_plane_identity_kind=', 'data_plane_identity_value=', 'controller_identity_kind=', 'controller_identity_value=', 'owner_identity_kind=', 'owner_identity_value=', 'acl_profile=', 'directory_acl_digest=', 'fullchain_acl_digest=', 'private_key_acl_digest=', 'manifest_acl_digest=', 'acl_protected=', 'reparse_free=', 'single_link_files=', 'snapshot_file_ids_digest=', 'broker_runtime_generation='];
    $previous = -1;
    foreach ($ordered as $field) {
        $position = \strpos($canonical, $field);
        self::assertIsInt($position, $field);
        self::assertGreaterThan($previous, $position, $field);
        $previous = $position;
    }
    $macEnd = \strpos($source, 'static int wls_snapshot_receipt_publish(', $canonicalEnd);
    self::assertIsInt($macEnd);
    $mac = \substr($source, $canonicalEnd, $macEnd - $canonicalEnd);
    self::assertStringContainsString('wls_snapshot_receipt_key(home, token)', $mac);
    self::assertStringContainsString('snapshot-receipt.key', $source);
    self::assertStringContainsString('wls_snapshot_receipt_key_link_recover(', $source);
    self::assertStringContainsString('wls_snapshot_receipt_key_candidate_namespace_empty(', $source);
    self::assertStringContainsString('key_status->st_nlink != 2', $source);
    self::assertStringContainsString('Simulate a process dying after linkat(final)', $source);
    self::assertStringNotContainsString('admin.token', $mac);
    self::assertStringContainsString('"snapshots-v2"', $source);
    self::assertStringContainsString('"snapshot-candidates-v2"', $source);
    self::assertStringContainsString('WLS-SNAPSHOT-CANDIDATE-REMOVE/1', $source);
    self::assertStringContainsString('wls_snapshot_internal_candidates_recover(', $source);
    self::assertStringContainsString('".candidate-%s-%ld-2222222222222222"', $source);
    self::assertStringContainsString('wls_snapshot_internal_candidate_final_safe(', $source);
    self::assertStringContainsString('kill(owner_pid, 0) == 0 || errno != ESRCH', $source);
    self::assertStringContainsString('(private_key_before.st_mode & 07777) != 0640', $source);
    self::assertStringContainsString('snapshot_file_ids_digest', $source);
    $runtimeStart = \strpos($source, 'static int wls_prepare_data_plane_runtime(');
    $runtimeEnd = \strpos($source, 'static int wls_nginx_probe_context_load(', $runtimeStart ?: 0);
    self::assertIsInt($runtimeStart);
    self::assertIsInt($runtimeEnd);
    $runtimePreparation = \substr($source, $runtimeStart, $runtimeEnd - $runtimeStart);
    self::assertStringNotContainsString('{"snapshots", 0710}', $runtimePreparation);
}

private function assert_GatewayProtocolReceipt_testPosixNginxTestIsPublicationBoundAndRestoresTemporaryAcl(): void
{
    $source = self::posixNativeBrokerSource_GatewayProtocolReceipt();
    $start = \strpos($source, 'static int wls_nginx_test_action_v2(');
    $end = \strpos($source, 'static int wls_nginx_lifecycle_action_v2(', $start ?: 0);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $action = \substr($source, $start, $end - $start);
    self::assertStringContainsString('WLS-NGINX-TEST/1', $action);
    self::assertStringContainsString('wls_nginx_test_context_load(', $action);
    self::assertStringContainsString('wls_prepare_nginx_test_candidate(', $action);
    self::assertStringContainsString('wls_restore_nginx_test_candidate(', $action);
    self::assertStringContainsString('wls_nginx_test_context_binding_same(', $action);
    self::assertStringContainsString("test_spawn_status = wls_nginx_spawn_wait(\n" . "            &before.action,\n" . "            \"TEST\",\n" . '            pid_source_config,', $action);
    self::assertStringContainsString('candidate_granted = 0;', $action);
    self::assertStringContainsString('NGINX_TEST_FAILED', $action);
    self::assertStringContainsString('WLS-NGINX-TEST-RESTORE-FAILED/1', $source);
    self::assertStringContainsString('state/publication-current.json', $source);
    self::assertStringContainsString('wls_json_checksummed_envelope(', $source);
    self::assertStringContainsString('WLS-NGINX-LKG-TEST-INTENT/1', $source);
    self::assertStringContainsString('wls_lkg_certificate_closure_valid(', $source);
    self::assertStringContainsString('context->lkg_source_config_digest, &config_status', $source);
    self::assertStringNotContainsString('strcmp(candidate_digest, source_config_digest) != 0', $source);
    self::assertStringContainsString('strcmp(channel, "admin") != 0', $action);
    self::assertStringContainsString('count == 13U', $source);
    self::assertStringContainsString('arguments[14] = "--nginx-config";', $source);
    $restoreArmed = \strpos($action, 'candidate_granted = 1;');
    $prepare = \strpos($action, 'wls_prepare_nginx_test_candidate(', $restoreArmed ?: 0);
    $cleanupRestore = \strpos($action, 'if (candidate_granted) {', $prepare ?: 0);
    $failureRecord = \strpos($action, 'wls_nginx_test_restore_failure_record(', $cleanupRestore ?: 0);
    self::assertIsInt($restoreArmed);
    self::assertIsInt($prepare);
    self::assertIsInt($cleanupRestore);
    self::assertIsInt($failureRecord);
    self::assertGreaterThan($restoreArmed, $prepare);
    self::assertGreaterThan($prepare, $cleanupRestore);
    self::assertGreaterThan($cleanupRestore, $failureRecord);
    $receiptFields = ['gateway_epoch=%s', 'host_boot_id=%s', 'active_slot=%s', 'runtime_generation=%s', 'binary_digest=%s', 'broker_binary_digest=%s', 'config_digest=%s', 'test_config_path_digest=%s', 'target_config_path_digest=%s', 'publication_generation=%lu', 'candidate_transaction_id=%s', 'candidate_phase=%s'];
    $previous = -1;
    foreach ($receiptFields as $field) {
        $position = \strpos($action, $field);
        self::assertIsInt($position, $field);
        self::assertGreaterThan($previous, $position, $field);
        $previous = $position;
    }
}

private function assert_GatewayProtocolReceipt_testPosixReloadTestsSameFenceBeforeSignallingMaster(): void
{
    $source = self::posixNativeBrokerSource_GatewayProtocolReceipt();
    $start = \strpos($source, 'static int wls_nginx_lifecycle_action_v2(');
    $end = \strpos($source, 'static int wls_owned_nginx_alive(', $start ?: 0);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $lifecycle = \substr($source, $start, $end - $start);
    $testCall = "test_spawn_status = wls_nginx_spawn_wait(\n" . "            &before,\n" . "            \"TEST\",\n" . '            test_pid_source_config,';
    $test = \strpos($lifecycle, $testCall);
    $same = \strpos($lifecycle, 'wls_nginx_action_context_same(&before, &after)', $test ?: 0);
    $reload = \strpos($lifecycle, 'reload ? "RELOAD" : "START"', $same ?: 0);
    self::assertIsInt($test);
    self::assertIsInt($same);
    self::assertIsInt($reload);
    self::assertGreaterThan($test, $same);
    self::assertGreaterThan($same, $reload);
}

private function posixNativeBrokerSource_GatewayProtocolReceipt(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . DIRECTORY_SEPARATOR . 'Service' . DIRECTORY_SEPARATOR . 'Edge' . DIRECTORY_SEPARATOR . 'Gateway' . DIRECTORY_SEPARATOR . 'Native' . DIRECTORY_SEPARATOR . 'posix' . DIRECTORY_SEPARATOR . 'wls_gateway_broker.c';
    self::assertFileExists($path);
    $source = \file_get_contents($path);
    self::assertIsString($source);
    return $source;
}

private function assert_NativeGatewayAdminStoppedTransaction_testBothNativeStopWritersUseTheSharedPackageTransactionLock(): void
{
    $gateway = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway';
    $posix = (string) \file_get_contents($gateway . '/Native/posix/wls_gateway_broker.c');
    $windows = (string) \file_get_contents($gateway . '/Native/windows/wls_gateway_broker.c');
    $posixStop = $this->functionBody_NativeGatewayAdminStoppedTransaction($posix, 'static int wls_write_admin_stopped(', 'static int wls_upgrade_boot_id(');
    $windowsStop = $this->functionBody_NativeGatewayAdminStoppedTransaction($windows, 'static int wls_write_admin_stopped(', 'static int wls_upgrade_boot_id(');
    self::assertStringContainsString('wls_package_install_lock_acquire(', $posixStop);
    self::assertStringContainsString('flock(lock_fd, LOCK_UN)', $posixStop);
    self::assertStringContainsString('wls_win_package_install_lock_acquire(', $windowsStop);
    self::assertStringContainsString('wls_win_package_install_lock_release(', $windowsStop);
    self::assertGreaterThanOrEqual(2, \substr_count($posix, '"STOP"'));
    self::assertGreaterThanOrEqual(2, \substr_count($windows, '"STOP"'));
}

private function assert_NativeGatewayAdminStoppedTransaction_testPhpClearAndRestoreShareTheLockAndRestoreIsCompareAbsent(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/GatewayHostManager.php');
    $transaction = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'private function withAdminStoppedIntentTransaction(', 'private function clearAdminStoppedIntent(');
    $clear = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'private function clearAdminStoppedIntent(', 'private function restoreAdminStoppedIntent(');
    $restore = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'private function restoreAdminStoppedIntent(', 'private function assertAdminStoppedIntent(');
    self::assertStringContainsString('package-install.lock', $transaction);
    self::assertStringContainsString('withAdminStoppedIntentTransaction(', $clear);
    self::assertStringContainsString('cleanupAtomicWriteRecoveryBackups(', $clear);
    self::assertStringContainsString('withAdminStoppedIntentTransaction(', $restore);
    self::assertStringContainsString('hasAtomicWriteRecoveryBackups(', $restore);
    self::assertStringContainsString('if (\file_exists($file) || \is_link($file))', $restore);
}

private function assert_NativeGatewayAdminStoppedTransaction_testExplicitStartAndRebootstrapReserveAndProveCompensation(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/GatewayHostManager.php');
    $rebootstrap = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'public function rebootstrap(', 'private function rebootstrapTerminalResult(');
    $startGeneration = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'private function startRebootstrapGeneration(', 'private function awaitRebootstrapIdentity(');
    $startGateway = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'public function startGateway(', 'private function compensateFailedGatewayStart(');
    $compensation = $this->functionBody_NativeGatewayAdminStoppedTransaction($source, 'private function compensateFailedGatewayStart(', 'private function withAdminStoppedIntentTransaction(');
    self::assertStringContainsString('REBOOTSTRAP_COMPENSATION_RESERVE_SECONDS', $rebootstrap);
    self::assertStringContainsString('$forwardDeadline', $rebootstrap);
    self::assertStringContainsString('SERVICE_START_COMPENSATION_RESERVE_SECONDS', $startGeneration);
    self::assertStringContainsString('compensateFailedGatewayStart(', $startGeneration);
    self::assertStringContainsString('SERVICE_START_COMPENSATION_RESERVE_SECONDS', $startGateway);
    self::assertStringContainsString('compensateFailedGatewayStart(', $startGateway);
    self::assertStringContainsString('$this->platform->stop(', $compensation);
    self::assertStringContainsString('restoreAdminStoppedIntent(', $compensation);
    self::assertStringContainsString('persistentStoppedProof(', $compensation);
    self::assertStringContainsString('readVerifiedAdminStoppedIntent(', $compensation);
    self::assertGreaterThanOrEqual(4, \substr_count($compensation, 'catch (\Throwable $failure)'), 'Stop, intent restore and both terminal proofs must fail independently.');
}

private function assert_NativeGatewayAdminStoppedTransaction_testBothNativeBootstrapClientsUseTheProtocolSecretVerbatim(): void
{
    $gateway = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway';
    $posix = (string) \file_get_contents($gateway . '/Native/posix/wls_gateway_broker.c');
    $windows = (string) \file_get_contents($gateway . '/Native/windows/wls_gateway_broker.c');
    $posixBootstrap = $this->functionBody_NativeGatewayAdminStoppedTransaction($posix, 'static int wls_bootstrap_once(', 'static int wls_bootstrap_once_serialized(');
    $windowsBootstrap = $this->functionBody_NativeGatewayAdminStoppedTransaction($windows, 'static int wls_bootstrap_once(', 'static int wls_bootstrap_once_serialized(');
    self::assertStringNotContainsString('sodium_hex2bin(', $posixBootstrap);
    self::assertSame(2, \substr_count($posixBootstrap, '(const unsigned char *)token'));
    self::assertStringNotContainsString('wls_hex_decode_fixed(token', $windowsBootstrap);
    self::assertSame(2, \substr_count($windowsBootstrap, '(const unsigned char *)token'));
}

private function functionBody_NativeGatewayAdminStoppedTransaction(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Expected function start was not found: ' . $startNeedle);
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Expected function end was not found: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

private function assert_NativeGatewayAtomicCandidateRecovery_testBothBrokersInventoryOnlyTheirCompleteReusableTargetClosure(): void
{
    [$posix, $windows] = $this->sources_NativeGatewayAtomicCandidateRecovery();
    $posixInventory = $this->functionBody_NativeGatewayAtomicCandidateRecovery($posix, 'static const struct wls_atomic_recovery_target wls_atomic_recovery_targets[', 'static int wls_atomic_recovery_choice_valid(');
    $windowsInventory = $this->functionBody_NativeGatewayAtomicCandidateRecovery($windows, 'static const struct wls_atomic_recovery_target wls_atomic_recovery_targets[', 'static int wls_atomic_recovery_choice_valid(');
    $sharedTargets = ['admin-stopped.intent', 'upgrade-observing', 'upgrade-healthy', 'upgrade-rollback-healthy', 'controller-process.identity', 'process-attestation-candidate.fence', 'process-attestation.receipt', 'process-tree-retirement.receipt', 'broker-fencing-token'];
    foreach ($sharedTargets as $target) {
        self::assertStringContainsString('"' . $target . '"', $posixInventory);
        self::assertStringContainsString('L"' . $target . '"', $windowsInventory);
    }
    self::assertStringContainsString('"nginx-test-restore-failed"', $posixInventory);
    self::assertStringContainsString('L"broker-launch.receipt"', $windowsInventory);
    self::assertStringContainsString('L"nginx-process.identity"', $windowsInventory);
    self::assertStringContainsString('WLS_ATOMIC_RECOVERY_TARGET_COUNT 10U', $posix);
    self::assertStringContainsString('WLS_ATOMIC_RECOVERY_TARGET_COUNT 11U', $windows);
    self::assertStringNotContainsString('upgrade-rollback.request",', $posixInventory);
}

private function assert_NativeGatewayAtomicCandidateRecovery_testDiscoveryIsBoundedExactAndRejectsMalformedReservedLeaves(): void
{
    [$posix, $windows] = $this->sources_NativeGatewayAtomicCandidateRecovery();
    foreach ([$posix, $windows] as $source) {
        self::assertStringContainsString('WLS_ATOMIC_RECOVERY_DIRECTORY_ENTRIES_MAXIMUM', $source);
        self::assertStringContainsString('WLS_ATOMIC_RECOVERY_CANDIDATES_MAXIMUM', $source);
        self::assertStringContainsString('.candidate.', $source);
        self::assertStringContainsString('reserved_prefix', $source);
        self::assertStringContainsString('target_index == target_count', $source);
    }
    self::assertStringContainsString('O_NOFOLLOW', $posix);
    self::assertStringContainsString('S_ISREG(candidate_status.st_mode)', $posix);
    self::assertStringContainsString('candidate_status.st_nlink != 1', $posix);
    self::assertStringContainsString('candidate_status.st_uid', $posix);
    self::assertStringContainsString('candidate_status.st_mode & 0777', $posix);
    self::assertStringContainsString('FILE_FLAG_OPEN_REPARSE_POINT', $windows);
    self::assertStringContainsString('candidate_standard.NumberOfLinks != 1U', $windows);
    self::assertStringContainsString('wls_private_acl_safe(candidate, "S-1-5-18") != 1', $windows);
}

private function assert_NativeGatewayAtomicCandidateRecovery_testEveryCommittedPairIsSemanticallyValidatedBeforeAnyDeletion(): void
{
    [$posix, $windows] = $this->sources_NativeGatewayAtomicCandidateRecovery();
    $posixRecovery = $this->functionBody_NativeGatewayAtomicCandidateRecovery($posix, 'static int wls_recover_atomic_write_candidates_locked(', 'static int wls_recover_atomic_write_candidates(');
    $windowsRecovery = $this->functionBody_NativeGatewayAtomicCandidateRecovery($windows, 'static int wls_win_recover_atomic_write_candidates_locked(', 'static int wls_win_recover_atomic_write_candidates(');
    foreach ([$posixRecovery, $windowsRecovery] as $recovery) {
        self::assertStringContainsString('wls_atomic_recovery_committed_valid(', $recovery);
        self::assertStringContainsString('Phase 1: discover and validate', $recovery);
        self::assertStringContainsString('Phase 2: revalidate the complete closure', $recovery);
        self::assertStringContainsString('Phase 3: delete only after', $recovery);
    }
    self::assertLessThan(\strpos($posixRecovery, 'unlinkat('), \strpos($posixRecovery, 'Phase 2: revalidate the complete closure'));
    self::assertLessThan(\strpos($windowsRecovery, 'SetFileInformationByHandle('), \strpos($windowsRecovery, 'Phase 2: revalidate the complete closure'));
    self::assertStringContainsString('wls_atomic_recovery_payload_valid(', $posix);
    self::assertStringContainsString('wls_atomic_recovery_payload_valid(', $windows);
    self::assertStringContainsString('memchr(contents, \'\0\'', $posix);
    self::assertStringContainsString('memchr(contents, \'\0\'', $windows);
}

private function assert_NativeGatewayAtomicCandidateRecovery_testRecoveryRunsInsidePackageAndLifecycleLocksBeforeNewFencingPublication(): void
{
    [$posix, $windows] = $this->sources_NativeGatewayAtomicCandidateRecovery();
    $posixWrapper = $this->functionBody_NativeGatewayAtomicCandidateRecovery($posix, 'static int wls_recover_atomic_write_candidates(', 'static int wls_serve(');
    $windowsWrapper = $this->functionBody_NativeGatewayAtomicCandidateRecovery($windows, 'static int wls_win_recover_atomic_write_candidates(', 'int wmain(');
    $posixServe = $this->functionBody_NativeGatewayAtomicCandidateRecovery($posix, 'static int wls_serve(', 'static int wls_self_test(');
    $windowsMain = \substr($windows, (int) \strpos($windows, 'int wmain('));
    self::assertStringContainsString('wls_package_install_lock_acquire(', $posixWrapper);
    self::assertStringContainsString('flock(lock_fd, LOCK_UN)', $posixWrapper);
    self::assertStringContainsString('wls_win_package_install_lock_acquire(', $windowsWrapper);
    self::assertStringContainsString('wls_win_package_install_lock_release(', $windowsWrapper);
    self::assertLessThan(\strpos($posixServe, 'wls_write_fencing('), \strpos($posixServe, 'wls_recover_atomic_write_candidates('));
    self::assertLessThan(\strpos($windowsMain, 'wls_write_fencing_file('), \strpos($windowsMain, 'wls_win_recover_atomic_write_candidates('));
    self::assertStringContainsString('flock(lock_fd, LOCK_EX | LOCK_NB)', $posixServe);
    self::assertStringContainsString('CreateMutexW(NULL, TRUE, L"Global\\\\WelineWlsGatewayV2Broker")', $windowsMain);
}

/** @return array{string, string} */
private function sources_NativeGatewayAtomicCandidateRecovery(): array
{
    $gateway = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native';
    return [(string) \file_get_contents($gateway . '/posix/wls_gateway_broker.c'), (string) \file_get_contents($gateway . '/windows/wls_gateway_broker.c')];
}

private function functionBody_NativeGatewayAtomicCandidateRecovery(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Expected function start was not found: ' . $startNeedle);
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Expected function end was not found: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

private function assert_NativeGatewayAtomicPendingLedger_testSecurityLedgerAuxiliaryFilesAreExactBoundedAtomicTargetsOnBothPlatforms(): void
{
    $native = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native';
    $posix = (string) \file_get_contents($native . '/posix/wls_gateway_broker.c');
    $windows = (string) \file_get_contents($native . '/windows/wls_gateway_broker.c');
    $posixTargets = $this->targetTable_NativeGatewayAtomicPendingLedger($posix, 'static const struct wls_atomic_target_limit wls_atomic_targets[] = {', 'static uint64_t wls_atomic_target_maximum(');
    self::assertSame(1, \substr_count($posixTargets, '{"state/security-ledger.pending.json", WLS_MAX_ATOMIC_STATE}'));
    self::assertSame(1, \substr_count($posix, '#define WLS_MAX_ATOMIC_SECURITY_DISTRUST 128U'));
    self::assertSame(1, \substr_count($posixTargets, '{"state/security-ledger.json.untrusted", WLS_MAX_ATOMIC_SECURITY_DISTRUST}'));
    self::assertSame(1, \substr_count($posixTargets, '{"state/security-anchor.json", WLS_MAX_REQUEST}'));
    self::assertSame(1, \substr_count($posixTargets, '{"state/wls-edge-2.initialized.json", WLS_MAX_REQUEST}'));
    self::assertStringNotContainsString('{"trust/security-anchor.json",', $posixTargets);
    self::assertStringNotContainsString('{"trust/wls-edge-2.initialized.json",', $posixTargets);
    $windowsTargets = $this->targetTable_NativeGatewayAtomicPendingLedger($windows, 'static const struct wls_win_atomic_target_limit wls_win_atomic_targets[] = {', 'static unsigned long long wls_win_atomic_target_maximum(');
    self::assertSame(1, \substr_count($windowsTargets, '{L"state\\\\security-ledger.pending.json", WLS_MAX_ATOMIC_STATE}'));
    self::assertSame(1, \substr_count($windows, '#define WLS_MAX_ATOMIC_SECURITY_DISTRUST 128ULL'));
    self::assertSame(1, \substr_count($windowsTargets, '{L"state\\\\security-ledger.json.untrusted", WLS_MAX_ATOMIC_SECURITY_DISTRUST}'));
    self::assertSame(1, \substr_count($windowsTargets, '{L"state\\\\security-anchor.json", WLS_MAX_REQUEST}'));
    self::assertSame(1, \substr_count($windowsTargets, '{L"state\\\\wls-edge-2.initialized.json", WLS_MAX_REQUEST}'));
    self::assertStringNotContainsString('{L"trust\\\\security-anchor.json",', $windowsTargets);
    self::assertStringNotContainsString('{L"trust\\\\wls-edge-2.initialized.json",', $windowsTargets);
    foreach ([$posixTargets, $windowsTargets] as $targets) {
        self::assertStringNotContainsString('*', $targets);
        self::assertStringNotContainsString('security-ledger.*', $targets);
        self::assertStringNotContainsString('security-ledger/', $targets);
    }
}

private function targetTable_NativeGatewayAtomicPendingLedger(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Missing atomic target table.');
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Missing atomic target table boundary.');
    return \substr($source, $start, $end - $start);
}

private string $posix_NativeGatewayBootstrapDeadline;

private string $windows_NativeGatewayBootstrapDeadline;

private string $controller_NativeGatewayBootstrapDeadline;

private function setUp_NativeGatewayBootstrapDeadline(): void
{
    $server = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $native = $server . '/Service/Edge/Gateway/Native';
    $this->posix_NativeGatewayBootstrapDeadline = (string) \file_get_contents($native . '/posix/wls_gateway_broker.c');
    $this->windows_NativeGatewayBootstrapDeadline = (string) \file_get_contents($native . '/windows/wls_gateway_broker.c');
    $this->controller_NativeGatewayBootstrapDeadline = (string) \file_get_contents($server . '/bin/wls_gateway_controller.php');
}

private function assert_NativeGatewayBootstrapDeadline_testBootstrapWireBudgetContainsTheControllerBudgetOnBothPlatforms(): void
{
    self::assertMatchesRegularExpression('/#define WLS_CONTROLLER_IO_TIMEOUT_SECONDS\s+90\b/', $this->posix_NativeGatewayBootstrapDeadline);
    self::assertMatchesRegularExpression('/#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_SECONDS\s+\\\\\\s*' . 'WLS_CONTROLLER_IO_TIMEOUT_SECONDS/', $this->posix_NativeGatewayBootstrapDeadline);
    self::assertMatchesRegularExpression('/#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS\s+90000U\b/', $this->windows_NativeGatewayBootstrapDeadline);
    self::assertMatchesRegularExpression('/#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_MS\s+\\\\\\s*' . 'WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS/', $this->windows_NativeGatewayBootstrapDeadline);
    self::assertMatchesRegularExpression('/private const BOOTSTRAP_RECOVERY_BUDGET_SECONDS = 85\.0;/', $this->controller_NativeGatewayBootstrapDeadline);
}

private function assert_NativeGatewayBootstrapDeadline_testActionCapCoversTheRouteQuotaAndFailsDeterministically(): void
{
    self::assertMatchesRegularExpression('/private const MAX_TOTAL_ROUTES = 2048;/', $this->controller_NativeGatewayBootstrapDeadline);
    self::assertStringContainsString("\\strlen(\$line) > 65_536", $this->controller_NativeGatewayBootstrapDeadline);
    foreach ([$this->posix_NativeGatewayBootstrapDeadline, $this->windows_NativeGatewayBootstrapDeadline] as $source) {
        self::assertMatchesRegularExpression('/#define WLS_BOOTSTRAP_MAX_ACTIONS\s+4096U\b/', $source);
        self::assertStringContainsString('#define WLS_BOOTSTRAP_ACTION_LIMIT_EXCEEDED 3', $source);
        $bootstrap = $this->functionBody_NativeGatewayBootstrapDeadline($source, 'static int wls_bootstrap_once(', 'static int wls_bootstrap_lock_until(');
        self::assertStringContainsString('action_count >= WLS_BOOTSTRAP_MAX_ACTIONS', $bootstrap);
        self::assertStringContainsString('result = WLS_BOOTSTRAP_ACTION_LIMIT_EXCEEDED;', $bootstrap);
    }
}

private function assert_NativeGatewayBootstrapDeadline_testPosixBootstrapUsesOneDeadlineAcrossLockAndEveryWireStep(): void
{
    $serialized = $this->functionBody_NativeGatewayBootstrapDeadline($this->posix_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_once_serialized(', 'static int wls_bootstrap_health_record(');
    $bootstrap = $this->functionBody_NativeGatewayBootstrapDeadline($this->posix_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_once(', 'static int wls_bootstrap_lock_until(');
    $read = $this->functionBody_NativeGatewayBootstrapDeadline($this->posix_NativeGatewayBootstrapDeadline, 'static ssize_t wls_bootstrap_read_line_until(', 'static int wls_bootstrap_write_all_until(');
    $write = $this->functionBody_NativeGatewayBootstrapDeadline($this->posix_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_write_all_until(', 'static int wls_authenticate_controller_until(');
    $this->assertNeedlesInOrder_NativeGatewayBootstrapDeadline($serialized, ['wls_monotonic_milliseconds(&now_ms)', 'absolute_deadline_ms = now_ms + timeout_ms;', 'wls_bootstrap_lock_until(absolute_deadline_ms)', 'wls_bootstrap_once(', 'absolute_deadline_ms,', 'pthread_mutex_unlock(&wls_bootstrap_mutex)']);
    self::assertStringContainsString('absolute_deadline_ms', $bootstrap);
    self::assertStringContainsString('wls_connect_controller_until(', $bootstrap);
    self::assertSame(3, \substr_count($bootstrap, 'wls_bootstrap_write_all_until('));
    self::assertStringContainsString('wls_bootstrap_read_line_until(', $bootstrap);
    self::assertStringNotContainsString('wls_write_all(', $bootstrap);
    self::assertStringContainsString('MSG_PEEK', $read);
    self::assertStringContainsString('wls_bootstrap_socket_timeout_until(fd, deadline_ms)', $read);
    self::assertStringContainsString('wls_bootstrap_read_exact_until(', $read);
    self::assertStringContainsString('wls_bootstrap_socket_timeout_until(fd, deadline_ms)', $write);
}

private function assert_NativeGatewayBootstrapDeadline_testWindowsBootstrapUsesOneDeadlineAndStopFenceAcrossWireSteps(): void
{
    $serialized = $this->functionBody_NativeGatewayBootstrapDeadline($this->windows_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_once_serialized(', 'static int wls_bootstrap_controller(');
    $bootstrap = $this->functionBody_NativeGatewayBootstrapDeadline($this->windows_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_once(', 'static int wls_bootstrap_lock_until(');
    $deadlineIo = $this->functionBody_NativeGatewayBootstrapDeadline($this->windows_NativeGatewayBootstrapDeadline, 'static int wls_bootstrap_socket_timeout_until(', 'static void wls_hex_encode_fixed(');
    $this->assertNeedlesInOrder_NativeGatewayBootstrapDeadline($serialized, ['wls_protocol_monotonic_milliseconds(&now_ms)', 'absolute_deadline_ms = now_ms + (ULONGLONG)io_timeout_ms;', 'wls_bootstrap_lock_until(absolute_deadline_ms, stop_event)', 'wls_bootstrap_once(', 'absolute_deadline_ms,', 'stop_event,', 'ReleaseSRWLockExclusive(&wls_bootstrap_lock)']);
    self::assertStringContainsString('wls_connect_controller_until(', $bootstrap);
    self::assertSame(3, \substr_count($bootstrap, 'wls_bootstrap_socket_write_all_until('));
    self::assertStringContainsString('wls_bootstrap_socket_read_line_until(', $bootstrap);
    self::assertStringNotContainsString('wls_socket_write_all(', $bootstrap);
    self::assertStringContainsString('WaitForSingleObject(stop_event, 0U)', $deadlineIo);
    self::assertGreaterThanOrEqual(3, \substr_count($deadlineIo, 'wls_bootstrap_socket_timeout_until('));
}

private function functionBody_NativeGatewayBootstrapDeadline(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Missing function start: ' . $startNeedle);
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Missing function end: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

/** @param list<string> $needles */
private function assertNeedlesInOrder_NativeGatewayBootstrapDeadline(string $source, array $needles): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $position = \strpos($source, $needle, $offset);
        self::assertIsInt($position, 'Missing ordered contract: ' . $needle);
        $offset = $position + \strlen($needle);
    }
}

private string $posixBroker_NativeGatewayControllerOnlyRecovery;

private string $windowsBroker_NativeGatewayControllerOnlyRecovery;

private string $posixLauncher_NativeGatewayControllerOnlyRecovery;

private string $windowsLauncher_NativeGatewayControllerOnlyRecovery;

private function setUp_NativeGatewayControllerOnlyRecovery(): void
{
    $native = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/Native';
    $this->posixBroker_NativeGatewayControllerOnlyRecovery = (string) \file_get_contents($native . '/posix/wls_gateway_broker.c');
    $this->windowsBroker_NativeGatewayControllerOnlyRecovery = (string) \file_get_contents($native . '/windows/wls_gateway_broker.c');
    $this->posixLauncher_NativeGatewayControllerOnlyRecovery = (string) \file_get_contents($native . '/posix/wls_gateway_launcher.c');
    $this->windowsLauncher_NativeGatewayControllerOnlyRecovery = (string) \file_get_contents($native . '/windows/wls_gateway_launcher.c');
}

private function assert_NativeGatewayControllerOnlyRecovery_testControllerOnlyBootstrapKeepsFiveSecondDataPlaneFenceOnBothPlatforms(): void
{
    self::assertMatchesRegularExpression('/#define WLS_CONTROLLER_LIVENESS_INTERVAL_MILLISECONDS\s+5000ULL\b/', $this->posixBroker_NativeGatewayControllerOnlyRecovery);
    self::assertMatchesRegularExpression('/#define WLS_CONTROLLER_LIVENESS_INTERVAL_MS\s+5000ULL\b/', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    $posixBootstrap = $this->functionBody_NativeGatewayControllerOnlyRecovery($this->posixBroker_NativeGatewayControllerOnlyRecovery, 'static int wls_bootstrap_controller(', 'static void wls_destroy_bootstrap_maintenance_context(');
    self::assertGreaterThanOrEqual(3, \substr_count($posixBootstrap, 'wls_owned_nginx_generation_alive('));
    self::assertStringContainsString('if (slice > 1000000U)', $posixBootstrap);
    self::assertStringContainsString('return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;', $posixBootstrap);
    self::assertMatchesRegularExpression('/bootstrap_maintenance_context,\s*WLS_CONTROLLER_IO_TIMEOUT_SECONDS,\s*' . 'NULL,\s*NULL,\s*NULL/s', $this->posixBroker_NativeGatewayControllerOnlyRecovery);
    self::assertMatchesRegularExpression('/bootstrap_maintenance_context,\s*\(int\)\(\s*' . 'WLS_CONTROLLER_LIVENESS_INTERVAL_MILLISECONDS\s*\/\s*1000ULL\s*\),\s*' . 'home,\s*active_slot,\s*runtime_generation/s', $this->posixBroker_NativeGatewayControllerOnlyRecovery);
    $windowsBootstrap = $this->functionBody_NativeGatewayControllerOnlyRecovery($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'static int wls_bootstrap_controller(', 'static DWORD WINAPI wls_bootstrap_maintenance_thread(');
    self::assertGreaterThanOrEqual(2, \substr_count($windowsBootstrap, 'WaitForSingleObject(nginx_process, 0U)'));
    self::assertStringContainsString('HANDLE wait_handles[2] = {stop_event, nginx_process};', $windowsBootstrap);
    self::assertStringContainsString('WaitForMultipleObjects(', $windowsBootstrap);
    self::assertStringContainsString('return WLS_CONTROLLER_WAIT_STOPPED;', $windowsBootstrap);
    self::assertStringContainsString('return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;', $windowsBootstrap);
    self::assertMatchesRegularExpression('/&bootstrap_maintenance_context,\s*WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS,\s*NULL/s', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertMatchesRegularExpression('/&bootstrap_maintenance_context,\s*\(DWORD\)' . 'WLS_CONTROLLER_LIVENESS_INTERVAL_MS,\s*nginx_process/s', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
}

private function assert_NativeGatewayControllerOnlyRecovery_testPosixDataPlaneDownSkipsNormalZeroExitAndRequestsFullTreeRestart(): void
{
    self::assertStringContainsString('#define WLS_DATA_PLANE_DOWN_EXIT 79', $this->posixBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringContainsString('#define WLS_SERVICE_TREE_RESTART 79', $this->posixLauncher_NativeGatewayControllerOnlyRecovery);
    self::assertGreaterThanOrEqual(3, \substr_count($this->posixBroker_NativeGatewayControllerOnlyRecovery, 'controller_restarted = -2;'));
    self::assertStringContainsString("if (controller_restarted == -2) {\n                    goto cleanup;\n                }", $this->posixBroker_NativeGatewayControllerOnlyRecovery);
    $dataPlaneJump = \strpos($this->posixBroker_NativeGatewayControllerOnlyRecovery, 'if (controller_restarted == -2)');
    $normalExit = \strpos($this->posixBroker_NativeGatewayControllerOnlyRecovery, 'exit_code = wls_bootstrap_maintenance_failed ? 1 : 0;');
    $cleanup = \strpos($this->posixBroker_NativeGatewayControllerOnlyRecovery, "cleanup:\n", $normalExit ?: 0);
    self::assertIsInt($dataPlaneJump);
    self::assertIsInt($normalExit);
    self::assertIsInt($cleanup);
    self::assertLessThan($normalExit, $dataPlaneJump);
    self::assertLessThan($cleanup, $normalExit);
    self::assertStringContainsString('wls_classify_broker_exit(WLS_SERVICE_TREE_RESTART, 1)', $this->posixLauncher_NativeGatewayControllerOnlyRecovery);
    self::assertMatchesRegularExpression('/wls_classify_broker_exit\(\s*WLS_SERVICE_TREE_RESTART,\s*0,\s*1,\s*0\s*\)' . '\s*!=\s*WLS_SERVICE_TREE_RESTART/s', $this->windowsLauncher_NativeGatewayControllerOnlyRecovery);
}

private function assert_NativeGatewayControllerOnlyRecovery_testWindowsControllerReadyWaitUsesOneAbsoluteDeadlineAndAllCancellationHandles(): void
{
    $wait = $this->functionBody_NativeGatewayControllerOnlyRecovery($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'static int wls_wait_for_controller(', 'static int wls_retire_controller_handle_bounded(');
    self::assertStringContainsString('HANDLE wait_handles[3];', $wait);
    self::assertStringContainsString('started_ms > ULLONG_MAX - 45000ULL', $wait);
    self::assertSame(1, \substr_count($wait, 'deadline_ms = started_ms + 45000ULL;'));
    self::assertStringContainsString('WLS_CONTROLLER_IO_TIMEOUT_MS', $wait);
    self::assertSame(2, \substr_count($wait, 'WaitForMultipleObjects('));
    self::assertStringContainsString('wait_handles[0] = controller;', $wait);
    self::assertStringContainsString('if (stop_event != NULL) wait_handles[wait_count++] = stop_event;', $wait);
    self::assertStringContainsString('if (nginx_process != NULL) wait_handles[wait_count++] = nginx_process;', $wait);
    self::assertStringContainsString('return WLS_CONTROLLER_WAIT_STOPPED;', $wait);
    self::assertStringContainsString('return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;', $wait);
    self::assertMatchesRegularExpression('/controller_process,\s*home,\s*fencing,\s*stop_event,\s*NULL/s', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertMatchesRegularExpression('/controller_process,\s*home,\s*fencing,\s*stop_event,\s*nginx_process/s', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
}

private function assert_NativeGatewayControllerOnlyRecovery_testWindowsRecoveryRetainsVerifiedHandlesAndRequiresStableReadyWindow(): void
{
    $reopen = $this->functionBody_NativeGatewayControllerOnlyRecovery($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'static int wls_reopen_owned_nginx(', 'static HANDLE wls_start_controller(');
    $this->assertNeedlesInOrder_NativeGatewayControllerOnlyRecovery($reopen, ['verified_process = wls_open_owned_nginx(', 'if (verified_process == NULL || verified_pid != nginx_pid)', 'CloseHandle(*current_process);', '*current_process = verified_process;']);
    $retire = $this->functionBody_NativeGatewayControllerOnlyRecovery($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'static int wls_retire_controller_handle_bounded(', 'static void wls_wake_pipe(');
    $this->assertNeedlesInOrder_NativeGatewayControllerOnlyRecovery($retire, ['TerminateProcess(*controller, exit_code)', 'WaitForSingleObject(*controller, 5000U)', 'if (wait != WAIT_OBJECT_0) return 1;', 'CloseHandle(*controller);', '*controller = NULL;']);
    self::assertStringContainsString('wls_retire_controller_handle_bounded(', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringContainsString('controller_ready_since_ms = 0ULL;', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringContainsString('controller_ready_since_ms = GetTickCount64();', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringContainsString('probe_now_ms - controller_ready_since_ms', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringContainsString('>= WLS_CONTROLLER_RESTART_STABLE_MS', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    self::assertStringNotContainsString('controller_last_restart_ms', $this->windowsBroker_NativeGatewayControllerOnlyRecovery);
    $ready = \strpos($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'controller_ready_since_ms = GetTickCount64();');
    $restarted = \strpos($this->windowsBroker_NativeGatewayControllerOnlyRecovery, 'controller_restarted = 1;', $ready ?: 0);
    self::assertIsInt($ready);
    self::assertIsInt($restarted);
    self::assertStringNotContainsString('controller_restart_streak = 0U;', \substr($this->windowsBroker_NativeGatewayControllerOnlyRecovery, $ready, $restarted - $ready));
}

private function functionBody_NativeGatewayControllerOnlyRecovery(string $source, string $startNeedle, string $endNeedle): string
{
    $start = \strpos($source, $startNeedle);
    self::assertIsInt($start, 'Missing function start: ' . $startNeedle);
    $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
    self::assertIsInt($end, 'Missing function end: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

/** @param list<string> $needles */
private function assertNeedlesInOrder_NativeGatewayControllerOnlyRecovery(string $source, array $needles): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $position = \strpos($source, $needle, $offset);
        self::assertIsInt($position, 'Missing ordered contract: ' . $needle);
        $offset = $position + \strlen($needle);
    }
}

private string $posix_NativeGatewayEmergencyBinding;

private string $windows_NativeGatewayEmergencyBinding;

private string $controller_NativeGatewayEmergencyBinding;

private function setUp_NativeGatewayEmergencyBinding(): void
{
    $server = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $native = $server . '/Service/Edge/Gateway/Native';
    $this->posix_NativeGatewayEmergencyBinding = (string) \file_get_contents($native . '/posix/wls_gateway_broker.c');
    $this->windows_NativeGatewayEmergencyBinding = (string) \file_get_contents($native . '/windows/wls_gateway_broker.c');
    $this->controller_NativeGatewayEmergencyBinding = (string) \file_get_contents($server . '/bin/wls_gateway_controller.php');
}

private function assert_NativeGatewayEmergencyBinding_testControllerAndBothBrokersShareTheExactBindingEnvelope(): void
{
    $controllerBinding = $this->between_NativeGatewayEmergencyBinding($this->controller_NativeGatewayEmergencyBinding, 'private function bindBrokerEmergencyCredential(', 'private function abortBrokerCertificateRoots(');
    foreach (["'EMERGENCY_BIND'", '$projectUuid', '$transactionId', '$intentDigest', '(string)$securityGeneration', '$this->brokerOwnerIdentity($owner)', '$credentialId', '(string)$credentialGeneration', '$credentialSecret'] as $field) {
        self::assertStringContainsString($field, $controllerBinding);
    }
    self::assertStringContainsString('\count($response) !== 5', $controllerBinding);
    foreach ([$this->posix_NativeGatewayEmergencyBinding, $this->windows_NativeGatewayEmergencyBinding] as $source) {
        $dispatcher = $this->between_NativeGatewayEmergencyBinding($source, 'static int wls_handle_action_v2(', 'static const char *wls_json_value(', 2);
        $this->assertNeedlesInOrder_NativeGatewayEmergencyBinding($dispatcher, ['if (strcmp(channel', '"admin"', 'strcmp(fields[1], "EMERGENCY_BIND") == 0', 'count != 10U', '"DENIED"', 'fields[9]']);
        self::assertStringNotContainsString('"EMERGENCY_REVOKE"', $dispatcher);
    }
}

private function assert_NativeGatewayEmergencyBinding_testPosixBindingIsCommittedOwnerFencedAndSecretPrivate(): void
{
    $binding = $this->between_NativeGatewayEmergencyBinding($this->posix_NativeGatewayEmergencyBinding, 'static int wls_emergency_bind_v1(', 'static int wls_emergency_domain(');
    foreach (['wls_security_summary(', '!security_summary.committed_found', 'security_summary.assigned != security_generation', 'wls_auth_collect(', 'security_summary.transaction_anchor, committed_digest', 'roots[index].owner != owner', 'roots[index].expected_count != (unsigned long)root_count', 'credential_generation < existing.credential_generation', 'credential_generation == existing.credential_generation', 'wls_security_append_locked(', 'WLS-ACTION/2\tOK\tEMERGENCY_BIND'] as $required) {
        self::assertStringContainsString($required, $binding);
    }
    self::assertStringNotContainsString('WLS_MAYBE_UNUSED wls_emergency_bind_v1', $this->posix_NativeGatewayEmergencyBinding);
    $open = $this->between_NativeGatewayEmergencyBinding($this->posix_NativeGatewayEmergencyBinding, 'static int wls_emergency_file_open_locked(', 'static int wls_emergency_parse_binding(');
    foreach (['O_NOFOLLOW', 'LOCK_EX', 'status.st_nlink != 1', 'status.st_uid != geteuid()', 'wls_posix_acl_remove_and_verify(*fd, 0)'] as $required) {
        self::assertStringContainsString($required, $open);
    }
    self::assertStringContainsString('"trust/emergency-credentials-v1.tsv", 0600', $binding);
    $collector = $this->between_NativeGatewayEmergencyBinding($this->posix_NativeGatewayEmergencyBinding, 'static int wls_emergency_collect_binding(', 'static int wls_emergency_bind_v1(');
    self::assertStringContainsString('parsed.credential_generation', $collector);
    self::assertStringContainsString('== selected->credential_generation', $collector);
    self::assertStringContainsString('strcmp(parsed.secret, selected->secret) != 0', $collector);
}

private function assert_NativeGatewayEmergencyBinding_testWindowsBindingHasEquivalentAclLedgerAndConflictSemantics(): void
{
    $open = $this->between_NativeGatewayEmergencyBinding($this->windows_NativeGatewayEmergencyBinding, 'static int wls_win_emergency_open_locked(', 'static int wls_win_emergency_parse_binding(');
    foreach (['L"trust\\\\emergency-credentials-v1.tsv"', 'FILE_FLAG_OPEN_REPARSE_POINT', 'FILE_FLAG_WRITE_THROUGH', 'LOCKFILE_EXCLUSIVE_LOCK', 'info.NumberOfLinks != 1U', 'wls_secure_root_only_handle(*file)'] as $required) {
        self::assertStringContainsString($required, $open);
    }
    $binding = $this->between_NativeGatewayEmergencyBinding($this->windows_NativeGatewayEmergencyBinding, 'static int wls_win_emergency_bind_v1(', 'static int wls_win_auth_transfer_target(');
    foreach (['wls_win_security_summary(', '!security_summary.committed_found', 'security_summary.assigned != security_generation', 'wls_win_auth_collect(', 'security_summary.transaction_anchor, committed_digest', '_stricmp(roots[index].owner, owner) != 0', 'roots[index].expected_count', 'wls_win_emergency_collect_binding(', 'credential_generation < existing.credential_generation', 'wls_win_emergency_binding_equal(&existing, &requested)', 'wls_win_security_append_locked(binding_file, record)', 'WLS-ACTION/2\tOK\tEMERGENCY_BIND', '"BINDING_CONFLICT" : "LEDGER_INVALID"', 'SecureZeroMemory(record, sizeof(record))'] as $required) {
        self::assertStringContainsString($required, $binding);
    }
    $collector = $this->between_NativeGatewayEmergencyBinding($this->windows_NativeGatewayEmergencyBinding, 'static int wls_win_emergency_collect_binding(', 'static int wls_win_emergency_bind_v1(');
    self::assertStringContainsString('== selected->credential_generation', $collector);
    self::assertStringContainsString('wls_win_emergency_binding_equal(&parsed, selected)', $collector);
}

private function between_NativeGatewayEmergencyBinding(string $source, string $startNeedle, ?string $endNeedle, int $occurrence = 1): string
{
    $offset = 0;
    $start = false;
    for ($index = 0; $index < $occurrence; $index++) {
        $start = \strpos($source, $startNeedle, $offset);
        self::assertIsInt($start, 'Missing source contract: ' . $startNeedle);
        $offset = $start + \strlen($startNeedle);
    }
    if ($endNeedle === null) {
        return \substr($source, $start);
    }
    $end = \strpos($source, $endNeedle, $offset);
    self::assertIsInt($end, 'Missing source boundary: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

/** @param list<string> $needles */
private function assertNeedlesInOrder_NativeGatewayEmergencyBinding(string $source, array $needles): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $position = \strpos($source, $needle, $offset);
        self::assertIsInt($position, 'Missing ordered contract: ' . $needle);
        $offset = $position + \strlen($needle);
    }
}

private function assert_NativeGatewayRollbackAction_testBothNativeBrokersOwnThePackageLockedRollbackPublication(): void
{
    $gateway = '/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway';
    $posix = (string) \file_get_contents($gateway . '/Native/posix/wls_gateway_broker.c');
    $windows = (string) \file_get_contents($gateway . '/Native/windows/wls_gateway_broker.c');
    foreach ([$posix, $windows] as $source) {
        self::assertStringContainsString('ROLLBACK_REQUEST', $source);
        self::assertStringContainsString('package-install.lock', $source);
        self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/3', $source);
        self::assertStringContainsString('upgrade-rollback.request.wls-backup-', $source);
        self::assertStringContainsString('request_nonce=%32[0-9a-f]', $source);
        self::assertStringContainsString('host_boot_id=%64[0-9a-f]', $source);
        self::assertStringContainsString('binding->prepared_monotonic', $source);
        self::assertStringContainsString('binding->total_deadline_monotonic', $source);
        self::assertStringContainsString('binding->intent_boot_id', $source);
        self::assertStringContainsString('request.request_sha256', $source);
    }
    $posixActionStart = \strpos($posix, 'static int wls_rollback_request_action_v3(');
    $posixActionEnd = \strpos($posix, 'static int wls_process_identity(', $posixActionStart);
    $windowsActionStart = \strpos($windows, 'static int wls_win_rollback_request_action_v3(');
    $windowsActionEnd = \strpos($windows, 'static int wls_win_read_path_contents(', $windowsActionStart);
    self::assertIsInt($posixActionStart);
    self::assertIsInt($posixActionEnd);
    self::assertIsInt($windowsActionStart);
    self::assertIsInt($windowsActionEnd);
    foreach ([\substr($posix, $posixActionStart, $posixActionEnd - $posixActionStart), \substr($windows, $windowsActionStart, $windowsActionEnd - $windowsActionStart)] as $action) {
        self::assertStringContainsString('requested_monotonic', $action);
        self::assertStringNotContainsString('time(NULL)', $action);
    }
    self::assertStringContainsString('flock(fd, LOCK_EX | LOCK_NB)', $posix);
    self::assertStringContainsString('mach_absolute_time()', $posix);
    self::assertStringContainsString('LockFileEx(', $windows);
    self::assertStringContainsString('UnlockFileEx(', $windows);
    self::assertStringContainsString('QueryPerformanceCounter(', $windows);
    self::assertStringContainsString('wls_protocol_monotonic_milliseconds(&monotonic_now)', $windows);
}

private function assert_NativeGatewayRollbackAction_testControllerUsesOnlyTheBoundBrokerReceiptForRollback(): void
{
    $server = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $controller = (string) \file_get_contents($server . '/bin/wls_gateway_controller.php');
    $start = \strpos($controller, 'private function rollbackBinarySlot(): bool');
    $end = \strpos($controller, 'private function validateUpgradeIntentForRollback(', $start);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $rollback = \substr($controller, $start, $end - $start);
    self::assertStringContainsString('$this->verifiedRollbackTargetManifest($previous)', $rollback);
    self::assertLessThan(\strpos($rollback, "'ROLLBACK_REQUEST'"), \strpos($rollback, 'verifiedRollbackTargetManifest($previous)'));
    self::assertStringContainsString('$this->stableFileHash(', $rollback);
    self::assertStringContainsString('$previousManifest[\'components\']', $rollback);
    self::assertStringContainsString("'ROLLBACK_REQUEST'", $rollback);
    self::assertStringContainsString('brokerActionsAvailable($channel)', $rollback);
    self::assertStringContainsString('WLS-UPGRADE-ROLLBACK/3', $rollback);
    self::assertStringContainsString('requested_monotonic_ms=', $rollback);
    self::assertStringContainsString("\\hrtime(true)", $rollback);
    self::assertStringContainsString("\\hash('sha256', \$requestEnvelope)", $rollback);
    self::assertStringNotContainsString("stateDir() . DIRECTORY_SEPARATOR . 'upgrade-rollback.request'", $rollback);
    self::assertStringNotContainsString('$this->atomicWrite(', $rollback);
    $targetStart = \strpos($controller, 'private function verifiedRollbackTargetManifest(');
    self::assertIsInt($targetStart);
    $targetEnd = \strpos($controller, 'private function validateUpgradeIntentForRollback(', $targetStart);
    self::assertIsInt($targetEnd);
    $target = \substr($controller, $targetStart, $targetEnd - $targetStart);
    self::assertStringContainsString('self::DURABLE_STATE_CONTRACT', $target);
    self::assertStringContainsString('$expectedRuntimeGeneration', $target);
    self::assertStringContainsString("'host_gateway'", $target);
    self::assertStringContainsString("'stable_launcher_rollback_target_proof'", $target);
    self::assertStringContainsString("(\$capabilities['stable_launcher_rollback_target_proof'] ?? null)", $target);
    self::assertStringContainsString('!== true', $target);
}

private function assert_NativeGatewayRollbackAction_testControllerRollbackContractMatchesThePackageContractExactly(): void
{
    $controller = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/bin/wls_gateway_controller.php');
    $start = \strpos($controller, 'private const DURABLE_STATE_CONTRACT = [');
    self::assertIsInt($start);
    $end = \strpos($controller, '    ];', $start);
    self::assertIsInt($end);
    $constant = \substr($controller, $start, $end - $start);
    self::assertSame(\count(HostGatewayPackageManager::DURABLE_STATE_CONTRACT), \substr_count($constant, ' => '));
    foreach (HostGatewayPackageManager::DURABLE_STATE_CONTRACT as $field => $value) {
        $encoded = \is_string($value) ? "'" . $value . "'" : (string) $value;
        self::assertStringContainsString("'" . $field . "' => " . $encoded, $constant);
    }
}

private function assert_NativeGatewayRollbackAction_testPackageManagerCleansOnlyAFullyBoundCurrentRequestUnderInstallLock(): void
{
    $manager = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Service/Edge/Gateway/HostGatewayPackageManager.php');
    $start = \strpos($manager, 'public function rollbackUpgradeActivation(');
    $end = \strpos($manager, 'public function rollbackActivation(', $start);
    self::assertIsInt($start);
    self::assertIsInt($end);
    $rollback = \substr($manager, $start, $end - $start);
    self::assertStringContainsString('$this->withInstallLock(', $rollback);
    self::assertStringContainsString('cleanupAtomicWriteRecoveryBackups(', $rollback);
    self::assertStringContainsString('validateUpgradeRollbackRequest(', $rollback);
    self::assertStringContainsString("'prepared_monotonic_ms'", $manager);
    self::assertStringContainsString("'rollback_deadline_monotonic_ms'", $manager);
}

private string $capacity_NativeGatewayWindowsCapacity;

private string $launcher_NativeGatewayWindowsCapacity;

private string $cmake_NativeGatewayWindowsCapacity;

private string $fixture_NativeGatewayWindowsCapacity;

private string $workflow_NativeGatewayWindowsCapacity;

private function setUp_NativeGatewayWindowsCapacity(): void
{
    parent::setUp();
    $server = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $native = $server . '/Service/Edge/Gateway/Native';
    $this->capacity_NativeGatewayWindowsCapacity = $this->read_NativeGatewayWindowsCapacity($native . '/windows/wls_gateway_capacity.c');
    $this->launcher_NativeGatewayWindowsCapacity = $this->read_NativeGatewayWindowsCapacity($native . '/windows/wls_gateway_launcher.c');
    $this->cmake_NativeGatewayWindowsCapacity = $this->read_NativeGatewayWindowsCapacity($native . '/CMakeLists.txt');
    $this->fixture_NativeGatewayWindowsCapacity = $this->read_NativeGatewayWindowsCapacity($server . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php');
    $this->workflow_NativeGatewayWindowsCapacity = $this->read_NativeGatewayWindowsCapacity(\dirname($server, 4) . '/.github/workflows/wls-gateway-native.yml');
}

private function assert_NativeGatewayWindowsCapacity_testPhysicalAllocationAndInodeIdentityProofAreNative(): void
{
    foreach (['#define WLS_CAPACITY_PRODUCTION_BYTES 10737418240ULL', '#define WLS_CAPACITY_PRODUCTION_INODES 65536U', '#define WLS_CAPACITY_TOKEN_FLUSH_BATCH 4096U', 'FileAllocationInfo', 'FileEndOfFileInfo', 'standard.AllocationSize.QuadPart', 'FILE_ATTRIBUTE_SPARSE_FILE', 'FILE_ATTRIBUTE_COMPRESSED', 'FILE_ATTRIBUTE_ENCRYPTED', 'FILE_ATTRIBUTE_REPARSE_POINT', 'FILE_ID_INFO identity', 'FILE_ID_128 *identities', 'observation.standard.EndOfFile.QuadPart != 0LL', 'observation.standard.NumberOfLinks != 1U', 'qsort(', 'FlushFileBuffers(volume)'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $createTokens = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_create_tokens(', 'static int wls_capacity_parse_token_leaf(');
    self::assertStringContainsString('(index + 1U) % WLS_CAPACITY_TOKEN_FLUSH_BATCH == 0U', $createTokens);
    self::assertStringNotContainsString('FlushFileBuffers(token)', $createTokens, 'Token creation must batch durable volume metadata instead of flushing every token.');
}

private function assert_NativeGatewayWindowsCapacity_testProgramDataAuthorityIgnoresEnvironmentAndRejectsReparseRoots(): void
{
    foreach (['SHGetKnownFolderPath(', 'wls_capacity_folderid_programdata', 'GetLongPathNameW(', 'GetDriveTypeW(root) == DRIVE_FIXED', 'FILE_FLAG_OPEN_REPARSE_POINT', 'wls_capacity_validate_directory_chain(', 'wls_capacity_parent_acl_safe(program_data_directory)', 'wls_capacity_acl_safe(handle)', 'sid_length != (DWORD)header->AceSize - sid_offset'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    self::assertDoesNotMatchRegularExpression('/(?:GetEnvironmentVariableW|_wgetenv|getenv)\s*\([^\n]*PROGRAMDATA/i', $this->capacity_NativeGatewayWindowsCapacity);
    self::assertStringContainsString('wls_windows_programdata_authority()', $this->launcher_NativeGatewayWindowsCapacity);
}

private function assert_NativeGatewayWindowsCapacity_testHeldCannotBypassDurableReleaseTransition(): void
{
    $command = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'int wls_windows_capacity_command(', null);
    $manifest = \strpos($command, 'wls_capacity_manifest_binding(');
    $live = \strpos($command, 'wls_capacity_live_present(');
    self::assertIsInt($manifest);
    self::assertIsInt($live);
    self::assertLessThan($live, $manifest, 'Manifest identity must be authenticated before live-state mutation.');
    self::assertStringContainsString('capacity reserve release transition was not started', $command);
    self::assertStringContainsString('WLS_CAPACITY_CONTROL_ABSENT', $this->between_NativeGatewayWindowsCapacity($command, 'if (held_present) {', '(void)printf("{\"state\":\"RELEASED\"}\n");'));
    foreach (['WLS_CAPACITY_CONTROL_REQUIRED', 'WLS_CAPACITY_CONTROL_TRANSITION', 'WLS_CAPACITY_CONTROL_ABSENT', 'WLS_CAPACITY_RELEASE_MARKER', 'MOVEFILE_WRITE_THROUGH'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $marker = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_control_marker(', 'static int wls_capacity_directory_optional(');
    self::assertStringNotContainsString('marker[0]', $marker, 'A torn release-marker write remains crash-replayable while all control tokens exist.');
}

private function assert_NativeGatewayWindowsCapacity_testEveryRebootstrapDerivedRootIsAnchoredBeforeMutation(): void
{
    $proof = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_anchor_proof(', 'static int wls_capacity_manifest_binding(');
    foreach (['L"state"', 'L"trust"', 'L"runtime"', 'L"runtime\\\\conf"', 'L"runtime\\\\temp"', 'L"runtime\\\\shadow"', 'L"runtime\\\\run"', 'L"snapshots"', 'L"snapshots-v2"', 'L"snapshot-candidates-v2"'] as $required) {
        self::assertStringContainsString($required, $proof);
    }
    foreach (['wls_capacity_hash_anchor_path(', 'observation.identity.VolumeSerialNumber == expected_volume', 'wls_capacity_open_directory('] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $command = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'int wls_windows_capacity_command(', null);
    $anchor = \strpos($command, 'wls_capacity_anchor_proof(');
    $liveMutation = \strpos($command, 'wls_capacity_create_live(');
    self::assertIsInt($anchor);
    self::assertIsInt($liveMutation);
    self::assertLessThan($liveMutation, $anchor, 'Every movable root must be pinned to the authority volume before reserve state can advance.');
}

private function assert_NativeGatewayWindowsCapacity_testWindowsBuildAndRealIntegrationOwnTheCapacityContract(): void
{
    foreach (['windows/wls_gateway_capacity.c', 'advapi32', 'ole32', 'shell32', '/W4 /WX /sdl'] as $required) {
        self::assertStringContainsString($required, $this->cmake_NativeGatewayWindowsCapacity);
    }
    foreach (['--capacity-reserve-contract-self-test', 'WLS_RUN_NATIVE_GATEWAY_WINDOWS_CAPACITY_INTEGRATION', 'capacity-test', '--launcher=build/wls-gateway-native/Release/wls-gateway-launcher.exe'] as $required) {
        self::assertStringContainsString($required, $this->workflow_NativeGatewayWindowsCapacity);
    }
    foreach (['Windows physical reserve create', 'Windows HELD reserve bypassed begin-release.', 'Windows torn release marker was not replayable.', 'Partial Windows control credits failed open.', 'Missing Windows control reserve failed open.', 'Windows ALLOCATING cancellation did not converge.', 'Windows derived-root junction/other-volume anchor failed open before HELD.'] as $required) {
        self::assertStringContainsString($required, $this->fixture_NativeGatewayWindowsCapacity);
    }
}

private function assert_NativeGatewayWindowsCapacity_testProductionCapacityIsAnExplicitDedicatedWindowsCrashMatrixGate(): void
{
    foreach (['schedule:', 'run_windows_production_capacity', 'windows-production-capacity', 'wls-gateway-capacity', 'WLS_RUN_NATIVE_GATEWAY_WINDOWS_PRODUCTION_CAPACITY_INTEGRATION', 'capacity-production-test', '--bytes=10737418240', '--inodes=65536', '--test-mode=0', "'token-directory'", "'direct-seal'", "'control-token-partial'", "'primary-token-partial'", 'wlsCapacityKillAtFailpoint(', 'wlsCapacityRecoverDurableMarker(', 'FOLDERID_ProgramData'] as $required) {
        self::assertStringContainsString($required, $this->workflow_NativeGatewayWindowsCapacity . $this->fixture_NativeGatewayWindowsCapacity);
    }
}

private function assert_NativeGatewayWindowsCapacity_testInspectMatchesTheReadOnlyFailClosedCapacityProtocol(): void
{
    foreach (['#define WLS_CAPACITY_INSPECT_SCHEMA "wls-capacity-inspect/1"', '#define WLS_CAPACITY_INSPECT_UNSAFE_EXIT 77', '#define WLS_CAPACITY_INSPECT_CONFLICT_EXIT 78', 'static void wls_capacity_print_inspect(', '{\"schema\":\"%s\",\"state\":\"%s\"}'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $command = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'int wls_windows_capacity_command(', null);
    self::assertStringContainsString('inspect_operation = wcscmp(operation, L"inspect") == 0;', $command);
    self::assertStringContainsString('(inspect_operation && manifest_argument != NULL)', $command);
    self::assertStringContainsString('|| inspect_operation)', $command);
    self::assertStringContainsString('&& reason != NULL)', $command);
    self::assertStringContainsString('result = WLS_CAPACITY_INSPECT_CONFLICT_EXIT;', $command);
    $inspect = $this->between_NativeGatewayWindowsCapacity($command, 'if (inspect_operation) {', 'if (wcscmp(operation, L"create") == 0) {');
    foreach (['wls_capacity_print_inspect("NONE")', 'wls_capacity_print_inspect("ALLOCATING")', 'wls_capacity_print_inspect("HELD")', 'wls_capacity_print_inspect("RELEASING")', 'wls_capacity_validate_removable_live(', 'wls_capacity_validate_live(', 'wls_capacity_detect_control_state(', 'WLS_CAPACITY_INSPECT_UNSAFE_EXIT'] as $required) {
        self::assertStringContainsString($required, $inspect);
    }
    foreach (['wls_capacity_create_live(', 'wls_capacity_remove_live(', 'MoveFileExW(', 'DeleteFileW(', 'RemoveDirectoryW(', 'FlushFileBuffers('] as $mutation) {
        self::assertStringNotContainsString($mutation, $inspect);
    }
    self::assertStringContainsString("!inspect_operation\n            && wls_capacity_volume_handle(", $command, 'Inspect must not acquire the mutating volume handle.');
    self::assertStringContainsString('inspect_operation ? 0 : 1,', $command, 'Inspect must not request a writable capacity-directory handle.');
}

private function assert_NativeGatewayWindowsCapacity_testDefinitionParentCreditsAreDurableAndLifecycleBound(): void
{
    foreach (['#define WLS_CAPACITY_PLATFORM_BYTES 4194304ULL', '#define WLS_CAPACITY_PLATFORM_INODES 2U', '#define WLS_CAPACITY_PLATFORM_BYTES_PER_FILE 2097152ULL', 'struct wls_capacity_platform_anchor', 'wls_capacity_platform_reserve_state(', 'wls_capacity_platform_reserve_create(', 'wls_capacity_platform_reserve_release(', 'wls_capacity_platform_direct_acl_exact(', 'PROTECTED_DACL_SECURITY_INFORMATION', 'FILE_FLAG_OPEN_REPARSE_POINT', 'standard.NumberOfLinks != 1U'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $command = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'int wls_windows_capacity_command(', null);
    $create = $this->between_NativeGatewayWindowsCapacity($command, 'if (wcscmp(operation, L"create") == 0) {', 'if (wcscmp(operation, L"verify") == 0) {');
    foreach (['wls_capacity_platform_reserve_create(', 'wls_capacity_platform_reserve_verify(', 'wls_capacity_platform_reserve_cleanup_allocating('] as $required) {
        self::assertStringContainsString($required, $create);
    }
    $begin = $this->between_NativeGatewayWindowsCapacity($command, 'if (wcscmp(operation, L"begin-release") == 0) {', 'if (held_present) {');
    $transition = \strpos($begin, 'wls_capacity_prepare_release(');
    $directRelease = \strpos($begin, 'wls_capacity_platform_reserve_release(');
    $finish = \strpos($begin, 'wls_capacity_finish_release_control(');
    self::assertIsInt($transition);
    self::assertIsInt($directRelease);
    self::assertIsInt($finish);
    self::assertGreaterThan($transition, $directRelease);
    self::assertGreaterThan($directRelease, $finish);
    $complete = $this->between_NativeGatewayWindowsCapacity($command, 'if (held_present) {', '(void)printf("{\"state\":\"RELEASED\"}\n");');
    self::assertStringContainsString('wls_capacity_platform_reserve_absent(', $complete);
    self::assertStringContainsString('wls_capacity_platform_reserve_cleanup_allocating(', $complete);
}

private function assert_NativeGatewayWindowsCapacity_testProductionCrashGateKillsRealHelpersAndRecoversDurableState(): void
{
    foreach (['if(WLS_BUILD_TEST_HELPERS)', 'WLS_NATIVE_TEST_HOOKS=1'] as $testBuildFence) {
        self::assertStringContainsString($testBuildFence, $this->cmake_NativeGatewayWindowsCapacity);
    }
    self::assertStringContainsString('-DWLS_BUILD_TEST_HELPERS=ON', $this->workflow_NativeGatewayWindowsCapacity);
    foreach (['#if defined(WLS_NATIVE_TEST_HOOKS)', 'WLS_CAPACITY_TEST_FAILPOINT', 'wls_capacity_test_failpoint(L"allocation")', 'wls_capacity_test_failpoint(L"token-batch")', 'wls_capacity_test_failpoint(L"rename")', 'wls_capacity_test_failpoint(L"begin")', 'wls_capacity_test_failpoint(L"release")', 'wls_capacity_test_failpoint(L"token-directory")', 'wls_capacity_test_failpoint(L"direct-seal")', 'L"control-token-partial"', 'L"primary-token-partial"'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    foreach (['function wlsCapacityKillAtFailpoint(', 'function wlsCapacityRecoverDurableMarker(', 'proc_open(', 'proc_terminate(', "'schema' => 'wls-capacity-production-gate/1'", "'allocation'", "'token-batch'", "'rename'", "'begin'", "'release'", "'token-directory'", "'direct-seal'", "'control-token-partial'", "'primary-token-partial'", "'schema' => 'wls-capacity-inspect/1'", "'state' => 'ALLOCATING'", "=== 'HELD'", "=== 'RELEASING'"] as $required) {
        self::assertStringContainsString($required, $this->fixture_NativeGatewayWindowsCapacity);
    }
    foreach (['concurrency:', 'wls-windows-production-capacity', 'cancel-in-progress: false'] as $required) {
        self::assertStringContainsString($required, $this->workflow_NativeGatewayWindowsCapacity);
    }
}

private function assert_NativeGatewayWindowsCapacity_testCrashIntermediatesKeepExactAclAndReplayableState(): void
{
    $directoryCreate = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_create_directory(', 'static int wls_capacity_allocate_file(');
    foreach (['SECURITY_ATTRIBUTES attributes', 'wls_capacity_directory_descriptor(', 'CreateDirectoryW(path, &attributes)', 'wls_capacity_platform_direct_apply(directory, security)', 'wls_capacity_directory_acl_exact(', 'if (result != 0 && created)'] as $required) {
        self::assertStringContainsString($required, $directoryCreate);
    }
    $allocation = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_allocate_file(', 'static int wls_capacity_token_leaf(');
    self::assertStringContainsString('if (result != 0 && created) (void)DeleteFileW(path);', $allocation);
    $tokens = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'static int wls_capacity_create_tokens(', 'static int wls_capacity_parse_token_leaf(');
    foreach (['wls_capacity_platform_direct_descriptor(', '&attributes', 'wls_capacity_platform_direct_apply(token, security)', 'wls_capacity_platform_direct_acl_exact(', 'if (created) (void)DeleteFileW(path);'] as $required) {
        self::assertStringContainsString($required, $tokens);
    }
    foreach (['wls_capacity_platform_reserve_staging_leaf(', 'wls_capacity_platform_staging_file_state(', 'MOVEFILE_WRITE_THROUGH', 'L"%ls.%u.staging"'] as $required) {
        self::assertStringContainsString($required, $this->capacity_NativeGatewayWindowsCapacity);
    }
    $command = $this->between_NativeGatewayWindowsCapacity($this->capacity_NativeGatewayWindowsCapacity, 'int wls_windows_capacity_command(', null);
    $rename = \strpos($command, 'MoveFileExW(held, releasing');
    $prepare = \strpos($command, 'wls_capacity_prepare_release(');
    self::assertIsInt($rename);
    self::assertIsInt($prepare);
    self::assertLessThan($prepare, $rename, 'The durable RELEASING namespace must exist before token deletion starts.');
    self::assertStringContainsString('wls_capacity_token_prefix_exact(', $this->capacity_NativeGatewayWindowsCapacity);
    foreach (['int replay_control_state = wls_capacity_detect_control_state(', 'int replay_platform_state = wls_capacity_platform_reserve_state(', 'replay_control_state == WLS_CAPACITY_CONTROL_REQUIRED', 'replay_control_state == WLS_CAPACITY_CONTROL_ABSENT'] as $replayFence) {
        self::assertStringContainsString($replayFence, $command);
    }
    self::assertStringContainsString("if (\$beginReplay['code'] === 0)", $this->fixture_NativeGatewayWindowsCapacity);
    self::assertStringContainsString('Malformed HELD primary tokens were downgraded to removable state.', $this->fixture_NativeGatewayWindowsCapacity);
}

private function read_NativeGatewayWindowsCapacity(string $file): string
{
    $contents = \file_get_contents($file);
    self::assertIsString($contents, 'Unable to read ' . $file);
    return $contents;
}

private function between_NativeGatewayWindowsCapacity(string $source, string $start, ?string $end): string
{
    $offset = \strpos($source, $start);
    self::assertIsInt($offset, 'Missing source marker: ' . $start);
    if ($end === null) {
        return \substr($source, $offset);
    }
    $limit = \strpos($source, $end, $offset + \strlen($start));
    self::assertIsInt($limit, 'Missing source marker: ' . $end);
    return \substr($source, $offset, $limit - $offset);
}

private string $broker_NativeGatewayWindowsDataPlaneSecurity;

private string $launcher_NativeGatewayWindowsDataPlaneSecurity;

private string $guardian_NativeGatewayWindowsDataPlaneSecurity;

private string $guardianRuntime_NativeGatewayWindowsDataPlaneSecurity;

private string $guardianRecovery_NativeGatewayWindowsDataPlaneSecurity;

private string $cmake_NativeGatewayWindowsDataPlaneSecurity;

private string $installer_NativeGatewayWindowsDataPlaneSecurity;

private string $windowsServiceTemplate_NativeGatewayWindowsDataPlaneSecurity;

private string $windowsRecoveryFixture_NativeGatewayWindowsDataPlaneSecurity;

private function setUp_NativeGatewayWindowsDataPlaneSecurity(): void
{
    parent::setUp();
    $serverRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Server';
    $root = $serverRoot . '/Service/Edge/Gateway/Native/windows/';
    $this->broker_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($root . 'wls_gateway_broker.c');
    $this->launcher_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($root . 'wls_gateway_launcher.c');
    $this->guardian_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($root . 'wls_gateway_guardian.inc');
    $this->guardianRuntime_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($root . 'wls_gateway_guardian_runtime.inc');
    $this->guardianRecovery_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($root . 'wls_gateway_guardian_recovery.inc');
    $this->cmake_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents(\dirname($root) . '/CMakeLists.txt');
    $this->installer_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($serverRoot . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php');
    $this->windowsServiceTemplate_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($serverRoot . '/env/gateway/windows-service.json.template');
    $this->windowsRecoveryFixture_NativeGatewayWindowsDataPlaneSecurity = (string) \file_get_contents($serverRoot . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php');
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testBrokerOwnsTheWindowsNginxPidNamespaceAcrossTestStartAndTerminalCleanup(): void
{
    $prepare = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_prepare_data_plane_runtime(', 'static void wls_win_close_public_sockets(');
    foreach (['wls_win_nginx_pid_directory_open(', 'wls_win_nginx_pid_directory_stable_acl_valid('] as $required) {
        self::assertStringContainsString($required, $prepare);
    }
    foreach (['wls_win_nginx_pid_leaf_test_cleanup(', 'wls_win_nginx_pid_leaf_seal(', 'wls_win_nginx_pid_leaf_terminal_cleanup(', 'FILE_ADD_FILE | FILE_DELETE_CHILD', 'L"nginx-pid"', 'wls_read_nginx_pid_leaf(', 'wls_win_nginx_pid_legacy_absent('] as $required) {
        self::assertStringContainsString($required, $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    }
    self::assertStringContainsString('L"nginx-pid\\\\nginx.pid"', $this->launcher_NativeGatewayWindowsDataPlaneSecurity);
    $retire = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_process_tree_retire_v2(', 'static int wls_handle_action_v2(');
    $death = strpos($retire, 'wls_win_wait_process_tree_exit(');
    $cleanup = strpos($retire, 'wls_win_nginx_pid_terminal_cleanup_after_exact_death(');
    self::assertNotFalse($death);
    self::assertNotFalse($cleanup);
    self::assertGreaterThan($death, $cleanup);
    self::assertLessThan(strpos($retire, 'wls_win_write_root_process_tree_retirement('), $cleanup);
    self::assertStringContainsString('wls_nginx_pid_namespace_restart_latched', $this->broker_NativeGatewayWindowsDataPlaneSecurity);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testPidSourcePublicationKeepsTheWindowsParentAclStable(): void
{
    foreach (['wls_win_nginx_pid_source_create(', 'wls_win_nginx_pid_precreate_canonical_source(', 'wls_win_nginx_pid_publish_from_source(', 'wls_win_nginx_pid_source_config_create(', 'wls_win_nginx_pid_source_config_exact(', 'wls_win_nginx_action_config_relative(', 'wls_win_nginx_pid_test_sources_prepare(', 'wls_win_nginx_pid_directory_no_staged_residue(', 'preheld source handle'] as $required) {
        self::assertStringContainsString($required, $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    }
    $lifecycle = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_lifecycle_action_v2(', 'typedef wchar_t wls_win_snapshot_name');
    self::assertStringNotContainsString('wls_win_nginx_pid_directory_window_acl_apply(', $lifecycle);
    self::assertStringContainsString('wls_win_nginx_pid_precreate_canonical_source(', $lifecycle);
    self::assertStringContainsString('before->config_path,', $lifecycle);
    self::assertStringContainsString('NGINX_START_SERVICE_TREE_RESTART_REQUIRED', $lifecycle);
    self::assertStringContainsString('wls_win_nginx_lifecycle_start_restart_required(', $lifecycle);
    self::assertStringContainsString('action_failed', $lifecycle);
    self::assertStringContainsString('committed', $lifecycle);
    self::assertStringContainsString('if (locked) ReleaseSRWLockExclusive', $lifecycle);
    $restartPolicy = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_lifecycle_start_restart_required(', 'static int wls_win_nginx_lifecycle_action_v2(');
    self::assertStringContainsString('action_failed && (start_tree_may_exist || latched != 0L)', $restartPolicy);
    self::assertStringContainsString('!committed && latched != 0L', $restartPolicy);
    $sourceAcl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_source_acl_apply(', 'static int wls_win_nginx_pid_source_valid(');
    self::assertStringContainsString('FILE_GENERIC_READ | FILE_GENERIC_WRITE', $sourceAcl);
    self::assertStringNotContainsString('DELETE |', $sourceAcl);
    self::assertStringNotContainsString('WRITE_DAC |', $sourceAcl);
    self::assertStringNotContainsString('WRITE_OWNER |', $sourceAcl);
    $publication = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_publish_from_source(', 'static int WLS_MAYBE_UNUSED wls_win_nginx_pid_leaf_test_cleanup(');
    self::assertStringContainsString('wcscmp(source_leaf, L"nginx.pid") != 0', $publication);
    $pidNamespace = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_directory_stable_acl_valid(', 'static int wls_win_nginx_pid_legacy_absent(');
    self::assertStringNotContainsString('FILE_ADD_FILE | FILE_DELETE_CHILD', $pidNamespace);
    $command = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_process_command_matches(', 'static int wls_same_file_identity(');
    self::assertStringContainsString('_wcsicmp(arguments[4], config) == 0', $command);
    self::assertStringNotContainsString('_wcsnicmp(arguments[4]', $command);
    $identity = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_identity(', 'static int wls_win_nginx_action_receipt(');
    self::assertStringContainsString('process_config = context->config_path;', $identity);
    self::assertStringNotContainsString('wls_nginx_shadow_config_', $identity);
    $sourceConfig = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_source_config_create(', 'static int wls_win_nginx_pid_source_config_remove(');
    self::assertStringContainsString('L"%ls\\\\nginx-pid\\\\%ls"', $sourceConfig);
    self::assertStringContainsString('wls_win_nginx_pid_source_config_exact(', $sourceConfig);
    self::assertStringContainsString('wls_win_nginx_action_config_relative(context, relative)', $sourceConfig);
    self::assertStringContainsString('home_root, relative, WLS_MAX_ATOMIC_CONFIG,', $sourceConfig);
    self::assertStringContainsString('wls_win_nginx_pid_source_config_remove(', $sourceConfig);
    self::assertStringContainsString('config_created && config_leaf != NULL', $sourceConfig);
    self::assertStringContainsString('CloseHandle(shadow);', $sourceConfig);
    self::assertStringContainsString('wls_win_nginx_pid_flush_directory(nginx_pid_directory)', $sourceConfig);
    self::assertStringContainsString('GetLastError() != ERROR_FILE_NOT_FOUND', $sourceConfig);
    $sourceRemove = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_source_remove_empty(', 'static int wls_win_nginx_pid_publish_from_source(', 2);
    $sourceDisposition = strpos($sourceRemove, 'SetFileInformationByHandle(');
    $sourceClose = strpos($sourceRemove, 'CloseHandle(source);', is_int($sourceDisposition) ? $sourceDisposition : 0);
    $sourceFlush = strpos($sourceRemove, 'wls_win_nginx_pid_flush_directory(directory)', is_int($sourceClose) ? $sourceClose : 0);
    $sourceAbsence = strpos($sourceRemove, 'GetLastError() != ERROR_FILE_NOT_FOUND', is_int($sourceFlush) ? $sourceFlush : 0);
    self::assertIsInt($sourceDisposition);
    self::assertIsInt($sourceClose);
    self::assertIsInt($sourceFlush);
    self::assertIsInt($sourceAbsence);
    self::assertGreaterThan($sourceDisposition, $sourceClose);
    self::assertGreaterThan($sourceClose, $sourceFlush);
    self::assertGreaterThan($sourceFlush, $sourceAbsence);
    self::assertStringNotContainsString('wls_win_neutral_tls_dispose_staged_leaf(', $sourceConfig);
    self::assertStringNotContainsString('L"%ls\\\\runtime\\\\conf\\\\%ls"', $sourceConfig);
    $sidConversion = strpos($sourceConfig, 'data_length = MultiByteToWideChar(');
    $sidZero = strpos($sourceConfig, 'ZeroMemory(data_sid, sizeof(data_sid));', $sidConversion);
    $sidSddl = strpos($sourceConfig, '_snwprintf_s(', $sidConversion);
    self::assertIsInt($sidConversion);
    self::assertIsInt($sidZero);
    self::assertIsInt($sidSddl);
    self::assertGreaterThan($sidSddl, $sidZero);
    self::assertStringContainsString('wls_win_nginx_pid_canonical_source_empty_exact(', $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    $spawn = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_spawn(', 'static int wls_win_nginx_attestation_matches(');
    self::assertStringContainsString('if (strcmp(operation, "TEST") == 0)', $spawn);
    self::assertStringContainsString('wcscmp(config_path, context->config_path) == 0', $spawn);
    self::assertStringContainsString('!wls_win_nginx_pid_source_config_leaf_valid(leaf)', $spawn);
    self::assertStringContainsString('} else if (wcscmp(config_path, context->config_path) != 0)', $spawn);
    $pidTest = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_test(', 'static int wls_win_nginx_pid_identity(');
    self::assertStringContainsString('wls_win_nginx_pid_leaf_sealed_valid(canonical, canonical_pid)', $pidTest);
    self::assertStringContainsString('wls_same_file_identity(', $pidTest);
    self::assertStringContainsString('wls_win_nginx_pid_test_sources_prepare(', $pidTest);
    $cleanup = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_pid_test(', 'static int wls_win_nginx_pid_identity(');
    self::assertStringContainsString('InterlockedExchange(&wls_nginx_pid_namespace_restart_latched, 1L);', $cleanup);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testDataPlaneUsesAProductRestrictingSidAndCannotEnterControlPipe(): void
{
    foreach (['WLS_DATA_PLANE_SERVICE_NAME_UPPER', 'L"WELINE-WLS-GATEWAY-V2-DATA-PLANE"', 'CreateRestrictedToken(', 'TokenRestrictedSids', '!wls_token_has_restricted_sid(restricted, restricting_sid)', 'EqualSid(controller_sid, data_plane_sid)'] as $required) {
        self::assertStringContainsString($required, $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    }
    $main = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'int wmain(', null);
    self::assertStringContainsString('L"D:P(D;;GA;;;%ls)', $main);
    self::assertStringContainsString('wls_data_plane_sid_text', $main);
    self::assertStringContainsString('wls_win_snapshot_recover_publish_temporaries_v2(home)', $main);
    self::assertStringContainsString('wls_win_snapshot_receipt_recover_temporaries_v2(home)', $main);
    $pipeWorker = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static DWORD WINAPI wls_channel_thread(', 'static int wls_write_fencing_file(');
    $peerCheck = \strpos($pipeWorker, 'wls_peer_sid(');
    $frameRead = \strpos($pipeWorker, 'wls_pipe_read_frame_alloc(');
    self::assertIsInt($peerCheck);
    self::assertIsInt($frameRead);
    self::assertLessThan($frameRead, $peerCheck);
    self::assertStringContainsString('restricted_sid_denied', $pipeWorker);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testNginxGetsOnlyValidatedPreboundSocketsInsideTheDataPlaneJob(): void
{
    $open = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static const char *wls_win_open_public_sockets(', 'static const char *wls_win_tcp_port_probe(');
    foreach (['SO_EXCLUSIVEADDRUSE', 'IPV6_V6ONLY', 'SOCK_STREAM', 'SOCK_DGRAM', '80U', '443U'] as $required) {
        self::assertStringContainsString($required, $open);
    }
    $spawn = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_spawn(', 'static int wls_win_nginx_attestation_matches(');
    foreach (['wls_win_public_socket_set_valid(', 'PROC_THREAD_ATTRIBUTE_HANDLE_LIST', 'CREATE_SUSPENDED', 'CreateProcessAsUserW(', 'AssignProcessToJobObject(wls_data_plane_job, process.hProcess)', 'wls_win_data_plane_process(process.hProcess)', 'ResumeThread(process.hThread)'] as $required) {
        self::assertStringContainsString($required, $spawn);
    }
    self::assertStringContainsString('L"NGINX"', $spawn . $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    self::assertLessThan(\strpos($spawn, 'ResumeThread(process.hThread)'), \strpos($spawn, 'AssignProcessToJobObject('));
    self::assertStringNotContainsString('CreateProcessW(', $spawn);
    $adoption = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static HANDLE wls_open_verified_job_nginx(', 'static int wls_force_terminate_process(');
    self::assertStringContainsString('process, supervision_job, &belongs_to_supervision', $adoption);
    self::assertStringContainsString('IsProcessInJob(process, data_plane_job', $adoption);
    self::assertStringContainsString('wls_verify_slot_durable_state_contract_v2(', $adoption);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testNginxTestUsesSealedCandidateAclAndIndependentLkgSourceClosure(): void
{
    $dispatcher = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_handle_action_v2(', 'static const char *wls_json_value(');
    self::assertStringContainsString('"NGINX_TEST"', $dispatcher);
    self::assertStringContainsString('wls_win_nginx_test_action_v2(', $dispatcher);
    $test = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_test_action_v2(', 'static int wls_win_nginx_lifecycle_action_v2(', occurrence: 2);
    foreach (['wls_win_nginx_test_open_regular(', 'wls_win_nginx_pid_test(', '&before_context, sockets, deadline, nginx_pid_directory', 'wls_win_nginx_test_candidate_restore(&config_before)', 'wls_win_nginx_test_observation_same(', 'WLS-ACTION/2\tOK\tNGINX_TEST', 'NGINX_TEST_RESTORE_FAILED'] as $required) {
        self::assertStringContainsString($required, $test);
    }
    $acl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_test_candidate_acl(', 'static int wls_win_nginx_test_open_regular(');
    self::assertStringContainsString('L"O:SYD:P(A;;FA;;;SY)(A;;GRGWSD;;;%ls)(A;;GR;;;%ls)"', $acl);
    self::assertStringContainsString('wls_data_plane_token', $acl);
    self::assertStringContainsString('WRITE_DAC', $acl);
    self::assertStringContainsString('WRITE_OWNER', $acl);
    $lkg = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_lkg_context_load(', 'static int wls_win_nginx_test_action_v2(');
    foreach (['candidate_config_digest', 'source_config_digest', 'source_lkg_manifest_digest', 'wls_win_nginx_lkg_certificate_closure_v2(', 'strcmp(context->config.digest, source_config_digest)'] as $required) {
        self::assertStringContainsString($required, $lkg);
    }
    self::assertStringNotContainsString('strcmp(source_config_digest, candidate_digest)', $lkg);
    self::assertStringNotContainsString('strcmp(candidate_digest, source_config_digest)', $lkg);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testSnapshotAndReceiptPublicationRecoverOnlyExactDigestBoundTemporaries(): void
{
    $snapshotName = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_publish_temp_name(', 'static int wls_win_snapshot_rename_directory(');
    self::assertStringContainsString('L".candidate-"', $snapshotName);
    self::assertStringContainsString('cursor[16] == L\'\0\'', $snapshotName);
    $snapshotRecovery = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_recover_publish_temporaries_v2(', 'static int wls_win_snapshot_publish_candidate(');
    foreach (['wls_win_snapshot_publish_temp_name(', 'wls_win_snapshot_manifest_bound_to_digest(', 'wls_win_snapshot_observe_leaf(', 'wls_win_snapshot_observation_equivalent(', 'wls_win_snapshot_rename_directory(', 'snapshot_file_ids_digest'] as $required) {
        self::assertStringContainsString($required, $snapshotRecovery);
    }
    self::assertStringContainsString('previous_digest[0] != \'\0\'', $snapshotRecovery);
    self::assertStringContainsString('if (exact != 1) continue;', $snapshotRecovery);
    $snapshotPublish = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_publish_candidate(', 'static int wls_win_snapshot_hash_enrolled_source(');
    self::assertStringContainsString('L".candidate-%hs-%lu-%hs"', $snapshotPublish);
    self::assertStringContainsString('wls_win_snapshot_observe_leaf(', $snapshotPublish);
    $key = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_receipt_key(', 'static int wls_win_snapshot_receipt_mac_v2(');
    foreach (['L".snapshot-receipt.key.candidate.%lu.%hs"', 'L"O:SYD:P(A;;GA;;;SY)"', 'CREATE_NEW', 'wls_win_snapshot_receipt_key_read_handle(', 'trust, L"snapshot-receipts"', 'home_root, L"snapshots-v2"', 'wls_same_file_identity(', 'exact_candidates > 1U'] as $required) {
        self::assertStringContainsString($required, $key);
    }
    $namespaceFence = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_receipt_key_namespace_empty(', 'static int wls_win_snapshot_receipt_key(');
    foreach (['wls_nt_open_child(', 'wls_handle_is_reparse(', 'wls_win_snapshot_owner_system(', 'wls_win_directory_names(', 'name_count == 0U', 'ERROR_FILE_NOT_FOUND', 'ERROR_PATH_NOT_FOUND'] as $required) {
        self::assertStringContainsString($required, $namespaceFence);
    }
    $receiptFence = \strpos($key, 'trust, L"snapshot-receipts"');
    $snapshotFence = \strpos($key, 'home_root, L"snapshots-v2"');
    $keyGeneration = \strpos($key, 'BCryptGenRandom(');
    self::assertIsInt($receiptFence);
    self::assertIsInt($snapshotFence);
    self::assertIsInt($keyGeneration);
    self::assertLessThan($keyGeneration, $receiptFence);
    self::assertLessThan($keyGeneration, $snapshotFence);
    $receiptRecovery = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_receipt_recover_temporaries_v2(', 'static int wls_win_snapshot_receipt_publish_v2(');
    foreach (['wls_win_snapshot_receipt_temp_name(', 'wls_win_snapshot_receipt_temp_content_valid(', 'wls_win_snapshot_receipt_read_handle(', 'wls_same_file_identity(', 'wls_win_snapshot_rename_directory('] as $required) {
        self::assertStringContainsString($required, $receiptRecovery);
    }
    self::assertStringContainsString('previous_digest[0] != \'\0\'', $receiptRecovery);
    $receiptPublish = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_receipt_publish_v2(', 'static int wls_win_snapshot_receipt_read_v2(');
    self::assertStringContainsString('L".receipt-%hs-%lu-%ls.tmp"', $receiptPublish);
    self::assertStringContainsString('L"O:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)"', $receiptPublish);
    self::assertStringContainsString('wls_win_snapshot_receipt_temp_content_valid(', $receiptPublish);
    $seal = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_snapshot_seal_action_v2(', 'static int wls_win_snapshot_attest_action_v2(', occurrence: 2);
    self::assertStringContainsString('wls_win_snapshot_publish_candidate(', $seal);
    self::assertStringContainsString('wls_win_snapshot_receipt_publish_v2(', $seal);
    $keyPreparation = \strpos($seal, 'wls_win_snapshot_receipt_key(');
    $snapshotPublication = \strpos($seal, 'wls_win_snapshot_publish_candidate(');
    self::assertIsInt($keyPreparation);
    self::assertIsInt($snapshotPublication);
    self::assertLessThan($snapshotPublication, $keyPreparation);
    self::assertStringContainsString('wls_win_snapshot_receipt_reply_v2(', $seal);
    self::assertStringNotContainsString('WINDOWS_SNAPSHOT_RECEIPT_UNSUPPORTED', $seal);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherRequiresExactSignedSchemaTwoRuntimeClosure(): void
{
    $contract = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_exact_durable_contract_v2(', 'static int wls_launcher_file_identity_equal(');
    foreach (['contract->count == 8U', 'security_ledger_read_schema', 'security_ledger_write_schema', 'snapshot_receipt_read_schema', 'snapshot_receipt_write_schema', 'snapshot_namespace', 'snapshots-v2', 'nonce_wal_schema', 'nginx_test_schema'] as $required) {
        self::assertStringContainsString($required, $contract);
    }
    $verify = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_verify_slot_durable_state_contract_v2_at_path_with_key(', 'static int wls_verify_slot_durable_state_contract_v2_with_key(');
    foreach (['"app/controller.php", 420ULL', '"bin/nginx.exe", 493ULL', '"bin/php.exe", 493ULL', '"bin/wls-gateway-broker.exe", 493ULL', '"bin/wls-gateway-launcher.exe", 493ULL', '"release/manifest.json", 384ULL', '"release/manifest.sig", 384ULL', 'wls_verify_release_bytes(', 'stable_launcher_rollback_target_proof', 'wls_launcher_json_generation(', 'wls_launcher_file_observation_equal(', 'wls_launcher_file_identity_equal('] as $required) {
        self::assertStringContainsString($required, $verify);
    }
    self::assertMatchesRegularExpression('/sodium_memcmp\(\s*declared_generation,\s*computed_generation,\s*64U\s*\)/', $verify);
    $observe = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_file_observe(', 'static int wls_launcher_file_observation_equal(');
    foreach (['FILE_FLAG_OPEN_REPARSE_POINT', 'before.nNumberOfLinks != 1U', 'after.nNumberOfLinks != 1U', 'wls_launcher_file_identity_equal(&before, &after)'] as $required) {
        self::assertStringContainsString($required, $observe);
    }
    $launch = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launch(', 'static VOID WINAPI wls_service_main(');
    $proof = \strpos($launch, 'wls_verify_slot_durable_state_contract_v2(');
    $brokerPath = \strpos($launch, 'wls_join(broker, WLS_PATH_CHARS, slot');
    self::assertIsInt($proof);
    self::assertIsInt($brokerPath);
    self::assertLessThan($brokerPath, $proof);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testRollbackTargetProofIsLazyAndSelfTestExercisesPositiveAndNegativeSlots(): void
{
    $reconcile = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_reconcile_upgrade_locked(', 'static int wls_reconcile_upgrade(');
    self::assertStringContainsString('int rollback_target_verified = 0;', $reconcile);
    self::assertStringContainsString('wls_require_rollback_slot_contract_v2(', $reconcile);
    $firstProof = \strpos($reconcile, 'wls_require_rollback_slot_contract_v2(');
    $firstRollbackWrite = \strpos($reconcile, 'home, &upgrade, boot_id, "ROLLBACK_PENDING"');
    self::assertIsInt($firstProof);
    self::assertIsInt($firstRollbackWrite);
    self::assertLessThan($firstRollbackWrite, $firstProof);
    $selfTest = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_rollback_target_proof_self_test(void)', 'static const wchar_t *wls_argument(');
    foreach (['GetTempPathW(', 'WelineWlsLauncherProof-%lu-%hs', 'CreateDirectoryW(home, &home_security)', 'crypto_sign_keypair(', 'crypto_sign_detached(', 'wls_verify_slot_durable_state_contract_v2_with_key(', 'WLS_BUILD_INSTALLED("true", 0)', 'WLS_BUILD_INSTALLED("false", 0)', 'WLS_BUILD_INSTALLED("true", 1)', 'wls_launcher_proof_self_test_delete_file(', 'wls_launcher_proof_self_test_remove_directory(home)', 'if (cleanup_failed) result = 1;'] as $required) {
        self::assertStringContainsString($required, $selfTest);
    }
    self::assertStringContainsString('stable_launcher_rollback_target_proof', $selfTest);
    self::assertStringContainsString('0000000000000000000000000000000000000000000000000000000000000000', $selfTest);
    self::assertStringNotContainsString('system(', $selfTest);
    self::assertStringNotContainsString('ShellExecute', $selfTest);
    $selfTestSecurity = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_proof_self_test_security(', 'static int wls_launcher_proof_self_test_digest(');
    self::assertStringContainsString('OpenProcessToken(', $selfTestSecurity);
    self::assertStringContainsString('TokenUser', $selfTestSecurity);
    self::assertStringContainsString('L"D:P(A;OICI;FA;;;%ls)"', $selfTestSecurity);
    $main = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'int wmain(', null);
    self::assertStringContainsString('L"--rollback-target-proof-self-test"', $main);
    self::assertStringContainsString('wls_launcher_rollback_target_proof_self_test()', $main);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherAndBrokerRequireDurableExactDataPlanePathAcls(): void
{
    foreach (['S-1-5-80-3070340479-3168417268-2770794561-992406300-110075626', 'S-1-5-80-3611316956-1833621424-61377994-3153356469-2496947245'] as $serviceSid) {
        self::assertStringContainsString($serviceSid, $this->launcher_NativeGatewayWindowsDataPlaneSecurity);
        self::assertStringContainsString($serviceSid, $this->installer_NativeGatewayWindowsDataPlaneSecurity);
    }
    $acl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_slot_acl_valid_profile(', 'static int wls_launcher_slot_acl_valid_mode(');
    foreach (['system_owner ? (PSID)system_buffer', '(control & SE_DACL_PROTECTED) == 0U', 'dacl->AclRevision != ACL_REVISION', '+ (data_plane_acl ? 1U : 0U)', 'EqualSid(ace_sid, data_plane_sid)', 'ace->Mask != data_plane_mask', 'data_plane_count == (data_plane_acl ? 1U : 0U)'] as $required) {
        self::assertStringContainsString($required, $acl);
    }
    self::assertStringContainsString('static int wls_launcher_slot_data_plane_directory_acl_valid(', $this->launcher_NativeGatewayWindowsDataPlaneSecurity);
    $directoryAcl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_data_plane_directory_acl_valid(', 'static int wls_launcher_slot_data_plane_directory_acl_valid(');
    foreach (['object,', '1,', 'controller_sid,', 'data_plane_sid,', 'system_owner,', 'FILE_TRAVERSE'] as $required) {
        self::assertStringContainsString($required, $directoryAcl);
    }
    $digest = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_file_digest(', 'static int wls_utf8_to_wide(');
    self::assertSame(2, \substr_count($digest, 'wls_launcher_nginx_acl_valid('));
    $component = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_verify_component_v2(', 'static int wls_verify_slot_durable_state_contract_v2_at_path_with_key(');
    self::assertStringContainsString('controller_acl && strcmp(relative, "bin/nginx.exe") == 0', $component);
    $install = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->installer_NativeGatewayWindowsDataPlaneSecurity, 'private function applyWindowsNginxExecutableAcl(', 'private function applyWindowsAcl(');
    foreach (['applyExactPathSddl(', '(A;;FA;;;S-1-5-18)', '(A;;FA;;;S-1-5-32-544)', '0x1200a9', '0x20', 'WINDOWS_CONTROLLER_SERVICE_SID', 'WINDOWS_DATA_PLANE_SERVICE_SID', 'four-ACE ACL verification failed'] as $required) {
        self::assertStringContainsString($required, $install);
    }
    self::assertStringContainsString('$this->applyWindowsNginxExecutableAcl(', $this->installer_NativeGatewayWindowsDataPlaneSecurity);
    self::assertStringContainsString('$this->applyWindowsDataPlaneTraversalAcl(', $this->installer_NativeGatewayWindowsDataPlaneSecurity);
    $runtime = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_service_path_acl_valid(', 'static void wls_win_close_public_sockets(');
    foreach (['information.AceCount != 3U + (data_plane_acl ? 1U : 0U)', 'system_owner ? (PSID)system_buffer', 'EqualSid(ace_sid, controller_sid)', 'EqualSid(ace_sid, wls_data_plane_sid)', 'wls_win_verify_data_plane_binary_relative(', 'wls_win_verify_data_plane_traversal_relative(', 'home_root, binary_relative', 'home_root, slot_relative, 0', 'home_root, bin_relative, 0'] as $required) {
        self::assertStringContainsString($required, $runtime);
    }
    self::assertStringNotContainsString('home_root, binary_relative, 0, read_access', $runtime);
    $selfTest = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_launcher_rollback_target_proof_self_test(void)', 'static const wchar_t *wls_argument(');
    self::assertStringContainsString('component_paths[1], 0, slot_service_sid, 1', $selfTest);
    self::assertStringContainsString('component_paths[1], 0, slot_service_sid', $selfTest);
    self::assertStringContainsString('bin, 1, slot_service_sid', $selfTest);
    self::assertStringContainsString('bin, slot_service_sid, 0', $selfTest);
    foreach (['service-test', '--profile=default', 'wlsApplyExactGatewayReadAcl', 'WLS_WINDOWS_TEST_SERVICE'] as $removedLegacyContract) {
        self::assertStringNotContainsString($removedLegacyContract, $this->windowsRecoveryFixture_NativeGatewayWindowsDataPlaneSecurity);
    }
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testLauncherOwnsAStableNestedJobAcrossBrokerRestarts(): void
{
    $create = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static HANDLE wls_create_data_plane_job(', 'static int wls_run_supervisor(');
    self::assertStringContainsString('Global\\\\WelineWlsGatewayV2DataPlane-', $create);
    self::assertStringContainsString('JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE', $create);
    self::assertStringContainsString('JOB_OBJECT_LIMIT_DIE_ON_UNHANDLED_EXCEPTION', $create);
    $supervisor = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_run_supervisor(', 'static VOID WINAPI wls_service_main(');
    self::assertStringContainsString('wls_create_data_plane_job(', $supervisor);
    self::assertStringContainsString('data_plane_job_name', $supervisor);
    self::assertLessThan(\strrpos($supervisor, 'CloseHandle(data_plane_job);'), \strpos($supervisor, 'wls_launch('));
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testGuardianIsTheOnlyScmEntrypointAndBothVariantsRemainWarningClean(): void
{
    $template = \json_decode($this->windowsServiceTemplate_NativeGatewayWindowsDataPlaneSecurity, true, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($template);
    self::assertSame(['service', 'display_name', 'account', 'start', 'failure_restart_seconds', 'repeat_last_failure_action', 'guardian', 'arguments'], \array_keys($template));
    self::assertSame('{{GUARDIAN}}', $template['guardian']);
    self::assertSame(['--service', '--home={{HOME}}', '--run={{RUN_DIR}}'], $template['arguments']);
    $configuration = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->installer_NativeGatewayWindowsDataPlaneSecurity, 'private function configureWindowsServiceDefinition(', 'private function enableWindowsServiceDefinition(');
    self::assertStringContainsString('$this->paths->guardianFile()', $configuration);
    self::assertStringContainsString('" --service', $configuration);
    self::assertStringNotContainsString('launcherFile()', $configuration);
    self::assertStringNotContainsString('--profile', $configuration);
    foreach (["add_executable(\n        wls-gateway-guardian", 'WLS_GUARDIAN_EXECUTABLE=1', 'RUNTIME DESTINATION guardian/v1', 'wls-gateway-launcher PRIVATE /W4 /WX /sdl /wd4505', 'wls-gateway-guardian PRIVATE /W4 /WX /sdl /wd4505'] as $required) {
        self::assertStringContainsString($required, $this->cmake_NativeGatewayWindowsDataPlaneSecurity);
    }
    self::assertSame(2, \substr_count($this->cmake_NativeGatewayWindowsDataPlaneSecurity, '-Wno-unused-function'));
    $main = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'int wmain(', null);
    self::assertStringContainsString('return wls_guardian_child_command(argc, argv);', $main);
    self::assertStringContainsString('if (service_mode) return 64;', $main);
    self::assertStringContainsString('if (!service_mode || argc != 4) return 64;', $main);
    self::assertStringContainsString('wls_guardian_fixed_runtime_paths(', $main);
    self::assertStringContainsString('wls_guardian_scm_definition_valid(', $this->guardian_NativeGatewayWindowsDataPlaneSecurity);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testGuardianRequiresPreshutdownAndAnExactProtectedScmDacl(): void
{
    $definition = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardian_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_scm_definition_valid(', 'static int wls_guardian_core_self_test(');
    foreach (['SERVICE_CONFIG_PRESHUTDOWN_INFO', 'SERVICE_PRESHUTDOWN_INFO', 'dwPreshutdownTimeout != 330000U', 'wls_guardian_scm_service_dacl_exact('] as $required) {
        self::assertStringContainsString($required, $definition);
    }
    self::assertStringContainsString('QueryServiceObjectSecurity(', $this->guardian_NativeGatewayWindowsDataPlaneSecurity);
    $dacl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardian_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_scm_service_dacl_exact(', 'static int wls_guardian_scm_definition_valid(');
    foreach (['SE_DACL_PROTECTED', 'ACCESS_ALLOWED_ACE_TYPE', 'ace->Header.AceFlags != 0U', 'SERVICE_ALL_ACCESS', 'WinLocalSystemSid', 'WinBuiltinAdministratorsSid', 'system_count != 1U', 'administrators_count != 1U', 'information.AceCount != 2U'] as $required) {
        self::assertStringContainsString($required, $dacl);
    }
    $selfTest = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardian_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_scm_service_dacl_self_test(', 'static int wls_guardian_core_self_test(');
    foreach (['D:P(A;;0xF01FF;;;SY)(A;;0xF01FF;;;BA)', '(A;;GR;;;BU)', '(A;ID;0xF01FF;;;SY)', '(A;;GR;;;BA)'] as $negativeFixture) {
        self::assertStringContainsString($negativeFixture, $selfTest);
    }
    $service = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static DWORD WINAPI wls_service_control(', 'static HANDLE wls_create_supervision_job(');
    self::assertStringContainsString('SERVICE_CONTROL_PRESHUTDOWN', $service);
    self::assertStringContainsString('wls_service_stop_intent_persisted()', $service);
    self::assertStringContainsString('WLS_GUARDIAN_DRAIN_MILLISECONDS', $this->guardianRuntime_NativeGatewayWindowsDataPlaneSecurity);
    self::assertStringContainsString('SERVICE_ACCEPT_PRESHUTDOWN', $this->launcher_NativeGatewayWindowsDataPlaneSecurity);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testPreshutdownPersistenceFailureCannotRestartTheDataPlaneInTheSameBoot(): void
{
    $intent = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardian_NativeGatewayWindowsDataPlaneSecurity, 'static const wchar_t wls_service_stop_intent_registry_path[]', 'static HANDLE wls_guardian_singleton(');
    foreach (['RegCreateKeyExW(', 'REG_OPTION_VOLATILE', 'RegFlushKey(', 'WLS-SERVICE-PRESHUTDOWN/1', 'host_boot_id', 'D:P(A;;KA;;;SY)(A;;KA;;;BA)', 'wls_guardian_service_stop_intent_volatile_active('] as $required) {
        self::assertStringContainsString($required, $intent);
    }
    $service = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static DWORD WINAPI wls_service_control(', 'static HANDLE wls_create_supervision_job(');
    self::assertStringContainsString('InterlockedExchange(&wls_service_preshutdown_unsealed, 1)', $service);
    $serviceMain = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->launcher_NativeGatewayWindowsDataPlaneSecurity, 'static VOID WINAPI wls_service_main(', 'struct wls_launcher_proof_self_test_component');
    self::assertStringContainsString('wls_service_preshutdown_unsealed', $serviceMain);
    self::assertStringContainsString('Sleep(10000U)', $serviceMain);
    $guardianRun = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardianRuntime_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_service_run(', null);
    $active = \strpos($guardianRun, 'wls_guardian_service_stop_intent_active(run_directory)');
    $prepare = \strpos($guardianRun, 'wls_guardian_runtime_prepare_child(');
    self::assertIsInt($active);
    self::assertIsInt($prepare);
    self::assertLessThan($prepare, $active);
    self::assertStringContainsString('now_monotonic - last_checkpoint >= 10000ULL', $this->launcher_NativeGatewayWindowsDataPlaneSecurity);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testRecoveryScmMutationIsSealedAndTransactional(): void
{
    $apply = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardianRecovery_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_recovery_platform_apply_scm(', 'static int wls_guardian_recovery_runtime_path_binding(');
    foreach (['wls_guardian_recovery_scm_snapshot_capture(', 'SERVICE_CONFIG_PRESHUTDOWN_INFO', 'dwPreshutdownTimeout = 330000U', 'PROTECTED_DACL_SECURITY_INFORMATION', 'SetSecurityInfo(', 'wls_guardian_scm_definition_valid(', 'SERVICE_DEMAND_START', 'wls_guardian_recovery_scm_delete_verified(', 'wls_guardian_recovery_scm_snapshot_restore(', 'wls_guardian_recovery_scm_snapshot_matches(', 'wls_guardian_recovery_scm_force_safe_start(', 'rollback_hard_failure = 1'] as $required) {
        self::assertStringContainsString($required, $apply);
    }
    foreach (['if (!DeleteService(*service)) return 1;', 'ERROR_SERVICE_MARKED_FOR_DELETE', 'SERVICE_CHANGE_CONFIG | SERVICE_QUERY_CONFIG'] as $required) {
        self::assertStringContainsString($required, $this->guardianRecovery_NativeGatewayWindowsDataPlaneSecurity);
    }
    self::assertStringNotContainsString('(void)DeleteService(', $apply);
    self::assertStringNotContainsString('(void)wls_guardian_recovery_scm_snapshot_restore(', $apply);
    self::assertStringContainsString('int readback_failed = wls_guardian_recovery_scm_snapshot_matches(', $apply);
    self::assertStringContainsString('result = 2;', $apply);
    self::assertLessThan(\strpos($apply, 'wls_guardian_scm_definition_valid('), \strpos($apply, 'SERVICE_AUTO_START'), 'The recovery path must keep a new SCM object non-automatic until sealing succeeds.');
    self::assertLessThan(\strpos($apply, 'result = 0;'), \strrpos($apply, 'wls_guardian_scm_definition_valid('), 'Strict SCM read-back must complete before recovery publishes success.');
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testContinuityPublisherPersistsItsMonotonicFloorInsideTheHeadLock(): void
{
    $floor = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_existing_continuity_floor(', 'static int wls_guardian_unique_issued_monotonic(');
    foreach (['FILE_FLAG_OPEN_REPARSE_POINT', 'standard.NumberOfLinks != 1U', 'wls_win_controller_receipt_acl_valid(file)', 'wls_same_file_identity(&before, &after)', 'wls_hmac_sha256(', 'strcmp(host_id, expected_host_id) != 0', 'strcmp(boot_id, expected_boot_id) == 0 ? parsed : 0ULL'] as $required) {
        self::assertStringContainsString($required, $floor);
    }
    $publishStart = \strrpos($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_health_publish(');
    self::assertIsInt($publishStart);
    $publish = \substr($this->broker_NativeGatewayWindowsDataPlaneSecurity, $publishStart);
    $lock = \strpos($publish, 'wls_guardian_transition_lock_acquire(');
    $key = \strpos($publish, 'wls_read_hex_authority_file(token_path');
    $durable = \strpos($publish, 'wls_guardian_existing_continuity_floor(');
    $issued = \strpos($publish, 'wls_guardian_unique_issued_monotonic(');
    $continuityWrite = \strpos($publish, "wls_atomic_sddl_bytes(\n            continuity_path");
    $unlock = \strpos($publish, 'wls_guardian_transition_lock_release(');
    foreach ([$lock, $key, $durable, $issued, $continuityWrite, $unlock] as $offset) {
        self::assertIsInt($offset);
    }
    self::assertTrue($lock < $key);
    self::assertTrue($key < $durable);
    self::assertTrue($durable < $issued);
    self::assertTrue($issued < $continuityWrite);
    self::assertTrue($continuityWrite < $unlock);
    self::assertStringContainsString('(void)DeleteFileW(continuity_path);', $publish);
    self::assertStringContainsString('publication_checked_ms - issued_monotonic_ms', $publish);
    $unique = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_unique_issued_monotonic(', 'static int wls_guardian_process_system_service(');
    self::assertStringContainsString('durable_floor < context->last_guardian_issued_monotonic_ms', $unique);
    self::assertStringContainsString('&context, observed, first, &second', $unique);
    $accept = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->guardianRuntime_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_guardian_runtime_sample_accept(', 'static int wls_guardian_runtime_self_test(');
    $copy = \strpos($accept, 'next = *observation;');
    $commit = \strpos($accept, '*observation = next;');
    self::assertIsInt($copy);
    self::assertIsInt($commit);
    self::assertTrue($copy < $commit);
    self::assertStringContainsString("sample->issued_monotonic_ms\n                    == next.last_issued_monotonic_ms", $accept);
    self::assertStringContainsString('sample->raw_sha256', $accept);
}

private function assert_NativeGatewayWindowsDataPlaneSecurity_testNeutralTlsServingPairIsBrokerSealedAndRevalidatedBeforeEverySpawn(): void
{
    $servingRootAcl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_acl_valid(', 'static int wls_win_neutral_tls_apply_acl(');
    foreach (['administrators_buffer', 'administrators_count', 'information.AceCount != 3U', 'FILE_ALL_ACCESS', 'FILE_TRAVERSE', 'FILE_GENERIC_READ'] as $required) {
        self::assertStringContainsString($required, $servingRootAcl);
    }
    $servingLeafApply = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_apply_acl(', 'static HANDLE wls_win_neutral_tls_root_open(');
    self::assertStringContainsString('L"O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;0x120089;;;%ls)"', $servingLeafApply);
    self::assertStringNotContainsString('L"O:SYD:P(A;;FA;;;SY)(A;;FA;;;BA)(A;;GR;;;%ls)"', $servingLeafApply);
    $stateParentAcl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_state_parent_acl_valid(', 'static int wls_win_neutral_tls_state_source_acl_valid(');
    foreach (['administrators_buffer', 'SE_DACL_PROTECTED', 'OBJECT_INHERIT_ACE | CONTAINER_INHERIT_ACE', 'information.AceCount != 3U', '0x001301BFUL'] as $required) {
        self::assertStringContainsString($required, $stateParentAcl);
    }
    $publication = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_publish_and_validate(', 'static int wls_win_prepare_data_plane_runtime(');
    foreach (['wls_win_neutral_tls_publish_from_open_sources(', 'wls_win_neutral_tls_consumer_validate(', 'wls_win_neutral_tls_state_parent_acl_valid(state)'] as $required) {
        self::assertStringContainsString($required, $publication);
    }
    self::assertStringNotContainsString('wls_win_controller_receipt_acl_valid(state)', $publication);
    $source = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_source_open(', 'static int wls_win_neutral_tls_receipt_canonical(');
    foreach (['int certificate_source', 'wls_win_neutral_tls_source_certificate_acl_valid(', 'wls_win_neutral_tls_source_key_acl_valid(', 'wls_handle_is_reparse(', 'NumberOfLinks != 1U'] as $required) {
        self::assertStringContainsString($required, $source);
    }
    $sourceAcl = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_state_source_acl_valid(', 'static int wls_win_neutral_tls_source_certificate_acl_valid(');
    foreach (['INHERITED_ACE', 'SE_DACL_AUTO_INHERITED', 'SE_DACL_PROTECTED', 'administrators_buffer', 'controller_sid', 'information.AceCount != 3U', 'ace->Header.AceFlags != INHERITED_ACE', 'FILE_ALL_ACCESS', '0x001301BFUL'] as $required) {
        self::assertStringContainsString($required, $sourceAcl);
    }
    $sourceValidators = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_source_certificate_acl_valid(', 'static int wls_win_neutral_tls_source_open(');
    self::assertStringNotContainsString('wls_win_controller_receipt_acl_valid(', $sourceValidators);
    self::assertSame(2, \substr_count($sourceValidators, 'wls_win_neutral_tls_state_source_acl_valid('));
    foreach (['L"state"', 'L"neutral-tls"', 'L"neutral-cert.pem"', 'L"neutral-key.pem"', 'L"neutral-tls.receipt"', 'wls_win_snapshot_receipt_mac_v2(', 'FILE_FLAG_OPEN_REPARSE_POINT', 'wls_same_file_identity(', 'wls_win_read_digest_bounded(', 'wls_win_snapshot_rename_directory('] as $required) {
        self::assertStringContainsString($required, $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    }
    $prepare = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_prepare_data_plane_runtime(', 'static void wls_win_close_public_sockets(');
    self::assertStringNotContainsString('L"state"', $prepare);
    self::assertStringNotContainsString('L"state\neutral-cert.pem"', $prepare);
    self::assertStringNotContainsString('L"state\neutral-key.pem"', $prepare);
    $consumer = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_neutral_tls_consumer_validate(', 'static int wls_win_neutral_tls_publish_and_validate(');
    foreach (['L"neutral-cert.pem"', 'L"neutral-key.pem"', 'L"neutral-tls.receipt"', 'wls_win_neutral_tls_read_leaf('] as $required) {
        self::assertStringContainsString($required, $consumer);
    }
    foreach (['wls_access_check_handle(', 'GENERIC_WRITE', 'DELETE', 'WRITE_DAC', 'WRITE_OWNER'] as $required) {
        self::assertStringContainsString($required, $this->broker_NativeGatewayWindowsDataPlaneSecurity);
    }
    $test = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_test_action_v2(', 'static int wls_win_nginx_lifecycle_action_v2(');
    self::assertStringContainsString('wls_win_neutral_tls_publish_and_validate(', $test);
    self::assertLessThan(\strpos($test, 'wls_win_nginx_spawn('), \strpos($test, 'wls_win_neutral_tls_consumer_validate('));
    $lifecycle = $this->between_NativeGatewayWindowsDataPlaneSecurity($this->broker_NativeGatewayWindowsDataPlaneSecurity, 'static int wls_win_nginx_lifecycle_action_v2(', null);
    self::assertStringContainsString('wls_win_neutral_tls_publish_and_validate(', $lifecycle);
    self::assertGreaterThanOrEqual(3, \substr_count($lifecycle, 'wls_win_neutral_tls_consumer_validate('));
}

private function between_NativeGatewayWindowsDataPlaneSecurity(string $source, string $startNeedle, ?string $endNeedle, int $occurrence = 1): string
{
    $offset = 0;
    $start = false;
    for ($index = 0; $index < $occurrence; ++$index) {
        $start = \strpos($source, $startNeedle, $offset);
        self::assertIsInt($start, 'Missing source marker: ' . $startNeedle);
        $offset = $start + \strlen($startNeedle);
    }
    if ($endNeedle === null) {
        return \substr($source, $start);
    }
    $end = \strpos($source, $endNeedle, $offset);
    self::assertIsInt($end, 'Missing source marker: ' . $endNeedle);
    return \substr($source, $start, $end - $start);
}

private function assert_PureWlsServingManifestRecovery_testMultiDomainRecoverySelectsTheRequestedHostInsteadOfTheFirstRoute(): void
{
    $routes = [['domain' => 'first.example.test', 'certificate_generation' => 41], ['domain' => 'requested.example.test', 'certificate_generation' => 42]];
    $method = new \ReflectionMethod(\Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::class, 'selectActiveRouteForRequestedHost');
    self::assertSame($routes[1], $method->invoke(null, $routes, 'REQUESTED.EXAMPLE.TEST.'));
    self::assertNull($method->invoke(null, $routes, 'missing.example.test'));
    $execute = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'execute');
    self::assertMatchesRegularExpression('/NativeServingManifestStartupRecovery::fromEndpoint\(.*?resolveCertificateHost\(/s', $execute);
}

private function assert_PureWlsServingManifestRecovery_testTerminalEndpointCanRecoverItsImmutablePreviousMasterFence(): void
{
    $endpoint = ['master_pid' => 0, 'master_epoch' => 9, 'lifecycle_state' => 'failed', 'gateway' => ['instance_id' => 'shop', 'project_uuid' => '123e4567-e89b-42d3-a456-426614174000', 'instance_generation' => 12, 'launch_id' => \str_repeat('a', 32), 'backend_identity_schema' => 'wls-backend-listener-identity/2']];
    $payload = ['instance_id' => 'shop', 'project_uuid' => '123e4567-e89b-42d3-a456-426614174000', 'instance_generation' => 12, 'master_pid' => 3210, 'master_epoch' => 9, 'launch_id' => \str_repeat('a', 32)];
    $method = new \ReflectionMethod(\Weline\Server\Service\Edge\NativeServingManifestStartupRecovery::class, 'terminalRecoveryFence');
    self::assertSame(['master_pid' => 3210, 'master_epoch' => 9, 'launch_id' => \str_repeat('a', 32), 'instance_generation' => 12], $method->invoke(null, $endpoint, $endpoint['gateway'], $payload));
    $starting = $endpoint;
    $starting['lifecycle_state'] = 'starting';
    self::assertNull($method->invoke(null, $starting, $starting['gateway'], $payload));
    $foreign = $payload;
    $foreign['launch_id'] = \str_repeat('b', 32);
    self::assertNull($method->invoke(null, $endpoint, $endpoint['gateway'], $foreign));
}

private function assert_PureWlsServingManifestRecovery_testStaleManifestFailurePromptsExplicitTargetedCleanStart(): void
{
    $execute = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'execute');
    self::assertStringContainsString("hasCliArgvToken(['--clean', '-clean'])", $execute);
    self::assertStringContainsString('prepareForceResetStart', $execute);
    self::assertStringContainsString('forceResetProjectSharedState', $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'prepareForcedCleanStart'));
    self::assertMatchesRegularExpression('/if \(\$forceClean && !\$masterOnly\).*prepareForceResetStart/s', $execute);
    self::assertMatchesRegularExpression('/elseif \(\$cleanRequested && !\$masterOnly\).*cleanupInactiveInstance\(\$instanceName\)/s', $execute);
    self::assertStringContainsString("'php bin/w server:start'", $execute);
    self::assertStringContainsString(". ' -clean'", $execute);
    $forcedClean = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'prepareForcedCleanStart');
    self::assertStringContainsString('forceCleanupInstance', $forcedClean);
    self::assertStringContainsString('stopExistingServer', $forcedClean);
    self::assertStringContainsString('hasForceCleanBlockingPresence', $forcedClean);
    self::assertStringContainsString('forceResetProjectSharedState', $forcedClean);
    $help = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'help');
    self::assertStringContainsString("'-clean, --clean'", $help);
    self::assertStringContainsString('-clean -f', $help);
    self::assertStringContainsString('强制 + 重置', $help);
    self::assertStringContainsString('server:start -f', $help);
}

private function assert_PureWlsServingManifestRecovery_testMonotonicRebuildUsesWholeProjectAuthorityAndPreservesSiblingDomain(): void
{
    $ensure = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'ensureSslCertificate');
    $rebuildAt = \strpos($ensure, 'if ($this->nativeServingManifestRebuildRequired)');
    $proofAt = \strpos($ensure, '$manifestRecoveryProof', (int) $rebuildAt);
    self::assertIsInt($rebuildAt);
    self::assertIsInt($proofAt);
    $rebuild = \substr($ensure, $rebuildAt, $proofAt - $rebuildAt);
    self::assertGreaterThanOrEqual(2, \substr_count($rebuild, '$this->nativeServingManifestRebuildActiveDomains'));
    self::assertStringContainsString('->authoritySnapshot(', $rebuild);
    self::assertStringContainsString('$selectedDomain = $certificateHost;', $rebuild);
    self::assertStringContainsString('$selectedDomain = $candidateDomain;', $rebuild);
    self::assertMatchesRegularExpression('/activeProjectCertificateResult\(\s*\$selectedDomain,/s', $rebuild);
    self::assertStringContainsString("\$recovered['code'] = 'TLS_SERVING_MANIFEST_REBUILT'", $rebuild);
    $execute = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'execute');
    self::assertMatchesRegularExpression('/\$config\[\'ssl_domain\'\]\s*=\s*\(\$servingManifestRecovery\s*' . '\|\|\s*\(\$sslResult\[\'project_generation_reused\'\].*?\)\s*===\s*true\)' . '\s*\?\s*\(string\)\(\$sslResult\[\'domain\'\]/s', $execute);
}

private function assert_PureWlsServingManifestRecovery_testReusedSelectorCarriesTheCompleteAfterImageWithoutActivation(): void
{
    $execute = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'execute');
    $start = \strpos($execute, "} elseif ((\$sslResult['project_generation_reused'] ?? false) === true) {");
    self::assertIsInt($start);
    $end = \strpos($execute, '} elseif ($sslEnabled', $start);
    self::assertIsInt($end);
    $reused = \substr($execute, $start, $end - $start);
    foreach ($this->completeAfterImageFields_PureWlsServingManifestRecovery() as $field) {
        self::assertStringContainsString("'{$field}' =>", $reused, $field);
    }
    self::assertStringNotContainsString('->activate(', $reused);
    self::assertStringNotContainsString('allocateCertificateGeneration', $reused);
    self::assertStringNotContainsString('ProjectCertificateGenerationStore', $reused);
    $result = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'activeProjectCertificateResult');
    foreach ($this->completeAfterImageFields_PureWlsServingManifestRecovery() as $field) {
        self::assertStringContainsString("'{$field}' =>", $result, $field);
    }
    self::assertStringContainsString("'project_generation_reused' => true", $result);
    self::assertStringNotContainsString('->activate(', $result);
    $managed = $this->methodSource_PureWlsServingManifestRecovery(ManagedNginxConfigWriter::class, 'resolveManagedCertificateGeneration');
    foreach (['domain', 'generation', 'source_digest', 'cert_path', 'key_path', 'chain_path', 'leaf_fingerprint_sha256', 'cert_sha256', 'key_sha256', 'chain_sha256'] as $field) {
        self::assertStringContainsString("'{$field}'", $managed, $field);
        self::assertStringContainsString("'{$field}' =>", $reused, $field);
    }
}

private function assert_PureWlsServingManifestRecovery_testServingManifestRecoveryCarriesItsSelectedDomainAfterImage(): void
{
    $execute = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'execute');
    $start = \strpos($execute, 'if ($servingManifestRecovery) {');
    self::assertIsInt($start);
    $end = \strpos($execute, "} elseif ((\$sslResult['project_generation_reused'] ?? false) === true) {", $start);
    self::assertIsInt($end);
    $recovery = \substr($execute, $start, $end - $start);
    self::assertStringContainsString("'domain' =>", $recovery);
    self::assertStringContainsString("\$sslResult['domain']", $recovery);
}

private function assert_PureWlsServingManifestRecovery_testAutoFallbackAcceptsTheExactReusedSelectorIdentity(): void
{
    $domain = 'sibling.example.test';
    $sourceDigest = \str_repeat('b', 64);
    $trustProfile = ProjectCertificateGenerationStore::TRUST_PROFILE_TEST;
    $provider = ProjectCertificateGenerationStore::PROVIDER_SELF_SIGNED;
    $materialClass = ProjectCertificateGenerationStore::MATERIAL_CLASS_SELF_SIGNED;
    $provenanceDigest = ProjectCertificateGenerationStore::provenanceDigest($domain, $sourceDigest, $trustProfile, $provider, $materialClass);
    $certificatePath = \PHP_OS_FAMILY === 'Windows' ? 'C:\wls\snapshots\fullchain.pem' : '/tmp/wls/snapshots/fullchain.pem';
    $privateKeyPath = \PHP_OS_FAMILY === 'Windows' ? 'C:\wls\snapshots\privkey.pem' : '/tmp/wls/snapshots/privkey.pem';
    $active = ['domain' => $domain, 'generation' => 17, 'source_digest' => $sourceDigest, 'trust_profile' => $trustProfile, 'provider' => $provider, 'material_class' => $materialClass, 'provenance_digest' => $provenanceDigest, 'leaf_fingerprint_sha256' => \str_repeat('d', 64), 'cert_path' => $certificatePath, 'key_path' => $privateKeyPath, 'chain_path' => '', 'cert_sha256' => \str_repeat('e', 64), 'key_sha256' => \str_repeat('f', 64), 'chain_sha256' => ''];
    $endpoint = ['name' => 'shop', 'instance_name' => 'shop', 'master_pid' => 3210, 'master_epoch' => 9, 'edge_adapter' => 'nginx', 'gateway' => ['mode' => 'gateway', 'requested_mode' => 'auto', 'project_uuid' => '123e4567-e89b-42d3-a456-426614174000', 'instance_generation' => 12, 'launch_id' => \str_repeat('a', 32), 'certificate_pending' => false, 'certificate_source' => ['domain' => $domain, 'generation' => 17, 'source_digest' => $sourceDigest, 'trust_profile' => $trustProfile, 'provider' => $provider, 'material_class' => $materialClass, 'provenance_digest' => $provenanceDigest, 'cert_path' => $certificatePath, 'key_path' => $privateKeyPath, 'leaf_fingerprint_sha256' => \str_repeat('d', 64)]]];
    $issued = GatewayStartupFallbackRequest::issue('shop', $endpoint, $active, 'gateway registration failed', 1700000000);
    $validated = GatewayStartupFallbackRequest::assertMatches($issued, 'shop', $endpoint, $active, 1700000010);
    self::assertSame($domain, $validated['certificate_domain']);
    self::assertSame(17, $validated['certificate_generation']);
    self::assertSame($provenanceDigest, $validated['certificate_provenance_digest']);
}

private function assert_PureWlsServingManifestRecovery_testTombstoneAndAbsentAuthorityCannotFallThroughToLegacyPemOrSigning(): void
{
    $ensure = $this->methodSource_PureWlsServingManifestRecovery(Start::class, 'ensureSslCertificate');
    $rebuildAt = \strpos($ensure, 'if ($this->nativeServingManifestRebuildRequired)');
    $configuredAt = \strpos($ensure, '$configuredCertPath', (int) $rebuildAt);
    self::assertIsInt($rebuildAt);
    self::assertIsInt($configuredAt);
    $authorityGate = \substr($ensure, $rebuildAt, $configuredAt - $rebuildAt);
    $invalidAt = \strpos($authorityGate, "'code' => 'TLS_SERVING_MANIFEST_REBUILD_INVALID'");
    $httpOnlyAt = \strpos($authorityGate, "'code' => 'TLS_CERTIFICATE_RETIRED_HTTP_ONLY'");
    self::assertIsInt($invalidAt);
    self::assertIsInt($httpOnlyAt);
    self::assertStringContainsString('has neither a current certificate selector nor a tombstone', $authorityGate);
    self::assertStringContainsString('starting an HTTP-only runtime without PEM material', $authorityGate);
    foreach (['tryUseStartupCertificateFiles(', 'restoreManagedCertificateForConfig(', 'generateSelfSignedCertificate(', 'ensureCertificate('] as $forbiddenFallback) {
        self::assertStringNotContainsString($forbiddenFallback, $authorityGate, $forbiddenFallback);
    }
}

private function assert_PureWlsServingManifestRecovery_testCertificateLifecycleAlwaysPrecedesPublicationTransaction(): void
{
    $publish = $this->methodSource_PureWlsServingManifestRecovery(ProjectServingManifestStore::class, 'publishFromRegistration');
    self::assertStringContainsString('withCertificateAuthorityPublicationTransaction(', $publish);
    self::assertStringNotContainsString('->withPublicationTransaction(', $publish);
    $ordered = $this->methodSource_PureWlsServingManifestRecovery(ProjectServingManifestStore::class, 'withCertificateAuthorityPublicationTransaction');
    $lifecycleAt = \strpos($ordered, '->withCertificateLifecycleLock(');
    $publicationAt = \strpos($ordered, '->withPublicationTransaction(');
    self::assertIsInt($lifecycleAt);
    self::assertIsInt($publicationAt);
    self::assertLessThan($publicationAt, $lifecycleAt, 'The project certificate lifecycle lock must be acquired before publication.');
    foreach (['handleGatewayFallbackCommand', 'handleRunningGatewayFallbackEnable'] as $method) {
        $orchestrator = $this->methodSource_PureWlsServingManifestRecovery(ServiceOrchestrator::class, $method);
        self::assertStringContainsString('->withCertificateAuthorityPublicationTransaction(', $orchestrator, $method);
        self::assertStringNotContainsString('->withPublicationTransaction(', $orchestrator, $method);
    }
    $orchestratorClass = (string) \file_get_contents((string) (new \ReflectionClass(ServiceOrchestrator::class))->getFileName());
    self::assertSame(0, \substr_count($orchestratorClass, '->withPublicationTransaction('));
}

/** @return list<string> */
private function completeAfterImageFields_PureWlsServingManifestRecovery(): array
{
    return ['domain', 'generation', 'source_digest', 'trust_profile', 'provider', 'material_class', 'provenance_digest', 'leaf_fingerprint_sha256', 'cert_path', 'key_path', 'chain_path', 'cert_sha256', 'key_sha256', 'chain_sha256'];
}

private function methodSource_PureWlsServingManifestRecovery(string $class, string $method): string
{
    $reflection = new \ReflectionMethod($class, $method);
    $lines = \file($reflection->getFileName());
    self::assertIsArray($lines);
    return \implode('', \array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}
}
