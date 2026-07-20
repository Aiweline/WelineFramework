<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Reports only capabilities proven by the selected WLS data plane.
 *
 * A configurable ticket or an available EventSslContext is not accepted as
 * proof of resumption. Production enablement still requires a live second
 * handshake that reports Reused plus protocol, SNI, policy and topology parity.
 */
final class TlsSessionResumptionCapabilityProbe
{
    private const PRODUCTION_RESUMPTION_TLS_P95_LIMIT_MS = 50.0;

    /**
     * @param array<string, mixed> $wlsAdapters
     * @return array<string, mixed>
     */
    public function snapshot(array $wlsAdapters): array
    {
        $eventContextAvailable = \extension_loaded('event')
            && \class_exists(\EventSslContext::class, false);
        $windows = \PHP_OS_FAMILY === 'Windows';
        $http2Enabled = (bool)($wlsAdapters['http2']['enabled'] ?? false);
        $http3Enabled = (bool)($wlsAdapters['http3']['enabled'] ?? false);
        $http3Capabilities = \is_array($wlsAdapters['http3']['adapter_capabilities'] ?? null)
            ? $wlsAdapters['http3']['adapter_capabilities']
            : [];
        $opensslSessionClass = 'Openssl\\Session';
        $opensslSessionExceptionClass = 'Openssl\\OpensslException';
        $externalStatefulSessionObjectAvailable = \class_exists($opensslSessionClass, false);
        $requiredSessionMethods = ['export', 'import', 'isResumable', 'getTimeout', 'getCreatedAt'];
        $externalStatefulSessionMethodsAvailable = $externalStatefulSessionObjectAvailable;
        foreach ($requiredSessionMethods as $method) {
            if (!\method_exists($opensslSessionClass, $method)) {
                $externalStatefulSessionMethodsAvailable = false;
                break;
            }
        }
        $externalStatefulSessionApiAvailable = TlsSessionCacheRuntime::apiAvailable();
        $externalCacheConfigValid = true;
        $externalCacheConfigError = '';
        try {
            $externalCacheConfig = TlsSessionCacheConfig::fromEnvironment();
        } catch (\Throwable $exception) {
            $externalCacheConfigValid = false;
            $externalCacheConfigError = $exception->getMessage();
            $externalCacheConfig = TlsSessionCacheConfig::fromSslConfig([]);
        }
        $externalCacheRequested = $externalCacheConfigValid && $externalCacheConfig->enabled();
        $externalCacheWorkerEligible = $externalCacheRequested && $externalStatefulSessionApiAvailable;
        try {
            $externalCacheEvidence = (new TlsSessionResumptionEvidenceStore())->readForCurrentRuntime();
        } catch (\Throwable $exception) {
            $externalCacheEvidence = [
                'evidence_available' => false,
                'runtime_mechanism_verified' => false,
                'active_runtime_verified' => false,
                'active_config_matches_evidence' => false,
                'current_scope_matches_evidence' => false,
                'server_session_reuse_observable' => false,
                'same_worker_verified' => false,
                'cross_worker_verified' => false,
                'reload_continuity_verified' => false,
                'sidecar_recovery_verified' => false,
                'performance_baseline_verified' => false,
                'resumption_latency_gate_verified' => false,
                'production_platform_matrix_verified' => false,
                'runtime_prerelease' => (bool)\preg_match('/(?:dev|alpha|beta|rc)/i', PHP_VERSION),
                'proof_summary' => [],
                'production_ready' => false,
                'reason' => 'TLS evidence inspection failed: ' . $exception->getMessage(),
            ];
        }
        $externalCacheRuntimeVerified = $externalCacheWorkerEligible
            && (bool)($externalCacheEvidence['active_runtime_verified'] ?? false);
        $externalCacheMechanismVerified = (bool)($externalCacheEvidence['runtime_mechanism_verified'] ?? false);
        $externalCacheActiveConfigMatches = $externalCacheWorkerEligible
            && (bool)($externalCacheEvidence['active_config_matches_evidence'] ?? false);
        // This global capability snapshot has no live instance receipt to compare.
        $externalCacheCurrentScopeEvaluated = false;
        $externalCacheCurrentScopeMatches = false;
        $externalCacheRuntimePrerelease = (bool)($externalCacheEvidence['runtime_prerelease']
            ?? (bool)\preg_match('/(?:dev|alpha|beta|rc)/i', PHP_VERSION));
        $externalCacheProofSummary = \is_array($externalCacheEvidence['proof_summary'] ?? null)
            ? $externalCacheEvidence['proof_summary']
            : [];
        $resumptionTlsP95Ms = \is_numeric($externalCacheProofSummary['resumption_tls_p95_ms'] ?? null)
            ? (float)$externalCacheProofSummary['resumption_tls_p95_ms']
            : null;
        $diagnosticResumptionTlsP95LimitMs = \is_numeric(
            $externalCacheProofSummary['resumption_tls_p95_limit_ms'] ?? null
        ) ? (float)$externalCacheProofSummary['resumption_tls_p95_limit_ms'] : null;
        $durableEvidenceReason = $externalCacheActiveConfigMatches
            ? 'The durable evidence matches the active external-cache configuration. Active instance scope was not evaluated by this global capability snapshot.'
            : 'The durable evidence does not match the active external-cache configuration. No active resumption claim is made.';
        $streamReason = match (true) {
            !$externalCacheConfigValid => 'The TLS external session-cache configuration is invalid: ' . $externalCacheConfigError,
            $externalCacheRuntimeVerified => 'PHP 8.6 external stateful Session Cache has verified independent-connection, cross-Worker, reload and Memory-sidecar recovery on this exact runtime/config/source revision. This is a stateful TCP cache, not a stateless Ticket key ring. Dedicated resumed-handshake latency and the macOS/Linux/Windows production matrix remain separate gates.',
            $externalCacheMechanismVerified => 'This exact PHP/WLS runtime has durable external stateful TLS Session Resumption evidence. '
                . $durableEvidenceReason
                . ' The fixed production resumed-handshake gate is P95 <= 50 ms; prerelease PHP and the stable macOS/Linux/Windows matrix remain independent production blockers.',
            $externalCacheWorkerEligible => 'PHP 8.6 external stateful Session Cache is configured for the defer-SSL per-connection SNI path. WLS uses a dedicated RAM-only Memory-sidecar store and a preconnected fail-fast channel, but this exact runtime/config/source revision has no accepted live resumption evidence.',
            $externalCacheRequested => 'TLS external Session Cache is configured, but this PHP runtime lacks the PHP 8.6 OpenSSL Stream callbacks. WLS rejects that explicit configuration before listen instead of compiling or silently downgrading.',
            $externalStatefulSessionApiAvailable => 'PHP 8.6 OpenSSL Stream external stateful Session Cache is available but disabled by configuration. API availability is not proof of resumption.',
            default => 'The current PHP runtime lacks the PHP 8.6 OpenSSL Stream external stateful session-cache API. Keep-Alive and HTTP/2 multiplexing avoid handshakes only on the existing connection; cross-connection and cross-Worker TCP resumption remain unsupported and unverified.',
        };

        return (new TlsRuntimeCapability(
            tls13Server: \defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER'),
            handshakeAvoidance: $http2Enabled
                ? 'http2_multiplex_and_keep_alive'
                : 'http1_keep_alive',
            stream: [
                'production_enabled' => true,
                'shared_ssl_context' => false,
                'long_lived_ssl_context' => false,
                'stream_context_ticket_callback_supported' => false,
                'external_stateful_session_api_min_php' => '8.6.0',
                'external_stateful_session_api_available' => $externalStatefulSessionApiAvailable,
                'external_stateful_session_object_available' => $externalStatefulSessionObjectAvailable,
                'external_stateful_session_methods_available' => $externalStatefulSessionMethodsAvailable,
                'external_stateful_session_exception_available' => \class_exists($opensslSessionExceptionClass, false),
                'external_cache_mode' => $externalCacheConfigValid ? $externalCacheConfig->mode : 'invalid',
                'external_cache_config_valid' => $externalCacheConfigValid,
                'external_cache_configured' => $externalCacheRequested,
                'external_cache_worker_eligible' => $externalCacheWorkerEligible,
                'external_cache_dedicated_ram_store' => true,
                'external_cache_callback_connects_or_retries' => false,
                'external_cache_config' => $externalCacheConfig->toArray(),
                'external_cache_evidence' => $externalCacheEvidence,
                'external_cache_runtime_mechanism_verified' => $externalCacheMechanismVerified,
                'external_cache_runtime_verified' => $externalCacheRuntimeVerified,
                'external_cache_durable_evidence_verified' => $externalCacheMechanismVerified,
                'external_cache_active_config_matches_evidence' => $externalCacheActiveConfigMatches,
                'external_cache_current_scope_evaluated' => $externalCacheCurrentScopeEvaluated,
                'external_cache_current_scope_matches_evidence' => $externalCacheCurrentScopeMatches,
                'external_cache_runtime_prerelease' => $externalCacheRuntimePrerelease,
                'external_cache_php_release_channel' => $externalCacheRuntimePrerelease
                    ? 'prerelease'
                    : 'stable',
                'tls13_stateful_ticket_expected' => $externalCacheWorkerEligible,
                'stateless_ticket_key_ring' => false,
                'external_cache_api_disables_stateless_tickets' => $externalStatefulSessionApiAvailable,
                'session_ticket_hint_configurable' => false,
                'session_ticket_configured' => $externalCacheWorkerEligible
                    && $externalCacheConfig->numTickets > 0,
                'session_id_context_supported' => $externalStatefulSessionApiAvailable,
                'session_id_context_configured_by_worker' => $externalCacheWorkerEligible,
                'server_session_reuse_observable_api_available' => $externalStatefulSessionApiAvailable,
                'server_session_reuse_observable' => $externalCacheMechanismVerified
                    && (bool)($externalCacheEvidence['server_session_reuse_observable'] ?? false),
                'session_resumption_verified' => $externalCacheRuntimeVerified,
                'same_worker_session_resumption_verified' => $externalCacheMechanismVerified
                    && (bool)($externalCacheEvidence['same_worker_verified'] ?? false),
                'cross_worker_session_resumption_verified' => $externalCacheMechanismVerified
                    && (bool)($externalCacheEvidence['cross_worker_verified'] ?? false),
                'reload_continuity_verified' => $externalCacheMechanismVerified
                    && (bool)($externalCacheEvidence['reload_continuity_verified'] ?? false),
                'sidecar_recovery_verified' => $externalCacheMechanismVerified
                    && (bool)($externalCacheEvidence['sidecar_recovery_verified'] ?? false),
                'performance_baseline_verified' => (bool)($externalCacheEvidence['performance_baseline_verified'] ?? false),
                'resumption_tls_p95_ms' => $resumptionTlsP95Ms,
                'diagnostic_resumption_tls_p95_limit_ms' => $diagnosticResumptionTlsP95LimitMs,
                'production_resumption_tls_p95_limit_ms' => self::PRODUCTION_RESUMPTION_TLS_P95_LIMIT_MS,
                'resumption_latency_gate_verified' => (bool)($externalCacheEvidence['resumption_latency_gate_verified'] ?? false),
                'production_platform_matrix_verified' => (bool)($externalCacheEvidence['production_platform_matrix_verified'] ?? false),
                'production_ready' => (bool)($externalCacheEvidence['production_ready'] ?? false),
                'cross_worker_ticket_key_ring' => false,
                'cross_worker_ticket_reuse_verified' => false,
                'reason' => $streamReason,
            ],
            eventBuffer: [
                'extension_available' => $eventContextAvailable,
                'platform_candidate' => $eventContextAvailable && !$windows,
                'production_enabled' => false,
                'shared_ssl_context_api_available' => $eventContextAvailable,
                'shared_ssl_context' => false,
                'long_lived_ssl_context_activated' => false,
                'ssl_ctx_ticket_callback_supported' => false,
                'server_session_reuse_observable' => false,
                'session_resumption_verified' => false,
                'same_worker_session_resumption_verified' => false,
                'cross_worker_ticket_key_ring' => false,
                'cross_worker_ticket_reuse_verified' => false,
                'runtime_self_test_required' => true,
                'reason' => $windows
                    ? 'Native Windows event SSL is disabled because the live TLS accept path has no passing runtime self-test.'
                    : 'Auto selection remains blocked until live two-handshake reuse, ALPN, SNI, policy and topology parity all pass.',
            ],
            http2Multiplex: $http2Enabled,
            http3Quic: [
                'enabled' => $http3Enabled,
                'tcp_alpn' => false,
                'upgrade' => 'alt-svc',
                'native_tls_ticket_key_ring' => (bool)($http3Capabilities['native_tls_ticket_key_ring'] ?? false),
                'cross_worker_ticket_key_ring' => (bool)($http3Capabilities['cross_worker_ticket_key_ring'] ?? false),
                'server_session_reuse_observable' => (bool)($http3Capabilities['tls_server_session_reuse_observable'] ?? false),
                'session_resumption_verified' => (bool)($http3Capabilities['tls_session_resumption_verified'] ?? false),
                'cross_context_session_resumption_verified' => (bool)($http3Capabilities['tls_cross_context_session_resumption_verified'] ?? false),
                'cross_worker_session_resumption_verified' => (bool)($http3Capabilities['tls_cross_worker_session_resumption_verified'] ?? false),
                'ticket_rotation_continuity_verified' => (bool)($http3Capabilities['tls_ticket_rotation_continuity_verified'] ?? false),
                'ticket_ring_ack_activation' => (bool)($http3Capabilities['tls_ticket_ring_ack_activation'] ?? false),
                'early_data_disabled' => (bool)($http3Capabilities['tls_early_data_disabled'] ?? false),
                'ticket_model' => 'stateless_shared_key_ring',
                'reason' => (bool)($http3Capabilities['tls_session_resumption_verified'] ?? false)
                    ? 'Native HTTP/3 stateless session-ticket key-ring resumption is verified independently of the PHP Stream TCP data plane.'
                    : 'Native HTTP/3 stateless session-ticket key-ring resumption is unavailable or has not passed its server-side proof.',
            ],
        ))->toArray();
    }
}
