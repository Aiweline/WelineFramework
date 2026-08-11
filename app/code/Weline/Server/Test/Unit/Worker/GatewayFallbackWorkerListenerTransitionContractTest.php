<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\TestCase;

final class GatewayFallbackWorkerListenerTransitionContractTest extends TestCase
{
    public function testFallbackDrainUsesTheReversibleBranchWithoutClosingTheListener(): void
    {
        $source = $this->source();

        self::assertStringContainsString('--gateway-master-launch-id=', $source);
        self::assertStringContainsString('$gatewayFallbackListenerState', $source);
        self::assertStringContainsString('validateGatewayFallbackListenerTransition(', $source);
        self::assertStringContainsString('wlsGatewayFallbackTransitionIdentityMatches(', $source);
        self::assertStringContainsString('wlsApplyGatewayFallbackListenerTransition(', $source);

        $handler = $this->region(
            $source,
            'case \\Weline\\Server\\IPC\\ControlMessage::TYPE_DRAIN:',
            'case \\Weline\\Server\\IPC\\ControlMessage::TYPE_SET_MAINTENANCE_MODE:',
        );
        $fallbackBranch = $this->region(
            $handler,
            'if ($isGatewayFallbackWorker',
            '// 排水模式：停止接受新连接',
        );
        self::assertStringContainsString('GATEWAY_FALLBACK_LISTENER_PROTOCOL', $fallbackBranch);
        self::assertStringContainsString('\\hash_equals(', $fallbackBranch);
        self::assertStringContainsString('validateGatewayFallbackListenerTransition(', $fallbackBranch);
        self::assertStringContainsString('wlsApplyGatewayFallbackListenerTransition(', $fallbackBranch);
        self::assertStringContainsString('wlsSendGatewayFallbackListenerAck(', $fallbackBranch);
        self::assertStringNotContainsString('$ipcDraining', $fallbackBranch);
        self::assertStringNotContainsString('$drainStartTime', $fallbackBranch);
        self::assertStringNotContainsString('$maxDrainTime', $fallbackBranch);
        self::assertStringNotContainsString('$shouldExit = true;', $fallbackBranch);
        self::assertStringNotContainsString('@\\fclose($socket);', $fallbackBranch);
        self::assertStringNotContainsString('beginDrain(', $fallbackBranch);
        self::assertStringNotContainsString('initiateGoaway(', $fallbackBranch);
        self::assertStringNotContainsString('drainingComplete(', $fallbackBranch);

        $stateMachine = $this->region(
            $source,
            'function wlsApplyGatewayFallbackListenerTransition(',
            'function wlsSendGatewayFallbackListenerAck(',
        );
        self::assertStringContainsString('GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN', $stateMachine);
        self::assertStringContainsString('$listenerDraining = true;', $stateMachine);
        self::assertStringContainsString('$listenerDraining = false;', $stateMachine);
        self::assertStringNotContainsString('$ipcDraining', $stateMachine);

        // Generic DRAIN remains terminal for every non-fallback worker.
        self::assertStringContainsString('$shouldExit = true;', $handler);
        self::assertStringContainsString('$ipcDraining = true;', $handler);
        self::assertStringContainsString('@\\fclose($socket);', $handler);
        self::assertLessThan(
            \strpos($handler, '$shouldExit = true;'),
            \strpos($handler, 'break;', \strpos($handler, 'if ($isGatewayFallbackWorker')),
            'The reversible branch must return before the generic terminal DRAIN path.',
        );
    }

