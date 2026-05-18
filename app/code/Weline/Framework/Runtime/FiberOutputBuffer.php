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
    private const MAX_CAPTURE_BYTES = 16777216;

    private const MIN_MEMORY_HEADROOM_BYTES = 16777216;

    private static bool $installed = false;

    private static int $installedLevel = 0;

    /** @var \WeakMap<\Fiber, list<FiberOutputCaptureFrame>>|null */
    private static ?\WeakMap $fiberBufferStacks = null;

    /** @var list<FiberOutputCaptureFrame> */
    private static array $mainBufferStack = [];

    private static ?int $memoryLimitBytes = null;

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
            self::$mainBufferStack[] = new FiberOutputCaptureFrame();
            return;
        }

        self::$fiberBufferStacks ??= new \WeakMap();
        $stack = self::$fiberBufferStacks[$fiber] ?? [];
        $stack[] = new FiberOutputCaptureFrame();
        self::$fiberBufferStacks[$fiber] = $stack;
    }

    public static function endCapture(): string
    {
        if (!Runtime::isPersistent()) {
            return (string)\ob_get_clean();
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if (self::$mainBufferStack === []) {
                return '';
            }

            return self::finishFrame(\array_pop(self::$mainBufferStack));
        }

        if (self::$fiberBufferStacks === null || !isset(self::$fiberBufferStacks[$fiber])) {
            return '';
        }

        $stack = self::$fiberBufferStacks[$fiber];
        if ($stack === []) {
            unset(self::$fiberBufferStacks[$fiber]);
            return '';
        }

        $frame = \array_pop($stack);
        if ($stack === []) {
            unset(self::$fiberBufferStacks[$fiber]);
        } else {
            self::$fiberBufferStacks[$fiber] = $stack;
        }

        return self::finishFrame($frame);
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
            if (self::$mainBufferStack !== []) {
                \array_pop(self::$mainBufferStack);
            }
            return;
        }

        if (self::$fiberBufferStacks === null || !isset(self::$fiberBufferStacks[$fiber])) {
            return;
        }

        $stack = self::$fiberBufferStacks[$fiber];
        if ($stack !== []) {
            \array_pop($stack);
        }
        if ($stack === []) {
            unset(self::$fiberBufferStacks[$fiber]);
        } else {
            self::$fiberBufferStacks[$fiber] = $stack;
        }
    }

    public static function resetCurrent(): void
    {
        if (!Runtime::isPersistent()) {
            return;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$mainBufferStack = [];
            return;
        }

        if (self::$fiberBufferStacks !== null && isset(self::$fiberBufferStacks[$fiber])) {
            unset(self::$fiberBufferStacks[$fiber]);
        }
    }

    private static function handleChunk(string $chunk): string
    {
        if (!Runtime::isPersistent()) {
            return $chunk;
        }

        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if (self::$mainBufferStack !== []) {
                self::appendToFrame(self::$mainBufferStack[\array_key_last(self::$mainBufferStack)], $chunk);
            }
            return '';
        }

        if (self::$fiberBufferStacks !== null && isset(self::$fiberBufferStacks[$fiber])) {
            $stack = self::$fiberBufferStacks[$fiber];
            if ($stack !== []) {
                self::appendToFrame($stack[\array_key_last($stack)], $chunk);
            }
        }

        return '';
    }

    private static function appendToFrame(FiberOutputCaptureFrame $frame, string $chunk): void
    {
        if ($chunk === '' || $frame->overflowed) {
            return;
        }

        $chunkBytes = \strlen($chunk);
        if (!self::canAppend($frame->bytes, $chunkBytes)) {
            $frame->buffer = '';
            $frame->bytes = 0;
            $frame->overflowed = true;
            return;
        }

        $frame->buffer .= $chunk;
        $frame->bytes += $chunkBytes;
    }

    private static function finishFrame(FiberOutputCaptureFrame $frame): string
    {
        if ($frame->overflowed) {
            throw new \OverflowException(
                'WLS output capture exceeded safe memory limits; request output was discarded before it could crash the worker.'
            );
        }

        return $frame->buffer;
    }

    private static function canAppend(int $currentBytes, int $chunkBytes): bool
    {
        if ($chunkBytes <= 0) {
            return true;
        }

        if ($currentBytes > self::MAX_CAPTURE_BYTES - $chunkBytes) {
            return false;
        }

        $memoryLimit = self::getMemoryLimitBytes();
        if ($memoryLimit <= 0) {
            return true;
        }

        $projectedAppendBytes = $currentBytes + $chunkBytes + self::MIN_MEMORY_HEADROOM_BYTES;

        return \memory_get_usage(true) + $projectedAppendBytes < $memoryLimit;
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
        self::$fiberBufferStacks = null;
        self::$mainBufferStack = [];
    }

    private static function getMemoryLimitBytes(): int
    {
        if (self::$memoryLimitBytes !== null) {
            return self::$memoryLimitBytes;
        }

        $raw = \trim((string)\ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return self::$memoryLimitBytes = 0;
        }

        $unit = \strtolower($raw[-1]);
        $value = (float)$raw;
        self::$memoryLimitBytes = (int)match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        return self::$memoryLimitBytes;
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

final class FiberOutputCaptureFrame
{
    public string $buffer = '';

    public int $bytes = 0;

    public bool $overflowed = false;
}
