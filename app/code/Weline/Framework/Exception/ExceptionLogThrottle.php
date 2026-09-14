<?php

declare(strict_types=1);

namespace Weline\Framework\Exception;

/**
 * Cross-process exception.log flood control.
 *
 * Same logical failure (normalized message) is fully logged once per window;
 * repeats are suppressed and counted; the next allowed write includes a
 * suppressed-count summary so operators still see volume without multi‑GB spam.
 */
final class ExceptionLogThrottle
{
    public const DEFAULT_WINDOW_SECONDS = 300;

    /** Heartbeat: even inside a window, emit a compact summary every N suppressions. */
    public const HEARTBEAT_EVERY = 100;

    /**
     * @return array{
     *   allow: bool,
     *   suppressed_before: int,
     *   flood_key: string,
     *   short_key: string,
     *   summary: ?string
     * }
     */
    public static function decide(\Throwable $exception, ?int $windowSeconds = null): array
    {
        $windowSeconds = max(30, $windowSeconds ?? self::DEFAULT_WINDOW_SECONDS);
        $floodKey = ExceptionFingerprint::generateFloodKey($exception);
        $shortKey = substr($floodKey, 0, 8);
        $now = time();
        $path = self::statePath($floodKey);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [
                'allow' => true,
                'suppressed_before' => 0,
                'flood_key' => $floodKey,
                'short_key' => $shortKey,
                'summary' => null,
            ];
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return [
                'allow' => true,
                'suppressed_before' => 0,
                'flood_key' => $floodKey,
                'short_key' => $shortKey,
                'summary' => null,
            ];
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return [
                    'allow' => true,
                    'suppressed_before' => 0,
                    'flood_key' => $floodKey,
                    'short_key' => $shortKey,
                    'summary' => null,
                ];
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($state)) {
                $state = [];
            }

            $lastLoggedAt = (int)($state['last_logged_at'] ?? 0);
            $suppressed = (int)($state['suppressed'] ?? 0);
            $sample = (string)($state['sample_message'] ?? '');
            if ($sample === '') {
                $sample = self::clipMessage($exception->getMessage());
            }

            $elapsed = $lastLoggedAt > 0 ? ($now - $lastLoggedAt) : $windowSeconds;
            $windowExpired = $lastLoggedAt <= 0 || $elapsed >= $windowSeconds;

            if ($windowExpired) {
                $summary = $suppressed > 0
                    ? sprintf(
                        'exception_log_throttle: suppressed %d duplicate(s) of [%s] over ~%ds; sample=%s',
                        $suppressed,
                        $shortKey,
                        max(0, $elapsed),
                        $sample
                    )
                    : null;
                self::writeState($handle, [
                    'flood_key' => $floodKey,
                    'last_logged_at' => $now,
                    'suppressed' => 0,
                    'sample_message' => self::clipMessage($exception->getMessage()),
                    'class' => get_class($exception),
                ]);

                return [
                    'allow' => true,
                    'suppressed_before' => $suppressed,
                    'flood_key' => $floodKey,
                    'short_key' => $shortKey,
                    'summary' => $summary,
                ];
            }

            $suppressed++;
            $heartbeat = ($suppressed % self::HEARTBEAT_EVERY) === 0;
            self::writeState($handle, [
                'flood_key' => $floodKey,
                'last_logged_at' => $lastLoggedAt,
                'suppressed' => $suppressed,
                'sample_message' => $sample,
                'class' => get_class($exception),
            ]);

            if ($heartbeat) {
                return [
                    'allow' => true,
                    'suppressed_before' => $suppressed,
                    'flood_key' => $floodKey,
                    'short_key' => $shortKey,
                    'summary' => sprintf(
                        'exception_log_throttle: still repeating [%s] x%d within %ds window; sample=%s',
                        $shortKey,
                        $suppressed,
                        $windowSeconds,
                        $sample
                    ),
                ];
            }

            return [
                'allow' => false,
                'suppressed_before' => $suppressed,
                'flood_key' => $floodKey,
                'short_key' => $shortKey,
                'summary' => null,
            ];
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $state
     */
    private static function writeState($handle, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json);
        fflush($handle);
    }

    private static function statePath(string $floodKey): string
    {
        $base = defined('BP') && BP !== ''
            ? rtrim((string)BP, "\\/") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp'
            : sys_get_temp_dir();

        return $base . DIRECTORY_SEPARATOR . 'exception-log-throttle'
            . DIRECTORY_SEPARATOR . $floodKey . '.json';
    }

    private static function clipMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return mb_strlen($message) > 180 ? (mb_substr($message, 0, 177) . '...') : $message;
    }

    /** @internal testing */
    public static function resetForTests(string $floodKey): void
    {
        $path = self::statePath($floodKey);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
