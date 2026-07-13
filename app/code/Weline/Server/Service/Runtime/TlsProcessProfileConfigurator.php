<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

/**
 * Configures OpenSSL before WLS child PHP processes are spawned.
 *
 * PHP streams expose protocol/cipher controls but not the TLS 1.3 supported
 * groups list. The performance profile therefore uses OpenSSL's process-level
 * SSL_CONF hook. It remains opt-out and never overwrites an operator supplied
 * OPENSSL_CONF.
 */
final class TlsProcessProfileConfigurator
{
    public const PROFILE_PERFORMANCE = 'performance';
    public const PROFILE_SYSTEM = 'system';

    /**
     * @param array<string, mixed> $config
     * @return array{requested:string,effective:string,openssl_conf:?string,reason:string}
     */
    public function activate(array $config, bool $sslEnabled): array
    {
        $selection = $this->resolveConfiguration($config);
        $requested = $selection['requested'];
        $protocols = $selection['protocols'];

        if (!$sslEnabled) {
            return [
                'requested' => $requested,
                'effective' => 'disabled',
                'openssl_conf' => null,
                'reason' => 'HTTPS is disabled',
            ];
        }

        if (\in_array('tls1.2', $protocols, true)
            && !\defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')
        ) {
            throw new \RuntimeException(
                'WLS TLS 1.2 requires a PHP/OpenSSL build exposing STREAM_CRYPTO_METHOD_TLSv1_2_SERVER.'
            );
        }
        if ($this->tls13Requested($protocols) && !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
            throw new \RuntimeException(
                'WLS TLS 1.3 requires a PHP/OpenSSL build exposing STREAM_CRYPTO_METHOD_TLSv1_3_SERVER.'
            );
        }

        if ($requested === self::PROFILE_SYSTEM) {
            return [
                'requested' => $requested,
                'effective' => self::PROFILE_SYSTEM,
                'openssl_conf' => $this->currentOpenSslConfig(),
                'reason' => 'using the operator/system OpenSSL group policy',
            ];
        }

        $existing = $this->currentOpenSslConfig();
        if ($existing !== null) {
            return [
                'requested' => $requested,
                'effective' => 'external',
                'openssl_conf' => $existing,
                'reason' => 'preserving the operator supplied OPENSSL_CONF',
            ];
        }

        $path = $this->writePerformanceConfig();
        \putenv('OPENSSL_CONF=' . $path);
        $_ENV['OPENSSL_CONF'] = $path;
        $_SERVER['OPENSSL_CONF'] = $path;

        return [
            'requested' => $requested,
            'effective' => self::PROFILE_PERFORMANCE,
            'openssl_conf' => $path,
            'reason' => 'TLS 1.3 groups pinned to X25519:P-256 for lower handshake CPU and wire size',
        ];
    }

    /**
     * Resolve the transport-neutral TLS contract once so native PHP TLS and
     * the public protocol edge cannot silently apply different versions or
     * key-exchange profiles.
     *
     * @param array<string, mixed> $config
     * @return array{requested:string,protocols:non-empty-list<'tls1.2'|'tls1.3'>}
     */
    public function resolveConfiguration(array $config): array
    {
        $ssl = \is_array($config['ssl'] ?? null) ? $config['ssl'] : [];

        return [
            'requested' => $this->normalizeProfile(
                $ssl['key_exchange_profile'] ?? self::PROFILE_PERFORMANCE
            ),
            'protocols' => $this->normalizeProtocols($ssl),
        ];
    }

