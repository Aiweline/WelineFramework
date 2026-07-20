<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * Control-plane real QUIC/TLS/HTTP3 loopback verification.
 */
final class NativeTransportSelfTest
{
    /** @var array<string,string> */
    private array $trustedCurlDigests = [];

    /**
     * @param array<string,mixed>|null $candidateManifest Exact manifest returned by NativeTransportCompiler::ensure().
     * @return array{ready:bool,reason:string}
     */
    public function verify(
        string $certificate,
        string $privateKey,
        ?array $candidateManifest = null,
    ): array
    {
        NativeTransportLibrary::reset();
        $manifest = $candidateManifest ?? NativeTransportLibrary::manifest();
        $fingerprint = \strtolower(\trim((string)($manifest['fingerprint'] ?? '')));
        $expectedLibrarySha256 = (string)($manifest['library_sha256'] ?? '');
        if (\preg_match('/^[a-f0-9]{32}$/D', $fingerprint) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $expectedLibrarySha256) !== 1
        ) {
            return ['ready' => false, 'reason' => 'Native HTTP/3 self-test has no immutable library digest.'];
        }
        $verifierSha256 = NativeTransportLibrary::runtimeEvidenceVerifierSha256();
        if (\preg_match('/^[a-f0-9]{64}$/D', $verifierSha256) !== 1) {
            $reason = 'Native HTTP/3 self-test could not snapshot its verifier identity.';
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }
        try {
            $manifest = NativeTransportLibrary::pinSelfTestCandidate($fingerprint, $expectedLibrarySha256);
        } catch (\Throwable $exception) {
            NativeTransportLibrary::reset();
            $reason = 'Native HTTP/3 self-test candidate could not be pinned: ' . $exception->getMessage();
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }
        try {
            $curl = $this->http3Curl($manifest);
        if ($curl === null) {
            $reason = 'No local curl build with HTTP/3 support is available for the mandatory QUIC loopback self-test.';
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }

        $adapter = new Ngtcp2QuicTransportAdapter(true);
        try {
            $adapter->open(
                '127.0.0.1',
                0,
                $certificate,
                $privateKey,
                false,
                [
                    'max_header_bytes' => 65536,
                    'max_body_bytes' => 1048576,
                    'max_connections' => 8,
                    'max_active_streams' => 32,
                    'max_streams_bidi' => 8,
                    'max_idle_timeout_ms' => 5000,
                ],
                \hash('sha256', 'wls-http3-control-plane-self-test', true)
            );
        } catch (\Throwable $exception) {
            $reason = 'Native HTTP/3 loopback listener failed: ' . $exception->getMessage();
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }

        $url = 'https://127.0.0.1:' . $adapter->boundPort() . '/_wls/native-http3-selftest';
        $pipes = [];
        if (!$this->curlIdentityStillTrusted($curl)) {
            $adapter->close();
            $reason = 'HTTP/3 loopback client identity changed before execution.';
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }
        $process = @\proc_open([
            $curl,
            '--disable',
            '-sk',
            '--noproxy',
            '*',
            '--http3-only',
            '--max-time',
            '4',
            $url,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $this->trustedProcessEnvironment(), ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            $adapter->close();
            $reason = 'Unable to start the HTTP/3 loopback client.';
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }
        @\fclose($pipes[0]);
        @\stream_set_blocking($pipes[1], false);
        @\stream_set_blocking($pipes[2], false);

        $deadline = \microtime(true) + 5.0;
        $dispatched = false;
        $exitCode = -1;
        $processRunning = true;
        do {
            try {
                $adapter->poll(5);
                foreach ($adapter->nextRequests(8) as $request) {
                    if (!\str_contains($request['raw_request'], 'GET /_wls/native-http3-selftest HTTP/1.1')) {
                        $adapter->closeRequest($request['token']);
                        continue;
                    }
                    $body = 'wls-http3-selftest-ok';
                    $adapter->respond(
                        $request['token'],
                        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
                        . \strlen($body) . "\r\n\r\n" . $body
                    );
                    $dispatched = true;
                }
            } catch (\Throwable $exception) {
                @\proc_terminate($process, 9);
                $adapter->close();
                $reason = 'HTTP/3 loopback data-plane failed: ' . $exception->getMessage();
                $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
                return ['ready' => false, 'reason' => $reason];
            }
            $status = \proc_get_status($process);
            $processRunning = (bool)($status['running'] ?? false);
            if (!$processRunning) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            SchedulerSystem::usleep(1000);
        } while (\microtime(true) < $deadline);

        $stdout = (string)@\stream_get_contents($pipes[1]);
        $stderr = (string)@\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        if ($processRunning) {
            $status = \proc_get_status($process);
            $processRunning = (bool)($status['running'] ?? false);
            if (!$processRunning) {
                $exitCode = (int)($status['exitcode'] ?? -1);
            }
        }
        if ($processRunning) {
            @\proc_terminate($process, 9);
        }
        $closed = @\proc_close($process);
        if ($exitCode < 0 && \is_int($closed)) {
            $exitCode = $closed;
        }
        $adapter->close();

        $ready = $dispatched && $exitCode === 0 && \trim($stdout) === 'wls-http3-selftest-ok';
        if (!$ready) {
            $reason = 'HTTP/3 loopback self-test failed'
                . ($stderr !== '' ? ': ' . \trim($stderr) : ' (exit=' . $exitCode . ')');
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }

        $ticketProof = $this->verifyTicketRingResumption($certificate, $privateKey, $verifierSha256);
        $ready = $ticketProof['ready'];
        $currentVerifierSha256 = NativeTransportLibrary::runtimeEvidenceVerifierSha256();
        if ($ready && !\hash_equals($verifierSha256, $currentVerifierSha256)) {
            $ready = false;
            $reason = 'HTTP/3 verifier code or runtime identity changed while its self-test was running.';
        } else {
            $reason = $ready
                ? 'Real UDP bind, QUIC TLS 1.3, HTTP/3 request/response, FFI dispatch and independent TLS-context ticket resumption self-tests passed. '
                    . $ticketProof['reason']
                : $ticketProof['reason'];
        }
        if (!$ready) {
            $this->recordFailure($reason, $fingerprint, $expectedLibrarySha256);
            return ['ready' => false, 'reason' => $reason];
        }
        $published = (new NativeTransportCompiler())->markRuntimeVerified(
            true,
            $reason,
            $expectedLibrarySha256,
            $ticketProof['evidence'],
            $fingerprint,
        );
        if (!$published) {
            $this->recordFailure(
                'HTTP/3 runtime evidence publication was rejected because the pinned artifact or verifier identity changed.',
                $fingerprint,
                $expectedLibrarySha256,
            );
            return [
                'ready' => false,
                'reason' => 'HTTP/3 native library, verifier code or runtime identity changed while its loopback self-test was running.',
            ];
        }
            return ['ready' => true, 'reason' => $reason];
        } finally {
            NativeTransportLibrary::reset();
        }
    }

