<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Builds one long-lived EventSslContext per Worker process.
 *
 * The caller must retain the returned context for the complete Worker lifetime.
 * Creating a context for every accepted connection discards the OpenSSL session
 * cache and ticket keys, preventing TLS session resumption.
 */
final class EventTlsContextFactory
{
    public const TLS_1_3_VERSION = 0x0304;

    private const OPENSSL_TLS_1_3_MIN_VERSION = 0x10101000;
    private const DEFAULT_TLS_1_2_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:'
        . 'ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:'
        . 'ECDHE-RSA-CHACHA20-POLY1305:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:'
        . 'ECDHE-RSA-AES256-GCM-SHA384:'
        . '!aNULL:!eNULL:!MD5:!RC4:!DES:!3DES:!DSS:!SHA1:!DHE';

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        $eventLoaded = \extension_loaded('event');
        $contextAvailable = \class_exists(\EventSslContext::class);
        $bufferEventAvailable = \class_exists(\EventBufferEvent::class)
            && \method_exists(\EventBufferEvent::class, 'sslSocket');
        $protocolBoundsAvailable = $contextAvailable
            && \method_exists(\EventSslContext::class, 'setMinProtoVersion')
            && \method_exists(\EventSslContext::class, 'setMaxProtoVersion');
        $opensslVersionNumber = $contextAvailable
            && \defined('EventSslContext::OPENSSL_VERSION_NUMBER')
            ? (int)\constant('EventSslContext::OPENSSL_VERSION_NUMBER')
            : 0;
        $opensslVersionText = $contextAvailable
            && \defined('EventSslContext::OPENSSL_VERSION_TEXT')
            ? (string)\constant('EventSslContext::OPENSSL_VERSION_TEXT')
            : '';
        $tls13Available = $protocolBoundsAvailable
            && $opensslVersionNumber >= self::OPENSSL_TLS_1_3_MIN_VERSION;
        $available = $eventLoaded
            && $contextAvailable
            && $bufferEventAvailable
            && $protocolBoundsAvailable
            && $tls13Available;

        if (!$eventLoaded) {
            $reason = 'ext-event is not loaded';
        } elseif (!$contextAvailable || !$bufferEventAvailable) {
            $reason = 'ext-event was built without the required OpenSSL buffer-event API';
        } elseif (!$protocolBoundsAvailable) {
            $reason = 'EventSslContext does not expose min/max protocol bounds';
        } elseif (!$tls13Available) {
            $reason = 'EventSslContext is not linked to an OpenSSL version with TLS 1.3 support';
        } else {
            $reason = 'one retained EventSslContext can serve multiple TLS 1.2/1.3 connections in this Worker';
        }

        return [
            'available' => $available,
            'transport' => 'event_buffer',
            'extension_version' => ($version = \phpversion('event')) !== false ? $version : null,
            'openssl_version_number' => $opensslVersionNumber,
            'openssl_version_text' => $opensslVersionText,
            'tls' => [
                'tls13_supported' => $tls13Available,
                'tls12_fallback_supported' => $contextAvailable
                    && \defined('EventSslContext::TLS1_2_VERSION'),
                'minimum' => 'TLSv1.2',
                'maximum' => 'TLSv1.3',
                'preference' => 'highest mutually supported protocol',
            ],
            'context' => [
                'scope' => 'worker_process',
                'retain_for_worker_lifetime' => true,
                'one_ssl_handle_per_connection' => $bufferEventAvailable,
            ],
            'session_resumption' => [
                'same_context_eligible' => $available,
                'runtime_probe_required' => true,
                'cross_worker' => false,
                'across_restart' => false,
                'session_cache_control' => false,
                'ticket_key_rotation' => false,
                'session_reused_telemetry' => false,
            ],
            'alpn' => [
                'configurable' => false,
                'selected_protocol_visible' => false,
                'reason' => 'EventSslContext 3.x exposes neither ALPN selection nor selected-ALPN accessors',
            ],
            'http2_ready' => false,
            'reason' => $reason,
        ];
    }

    public function createServerContext(
        string $certificatePath,
        string $privateKeyPath,
        ?string $tls12Ciphers = null,
    ): \EventSslContext {
        $capabilities = $this->capabilities();
        if (!(bool)$capabilities['available']) {
            throw new \RuntimeException(
                'WLS Event TLS context is unavailable: ' . (string)$capabilities['reason']
            );
        }

        $this->assertReadablePem($certificatePath, 'certificate');
        $this->assertReadablePem($privateKeyPath, 'private key');
        $tls12Ciphers = \trim((string)$tls12Ciphers);
        if ($tls12Ciphers === '') {
            $tls12Ciphers = self::DEFAULT_TLS_1_2_CIPHERS;
        }

        $context = new \EventSslContext(\EventSslContext::TLS_SERVER_METHOD, [
            \EventSslContext::OPT_LOCAL_CERT => $certificatePath,
            \EventSslContext::OPT_LOCAL_PK => $privateKeyPath,
            \EventSslContext::OPT_VERIFY_PEER => false,
            \EventSslContext::OPT_CIPHERS => $tls12Ciphers,
            \EventSslContext::OPT_CIPHER_SERVER_PREFERENCE => true,
        ]);
        if ($context->setMinProtoVersion(\EventSslContext::TLS1_2_VERSION) !== true) {
            throw new \RuntimeException('Unable to set the WLS Event TLS minimum protocol to TLS 1.2.');
        }
        if ($context->setMaxProtoVersion(self::TLS_1_3_VERSION) !== true) {
            throw new \RuntimeException('Unable to set the WLS Event TLS maximum protocol to TLS 1.3.');
        }

        return $context;
    }

    private function assertReadablePem(string $path, string $label): void
    {
        if ($path === '' || !\is_file($path) || !\is_readable($path)) {
            throw new \RuntimeException('WLS Event TLS ' . $label . ' is not a readable file: ' . $path);
        }
    }
}