    public function testFallbackUndrainRequiresTheAcknowledgedExactPredecessor(): void
    {
        $source = $this->source();
        $handler = $this->region(
            $source,
            'case \\Weline\\Server\\IPC\\ControlMessage::TYPE_UNDRAIN:',
            'case \\Weline\\Server\\IPC\\ControlMessage::TYPE_SET_MAINTENANCE_MODE:',
        );

        self::assertStringContainsString('$gatewayFallbackDrainAcknowledged', $handler);
        self::assertStringContainsString('wlsGatewayFallbackTlsContextIsUsable(', $handler);
        self::assertStringContainsString('$gatewayFallbackListenerDraining = false;', $handler);
        self::assertStringContainsString('wlsSendGatewayFallbackListenerAck(', $handler);
        self::assertStringContainsString("!\$ackDelivery['enqueued']", $handler);
        self::assertStringContainsString("\$ackDelivery['flushed']", $handler);
        self::assertStringContainsString('$ipcReceivedShutdown', $handler);
        self::assertStringContainsString('$shouldExit', $handler);
        self::assertStringNotContainsString('$ipcDraining', $handler);
        self::assertStringNotContainsString('$drainStartTime', $handler);
        self::assertStringNotContainsString('$maxDrainTime', $handler);

        $rollback = $this->region(
            $handler,
            "if (\$result['success']\n                            && !\$ackDelivery['enqueued']",
            "if (\$result['success'] && \$ackDelivery['enqueued'])",
        );
        self::assertStringContainsString("!\$ackDelivery['enqueued']", $rollback);
        self::assertStringNotContainsString("!\$ackDelivery['flushed']", $rollback);

        $stateMachine = $this->region(
            $source,
            'function wlsApplyGatewayFallbackListenerTransition(',
            'function wlsSendGatewayFallbackListenerAck(',
        );
        self::assertStringContainsString('predecessor_action_digest', $stateMachine);
        self::assertStringContainsString('wlsGatewayFallbackTransitionIdentityMatches(', $stateMachine);
        self::assertStringContainsString('$drainAcknowledged', $stateMachine);
        self::assertStringContainsString('$undrainAllowed', $stateMachine);
        self::assertStringContainsString('undrain_tls_context_unavailable', $stateMachine);
        self::assertStringContainsString('drain_transition_conflict', $stateMachine);
        self::assertStringContainsString('drain_transition_replay', $stateMachine);
        self::assertStringContainsString('undrain_transition_replay', $stateMachine);
        self::assertStringContainsString('isset($retiredTransitions[$transitionId])', $stateMachine);
        self::assertStringContainsString('transition_history_exhausted', $stateMachine);
        self::assertStringNotContainsString('\\array_shift($retiredTransitions)', $stateMachine);

        $tlsFence = $this->region(
            $source,
            'function wlsGatewayFallbackTlsContextIsUsable(',
            'function wlsApplyGatewayFallbackListenerTransition(',
        );
        self::assertStringContainsString('ProjectCertificateGenerationStore', $tlsFence);
        self::assertStringContainsString(
            '->active($domain, $deadline, $trustProfile)',
            $tlsFence,
        );
        self::assertStringContainsString("['certificate_generation']", $tlsFence);
        self::assertStringContainsString("['certificate_source_digest']", $tlsFence);
        self::assertStringContainsString("['leaf_fingerprint_sha256']", $tlsFence);
        self::assertStringContainsString("['cert_path']", $tlsFence);
        self::assertStringContainsString("['key_path']", $tlsFence);

        $ackHelper = $this->region(
            $source,
            'function wlsSendGatewayFallbackListenerAck(',
            'function wlsServingManifestContextIdentitySet(',
        );
        self::assertStringContainsString("'enqueued' => \$enqueued", $ackHelper);
        self::assertStringContainsString("'flushed' => \$enqueued &&", $ackHelper);
    }

    public function testDrainingListenerConsumesABoundedAcceptBatchWithoutAdmission(): void
    {
        $source = $this->source();
        $acceptor = $this->region(
            $source,
            'function wlsSslAcceptNewConnections(',
            'function wlsSslTuneAcceptedStream(',
        );

        self::assertStringContainsString('$rejectWithoutAdmission', $acceptor);
        self::assertStringContainsString('$accepted < $maxAcceptPerLoop', $acceptor);
        self::assertStringContainsString('safeCloseStream($conn);', $acceptor);
        self::assertStringContainsString('continue;', $acceptor);

        $call = $this->region(
            $source,
            '$admittedConnections = wlsSslAcceptNewConnections(',
            'if ($darwinSharedAcceptCooldownEnabled && $admittedConnections > 0)',
        );
        self::assertStringContainsString('$gatewayFallbackListenerDraining', $call);
        self::assertStringContainsString('rejectWithoutAdmission:', $call);
    }

    public function testFallbackGracefulExitReportsItsExactRole(): void
    {
        $source = $this->source();
        self::assertMatchesRegularExpression(
            '/\$gracefulExit\s*=\s*function\s*\([^)]*\)\s*use\s*\([^)]*'
                . '\$isGatewayFallbackWorker[^)]*\)/s',
            $source,
            'The graceful-exit closure must capture the fallback role selector it reads.',
        );
    }

    private function source(): string
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 7) . '/app/code/Weline/Server/bin/worker_ssl.php',
        );
        self::assertIsString($source);
        return $source;
    }

    private function region(string $source, string $startMarker, string $endMarker): string
    {
        $start = \strpos($source, $startMarker);
        self::assertNotFalse($start, 'Missing source marker: ' . $startMarker);
        $end = \strpos($source, $endMarker, (int)$start + \strlen($startMarker));
        self::assertNotFalse($end, 'Missing source marker: ' . $endMarker);
        return \substr($source, (int)$start, (int)$end - (int)$start);
    }
}
