<?php
declare(strict_types=1);

namespace Weline\Server\Log;

use Weline\Server\Service\WlsLogService;

class WlsLogger
{
    private static ?self $instance = null;

    private string $processTag = 'Unknown';
    private string $instanceName = 'default';
    private string $minLevel = LogLevel::INFO;
    private bool $stdoutEnabled = false;
    private bool $fileEnabled = true;
    private string $logDir = '';
    private string $buffer = '';
    private int $bufferSize = 0;
    private int $maxBufferBytes = 8192;
    private float $flushInterval = 0.5;
    private float $lastFlushTime = 0.0;
    private bool $shutdownRegistered = false;

    /**
     * callable(string $line, string $level, string $processTag): void
     */
    private $ipcLogSink = null;

    private function __construct()
    {
        $this->lastFlushTime = \microtime(true);
        $this->instanceName = WlsLogService::resolveInstanceName();
        $this->logDir = LogConfig::getLogDir($this->instanceName, $this->processTag);
        $this->minLevel = LogConfig::getMinLevel();
        $this->fileEnabled = LogConfig::isEnabled();

        if (LogConfig::isDevMode()) {
            $this->stdoutEnabled = true;
            $this->fileEnabled = true;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function setProcessTag(string $tag): self
    {
        $this->processTag = $tag;
        $this->setInstanceName(WlsLogService::resolveInstanceName(null, $tag));
        return $this;
    }

    public function getProcessTag(): string
    {
        return $this->processTag;
    }

    public function setInstanceName(string $instanceName): self
    {
        $resolved = WlsLogService::sanitizeInstanceName($instanceName);
        if ($resolved === $this->instanceName) {
            return $this;
        }

        $this->flush(true);
        $this->instanceName = $resolved;
        $this->logDir = LogConfig::getLogDir($this->instanceName, $this->processTag);

        return $this;
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function setMinLevel(string $level): self
    {
        $this->minLevel = LogLevel::normalize($level);
        return $this;
    }

    public function setStdoutEnabled(bool $enabled): self
    {
        $this->stdoutEnabled = $enabled;
        return $this;
    }

    public function setFileEnabled(bool $enabled): self
    {
        $this->fileEnabled = $enabled;
        return $this;
    }

    /**
     * @param callable|null $sink function(string $line, string $level, string $processTag): void
     */
    public function setIpcLogSink(?callable $sink): self
    {
        $this->ipcLogSink = $sink;
        if ($sink !== null && LogConfig::isDevMode()) {
            $this->stdoutEnabled = false;
        }
        return $this;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = LogLevel::normalize($level);

        $contextInstance = $context['instance'] ?? null;
        if (\is_scalar($contextInstance) && (string)$contextInstance !== '') {
            $this->setInstanceName((string)$contextInstance);
        }

        if (!LogLevel::isAtLeast($level, $this->minLevel)) {
            return;
        }

        $timestamp = \date('Y-m-d H:i:s');
        $contextStr = !empty($context)
            ? ' ' . \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';
        $line = "[{$timestamp}] [{$this->processTag}] [{$level}] {$message}{$contextStr}\n";

        if (LogConfig::isDevMode() && $this->ipcLogSink !== null) {
            ($this->ipcLogSink)($line, $level, $this->processTag);
            $this->ensureShutdownRegistered();
            return;
        }

        if ($this->stdoutEnabled) {
            $this->writeStdout($line, $level);
        }

        if ($this->fileEnabled) {
            $this->writeBuffer($line, $level);
        }

        if ($level === LogLevel::ERROR || $level === LogLevel::FATAL) {
            $this->flush(true);
        }

        $this->ensureShutdownRegistered();
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function fatal(string $message, array $context = []): void
    {
        $this->log(LogLevel::FATAL, $message, $context);
    }

    public static function log_(string $level, string $message, array $context = []): void
    {
        self::getInstance()->log($level, $message, $context);
    }

    public static function debug_(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }

    public static function info_(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function notice_(string $message, array $context = []): void
    {
        self::getInstance()->notice($message, $context);
    }

    public static function warning_(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function error_(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function fatal_(string $message, array $context = []): void
    {
        self::getInstance()->fatal($message, $context);
    }

    public static function flush_(bool $force = false): void
    {
        self::getInstance()->flush($force);
    }

    public static function tick_(): bool
    {
        return self::getInstance()->tick();
    }

    public function tick(): bool
    {
        if ($this->bufferSize === 0) {
            return false;
        }

        $now = \microtime(true);
        if (($now - $this->lastFlushTime) >= $this->flushInterval) {
            $this->flush(true);
            return true;
        }

        return false;
    }

    public function flush(bool $force = false): void
    {
        if ($this->bufferSize === 0) {
            return;
        }

        if (!$force) {
            $now = \microtime(true);
            if (($now - $this->lastFlushTime) < $this->flushInterval) {
                return;
            }
        }

        if (!empty($this->logDir) && !\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $mainLog = $this->logDir . 'wls.log';
        @\file_put_contents($mainLog, $this->buffer, FILE_APPEND | LOCK_EX);

        $this->buffer = '';
        $this->bufferSize = 0;
        $this->lastFlushTime = \microtime(true);
    }

    public function writeErrorLog(string $line): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $errorLog = $this->logDir . 'error.log';
        @\file_put_contents($errorLog, $line, FILE_APPEND | LOCK_EX);
    }

    public function writeCrashLog(array $crashData): void
    {
        if (empty($this->logDir)) {
            return;
        }

        if (!\is_dir($this->logDir)) {
            @\mkdir($this->logDir, 0755, true);
        }

        $crashLog = $this->logDir . 'crash.log';
        $json = \json_encode($crashData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        @\file_put_contents($crashLog, $json . "\n\n", FILE_APPEND | LOCK_EX);
    }

    private function writeStdout(string $line, string $level): void
    {
        $colored = LogLevel::colorLine($line, $level, $this->processTag);

        if (\defined('STDOUT') && \is_resource(STDOUT)) {
            @\fwrite(STDOUT, $colored);
            @\fflush(STDOUT);
        } else {
            echo $colored;
            @\flush();
        }
    }

    private function writeBuffer(string $line, string $level): void
    {
        $this->buffer .= $line;
        $this->bufferSize += \strlen($line);

        if (LogLevel::isAtLeast($level, LogLevel::ERROR)) {
            $this->writeErrorLog($line);
        }

        if ($this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }
    }

    public function appendLineForMaster(string $line): void
    {
        if ($line === '') {
            return;
        }

        $this->buffer .= $line;
        $this->bufferSize += \strlen($line);
        if ($this->bufferSize >= $this->maxBufferBytes) {
            $this->flush(true);
        }
    }

    private function ensureShutdownRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        \register_shutdown_function(function () {
            $this->flush(true);
        });

        $this->shutdownRegistered = true;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->flush(true);
        }
        self::$instance = null;
        LogConfig::clearCache();
    }
}

