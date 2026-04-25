<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Fiber-local output capture for persistent WLS workers.
 *
 * PHP output buffering is process-global, so `ob_start()` around request work is
 * unsafe under fiber interleaving. This class installs a single process-level
 * output handler and routes chunks into the current fiber's logical buffer.
 */
final class FiberOutputBuffer
{
    private static bool $installed = false;

    private static int $installedLevel = 0;

    /** @var \WeakMap<\Fiber, string>|null */
    private static ?\WeakMap $fiberBuffers = null;

    /** @var \WeakMap<\Fiber, int>|null */
    private static ?\WeakMap $fiberDepths = null;

    private static string $mainBuffer = '';

    private static int $mainDepth = 0;

    public static function install(): void
    {
        self::ensureInstalled('install');
    }

    public static function ensureInstalled(string $reason = ''): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        if (self::$installed && self::isInstalledBufferActive()) {
            return;
        }

        $wasMarkedInstalled = self::$installed;
        $previousLevel = self::$installedLevel;

        if ($wasMarkedInstalled) {
            self::resetInstalledState();
        }

        \ob_start([self::class, 'handleOutputChunk'], 1);
        self::$installed = true;
        self::$installedLevel = \ob_get_level();

        if ($wasMarkedInstalled) {
            self::logRecovered($reason, $previousLevel);
        }
    }

    public static function isActive(): bool
    {
        return Runtime::isPersistent() && self::isInstalledBufferActive();
    }

    public static function handleOutputChunk(string $chunk): string
    {
        return self::handleChunk($chunk);
    }

    public static function uninstall(): void
    {
        if (!self::$installed) {
            return;
        }

        $shouldCloseActiveBuffer = self::isInstalledBufferActive()
            && self::$installedLevel > 0
            && \ob_get_level() === self::$installedLevel;

        self::resetInstalledState();

        if ($shouldCloseActiveBuffer) {
            \ob_end_clean();
        }
    }

    public static function beginCapture(): void
    {
        if (!Runtime::isPersistent()) {
            \ob_start();
            return;
        }

        self::ensureInstalled('begin_capture');
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if (self::$mainDepth === 0) {
                self::$mainBuffer = '';
            }
            self::$mainDepth++;
            return;
        }

        self::$fiberBuffers ??= new \WeakMap();
        self::$fiberDepths ??= new \WeakMap();
        if (!isset(self::$fiberDepths[$fiber]) || self::$fiberDepths[$fiber] === 0) {
            self::$fiberBuffers[$fiber] = '';
            self::$fiberDepths[$fiber] = 0;
        }
        self::$fiberDepths[$fiber] = (int)self::$fiberDepths[$fiber] + 1;
    }

    public static function endCapture(): string
    {
        if (!Runtime::isPersistent()) {
            return (string)\ob_get_clean();
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            $result = self::$mainBuffer;
            if (self::$mainDepth > 0) {
                self::$mainDepth--;
            }
            if (self::$mainDepth <= 0) {
                self::$mainDepth = 0;
                self::$mainBuffer = '';
            }
            return $result;
        }

        if (self::$fiberDepths === null || !isset(self::$fiberDepths[$fiber])) {
            return '';
        }

        $result = (string)(self::$fiberBuffers[$fiber] ?? '');
        self::$fiberDepths[$fiber] = (int)self::$fiberDepths[$fiber] - 1;
        if (self::$fiberDepths[$fiber] <= 0) {
            unset(self::$fiberDepths[$fiber], self::$fiberBuffers[$fiber]);
        } else {
            self::$fiberBuffers[$fiber] = '';
        }

        return $result;
    }

    public static function discardCapture(): void
    {
        if (!Runtime::isPersistent()) {
            while (\ob_get_level() > 0) {
                \ob_end_clean();
            }
            return;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$mainDepth = 0;
            self::$mainBuffer = '';
            return;
        }

        if (self::$fiberDepths !== null && isset(self::$fiberDepths[$fiber])) {
            unset(self::$fiberDepths[$fiber]);
        }
        if (self::$fiberBuffers !== null && isset(self::$fiberBuffers[$fiber])) {
            unset(self::$fiberBuffers[$fiber]);
        }
    }

    public static function resetCurrent(): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        self::discardCapture();
    }

    private static function handleChunk(string $chunk): string
    {
        if (!Runtime::isPersistent()) {
            return $chunk;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if (self::$mainDepth > 0) {
                self::$mainBuffer .= $chunk;
            }
            return '';
        }

        if (self::$fiberDepths !== null && isset(self::$fiberDepths[$fiber]) && self::$fiberDepths[$fiber] > 0) {
            self::$fiberBuffers ??= new \WeakMap();
            $current = self::$fiberBuffers[$fiber] ?? '';
            self::$fiberBuffers[$fiber] = $current . $chunk;
        }

        return '';
    }

    private static function isInstalledBufferActive(): bool
    {
        if (!self::$installed || self::$installedLevel <= 0) {
            return false;
        }

        if (\ob_get_level() < self::$installedLevel) {
            return false;
        }

        $statuses = \ob_get_status(true);
        $status = $statuses[self::$installedLevel - 1] ?? null;
        if (!\is_array($status)) {
            return false;
        }

        return (string)($status['name'] ?? '') === self::handlerName();
    }

    private static function resetInstalledState(): void
    {
        self::$installed = false;
        self::$installedLevel = 0;
        self::$fiberBuffers = null;
        self::$fiberDepths = null;
        self::$mainBuffer = '';
        self::$mainDepth = 0;
    }

    private static function handlerName(): string
    {
        return self::class . '::handleOutputChunk';
    }

    private static function logRecovered(string $reason, int $previousLevel): void
    {
        if (!\class_exists(\Weline\Server\Log\WlsLogger::class, false)) {
            return;
        }

        try {
            \Weline\Server\Log\WlsLogger::warning_(
                '[FiberOutputBufferRecovered] reason=' . ($reason !== '' ? $reason : 'unknown')
                . ' uri=' . (string)($_SERVER['REQUEST_URI'] ?? '(none)')
                . ' previous_level=' . $previousLevel
                . ' current_ob_level=' . \ob_get_level()
                . ' new_level=' . self::$installedLevel
            );
        } catch (\Throwable) {
            // Recovery must not fail because diagnostics are unavailable.
        }
    }
}
