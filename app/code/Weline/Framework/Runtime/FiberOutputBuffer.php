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

    /** @var \WeakMap<\Fiber, string>|null */
    private static ?\WeakMap $fiberBuffers = null;

    /** @var \WeakMap<\Fiber, int>|null */
    private static ?\WeakMap $fiberDepths = null;

    private static string $mainBuffer = '';

    private static int $mainDepth = 0;

    public static function install(): void
    {
        if (self::$installed || !Runtime::isPersistent()) {
            return;
        }

        \ob_start(static fn(string $chunk): string => self::handleChunk($chunk), 1);
        self::$installed = true;
    }

    public static function uninstall(): void
    {
        if (!self::$installed) {
            return;
        }

        self::$installed = false;
        self::$fiberBuffers = null;
        self::$fiberDepths = null;
        self::$mainBuffer = '';
        self::$mainDepth = 0;

        if (\ob_get_level() > 0) {
            \ob_end_clean();
        }
    }

    public static function beginCapture(): void
    {
        if (!Runtime::isPersistent()) {
            \ob_start();
            return;
        }

        self::install();
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
}