    /**
     * @return array{ready:bool,reason:string,evidence:array<string,bool|int|string>}
     */
    private function verifyTicketRingResumption(
        string $certificate,
        string $privateKey,
        string $verifierSha256,
    ): array
    {
        $phpCurlClient = \extension_loaded('curl')
            && \defined('CURL_VERSION_HTTP3')
            && \defined('CURL_HTTP_VERSION_3ONLY')
            && \defined('CURL_HTTP_VERSION_3')
            && \defined('CURL_LOCK_DATA_SSL_SESSION')
            && (((int)(\curl_version()['features'] ?? 0) & \CURL_VERSION_HTTP3) !== 0);
        $share = null;
        $externalCurl = null;
        $externalCurlSha256 = '';
        $sessionFile = null;
        $sessionDirectory = null;
        if ($phpCurlClient) {
            $share = \curl_share_init();
            if (!\curl_share_setopt($share, \CURLSHOPT_SHARE, \CURL_LOCK_DATA_SSL_SESSION)) {
                \curl_share_close($share);
                return [
                    'ready' => false,
                    'reason' => 'Unable to create the shared TLS-session cache for the ticket-resumption self-test.',
                    'evidence' => [],
                ];
            }
        } else {
            $externalCurl = $this->http3Curl(NativeTransportLibrary::manifest(), true);
            if ($externalCurl === null) {
                return [
                    'ready' => false,
                    'reason' => 'Ticket resumption requires either PHP ext-curl HTTP/3 session sharing or the private HTTP/3 curl with SSLS-EXPORT.',
                    'evidence' => [],
                ];
            }
            $externalCurlSha256 = (string)\hash_file('sha256', $externalCurl);
            if (\preg_match('/^[a-f0-9]{64}$/D', $externalCurlSha256) !== 1) {
                return [
                    'ready' => false,
                    'reason' => 'Unable to snapshot the private HTTP/3 curl identity.',
                    'evidence' => [],
                ];
            }
            $sessionDirectory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
                . 'wls-h3-ticket-' . \getmypid() . '-' . \bin2hex(\random_bytes(8));
            if (!@\mkdir($sessionDirectory, 0700)) {
                return [
                    'ready' => false,
                    'reason' => 'Unable to create the private HTTP/3 ticket-session directory.',
                    'evidence' => [],
                ];
            }
            $sessionFile = $sessionDirectory . \DIRECTORY_SEPARATOR . 'sessions.txt';
        }

        $issuer = null;
        $consumer = null;
        $key0 = \random_bytes(32);
        $key1 = \random_bytes(32);
        $key2 = \random_bytes(32);
        $instanceName = 'wls-native-http3-ticket-self-test';
        $lifetime = 3600;
        $retrySecret = \hash('sha256', 'wls-http3-ticket-ring-self-test-retry', true);
        $limits = [
            'max_header_bytes' => 65536,
            'max_body_bytes' => 1048576,
            'max_connections' => 8,
            'max_active_streams' => 32,
            'max_streams_bidi' => 8,
            'max_idle_timeout_ms' => 5000,
        ];

        try {
            $issuer = new Ngtcp2QuicTransportAdapter(true);
            $issuer->open(
                '127.0.0.1',
                0,
                $certificate,
                $privateKey,
                false,
                $limits,
                $retrySecret,
                null,
                null,
                [
                    'instance_name' => $instanceName,
                    'epoch' => 1,
                    'created_at' => \time(),
                    'rotation_seconds' => $lifetime,
                    'digest' => $this->ticketRingDigest(1, $key1, $key0),
                    'current' => $key1,
                    'previous' => $key0,
                ],
            );
            $port = $issuer->boundPort();
            $firstTransfer = $externalCurl === null
                ? $this->performTicketTransfer($issuer, $share, $port)
                : $this->performExternalTicketTransfer($issuer, $externalCurl, (string)$sessionFile, $port);
            $issuerStats = $issuer->tlsTicketRingStatus();
            $issuer->close();
            $issuer = null;

            $consumer = new Ngtcp2QuicTransportAdapter(true);
            $consumer->open(
                '127.0.0.1',
                $port,
                $certificate,
                $privateKey,
                false,
                $limits,
                $retrySecret,
                null,
                null,
                [
                    'instance_name' => $instanceName,
                    'epoch' => 2,
                    'created_at' => \time(),
                    'rotation_seconds' => $lifetime,
                    'digest' => $this->ticketRingDigest(2, $key2, $key1),
                    'current' => $key2,
                    'previous' => $key1,
                ],
            );
            $secondTransfer = $externalCurl === null
                ? $this->performTicketTransfer($consumer, $share, $port)
                : $this->performExternalTicketTransfer($consumer, $externalCurl, (string)$sessionFile, $port);
            $consumerStats = $consumer->tlsTicketRingStatus();

            $ready = $firstTransfer['ready']
                && $secondTransfer['ready']
                && (bool)($issuerStats['active'] ?? false)
                && (bool)($issuerStats['early_data_disabled'] ?? false)
                && (int)($issuerStats['epoch'] ?? 0) === 1
                && (int)($issuerStats['full_handshakes'] ?? 0) >= 1
                && (int)($issuerStats['resumed_handshakes'] ?? 0) === 0
                && (int)($issuerStats['tickets_encrypted'] ?? 0) >= 1
                && (int)($issuerStats['ticket_errors'] ?? 0) === 0
                && (bool)($consumerStats['active'] ?? false)
                && (bool)($consumerStats['early_data_disabled'] ?? false)
                && (int)($consumerStats['epoch'] ?? 0) === 2
                && (int)($consumerStats['resumed_handshakes'] ?? 0) >= 1
                && (int)($consumerStats['tickets_decrypted_previous'] ?? 0) >= 1
                && (int)($consumerStats['tickets_rejected'] ?? 0) === 0
                && (int)($consumerStats['ticket_errors'] ?? 0) === 0
                && ($externalCurl === null
                    || (\is_file($externalCurl)
                        && \hash_equals($externalCurlSha256, (string)\hash_file('sha256', $externalCurl))));
            if (!$ready) {
                return [
                    'ready' => false,
                    'reason' => 'HTTP/3 TLS ticket-resumption proof failed: issuer=' . \json_encode($issuerStats)
                        . '; consumer=' . \json_encode($consumerStats)
                        . '; transfers=' . \json_encode([$firstTransfer, $secondTransfer]),
                    'evidence' => [],
                ];
            }

            return [
                'ready' => true,
                'reason' => 'A fresh TLS context resumed the first context\'s session through the rotated previous ticket key; 0-RTT remained disabled.',
                'evidence' => [
                    'schema' => NativeTransportLibrary::RUNTIME_EVIDENCE_SCHEMA,
                    'verifier_sha256' => $verifierSha256,
                    'integration_sha256' => NativeTransportLibrary::productionIntegrationSha256(),
                    'ticket_client' => $externalCurl === null
                        ? 'php_ext_curl_share'
                        : 'external_curl_ssls_export',
                    'ticket_client_sha256' => $externalCurlSha256,
                    'quic_loopback' => true,
                    'tls_ticket_ring_cross_context' => true,
                    'tls_ticket_previous_key_resumption' => true,
                    'tls_session_resumption' => true,
                    'early_data_disabled' => true,
                    'issuer_full_handshakes' => (int)$issuerStats['full_handshakes'],
                    'issuer_tickets_encrypted' => (int)$issuerStats['tickets_encrypted'],
                    'consumer_resumed_handshakes' => (int)$consumerStats['resumed_handshakes'],
                    'consumer_tickets_decrypted_previous' => (int)$consumerStats['tickets_decrypted_previous'],
                    'first_http_version' => (int)$firstTransfer['http_version'],
                    'second_http_version' => (int)$secondTransfer['http_version'],
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'ready' => false,
                'reason' => 'HTTP/3 TLS ticket-resumption self-test failed: ' . $exception->getMessage(),
                'evidence' => [],
            ];
        } finally {
            if ($issuer instanceof Ngtcp2QuicTransportAdapter) {
                $issuer->close();
            }
            if ($consumer instanceof Ngtcp2QuicTransportAdapter) {
                $consumer->close();
            }
            if ($share !== null) {
                \curl_share_close($share);
            }
            if (\is_string($sessionFile) && \is_file($sessionFile)) {
                $size = (int)@\filesize($sessionFile);
                if ($size > 0) {
                    @\file_put_contents($sessionFile, \str_repeat("\0", $size), \LOCK_EX);
                }
                @\unlink($sessionFile);
            }
            if (\is_string($sessionDirectory) && \is_dir($sessionDirectory)) {
                @\rmdir($sessionDirectory);
            }
            if (\function_exists('sodium_memzero')) {
                \sodium_memzero($key0);
                \sodium_memzero($key1);
                \sodium_memzero($key2);
                \sodium_memzero($retrySecret);
            }
        }
    }

    private function ticketRingDigest(int $epoch, string $current, string $previous): string
    {
        return \hash('sha256', "wls-http3-ticket-ring-self-test\0" . $epoch . $current . $previous);
    }

    /**
     * @return array{ready:bool,http_version:int,appconnect_time_us:int}
     */
    private function performTicketTransfer(
        Ngtcp2QuicTransportAdapter $adapter,
        mixed $share,
        int $port,
    ): array {
        $host = 'wls-native-http3-ticket.test';
        $path = '/_wls/native-http3-ticket-selftest';
        $easy = \curl_init('https://' . $host . ':' . $port . $path);
        $multi = \curl_multi_init();
        $added = false;
        try {
            $clientPort = $this->reserveClientUdpPort($port);
            $options = [
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_3ONLY,
                \CURLOPT_SSL_VERIFYPEER => false,
                \CURLOPT_SSL_VERIFYHOST => 0,
                \CURLOPT_CONNECTTIMEOUT_MS => 2000,
                \CURLOPT_TIMEOUT_MS => 5000,
                \CURLOPT_FRESH_CONNECT => true,
                \CURLOPT_FORBID_REUSE => true,
                \CURLOPT_RESOLVE => [$host . ':' . $port . ':127.0.0.1'],
                \CURLOPT_PROXY => '',
                \CURLOPT_NOPROXY => '*',
                \CURLOPT_SHARE => $share,
                \CURLOPT_LOCALPORT => $clientPort,
                \CURLOPT_LOCALPORTRANGE => 1,
                \CURLOPT_NOSIGNAL => true,
            ];
            if (\defined('CURLOPT_SSL_SESSIONID_CACHE')) {
                $options[\CURLOPT_SSL_SESSIONID_CACHE] = true;
            }
            if (!\curl_setopt_array($easy, $options)) {
                throw new \RuntimeException('unable to configure the HTTP/3 ticket client');
            }
            if (\curl_multi_add_handle($multi, $easy) !== \CURLM_OK) {
                throw new \RuntimeException('unable to schedule the HTTP/3 ticket client');
            }
            $added = true;

            $deadline = \microtime(true) + 6.0;
            $running = 1;
            $completed = false;
            $resultCode = \CURLE_OK;
            $dispatched = false;
            do {
                do {
                    $multiCode = \curl_multi_exec($multi, $running);
                } while (\defined('CURLM_CALL_MULTI_PERFORM') && $multiCode === \CURLM_CALL_MULTI_PERFORM);
                if ($multiCode !== \CURLM_OK) {
                    throw new \RuntimeException('HTTP/3 ticket client multi error ' . $multiCode);
                }

                $adapter->poll(2);
                foreach ($adapter->nextRequests(8) as $request) {
                    if (!\str_contains($request['raw_request'], 'GET ' . $path . ' HTTP/1.1')) {
                        $adapter->closeRequest($request['token']);
                        continue;
                    }
                    $body = 'wls-http3-ticket-selftest-ok';
                    $adapter->respond(
                        $request['token'],
                        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
                        . \strlen($body) . "\r\n\r\n" . $body,
                    );
                    $dispatched = true;
                }

                while (($info = \curl_multi_info_read($multi)) !== false) {
                    if (($info['handle'] ?? null) !== $easy) {
                        continue;
                    }
                    $completed = true;
                    $resultCode = (int)($info['result'] ?? \CURLE_OK);
                }
                if ($completed && $running === 0) {
                    break;
                }
                if ($running > 0) {
                    $selected = \curl_multi_select($multi, 0.001);
                    if ($selected < 0) {
                        SchedulerSystem::usleep(1000);
                    }
                } else {
                    SchedulerSystem::usleep(1000);
                }
            } while (\microtime(true) < $deadline);

            $body = (string)\curl_multi_getcontent($easy);
            $httpCode = (int)\curl_getinfo($easy, \CURLINFO_RESPONSE_CODE);
            $httpVersion = (int)\curl_getinfo($easy, \CURLINFO_HTTP_VERSION);
            $appConnectUs = \defined('CURLINFO_APPCONNECT_TIME_T')
                ? (int)\curl_getinfo($easy, \CURLINFO_APPCONNECT_TIME_T)
                : (int)\round((float)\curl_getinfo($easy, \CURLINFO_APPCONNECT_TIME) * 1_000_000);
            $ready = $completed
                && $resultCode === \CURLE_OK
                && \curl_errno($easy) === \CURLE_OK
                && $dispatched
                && $httpCode === 200
                && $httpVersion === \CURL_HTTP_VERSION_3
                && $body === 'wls-http3-ticket-selftest-ok';
            if (!$ready) {
                throw new \RuntimeException('HTTP/3 ticket transfer failed: result=' . $resultCode
                    . ', errno=' . \curl_errno($easy)
                    . ', error=' . \curl_error($easy)
                    . ', status=' . $httpCode
                    . ', version=' . $httpVersion);
            }
            return [
                'ready' => true,
                'http_version' => $httpVersion,
                'appconnect_time_us' => $appConnectUs,
            ];
        } finally {
            if ($added) {
                \curl_multi_remove_handle($multi, $easy);
            }
            \curl_close($easy);
            \curl_multi_close($multi);
            unset($easy, $multi, $info);
        }
    }

    /**
     * @return array{ready:bool,http_version:int,appconnect_time_us:int}
     */
    private function performExternalTicketTransfer(
        Ngtcp2QuicTransportAdapter $adapter,
        string $curl,
        string $sessionFile,
        int $port,
    ): array {
        if (!$this->curlIdentityStillTrusted($curl)) {
            throw new \RuntimeException('external HTTP/3 ticket client identity changed before execution');
        }
        $host = 'wls-native-http3-ticket.test';
        $path = '/_wls/native-http3-ticket-selftest';
        $url = 'https://' . $host . ':' . $port . $path;
        $pipes = [];
        $process = @\proc_open([
            $curl,
            '--disable',
            '--silent',
            '--show-error',
            '--insecure',
            '--noproxy',
            '*',
            '--http3-only',
            '--ssl-sessions',
            $sessionFile,
            '--resolve',
            $host . ':' . $port . ':127.0.0.1',
            '--connect-timeout',
            '2',
            '--max-time',
            '6',
            '--write-out',
            "\nWLS_TICKET_INFO:%{http_code}:%{http_version}:%{time_appconnect}\n",
            $url,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $this->trustedProcessEnvironment(), ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('unable to start the external HTTP/3 ticket client');
        }
        @\fclose($pipes[0]);
        @\stream_set_blocking($pipes[1], false);
        @\stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $dispatched = false;
        $processRunning = true;
        $deadline = \microtime(true) + 8.0;
        try {
            do {
                $adapter->poll(2);
                foreach ($adapter->nextRequests(8) as $request) {
                    if (!\str_contains($request['raw_request'], 'GET ' . $path . ' HTTP/1.1')) {
                        $adapter->closeRequest($request['token']);
                        continue;
                    }
                    $body = 'wls-http3-ticket-selftest-ok';
                    $adapter->respond(
                        $request['token'],
                        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
                            . \strlen($body) . "\r\n\r\n" . $body,
                    );
                    $dispatched = true;
                }
                $stdout .= (string)@\stream_get_contents($pipes[1]);
                $stderr .= (string)@\stream_get_contents($pipes[2]);
                $status = \proc_get_status($process);
                $processRunning = (bool)($status['running'] ?? false);
                if (!$processRunning) {
                    $exitCode = (int)($status['exitcode'] ?? -1);
                    break;
                }
                SchedulerSystem::usleep(1000);
            } while (\microtime(true) < $deadline);

            $stdout .= (string)@\stream_get_contents($pipes[1]);
            $stderr .= (string)@\stream_get_contents($pipes[2]);
            if ($processRunning) {
                @\proc_terminate($process, 9);
            }
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            $closed = @\proc_close($process);
            if ($exitCode < 0 && \is_int($closed)) {
                $exitCode = $closed;
            }

            $marker = "\nWLS_TICKET_INFO:";
            $markerAt = \strrpos($stdout, $marker);
            $body = $markerAt === false ? '' : \substr($stdout, 0, $markerAt);
            $info = $markerAt === false ? '' : \trim(\substr($stdout, $markerAt + 1));
            $matched = \preg_match(
                '/^WLS_TICKET_INFO:(\d+):(3(?:\.0)?):([0-9]+(?:\.[0-9]+)?)$/D',
                $info,
                $captures,
            ) === 1;
            $sessionBytes = \is_file($sessionFile) ? (int)@\filesize($sessionFile) : 0;
            if ($sessionBytes > 0) {
                @\chmod($sessionFile, 0600);
            }
            $ready = !$processRunning
                && $exitCode === 0
                && $dispatched
                && $matched
                && (int)($captures[1] ?? 0) === 200
                && $body === 'wls-http3-ticket-selftest-ok'
                && $sessionBytes > 0;
            if (!$ready) {
                throw new \RuntimeException('external HTTP/3 ticket client failed: exit=' . $exitCode
                    . ', dispatched=' . ($dispatched ? 'yes' : 'no')
                    . ', session_bytes=' . $sessionBytes
                    . ', stdout=' . \trim($stdout)
                    . ', stderr=' . \trim($stderr));
            }
            return [
                'ready' => true,
                'http_version' => 30,
                'appconnect_time_us' => (int)\round((float)$captures[3] * 1_000_000),
            ];
        } finally {
            if (\is_resource($pipes[1] ?? null)) {
                @\fclose($pipes[1]);
            }
            if (\is_resource($pipes[2] ?? null)) {
                @\fclose($pipes[2]);
            }
            if (\is_resource($process)) {
                $status = @\proc_get_status($process);
                if ((bool)($status['running'] ?? false)) {
                    @\proc_terminate($process, 9);
                }
                @\proc_close($process);
            }
        }
    }

    private function reserveClientUdpPort(int $serverPort): int
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $errorCode = 0;
            $errorMessage = '';
            $socket = @\stream_socket_server(
                'udp://127.0.0.1:0',
                $errorCode,
                $errorMessage,
                \STREAM_SERVER_BIND,
            );
            if (!\is_resource($socket)) {
                continue;
            }
            $name = (string)@\stream_socket_get_name($socket, false);
            @\fclose($socket);
            $separator = \strrpos($name, ':');
            $port = $separator === false ? 0 : (int)\substr($name, $separator + 1);
            if ($port > 0 && $port !== $serverPort) {
                return $port;
            }
        }
        throw new \RuntimeException('unable to reserve a distinct local UDP port for the HTTP/3 ticket client');
    }

