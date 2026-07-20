<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Separates ALPN configuration support from live negotiation evidence.
 *
 * Accepting an ssl context option does not prove that a server handshake
 * negotiated the requested protocol. Runtime verification therefore remains
 * false until a live TLS handshake reports the selected ALPN protocol.
 */
final class TlsAlpnRuntimeProbe
{
    public const SERVER_PROTOCOLS = 'h2,http/1.1';

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $deadlineBudgetMs = 500;
        $configured = $this->configured();
        $snapshot = [
            'configured' => $configured,
            'runtime_verified' => false,
            'tls13_runtime_verified' => false,
            'verification_source' => 'live_tls_handshake_required',
            'offered_protocols' => ['h2', 'http/1.1'],
            'negotiated_protocols' => [],
            'tls_protocols' => [],
            'deadline_budget_ms' => $deadlineBudgetMs,
            'handshake_mode' => 'parallel_shared_listener',
            'reason' => $configured
                ? 'The PHP stream context accepts ALPN configuration; live server handshakes are still required.'
                : 'The PHP stream context does not accept the required ALPN configuration.',
        ];
        if (!$configured) {
            return $snapshot;
        }
        if (!\extension_loaded('openssl')) {
            $snapshot['reason'] = 'Live ALPN verification is unavailable because the OpenSSL extension is missing.';
            return $snapshot;
        }

        $requiredFunctions = [
            'openssl_pkey_new',
            'openssl_csr_new',
            'openssl_csr_sign',
            'openssl_x509_export',
            'openssl_pkey_export',
            'stream_socket_server',
            'stream_socket_client',
            'stream_socket_accept',
            'stream_socket_enable_crypto',
        ];
        foreach ($requiredFunctions as $function) {
            if (!\function_exists($function)) {
                $snapshot['reason'] = 'Live ALPN verification is unavailable because ' . $function . '() is missing.';
                return $snapshot;
            }
        }
        if (!\defined('OPENSSL_KEYTYPE_EC')
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')
        ) {
            $snapshot['reason'] = 'Live ALPN verification is unavailable because TLS 1.3 stream crypto is missing.';
            return $snapshot;
        }

