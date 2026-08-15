<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge;

use PHPUnit\Framework\TestCase;

final class CertificateMaterialUpdateCoordinatorTest extends TestCase
{
    public function testStaticContractPublishesWholeProjectStateBeforeTargetedReload(): void
    {
        $source = $this->source();
        $transaction = \strpos($source, 'withServingPublicationTransactions(');
        $manifest = \strpos($source, '$publication = $this->manifestPublication(');
        $published = \strpos($source, '->submitBuiltRegistration(');
        $reload = \strpos($source, 'reloadSslCertAndWait(');

        self::assertIsInt($transaction);
        self::assertIsInt($manifest);
        self::assertIsInt($published);
        self::assertIsInt($reload);
        self::assertLessThan($manifest, $transaction);
        self::assertLessThan($published, $manifest);
        self::assertLessThan($reload, $published);
        self::assertStringContainsString('$gatewayIntentErrors[]', $source);
        self::assertStringContainsString(
            '$failures[] = \'gateway renewal intent \'',
            $source,
        );
        self::assertStringContainsString('$operationId = \\bin2hex(\\random_bytes(16))', $source);
        self::assertStringContainsString(
            "\$manifestGeneration = (int)\$publication['generation']",
            $source,
        );
        self::assertStringContainsString(
            "\$manifestDigest = (string)\$publication['digest']",
            $source,
        );
        self::assertStringContainsString("['operation_id']", $source);
        self::assertStringContainsString("['expected_manifest_generation']", $source);
        self::assertStringContainsString("['expected_manifest_digest']", $source);
        self::assertStringContainsString("['eligible_workers']", $source);
        self::assertStringContainsString("['acked_workers']", $source);
        self::assertStringContainsString("['failed_workers']", $source);
    }

    public function testNativeReloadWaitRequiresAnOldTlsServingFence(): void
    {
        $source = $this->source();
        self::assertStringContainsString(
            '$nativeReloadRequired[$instanceName] = true',
            $source,
        );
        self::assertStringContainsString(
            '$nativeContainmentTargets[$instanceName] = $endpoint',
            $source,
        );
        self::assertStringContainsString('quarantineNativeTlsFaces(', $source);
        self::assertStringContainsString('explicitPureWlsServingEndpoint(', $source);
        self::assertStringContainsString(
            'fallbackWlsIsServing('
                . "\n            \$endpoint,"
                . "\n            \$deadlineMonotonic,",
            $source,
        );
        self::assertStringContainsString("'NATIVE_EDGE_DRAINING'", $source);
        self::assertStringContainsString("'GATEWAY_ACTIVE'", $source);
        self::assertStringContainsString("\\hash_equals('DRAINED', \$nativeState)", $source);
        self::assertStringContainsString(
            '$potentialNativeTls = $revocationCommitted'
                . "\n                && \$this->nativeReloadRequired("
                . '$endpoint, $deadlineMonotonic)',
            $source,
        );
        self::assertStringContainsString(
            'GatewayStartupRuntimeView::SOURCE_AUTO_NATIVE_WLS',
            $source,
        );
        self::assertStringContainsString("['ACTIVE', 'DRAINING']", $source);
        self::assertStringNotContainsString('reloadSslCert($domains, $instanceName)', $source);
    }

    public function testRuntimeProofDoesNotCompleteOrReloadLegacyRetirement(): void
    {
        $source = $this->source();
        $completion = \strpos($source, '->completeRetirementIntent(');
        $legacy = \strpos($source, '$legacyCompatibilityProbe =');

        self::assertIsInt($completion);
        self::assertIsInt($legacy);
        self::assertLessThan($legacy, $completion);
        self::assertStringNotContainsString('function replayPendingRetirements(', $source);
        self::assertStringContainsString(
            'if (!$revocationCommitted && ($legacyManaged || $legacyCompatibilityProbe))',
            $source,
        );
        $sslService = \file_get_contents(
            \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
                . 'app/code/Weline/Server/Service/SslCertificateService.php',
        );
        self::assertIsString($sslService);
        self::assertStringContainsString(
            "['wls_revocation_intent' => \$intent]",
            $sslService,
        );
    }