    private function normalizeProfile(mixed $profile): string
    {
        $profile = \strtolower(\trim((string)$profile));
        if ($profile === '' || $profile === 'auto' || $profile === 'optimized') {
            return self::PROFILE_PERFORMANCE;
        }
        if (\in_array($profile, ['system', 'default', 'post_quantum', 'post-quantum'], true)) {
            return self::PROFILE_SYSTEM;
        }
        if ($profile !== self::PROFILE_PERFORMANCE) {
            throw new \RuntimeException(
                'wls.ssl.key_exchange_profile must be performance or system.'
            );
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $ssl
     * @return non-empty-list<'tls1.2'|'tls1.3'>
     */
    private function normalizeProtocols(array $ssl): array
    {
        $configured = \array_key_exists('protocols', $ssl)
            ? $ssl['protocols']
            : (\array_key_exists('server_protocols', $ssl)
                ? $ssl['server_protocols']
                : ['tls1.2', 'tls1.3']);
        if (\is_string($configured)) {
            $configured = \preg_split('/[\s,|]+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!\is_array($configured) || $configured === []) {
            throw new \RuntimeException(
                'wls.ssl.protocols must be a non-empty list containing only tls1.2 and/or tls1.3.'
            );
        }

        $protocols = [];
        foreach ($configured as $protocol) {
            if (!\is_string($protocol)) {
                throw new \RuntimeException(
                    'wls.ssl.protocols must contain only string values tls1.2 and/or tls1.3.'
                );
            }
            $protocol = \strtolower(\str_replace(['_', '-', ' '], ['.', '.', ''], \trim((string)$protocol)));
            $protocol = \str_replace('tlsv', 'tls', $protocol);
            if (\in_array($protocol, ['1.2', 'tls1.2', 'tls12'], true)) {
                $protocols[] = 'tls1.2';
                continue;
            }
            if (\in_array($protocol, ['1.3', 'tls1.3', 'tls13'], true)) {
                $protocols[] = 'tls1.3';
                continue;
            }

            throw new \RuntimeException(
                'wls.ssl.protocols contains unsupported value "' . $protocol
                . '"; only tls1.2 and tls1.3 are allowed.'
            );
        }

        $protocols = \array_values(\array_unique($protocols));
        if ($protocols === []) {
            throw new \RuntimeException(
                'wls.ssl.protocols must enable at least one of tls1.2 or tls1.3.'
            );
        }

        return $protocols;
    }

    /** @param list<string> $protocols */
    private function tls13Requested(array $protocols): bool
    {
        return \in_array('tls1.3', $protocols, true);
    }

    private function currentOpenSslConfig(): ?string
    {
        $path = \trim((string)(\getenv('OPENSSL_CONF') ?: ''));
        return $path !== '' ? $path : null;
    }

    private function writePerformanceConfig(): string
    {
        $content = <<<'CONF'
openssl_conf = wls_init

[wls_init]
ssl_conf = wls_ssl

[wls_ssl]
system_default = wls_system_default

[wls_system_default]
Groups = X25519:P-256
CONF;
        $content .= "\n";
        $directory = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'tls';
        if (!\is_dir($directory) && !@\mkdir($directory, 0755, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create WLS TLS runtime directory: ' . $directory);
        }

        $path = $directory . DIRECTORY_SEPARATOR
            . 'openssl-performance-' . \substr(\hash('sha256', $content), 0, 16) . '.cnf';
        if (\is_link($path)) {
            throw new \RuntimeException('Refusing symlinked WLS OpenSSL config: ' . $path);
        }
        if (!\is_file($path)) {
            $temporary = $path . '.' . \getmypid() . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
            if (@\file_put_contents($temporary, $content, LOCK_EX) !== \strlen($content)) {
                @\unlink($temporary);
                throw new \RuntimeException('Unable to write WLS OpenSSL performance config.');
            }
            @\chmod($temporary, 0644);
            if (!@\rename($temporary, $path)) {
                @\unlink($temporary);
                if (!\is_file($path)) {
                    throw new \RuntimeException('Unable to publish WLS OpenSSL performance config.');
                }
            }
        }
        if (@\file_get_contents($path) !== $content) {
            throw new \RuntimeException('WLS OpenSSL performance config integrity check failed.');
        }

        return $path;
    }
}
