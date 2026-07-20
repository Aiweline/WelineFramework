<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\App\Env;

/**
 * Validated configuration for PHP 8.6's external OpenSSL session cache.
 *
 * The feature stays opt-in until the PHP 8.6 same/cross-Worker and latency
 * gates have passed on every supported platform.
 */
final class TlsSessionCacheConfig
{
    public const MODE_OFF = 'off';
    public const MODE_EXTERNAL = 'external';

    public function __construct(
        public readonly string $mode,
        public readonly int $timeoutSeconds,
        public readonly int $numTickets,
        public readonly int $localCacheSize,
        public readonly int $maxSessionBytes,
        public readonly int $maxEntries,
        public readonly int $maxTotalBytes,
        public readonly float $callbackTimeoutSeconds,
        public readonly float $readyTimeoutSeconds,
        public readonly float $reconnectCooldownSeconds,
        public readonly string $contextEpoch,
    ) {
    }

    /**
     * @param array<string, mixed> $sslConfig
     */
    public static function fromSslConfig(array $sslConfig): self
    {
        $raw = $sslConfig['session_cache'] ?? [];
        if (\is_bool($raw)) {
            $raw = ['mode' => $raw ? self::MODE_EXTERNAL : self::MODE_OFF];
        } elseif (\is_string($raw)) {
            $raw = ['mode' => $raw];
        } elseif (!\is_array($raw)) {
            throw new \InvalidArgumentException('wls.ssl.session_cache must be an array, string, or boolean.');
        }

        $mode = \strtolower(\trim((string)($raw['mode'] ?? self::MODE_OFF)));
        $mode = match ($mode) {
            '', '0', 'false', 'disabled', 'none' => self::MODE_OFF,
            '1', 'true', 'enabled', 'stateful', 'external_stateful' => self::MODE_EXTERNAL,
            default => $mode,
        };
        if (!\in_array($mode, [self::MODE_OFF, self::MODE_EXTERNAL], true)) {
            throw new \InvalidArgumentException(
                'wls.ssl.session_cache.mode must be off or external; received "' . $mode . '".'
            );
        }

        $contextEpochValue = $raw['context_epoch'] ?? '1';
        if (!\is_string($contextEpochValue) && !\is_int($contextEpochValue)) {
            throw new \InvalidArgumentException(
                'wls.ssl.session_cache.context_epoch must be a string or integer.'
            );
        }
        $contextEpoch = \trim((string)$contextEpochValue);
        if ($contextEpoch === '' || \strlen($contextEpoch) > 64
            || !\preg_match('/^[A-Za-z0-9._-]+$/D', $contextEpoch)
        ) {
            throw new \InvalidArgumentException(
                'wls.ssl.session_cache.context_epoch must be 1-64 characters using A-Z, a-z, 0-9, dot, underscore, or dash.'
            );
        }

        return new self(
            mode: $mode,
            timeoutSeconds: self::intInRange($raw, 'timeout_seconds', 300, 30, 86400),
            numTickets: self::intInRange($raw, 'num_tickets', 2, 1, 4),
            localCacheSize: self::intInRange($raw, 'local_cache_size', 256, 0, 4096),
            maxSessionBytes: self::intInRange($raw, 'max_session_bytes', 16384, 1024, 262144),
            maxEntries: self::intInRange($raw, 'max_entries', 20000, 128, 200000),
            maxTotalBytes: self::intInRange($raw, 'max_total_bytes', 67108864, 1048576, 536870912),
            callbackTimeoutSeconds: self::millisecondsInRange(
                $raw,
                'callback_timeout_ms',
                2.0,
                0.5,
                20.0
            ),
            readyTimeoutSeconds: self::millisecondsInRange(
                $raw,
                'ready_timeout_ms',
                250.0,
                10.0,
                2000.0
            ),
            reconnectCooldownSeconds: self::millisecondsInRange(
                $raw,
                'reconnect_cooldown_ms',
                1000.0,
                100.0,
                30000.0
            ),
            contextEpoch: $contextEpoch,
        );
    }

    public static function fromEnvironment(): self
    {
        $wls = Env::get('wls', []);
        $wls = \is_array($wls) ? $wls : [];
        $ssl = \is_array($wls['ssl'] ?? null) ? $wls['ssl'] : [];

        return self::fromSslConfig($ssl);
    }

    public function enabled(): bool
    {
        return $this->mode === self::MODE_EXTERNAL;
    }

    public function sha256(): string
    {
        $config = $this->toArray();
        \ksort($config, SORT_STRING);

        return \hash('sha256', \json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string, int|float|string|bool> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'enabled' => $this->enabled(),
            'timeout_seconds' => $this->timeoutSeconds,
            'num_tickets' => $this->numTickets,
            'local_cache_size' => $this->localCacheSize,
            'max_session_bytes' => $this->maxSessionBytes,
            'max_entries' => $this->maxEntries,
            'max_total_bytes' => $this->maxTotalBytes,
            'callback_timeout_ms' => $this->callbackTimeoutSeconds * 1000,
            'ready_timeout_ms' => $this->readyTimeoutSeconds * 1000,
            'reconnect_cooldown_ms' => $this->reconnectCooldownSeconds * 1000,
            'context_epoch' => $this->contextEpoch,
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function intInRange(
        array $raw,
        string $key,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = \array_key_exists($key, $raw) ? (int)$raw[$key] : $default;
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException(\sprintf(
                'wls.ssl.session_cache.%s must be between %d and %d.',
                $key,
                $minimum,
                $maximum
            ));
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function millisecondsInRange(
        array $raw,
        string $key,
        float $default,
        float $minimum,
        float $maximum
    ): float {
        $milliseconds = \array_key_exists($key, $raw) ? (float)$raw[$key] : $default;
        if (!\is_finite($milliseconds) || $milliseconds < $minimum || $milliseconds > $maximum) {
            throw new \InvalidArgumentException(\sprintf(
                'wls.ssl.session_cache.%s must be between %.1f and %.1f milliseconds.',
                $key,
                $minimum,
                $maximum
            ));
        }

        return $milliseconds / 1000;
    }
}