    public function testMissingProjectCredentialOnExistingHostRemainsIndeterminate(): void
    {
        $source = $this->source();
        self::assertStringContainsString(
            '$gatewayEnrollmentState = \'credential_missing\'',
            $source,
        );
        self::assertStringContainsString('$gatewayObservationIndeterminate = true', $source);
        self::assertStringContainsString(
            "\$gatewayEnrollmentState = 'credential_missing'",
            $source,
        );
        self::assertStringContainsString(
            "'credential is missing; retirement remains pending'",
            $source,
        );
    }

    public function testRuntimeFailureRequiresACompleteRecoveryProofBeforeCompletion(): void
    {
        $source = $this->source();
        $proofClosure = \strpos($source, 'if ($failures !== []');
        $recoveredFailures = \strpos(
            $source,
            'foreach (\\array_values(\\array_unique(',
        );
        $completion = \strpos($source, '->completeRetirementIntent(');
        self::assertIsInt($proofClosure);
        self::assertIsInt($recoveredFailures);
        self::assertIsInt($completion);
        self::assertLessThan($recoveredFailures, $proofClosure);
        self::assertLessThan($completion, $recoveredFailures);
        self::assertStringContainsString('|| $gatewayObservationIndeterminate', $source);
        self::assertStringContainsString('!$gatewayProofComplete', $source);
        self::assertStringContainsString('$missingLiveNativeProofs', $source);
        self::assertStringContainsString('$unclosedNativeGenerations', $source);
        self::assertStringContainsString('withRetirementLifecycleLock(', $source);
        self::assertStringContainsString('$remaining / 2.0', $source);
    }

    public function testFinalProofClosureRevalidatesEveryFaceUnderOneAbsoluteDeadline(): void
    {
        $source = $this->source();
        self::assertStringContainsString(
            '$finalizeRetirement = function (array $transactions = [])',
            $source,
        );
        self::assertStringContainsString(
            '$liveProjectInstances + $nativeContainmentTargets',
            $source,
        );
        self::assertStringContainsString('$nativeProofDigests = []', $source);
        self::assertStringContainsString('$finalHostTrust = $this->hostGatewayTrustObservation(', $source);
        self::assertStringContainsString('$finalGatewayRetirementRequired = true', $source);
        self::assertStringContainsString('refreshNativeTlsProofs(', $source);
        self::assertStringContainsString('$finalNativeContainmentTargets', $source);
        self::assertStringContainsString('$unlockedFinalNativeTargets', $source);
        self::assertStringContainsString('sameNativeRuntimeFence(', $source);
        self::assertStringContainsString("['observed_generation_absence']", $source);
        self::assertStringContainsString("['final_generation_absence']", $source);
        self::assertStringContainsString("['live_generation_containment']", $source);
        self::assertStringContainsString(
            'proveDeadNativeTlsEndpointAbsent(',
            $source,
        );
        self::assertStringContainsString(
            "'register:' . \$retiredDomain",
            $source,
        );
        self::assertStringContainsString(
            "'guardian:' . \$revokedDomain",
            $source,
        );
        self::assertStringContainsString(
            '->submitBuiltRegistration(',
            $source,
        );
        self::assertStringContainsString('$deadlineMonotonic,', $source);
    }

    public function testRegistrationAndGuardianIoConsumeTheSameAbsoluteDeadline(): void
    {
        $root = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/Gateway/';
        $host = \file_get_contents($root . 'GatewayHostManager.php');
        $client = \file_get_contents($root . 'GatewayClient.php');
        $guardian = \file_get_contents(
            $root . 'GatewayEmergencyRevocationClient.php',
        );
        $builder = \file_get_contents($root . 'GatewayRegistrationBuilder.php');
        foreach ([$host, $client, $guardian, $builder] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('$deadlineMonotonic', $source);
        }
        self::assertStringContainsString(
            'submitBuiltRegistration(',
            $host,
        );
        self::assertStringContainsString(
            'operationLockWaitTimeout($deadlineMonotonic',
            $host,
        );
        self::assertStringContainsString(
            'remainingDeadlineSeconds($deadlineMonotonic)',
            $client,
        );
        self::assertStringContainsString(
            'setStreamDeadlineTimeout(',
            $client,
        );
        self::assertStringContainsString(
            'remainingDeadlineSeconds($deadlineMonotonic)',
            $guardian,
        );
        self::assertStringContainsString(
            'withServingPublicationTransactions(',
            $builder,
        );
        self::assertStringContainsString(
            'publicationLockWaitTimeout($deadlineMonotonic)',
            $builder,
        );
    }