    /** @param array<string,mixed> $manifest */
    private function http3Curl(array $manifest, bool $requireSessionExport = false): ?string
    {
        $candidates = [];
        $manifestCurl = (string)($manifest['http3_curl'] ?? '');
        $manifestCurlSha256 = \strtolower(\trim((string)($manifest['http3_curl_sha256'] ?? '')));
        if ($manifestCurl !== '') {
            $candidates[$manifestCurl] = $manifestCurlSha256;
        }
        if (!$requireSessionExport) {
            foreach ([
                '/opt/homebrew/opt/curl/bin/curl',
                '/usr/local/opt/curl/bin/curl',
                '/usr/bin/curl',
            ] as $candidate) {
                $candidates[$candidate] ??= '';
            }
        }
        foreach ($candidates as $candidate => $expectedSha256) {
            $trusted = $this->trustedExecutable($candidate, $expectedSha256);
            if ($trusted === null) {
                continue;
            }
            [$candidate, $actualSha256] = $trusted;
            $pipes = [];
            $process = @\proc_open([$candidate, '--disable', '--version'], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $this->trustedProcessEnvironment(), ['bypass_shell' => true]);
            if (!\is_resource($process)) {
                continue;
            }
            @\fclose($pipes[0]);
            $output = (string)@\stream_get_contents($pipes[1])
                . (string)@\stream_get_contents($pipes[2]);
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            @\proc_close($process);
            if (\preg_match('/Features:.*\bHTTP3\b/i', $output) === 1
                && (!$requireSessionExport
                    || \preg_match('/Features:.*\bSSLS-EXPORT\b/i', $output) === 1)
            ) {
                $this->trustedCurlDigests[$candidate] = $actualSha256;
                return $candidate;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string}|null */
    private function trustedExecutable(string $candidate, string $expectedSha256 = ''): ?array
    {
        if ($candidate === '' || $candidate[0] !== \DIRECTORY_SEPARATOR) {
            return null;
        }
        $real = \realpath($candidate);
        $stat = \is_string($real) ? @\lstat($real) : false;
        if (!\is_string($real)
            || !\is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (((int)($stat['mode'] ?? 0)) & 0022) !== 0
            || !\is_executable($real)
        ) {
            return null;
        }
        if (\function_exists('posix_geteuid')) {
            $owner = (int)($stat['uid'] ?? -1);
            if ($owner !== 0 && $owner !== (int)\posix_geteuid()) {
                return null;
            }
        }
        $actualSha256 = (string)\hash_file('sha256', $real);
        if (\preg_match('/^[a-f0-9]{64}$/D', $actualSha256) !== 1
            || ($expectedSha256 !== ''
                && (\preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1
                    || !\hash_equals($expectedSha256, $actualSha256)))
        ) {
            return null;
        }
        return [$real, $actualSha256];
    }

    private function curlIdentityStillTrusted(string $curl): bool
    {
        $expectedSha256 = (string)($this->trustedCurlDigests[$curl] ?? '');
        $trusted = $this->trustedExecutable($curl, $expectedSha256);
        return $expectedSha256 !== '' && $trusted !== null;
    }

    /** @return array<string,string> */
    private function trustedProcessEnvironment(): array
    {
        return [
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ];
    }

    private function recordFailure(string $reason, string $fingerprint, string $librarySha256): void
    {
        (new NativeTransportCompiler())->recordRuntimeVerificationFailure(
            $reason,
            $librarySha256,
            $fingerprint,
        );
    }
}
