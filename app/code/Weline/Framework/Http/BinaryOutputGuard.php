<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\Runtime;

/**
 * Suppress stray PHP output around binary API responses.
 *
 * Under WLS (persistent), never drain the process-global ob stack — that would
 * tear down {@see FiberOutputBuffer}'s installed handler and cross-contaminate
 * fibers. Capture is fiber-local; display_errors is left alone (capture swallows
 * printed warnings).
 *
 * Under FPM, pre-existing buffers are drained once, display_errors temporarily
 * disabled, and capture uses FiberOutputBuffer's non-persistent native baseline.
 */
final class BinaryOutputGuard
{
    /**
     * @return array{
     *   mode: 'persistent'|'fpm',
     *   label: string,
     *   display_errors?: string|false,
     *   html_errors?: string|false
     * }
     */
    public static function begin(string $label = 'Binary'): array
    {
        if (Runtime::isPersistent()) {
            FiberOutputBuffer::beginCapture();

            return [
                'mode' => 'persistent',
                'label' => $label,
            ];
        }

        $preExisting = '';
        while (\ob_get_level() > 0) {
            $chunk = \ob_get_clean();
            if (\is_string($chunk) && $chunk !== '') {
                $preExisting = $chunk . $preExisting;
            }
        }
        if ($preExisting !== '') {
            self::logCleared($label, $preExisting);
        }

        $guard = [
            'mode' => 'fpm',
            'label' => $label,
            'display_errors' => \ini_get('display_errors'),
            'html_errors' => \ini_get('html_errors'),
        ];
        @\ini_set('display_errors', '0');
        @\ini_set('html_errors', '0');
        FiberOutputBuffer::beginCapture();

        return $guard;
    }

    /**
     * @param array{
     *   mode: 'persistent'|'fpm',
     *   label: string,
     *   display_errors?: string|false,
     *   html_errors?: string|false
     * } $guard
     */
    public static function end(array $guard): void
    {
        $captured = '';
        try {
            $captured = FiberOutputBuffer::endCapture();
        } catch (\Throwable) {
            FiberOutputBuffer::discardCapture();
        }

        if (($guard['mode'] ?? '') === 'fpm') {
            if (\array_key_exists('display_errors', $guard)
                && (\is_string($guard['display_errors']) || $guard['display_errors'] === false)
            ) {
                @\ini_set(
                    'display_errors',
                    $guard['display_errors'] === false ? '0' : (string)$guard['display_errors']
                );
            }
            if (\array_key_exists('html_errors', $guard)
                && (\is_string($guard['html_errors']) || $guard['html_errors'] === false)
            ) {
                @\ini_set(
                    'html_errors',
                    $guard['html_errors'] === false ? '0' : (string)$guard['html_errors']
                );
            }
        }

        if ($captured !== '') {
            self::logSuppressed((string)($guard['label'] ?? 'Binary'), $captured);
        }
    }

    private static function logCleared(string $label, string $preExisting): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }
        if ($label === 'QueryBin') {
            \w_log_warning('[' . $label . '] Cleared pre-existing output buffer before binary response.', [
                'bytes' => \strlen($preExisting),
                'sha256' => \hash('sha256', $preExisting),
            ], 'query_bin');
            return;
        }
        \w_log_warning(
            '[' . $label . '] Cleared pre-existing output buffer before binary response: '
            . \mb_substr(\trim($preExisting), 0, 500)
        );
    }

    private static function logSuppressed(string $label, string $captured): void
    {
        if (!\function_exists('w_log_warning')) {
            return;
        }
        if ($label === 'QueryBin') {
            \w_log_warning('[' . $label . '] Suppressed stray output during binary response.', [
                'bytes' => \strlen($captured),
                'sha256' => \hash('sha256', $captured),
            ], 'query_bin');
            return;
        }
        \w_log_warning(
            '[' . $label . '] Suppressed stray output during binary response: '
            . \mb_substr(\trim($captured), 0, 500)
        );
    }
}