    public function testDeadMasterRequiresManagedTlsTreeAndListenerAbsenceProof(): void
    {
        $source = $this->source();
        self::assertStringContainsString('proveDeadNativeTlsEndpointAbsent(', $source);
        self::assertStringContainsString('processIsDefinitelyRunning(', $source);
        self::assertStringContainsString('GatewayBoundedCommandRunner::run(', $source);
        self::assertStringNotContainsString('getProcessNamesByPrefix(', $source);
        self::assertStringNotContainsString('getProcessIdByPort(', $source);
        self::assertStringContainsString("foreach (['tcp', 'udp'] as \$transport)", $source);
        self::assertStringContainsString('STREAM_SERVER_BIND | STREAM_SERVER_LISTEN', $source);
        self::assertStringContainsString('$nativeListenerGuards = []', $source);
        self::assertStringContainsString('$listenerGuards[$guardKey] = $guard', $source);
        self::assertStringContainsString('$sameRuntimeFence', $source);
        self::assertStringContainsString(
            'The observed native TLS generation remains running',
            $source,
        );
        self::assertStringContainsString(
            "\\in_array(\$servingMode, ['fallback_wls', 'native_wls'], true)",
            $source,
        );
        self::assertStringContainsString('native TLS observed generation proof', $source);
    }

    public function testStaticContractRequiresLiveLegacyMasterBeforeManagedReload(): void
    {
        $source = $this->source();
        $legacy = \strpos($source, 'if ($explicitLegacy)');
        self::assertIsInt($legacy);
        $legacyBlock = \substr($source, $legacy, 2300);
        $lease = \strpos($legacyBlock, 'validateRunningLease(');
        $authorize = \strpos($legacyBlock, "['authorized']");
        $enableReload = \strpos($legacyBlock, '$legacyManaged = true');

        self::assertIsInt($lease);
        self::assertIsInt($authorize);
        self::assertIsInt($enableReload);
        self::assertLessThan($authorize, $lease);
        self::assertLessThan($enableReload, $authorize);
        self::assertStringNotContainsString(
            "if (\$explicitLegacy) {\n                \$legacyManaged = true;",
            $source,
        );
        self::assertStringContainsString('$legacyCompatibilityProbe', $source);
        self::assertStringContainsString(
            '$sourceAdapter === EdgeAdapterInterface::NAME_NGINX',
            $source,
        );
        self::assertStringContainsString(
            'legacy managed Nginx is not installed or no longer owned',
            $source,
        );
        self::assertStringContainsString(
            "catch (\\Throwable \$throwable) {\n"
                . "                if (\$legacyManaged) {\n"
                . "                    \$failures[] = 'legacy managed Nginx: '",
            $source,
        );
    }

    public function testAdaptersDeclareTheirCertificateUpdateSourceMode(): void
    {
        $root = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/';
        $nginx = \file_get_contents($root . 'NginxEdgeAdapter.php');
        $native = \file_get_contents($root . 'WlsNativeEdgeAdapter.php');
        self::assertIsString($nginx);
        self::assertIsString($native);
        self::assertStringContainsString(
            'notify($domain, $paths, self::NAME_NGINX)',
            $nginx,
        );
        self::assertStringContainsString(
            'notify($domain, $paths, self::NAME_WLS)',
            $native,
        );
    }

    private function source(): string
    {
        $path = \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR
            . 'app/code/Weline/Server/Service/Edge/CertificateMaterialUpdateCoordinator.php';
        $source = \file_get_contents($path);
        self::assertIsString($source);
        return $source;
    }
}
