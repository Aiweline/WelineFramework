<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * Proves TLS 1.3 session resumption against the loopback-only managed Nginx probe.
 *
 * Every request uses a fresh TCP connection. Each proof pair owns a new cURL
 * SSL session share containing exactly one fresh issuer request and one resume
 * probe, so an "r" response is bound to that pair's issuer and probe Worker
 * PIDs, never HTTP keep-alive, HTTP/2 multiplexing, or an older shared cache.
 */
final class ManagedNginxTlsSessionResumptionVerifier
{
    public const PROOF_MODEL = 'fresh-share-two-connection-pair-v1';
    public const RELOAD_CONTINUITY_PROOF_MODEL = 'fresh-share-across-nginx-reload-v1';
    public const MIN_VALID_PROBES = 8;
    public const MAX_PROOF_PAIRS = 128;
    public const MAX_RESUMED_TLS_HANDSHAKE_P95_US = 50_000;

    private const PROBE_PATH = '/_wls/nginx/tls-session-probe';
    private const CONNECT_TIMEOUT_MS = 1_000;
    private const REQUEST_TIMEOUT_MS = 2_000;
    private const VERIFICATION_DEADLINE_SECONDS = 30.0;

    /**
     * @param list<string> $serverNames
     * @return array{ok:bool,message:string,evidence:array<string,mixed>}
     */
    public function verify(
        int $port,
        array $serverNames,
        int $masterPid,
        string $configGeneration,
        string $configSha256,
        string $certificateSha256,
    ): array {
        $configGeneration = \strtolower(\trim($configGeneration));
        $configSha256 = \strtolower(\trim($configSha256));
        $certificateSha256 = \strtolower(\trim($certificateSha256));
        if ($port < 1
            || $port > 65535
            || $masterPid < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $configGeneration) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $configSha256) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $certificateSha256) !== 1
        ) {
            return $this->failure('managed nginx TLS session verification identity is invalid');
        }
        if (!\extension_loaded('curl')
            || !\function_exists('curl_share_init')
            || !\defined('CURL_LOCK_DATA_SSL_SESSION')
            || !\defined('CURL_SSLVERSION_TLSv1_3')
            || !\defined('CURL_SSLVERSION_MAX_TLSv1_3')
            || !\defined('CURLINFO_HTTP_VERSION')
        ) {
            return $this->failure(
                'PHP cURL with TLS 1.3 and shared SSL session cache support is required'
            );
        }

        $peerName = $this->resolvePeerName($serverNames);
        try {
            $effectiveWorkerCount = $this->detectEffectiveWorkerCount($masterPid);
            if ($effectiveWorkerCount < 1) {
                return $this->failure(
                    'managed nginx effective worker count could not be verified'
                );
            }

            $baselineWorkerPid = 0;
            $observedWorkerPids = [];
            $sampleCount = 0;
            $completedCount = 0;
            $failedCount = 0;
            $freshCount = 0;
            $resumedCount = 0;
            $sameWorkerResumedCount = 0;
            $crossWorkerResumedCount = 0;
            $resumedTlsHandshakeMicros = [];
            $deadline = \microtime(true) + self::VERIFICATION_DEADLINE_SECONDS;

            while ($sampleCount < self::MAX_PROOF_PAIRS && \microtime(true) < $deadline) {
                $pair = $this->performProofPair(
                    $peerName,
                    $port,
                    $configGeneration,
                );
                $sampleCount++;
                $completedCount++;
                $issuerWorkerPid = (int)$pair['issuer_worker_pid'];
                $probeWorkerPid = (int)$pair['probe_worker_pid'];
                if ($baselineWorkerPid === 0) {
                    $baselineWorkerPid = $issuerWorkerPid;
                }
                $observedWorkerPids[$issuerWorkerPid] = true;
                $observedWorkerPids[$probeWorkerPid] = true;

                if (\hash_equals('.', (string)$pair['reuse'])) {
                    $freshCount++;
                } else {
                    $resumedCount++;
                    $resumedTlsHandshakeMicros[] = (int)$pair['appconnect_time_us'];
                    if ($probeWorkerPid === $issuerWorkerPid) {
                        $sameWorkerResumedCount++;
                    } else {
                        $crossWorkerResumedCount++;
                    }
                }

                $observedWorkerCount = \count($observedWorkerPids);
                if ($observedWorkerCount > $effectiveWorkerCount) {
                    return $this->failure(
                        'managed nginx Worker identity set changed during TLS session proof pairs'
                    );
                }
                if ($completedCount >= self::MIN_VALID_PROBES
                    && (($effectiveWorkerCount === 1 && $sameWorkerResumedCount > 0)
                        || ($effectiveWorkerCount > 1
                        && $sameWorkerResumedCount > 0
                        && $crossWorkerResumedCount > 0))
                ) {
                    break;
                }
            }

            $finalEffectiveWorkerCount = $this->detectEffectiveWorkerCount($masterPid);
            if ($finalEffectiveWorkerCount !== $effectiveWorkerCount) {
                return $this->failure(
                    'managed nginx effective Worker count changed during TLS session proof pairs'
                );
            }
            if ($completedCount < self::MIN_VALID_PROBES
                || $failedCount !== 0
                || $completedCount + $failedCount !== $sampleCount
                || $freshCount + $resumedCount !== $completedCount
                || $sameWorkerResumedCount + $crossWorkerResumedCount !== $resumedCount
            ) {
                return $this->failure(
                    'managed nginx TLS session proof pairs did not produce eight valid zero-failure probes'
                );
            }

            $observedWorkerCount = \count($observedWorkerPids);
            $sameWorkerVerified = $sameWorkerResumedCount > 0;
            $crossWorkerVerified = $crossWorkerResumedCount > 0;
            if (!$sameWorkerVerified
                || ($effectiveWorkerCount === 1
                    && ($observedWorkerCount !== 1 || $crossWorkerVerified))
                || ($effectiveWorkerCount > 1
                    && ($observedWorkerCount < 2 || !$crossWorkerVerified))
            ) {
                return $this->failure(
                    'managed nginx TLS session proof pairs did not bind same/cross worker resumption to the live worker count'
                );
            }

            $negativeControl = $this->performNegativeControl(
                $peerName,
                $port,
                $configGeneration,
            );
            if (!($negativeControl['valid'] ?? false)
                || !\hash_equals('.', (string)($negativeControl['reuse'] ?? ''))
            ) {
                return $this->failure(
                    'managed nginx independent SSL session share negative control was not fresh'
                );
            }
            if ($resumedCount < 1) {
                return $this->failure(
                    'managed nginx did not prove TLS 1.3 session resumption on fresh TCP connections'
                );
            }

            $resumedTlsHandshakeP95Us = $this->percentile95($resumedTlsHandshakeMicros);
            if ($resumedTlsHandshakeP95Us < 1
                || $resumedTlsHandshakeP95Us > self::MAX_RESUMED_TLS_HANDSHAKE_P95_US
            ) {
                return $this->failure(
                    'managed nginx resumed TLS handshake p95 exceeded the 50ms release gate'
                );
            }
            $sameWorkerStatus = 'verified';
            $crossWorkerStatus = $effectiveWorkerCount === 1
                ? 'not_applicable'
                : 'verified';
            $evidence = [
                'tls_session_resumption_runtime_verified' => true,
                'tls_session_resumption_status' => 'verified',
                'tls_session_resumption_proof_model' => self::PROOF_MODEL,
                'tls_session_resumption_baseline_result' => '.',
                'tls_session_resumption_negative_control_result' => '.',
                'tls_session_resumption_tls_protocol' => 'TLSv1.3',
                'tls_session_resumption_http_protocol' => 'http/1.1',
                'tls_session_resumption_sample_count' => $sampleCount,
                'tls_session_resumption_completed_count' => $completedCount,
                'tls_session_resumption_failed_count' => $failedCount,
                'tls_session_resumption_fresh_count' => $freshCount,
                'tls_session_resumption_resumed_count' => $resumedCount,
                'tls_session_resumption_resumed_tls_handshake_p95_us' => $resumedTlsHandshakeP95Us,
                'tls_session_resumption_same_worker_status' => $sameWorkerStatus,
                'tls_session_resumption_same_worker_runtime_verified' => true,
                'tls_session_resumption_same_worker_resumed_count' => $sameWorkerResumedCount,
                'tls_session_resumption_cross_worker_status' => $crossWorkerStatus,
                'tls_session_resumption_cross_worker_resumed_count' => $crossWorkerResumedCount,
                'tls_session_resumption_cross_worker_runtime_verified' => $effectiveWorkerCount > 1,
                'tls_session_resumption_baseline_worker_pid' => $baselineWorkerPid,
                'tls_session_resumption_observed_worker_count' => $observedWorkerCount,
                'tls_session_resumption_effective_worker_count' => $effectiveWorkerCount,
                'tls_session_resumption_master_pid' => $masterPid,
                'tls_session_resumption_config_generation' => $configGeneration,
                'tls_session_resumption_config_sha256' => $configSha256,
                'tls_session_resumption_ssl_certificate_sha256' => $certificateSha256,
                'tls_session_resumption_verified_at' => \date('c'),
            ];

            return [
                'ok' => true,
                'message' => 'managed nginx TLS 1.3 session resumption verified on fresh TCP connections',
                'evidence' => $evidence,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    /**
     * Capture one fresh TLS 1.3 Session before a managed Nginx reload.
     *
     * The returned probe intentionally owns a live cURL share handle. Call
     * completeReloadContinuityProbe() after the new config generation is live,
     * or releaseReloadContinuityProbe() on every abandoned path.
     *
     * @param list<string> $serverNames
     * @return array<string,mixed>
     */
    public function beginReloadContinuityProbe(
        int $port,
        array $serverNames,
        int $masterPid,
        string $configGeneration,
        string $certificateSha256,
    ): array {
        $configGeneration = \strtolower(\trim($configGeneration));
        $certificateSha256 = \strtolower(\trim($certificateSha256));
        if ($port < 1
            || $port > 65535
            || $masterPid < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $configGeneration) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $certificateSha256) !== 1
        ) {
            throw new \RuntimeException(
                'managed nginx reload continuity issuer identity is invalid'
            );
        }
        if (!\extension_loaded('curl')
            || !\function_exists('curl_share_init')
            || !\defined('CURL_LOCK_DATA_SSL_SESSION')
            || !\defined('CURL_SSLVERSION_TLSv1_3')
            || !\defined('CURL_SSLVERSION_MAX_TLSv1_3')
            || !\defined('CURLINFO_HTTP_VERSION')
        ) {
            throw new \RuntimeException(
                'PHP cURL with TLS 1.3 and shared SSL session cache support is required'
            );
        }
        if ($this->detectEffectiveWorkerCount($masterPid) < 1) {
            throw new \RuntimeException(
                'managed nginx effective worker count could not be verified before reload'
            );
        }

        $peerName = $this->resolvePeerName($serverNames);
        $share = $this->createShare();
        try {
            $issuer = $this->performSingle($share, $peerName, $port, $configGeneration);
            if (!($issuer['valid'] ?? false)
                || !\hash_equals('.', (string)($issuer['reuse'] ?? ''))
                || (int)($issuer['worker_pid'] ?? 0) < 1
            ) {
                throw new \RuntimeException(
                    'managed nginx reload continuity issuer was not a fresh TLS 1.3 connection'
                );
            }

            return [
                'share' => $share,
                'peer_name' => $peerName,
                'port' => $port,
                'master_pid' => $masterPid,
                'previous_config_generation' => $configGeneration,
                'certificate_sha256' => $certificateSha256,
                'issuer_worker_pid' => (int)$issuer['worker_pid'],
            ];
        } catch (\Throwable $throwable) {
            $this->closeShare($share);
            throw $throwable;
        }
    }

    /**
     * Resume the exact pre-reload Session against the new config generation.
     *
     * @param array<string,mixed> $probe
     * @return array{ok:bool,message:string,evidence:array<string,mixed>}
     */
    public function completeReloadContinuityProbe(
        array &$probe,
        int $port,
        int $masterPid,
        string $configGeneration,
        string $certificateSha256,
    ): array {
        $configGeneration = \strtolower(\trim($configGeneration));
        $certificateSha256 = \strtolower(\trim($certificateSha256));
        $previousGeneration = \strtolower(\trim((string)($probe['previous_config_generation'] ?? '')));
        $previousCertificateSha256 = \strtolower(\trim((string)($probe['certificate_sha256'] ?? '')));
        $issuerWorkerPid = (int)($probe['issuer_worker_pid'] ?? 0);
        $share = $probe['share'] ?? null;
        try {
            if ((!@\is_resource($share) && !\is_object($share))
                || $port < 1
                || $port > 65535
                || (int)($probe['port'] ?? 0) !== $port
                || $masterPid < 1
                || (int)($probe['master_pid'] ?? 0) !== $masterPid
                || $issuerWorkerPid < 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', $previousGeneration) !== 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', $configGeneration) !== 1
                || \hash_equals($previousGeneration, $configGeneration)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $previousCertificateSha256) !== 1
                || !\hash_equals($previousCertificateSha256, $certificateSha256)
            ) {
                return $this->failure(
                    'managed nginx reload continuity completion identity is invalid'
                );
            }
            if ($this->detectEffectiveWorkerCount($masterPid) < 1) {
                return $this->failure(
                    'managed nginx effective worker count could not be verified after reload'
                );
            }

            $resumed = $this->performSingle(
                $share,
                (string)($probe['peer_name'] ?? ''),
                $port,
                $configGeneration,
            );
            $probeWorkerPid = (int)($resumed['worker_pid'] ?? 0);
            $handshakeUs = (int)($resumed['appconnect_time_us'] ?? 0);
            if (!($resumed['valid'] ?? false)
                || !\hash_equals('r', (string)($resumed['reuse'] ?? ''))
                || $probeWorkerPid < 1
                || $probeWorkerPid === $issuerWorkerPid
            ) {
                return $this->failure(
                    'managed nginx did not resume the pre-reload TLS 1.3 Session on the new Worker generation'
                );
            }
            if ($handshakeUs < 1 || $handshakeUs > self::MAX_RESUMED_TLS_HANDSHAKE_P95_US) {
                return $this->failure(
                    'managed nginx post-reload resumed TLS handshake exceeded the 50ms release gate'
                );
            }

            return [
                'ok' => true,
                'message' => 'managed nginx TLS 1.3 Session survived the verified reload generation',
                'evidence' => [
                    'tls_session_resumption_reload_continuity_verified' => true,
                    'tls_session_resumption_reload_continuity_status' => 'verified',
                    'tls_session_resumption_reload_continuity_proof_model' =>
                        self::RELOAD_CONTINUITY_PROOF_MODEL,
                    'tls_session_resumption_reload_continuity_result' => 'r',
                    'tls_session_resumption_reload_issuer_worker_pid' => $issuerWorkerPid,
                    'tls_session_resumption_reload_probe_worker_pid' => $probeWorkerPid,
                    'tls_session_resumption_reload_master_pid' => $masterPid,
                    'tls_session_resumption_reload_tls_handshake_us' => $handshakeUs,
                    'tls_session_resumption_reload_previous_config_generation' => $previousGeneration,
                    'tls_session_resumption_reload_config_generation' => $configGeneration,
                    'tls_session_resumption_reload_verified_at' => \date('c'),
                ],
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        } finally {
            $this->releaseReloadContinuityProbe($probe);
        }
    }

    /** @param array<string,mixed> $probe */
    public function releaseReloadContinuityProbe(array &$probe): void
    {
        $share = $probe['share'] ?? null;
        if (@\is_resource($share) || \is_object($share)) {
            $this->closeShare($share);
        }
        $probe['share'] = null;
    }

    /** @return array{ok:bool,message:string,evidence:array<string,mixed>} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'evidence' => []];
    }

    /** @param list<string> $serverNames */
    private function resolvePeerName(array $serverNames): string
    {
        foreach ($serverNames as $serverName) {
            $candidate = \strtolower(\trim((string)$serverName));
            if ($candidate === ''
                || $candidate === '_'
                || \str_contains($candidate, '*')
                || \str_starts_with($candidate, '.')
            ) {
                continue;
            }
            if (\filter_var(\trim($candidate, '[]'), FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            if (\preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'localhost';
    }

    private function createShare(): mixed
    {
        $share = @\curl_share_init();
        if ($share === false
            || !@\curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION)
        ) {
            if ($share !== false) {
                $this->closeShare($share);
            }
            throw new \RuntimeException('unable to initialize cURL shared SSL session cache');
        }

        return $share;
    }

    private function closeShare(mixed $share): void
    {
        if (\function_exists('curl_share_close')) {
            @\curl_share_close($share);
        }
    }

    /**
     * @return array{valid:bool,reuse:string,worker_pid:int,appconnect_time_us:int}
     */
    private function performSingle(
        mixed $share,
        string $peerName,
        int $port,
        string $configGeneration,
    ): array {
        $request = $this->createRequest($share, $peerName, $port);
        try {
            @\curl_exec($request['handle']);
            return $this->completeRequest($request, $configGeneration);
        } finally {
            @\curl_close($request['handle']);
        }
    }

    /**
     * @return array{issuer_worker_pid:int,probe_worker_pid:int,reuse:string,appconnect_time_us:int}
     */
    private function performProofPair(
        string $peerName,
        int $port,
        string $configGeneration,
    ): array {
        $share = $this->createShare();
        try {
            $issuer = $this->performSingle($share, $peerName, $port, $configGeneration);
            if (!($issuer['valid'] ?? false)
                || !\hash_equals('.', (string)($issuer['reuse'] ?? ''))
            ) {
                throw new \RuntimeException(
                    'managed nginx TLS session proof-pair issuer was not a fresh TLS 1.3 connection'
                );
            }
            $probe = $this->performSingle($share, $peerName, $port, $configGeneration);
            if (!($probe['valid'] ?? false)) {
                throw new \RuntimeException(
                    'managed nginx TLS session proof-pair resume probe was invalid'
                );
            }

            return [
                'issuer_worker_pid' => (int)$issuer['worker_pid'],
                'probe_worker_pid' => (int)$probe['worker_pid'],
                'reuse' => (string)$probe['reuse'],
                'appconnect_time_us' => (int)$probe['appconnect_time_us'],
            ];
        } finally {
            $this->closeShare($share);
        }
    }

    /** @return array{valid:bool,reuse:string,worker_pid:int,appconnect_time_us:int} */
    private function performNegativeControl(
        string $peerName,
        int $port,
        string $configGeneration,
    ): array {
        $share = $this->createShare();
        try {
            return $this->performSingle($share, $peerName, $port, $configGeneration);
        } finally {
            $this->closeShare($share);
        }
    }

    /**
     * @return array{handle:mixed,state:\stdClass}
     */
    private function createRequest(mixed $share, string $peerName, int $port): array
    {
        $state = new \stdClass();
        $state->headers = [];
        $url = 'https://' . $peerName . ':' . $port . self::PROBE_PATH;
        $handle = @\curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('unable to initialize cURL TLS session verification request');
        }
        $sslVersion = (int)\constant('CURL_SSLVERSION_TLSv1_3')
            | (int)\constant('CURL_SSLVERSION_MAX_TLSv1_3');
        $options = [
            CURLOPT_SHARE => $share,
            CURLOPT_RESOLVE => [$peerName . ':' . $port . ':127.0.0.1'],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSLVERSION => $sslVersion,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::REQUEST_TIMEOUT_MS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Accept: image/gif',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Connection: close',
            ],
            CURLOPT_HEADERFUNCTION => static function (mixed $curl, string $line) use ($state): int {
                $trimmed = \rtrim($line, "\r\n");
                if (\preg_match('/\AHTTP\//i', $trimmed) === 1) {
                    $state->headers = [];
                    return \strlen($line);
                }
                $separator = \strpos($trimmed, ':');
                if ($separator !== false) {
                    $name = \strtolower(\trim(\substr($trimmed, 0, $separator)));
                    $value = \trim(\substr($trimmed, $separator + 1));
                    if ($name !== '') {
                        $state->headers[$name] = $value;
                    }
                }
                return \strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static fn(mixed $curl, string $chunk): int => \strlen($chunk),
        ];
        if (\defined('CURLOPT_SSL_SESSIONID_CACHE')) {
            $options[(int)\constant('CURLOPT_SSL_SESSIONID_CACHE')] = true;
        }
        if (!@\curl_setopt_array($handle, $options)) {
            @\curl_close($handle);
            throw new \RuntimeException('unable to configure cURL TLS session verification request');
        }

        return ['handle' => $handle, 'state' => $state];
    }

    /**
     * @param array{handle:mixed,state:\stdClass} $request
     * @return array{valid:bool,reuse:string,worker_pid:int,appconnect_time_us:int}
     */
    private function completeRequest(array $request, string $configGeneration): array
    {
        $handle = $request['handle'];
        $headers = (array)$request['state']->headers;
        $appConnectTimeUs = $this->appConnectTimeMicros($handle);
        $reuse = (string)($headers['x-wls-nginx-tls-session-reused'] ?? '');
        $workerPidRaw = (string)($headers['x-wls-nginx-worker-pid'] ?? '');
        $workerPid = \ctype_digit($workerPidRaw) ? (int)$workerPidRaw : 0;
        $httpVersion = (int)@\curl_getinfo($handle, CURLINFO_HTTP_VERSION);
        $valid = @\curl_errno($handle) === CURLE_OK
            && (int)@\curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200
            && $httpVersion === CURL_HTTP_VERSION_1_1
            && \hash_equals(
                $configGeneration,
                \strtolower((string)($headers['x-wls-nginx-config'] ?? '')),
            )
            && $workerPid > 0
            && \in_array($reuse, ['.', 'r'], true)
            && \hash_equals(
                'TLSv1.3',
                (string)($headers['x-wls-nginx-tls-protocol'] ?? ''),
            )
            && \str_contains(
                \strtolower((string)($headers['cache-control'] ?? '')),
                'no-store',
            );

        return [
            'valid' => $valid,
            'reuse' => $valid ? $reuse : '',
            'worker_pid' => $valid ? $workerPid : 0,
            'appconnect_time_us' => $valid ? $appConnectTimeUs : 0,
        ];
    }

    private function appConnectTimeMicros(mixed $handle): int
    {
        if (\defined('CURLINFO_APPCONNECT_TIME_T')) {
            $micros = @\curl_getinfo(
                $handle,
                (int)\constant('CURLINFO_APPCONNECT_TIME_T'),
            );
            if ((\is_int($micros) || \is_float($micros)) && $micros >= 0) {
                return (int)$micros;
            }
        }
        $seconds = (float)@\curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);

        return \max(0, (int)\round($seconds * 1_000_000));
    }

    /** @param list<int> $values */
    private function percentile95(array $values): int
    {
        if ($values === []) {
            throw new \RuntimeException('resumed TLS handshake timing evidence is unavailable');
        }
        \sort($values, SORT_NUMERIC);
        $index = \max(0, (int)\ceil(\count($values) * 0.95) - 1);

        return (int)$values[$index];
    }

    private function detectEffectiveWorkerCount(int $masterPid): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 1;
        }

        $workers = [];
        if (PHP_OS_FAMILY === 'Linux') {
            $childrenFile = '/proc/' . $masterPid . '/task/' . $masterPid . '/children';
            $children = @\file_get_contents($childrenFile);
            if (\is_string($children)) {
                foreach (\preg_split('/\s+/', \trim($children)) ?: [] as $child) {
                    if (!\ctype_digit($child)) {
                        continue;
                    }
                    $command = @\file_get_contents('/proc/' . $child . '/cmdline');
                    $title = \is_string($command) ? \str_replace("\0", ' ', $command) : '';
                    if (\str_contains(\strtolower($title), 'nginx: worker process')) {
                        $workers[(int)$child] = true;
                    }
                }
            }
        }
        if ($workers !== []) {
            return \count($workers);
        }

        $output = [];
        $code = 1;
        @\exec('ps -axo pid=,ppid=,command= 2>/dev/null', $output, $code);
        if ($code !== 0) {
            return 0;
        }
        foreach ($output as $line) {
            if (\preg_match('/\A\s*([1-9][0-9]*)\s+([1-9][0-9]*)\s+(.+)\z/D', $line, $match) !== 1
                || (int)$match[2] !== $masterPid
                || !\str_contains(\strtolower((string)$match[3]), 'nginx: worker process')
            ) {
                continue;
            }
            $workers[(int)$match[1]] = true;
        }

        return \count($workers);
    }
}