        $configPath = null;
        $certificatePath = null;
        try {
            $configPath = @\tempnam(\sys_get_temp_dir(), 'wls-alpn-conf-');
            $opensslConfig = <<<'OPENSSL_CONFIG'
[ req ]
distinguished_name = req_distinguished_name
prompt = no
default_md = sha256
req_extensions = v3_req

[ req_distinguished_name ]
CN = localhost

[ v3_req ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = localhost
IP.1 = 127.0.0.1
OPENSSL_CONFIG;
            if (!\is_string($configPath) || $configPath === '') {
                throw new \RuntimeException('Unable to reserve the ephemeral ALPN probe OpenSSL configuration.');
            }
            @\chmod($configPath, 0600);
            if (@\file_put_contents($configPath, $opensslConfig, LOCK_EX) !== \strlen($opensslConfig)) {
                throw new \RuntimeException('Unable to publish the ephemeral ALPN probe OpenSSL configuration.');
            }

            $keyOptions = [
                'config' => $configPath,
                'private_key_type' => \OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
            ];
            $requestOptions = $keyOptions + [
                'digest_alg' => 'sha256',
                'req_extensions' => 'v3_req',
            ];
            $signOptions = [
                'config' => $configPath,
                'digest_alg' => 'sha256',
                'x509_extensions' => 'v3_req',
            ];

            $privateKey = @\openssl_pkey_new($keyOptions);
            $csr = $privateKey !== false
                ? @\openssl_csr_new(['commonName' => 'localhost'], $privateKey, $requestOptions)
                : false;
            $certificate = $csr !== false && $privateKey !== false
                ? @\openssl_csr_sign($csr, null, $privateKey, 1, $signOptions)
                : false;
            $certificatePem = '';
            $privateKeyPem = '';
            if ($certificate === false
                || $privateKey === false
                || !@\openssl_x509_export($certificate, $certificatePem)
                || !@\openssl_pkey_export($privateKey, $privateKeyPem, null, $keyOptions)
            ) {
                throw new \RuntimeException('Unable to create the ephemeral ALPN probe certificate.');
            }

            $certificatePath = @\tempnam(\sys_get_temp_dir(), 'wls-alpn-cert-');
            $pem = $certificatePem . $privateKeyPem;
            if (!\is_string($certificatePath) || $certificatePath === '') {
                throw new \RuntimeException('Unable to reserve the ephemeral ALPN probe certificate.');
            }
            @\chmod($certificatePath, 0600);
            if (@\file_put_contents($certificatePath, $pem, LOCK_EX) !== \strlen($pem)) {
                throw new \RuntimeException('Unable to publish the ephemeral ALPN probe certificate.');
            }

            $serverCryptoMethod = (int)\constant('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER');
            $clientCryptoMethod = (int)\constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
            $probeDeadline = \microtime(true) + ($deadlineBudgetMs / 1000);
            $connectionSpecs = [
                'preferred' => [
                    'client_protocols' => self::SERVER_PROTOCOLS,
                    'expected_alpn' => 'h2',
                ],
                'fallback' => [
                    'client_protocols' => 'http/1.1',
                    'expected_alpn' => 'http/1.1',
                ],
            ];
            $results = [
                'preferred' => ['ok' => false, 'reason' => 'tls_probe_not_started'],
                'fallback' => ['ok' => false, 'reason' => 'tls_probe_not_started'],
            ];
            $listener = null;
            $pairs = [];

            try {
                if (\microtime(true) >= $probeDeadline) {
                    throw new \RuntimeException('tls_probe_total_deadline');
                }
                $serverContext = \stream_context_create(['ssl' => [
                    'local_cert' => $certificatePath,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'disable_compression' => true,
                    'crypto_method' => $serverCryptoMethod,
                    'alpn_protocols' => self::SERVER_PROTOCOLS,
                ]]);
                $listener = @\stream_socket_server(
                    'tcp://127.0.0.1:0',
                    $errorCode,
                    $errorMessage,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $serverContext
                );
                if (!\is_resource($listener)) {
                    throw new \RuntimeException('loopback_listen_failed:' . $errorCode);
                }
                $address = (string)@\stream_socket_get_name($listener, false);
                if ($address === '') {
                    throw new \RuntimeException('loopback_address_unavailable');
                }

                foreach ($connectionSpecs as $name => $spec) {
                    $remaining = $probeDeadline - \microtime(true);
                    if ($remaining <= 0.0) {
                        $results[$name]['reason'] = 'tls_probe_total_deadline';
                        continue;
                    }
                    $clientContext = \stream_context_create(['ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                        'disable_compression' => true,
                        'crypto_method' => $clientCryptoMethod,
                        'alpn_protocols' => $spec['client_protocols'],
                    ]]);
                    $client = @\stream_socket_client(
                        'tcp://' . $address,
                        $errorCode,
                        $errorMessage,
                        \min(0.1, $remaining),
                        STREAM_CLIENT_CONNECT,
                        $clientContext
                    );
                    $remaining = $probeDeadline - \microtime(true);
                    $serverConnection = $remaining > 0.0
                        ? @\stream_socket_accept($listener, \min(0.1, $remaining))
                        : false;
                    if (!\is_resource($client) || !\is_resource($serverConnection)) {
                        if (\is_resource($serverConnection)) {
                            @\fclose($serverConnection);
                        }
                        if (\is_resource($client)) {
                            @\fclose($client);
                        }
                        $results[$name]['reason'] = $remaining > 0.0
                            ? 'loopback_accept_failed:' . $errorCode
                            : 'tls_probe_total_deadline';
                        continue;
                    }

                    \stream_set_blocking($client, false);
                    \stream_set_blocking($serverConnection, false);
                    $pairs[$name] = [
                        'client' => $client,
                        'server' => $serverConnection,
                        'client_state' => 0,
                        'server_state' => 0,
                        'done' => false,
                    ];
                    $results[$name]['reason'] = 'tls_handshake_deadline';
                }

                while (\microtime(true) < $probeDeadline) {
                    $read = [];
                    $unfinished = 0;
                    foreach ($pairs as $name => &$pair) {
                        if ($pair['done']) {
                            continue;
                        }
                        if ($pair['client_state'] !== true) {
                            $pair['client_state'] = @\stream_socket_enable_crypto(
                                $pair['client'],
                                true,
                                $clientCryptoMethod
                            );
                        }
                        if ($pair['server_state'] !== true) {
                            $pair['server_state'] = @\stream_socket_enable_crypto(
                                $pair['server'],
                                true,
                                $serverCryptoMethod
                            );
                        }
                        if ($pair['client_state'] === false || $pair['server_state'] === false) {
                            $results[$name] = ['ok' => false, 'reason' => 'tls_handshake_rejected'];
                            $pair['done'] = true;
                            continue;
                        }
                        if ($pair['client_state'] === true && $pair['server_state'] === true) {
                            $clientCrypto = (array)(\stream_get_meta_data($pair['client'])['crypto'] ?? []);
                            $serverCrypto = (array)(\stream_get_meta_data($pair['server'])['crypto'] ?? []);
                            $clientAlpn = (string)($clientCrypto['alpn_protocol'] ?? '');
                            $serverAlpn = (string)($serverCrypto['alpn_protocol'] ?? '');
                            if ($clientAlpn === ''
                                || $clientAlpn !== $serverAlpn
                                || $serverAlpn !== $connectionSpecs[$name]['expected_alpn']
                            ) {
                                $results[$name] = [
                                    'ok' => false,
                                    'reason' => 'selected_alpn_unavailable_or_mismatched',
                                ];
                            } else {
                                $results[$name] = [
                                    'ok' => true,
                                    'alpn' => $serverAlpn,
                                    'tls' => (string)($serverCrypto['protocol'] ?? ''),
                                ];
                            }
                            $pair['done'] = true;
                            continue;
                        }

                        $unfinished++;
                        $read[] = $pair['client'];
                        $read[] = $pair['server'];
                    }
                    unset($pair);

                    if ($unfinished === 0) {
                        break;
                    }
                    $remainingMicros = (int)\max(
                        0,
                        \min(20000, ($probeDeadline - \microtime(true)) * 1000000)
                    );
                    if ($remainingMicros <= 0 || $read === []) {
                        break;
                    }
                    $write = [];
                    $except = [];
                    @\stream_select($read, $write, $except, 0, $remainingMicros);
                }
            } catch (\Throwable $exception) {
                foreach ($results as $name => $result) {
                    if (($result['reason'] ?? '') === 'tls_probe_not_started') {
                        $results[$name]['reason'] = $exception->getMessage();
                    }
                }
            } finally {
                foreach ($pairs as $pair) {
                    if (\is_resource($pair['server'])) {
                        @\fclose($pair['server']);
                    }
                    if (\is_resource($pair['client'])) {
                        @\fclose($pair['client']);
                    }
                }
                if (\is_resource($listener)) {
                    @\fclose($listener);
                }
            }

            $http2 = $results['preferred'];
            $http1 = $results['fallback'];
            $runtimeVerified = (bool)($http2['ok'] ?? false)
                && (string)($http2['alpn'] ?? '') === 'h2'
                && (bool)($http1['ok'] ?? false)
                && (string)($http1['alpn'] ?? '') === 'http/1.1';
            $snapshot['runtime_verified'] = $runtimeVerified;
            $snapshot['tls13_runtime_verified'] = $runtimeVerified
                && (string)($http2['tls'] ?? '') === 'TLSv1.3'
                && (string)($http1['tls'] ?? '') === 'TLSv1.3';
            $snapshot['verification_source'] = 'php_stream_loopback_server_handshake';
            $snapshot['negotiated_protocols'] = [
                'preferred' => (string)($http2['alpn'] ?? ''),
                'fallback' => (string)($http1['alpn'] ?? ''),
            ];
            $snapshot['tls_protocols'] = [
                'preferred' => (string)($http2['tls'] ?? ''),
                'fallback' => (string)($http1['tls'] ?? ''),
            ];
            $snapshot['reason'] = $runtimeVerified
                ? 'Two parallel bounded PHP stream handshakes negotiated h2 and the http/1.1 fallback.'
                : 'Live ALPN verification failed: preferred=' . (string)($http2['reason'] ?? $http2['alpn'] ?? 'unknown')
                    . '; fallback=' . (string)($http1['reason'] ?? $http1['alpn'] ?? 'unknown');
        } catch (\Throwable $exception) {
            $snapshot['verification_source'] = 'php_stream_loopback_server_handshake';
            $snapshot['reason'] = 'Live ALPN verification failed: ' . $exception->getMessage();
        } finally {
            if (\is_string($certificatePath) && $certificatePath !== '') {
                @\unlink($certificatePath);
            }
            if (\is_string($configPath) && $configPath !== '') {
                @\unlink($configPath);
            }
        }

        return $snapshot;
    }

    public function configured(): bool
    {
        try {
            $context = \stream_context_create([
                'ssl' => ['alpn_protocols' => self::SERVER_PROTOCOLS],
            ]);
            $options = \stream_context_get_options($context);

            return (($options['ssl']['alpn_protocols'] ?? null) === self::SERVER_PROTOCOLS);
        } catch (\Throwable) {
            return false;
        }
    }
}
